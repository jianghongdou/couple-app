<?php
/**
 * 插件内部共享守卫：路由白名单、请求体解析、输出工具。
 *
 * @package CoupleSpace
 */

namespace CoupleSpace;

defined( 'ABSPATH' ) || exit;

/**
 * 路由注册表：所有接口只在 RouteTable 里登记一次，
 * 三种访问地址（/api/...、/wp-json/couple/v1/api/...、/?couple_api=/api/...）共用同一份。
 */
class Router {

	/** @var array<int,array{method:string,segs:array<int,string>,handler:callable,pattern:string}> */
	private static $routes = array();

	/**
	 * 登记一条路由。
	 *
	 * @param string   $method  HTTP 方法。
	 * @param string   $pattern 形如 /api/auth/login 或 /api/emoji/blob/:id。
	 * @param callable $handler 处理函数，接收 $params 数组。
	 */
	public static function add( $method, $pattern, callable $handler ) {
		$segs = array();
		foreach ( explode( '/', $pattern ) as $s ) {
			if ( '' !== $s ) {
				$segs[] = $s;
			}
		}
		self::$routes[] = array(
			'method'  => strtoupper( $method ),
			'segs'    => $segs,
			'handler' => $handler,
			'pattern' => $pattern,
		);
	}

	/** @return array<int,array{method:string,segs:array<int,string>,handler:callable,pattern:string}> */
	public static function all() {
		return self::$routes;
	}

	/**
	 * 把请求路径切成段（每段已 rawurldecode）。
	 *
	 * 与 server-php Router 一样按「段」匹配，而不是拼正则：这样
	 * /api/emoji/items/e_..%2F..%2Fdb 会把解码后的整段交给业务层校验并返回 400，
	 * 而不是被服务器/框架提前处理。
	 *
	 * @param string $path 形如 /api/auth/login。
	 * @return array<int,string>
	 */
	public static function segments( $path ) {
		$out = array();
		foreach ( explode( '/', trim( (string) $path, '/' ) ) as $p ) {
			if ( '' === $p ) {
				continue;
			}
			$out[] = rawurldecode( $p );
		}
		return $out;
	}

	/**
	 * 按段匹配并执行。
	 *
	 * 处理函数不直接输出，而是返回：
	 *   - array{payload:array,status:int}  一份 JSON 响应
	 *   - RawResponse                      原始响应（表情图片二进制）
	 * 由 Dispatcher 统一输出，保证错误处理与响应头只有一处实现。
	 *
	 * @param string $method HTTP 方法。
	 * @param string $path   请求路径。
	 * @return mixed 未命中返回 null。
	 */
	public static function dispatch( $method, $path ) {
		$method = strtoupper( $method );
		if ( 'HEAD' === $method ) {
			$method = 'GET';
		}
		$segments = self::segments( $path );

		foreach ( self::$routes as $route ) {
			if ( $route['method'] !== $method ) {
				continue;
			}
			$params = self::match_segments( $route['segs'], $segments );
			if ( null === $params ) {
				continue;
			}
			return call_user_func( $route['handler'], $params );
		}
		return null;
	}

	/** @return bool 路径是否是本插件登记过的接口（不区分是否命中方法）。 */
	public static function has_path( $path ) {
		$segments = self::segments( $path );
		foreach ( self::$routes as $route ) {
			if ( null !== self::match_segments( $route['segs'], $segments ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param array<int,string> $pattern  路由段。
	 * @param array<int,string> $segments 请求段。
	 * @return array<string,string>|null
	 */
	private static function match_segments( array $pattern, array $segments ) {
		if ( count( $pattern ) !== count( $segments ) ) {
			return null;
		}
		$params = array();
		foreach ( $pattern as $i => $p ) {
			$value = $segments[ $i ];
			if ( strlen( $p ) > 0 && ':' === $p[0] ) {
				$params[ substr( $p, 1 ) ] = $value;
				continue;
			}
			if ( $p !== $value ) {
				return null;
			}
		}
		return $params;
	}

	/**
	 * 把任意形式的请求地址归一化成 /api/... 或 /admin/api/...
	 *
	 * 支持：
	 *   - /api/health           （伪静态 rewrite）
	 *   - /wp-json/couple/v1/api/health
	 *   - /?couple_api=/api/health
	 *   - /index.php?rest_route=/couple/v1/api/health
	 *   - 子目录安装（/blog/api/health）
	 *
	 * @param string $uri        原始 REQUEST_URI（不含查询串）。
	 * @param string $couple_api $_GET['couple_api']。
	 * @param string $rest_route $_GET['rest_route']。
	 * @return string 归一化路径；无法识别时返回空串。
	 */
	public static function normalize_path( $uri, $couple_api = '', $rest_route = '' ) {
		if ( is_string( $couple_api ) && '' !== $couple_api ) {
			return self::clean( $couple_api );
		}
		if ( is_string( $rest_route ) && '' !== $rest_route ) {
			$r = '/' . ltrim( $rest_route, '/' );
			$ns = '/' . COUPLE_SPACE_REST_NAMESPACE;
			if ( 0 === strpos( $r, $ns . '/' ) ) {
				return self::clean( substr( $r, strlen( $ns ) ) );
			}
			return self::clean( $r );
		}

		$uri = (string) $uri;
		if ( '' === $uri ) {
			$uri = '/';
		}
		// 去掉可能存在的绝对地址前缀（部分代理会写成 http://host/path）。
		if ( preg_match( '#^[a-zA-Z][a-zA-Z0-9+.-]*://[^/]*(/.*)$#', $uri, $m ) ) {
			$uri = $m[1];
		}

		$ns = '/' . COUPLE_SPACE_REST_NAMESPACE;
		if ( preg_match( '#' . preg_quote( $ns, '#' ) . '(/api(?:/.*)?)$#', $uri, $m ) ) {
			return self::clean( $m[1] );
		}
		// 后台接口必须先于 /api/ 判断，避免 /admin/api/... 被 /api/ 误截。
		if ( preg_match( '#(/admin/api(?:/.*)?)$#', $uri, $m ) ) {
			return self::clean( $m[1] );
		}
		if ( preg_match( '#(/api(?:/.*)?)$#', $uri, $m ) ) {
			return self::clean( $m[1] );
		}
		return '';
	}

	/**
	 * 清理路径：只保留 /api/... 或 /admin/api/...，其余一律丢弃。
	 *
	 * @param string $path 原始路径。
	 * @return string
	 */
	private static function clean( $path ) {
		$path = (string) $path;
		$pos  = strpos( $path, '?' );
		if ( false !== $pos ) {
			$path = substr( $path, 0, $pos );
		}
		$path = '/' . ltrim( $path, '/' );
		if ( ! preg_match( '#^/(admin/api|api)(/|$)#', $path ) ) {
			return '';
		}
		return $path;
	}
}
