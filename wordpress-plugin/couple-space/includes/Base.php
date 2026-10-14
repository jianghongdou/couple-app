<?php
/**
 * 路由基类：统一响应构造与小工具。
 *
 * @package CoupleSpace
 */

namespace CoupleSpace;

defined( 'ABSPATH' ) || exit;

/**
 * 路由基类。
 */
abstract class Base {

	/**
	 * 成功响应（Dispatcher 会补上 serverTime）。
	 *
	 * @param array<string,mixed> $payload 载荷。
	 * @param int                 $status  HTTP 状态码。
	 * @return array{payload:array<string,mixed>,status:int}
	 */
	protected static function ok( array $payload, $status = 200 ) {
		return array(
			'payload' => $payload,
			'status'  => (int) $status,
		);
	}

	/**
	 * 原始响应。
	 *
	 * @param string               $body    响应体。
	 * @param int                  $status  HTTP 状态码。
	 * @param array<string,string> $headers 响应头。
	 * @return RawResponse
	 */
	protected static function raw( $body, $status = 200, array $headers = array() ) {
		return new RawResponse( $body, $status, $headers );
	}

	/**
	 * 抛出版本错误。
	 *
	 * @param string $message 提示。
	 * @throws HttpException 总是抛出。
	 */
	protected static function not_found( $message ) {
		throw new HttpException( 404, 'not_found', $message );
	}

	/**
	 * 抛出 403。
	 *
	 * @param string $message 提示。
	 * @throws HttpException 总是抛出。
	 */
	protected static function forbidden( $message ) {
		throw new HttpException( 403, 'forbidden', $message );
	}

	/**
	 * 抛出 409。
	 *
	 * @param string $message 提示。
	 * @throws HttpException 总是抛出。
	 */
	protected static function conflict( $message ) {
		throw new HttpException( 409, 'conflict', $message );
	}

	/**
	 * 从请求体中取一个字符串（不存在返回 null）。
	 *
	 * @param mixed  $body  请求体。
	 * @param string $field 字段名。
	 * @return string|null
	 */
	protected static function str( $body, $field ) {
		$v = Http::field( $body, $field );
		return is_string( $v ) ? $v : null;
	}
}
