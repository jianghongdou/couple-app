<?php
/**
 * 插件引导与请求分发。
 *
 * 三种访问地址都走同一套 RouteTable：
 *   1. https://域名/api/...                          （插件自己加的 rewrite 规则）
 *   2. https://域名/wp-json/couple/v1/api/...        （REST 命名空间 couple/v1）
 *   3. https://域名/?couple_api=/api/...             （固定链接没开时的兜底）
 *
 * 分发时机：$wp->parse_request 的优先级 1（早于 WordPress 解析主查询、
 * 早于 rest_api_loaded、早于 canonical 重定向），这样 /api/... 不会被
 * WordPress 当成「文章别名」而 404 或重定向。
 *
 * @package CoupleSpace
 */

namespace CoupleSpace;

defined( 'ABSPATH' ) || exit;

/**
 * 插件主类。
 */
class Plugin {

	/** 选项：库结构版本（用于升级时再跑一次幂等迁移）。 */
	const DB_VERSION_OPTION = 'couple_space_db_version';

	/** 选项：是否需要刷新 rewrite 规则。 */
	const FLUSH_OPTION = 'couple_space_needs_flush';

	/** @var bool */
	private static $booted = false;

	/** 引导插件（挂在 plugins_loaded 上）。 */
	public static function boot() {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		// 路由注册表：三种地址共用。
		RouteTable::register();

		// 升级时补跑一次幂等迁移（建表 / 补列 / 补索引）。
		if ( get_option( self::DB_VERSION_OPTION ) !== COUPLE_SPACE_VERSION ) {
			try {
				Db::install();
				Config::ensure_dirs();
				Settings::ensure_admin_password_seeded();
				update_option( self::DB_VERSION_OPTION, COUPLE_SPACE_VERSION, false );
			} catch ( \Throwable $e ) {
				Log::error( '插件升级迁移失败', array( 'error' => $e->getMessage() ) );
			}
		}

		// 加 /api/(.*)$ rewrite 规则（让 App 直接填域名就能连上）。
		add_action( 'init', array( __CLASS__, 'register_rewrite' ) );
		add_filter( 'query_vars', array( __CLASS__, 'register_query_vars' ) );
		add_action( 'init', array( __CLASS__, 'maybe_flush_rewrite' ), 99 );

		// 兜底：即使没走 rewrite（固定链接没开 / 站点在子目录），
		// 也从 REQUEST_URI 里识别 /api/... 、/wp-json/couple/v1/... 与 ?couple_api=。
		add_action( 'parse_request', array( __CLASS__, 'handle_request' ), 1 );

		// 同一批路由也注册进 WordPress REST 命名空间 couple/v1，
		// 这样 /wp-json/couple/v1/api/... 是「真的注册过」而不是仅靠兜底识别。
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );

		// 后台管理界面与 WP-CLI。
		if ( is_admin() ) {
			Admin::boot();
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			Cli::boot();
		}

