<?php
namespace App;

/**
 * 配置加载与启动校验。
 *
 * 读取顺序：环境变量（显式设置，含空串） > config.php > 默认值
 *
 * 数据库为 MySQL / MariaDB：db_host / db_port / db_name / db_user / db_pass / db_charset。
 * 首次部署（还没有 config.php，或者配置不完整）时不再直接 Fatal error，
 * 而是交给网页安装向导 public/install.php 处理（见 needsSetup()）。
 */
class Config
{
    /** 服务标识（API.md §1 /api/health 的 name 字段） */
    const APP_NAME = 'couple-server';
    /** 服务版本（API.md §1 /api/health 的 version 字段） */
    const APP_VERSION = '1.0.0';
    /** 客户端展示名（API.md §1 /api/meta 的 appName） */
    const DISPLAY_NAME = '情侣空间';

    /** 用户 token 有效期：30 天（秒） */
    const JWT_EXPIRES_IN_SECONDS = 2592000;
    /** 后台 token 有效期：12 小时（秒） */
    const ADMIN_TOKEN_TTL_SECONDS = 43200;

    /** 业务限制值（与 docs/API.md 严格一致） */
    const LIMITS = [
        'USERNAME_MIN' => 3,
        'USERNAME_MAX' => 32,
        'PASSWORD_MIN' => 6,
        'PASSWORD_MAX' => 64,
        'SYNC_MAX_OBJECTS' => 200,          // §4 单次最多 200 个对象
        'SYNC_PULL_DEFAULT' => 500,         // §4 limit 默认 500
        'SYNC_PULL_MAX' => 1000,            // §4 limit 上限 1000
        'CHAT_BODY_MAX' => 2000,            // §5 body 长度上限 2000 字符
        'CHAT_PAGE_DEFAULT' => 50,          // §5 默认 50 条
        'CHAT_RECALL_WINDOW_MS' => 86400000, // §5 撤回 24 小时内
        'COUPLE_MAX_MEMBERS' => 2,          // §3 情侣空间 2 人
        'COUPLE_RETENTION_DAYS' => 30,      // §3 解绑后数据保留 30 天
        'EMOJI_MAX_PACKS' => 30,            // §6 每个情侣空间最多 30 个自建包
        'EMOJI_MAX_ITEMS_PER_PACK' => 200,  // §6 每个包上限 200 张
        'EMOJI_MAX_BYTES' => 1048576,       // §6 解码后单张上限 1MB
        'AI_TEXT_MAX' => 2000,
        'AI_DAILY_LIMIT_DEFAULT' => 200,
        'AI_MAX_ITEMS' => 200,
        'JSON_FIELD_MAX_BYTES' => 262144,   // payload / meta 上限 256KB
        'BODY_MAX_BYTES' => 4194304,        // 请求体上限 4MB（表情走 base64）
    ];

    /** 同步 kind 白名单（§4）。ledger / vault 明确拒绝。 */
    const SYNC_KINDS = ['spark', 'pet', 'checkin', 'wish', 'worklog', 'settings'];
    /** 明确拒绝且需要给出解释的 kind（只存手机本地） */
    const SYNC_REJECTED_KINDS = ['ledger', 'vault'];

    /** 表情 mime 白名单（§6） */
    const EMOJI_MIMES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    /** 消息 kind 白名单（§5） */
    const MESSAGE_KINDS = ['text', 'emoji', 'image', 'system'];
    /** 客户端可主动发送的 kind（system 由服务端产生） */
    const CLIENT_MESSAGE_KINDS = ['text', 'emoji', 'image'];

    /** 首次启动写入 settings 表的默认值 */
    const DEFAULTS = [
        'aiEnabled' => true,
        'aiDailyLimit' => 200,
        'registerOpen' => true,
        'deepseekBaseUrl' => 'https://api.deepseek.com',
        'deepseekModel' => 'deepseek-chat',
        'announcement' => '',
        'minAppBuild' => 1,
        'latestAppBuild' => 1,
        'updateURL' => '',
        'maxEmojiPacks' => 30,
        'maxMessagesPerDay' => 3000,
        'deepseekApiKey' => '',
    ];

    /** 数据库字符集（MySQL 统一 utf8mb4） */
    const DB_CHARSET_DEFAULT = 'utf8mb4';

