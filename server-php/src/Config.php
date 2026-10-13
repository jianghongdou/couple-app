<?php
namespace App;

/**
 * 配置加载与启动校验。
 *
 * 读取顺序：config.php（若存在） <- 环境变量（覆盖）
 * 缺少 jwt_secret 或 admin_password 时打印中文提示并拒绝启动。
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

    /** @var array<string,mixed> */
    private static $values = [];
    /** @var string[] 启动校验错误（中文） */
    private static $errors = [];
    private static $loaded = false;

    /** server-php/ 根目录 */
    public static function root()
    {
        return dirname(__DIR__);
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
        $pwd = $values['admin_password'];
        if ($pwd === '') {
            $errors[] = '缺少配置项 admin_password（后台管理登录密码）';
        } elseif (strlen($pwd) < 6) {
            $errors[] = '配置项 admin_password 过短（当前 ' . strlen($pwd) . ' 位，至少 6 位）';
        }
        self::$errors = $errors;
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

    /**
     * 启动校验：有任何配置错误则打印全部错误并退出（不带着坏配置运行）。
     */
    public static function assertConfig()
    {
        self::load();
        if (count(self::$errors) === 0) {
            return;
        }
        $lines = ['❌ 启动失败：环境配置不完整或非法。'];
        foreach (self::$errors as $i => $e) {
            $lines[] = '   ' . ($i + 1) . ') ' . $e;
        }
        $lines[] = '';
        $lines[] = '请复制 config.example.php 为 config.php 并填写：';
        $lines[] = "   'jwt_secret'     => '<至少 32 位随机串>'";
        $lines[] = "   'admin_password' => '<后台管理密码>'";
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

    public static function dbFile()
    {
        return self::dataDir() . '/couple.sqlite';
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
