<?php
/**
 * 公开接口：健康检查与客户端元信息（API.md §1）。
 *
 * 移植自 server-php/src/routes/MetaRoutes.php。
 *
 * @package CoupleSpace
 */

namespace CoupleSpace\Routes;

use CoupleSpace\Base;
use CoupleSpace\Config;
use CoupleSpace\Db;
use CoupleSpace\Http;
use CoupleSpace\Settings;
use CoupleSpace\Time;
use CoupleSpace\WsCompat;

defined( 'ABSPATH' ) || exit;

/**
 * 公开接口。
 */
class MetaRoutes extends Base {

	/**
	 * 注册路由。
	 *
	 * @param object $r 路由器（Router / RouteRegistrar）。
	 */
	public static function register( $r ) {
		// GET /api/health —— { ok, name, version, time }。
		$r->get(
			'/api/health',
			static function () {
				return self::ok(
					array(
						'ok'      => true,
						'name'    => COUPLE_SPACE_SERVER_NAME,
						'version' => COUPLE_SPACE_VERSION,
						'time'    => Time::now_ms(),
					)
				);
			}
		);

		// GET /api/meta —— 无需鉴权，客户端启动时拉取。
		$r->get(
			'/api/meta',
			static function () {
				$s             = Settings::read_all();
				$ai_on         = ( false !== $s['aiEnabled'] );
				$ai_configured = ( '' !== $s['deepseekApiKey'] );

				// AI 聊天（docs/API-AI聊天.md §AI-5 第 6 条）：
				// features.aiChat = AI 聊天是否可用；features.aiProviders = 启用供应商数量。
				$ai_chat_on  = $ai_on && Settings::is_ai_chat_enabled();
				$ai_provider = 0;
				$ai_chat_ok  = false;
				try {
					$ai_provider = Db::count_enabled_ai_providers();
					$ai_chat_ok  = ( null !== Db::get_usable_ai_provider() );
				} catch ( \Throwable $e ) {
					$ai_provider = 0;
					$ai_chat_ok  = false;
				}

				return self::ok(
					array(
						'appName'         => COUPLE_SPACE_DISPLAY_NAME,
						'serverVersion'   => COUPLE_SPACE_VERSION,
						'minAppBuild'     => is_int( $s['minAppBuild'] ) ? $s['minAppBuild'] : 1,
						'latestAppBuild'  => is_int( $s['latestAppBuild'] ) ? $s['latestAppBuild'] : 1,
						'updateURL'       => is_string( $s['updateURL'] ) ? $s['updateURL'] : '',
						'announcement'    => is_string( $s['announcement'] ) ? $s['announcement'] : '',
						'registerOpen'    => ( false !== $s['registerOpen'] ),
						'features'        => array(
							'chat'        => true,
							'emoji'       => true,
							'ai'          => $ai_on,
							'aiChat'      => (bool) $ai_chat_on,
							'aiProviders' => (int) $ai_provider,
							'sync'        => true,
						),
						// 是否至少有一个可用的 AI 供应商（记账 AI 的 aiConfigured 保持原语义）。
						'aiConfigured'    => $ai_configured,
					)
				);
			}
		);

		// GET /api/status —— 运维概览（公开只读，不含敏感信息）。
		$r->get(
			'/api/status',
			static function () {
				$stats = Db::get_stats();
				return self::ok(
					array(
						'ok'            => true,
						'version'       => COUPLE_SPACE_VERSION,
						'users'         => $stats['users'],
						'couples'       => $stats['couples'],
						'messages'      => $stats['messages'],
						'online'        => WsCompat::online_count(),
						'dbOk'          => Db::ping_db(),
						'uptimeSeconds' => WsCompat::uptime_seconds(),
					)
				);
			}
		);
	}
}
