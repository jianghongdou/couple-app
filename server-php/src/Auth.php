<?php
namespace App;

/**
 * 鉴权：用户 token 与后台 token 使用不同密钥、不同 typ，互不通用。
 */
class Auth
{
    /**
     * 生成用户 token（有效期 30 天）
     * @param array $user users 表原始行
     */
    public static function signUserToken(array $user)
    {
        return Jwt::sign([
            'sub' => $user['id'],
            'username' => $user['username'],
            'role' => isset($user['role']) ? $user['role'] : 'user',
            'typ' => 'user',
        ], Config::get('jwt_secret'), Config::JWT_EXPIRES_IN_SECONDS);
    }

    /**
     * 校验用户 token
     * @param string $token
     * @return array|null ['row'=>..., 'user'=>...]
     */
    public static function verifyUserToken($token)
    {
        $payload = Jwt::verify($token, Config::get('jwt_secret'));
        if (!$payload || !isset($payload['typ']) || $payload['typ'] !== 'user' || empty($payload['sub'])) {
            return null;
        }
        $found = Db::getUserById($payload['sub']);
        if (!$found) {
            return null;
        }
        return $found;
    }

    /**
     * 必须登录：返回 ['row'=>..,'user'=>..]，否则抛 401/403
     */
    public static function requireUser()
    {
        $token = Http::extractToken();
        if ($token === null) {
            throw new HttpException(401, 'unauthorized', '缺少登录凭证');
        }
        $found = self::verifyUserToken($token);
        if (!$found) {
            throw new HttpException(401, 'unauthorized', '登录已过期，请重新登录');
        }
        if (!empty($found['row']['disabled'])) {
            throw new HttpException(403, 'forbidden', '账号已被禁用');
        }
        return $found;
    }

    /** 要求已绑定情侣关系（未绑定 -> 403 forbidden） */
    public static function requireCouple($user)
    {
        if (empty($user['coupleId'])) {
            throw new HttpException(403, 'forbidden', '尚未绑定情侣关系');
        }
        return $user['coupleId'];
    }

    // ------------------------------------------------------------------
    // 后台
    // ------------------------------------------------------------------

    /**
     * 常量时间比较密码，避免时序侧信道。
     * 先做 SHA-256 再比较，保证长度不同也不会提前返回。
     */
    public static function timingSafeCompare($a, $b)
    {
        $ha = hash('sha256', (string) $a, true);
        $hb = hash('sha256', (string) $b, true);
        return hash_equals($ha, $hb);
    }

    /** 后台密码的 bcrypt cost（与用户密码保持一致） */
    const BCRYPT_COST = 10;

    /** 生成后台密码哈希（bcrypt） */
    public static function hashAdminPassword($password)
    {
        return password_hash((string) $password, PASSWORD_BCRYPT, ['cost' => self::BCRYPT_COST]);
    }

    /**
     * 校验后台管理员密码。
     *
     *   1) 数据库里**有** settings.adminPasswordHash → 只认它（password_verify）。
     *      此时 config.php 里的旧密码立即失效（改过密码就是真的改掉了，不留后门）。
     *   2) 数据库里**没有**哈希 → 按老逻辑与 config.php 的明文做常数时间比较；
     *      一旦验证成功，立刻把该密码哈希写入数据库（自动迁移），
     *      用户无需任何额外操作就能开始用「后台自助改密码」。
     *
     * @param mixed $password
     * @return bool
     */
    public static function verifyAdminPassword($password)
    {
        if (!is_string($password) || $password === '') {
            return false;
        }

        $hash = Settings::adminPasswordHash();
        if ($hash !== '') {
            return password_verify($password, $hash);
        }

        $configPassword = (string) Config::get('admin_password', '');
        if ($configPassword === '' || !self::timingSafeCompare($password, $configPassword)) {
            return false;
        }

        // config.php 的初始密码校验通过 → 自动迁入数据库（失败也不影响本次登录）
        self::migrateAdminPasswordToDatabase($password);
        return true;
    }

    /**
     * 把明文后台密码哈希后写入数据库（首次迁移 / 重置）。
     * 任何失败都只记日志，不抛出：调用方（登录、改密码校验）不应因此失败。
     */
    public static function migrateAdminPasswordToDatabase($password)
    {
        try {
            Settings::setAdminPasswordHash(self::hashAdminPassword($password));
            Log::info('后台密码已自动迁入数据库（config.php 中的密码作为初始密码）');
            return true;
        } catch (\Exception $e) {
            Log::warn('后台密码自动迁入数据库失败（本次仍按 config.php 校验）', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public static function signAdminToken()
    {
        return Jwt::sign([
            'typ' => 'admin',
            'role' => 'admin',
        ], Config::get('admin_jwt_secret'), Config::ADMIN_TOKEN_TTL_SECONDS);
    }

    /** 后台接口鉴权（只认 Authorization 头） */
    public static function requireAdmin()
    {
        $token = Http::bearerToken();
        if ($token === null) {
            throw new HttpException(401, 'unauthorized', '缺少后台登录凭证');
        }
        $payload = Jwt::verify($token, Config::get('admin_jwt_secret'));
        if (!$payload || !isset($payload['typ']) || $payload['typ'] !== 'admin') {
            throw new HttpException(401, 'unauthorized', '后台登录已过期，请重新登录');
        }
        return true;
    }

    /** 记录后台操作日志（所有写操作都必须调用） */
    public static function logAdminAction($action, $detail = null, $ip = null)
    {
        try {
            Db::addAdminLog([
                'action' => $action,
                'detail' => $detail === null ? null : mb_substr((string) $detail, 0, 500, 'UTF-8'),
                'ip' => $ip === null ? Http::clientIp() : $ip,
            ]);
        } catch (\Exception $e) {
            Log::error('写入后台操作日志失败', ['error' => $e->getMessage()]);
        }
    }
}
