<?php
namespace App;

use PDOException;

/**
 * 限流：把计数落在数据库的 rate_limits 表里。
 *
 * 原因：PHP 每个请求是独立进程，Node 版那种「进程内 Map」在 PHP-FPM 下无效。
 * 算法与 Node 版一致 —— 固定窗口 + 权重衰减，近似滑动窗口：
 * 保存当前窗口与上一窗口计数，按当前时间在窗口内的推进比例对上一窗口做衰减，
 * 得到平滑的近似值，避免窗口边界处的双倍突发。
 */
class RateLimit
{
    /**
     * 记录一次访问并判断是否超限
     * @return array{allowed:bool,remaining:int,retryAfterSeconds:int}
     */
    public static function hit($key, $limit, $windowMs)
    {
        $now = Time::nowMs();
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $row = Db::one('SELECT * FROM rate_limits WHERE `key` = ?', [$key]);
            $count = 0;
            $prevCount = 0;
            $windowStart = $now;

            if ($row && ($now - (int) $row['window_start']) < $windowMs) {
                // 还在当前窗口内
                $count = (int) $row['count'];
                $prevCount = (int) $row['prev_count'];
                $windowStart = (int) $row['window_start'];
            } elseif ($row) {
                // 进入新窗口：旧窗口降级为上一窗口
                $prevCount = ($now - (int) $row['window_start']) < $windowMs * 2 ? (int) $row['count'] : 0;
                $windowStart = $now;
                $count = 0;
            }

            $count += 1;

            Db::run(
                'INSERT INTO rate_limits (`key`, window_start, window_ms, count, prev_count, prev_start)
                 VALUES (?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                   window_start = VALUES(window_start),
                   window_ms    = VALUES(window_ms),
                   count        = VALUES(count),
                   prev_count   = VALUES(prev_count),
                   prev_start   = VALUES(prev_start)',
                [$key, $windowStart, $windowMs, $count, $prevCount, $windowStart]
            );
            $pdo->commit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            // 限流表异常不应阻断正常业务
            Log::warn('限流计数失败', ['error' => $e->getMessage()]);
            return ['allowed' => true, 'remaining' => $limit, 'retryAfterSeconds' => 1];
        }

        $elapsed = $now - $windowStart;
        $ratio = max(0.0, 1.0 - $elapsed / $windowMs);
        $weighted = $count + $prevCount * $ratio;
        $allowed = $weighted <= $limit;
        $remaining = (int) max(0, floor($limit - $weighted));
        $retryAfter = (int) max(1, ceil(($windowStart + $windowMs - $now) / 1000));

        return ['allowed' => $allowed, 'remaining' => $remaining, 'retryAfterSeconds' => $retryAfter];
    }

    /**
     * 执行限流：超限时设置响应头并抛 429 rate_limited
     */
    public static function enforce($key, $limit, $windowMs, $message = '请求过于频繁，请稍后再试')
    {
        $r = self::hit($key, $limit, $windowMs);
        if (!headers_sent()) {
            header('X-RateLimit-Limit: ' . $limit);
            header('X-RateLimit-Remaining: ' . $r['remaining']);
        }
        if (!$r['allowed']) {
            if (!headers_sent()) {
                header('Retry-After: ' . $r['retryAfterSeconds']);
            }
            throw new HttpException(429, 'rate_limited', $message);
        }
        return $r;
    }

    /** 清理过期限流记录（CLI 维护用） */
    public static function purge($olderThanMs = 86400000)
    {
        $cutoff = Time::nowMs() - $olderThanMs;
        $stmt = Db::run('DELETE FROM rate_limits WHERE window_start < ?', [$cutoff]);
        return $stmt->rowCount();
    }
}