    /** 当前数据库结构版本（写在 settings 表的 schema_version） */
    const SCHEMA_VERSION = 2;

    /** @var array<string,mixed> */
    private static $values = [];
    /** @var string[] 启动校验错误（中文） */
    private static $errors = [];
    private static $loaded = false;
    /** @var string|null 实际读取的配置文件路径（null=不存在，'none'=显式不读文件） */
    private static $configFile = null;

    /** server-php/ 根目录 */
    public static function root()
    {
        return dirname(__DIR__);
    }

    /**
     * 配置文件路径。返回 null 表示文件不存在。
     * 自动化测试会把 COUPLE_CONFIG_FILE 设为 'none'（完全不读配置文件）。
     */
    public static function configFile()
    {
        self::load();
        return self::$configFile;
    }

    /** config.php 的完整路径（无论是否存在） */
    public static function configFilePath()
    {
        $file = getenv('COUPLE_CONFIG_FILE');
        if ($file === false) {
            return self::root() . '/config.php';
        }
        return $file;
    }

    /** 环境变量是否显式提供了数据库连接信息（测试/宝塔里可完全不用 config.php） */
    private static function hasDbEnv()
    {
        return getenv('DB_NAME') !== false || getenv('DB_USER') !== false || getenv('DB_HOST') !== false;
    }

    /**
     * 重新载入配置（清空静态缓存后再读一次）。
     *
     * 用途：网页安装向导写完 config.php 后**必须**调用一次。否则静态缓存里
     * 还是「未安装」时的旧值（db_user 为空），紧接着建表就会失败并报
     * 「MySQL 用户名不能为空，请在安装向导里填写数据库用户名」。
     */
    public static function reload()
    {
        self::$loaded = false;
        self::$values = [];
        self::$errors = [];
        self::$configFile = null;
        self::load();
    }

