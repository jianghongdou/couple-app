<?php
namespace App;

use App\routes\AdminRoutes;
use App\routes\AiRoutes;
use App\routes\AuthRoutes;
use App\routes\ChatRoutes;
use App\routes\CoupleRoutes;
use App\routes\EmojiRoutes;
use App\routes\MetaRoutes;
use App\routes\SyncRoutes;
use PDOException;

/**
 * 启动引导：自动加载、配置校验、数据库初始化、错误处理、路由分发。
 *
 * 不使用 Composer：这里手写一个按命名空间映射到 src/ 的自动加载器。
 *   App\Config           -> src/Config.php
 *   App\util\Validate    -> src/util/Validate.php
 *   App\routes\AuthRoutes-> src/routes/AuthRoutes.php
 */
class Bootstrap
{
    private static $booted = false;
    private static $autoloaded = false;

    /** PSR-4 风格的极简自动加载（仅处理 App\ 前缀） */
    public static function registerAutoloader()
    {
        if (self::$autoloaded) {
            return;
        }
        self::$autoloaded = true;
        spl_autoload_register(function ($class) {
            if (strpos($class, 'App\\') !== 0) {
                return;
            }
            $rel = str_replace('\\', '/', substr($class, 4));
            $file = __DIR__ . '/' . $rel . '.php';
            if (is_file($file)) {
                require $file;
            }
        });
    }

    /**
     * 启动前的公共初始化（Web 与 CLI 共用）
     */
    public static function boot()
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        self::registerAutoloader();

        // 未安装（缺 config.php / 缺 db_* 或密钥）时抛 InstallRequired；
        // Web 入口会渲染安装向导，CLI 会给出中文引导，都不再直接 Fatal error。
        Config::assertConfig();

        date_default_timezone_set((string) Config::get('timezone', 'Asia/Shanghai'));

        // 不用 display_errors 把 PHP 报错吐给客户端（避免破坏 JSON / 泄漏路径）
        @ini_set('display_errors', '0');
        @ini_set('log_errors', '0');
        error_reporting(E_ALL);
        set_error_handler([__CLASS__, 'handlePhpError']);

        Config::ensureDirs();
        try {
            Db::init();
        } catch (DbConnectionError $e) {
            // 数据库连不上：Web 引导到安装向导，CLI 给中文提示
            Log::error('数据库连接失败', ['error' => $e->detail(), 'db' => Config::dbLabel()]);
            throw $e;
        }
        Settings::seedFromConfig();
        Runtime::ensureBoot();

        // 内置公共表情包（public/emoji/<包名>/<图片>）按目录指纹增量同步
        if (PHP_SAPI !== 'cli' || getenv('COUPLE_SKIP_PUBLIC_EMOJI') !== '1') {
            try {
                PublicEmoji::syncIfChanged();
            } catch (\Exception $e) {
                // 公共表情同步失败不应阻止服务启动
                Log::warn('同步内置公共表情包失败', ['error' => $e->getMessage()]);
            }
        }

