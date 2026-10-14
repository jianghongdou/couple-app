<?php
/**
 * 请求分发与统一错误处理。
 *
 * 与 server-php/src/Bootstrap.php 的 run()/handleException()/handleFatal() 行为对齐：
 *   - 未捕获的 HttpException -> API.md 规定的错误码与状态
 *   - 数据库约束冲突 -> 409 conflict
 *   - 其它异常 -> 500 server_error（并记录日志，不把堆栈吐给客户端）
 *   - 未匹配的接口 -> 404 not_found，文案与 server-php 一致
 *
 * @package CoupleSpace
 */

namespace CoupleSpace;

defined( 'ABSPATH' ) || exit;

/**
 * 分发器。
 */
class Dispatcher {

	/**
	 * 处理一个 API 请求并结束进程。
	 *
	 * @param string $path 归一化路径（/api/... 或 /admin/api/...）。
	 */
	public static function serve( $path ) {
		$response = self::handle( Http::method(), $path );
		self::send( $response );
		exit;
	}

	/**
	 * 处理请求并返回响应对象（供测试直接调用）。
	 *
	 * @param string $method HTTP 方法。
	 * @param string $path   归一化路径。
	 * @return array{status:int,headers:array<string,string>,body:string}
	 */
	public static function handle( $method, $path ) {
		// 不把 PHP 报错吐给客户端（避免破坏 JSON / 泄漏路径）。
		@ini_set( 'display_errors', '0' ); // phpcs:ignore WordPress.PHP.IniSet.display_errors_Blacklisted, WordPress.PHP.NoSilencedErrors.Discouraged

		try {
			if ( ! Db::has_core_tables() ) {
				// 表还没建（插件被手工复制进来但没走激活流程）：先幂等补建一次，避免 500。
				try {
					Db::install();
				} catch ( \Throwable $e ) {
					Log::error( '自动建表失败', array( 'error' => $e->getMessage() ) );
					return self::error_response( 503, 'db_unavailable', '数据库暂时不可用，请检查 WordPress 的数据库配置' );
				}
			}

			Config::ensure_dirs();

			// 内置公共表情包（public/emoji/<包名>/<图片>）按目录指纹增量同步。
			try {
				PublicEmoji::sync_if_changed();
			} catch ( \Throwable $e ) {
				Log::warn( '同步内置公共表情包失败', array( 'error' => $e->getMessage() ) );
			}

			$result = Router::dispatch( $method, $path );
			if ( null === $result ) {
				return self::error_response( 404, 'not_found', '接口不存在：' . strtoupper( $method ) . ' ' . $path );
			}

			if ( $result instanceof RawResponse ) {
				$headers                  = $result->headers;
				$headers['Content-Length'] = (string) strlen( $result->body );
				return array(
					'status'  => $result->status,
					'headers' => $headers,
					'body'    => $result->body,
				);
			}

			if ( is_array( $result ) && isset( $result['payload'] ) ) {
				$payload = is_array( $result['payload'] ) ? $result['payload'] : array();
				$status  = isset( $result['status'] ) ? (int) $result['status'] : 200;
				$payload['serverTime'] = Time::now_ms();
				return self::json_response( $payload, $status );
			}

			// 命中路由但返回值不合法（理论上不会发生）：当作服务端错误。
			Log::error( '路由处理函数返回值不合法', array( 'path' => $path, 'method' => $method ) );
			return self::error_response( 500, 'server_error', '服务器内部错误' );
		} catch ( HttpException $e ) {
			return self::error_response( $e->get_status(), $e->get_error_code(), $e->getMessage() );
		} catch ( AiError $e ) {
			return self::error_response( $e->get_status(), $e->get_error_code(), $e->getMessage() );
		} catch ( \Throwable $e ) {
			$message = (string) $e->getMessage();
			if ( Db::is_conflict_error( $message ) ) {
				Log::warn( '数据库约束冲突', array( 'error' => $message ) );
				return self::error_response( 409, 'conflict', '数据冲突，请刷新后重试' );
			}
			Log::error(
				'未处理的服务端异常',
				array(
					'error' => $message,
					'file'  => basename( $e->getFile() ) . ':' . $e->getLine(),
					'path'  => $path,
				)
			);
			return self::error_response( 500, 'server_error', '服务器内部错误' );
		}
	}

	/**
	 * 输出响应并清理缓冲区。
	 *
	 * @param array{status:int,headers:array<string,string>,body:string} $response 响应。
	 */
	public static function send( array $response ) {
		// 丢掉 WordPress 在 parse_request 之前可能已经刷出的内容，保证响应体干净。
		while ( ob_get_level() > 0 ) {
			@ob_end_clean(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		if ( ! headers_sent() ) {
			status_header( (int) $response['status'] );
			foreach ( $response['headers'] as $name => $value ) {
				header( $name . ': ' . $value );
			}
			header( 'X-Content-Type-Options: nosniff' );
			header( 'Referrer-Policy: no-referrer' );
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 这是原始响应体（JSON 或图片二进制）。
		echo $response['body'];
	}

	/**
	 * @param array<string,mixed> $payload JSON 载荷。
	 * @param int                 $status  HTTP 状态码。
	 * @return array{status:int,headers:array<string,string>,body:string}
	 */
	public static function json_response( array $payload, $status = 200 ) {
		$body = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( false === $body ) {
			$status = 500;
			$body   = '{"error":"server_error","message":"响应序列化失败"}';
		}
		return array(
			'status'  => (int) $status,
			'headers' => array(
				'Content-Type'  => 'application/json; charset=utf-8',
				'Cache-Control' => 'no-store',
			),
			'body'    => $body,
		);
	}

	/**
	 * @param int    $status     HTTP 状态码。
	 * @param string $error_code 错误码。
	 * @param string $message    提示。
	 * @return array{status:int,headers:array<string,string>,body:string}
	 */
	public static function error_response( $status, $error_code, $message ) {
		return self::json_response(
			array(
				'error'      => $error_code,
				'message'    => $message,
				'serverTime' => Time::now_ms(),
			),
			$status
		);
	}
}
