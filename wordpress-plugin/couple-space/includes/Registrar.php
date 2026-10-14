<?php
/**
 * 路由登记器：给路由类提供 get/post/put/patch/delete 的流式接口。
 *
 * @package CoupleSpace
 */

namespace CoupleSpace;

defined( 'ABSPATH' ) || exit;

/**
 * 登记器。
 */
class Registrar {

	/**
	 * @param string   $method  HTTP 方法。
	 * @param string   $pattern 路径。
	 * @param callable $handler 处理函数。
	 * @return $this
	 */
	public function add( $method, $pattern, callable $handler ) {
		Router::add( $method, $pattern, $handler );
		return $this;
	}

	/**
	 * @param string   $pattern 路径。
	 * @param callable $handler 处理函数。
	 * @return $this
	 */
	public function get( $pattern, callable $handler ) {
		return $this->add( 'GET', $pattern, $handler );
	}

	/**
	 * @param string   $pattern 路径。
	 * @param callable $handler 处理函数。
	 * @return $this
	 */
	public function post( $pattern, callable $handler ) {
		return $this->add( 'POST', $pattern, $handler );
	}

	/**
	 * @param string   $pattern 路径。
	 * @param callable $handler 处理函数。
	 * @return $this
	 */
	public function put( $pattern, callable $handler ) {
		return $this->add( 'PUT', $pattern, $handler );
	}

	/**
	 * @param string   $pattern 路径。
	 * @param callable $handler 处理函数。
	 * @return $this
	 */
	public function patch( $pattern, callable $handler ) {
		return $this->add( 'PATCH', $pattern, $handler );
	}

	/**
	 * @param string   $pattern 路径。
	 * @param callable $handler 处理函数。
	 * @return $this
	 */
	public function delete( $pattern, callable $handler ) {
		return $this->add( 'DELETE', $pattern, $handler );
	}
}
