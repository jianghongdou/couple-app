<?php
/**
 * 限流：把计数落在自建 rate_limits 表里。
 *
 * 移植自 server-php/src/RateLimit.php（原因：PHP 每个请求是独立进程，
 * Node 版那种「进程内 Map」在 PHP-FPM 下无效，WordPress 也一样）。
 *
 * 算法：固定窗口 + 权重衰减，近似滑动窗口 —— 保存当前窗口与上一窗口计数，
 * 按当前时间在窗口内的推进比例对上一窗口做衰减，得到平滑的近似值，
 * 避免窗口边界处的双倍突发。
 *
 * @package CoupleSpace
 */

namespace CoupleSpace;

defined( 'ABSPATH' ) || exit;

/**
 * 限流器。
 */
class RateLimit {

	/**
	 * 记录一次访问并判断是否超限。
	 *
	 * @param string $key       限流键。
	 * @param int    $limit     窗口内允许次数。
	 * @param int    $window_ms 窗口长度（毫秒）。
	 * @return array{allowed:bool,remaining:int,retryAfterSeconds:int}
	 */
	public static function hit( $key, $limit, $window_ms ) {
		global $wpdb;
		$now   = Time::now_ms();
		$table = Db::table( 'rate_limits' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( 'START TRANSACTION' );
		try {
			$row          = Db::one( 'SELECT * FROM `' . $table . '` WHERE `key` = %s', array( $key ) );
			$count        = 0;
			$prev_count   = 0;
			$window_start = $now;

			if ( $row && ( $now - (int) $row['window_start'] ) < $window_ms ) {
				// 还在当前窗口内。
				$count        = (int) $row['count'];
				$prev_count   = (int) $row['prev_count'];
				$window_start = (int) $row['window_start'];
			} elseif ( $row ) {
				// 进入新窗口：旧窗口降级为上一窗口。
				$prev_count   = ( ( $now - (int) $row['window_start'] ) < $window_ms * 2 ) ? (int) $row['count'] : 0;
				$window_start = $now;
				$count        = 0;
			}

			$count += 1;

			Db::run(
				'INSERT INTO `' . $table . '` (`key`, window_start, window_ms, count, prev_count, prev_start)
				 VALUES (%s, %d, %d, %d, %d, %d)
				 ON DUPLICATE KEY UPDATE
				   window_start = VALUES(window_start),
				   window_ms    = VALUES(window_ms),
				   count        = VALUES(count),
				   prev_count   = VALUES(prev_count),
				   prev_start   = VALUES(prev_start)',
				array( $key, $window_start, $window_ms, $count, $prev_count, $window_start )
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( 'COMMIT' );
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( 'ROLLBACK' );
			// 限流表异常不应阻断正常业务。
			Log::warn( '限流计数失败', array( 'error' => $e->getMessage() ) );
			return array(
				'allowed'           => true,
				'remaining'         => $limit,
				'retryAfterSeconds' => 1,
			);
		}

		$elapsed  = $now - $window_start;
		$ratio    = max( 0.0, 1.0 - $elapsed / max( 1, $window_ms ) );
		$weighted = $count + $prev_count * $ratio;
		$allowed  = $weighted <= $limit;
		$remain   = (int) max( 0, floor( $limit - $weighted ) );
		$retry    = (int) max( 1, ceil( ( $window_start + $window_ms - $now ) / 1000 ) );

		return array(
			'allowed'           => $allowed,
			'remaining'         => $remain,
			'retryAfterSeconds' => $retry,
		);
	}

	/**
	 * 执行限流：超限时设置响应头并抛 429 rate_limited。
	 *
	 * @param string $key       限流键。
	 * @param int    $limit     窗口内允许次数。
	 * @param int    $window_ms 窗口长度（毫秒）。
	 * @param string $message   超限提示。
	 * @return array{allowed:bool,remaining:int,retryAfterSeconds:int}
	 */
	public static function enforce( $key, $limit, $window_ms, $message = '请求过于频繁，请稍后再试' ) {
		$r = self::hit( $key, $limit, $window_ms );
		if ( ! headers_sent() ) {
			header( 'X-RateLimit-Limit: ' . $limit );
			header( 'X-RateLimit-Remaining: ' . $r['remaining'] );
		}
		if ( ! $r['allowed'] ) {
			if ( ! headers_sent() ) {
				header( 'Retry-After: ' . $r['retryAfterSeconds'] );
			}
			throw new HttpException( 429, 'rate_limited', $message );
		}
		return $r;
	}

	/**
	 * 清理过期限流记录（WP-Cron / 维护用）。
	 *
	 * @param int $older_than_ms 多久之前的记录算过期。
	 * @return int
	 */
	public static function purge( $older_than_ms = 86400000 ) {
		$cutoff = Time::now_ms() - $older_than_ms;
		$stmt   = Db::run( 'DELETE FROM `' . Db::table( 'rate_limits' ) . '` WHERE window_start < %d', array( $cutoff ) );
		return (int) $stmt;
	}
}
