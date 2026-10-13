<?php
namespace App\util;

/**
 * id 生成：形如 u_xxx / c_xxx / m_xxx / p_xxx / e_xxx
 *
 * 组成：base36 毫秒时间戳 + 进程内自增计数(3 位 base36) + 随机后缀(4 位)
 * 特点：
 *   - 字符集固定 [0-9a-z]，满足表情 id 正则 ^e_[A-Za-z0-9]+$
 *   - 前缀 + 时间戳，天然近似有序，便于排查
 *   - 计数器 + 随机后缀，避免同一毫秒内并发调用产生重复
 */
class Ids
{
    const ALPHABET = '0123456789abcdefghijklmnopqrstuvwxyz';

    /** @var int 进程内自增计数（36^3 = 46656） */
    private static $counter = -1;

    public static function makeId($prefix)
    {
        if (self::$counter < 0) {
            self::$counter = random_int(0, 46655);
        }
        self::$counter = (self::$counter + 1) % 46656;
        $ts = self::base36((int) floor(microtime(true) * 1000));
        $seq = str_pad(self::base36(self::$counter), 3, '0', STR_PAD_LEFT);
        return $prefix . '_' . $ts . $seq . self::randomSuffix(4);
    }

    /** 用户 id：u_ 前缀 */
    public static function newUserId()
    {
        return self::makeId('u');
    }

    /** 情侣空间 id：c_ 前缀 */
    public static function newCoupleId()
    {
        return self::makeId('c');
    }

    /** 消息 id：m_ 前缀 */
    public static function newMessageId()
    {
        return self::makeId('m');
    }

    /** 表情包 id：p_ 前缀 */
    public static function newPackId()
    {
        return self::makeId('p');
    }

    /** 表情 id：e_ 前缀（blob 文件名直接由该 id 决定） */
    public static function newEmojiId()
    {
        return self::makeId('e');
    }

    /** 后台日志 id */
    public static function newLogId()
    {
        return 'l_' . self::base36((int) floor(microtime(true) * 1000)) . self::randomSuffix(6);
    }

    /** AI 计数 id */
    public static function newAiUsageId()
    {
        return 'a_' . self::base36((int) floor(microtime(true) * 1000)) . self::randomSuffix(6);
    }

    /**
     * 校验表情 id 格式（防路径穿越的第一道关卡）
     * @param mixed $id
     */
    public static function isValidEmojiId($id)
    {
        return is_string($id) && strlen($id) <= 64 && preg_match('/^e_[A-Za-z0-9]+$/', $id) === 1;
    }

    /** 生成 6 位邀请码（大写字母 + 数字，排除易混淆字符 0/O/1/I） */
    public static function newInviteCode()
    {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $out = '';
        $max = strlen($chars) - 1;
        for ($i = 0; $i < 6; $i++) {
            $out .= $chars[random_int(0, $max)];
        }
        return $out;
    }

    /** 生成随机密钥（用于文档示例/CLI） */
    public static function randomSecret($bytes = 32)
    {
        return bin2hex(random_bytes($bytes));
    }

    private static function randomSuffix($len)
    {
        $out = '';
        $max = strlen(self::ALPHABET) - 1;
        for ($i = 0; $i < $len; $i++) {
            $out .= self::ALPHABET[random_int(0, $max)];
        }
        return $out;
    }

    private static function base36($n)
    {
        if ($n <= 0) {
            return '0';
        }
        $out = '';
        while ($n > 0) {
            $out = self::ALPHABET[$n % 36] . $out;
            $n = intdiv($n, 36);
        }
        return $out;
    }
}
