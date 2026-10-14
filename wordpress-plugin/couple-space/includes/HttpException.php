<?php
/**
 * 带 HTTP 状态的业务异常，由统一错误处理器映射为 API.md 规定的错误码。
 *
 * @package CoupleSpace
 */

namespace CoupleSpace;

defined( 'ABSPATH' ) || exit;

/**
 * HTTP 业务异常。
 */
class HttpException extends \RuntimeException {

	/** @var int */
	private $status;

	/** @var string */
	private $error_code;

	/**
	 * @param int    $status     HTTP 状态码。
	 * @param string $error_code API.md 的错误码（unauthorized / forbidden / ...）。
	 * @param string $message    中文提示。
	 */
	public function __construct( $status, $error_code, $message ) {
		parent::__construct( $message );
		$this->status     = (int) $status;
		$this->error_code = (string) $error_code;
	}

	/** @return int */
	public function get_status() {
		return $this->status;
	}

	/** @return string */
	public function get_error_code() {
		return $this->error_code;
	}
}
