<?php
namespace App;

/**
 * settings 表读写服务。
 *
 * 安全要点：
 *   1. DeepSeek API Key 落库前用 AES-256-GCM 加密（密钥由 jwt_secret 派生），
 *      即使数据库文件泄漏也不会直接暴露明文 Key。
 *   2. 对外（后台接口）一律只返回掩码，绝不返回明文。
 *   3. 明文 Key 仅存在于服务端内存与 settings 表的加密值中。
 *   4. 后台管理员密码以 bcrypt 哈希存在 settings.adminPasswordHash，
 *      任何接口都不会返回该哈希（连 readAll() 都不带它），只暴露
 *      adminPasswordInDatabase / adminPasswordSource 两个布尔/枚举值。
 */
class Settings
{
    /** 加密标记前缀，便于识别历史明文值（兼容旧数据） */
    const ENC_PREFIX = 'enc:v1:';

    /** 后台管理员密码哈希在 settings 表中的键 */
    const ADMIN_PASSWORD_HASH_KEY = 'adminPasswordHash';

    /**
     * 绝不进入 Settings::readAll() 的敏感键（避免被 ReadAll 的调用方整体回吐）。
     * 需要时用 adminPasswordHash() 单独读取。
     */
    const INTERNAL_KEYS = ['adminPasswordHash'];

    /** @var array|null 请求内缓存 */
    private static $cache = null;

    /** 由 jwt_secret 派生 32 字节加密密钥 */
    private static function deriveKey()
    {
        return hash('sha256', 'couple-server:settings:' . Config::get('jwt_secret'), true);
    }

