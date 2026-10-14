<?php
/**
 * AI 调用异常（携带 HTTP 状态与错误码）。
 *
 * 移植自 server-php/src/AiError.php。
 *
 * @package CoupleSpace
 */

namespace CoupleSpace;

defined( 'ABSPATH' ) || exit;

/**
 * AI 异常。
 */
class AiError extends \RuntimeException {

	/** @var string */
	private $error_code;

	/** @var int */
	private $status;

	/**
	 * @param string $message    中文提示。
	 * @param string $error_code API.md 的错误码。
	 * @param int    $status     HTTP 状态码。
	 */
	public function __construct( $message, $error_code = 'server_error', $status = 500 ) {
		parent::__construct( $message );
		$this->error_code = (string) $error_code;
		$this->status     = (int) $status;
	}

	/** @return string */
	public function get_error_code() {
		return $this->error_code;
	}

	/** @return int */
	public function get_status() {
		return $this->status;
	}
}
