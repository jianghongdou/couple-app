<?php
/**
 * 极简日志：写入上传目录下的 couple-emoji/logs/app.log。
 *
 * 移植自 server-php/src/Log.php。在 WordPress 里额外支持 WP_DEBUG：
 * 打开 WP_DEBUG 时日志同时写入 error_log，方便排查。
 *
 * @package CoupleSpace
 */

namespace CoupleSpace;

defined( 'ABSPATH' ) || exit;

/**
 * 日志。
 */
class Log {

	/** @var array<string,int> */
	private static $levels = array(
		'debug' => 10,
		'info'  => 20,
		'warn'  => 30,
		'error' => 40,
	);

	/** @var int */
	private static $threshold = 20;

	/**
	 * @param string               $message 消息。
	 * @param array<string,mixed>  $context 附加上下文。
	 */
	public static function debug( $message, array $context = array() ) {
		self::write( 'debug', $message, $context );
	}

	/**
	 * @param string              $message 消息。
	 * @param array<string,mixed> $context 附加上下文。
	 */
	public static function info( $message, array $context = array() ) {
		self::write( 'info', $message, $context );
	}

	/**
	 * @param string              $message 消息。
	 * @param array<string,mixed> $context 附加上下文。
	 */
	public static function warn( $message, array $context = array() ) {
		self::write( 'warn', $message, $context );
	}

	/**
	 * @param string              $message 消息。
	 * @param array<string,mixed> $context 附加上下文。
	 */
	public static function error( $message, array $context = array() ) {
		self::write( 'error', $message, $context );
	}

	/**
	 * @param string              $level   级别。
	 * @param string              $message 消息。
	 * @param array<string,mixed> $context 附加上下文。
	 */
	private static function write( $level, $message, array $context ) {
		$threshold = (int) self::$threshold;
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$threshold = 10;
		}
		if ( self::$levels[ $level ] < $threshold ) {
			return;
		}

		$line = '[' . gmdate( 'Y-m-d H:i:s' ) . '] ' . strtoupper( $level ) . ' ' . $message;
		if ( count( $context ) > 0 ) {
			$json = wp_json_encode( $context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			$line .= ' ' . ( false === $json ? '{}' : $json );
		}
		$line .= "\n";

		$file = Config::log_file();
		$dir  = dirname( $file );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		// 日志文件超过 8MB 时轮转一次，避免无限增长（与 server-php 行为一致）。
		if ( is_file( $file ) && (int) filesize( $file ) > 8388608 ) {
			@rename( $file, $file . '.' . gmdate( 'Ymd-His' ) . '.bak' );
		}
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- 仅在 WP_DEBUG 下输出。
		@file_put_contents( $file, $line, FILE_APPEND | LOCK_EX );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'couple-space ' . trim( $line ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