    /**
     * 加密字符串（AES-256-GCM）
     * 输出格式： enc:v1:<iv_base64>:<tag_base64>:<cipher_base64>
     */
    public static function encryptSecret($plain)
    {
        if (!is_string($plain) || $plain === '') {
            return '';
        }
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', self::deriveKey(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new \RuntimeException('DeepSeek Key 加密失败');
        }
        return self::ENC_PREFIX . base64_encode($iv) . ':' . base64_encode($tag) . ':' . base64_encode($cipher);
    }

    /** 解密字符串；非加密格式（历史明文）原样返回 */
    public static function decryptSecret($value)
    {
        if (!is_string($value) || $value === '') {
            return '';
        }
        if (strpos($value, self::ENC_PREFIX) !== 0) {
            return $value; // 兼容历史明文
        }
        $rest = substr($value, strlen(self::ENC_PREFIX));
        $parts = explode(':', $rest);
        if (count($parts) !== 3) {
            return '';
        }
        $iv = base64_decode($parts[0], true);
        $tag = base64_decode($parts[1], true);
        $data = base64_decode($parts[2], true);
        if ($iv === false || $tag === false || $data === false) {
            return '';
        }
        $plain = openssl_decrypt($data, 'aes-256-gcm', self::deriveKey(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($plain === false) {
            Log::warn('DeepSeek Key 解密失败（可能是 jwt_secret 已变更）');
            return '';
        }
        return $plain;
    }

    /**
     * 生成掩码：只保留首尾少量字符，中间用星号。
     * 例：sk-1234567890abcd -> sk-****abcd
     */
    public static function maskSecret($plain)
    {
        if (!is_string($plain) || $plain === '') {
            return '';
        }
        $len = strlen($plain);
        if ($len <= 4) {
            return str_repeat('*', $len);
        }
        if ($len <= 8) {
            return substr($plain, 0, 2) . str_repeat('*', $len - 4) . substr($plain, -2);
        }
        $head = strpos($plain, 'sk-') === 0 ? 'sk-' : substr($plain, 0, 3);
        $tail = substr($plain, -4);
        $stars = str_repeat('*', max(4, min(12, $len - strlen($head) - 4)));
        return $head . $stars . $tail;
    }

    /** 对外暴露的设置字段（与 API.md §9 的 GET /admin/api/settings 响应一致） */
    const PUBLIC_KEYS = [
        'deepseekBaseUrl',
        'deepseekModel',
        'aiEnabled',
        'aiDailyLimit',
        'registerOpen',
        'announcement',
        'minAppBuild',
        'latestAppBuild',
        'updateURL',
        'maxEmojiPacks',
        'maxMessagesPerDay',
    ];

    /**
     * 读取全部设置（含解密后的明文 Key，仅供服务端内部使用）
     */
    public static function readAll()
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $raw = Db::getAllSettings();
        $out = Config::DEFAULTS;
        foreach ($raw as $k => $v) {
            if (in_array($k, self::INTERNAL_KEYS, true)) {
                continue; // 后台密码哈希等敏感键不进 readAll()
            }
            $out[$k] = $v;
        }
        $out['deepseekApiKey'] = self::decryptSecret(isset($raw['deepseekApiKey']) ? $raw['deepseekApiKey'] : '');
        self::$cache = $out;
        return $out;
    }

    /** 清空请求内缓存（写入后调用） */
    public static function flush()
    {
        self::$cache = null;
    }

    /** 后台接口用的设置视图：Key 只返回掩码 */
    public static function forAdmin()
    {
        $s = self::readAll();
        $view = [
            'deepseekApiKeyMasked' => self::maskSecret($s['deepseekApiKey']),
            'deepseekApiKeyConfigured' => $s['deepseekApiKey'] !== '',
            // 后台密码只暴露「存在哪」，绝不返回哈希本身
            'adminPasswordInDatabase' => self::adminPasswordInDatabase(),
            'adminPasswordSource' => self::adminPasswordSource(),
        ];
        foreach (self::PUBLIC_KEYS as $key) {
            $view[$key] = array_key_exists($key, $s) ? $s[$key] : null;
        }
        return $view;
    }

    // ------------------------------------------------------------------
    // 后台管理员密码（bcrypt 哈希存 settings.adminPasswordHash）
    // ------------------------------------------------------------------

    /**
     * 读取后台密码哈希（数据库里没有则返回空字符串）。
     * 绕开 readAll() 的缓存与视图，保证改完密码立刻生效。
     */
    public static function adminPasswordHash()
    {
        try {
            $v = Db::getSetting(self::ADMIN_PASSWORD_HASH_KEY);
        } catch (\Exception $e) {
            Log::warn('读取后台密码哈希失败', ['error' => $e->getMessage()]);
            return '';
        }
        return is_string($v) ? trim($v) : '';
    }

    /** 后台密码是否已存于数据库（false 表示还在用 config.php 的初始密码） */
    public static function adminPasswordInDatabase()
    {
        return self::adminPasswordHash() !== '';
    }

    /** 后台密码来源：database（数据库哈希）/ config（config.php 兜底密码） */
    public static function adminPasswordSource()
    {
        return self::adminPasswordInDatabase() ? 'database' : 'config';
    }

    /**
     * 写入后台密码哈希（bcrypt）。
     * @param string $hash password_hash() 的产物
     */
    public static function setAdminPasswordHash($hash)
    {
        if (!is_string($hash) || $hash === '') {
            throw new \RuntimeException('后台密码哈希不能为空');
        }
        Db::setSetting(self::ADMIN_PASSWORD_HASH_KEY, $hash);
        self::flush();
    }

    /**
     * 删除后台密码哈希 —— 后台密码回到 config.php 里的初始密码（忘记密码的救援路径）。
     * 注意：本方法不写入空值，而是直接删除该行，避免留下空哈希。
     */
    public static function clearAdminPasswordHash()
    {
        Db::run('DELETE FROM settings WHERE `key` = ?', [self::ADMIN_PASSWORD_HASH_KEY]);
        self::flush();
    }

    /** 允许通过后台修改的字段及其类型校验 */
    const WRITABLE = [
        'deepseekApiKey' => 'string',
        'deepseekBaseUrl' => 'string',
        'deepseekModel' => 'string',
        'aiEnabled' => 'boolean',
        'aiDailyLimit' => 'int',
        'registerOpen' => 'boolean',
        'announcement' => 'string',
        'minAppBuild' => 'int',
        'latestAppBuild' => 'int',
        'updateURL' => 'string',
        'maxEmojiPacks' => 'int',
        'maxMessagesPerDay' => 'int',
    ];

    /**
     * 更新设置（只处理传入的字段），返回更新后的后台视图
     * @param array<string,mixed> $patch
     */
    public static function update(array $patch)
    {
        $entries = [];
        foreach ($patch as $key => $value) {
            if (!isset(self::WRITABLE[$key])) {
                continue; // 忽略未知字段，避免脏数据
            }
            $type = self::WRITABLE[$key];
            if ($type === 'string') {
                if ($value === null) {
                    continue;
                }
                if (!is_string($value) && !is_numeric($value)) {
                    continue;
                }
                $entries[$key] = (string) $value;
            } elseif ($type === 'boolean') {
                if (!is_bool($value)) {
                    continue;
                }
                $entries[$key] = $value;
            } else { // int
                if (is_bool($value) || $value === null || $value === '') {
                    continue;
                }
                if (!is_int($value) && !(is_float($value) && floor($value) === $value)
                    && !(is_string($value) && preg_match('/^\d+$/', $value))) {
                    continue;
                }
                $n = (int) $value;
                if ($n < 0) {
                    continue;
                }
                $entries[$key] = $n;
            }
        }

        // Key 单独处理：加密后落库；空字符串表示清空
        if (array_key_exists('deepseekApiKey', $entries)) {
            $plain = trim($entries['deepseekApiKey']);
            unset($entries['deepseekApiKey']);
            Db::setSetting('deepseekApiKey', $plain === '' ? '' : self::encryptSecret($plain));
        }
        if (count($entries) > 0) {
            Db::setSettings($entries);
        }
        self::flush();
        return self::forAdmin();
    }

    /** 首次启动时把 config.php 里的 deepseek_api_key 写入数据库（库里已有则不动） */
    public static function seedFromConfig()
    {
        $fromConfig = (string) Config::get('deepseek_api_key', '');
        if ($fromConfig === '') {
            return;
        }
        $row = Db::one('SELECT `value` FROM settings WHERE `key` = ?', ['deepseekApiKey']);
        $current = $row ? Db::parseJson($row['value']) : '';
        if (is_string($current) && $current !== '') {
            return;
        }
        Db::setSetting('deepseekApiKey', self::encryptSecret($fromConfig));
        self::flush();
    }

    /** AI 是否可用（开关打开 + 已配置 Key） */
    public static function isAiConfigured()
    {
        $s = self::readAll();
        return $s['deepseekApiKey'] !== '';
    }

    /** AI 功能是否开启 */
    public static function isAiEnabled()
    {
        $s = self::readAll();
        return $s['aiEnabled'] !== false;
    }

    /** 当前 AI 每日限额（0 表示不限制） */
    public static function getAiDailyLimit()
    {
        $s = self::readAll();
        $n = $s['aiDailyLimit'];
        if (is_int($n) && $n >= 0) {
            return $n;
        }
        if (is_numeric($n) && (int) $n == $n && (int) $n >= 0) {
            return (int) $n;
        }
        return Config::DEFAULTS['aiDailyLimit'];
    }

    /** 注册是否开放 */
    public static function isRegisterOpen()
    {
        $s = self::readAll();
        return $s['registerOpen'] !== false;
    }

    /** 每个情侣空间允许的自建表情包上限 */
    public static function maxEmojiPacks()
    {
        $s = self::readAll();
        $n = isset($s['maxEmojiPacks']) ? (int) $s['maxEmojiPacks'] : 0;
        return $n > 0 ? $n : Config::LIMITS['EMOJI_MAX_PACKS'];
    }

    /** 每日消息上限（0 或非正数表示不限制） */
    public static function maxMessagesPerDay()
    {
        $s = self::readAll();
        $n = isset($s['maxMessagesPerDay']) ? (int) $s['maxMessagesPerDay'] : 0;
        return $n > 0 ? $n : 0;
    }
}
