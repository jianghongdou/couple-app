<?php
namespace App;

use PDOException;

/**
 * 网页安装向导（public/install.php 的后端逻辑）。
 *
 * 目标：宝塔面板建好数据库后，用户只需要在浏览器里填一次信息，
 *       程序自动建库、建表、生成 config.php —— 全程不改代码。
 *
 * 安全约定：
 *   1. 安装完成后（config.php 存在且数据库可用）再次访问安装向导一律 403
 *   2. 表单带 CSRF token（session），提交必须校验
 *   3. 任何错误提示都不回显数据库密码
 *   4. 只有「目录不可写」的兜底场景、且数据库已校验通过后，才把配置内容显示出来
 */
class Installer
{
    /** 允许设置的环境变量前缀（安装向导只认这几个，防止被注入任意配置） */
    private static $envKeys = [
        'jwt_secret', 'admin_password', 'admin_jwt_secret', 'deepseek_api_key',
        'data_dir', 'public_base_url', 'timezone', 'port', 'trust_proxy', 'log_level',
        'ws_host', 'ws_port',
    ];

    /** @var array<int,array{label:string,value:string,ok:bool,hint:string}> */
    private static $checks = [];

    // ==================================================================
    // 请求入口
    // ==================================================================

    /**
     * 处理 /install.php 请求（含 GET 渲染、POST 安装）。
     */
    public static function handle()
    {
        self::startSession();
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-store');
            header('X-Content-Type-Options: nosniff');
            header('Referrer-Policy: no-referrer');
        }

        // 已安装锁定：config.php 存在且数据库可用 -> 403
        if (self::isLocked()) {
            self::renderLocked();
            return;
        }

        $values = self::formValues([]);
        $errors = [];
        $notices = [];
        $configSource = null;
        $configPath = null;
        $done = false;

        if (Http::method() === 'POST') {
            $result = self::processPost($values, $errors, $notices, $configSource, $configPath);
            $done = $result;
        }

        if ($done) {
            self::renderSuccess($values, $configSource, $configPath, $notices);
            return;
        }

