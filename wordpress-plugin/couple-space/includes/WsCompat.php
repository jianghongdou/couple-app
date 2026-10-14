<?php
/**
 * WebSocket 兼容占位。
 *
 * 本插件**不提供** WebSocket：WordPress 是「请求-响应」模型，
 * 跑不了常驻进程，所以 /api/status 与后台里的 online/uptime 字段统一返回 0，
 * 客户端会自然走 REST 轮询兜底（iOS 已有 5 秒轮询实现，见交付说明）。
 *
 * server-php 里对应的实现在 src/WsHub.php（常驻 ws-server 进程）。
 *
 * @package CoupleSpace
 */

namespace CoupleSpace;

defined( 'ABSPATH' ) || exit;

/**
 * WebSocket 兼容层（永远「无连接」）。
 */
class WsCompat {

	/** @return int 在线连接数（插件无 WebSocket，恒为 0）。 */
	public static function online_count() {
		return 0;
	}

	/** @return bool WebSocket 常驻进程是否在跑（恒为 false）。 */
	public static function is_running() {
		return false;
	}

	/** @return int 常驻进程已运行秒数（恒为 0）。 */
	public static function uptime_seconds() {
		return 0;
	}
}
