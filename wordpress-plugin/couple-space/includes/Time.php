<?php
/**
 * 时间工具：统一使用 Unix 毫秒时间戳（API.md §0）。
 *
 * @package CoupleSpace
 */

namespace CoupleSpace;

defined( 'ABSPATH' ) || exit;

/**
 * 时间工具。
 */
class Time {

	/**
	 * 当前毫秒时间戳。
	 *
	 * @return int
	 */
	public static function now_ms() {
		return (int) round( microtime( true ) * 1000 );
	}

	/**
	 * 本地日期 YYYY-MM-DD。
	 *
	 * 使用 WordPress 站点时区（wp_timezone），保证「每日限额」按站点的自然日计算。
	 *
	 * @param int|null $ms 毫秒时间戳；null 表示当前时间。
	 * @return string
	 */
	public static function day_key( $ms = null ) {
		$ts = ( null === $ms ) ? time() : (int) floor( $ms / 1000 );
		return wp_date( 'Y-m-d', $ts, wp_timezone() );
	}

	/**
	 * 站点当天 00:00:00 的毫秒时间戳。
	 *
	 * @return int
	 */
	public static function today_start_ms() {
		$tz  = wp_timezone();
		$now = new \DateTimeImmutable( 'now', $tz );
		$mid = $now->setTime( 0, 0, 0 );
		return (int) $mid->getTimestamp() * 1000;
	}
}
