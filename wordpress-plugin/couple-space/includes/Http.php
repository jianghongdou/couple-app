<?php
/**
 * HTTP 请求解析与响应输出。
 *
 * 移植自 server-php/src/Http.php。差异说明：
 *   - 用户在 WordPress 后台可以把「信任代理头」关掉（默认开，与 server-php 一致）；
 *   - JSON 输出使用 wp_json_encode，响应头用 status_header()/header() 设置；
 *   - 请求体读取 php://input（WordPress REST 兼容，不用 WP 的 rest_* 解析）。
 *
 * @package CoupleSpace
 */

namespace CoupleSpace;

defined( 'ABSPATH' ) || exit;

/**
 * HTTP 工具。
 */
class Http {

	/** @var mixed */
	private static $body_cache = null;

	/** @var bool */
	private static $body_parsed = false;

	/** @var string|null */
	private static $raw_body_cache = null;

	// ------------------------------------------------------------------
	// 请求
	// ------------------------------------------------------------------

	/** @return string */
	public static function method() {
		$m = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		return $m;
	}

	/**
	 * 原始请求路径（不含查询串，不做 URL 解码）。
	 *
	 * @return string
	 */
	public static function raw_path() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '/';
		$pos = strpos( $uri, '?' );
		if ( false !== $pos ) {
			$uri = substr( $uri, 0, $pos );
		}
		if ( '' === $uri ) {
			$uri = '/';
		}
		if ( preg_match( '#^[a-zA-Z][a-zA-Z0-9+.-]*://[^/]*(/.*)$#', $uri, $m ) ) {
			$uri = $m[1];
		}
		return $uri;
	}

	/**
	 * 查询参数（字符串或默认值）。
	 *
	 * @param string $name    参数名。
	 * @param mixed  $default 默认值。
	 * @return mixed
	 */
	public static function query( $name, $default = null ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 只读查询参数，nonce 由各接口自行校验。
		if ( ! isset( $_GET[ $name ] ) ) {
			return $default;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$v = wp_unslash( $_GET[ $name ] );
		if ( is_array( $v ) ) {
			return $default;
		}
		return $v;
	}

	/**
	 * 请求头（大小写不敏感；WordPress 已把 HTTP_* 放到 $_SERVER）。
	 *
	 * @param string $name    头名。
	 * @param mixed  $default 默认值。
	 * @return mixed
	 */
	public static function header( $name, $default = null ) {
		$key = 'HTTP_' . strtoupper( str_replace( '-', '_', $name ) );
		if ( isset( $_SERVER[ $key ] ) ) {
			return (string) $_SERVER[ $key ];
		}
		$alt = strtoupper( str_replace( '-', '_', $name ) );
		if ( isset( $_SERVER[ $alt ] ) ) {
			return (string) $_SERVER[ $alt ];
		}
		// 部分 SAPI（php -S / CGI）把 Authorization 放在这里。
		if ( 'authorization' === strtolower( $name ) && function_exists( 'getallheaders' ) ) {
			$all = getallheaders();
			if ( is_array( $all ) ) {
				foreach ( $all as $k => $v ) {
					if ( 0 === strcasecmp( (string) $k, $name ) ) {
						return (string) $v;
					}
				}
			}
		}
		return $default;
	}

	/**
	 * 原始请求体。
	 *
	 * @return string
	 */
	public static function raw_body() {
		if ( null !== self::$raw_body_cache ) {
			return self::$raw_body_cache;
		}
		$raw = file_get_contents( 'php://input' );
		// php://input 为空时（例如部分 SAPI 下 PUT/DELETE）退回 POST 原始数据。
		self::$raw_body_cache = ( false === $raw ) ? '' : (string) $raw;
		return self::$raw_body_cache;
	}

	/**
	 * 解析 JSON 请求体。
	 *
	 * 与 server-php / Node 版 express.json 行为一致：
	 *   - 空请求体 / 非 JSON Content-Type -> 空对象
	 *   - JSON 语法错误 -> 400 invalid_input
	 *   - 顶层可以是对象、数组或标量（业务层按需校验）
	 *
	 * @return mixed
	 */
	public static function json_body() {
		if ( self::$body_parsed ) {
			return ( null === self::$body_cache ) ? new \stdClass() : self::$body_cache;
		}
		self::$body_parsed = true;

		$len = (int) self::header( 'Content-Length', 0 );
		if ( $len > Config::limit( 'BODY_MAX_BYTES' ) ) {
			throw new HttpException( 413, 'invalid_input', '请求体过大（上限 4MB）' );
		}

		$raw = self::raw_body();
		if ( '' === $raw ) {
			self::$body_cache = new \stdClass();
			return self::$body_cache;
		}

		$ctype = strtolower( (string) self::header( 'Content-Type', '' ) );
		if ( false === strpos( $ctype, 'application/json' ) && false === strpos( $ctype, 'text/json' ) ) {
			self::$body_cache = new \stdClass();
			return self::$body_cache;
		}
		if ( strlen( $raw ) > Config::limit( 'BODY_MAX_BYTES' ) ) {
			throw new HttpException( 413, 'invalid_input', '请求体过大（上限 4MB）' );
		}

		$decoded = json_decode( $raw );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			throw new HttpException( 400, 'invalid_input', '请求体不是合法的 JSON' );
		}
		self::$body_cache = $decoded;
		return self::$body_cache;
	}

	/**
	 * 读取对象字段；不存在时返回默认值。
	 *
	 * @param mixed  $obj     对象/数组。
	 * @param string $name    字段名。
	 * @param mixed  $default 默认值。
	 * @return mixed
	 */
	public static function field( $obj, $name, $default = null ) {
		if ( $obj instanceof \stdClass && property_exists( $obj, $name ) ) {
			return $obj->{$name};
		}
		if ( is_array( $obj ) && array_key_exists( $name, $obj ) ) {
			return $obj[ $name ];
		}
		return $default;
	}

	/**
	 * 字段是否存在（区分「不存在」与「显式 null」）。
	 *
	 * @param mixed  $obj  对象/数组。
	 * @param string $name 字段名。
	 * @return bool
	 */
	public static function has( $obj, $name ) {
		if ( $obj instanceof \stdClass ) {
			return property_exists( $obj, $name );
		}
		return is_array( $obj ) && array_key_exists( $name, $obj );
	}

	/**
	 * 取出请求体的全部顶层字段。
	 *
	 * @param mixed $obj 对象/数组。
	 * @return array<string,mixed>
	 */
	public static function fields( $obj ) {
		if ( $obj instanceof \stdClass ) {
			return get_object_vars( $obj );
		}
		if ( is_array( $obj ) ) {
			return $obj;
		}
		return array();
	}

	/**
	 * 客户端 IP。
	 *
	 * WordPress 自带 wp_get_client_ip 语义（REMOTE_ADDR）；只有打开「信任代理头」
	 * 时才读 X-Forwarded-For / X-Real-IP（宝塔 + Nginx 反代场景）。
	 *
	 * @return string
	 */
	public static function client_ip() {
		if ( true === (bool) Config::option( 'trustProxy', true ) ) {
			$xff = self::header( 'X-Forwarded-For' );
			if ( is_string( $xff ) && '' !== $xff ) {
				$parts = explode( ',', $xff );
				$first = trim( $parts[0] );
				if ( '' !== $first && filter_var( $first, FILTER_VALIDATE_IP ) ) {
					return $first;
				}
			}
			$real = self::header( 'X-Real-IP' );
			if ( is_string( $real ) && '' !== $real && filter_var( $real, FILTER_VALIDATE_IP ) ) {
				return $real;
			}
		}
		return isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : 'unknown';
	}

	/**
	 * Authorization: Bearer xxx（后台 token 只认请求头）。
	 *
	 * @return string|null
	 */
	public static function bearer_token() {
		$header = (string) self::header( 'Authorization', '' );
		if ( 0 === stripos( $header, 'Bearer ' ) ) {
			$t = trim( substr( $header, 7 ) );
			if ( '' !== $t ) {
				return $t;
			}
		}
		return null;
	}

	/**
	 * 从 Authorization 头或 ?token= 中取 token（表情 blob 供 iOS AsyncImage 使用）。
	 *
	 * @return string|null
	 */
	public static function extract_token() {
		$t = self::bearer_token();
		if ( null !== $t ) {
			return $t;
		}
		$q = self::query( 'token' );
		if ( is_string( $q ) && '' !== $q ) {
			return $q;
		}
		return null;
	}

	// ------------------------------------------------------------------
	// 响应
	// ------------------------------------------------------------------

	/** 通用安全响应头。 */
	public static function security_headers() {
		if ( headers_sent() ) {
			return;
		}
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Referrer-Policy: no-referrer' );
	}

	/**
	 * 输出 JSON。
	 *
	 * @param mixed $data   数据。
	 * @param int   $status HTTP 状态码。
	 * @return string 序列化结果。
	 */
	public static function json( $data, $status = 200 ) {
		if ( ! headers_sent() ) {
			status_header( (int) $status );
			header( 'Content-Type: application/json; charset=utf-8' );
			header( 'Cache-Control: no-store' );
			self::security_headers();
		}
		$flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
		$out   = wp_json_encode( $data, $flags );
		if ( false === $out ) {
			status_header( 500 );
			$out = '{"error":"server_error","message":"响应序列化失败"}';
		}
		echo $out; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 这是 JSON 响应体。
		return $out;
	}

	/**
	 * 统一的成功响应：所有接口都带 serverTime（毫秒）。
	 *
	 * @param array<string,mixed> $payload 载荷。
	 * @return string
	 */
	public static function ok( array $payload ) {
		$payload['serverTime'] = Time::now_ms();
		return self::json( $payload, 200 );
	}

	/**
	 * 统一的错误响应。
	 *
	 * @param int    $status     HTTP 状态码。
	 * @param string $error_code 错误码。
	 * @param string $message    提示。
	 * @return string
	 */
	public static function error( $status, $error_code, $message ) {
		return self::json(
			array(
				'error'      => $error_code,
				'message'    => $message,
				'serverTime' => Time::now_ms(),
			),
			$status
		);
	}
}