        self::installErrorHandlers();
    }

    /** PHP 警告/提示：只写日志，不影响响应 */
    public static function handlePhpError($errno, $errstr, $errfile = '', $errline = 0)
    {
        if (!(error_reporting() & $errno)) {
            return true;
        }
        Log::warn('PHP 运行时提示', [
            'level' => $errno,
            'message' => $errstr,
            'file' => basename((string) $errfile) . ':' . $errline,
        ]);
        return true;
    }

    /** 未捕获异常 -> API.md 规定的错误响应 */
    public static function installErrorHandlers()
    {
        set_exception_handler([__CLASS__, 'handleException']);
        register_shutdown_function([__CLASS__, 'handleFatal']);
    }

    public static function handleException($e)
    {
        $status = 500;
        $code = 'server_error';
        $message = '服务器内部错误';

        if ($e instanceof HttpException) {
            $status = $e->getStatus();
            $code = $e->getErrorCode();
            $message = $e->getMessage();
        } elseif ($e instanceof DbConnectionError) {
            Log::error('数据库连接异常', ['error' => $e->detail()]);
            $status = 503;
            $code = 'db_unavailable';
            $message = '数据库暂时不可用，请按安装向导检查数据库配置';
        } elseif ($e instanceof PDOException) {
            $text = $e->getMessage();
            // MySQL 唯一键/外键冲突统一映射为 409 conflict（对应 SQLite 的 UNIQUE constraint failed）
            $sqlState = method_exists($e, 'getCode') ? (string) $e->getCode() : '';
            $sqlErrno = isset($e->errorInfo[1]) ? (int) $e->errorInfo[1] : 0;
            $isConflict = $sqlState === '23000'
                || in_array($sqlErrno, [1062, 1451, 1452], true)
                || stripos($text, 'UNIQUE constraint failed') !== false
                || stripos($text, 'Duplicate entry') !== false
                || stripos($text, 'constraint') !== false;
            if ($isConflict) {
                Log::warn('数据库约束冲突', ['error' => $text]);
                $status = 409;
                $code = 'conflict';
                $message = '数据冲突，请刷新后重试';
            } else {
                Log::error('数据库异常', ['error' => $text]);
                $message = '服务器内部错误';
            }
        } else {
            Log::error('未处理的服务端异常', [
                'error' => $e->getMessage(),
                'file' => basename($e->getFile()) . ':' . $e->getLine(),
                'path' => Http::routePath(),
            ]);
        }

        if (!headers_sent()) {
            Http::error($status, $code, $message);
        } else {
            echo json_encode(['error' => $code, 'message' => $message], JSON_UNESCAPED_UNICODE);
        }
    }

    /** 致命错误（E_ERROR 等）也要返回结构化 JSON，而不是空白页 */
    public static function handleFatal()
    {
        $err = error_get_last();
        if ($err === null) {
            return;
        }
        $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
        if (!in_array($err['type'], $fatal, true)) {
            return;
        }
        Log::error('PHP 致命错误', [
            'message' => $err['message'],
            'file' => basename((string) $err['file']) . ':' . $err['line'],
        ]);
        if (!headers_sent()) {
            Http::error(500, 'server_error', '服务器内部错误');
        }
    }

    /**
     * Web 入口：注册路由并分发
     */
    public static function run()
    {
        self::registerAutoloader();

        // 未安装 / 数据库连不上 -> 渲染安装向导，而不是抛 Fatal error
        if (Installer::needsSetup()) {
            self::renderInstallPage(503, '尚未完成安装');
            return;
        }
        try {
            self::boot();
        } catch (DbConnectionError $e) {
            self::renderInstallPage(503, $e->getMessage());
            return;
        } catch (InstallRequired $e) {
            self::renderInstallPage(503, $e->getMessage());
            return;
        }

        Http::securityHeaders();
        $path = Http::rawPath();
        if (strpos($path, '/api/') !== false || strpos($path, '/admin/api/') !== false
            || preg_match('#/api$#', $path) || preg_match('#/admin/api$#', $path)) {
            if (!headers_sent()) {
                header('Cache-Control: no-store');
            }
        }

        $router = new Router();
        MetaRoutes::register($router);
        AuthRoutes::register($router);
        CoupleRoutes::register($router);
        SyncRoutes::register($router);
        ChatRoutes::register($router);
        EmojiRoutes::register($router);
        AiRoutes::register($router);
        AdminRoutes::register($router);

        // 后台页面（/admin、/admin/、/admin.php），直接 include 单页
        $adminPage = function () {
            if (!headers_sent()) {
                header('Content-Type: text/html; charset=utf-8');
                header('Cache-Control: no-store');
                header('X-Content-Type-Options: nosniff');
                header('Referrer-Policy: no-referrer');
            }
            include Config::publicDir() . '/admin.php';
        };
        $router->get('/admin', $adminPage);
        // 段解析会把 /admin/ 归一化成 ['admin']，这里再显式登记一次以防万一
        $router->get('/admin/', $adminPage);
        $router->get('/admin.php', $adminPage);

        // 安装向导（/install、/install.php、/install/）
        $installPage = function () {
            include Config::publicDir() . '/install.php';
        };
        $router->get('/install', $installPage);
        $router->get('/install/', $installPage);
        $router->get('/install.php', $installPage);
        $router->post('/install.php', $installPage);

        // 首页：给一个简短的说明页，避免直接访问报 404
        $router->get('/', function () {
            if (!headers_sent()) {
                header('Content-Type: text/html; charset=utf-8');
            }
            echo self::infoPage();
        });

        // 静态资源：/static/** -> public/ 目录（内置公共表情包用）
        $segments = Http::segments();
        if (count($segments) >= 2 && $segments[0] === 'static' && Http::method() === 'GET') {
            self::serveStatic(array_slice($segments, 1));
            return;
        }

        // 后台接口统一鉴权：与 Node 版 router.use(requireAdmin) 一致 ——
        // 未带后台 token 时，连「不存在的 /admin/api/* 路径」也返回 401 而不是 404
        if (count($segments) >= 2 && $segments[0] === 'admin' && $segments[1] === 'api') {
            $isLogin = count($segments) === 3 && $segments[2] === 'login';
            if (!$isLogin) {
                Auth::requireAdmin();
            }
        }

        if ($router->dispatch()) {
            return;
        }

        // 未匹配的接口统一返回 not_found
        Http::error(404, 'not_found', '接口不存在：' . Http::method() . ' ' . Http::routePath());
    }

    /**
     * 未安装 / 数据库连不上时的统一出口：渲染安装向导页面。
     *
     * 浏览器请求返回 HTML（200/503），非 HTML 请求（iOS 客户端 / curl）返回
     * 结构化 JSON（503 db_unavailable），避免客户端拿到一坨 HTML。
     */
    private static function renderInstallPage($status, $reason)
    {
        $accept = isset($_SERVER['HTTP_ACCEPT']) ? (string) $_SERVER['HTTP_ACCEPT'] : '';
        $wantsHtml = stripos($accept, 'text/html') !== false;
        $isApi = false;
        if (isset($_SERVER['REQUEST_URI'])) {
            $uri = (string) $_SERVER['REQUEST_URI'];
            $isApi = strpos($uri, '/api/') !== false || strpos($uri, '/admin/api/') !== false;
        }
        if ($isApi && !$wantsHtml) {
            Http::error(503, 'db_unavailable', '服务尚未完成安装，请先在浏览器打开 ' . Config::installUrl() . ' 完成安装');
            return;
        }

        // 渲染安装向导（含环境自检、数据库表单）
        if (!headers_sent()) {
            http_response_code((int) $status);
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-store');
            header('X-Content-Type-Options: nosniff');
            header('Referrer-Policy: no-referrer');
        }
        try {
            Installer::renderBanner($reason);
        } catch (\Throwable $e) {
            echo '服务尚未完成安装，请打开 /install.php 完成配置。';
        }
    }

    /** 输出 public/ 下的静态文件（带路径穿越防护） */
    private static function serveStatic(array $segments)
    {
        $base = realpath(Config::publicDir());
        if ($base === false) {
            Http::error(404, 'not_found', '资源不存在');
            return;
        }
        $target = realpath($base . '/' . implode('/', $segments));
        if ($target === false || strpos($target, $base . DIRECTORY_SEPARATOR) !== 0 || !is_file($target)) {
            Http::error(404, 'not_found', '资源不存在');
            return;
        }
        $mimes = [
            'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml',
            'css' => 'text/css; charset=utf-8', 'js' => 'application/javascript; charset=utf-8',
            'html' => 'text/html; charset=utf-8', 'txt' => 'text/plain; charset=utf-8',
        ];
        $ext = strtolower(pathinfo($target, PATHINFO_EXTENSION));
        if (!headers_sent()) {
            header('Content-Type: ' . (isset($mimes[$ext]) ? $mimes[$ext] : 'application/octet-stream'));
            header('Content-Length: ' . (int) filesize($target));
            header('Cache-Control: public, max-age=31536000, immutable');
            header('X-Content-Type-Options: nosniff');
        }
        readfile($target);
    }

    /** 首页说明（深色主题，全中文） */
    private static function infoPage()
    {
        $version = Config::APP_VERSION;
        $online = WsHub::onlineCount();
        return '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>情侣空间服务端（PHP 版）</title>'
            . '<style>body{background:#0f1115;color:#e6e8eb;font-family:-apple-system,BlinkMacSystemFont,"PingFang SC",sans-serif;'
            . 'display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}'
            . '.box{text-align:center;line-height:2}a{color:#ff7a9c;text-decoration:none}'
            . 'code{background:#1b1f27;padding:2px 6px;border-radius:4px}</style></head><body><div class="box">'
            . '<h1>情侣空间服务端 · PHP 版</h1>'
            . '<p>版本 <code>' . htmlspecialchars($version, ENT_QUOTES, 'UTF-8') . '</code> · 在线设备 <code>' . $online . '</code></p>'
            . '<p><a href="/admin">进入后台管理</a></p>'
            . '<p><a href="/api/health">/api/health</a> · <a href="/api/meta">/api/meta</a></p>'
            . '</div></body></html>';
    }
}
