<?php
/**
 * 原始响应包装（表情图片二进制等非 JSON 输出）。
 *
 * @package CoupleSpace
 */

namespace CoupleSpace;

defined( 'ABSPATH' ) || exit;

/**
 * 原生响应。
 */
class RawResponse {

	/** @var int */
	public $status;

	/** @var array<string,string> */
	public $headers;

	/** @var string */
	public $body;

	/**
	 * @param string                $body    响应体。
	 * @param int                   $status  HTTP 状态码。
	 * @param array<string,string>  $headers 响应头。
	 */
	public function __construct( $body, $status = 200, array $headers = array() ) {
		$this->body    = (string) $body;
		$this->status  = (int) $status;
		$this->headers = $headers;
	}
}