    /**
     * 载入配置（幂等）。
     */
    public static function load()
    {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;

        // 配置文件路径：默认 server-php/config.php；
        // 环境变量 COUPLE_CONFIG_FILE 可指定其它路径，设为 none 表示「不读配置文件」
        // （自动化测试用，保证测试结果不受开发者本机 config.php 影响）
        $file = getenv('COUPLE_CONFIG_FILE');
        if ($file === false) {
            $file = self::root() . '/config.php';
        }
        $fromFile = [];
        if ($file !== 'none' && $file !== '' && is_file($file)) {
            /** @var mixed $loaded */
            $loaded = require $file;
            if (is_array($loaded)) {
                $fromFile = $loaded;
            }
            self::$configFile = $file;
        } elseif ($file === 'none') {
            self::$configFile = 'none';
        } else {
            self::$configFile = null;
        }

        /**
         * 取值优先级：环境变量（显式设置，含空串） > config.php > 默认值
         * 这样测试/宝塔里可以直接用环境变量覆盖配置文件。
         */
        $pick = function ($key, $env, $default) use ($fromFile) {
            $e = getenv($env);
            if ($e !== false) {
                return $e;
            }
            if (array_key_exists($key, $fromFile) && $fromFile[$key] !== null) {
                return $fromFile[$key];
            }
            return $default;
        };

        $values = [];
        $values['jwt_secret'] = (string) $pick('jwt_secret', 'JWT_SECRET', '');
        $values['admin_password'] = (string) $pick('admin_password', 'ADMIN_PASSWORD', '');
        $values['admin_jwt_secret'] = (string) $pick('admin_jwt_secret', 'ADMIN_JWT_SECRET', '');
        $values['deepseek_api_key'] = (string) $pick('deepseek_api_key', 'DEEPSEEK_API_KEY', '');

        $dataDir = (string) $pick('data_dir', 'DATA_DIR', './data');
        $values['data_dir'] = $dataDir === '' ? './data' : $dataDir;

        $values['public_base_url'] = rtrim((string) $pick('public_base_url', 'PUBLIC_BASE_URL', ''), '/');

        // ── MySQL / MariaDB 连接参数（安装向导会把它们写进 config.php）──────────
        $dbHost = (string) $pick('db_host', 'DB_HOST', '127.0.0.1');
        $values['db_host'] = $dbHost === '' ? '127.0.0.1' : $dbHost;

        $dbPort = (string) $pick('db_port', 'DB_PORT', '3306');
        $values['db_port'] = $dbPort === '' ? 3306 : (int) $dbPort;

        $values['db_name'] = (string) $pick('db_name', 'DB_NAME', '');
        $values['db_user'] = (string) $pick('db_user', 'DB_USER', '');
        $values['db_pass'] = (string) $pick('db_pass', 'DB_PASS', '');

        $charset = strtolower(trim((string) $pick('db_charset', 'DB_CHARSET', self::DB_CHARSET_DEFAULT)));
        // 只接受合法的字符集名，避免被拼进 DSN
        $values['db_charset'] = preg_match('/^[a-z0-9_]{3,20}$/', $charset) ? $charset : self::DB_CHARSET_DEFAULT;

        $values['db_ssl_ca'] = (string) $pick('db_ssl_ca', 'DB_SSL_CA', '');

        $timezone = (string) $pick('timezone', 'TIMEZONE', 'Asia/Shanghai');
        $values['timezone'] = $timezone === '' ? 'Asia/Shanghai' : $timezone;

        $port = (string) $pick('port', 'PORT', '8787');
        $values['port'] = $port === '' ? 8787 : (int) $port;

        $values['trust_proxy'] = self::toBool($pick('trust_proxy', 'TRUST_PROXY', true), true);

        $logLevel = (string) $pick('log_level', 'LOG_LEVEL', 'info');
        $values['log_level'] = $logLevel === '' ? 'info' : strtolower($logLevel);

        $wsHost = (string) $pick('ws_host', 'WS_HOST', '0.0.0.0');
        $values['ws_host'] = $wsHost === '' ? '0.0.0.0' : $wsHost;

        $wsPort = (string) $pick('ws_port', 'WS_PORT', '8900');
        $values['ws_port'] = $wsPort === '' ? 8900 : (int) $wsPort;

        // 后台 token 缺省回退到用户密钥（与 Node 版一致）
        $values['admin_jwt_secret'] = $values['admin_jwt_secret'] !== ''
            ? $values['admin_jwt_secret']
            : $values['jwt_secret'];

        // 数据目录：相对路径基于 server-php/ 解析，保证从任意 cwd 启动都一致
        $dir = $values['data_dir'];
        if ($dir === '' || $dir[0] !== '/' && !preg_match('#^[A-Za-z]:[\\\\/]#', $dir)) {
            $dir = self::root() . '/' . ltrim($dir, './');
        }
        $values['data_dir'] = rtrim(str_replace('\\', '/', $dir), '/');

        self::$values = $values;

        // 启动校验（与 Node 版 assertConfig 的规则一致）
        $errors = [];
        $jwt = $values['jwt_secret'];
        if ($jwt === '') {
            $errors[] = '缺少配置项 jwt_secret（用户 token 签名密钥，≥32 位随机串）';
        } elseif (strlen($jwt) < 16) {
            $errors[] = '配置项 jwt_secret 过短（当前 ' . strlen($jwt) . ' 位，要求 ≥32 位随机串）';
        }
        // admin_password 已改为「可选」：后台密码默认存放在数据库（settings.adminPasswordHash），
        // 在后台「安全」页或 php bin/console.php set-admin-password 里设置。
        // config.php 里这一项只是「首次迁移前的初始密码」（旧版升级兼容），留空即可。
        $pwd = $values['admin_password'];
        if ($pwd !== '' && strlen($pwd) < 6) {
            $errors[] = '配置项 admin_password 过短（当前 ' . strlen($pwd) . ' 位，至少 6 位，或直接留空改用数据库里的密码）';
        }
        if ($values['db_name'] === '' || $values['db_user'] === '') {
            $errors[] = '缺少数据库配置（db_name / db_user），请通过网页安装向导生成 config.php';
        }
        self::$errors = $errors;
    }

    /**
     * 是否处于「尚未安装」状态：需要引导用户打开网页安装向导。
     *
     * 判定：没有可用的 config.php（且环境变量也没提供完整配置），
     *       或者必需的配置项（jwt_secret / admin_password / db_*）缺失。
     */
    public static function needsSetup()
    {
        self::load();
        if (count(self::$errors) === 0) {
            return false;
        }
        // COUPLE_CONFIG_FILE=none + 环境变量提供全部配置（自动化测试）时不进安装模式
        if (self::$configFile === 'none' && self::hasDbEnv()) {
            return false;
        }
        // 完全没有 config.php：环境变量提供完整数据库配置时也允许正常启动
        if (self::$configFile === null && self::hasDbEnv()
            && getenv('JWT_SECRET') !== false && getenv('ADMIN_PASSWORD') !== false) {
            return false;
        }
        return true;
    }

