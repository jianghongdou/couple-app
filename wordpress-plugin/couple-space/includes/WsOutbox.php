<?php
/**
 * 实时推送出站队列（兼容层）。
 *
 * 本插件**不做 WebSocket**（WordPress 跑不了常驻进程），所以这里是一个空实现：
 * 所有事件都不入队、不广播。客户端靠 REST 轮询兜底（iOS 已实现 5 秒轮询），
 * 因此功能完全不受影响，只是消息到达延迟由「实时」变成「最多 5 秒」。
 *
 * server-php 里对应的实现在 src/WsOutbox.php + bin/ws-server.php。
 *
 * @package CoupleSpace
 */

namespace CoupleSpace;

defined( 'ABSPATH' ) || exit;

/**
 * 出站事件（空实现）。
 */
class WsOutbox {

	/**
	 * 向某个情侣空间广播事件。
	 *
	 * @param string              $couple_id 情侣空间 id。
	 * @param array<string,mixed> $payload   事件内容。
	 * @param string|null         $except    排除的用户。
	 * @return bool 恒为 false（没有 WebSocket）。
	 */
	public static function to_couple( $couple_id, array $payload, $except = null ) {
		unset( $couple_id, $payload, $except );
		return false;
	}

	/**
	 * 向单个用户广播事件。
	 *
	 * @param string              $user_id 用户 id。
	 * @param array<string,mixed> $payload 事件内容。
	 * @return bool 恒为 false。
	 */
	public static function to_user( $user_id, array $payload ) {
		unset( $user_id, $payload );
		return false;
	}

	/**
	 * 广播给所有在线连接。
	 *
	 * @param array<string,mixed> $payload 事件内容。
	 * @return bool 恒为 false。
	 */
	public static function broadcast( array $payload ) {
		unset( $payload );
		return false;
	}
}
