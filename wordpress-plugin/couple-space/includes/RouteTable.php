<?php
/**
 * 路由登记表：所有接口只在这里登记一次，三种访问地址共用。
 *
 * 地址形式：
 *   1. https://域名/api/...                       （插件 rewrite：^api/(.*)$）
 *   2. https://域名/wp-json/couple/v1/api/...     （REST 命名空间 couple/v1）
 *   3. https://域名/?couple_api=/api/...          （固定链接没开时的兜底）
 *
 * @package CoupleSpace
 */

namespace CoupleSpace;

defined( 'ABSPATH' ) || exit;

/**
 * 路由登记。
 */
class RouteTable {

	/** @var bool */
	private static $registered = false;

	/** 注册全部路由。 */
	public static function register() {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		$r = new Registrar();
		Routes\MetaRoutes::register( $r );
		Routes\AuthRoutes::register( $r );
		Routes\CoupleRoutes::register( $r );
		Routes\SyncRoutes::register( $r );
		Routes\ChatRoutes::register( $r );
		Routes\EmojiRoutes::register( $r );
		Routes\AiRoutes::register( $r );
		Routes\AiChatRoutes::register( $r );
		Routes\AdminRoutes::register( $r );
	}

	/** @return array<int,array{method:string,segs:array<int,string>,handler:callable,pattern:string}> */
	public static function all() {
		return Router::all();
	}
}