        self::renderForm($values, $errors, $configSource, $notices);
    }

    /** 当前是否处于「未安装」状态（用于 index.php / admin.php 引导） */
    public static function needsSetup()
    {
        try {
            return Config::needsSetup();
        } catch (\Throwable $e) {
            return true;
        }
    }

    /**
     * 由入口文件（public/index.php、public/admin.php）在「未安装/连不上库」时调用：
     * 渲染一条中文提示 + 完整安装表单。
     */
    public static function renderBanner($reason = '')
    {
        self::startSession();
        $notice = ['<b>尚未完成安装。</b>' . ($reason !== '' ? ' ' . $reason : '')
            . ' 请在下面的表单里填写数据库信息，安装向导会自动建库、建表并生成 config.php。'];
        if (self::isLocked()) {
            self::renderLocked();
            return;
        }
        $values = self::formValues([]);
        self::renderForm($values, [], null, $notice);
    }

    /**
     * 是否已安装完成（严格）：配置文件存在 + 数据库可连 + 核心表存在 + 必需项齐全。
     *
     * 安装向导的「已安装锁定」用这个判定，所以要求真的存在 config.php ——
     * 只用环境变量跑（自动化测试）的情况不会把向导误锁。
     */
    public static function isInstalled()
    {
        if (Config::needsSetup()) {
            return false;
        }
        if (Config::configFile() === null || Config::configFile() === 'none') {
            return false;
        }
        try {
            Db::init();
            return Db::hasCoreTables();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** 是否已经完成了必要的配置（允许配置全部来自环境变量，用于入口页判断） */
    public static function isConfigured()
    {
        return !self::needsSetup();
    }

    /** 已安装时拒绝再次执行安装向导 */
    private static function isLocked()
    {
        return self::isInstalled();
    }

    // ==================================================================
    // POST 处理
    // ==================================================================

    /**
     * 处理安装表单提交。
     * @return bool true 表示安装成功（渲染成功页）
     */
    private static function processPost(array &$values, array &$errors, array &$notices, &$configSource, &$configPath)
    {
        // 1) CSRF
        $token = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : '';
        if (!self::csrfValid($token)) {
            $errors[] = '页面已过期（CSRF 校验未通过）。请刷新页面后重新提交。';
            return false;
        }

        // 2) 收集字段
        $values = self::formValues($_POST);

        $host = $values['db_host'];
        $port = (int) $values['db_port'];
        $name = $values['db_name'];
        $user = $values['db_user'];
        $pass = $values['db_pass'];
        $autoCreate = $values['auto_create'] === '1';

        if ($host === '') {
            $errors[] = '数据库主机不能为空（本机一般填 127.0.0.1）。';
        }
        if ($port < 1 || $port > 65535) {
            $errors[] = '数据库端口不合法（常见为 3306）。';
        }
        if ($name === '') {
            $errors[] = '数据库名不能为空。';
        } elseif (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $name)) {
            $errors[] = '数据库名不合法：只允许字母、数字与下划线。';
        }
        if ($user === '') {
            $errors[] = '数据库用户名不能为空。';
        }

        // 3) 后台密码
        if ($values['admin_password'] === '') {
            $errors[] = '后台管理密码不能为空。';
        } elseif (strlen($values['admin_password']) < 6) {
            $errors[] = '后台管理密码至少 6 位。';
        } elseif ($values['admin_password'] !== $values['admin_password2']) {
            $errors[] = '两次输入的后台管理密码不一致。';
        }

        // 4) 站点地址（可选）
        if ($values['public_base_url'] !== '' && !preg_match('#^https?://#i', $values['public_base_url'])) {
            $errors[] = '站点地址需要以 http:// 或 https:// 开头（例如 https://love.example.com），留空也可以。';
        }

        if (count($errors) > 0) {
            return false;
        }

        // 5) 连接数据库（先不带 dbname，校验账号密码；再按需建库）
        $opts = [
            'host' => $host,
            'port' => $port,
            'name' => $name,
            'user' => $user,
            'pass' => $pass,
            'charset' => Config::DB_CHARSET_DEFAULT,
        ];
        try {
            Database::connect($opts, false);
        } catch (DbConnectionError $e) {
            Log::warn('安装向导：数据库连接失败', ['error' => $e->detail()]);
            $errors[] = $e->getMessage();
            return false;
        }

        $exists = false;
        try {
            $exists = Database::databaseExists($opts, $name);
        } catch (DbConnectionError $e) {
            $errors[] = $e->getMessage();
            return false;
        }

        if (!$exists) {
            if (!$autoCreate) {
                $errors[] = '数据库 ' . $name . ' 不存在，且没有勾选「数据库不存在时自动创建」。'
                    . '请先在宝塔面板新建该数据库，或勾选该选项。';
                return false;
            }
            try {
                Database::createDatabase($opts, $name);
                $notices[] = '已自动创建数据库 ' . $name . '（字符集 utf8mb4）。';
            } catch (DbConnectionError $e) {
                $errors[] = '数据库 ' . $name . ' 不存在，自动创建失败：' . $e->getMessage()
                    . '（也可以先在宝塔面板新建数据库再回来安装）';
                return false;
            } catch (PDOException $e) {
                $errors[] = '数据库 ' . $name . ' 不存在，自动创建失败：' . Database::shortReason($e->getMessage())
                    . '。请确认数据库账号有 CREATE 权限，或先在宝塔面板新建该数据库。';
                return false;
            }
        }

        // 6) 生成配置内容
        $config = self::buildConfig($values);
        $configSource = self::renderConfigFile($config);
        $configPath = Config::configFilePath();

        // 7) 写 config.php
        $writeError = self::writeConfigFile($configPath, $configSource);
        if ($writeError !== null) {
            $notices[] = $writeError;
            // 写文件失败时先不建表，避免「库建好了但连不上」的半成品状态不好排查；
            // 但数据库连接已校验通过，配置内容可以直接复制使用。
            return false;
        }

        // 8) 立刻建表 + 初始化默认设置（幂等），并做一次真实自检
        //    注意：必须先让 Config 丢掉启动时缓存的旧配置（那时 config.php 还不存在，
        //    db_user 是空的），否则 Db::init() 会读到空值并报「MySQL 用户名不能为空」。
        Config::reload();
        try {
            Db::close();
            Db::init();
            if (!Db::hasCoreTables()) {
                throw new \RuntimeException('数据表创建后自检失败');
            }
            // 后台管理员密码：安装时就以 bcrypt 哈希写进数据库（config.php 里不再保存明文密码）
            Settings::setAdminPasswordHash(Auth::hashAdminPassword($values['admin_password']));
        } catch (\Throwable $e) {
            @unlink($configPath);
            Config::reload();   // 已回滚配置，恢复「未安装」状态的缓存
            $msg = $e instanceof PDOException ? Database::shortReason($e->getMessage()) : $e->getMessage();
            $errors[] = '数据表初始化失败：' . $msg . '（已回滚生成的 config.php，可修正数据库信息后重新提交）';
            return false;
        }

        $notices[] = '数据表已创建（若已存在则自动跳过），默认设置已写入。';
        Log::info('安装向导完成', ['db' => $host . ':' . $port . '/' . $name]);
        return true;
    }

    // ==================================================================
    // config.php 生成
    // ==================================================================

    /** 组装完整配置数组（含随机 jwt_secret） */
    private static function buildConfig(array $values)
    {
        $conf = [
            // 安装向导自动生成，用户不需要关心
            'jwt_secret' => bin2hex(random_bytes(32)),
            'admin_jwt_secret' => '',
            'admin_password' => '',   // 后台密码存在数据库（settings.adminPasswordHash），这里留空
            'deepseek_api_key' => '',
            'db_host' => $values['db_host'],
            'db_port' => (int) $values['db_port'],
            'db_name' => $values['db_name'],
            'db_user' => $values['db_user'],
            'db_pass' => $values['db_pass'],
            'db_charset' => Config::DB_CHARSET_DEFAULT,
            'data_dir' => './data',
            'public_base_url' => $values['public_base_url'],
            'timezone' => $values['timezone'] !== '' ? $values['timezone'] : 'Asia/Shanghai',
            'port' => 8787,
            'trust_proxy' => true,
            'log_level' => 'info',
            'ws_host' => '0.0.0.0',
            'ws_port' => 8900,
        ];
        // 允许用 COUPLE_INSTALL_DEFAULTS 覆盖非敏感默认项（测试/高级部署用）
        $extra = getenv('COUPLE_INSTALL_DEFAULTS');
        if ($extra !== false && $extra !== '') {
            $json = json_decode($extra, true);
            if (is_array($json)) {
                foreach (self::$envKeys as $k) {
                    if (array_key_exists($k, $json) && $k !== 'admin_password' && $k !== 'jwt_secret') {
                        $conf[$k] = $json[$k];
                    }
                }
            }
        }
        return $conf;
    }

    /** 渲染 config.php 文件内容（PHP 数组字面量） */
    public static function renderConfigFile(array $conf)
    {
        $lines = [];
        $lines[] = '<?php';
        $lines[] = '/**';
        $lines[] = ' * 情侣空间服务端（PHP 版）· 配置文件';
        $lines[] = ' *';
        $lines[] = ' * 本文件由网页安装向导自动生成（' . date('Y-m-d H:i:s') . '）。';
        $lines[] = ' * 需要改数据库信息时，可以直接编辑本文件，或删除本文件后重新运行安装向导。';
        $lines[] = ' * 注意：本文件含有数据库密码与签名密钥，不要提交到版本库、也不要公开。';
        $lines[] = ' */';
        $lines[] = '';
        $lines[] = 'return [';
        foreach ($conf as $k => $v) {
            $lines[] = '    ' . self::exportKey($k) . ' => ' . self::exportValue($v) . ',';
        }
        $lines[] = '];';
        $lines[] = '';
        return implode("\n", $lines);
    }

    private static function exportKey($key)
    {
        return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], (string) $key) . "'";
    }

    /** 用 var_export 生成字面量，避免手写转义出错 */
    private static function exportValue($value)
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            return (string) $value;
        }
        return var_export((string) $value, true);
    }

    /**
     * 写 config.php。
     * @return string|null null 表示写入成功，否则返回中文错误说明
     */
    private static function writeConfigFile($path, $content)
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            return '配置目录不存在：' . $dir;
        }
        if (!is_writable($dir)) {
            return '代码目录不可写，无法自动生成 config.php：' . $dir
                . '。请在服务器上执行 chown -R www:www ' . $dir
                . '（宝塔里 PHP 默认运行用户是 www），或按下方内容手动创建 config.php。';
        }
        if (is_file($path) && !is_writable($path)) {
            return 'config.php 存在但不可写：' . $path
                . '。请执行 chown -R www:www ' . $dir . ' 或按下方内容手动覆盖该文件。';
        }

        $tmp = $path . '.tmp' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $content, LOCK_EX) === false) {
            @unlink($tmp);
            return '写入 config.php 失败（临时文件创建失败）。请检查目录权限：chown -R www:www ' . $dir;
        }
        @chmod($tmp, 0640);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            return '写入 config.php 失败（重命名失败）。请检查目录权限：chown -R www:www ' . $dir;
        }
        @chmod($path, 0640);
        clearstatcache(true, $path);
        return null;
    }

    // ==================================================================
    // 环境自检
    // ==================================================================

    /** 环境自检项（安装前就展示，帮助排查） */
    public static function envChecks($probeDb = true)
    {
        if (count(self::$checks) > 0) {
            return self::$checks;
        }
        $checks = [];

        $phpOk = version_compare(PHP_VERSION, '7.4.0', '>=');
        $checks[] = [
            'label' => 'PHP 版本',
            'value' => PHP_VERSION . (PHP_SAPI ? '（' . PHP_SAPI . '）' : ''),
            'ok' => $phpOk,
            'hint' => $phpOk ? '' : '需要 PHP 7.4 及以上（推荐 8.0+）',
        ];

        $hasPdoMysql = extension_loaded('pdo_mysql');
        $checks[] = [
            'label' => 'pdo_mysql 扩展',
            'value' => $hasPdoMysql ? '已加载' : '未加载',
            'ok' => $hasPdoMysql,
            'hint' => $hasPdoMysql ? '' : '宝塔面板 → 软件商店 → PHP → 安装扩展 → pdo_mysql，然后重启 PHP',
        ];

        foreach ([
            'curl' => '用于调用 DeepSeek AI 接口',
            'openssl' => '用于加密存储 DeepSeek Key、签发登录 token',
            'mbstring' => '用于中文昵称/消息的长度校验',
            'json' => '用于请求体与设置解析',
        ] as $ext => $why) {
            $ok = extension_loaded($ext);
            $checks[] = [
                'label' => $ext . ' 扩展',
                'value' => $ok ? '已加载' : '未加载',
                'ok' => $ok,
                'hint' => $ok ? '' : '缺少该扩展：' . $why,
            ];
        }

        $dir = dirname(Config::configFilePath());
        $writable = is_dir($dir) && is_writable($dir);
        $checks[] = [
            'label' => '代码目录可写',
            'value' => $dir . '（' . ($writable ? '可写' : '不可写') . '）',
            'ok' => $writable,
            'hint' => $writable ? '' : '生成 config.php 需要写权限，请在服务器执行：chown -R www:www ' . $dir,
        ];

        $dataDir = Config::dataDir();
        $dataOk = is_dir($dataDir) ? is_writable($dataDir) : is_writable(dirname($dataDir));
        $checks[] = [
            'label' => '数据目录可写',
            'value' => $dataDir . '（' . ($dataOk ? '可写' : '不可写') . '）',
            'ok' => $dataOk,
            'hint' => $dataOk ? '' : '表情图片与日志需要写权限：chown -R www:www ' . $dataDir,
        ];

        // 数据库连接测试（用已经填过的值，若没有则用默认值探一次本机）
        if ($probeDb) {
            $checks[] = self::probeDatabase(self::formValues([]));
        }

        self::$checks = $checks;
        return $checks;
    }

    /** 用表单里的值测一次数据库连接（不会回显密码） */
    private static function probeDatabase(array $values)
    {
        $label = '数据库连接';
        $opts = [
            'host' => $values['db_host'],
            'port' => (int) $values['db_port'],
            'name' => $values['db_name'],
            'user' => $values['db_user'],
            'pass' => $values['db_pass'],
            'charset' => Config::DB_CHARSET_DEFAULT,
        ];
        if ($opts['user'] === '' || $opts['name'] === '') {
            return [
                'label' => $label,
                'value' => '未填写数据库账号，跳过测试',
                'ok' => true,
                'hint' => '填写数据库名与用户名后，这里会显示真实连接结果',
            ];
        }
        try {
            Database::connect($opts, true);
            return [
                'label' => $label,
                'value' => '连接成功（' . $opts['host'] . ':' . $opts['port'] . '/' . $opts['name'] . '）',
                'ok' => true,
                'hint' => '',
            ];
        } catch (DbConnectionError $e) {
            try {
                Database::connect($opts, false);
            } catch (DbConnectionError $e2) {
                return ['label' => $label, 'value' => '连接失败', 'ok' => false, 'hint' => $e2->getMessage()];
            }
            return [
                'label' => $label,
                'value' => '账号可用，但数据库 ' . $opts['name'] . ' 还不存在',
                'ok' => true,
                'hint' => '勾选「数据库不存在时自动创建」即可自动建库',
            ];
        }
    }

    // ==================================================================
    // 表单 / 输出
    // ==================================================================

    /** 表单默认值 + 提交值归一化 */
    public static function formValues(array $src)
    {
        $pick = function ($key, $default) use ($src) {
            if (array_key_exists($key, $src)) {
                $v = $src[$key];
                return is_string($v) ? trim($v) : $default;
            }
            return $default;
        };
        $fromEnv = [];
        $envJson = getenv('COUPLE_INSTALL_DEFAULTS');
        if ($envJson !== false && $envJson !== '') {
            $json = json_decode($envJson, true);
            if (is_array($json)) {
                $fromEnv = $json;
            }
        }
        $envPick = function ($key, $default) use ($fromEnv) {
            return array_key_exists($key, $fromEnv) ? (string) $fromEnv[$key] : $default;
        };

        return [
            'db_host' => $pick('db_host', $envPick('db_host', '127.0.0.1')),
            'db_port' => $pick('db_port', $envPick('db_port', '3306')),
            'db_name' => $pick('db_name', $envPick('db_name', '')),
            'db_user' => $pick('db_user', $envPick('db_user', '')),
            'db_pass' => array_key_exists('db_pass', $src) ? (string) $src['db_pass'] : $envPick('db_pass', ''),
            'auto_create' => $pick('auto_create', '1') === '1' ? '1' : '0',
            'admin_password' => array_key_exists('admin_password', $src) ? (string) $src['admin_password'] : '',
            'admin_password2' => array_key_exists('admin_password2', $src) ? (string) $src['admin_password2'] : '',
            'public_base_url' => $pick('public_base_url', ''),
            'timezone' => $pick('timezone', 'Asia/Shanghai'),
        ];
    }

    // ── CSRF ────────────────────────────────────────────────────────────

    private static function startSession()
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        if (headers_sent()) {
            return;
        }
        @session_start();
    }

    public static function csrfToken()
    {
        self::startSession();
        if (!isset($_SESSION['couple_install_csrf']) || !is_string($_SESSION['couple_install_csrf'])
            || $_SESSION['couple_install_csrf'] === '') {
            $_SESSION['couple_install_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['couple_install_csrf'];
    }

    public static function csrfValid($token)
    {
        self::startSession();
        $expected = isset($_SESSION['couple_install_csrf']) ? $_SESSION['couple_install_csrf'] : '';
        if (!is_string($expected) || $expected === '' || !is_string($token) || $token === '') {
            return false;
        }
        return hash_equals($expected, $token);
    }

    // ── 页面渲染 ────────────────────────────────────────────────────────

    private static function renderLocked()
    {
        http_response_code(403);
        echo self::page(
            '安装向导已锁定',
            '<div class="alert err"><b>已安装。</b>如需重装请先删除 <code>config.php</code>。</div>'
            . '<p class="dim">当前站点已经完成安装，为避免数据库被覆盖，安装向导不再执行任何操作。</p>'
            . '<p class="dim">如果你确实要重装：在服务器上删除 <code>'
            . self::e(dirname(Config::configFilePath()) . '/config.php')
            . '</code>，然后重新打开本页面。</p>'
            . '<p><a class="btn" href="/admin.php">进入后台管理</a> <a class="btn ghost" href="/api/health">查看 /api/health</a></p>'
        );
    }

    private static function renderForm(array $values, array $errors, $configSource, array $notices)
    {
        // 安全：数据库密码绝不回显到页面上（用户的浏览器/密码管理器里会记住）
        $values['db_pass'] = '';
        $values['admin_password'] = '';
        $values['admin_password2'] = '';

        $checks = self::envChecks(true);
        $checkHtml = '';
        foreach ($checks as $c) {
            $checkHtml .= '<li class="' . ($c['ok'] ? 'ok' : 'bad') . '">'
                . '<span class="mark">' . ($c['ok'] ? '✔' : '✘') . '</span>'
                . '<span class="chk-label">' . self::e($c['label']) . '</span>'
                . '<span class="chk-value">' . self::e($c['value']) . '</span>'
                . ($c['hint'] !== '' ? '<div class="chk-hint">' . self::e($c['hint']) . '</div>' : '')
                . '</li>';
        }

        $body = '';
        if (count($errors) > 0) {
            $body .= '<div class="alert err"><b>安装未完成，请修正以下问题：</b><ul>';
            foreach ($errors as $e) {
                $body .= '<li>' . self::e($e) . '</li>';
            }
            $body .= '</ul></div>';
        }
        foreach ($notices as $n) {
            $body .= '<div class="alert warn">' . self::e($n) . '</div>';
        }

        $body .= '<h2>一、环境自检</h2><ul class="checks">' . $checkHtml . '</ul>';
        $body .= '<h2>二、数据库信息</h2>'
            . '<p class="dim">在宝塔面板「数据库」页面添加数据库后，把这里的信息填进来即可（数据库不存在时下面的选项会自动建库）。</p>'
            . '<form method="post" action="install.php" autocomplete="off">'
            . '<input type="hidden" name="csrf_token" value="' . self::e(self::csrfToken()) . '">'
            . '<div class="grid">'
            . self::field('数据库主机', 'db_host', $values['db_host'], '本机填 127.0.0.1')
            . self::field('数据库端口', 'db_port', $values['db_port'], '默认 3306（宝塔里可能不同）')
            . self::field('数据库名', 'db_name', $values['db_name'], '例如 couple')
            . self::field('数据库用户名', 'db_user', $values['db_user'], '宝塔里创建的数据库账号')
            . self::field('数据库密码', 'db_pass', $values['db_pass'], '不会回显到页面上', 'password')
            . '</div>'
            . '<label class="check-line"><input type="checkbox" name="auto_create" value="1"'
            . ($values['auto_create'] === '1' ? ' checked' : '') . '> 数据库不存在时自动创建（需要账号有 CREATE 权限）</label>'
            . '<h2>三、后台管理密码</h2>'
            . '<div class="grid">'
            . self::field('后台管理员密码', 'admin_password', $values['admin_password'], '至少 6 位，登录 /admin.php 用', 'password')
            . self::field('确认密码', 'admin_password2', $values['admin_password2'], '再输入一次', 'password')
            . '</div>'
            . '<h2>四、可选设置</h2>'
            . '<div class="grid">'
            . self::field('站点地址', 'public_base_url', $values['public_base_url'], '例如 https://love.example.com（留空则表情用相对地址）')
            . self::field('时区', 'timezone', $values['timezone'], '默认 Asia/Shanghai')
            . '</div>'
            . '<p class="dim">登录密钥（jwt_secret）会由程序自动生成随机值，你不需要填写。</p>'
            . '<button class="btn primary" type="submit">开始安装</button>'
            . '</form>';

        if ($configSource !== null) {
            $body .= '<h2>兜底：手动创建 config.php</h2>'
                . '<p class="dim">因为上面的目录不可写，程序无法自动生成配置文件。'
                . '数据库连接已经校验通过，请把下面的内容保存为 <code>'
                . self::e(dirname(Config::configFilePath()) . '/config.php') . '</code>（权限建议 0640）：</p>'
                . '<textarea class="conf" rows="18" readonly onclick="this.select()">' . self::e($configSource) . '</textarea>';
        }

        echo self::page('情侣空间 · 安装向导', $body);
    }

    private static function renderSuccess(array $values, $configSource, $configPath, array $notices)
    {
        // 成功页不回显任何密码
        $values['db_pass'] = '';
        $values['admin_password'] = '';
        $values['admin_password2'] = '';
        $noticesHtml = '';
        foreach ($notices as $n) {
            $noticesHtml .= '<div class="alert ok">' . self::e($n) . '</div>';
        }
        $body = '<div class="alert ok"><b>🎉 安装完成！</b>数据库已就绪，配置文件已生成。</div>'
            . $noticesHtml
            . '<h2>接下来</h2>'
            . '<ul class="next">'
            . '<li>后台管理：<a href="/admin.php">/admin.php</a>（用刚才设置的后台密码登录）</li>'
            . '<li>健康自检：<a href="/api/health">/api/health</a>（应返回 ok:true）</li>'
            . '<li>配置文件：<code>' . self::e($configPath) . '</code>（权限 0640，含密钥，请勿公开）</li>'
            . '<li>实时推送（可选）：用宝塔「进程守护管理器」常驻运行 <code>php bin/ws-server.php</code></li>'
            . '<li>建议：安装完成后删除本文件（<code>public/install.php</code>）或忽略它 —— '
            . '已安装状态下它只会返回 403，不会重复执行。</li>'
            . '</ul>'
            . '<p><a class="btn" href="/admin.php">进入后台管理</a> <a class="btn ghost" href="/api/health">打开 /api/health 自检</a></p>';

        if ($configSource !== null && !is_file($configPath)) {
            $body .= '<h2>兜底：手动创建 config.php</h2>'
                . '<textarea class="conf" rows="18" readonly onclick="this.select()">' . self::e($configSource) . '</textarea>';
        }
        echo self::page('安装完成 · 情侣空间', $body);
    }

    private static function field($label, $name, $value, $hint = '', $type = 'text')
    {
        return '<div class="field"><label for="' . self::e($name) . '">' . self::e($label) . '</label>'
            . '<input type="' . self::e($type) . '" id="' . self::e($name) . '" name="' . self::e($name) . '" value="'
            . self::e((string) $value) . '" autocomplete="off" spellcheck="false">'
            . ($hint !== '' ? '<div class="hint">' . self::e($hint) . '</div>' : '')
            . '</div>';
    }

    private static function e($text)
    {
        return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
    }

    /** 统一页面外壳（深色、全中文、手机可看） */
    private static function page($title, $body)
    {
        $t = self::e($title);
        return '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">'
            . '<meta name="color-scheme" content="dark">'
            . '<title>' . $t . '</title><style>'
            . ':root{--bg:#0f1115;--panel:#1a1f29;--border:#2a323d;--text:#e6e8eb;--dim:#9aa4b2;'
            . '--pink:#ff7a9c;--green:#3ddc97;--amber:#ffb454;--red:#ff5d6c}'
            . '*{box-sizing:border-box}body{margin:0;background:radial-gradient(900px 520px at 10% -8%,#1d2432 0%,var(--bg) 60%) no-repeat,var(--bg);'
            . 'color:var(--text);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif;'
            . 'font-size:14px;line-height:1.7;min-height:100vh;padding:20px}'
            . '.wrap{max-width:720px;margin:0 auto}h1{font-size:22px;margin:8px 0 4px}h2{font-size:16px;margin:26px 0 10px;color:var(--pink)}'
            . '.sub{color:var(--dim);margin:0 0 18px}a{color:var(--pink)}code{background:#232a35;padding:1px 5px;border-radius:4px;font-size:12.5px}'
            . '.card{background:var(--panel);border:1px solid var(--border);border-radius:14px;padding:16px 16px 20px}'
            . 'ul.checks{list-style:none;margin:0;padding:0}'
            . 'ul.checks li{border-bottom:1px dashed var(--border);padding:7px 0;display:flex;flex-wrap:wrap;gap:8px;align-items:baseline}'
            . 'ul.checks li:last-child{border-bottom:0}.mark{width:18px;text-align:center}'
            . 'li.ok .mark{color:var(--green)}li.bad .mark{color:var(--red)}'
            . '.chk-label{min-width:130px;color:var(--dim)}.chk-value{word-break:break-all}'
            . '.chk-hint{flex-basis:100%;color:var(--amber);font-size:13px;padding-left:26px}'
            . '.grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}'
            . '@media(max-width:560px){.grid{grid-template-columns:1fr}}'
            . '.field label{display:block;color:var(--dim);font-size:13px;margin-bottom:4px}'
            . 'input[type=text],input[type=password]{width:100%;padding:9px 11px;border-radius:9px;border:1px solid var(--border);'
            . 'background:#12161d;color:var(--text);font-size:14px;font-family:inherit}'
            . 'input:focus{outline:none;border-color:var(--pink)}.hint{color:#6b7686;font-size:12px;margin-top:4px}'
            . '.check-line{display:block;margin:14px 0 4px;color:var(--dim)}'
            . '.btn{display:inline-block;margin-top:12px;padding:10px 18px;border-radius:10px;border:1px solid var(--border);'
            . 'background:#232a35;color:var(--text);text-decoration:none;cursor:pointer;font-size:14px}'
            . '.btn.primary{background:linear-gradient(135deg,#ff7a9c,#ff5d8f);border-color:transparent;color:#1a0d13;font-weight:600}'
            . '.btn.ghost{background:transparent}'
            . '.alert{border-radius:10px;padding:11px 13px;margin:14px 0;border:1px solid var(--border);background:#1d2330}'
            . '.alert.err{border-color:#6b2530;background:#2a161c}.alert.err ul{margin:8px 0 0;padding-left:20px}'
            . '.alert.ok{border-color:#1f5c46;background:#12251f}.alert.warn{border-color:#6b5223;background:#2a2216;color:#ffd79b}'
            . '.conf{width:100%;margin-top:8px;background:#12161d;color:#cfe8ff;border:1px solid var(--border);border-radius:9px;'
            . 'padding:10px;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px}'
            . 'ul.next{padding-left:20px}.dim{color:var(--dim)}.mono{font-family:ui-monospace,Menlo,Consolas,monospace}'
            . '</style></head><body><div class="wrap">'
            . '<h1>情侣空间服务端 · 安装向导</h1>'
            . '<p class="sub">PHP 版 · MySQL / MariaDB · 填一次信息即可自动建库、建表并生成 config.php</p>'
            . '<div class="card">' . $body . '</div>'
            . '</div></body></html>';
    }
}