    /**
     * 启动校验：有任何配置错误则拒绝启动。
     *
     * 尚未安装（缺 config.php / 缺必填项）时抛 InstallRequired，
     * 由入口文件（Web）或 bin/console.php（CLI）引导到网页安装向导，
     * 不再直接抛 Fatal error。
     */
    public static function assertConfig()
    {
        self::load();
        if (count(self::$errors) === 0) {
            return;
        }
        if (self::needsSetup()) {
            throw new InstallRequired(implode('；', self::$errors));
        }
        self::fail(self::$errors);
    }

    /** 打印全部配置错误并退出（不带着坏配置运行） */
    private static function fail(array $errors)
    {
        $lines = ['❌ 启动失败：环境配置不完整或非法。'];
        foreach ($errors as $i => $e) {
            $lines[] = '   ' . ($i + 1) . ') ' . $e;
        }
        $lines[] = '';
        $lines[] = '正常部署请打开网页安装向导自动生成 config.php：';
        $lines[] = '   ' . self::installUrl();
        $lines[] = '也可以复制 config.example.php 为 config.php 手动填写：';
        $lines[] = "   'jwt_secret'     => '<至少 32 位随机串>'";
        $lines[] = "   'admin_password' => '<后台管理密码>'";
        $lines[] = "   'db_host' / 'db_port' / 'db_name' / 'db_user' / 'db_pass' => MySQL 连接信息";
        $lines[] = '生成随机密钥可执行： php -r "echo bin2hex(random_bytes(32));"';
        $msg = implode("\n", $lines) . "\n";
        // 直接写 stderr，避免依赖日志模块的初始化顺序
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, $msg);
        } else {
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            echo $msg;
        }
        exit(1);
    }

    /** 安装向导地址（Web 入口用，带上前缀便于子目录部署） */
    public static function installUrl()
    {
        return 'install.php';
    }

    private static function toBool($value, $fallback)
    {
        if ($value === null || $value === '') {
            return $fallback;
        }
        if (is_bool($value)) {
            return $value;
        }
        $s = strtolower(trim((string) $value));
        if (in_array($s, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array($s, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }
        return $fallback;
    }

    public static function get($key, $default = null)
    {
        self::load();
        return array_key_exists($key, self::$values) ? self::$values[$key] : $default;
    }

    public static function limit($key)
    {
        return self::LIMITS[$key];
    }

    public static function dataDir()
    {
        return self::get('data_dir');
    }

    // ── MySQL 连接参数读取（供 Database / Installer 使用）────────────────────

    public static function dbHost()
    {
        return (string) self::get('db_host', '127.0.0.1');
    }

    public static function dbPort()
    {
        return (int) self::get('db_port', 3306);
    }

    public static function dbName()
    {
        return (string) self::get('db_name', '');
    }

    public static function dbUser()
    {
        return (string) self::get('db_user', '');
    }

    public static function dbPass()
    {
        return (string) self::get('db_pass', '');
    }

    public static function dbCharset()
    {
        return (string) self::get('db_charset', self::DB_CHARSET_DEFAULT);
    }

    /** 数据库主机描述（日志/后台显示用，不含密码） */
    public static function dbLabel()
    {
        return self::dbHost() . ':' . self::dbPort() . '/' . self::dbName();
    }

    public static function emojiDir()
    {
        return self::dataDir() . '/emoji';
    }

    /** public/ 目录 */
    public static function publicDir()
    {
        return self::root() . '/public';
    }

    /** 内置公共表情包目录（public/emoji/<包名>/<图片>） */
    public static function publicEmojiDir()
    {
        return self::publicDir() . '/emoji';
    }

    public static function logFile()
    {
        return self::dataDir() . '/logs/app.log';
    }

    /** 确保数据目录存在 */
    public static function ensureDirs()
    {
        foreach ([self::dataDir(), self::emojiDir(), self::dataDir() . '/logs', self::publicEmojiDir()] as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
        }
    }
}