		// 每天清理过期的限流记录与已解散 30 天的情侣数据（WP-Cron）。
		add_action( 'couple_space_daily_cleanup', array( __CLASS__, 'daily_cleanup' ) );
		if ( ! wp_next_scheduled( 'couple_space_daily_cleanup' ) ) {
			wp_schedule_event( time() + 3600, 'daily', 'couple_space_daily_cleanup' );
		}
	}

	/**
	 * 注册 /api/(.*)$ -> index.php?couple_api=$matches[1]。
	 *
	 * 只加这一条规则：它同时覆盖 /api/... 与 /admin/api/...（因为
	 * /^api/ 只匹配以 api 开头的路径，后台域名下的 /admin/api/... 由兜底路径处理）。
	 * 后台接口在 WordPress 里改用 wp-admin 菜单，不再依赖 /admin/api/* 的 rewrite。
	 */
	public static function register_rewrite() {
		add_rewrite_tag( '%couple_api%', '(.+)' );
		// 用 top 优先级：避免被其它插件的规则抢先。
		add_rewrite_rule( '^api/(.*)$', 'index.php?couple_api=/api/$matches[1]', 'top' );
		add_rewrite_rule( '^api$', 'index.php?couple_api=/api', 'top' );
	}

	/**
	 * 把 couple_api 注册成公开 query var。
	 *
	 * @param array<int,string> $vars 现有 query vars。
	 * @return array<int,string>
	 */
	public static function register_query_vars( $vars ) {
		$vars[] = 'couple_api';
		return $vars;
	}

	/** 需要时刷新一次 rewrite 规则（只在激活后第一次请求真正执行）。 */
	public static function maybe_flush_rewrite() {
		if ( '1' === (string) get_option( self::FLUSH_OPTION, '' ) ) {
			update_option( self::FLUSH_OPTION, '0', false );
			flush_rewrite_rules( false );
		}
	}

	/** 供激活钩子调用：标记需要刷新 rewrite 规则。 */
	public static function mark_rewrite_dirty() {
		update_option( self::FLUSH_OPTION, '1', false );
	}

	/**
	 * 把同一批路由注册到 WordPress REST 命名空间 couple/v1。
	 *
	 * 注意：真实请求由 parse_request（优先级 1）先行接管并直接输出，
	 * 这里注册主要是为了让 /wp-json/couple/v1/... 在 WP 的 REST 路由表里
	 * 真实存在（便于排查、也被 WP 的 CORS / 认证钩子覆盖）。
	 */
	public static function register_rest_routes() {
		if ( ! function_exists( 'register_rest_route' ) ) {
			return;
		}
		foreach ( Router::all() as $route ) {
			$path = $route['pattern'];
			// 把 :id 这类占位符换成 REST 风格的正则命名组。
			$path = preg_replace( '#:([A-Za-z0-9_]+)#', '(?P<$1>[^/]+)', $path );
			$methods = $route['method'];
			register_rest_route(
				COUPLE_SPACE_REST_NAMESPACE,
				$path,
				array(
					'methods'             => $methods,
					// 鉴权完全由插件自己的 JWT 完成（Authorization: Bearer / ?token=），
					// 不使用 WordPress 的 cookie + nonce 认证。
					'permission_callback' => '__return_true',
					'callback'            => static function () use ( $route ) {
						$result = Router::dispatch( $route['method'], $route['pattern'] );
						if ( $result instanceof RawResponse ) {
							return new \WP_REST_Response( $result->body, $result->status );
						}
						if ( is_array( $result ) && isset( $result['payload'] ) ) {
							$payload               = $result['payload'];
							$payload['serverTime'] = Time::now_ms();
							return new \WP_REST_Response( $payload, isset( $result['status'] ) ? (int) $result['status'] : 200 );
						}
						return new \WP_REST_Response( array( 'error' => 'server_error' ), 500 );
					},
				)
			);
		}
	}

	/**
	 * 识别并处理本插件的 API 请求。
	 *
	 * @param \WP $wp WordPress 环境对象。
	 */
	public static function handle_request( $wp = null ) {
		$path = self::detect_api_path( $wp );
		if ( '' === $path ) {
			return;
		}
		Dispatcher::serve( $path );
	}

	/**
	 * 从当前请求中解析出 API 路径；不是本插件的请求返回空串。
	 *
	 * @param \WP|null $wp WordPress 环境对象。
	 * @return string
	 */
	public static function detect_api_path( $wp = null ) {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- 只用来识别路由，不读业务参数。
		$couple_api = isset( $_GET['couple_api'] ) && is_string( $_GET['couple_api'] ) ? wp_unslash( $_GET['couple_api'] ) : '';
		$rest_route = isset( $_GET['rest_route'] ) && is_string( $_GET['rest_route'] ) ? wp_unslash( $_GET['rest_route'] ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( '' === $couple_api && '' === $rest_route && is_object( $wp ) ) {
			$qv = isset( $wp->query_vars['couple_api'] ) ? (string) $wp->query_vars['couple_api'] : '';
			if ( '' !== $qv ) {
				$couple_api = $qv;
			}
			$rq = isset( $wp->query_vars['rest_route'] ) ? (string) $wp->query_vars['rest_route'] : '';
			if ( '' !== $rq ) {
				$rest_route = $rq;
			}
		}

		return Router::normalize_path( Http::raw_path(), $couple_api, $rest_route );
	}

	/** 每日清理：限流记录 + 已解散超过 30 天的情侣数据。 */
	public static function daily_cleanup() {
		try {
			RateLimit::purge( 86400000 );
		} catch ( \Throwable $e ) {
			Log::warn( '清理限流记录失败', array( 'error' => $e->getMessage() ) );
		}
		try {
			Db::purge_dissolved_couples( Config::limit( 'COUPLE_RETENTION_DAYS' ) );
		} catch ( \Throwable $e ) {
			Log::warn( '清理过期情侣数据失败', array( 'error' => $e->getMessage() ) );
		}
	}

	/**
	 * 当前站点应该让 App 填写的服务器地址（后台首页直接展示）。
	 *
	 * @return array{primary:string,alternatives:array<int,string>,note:string}
	 */
	public static function app_server_urls() {
		$home = untrailingslashit( home_url() );
		return array(
			'primary'      => $home,
			'alternatives' => array(
				$home . '/wp-json/couple/v1',
				$home . '/?couple_api=',
			),
			'note'         => '推荐直接填站点地址（App 会自动加 /api/...）；若站点开了固定链接之外的特殊规则，可改用第二种地址。',
		);
	}
}
