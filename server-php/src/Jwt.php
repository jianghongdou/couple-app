<?php
namespace App;

/**
 * 手写 JWT（HS256）：base64url + hash_hmac + hash_equals 校验。
 * 不依赖任何第三方库，兼容 PHP 8.1。
 */
class Jwt
{
    public static function base64UrlEncode($data)
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function base64UrlDecode($data)
    {
        $s = strtr($data, '-_', '+/');
        $pad = strlen($s) % 4;
        if ($pad > 0) {
            $s .= str_repeat('=', 4 - $pad);
        }
        $out = base64_decode($s, true);
        return $out === false ? null : $out;
    }

    /**
     * 签发 token
     * @param array $payload
     * @param string $secret
     * @param int|null $ttlSeconds 有效期（秒）；null 表示不带 exp
     */
    public static function sign(array $payload, $secret, $ttlSeconds = null)
    {
        $now = time();
        if (!isset($payload['iat'])) {
            $payload['iat'] = $now;
        }
        if ($ttlSeconds !== null) {
            $payload['exp'] = $now + (int) $ttlSeconds;
        }
        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $h = self::base64UrlEncode(json_encode($header, JSON_UNESCAPED_SLASHES));
        $p = self::base64UrlEncode(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $signingInput = $h . '.' . $p;
        $sig = self::base64UrlEncode(hash_hmac('sha256', $signingInput, $secret, true));
        return $signingInput . '.' . $sig;
    }

    /**
     * 校验 token，返回 payload 数组；失败返回 null
     * @return array|null
     */
    public static function verify($token, $secret)
    {
        if (!is_string($token) || $token === '') {
            return null;
        }
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        list($h, $p, $sig) = $parts;
        $expected = self::base64UrlEncode(hash_hmac('sha256', $h . '.' . $p, $secret, true));
        if (!hash_equals($expected, $sig)) {
            return null;
        }
        $payloadRaw = self::base64UrlDecode($p);
        if ($payloadRaw === null) {
            return null;
        }
        $payload = json_decode($payloadRaw, true);
        if (!is_array($payload)) {
            return null;
        }
        // 头部算法必须是 HS256
        $headerRaw = self::base64UrlDecode($h);
        $header = $headerRaw === null ? null : json_decode($headerRaw, true);
        if (!is_array($header) || !isset($header['alg']) || strtoupper((string) $header['alg']) !== 'HS256') {
            return null;
        }
        if (isset($payload['exp']) && time() >= (int) $payload['exp']) {
            return null;
        }
        return $payload;
    }
}
