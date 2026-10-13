<?php
namespace App;

/**
 * 时间工具：统一使用 Unix 毫秒时间戳（API.md §0）
 */
class Time
{
    /** 当前毫秒时间戳 */
    public static function nowMs()
    {
        return (int) round(microtime(true) * 1000);
    }

    /**
     * 本地日期 YYYY-MM-DD
     * @param int|null $ms 毫秒时间戳，null 表示当前时间
     */
    public static function dayKey($ms = null)
    {
        $ts = $ms === null ? time() : (int) floor($ms / 1000);
        return date('Y-m-d', $ts);
    }

    /** 本地当天 00:00:00 的毫秒时间戳 */
    public static function todayStartMs()
    {
        $d = getdate();
        $ts = mktime(0, 0, 0, $d['mon'], $d['mday'], $d['year']);
        return (int) $ts * 1000;
    }
}
