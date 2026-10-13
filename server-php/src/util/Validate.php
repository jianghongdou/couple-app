<?php
namespace App\util;

use App\Config;
use App\HttpException;

/**
 * 手写参数校验工具（不引第三方库）。
 * 所有校验失败都抛出 HttpException(400, invalid_input)，由统一错误处理器转换。
 * 错误文案与 Node 版保持一致。
 */
class Validate
{
    /** 抛出 400 invalid_input */
    public static function fail($message)
    {
        throw new HttpException(400, 'invalid_input', $message);
    }

    /** 抛出 404 not_found */
    public static function failNotFound($message)
    {
        throw new HttpException(404, 'not_found', $message);
    }

    /** 抛出 403 forbidden */
    public static function failForbidden($message)
    {
        throw new HttpException(403, 'forbidden', $message);
    }

    /** 抛出 409 conflict */
    public static function failConflict($message)
    {
        throw new HttpException(409, 'conflict', $message);
    }

    /** 是否为 JSON 对象（stdClass；数组与 null 都不算） */
    public static function isPlainObject($v)
    {
        return $v instanceof \stdClass;
    }

    /** 按 Unicode 码点计算长度（emoji 算 1 个字符） */
    public static function length($s)
    {
        return mb_strlen($s, 'UTF-8');
    }

    /**
     * 必填字符串 + 长度/格式约束
     * @param mixed $value
     * @param string $field
     * @param array $opts min,max,trim,pattern,patternHint
     * @return string
     */
    public static function requireString($value, $field, array $opts = [])
    {
        $min = isset($opts['min']) ? $opts['min'] : 1;
        $max = isset($opts['max']) ? $opts['max'] : null;
        $trim = !empty($opts['trim']);
        if (!is_string($value)) {
            self::fail($field . ' 必须是字符串');
        }
        $s = $trim ? trim($value) : $value;
        $len = self::length($s);
        if ($len < $min) {
            self::fail($field . ' 长度不能少于 ' . $min . ' 个字符');
        }
        if ($max !== null && $len > $max) {
            self::fail($field . ' 长度不能超过 ' . $max . ' 个字符');
        }
        if (isset($opts['pattern']) && !preg_match($opts['pattern'], $s)) {
            $hint = isset($opts['patternHint']) ? '（' . $opts['patternHint'] . '）' : '';
            self::fail($field . ' 格式不正确' . $hint);
        }
        return $s;
    }

    /** 可选字符串：null/缺失返回默认值 */
    public static function optionalString($value, $field, array $opts = [])
    {
        if ($value === null) {
            return isset($opts['defaultValue']) ? $opts['defaultValue'] : null;
        }
        return self::requireString($value, $field, $opts);
    }

    /**
     * 校验并归一化「毫秒时间戳」整数
     * @param mixed $value
     * @return int|null
     */
    public static function requireTimestamp($value, $field, array $opts = [])
    {
        $required = !empty($opts['required']);
        $min = isset($opts['min']) ? $opts['min'] : 0;
        $max = isset($opts['max']) ? $opts['max'] : PHP_INT_MAX;
        $default = isset($opts['defaultValue']) ? $opts['defaultValue'] : null;
        if ($value === null || $value === '') {
            if ($required) {
                self::fail($field . ' 不能为空');
            }
            return $default;
        }
        $n = self::toNumber($value);
        if ($n === null || !self::isIntegerNumber($n)) {
            self::fail($field . ' 必须是整数毫秒时间戳');
        }
        $n = (int) $n;
        if ($n < $min) {
            self::fail($field . ' 不能小于 ' . $min);
        }
        if ($n > $max) {
            self::fail($field . ' 超出允许范围');
        }
        return $n;
    }

    /** 可选布尔值 */
    public static function optionalBool($value, $field, $default = null)
    {
        if ($value === null) {
            return $default;
        }
        if (!is_bool($value)) {
            self::fail($field . ' 必须是布尔值');
        }
        return $value;
    }

    /** 必填整数 */
    public static function requireInt($value, $field, array $opts = [])
    {
        $min = isset($opts['min']) ? $opts['min'] : PHP_INT_MIN;
        $max = isset($opts['max']) ? $opts['max'] : PHP_INT_MAX;
        if (array_key_exists('defaultValue', $opts)) {
            $default = $opts['defaultValue'];
        } else {
            $default = null;
        }
        if ($value === null || $value === '') {
            if ($default !== null) {
                return $default;
            }
            self::fail($field . ' 不能为空');
        }
        $n = self::toNumber($value);
        if ($n === null || !self::isIntegerNumber($n)) {
            self::fail($field . ' 必须是整数');
        }
        $n = (int) $n;
        if ($n < $min || $n > $max) {
            self::fail($field . ' 必须在 ' . $min . ' 到 ' . $max . ' 之间');
        }
        return $n;
    }

    /** 查询参数形式的整数（来自 query string） */
    public static function queryInt($value, $field, array $opts = [])
    {
        if ($value === null || $value === '') {
            return isset($opts['defaultValue']) ? $opts['defaultValue'] : null;
        }
        if (is_array($value)) {
            self::fail($field . ' 必须是整数');
        }
        $n = self::toNumber($value);
        if ($n === null || !self::isIntegerNumber($n)) {
            self::fail($field . ' 必须是整数');
        }
        $n = (int) $n;
        $min = isset($opts['min']) ? $opts['min'] : PHP_INT_MIN;
        $max = isset($opts['max']) ? $opts['max'] : PHP_INT_MAX;
        if ($n < $min) {
            self::fail($field . ' 不能小于 ' . $min);
        }
        if ($n > $max) {
            self::fail($field . ' 不能大于 ' . $max);
        }
        return $n;
    }

    /** 校验日期 YYYY-MM-DD */
    public static function requireDateString($value, $field)
    {
        if (!is_string($value)) {
            self::fail($field . ' 必须是 YYYY-MM-DD 字符串');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            self::fail($field . ' 格式必须是 YYYY-MM-DD');
        }
        list($y, $m, $d) = array_map('intval', explode('-', $value));
        if (!checkdate($m, $d, $y)) {
            self::fail($field . ' 不是有效日期');
        }
        return $value;
    }

    /** 校验月份 YYYY-MM */
    public static function requireMonthString($value, $field)
    {
        if (!is_string($value)) {
            self::fail($field . ' 必须是 YYYY-MM 字符串');
        }
        if (!preg_match('/^\d{4}-\d{2}$/', $value)) {
            self::fail($field . ' 格式必须是 YYYY-MM');
        }
        $m = (int) substr($value, 5, 2);
        if ($m < 1 || $m > 12) {
            self::fail($field . ' 月份必须在 01-12 之间');
        }
        return $value;
    }

    /**
     * 校验 JSON 对象（用于 payload / meta），带体积保护
     * @param mixed $value
     * @return \stdClass|null
     */
    public static function requireJsonObject($value, $field, array $opts = [])
    {
        $allowNull = !array_key_exists('allowNull', $opts) || $opts['allowNull'];
        $default = array_key_exists('defaultValue', $opts) ? $opts['defaultValue'] : null;
        if ($value === null) {
            if ($allowNull) {
                return $default;
            }
            self::fail($field . ' 不能为空');
        }
        if (!self::isPlainObject($value)) {
            self::fail($field . ' 必须是 JSON 对象');
        }
        $serialized = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($serialized === false) {
            self::fail($field . ' 无法序列化为 JSON');
        }
        if (strlen($serialized) > Config::limit('JSON_FIELD_MAX_BYTES')) {
            self::fail($field . ' 数据过大（上限 256KB）');
        }
        return $value;
    }

    /** 昵称：1-32 字符 */
    public static function validateNickname($value, $field = 'nickname')
    {
        return self::requireString($value, $field, ['min' => 1, 'max' => 32, 'trim' => true]);
    }

    /** 头像 emoji：可选，单个 emoji */
    public static function validateAvatarEmoji($value)
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            self::fail('avatarEmoji 必须是字符串');
        }
        $chars = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);
        $count = is_array($chars) ? count($chars) : 0;
        if ($count === 0 || $count > 4) {
            self::fail('avatarEmoji 必须是单个 emoji');
        }
        if (strlen($value) > 16) {
            self::fail('avatarEmoji 过长');
        }
        return $value;
    }

    /** username：3-32 位，只允许 a-zA-Z0-9_ */
    public static function validateUsername($value)
    {
        return self::requireString($value, 'username', [
            'min' => 3,
            'max' => 32,
            'pattern' => '/^[a-zA-Z0-9_]+$/',
            'patternHint' => '只允许字母、数字和下划线',
        ]);
    }

    /** password：6-64 位 */
    public static function validatePassword($value, $field = 'password')
    {
        return self::requireString($value, $field, [
            'min' => Config::limit('PASSWORD_MIN'),
            'max' => Config::limit('PASSWORD_MAX'),
        ]);
    }

    /** 分页 limit */
    public static function validateLimit($value, $defaultSize, $maxSize)
    {
        return self::queryInt($value, 'limit', [
            'defaultValue' => $defaultSize,
            'min' => 1,
            'max' => $maxSize,
        ]);
    }

    /** 安全 JSON.parse */
    public static function safeJsonParse($text, $fallback = null)
    {
        if (!is_string($text) || $text === '') {
            return $fallback;
        }
        $v = json_decode($text);
        return json_last_error() === JSON_ERROR_NONE ? $v : $fallback;
    }

    /** 校验表情 mime 是否在白名单内 */
    public static function validateMime($mime, array $whitelist)
    {
        if (!is_string($mime)) {
            self::fail('mime 必须是字符串');
        }
        if (!in_array($mime, $whitelist, true)) {
            self::fail('mime 不在白名单内，仅支持：' . implode('、', $whitelist));
        }
        return $mime;
    }

    // ------------------------------------------------------------------
    // 内部工具
    // ------------------------------------------------------------------

    /**
     * 转数字（接受 int/float/数字字符串）
     * @param mixed $v
     * @return int|float|null
     */
    private static function toNumber($v)
    {
        if (is_int($v) || is_float($v)) {
            return $v;
        }
        if (is_string($v)) {
            $s = trim($v);
            if ($s === '' || !preg_match('/^[+-]?(\d+(\.\d+)?|\.\d+)([eE][+-]?\d+)?$/', $s)) {
                return null;
            }
            return $s + 0;
        }
        if (is_bool($v)) {
            return null;
        }
        return null;
    }

    /** @param int|float $n */
    private static function isIntegerNumber($n)
    {
        if (is_int($n)) {
            return true;
        }
        return is_float($n) && floor($n) === $n && is_finite($n);
    }
}
