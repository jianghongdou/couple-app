<?php
/**
 * App 后台管理接口（API.md §9）。
 *
 * 移植自 server-php/src/routes/AdminRoutes.php。所有写操作都会记入 admin_logs。
 *
 * 两套后台互不干扰：
 *   - 这里的 /admin/api/* 是「App 的后台」，用本插件自己的 JWT（bcrypt 密码在自建 settings 表）
 *   - WordPress 的 wp-admin 菜单是「站长的后台」，用 WordPress 用户与权限
 *
 * @package CoupleSpace
 */

namespace CoupleSpace\Routes;

use CoupleSpace\AiError;
use CoupleSpace\Auth;
use CoupleSpace\Base;
use CoupleSpace\Config;
use CoupleSpace\Db;
use CoupleSpace\DeepSeek;
use CoupleSpace\EmojiStore;
use CoupleSpace\Http;
use CoupleSpace\HttpException;
use CoupleSpace\Log;
use CoupleSpace\RateLimit;
use CoupleSpace\Settings;
use CoupleSpace\Validate;
use CoupleSpace\WsCompat;

defined( 'ABSPATH' ) || exit;

/**
 * 后台接口。
 */
class AdminRoutes extends Base {

	/**
	 * 注册路由。
	 *
	 * @param object $r 路由器。
	 */
	public static function register( $r ) {
		// ---------------------------------------------------------------
		// 登录（唯一不需要鉴权的后台接口）。
		// ---------------------------------------------------------------
		$r->post(
			'/admin/api/login',
			static function () {
				// 后台登录限流：同一 IP 15 分钟最多 10 次。
				RateLimit::enforce( 'adminlogin:' . Http::client_ip(), 10, 900000, '登录尝试过于频繁，请稍后再试' );

				$body     = Http::json_body();
				$password = Http::field( $body, 'password' );
				$valid    = Auth::verify_admin_password( $password );

				if ( ! $valid ) {
					// 还没设过任何后台密码（数据库没有哈希、选项里也没有初始密码）时，给一条能照着做的提示。
					if ( ! Settings::admin_password_in_database() && '' === (string) Config::option( 'initialAdminPassword', '' ) ) {
						throw new HttpException(
							409,
							'admin_password_unset',
							'尚未设置后台密码。请在 WordPress 后台「情侣空间 → 服务器信息」里设置，或执行：wp couple-space set-admin-password 你的新密码'
						);
					}
					Auth::log_admin_action( 'admin.login.failed', '密码错误' );
					Log::warn( '后台登录失败', array( 'ip' => Http::client_ip() ) );
					throw new HttpException( 401, 'unauthorized', '密码错误' );
				}

				$token = Auth::sign_admin_token();
				Auth::log_admin_action( 'admin.login.success', '登录成功' );
				Log::info( '后台登录成功', array( 'ip' => Http::client_ip() ) );
				return self::ok( array( 'token' => $token ) );
			}
		);

		// 以下全部需要后台 token。
		$admin = static function () {
			Auth::require_admin();
		};

		// GET /admin/api/settings。
		$r->get(
			'/admin/api/settings',
			static function () use ( $admin ) {
				$admin();
				return self::ok( Settings::for_admin() );
			}
		);

		// PUT /admin/api/settings
		// deepseekApiKey 传明文即覆盖，传空字符串表示清空。
		$r->put(
			'/admin/api/settings',
			static function () use ( $admin ) {
				$admin();
				$body   = Http::json_body();
				$fields = Http::fields( $body );
				if ( 0 === count( $fields ) ) {
					Validate::fail( '请求体不能为空' );
				}

				// 记录变更字段名（不记录 Key 明文）。
				$changed = array();
				foreach ( array_keys( $fields ) as $k ) {
					if ( 'deepseekApiKey' !== $k ) {
						$changed[] = $k;
					}
				}
				$key_changed = array_key_exists( 'deepseekApiKey', $fields );

				$settings = Settings::update( $fields );

				$parts = array();
				if ( count( $changed ) > 0 ) {
					$parts[] = '字段：' . implode( '、', $changed );
				}
				if ( $key_changed ) {
					$key_value = $fields['deepseekApiKey'];
					$parts[]   = ( is_string( $key_value ) && '' !== $key_value ) ? 'DeepSeek Key：已更新' : 'DeepSeek Key：已清空';
				}
				Auth::log_admin_action( 'settings.update', count( $parts ) > 0 ? implode( '；', $parts ) : '无变更' );
				Log::info(
					'后台更新设置',
					array(
						'fields'     => implode( ',', $changed ),
						'keyChanged' => $key_changed,
					)
				);

				return self::ok(
					array(
						'ok'       => true,
						'settings' => $settings,
					)
				);
			}
		);

		// POST /admin/api/admin-password —— 后台自助修改密码（API.md 之外的后台专用接口）。
		//
		// 密码存 settings.adminPasswordHash（bcrypt），任何响应都不回显哈希/明文。
		// 改密码**不会**让已签发的 admin token 失效（避免用户把自己踢出去）。
		$r->post(
			'/admin/api/admin-password',
			static function () use ( $admin ) {
				$admin();
				// 防止用暴力方式猜测「当前密码」：同一 IP 15 分钟最多 10 次。
				RateLimit::enforce( 'adminpwd:' . Http::client_ip(), 10, 900000, '密码修改尝试过于频繁，请稍后再试' );

				$body     = Http::json_body();
				$current  = Validate::require_string(
					Http::field( $body, 'currentPassword' ),
					'currentPassword',
					array(
						'min' => 1,
						'max' => Config::limit( 'PASSWORD_MAX' ),
					)
				);
				$new      = Validate::require_string(
					Http::field( $body, 'newPassword' ),
					'newPassword',
					array(
						'min' => Config::limit( 'PASSWORD_MIN' ),
						'max' => Config::limit( 'PASSWORD_MAX' ),
					)
				);

				// 当前密码不对 → 401（与登录失败同样的错误码风格），并记日志。
				if ( ! Auth::verify_admin_password( $current ) ) {
					Auth::log_admin_action( 'admin.password.change_failed', '当前密码错误' );
					Log::warn( '后台修改密码失败：当前密码错误', array( 'ip' => Http::client_ip() ) );
					throw new HttpException( 401, 'unauthorized', '当前密码错误' );
				}

				if ( $new === $current ) {
					Auth::log_admin_action( 'admin.password.change_failed', '新密码与当前密码相同' );
					Validate::fail( '新密码不能与当前密码相同' );
				}

				Settings::set_admin_password_hash( Auth::hash_admin_password( $new ) );
				Auth::log_admin_action( 'admin.password.changed', '后台密码已修改（bcrypt 哈希存入数据库）' );
				Log::info( '后台密码已修改', array( 'ip' => Http::client_ip() ) );

				return self::ok(
					array(
						'ok'                      => true,
						'message'                 => '后台密码已修改，当前登录仍然有效',
						'adminPasswordInDatabase' => true,
						'adminPasswordSource'     => 'database',
					)
				);
			}
		);

		// POST /admin/api/ai/test —— 用当前（或临时传入的）Key 试跑一条文本。
		$r->post(
			'/admin/api/ai/test',
			static function () use ( $admin ) {
				$admin();
				$body     = Http::json_body();
				$text_raw = Http::field( $body, 'text' );
				$text     = ( null === $text_raw || '' === trim( (string) $text_raw ) )
					? '今天午饭25，打车18块5'
					: Validate::require_string(
						$text_raw,
						'text',
						array(
							'min'  => 1,
							'max'  => Config::limit( 'AI_TEXT_MAX' ),
							'trim' => true,
						)
					);
				$today    = gmdate( 'Y-m-d' );

				// 允许临时传入 Key / 模型用于「先测通再保存」的场景。
				$override_key_raw     = Http::field( $body, 'deepseekApiKey' );
				$override_model_raw   = Http::field( $body, 'deepseekModel' );
				$override_baseurl_raw = Http::field( $body, 'deepseekBaseUrl' );
				$override_key         = is_string( $override_key_raw ) ? trim( $override_key_raw ) : '';
				$override_model       = is_string( $override_model_raw ) ? trim( $override_model_raw ) : '';
				$override_baseurl     = is_string( $override_baseurl_raw ) ? trim( $override_baseurl_raw ) : '';

				try {
					if ( '' !== $override_key || '' !== $override_model || '' !== $override_baseurl ) {
						$result = DeepSeek::parse_ledger(
							array(
								'text'     => $text,
								'currency' => 'CNY',
								'today'    => $today,
								'apiKey'   => $override_key,
								'model'    => $override_model,
								'baseUrl'  => $override_baseurl,
							)
						);

						Auth::log_admin_action( 'ai.test', '临时配置测试成功，识别 ' . count( $result['items'] ) . ' 条' );
						return self::ok(
							array(
								'ok'                 => true,
								'usingTemporaryKey'  => true,
								'text'               => $result['summary'],
								'items'              => $result['items'],
								'total'              => $result['total'],
								'model'              => $result['model'],
							)
						);
					}

					if ( ! Settings::is_ai_configured() ) {
						throw new HttpException( 403, 'forbidden', 'AI 未配置' );
					}

					$result = DeepSeek::parse_ledger(
						array(
							'text'     => $text,
							'currency' => 'CNY',
							'today'    => $today,
						)
					);
					Auth::log_admin_action( 'ai.test', '测试成功，识别 ' . count( $result['items'] ) . ' 条' );
					return self::ok(
						array(
							'ok'                => true,
							'usingTemporaryKey' => false,
							'text'              => $result['summary'],
							'items'             => $result['items'],
							'total'             => $result['total'],
							'model'             => $result['model'],
						)
					);
				} catch ( AiError $e ) {
					Auth::log_admin_action( 'ai.test.failed', $e->getMessage() );
					throw new HttpException( $e->get_status() ? $e->get_status() : 502, $e->get_error_code(), $e->getMessage() );
				}
			}
		);

		// GET /admin/api/stats —— 用户数/情侣数/消息数/今日 AI 调用/磁盘占用。
		$r->get(
			'/admin/api/stats',
			static function () use ( $admin ) {
				$admin();
				$stats = Db::get_stats();
				$disk  = Db::get_disk_usage();
				return self::ok(
					array(
						'users'         => $stats['users'],
						'couples'       => $stats['couples'],
						'couplesTotal'  => $stats['couplesTotal'],
						'messages'      => $stats['messages'],
						'aiToday'       => $stats['aiToday'],
						'aiTotal'       => $stats['aiTotal'],
						'packs'         => $stats['packs'],
						'items'         => $stats['items'],
						'syncObjects'   => $stats['syncObjects'],
						'disk'          => array(
							'dbBytes'    => $disk['dbBytes'],
							'emojiBytes' => $disk['emojiBytes'],
							'emojiFiles' => $disk['emojiFiles'],
							'totalBytes' => $disk['totalBytes'],
						),
						'uptimeSeconds' => WsCompat::uptime_seconds(),
						'online'        => WsCompat::online_count(),
					)
				);
			}
		);

		// GET /admin/api/users?query=&page=&pageSize=。
		$r->get(
			'/admin/api/users',
			static function () use ( $admin ) {
				$admin();
				$query_raw = Http::query( 'query' );
				$query     = is_string( $query_raw ) ? mb_substr( trim( $query_raw ), 0, 64, 'UTF-8' ) : '';
				$page      = Validate::query_int( Http::query( 'page' ), 'page', array( 'defaultValue' => 1, 'min' => 1, 'max' => 100000 ) );
				$page_size = Validate::query_int(
					Http::query( 'pageSize' ),
					'pageSize',
					array(
						'defaultValue' => 20,
						'min'          => 1,
						'max'          => 100,
					)
				);

				return self::ok(
					Db::list_users(
						array(
							'query'    => $query,
							'page'     => $page,
							'pageSize' => $page_size,
						)
					)
				);
			}
		);

		// POST /admin/api/users/:id/reset-password  { newPassword }。
		$r->post(
			'/admin/api/users/:id/reset-password',
			static function ( $params ) use ( $admin ) {
				$admin();
				$id  = Validate::require_string( $params['id'], 'id', array( 'min' => 1, 'max' => 64 ) );
				$body = Http::json_body();
				$new  = Validate::require_string(
					Http::field( $body, 'newPassword' ),
					'newPassword',
					array(
						'min' => Config::limit( 'PASSWORD_MIN' ),
						'max' => Config::limit( 'PASSWORD_MAX' ),
					)
				);

				$found = Db::get_user_by_id( $id );
				if ( ! $found ) {
					throw new HttpException( 404, 'not_found', '用户不存在' );
				}
				$hash = password_hash( $new, PASSWORD_BCRYPT, array( 'cost' => Auth::BCRYPT_ROUNDS ) );
				Db::update_user_password( $id, $hash );

				Auth::log_admin_action( 'user.reset_password', '用户 ' . $found['user']['username'] . '（' . $id . '）' );
				Log::info( '后台重置用户密码', array( 'userId' => $id ) );
				return self::ok( array( 'ok' => true ) );
			}
		);

		// POST /admin/api/users/:id/toggle-disabled  { disabled }。
		$r->post(
			'/admin/api/users/:id/toggle-disabled',
			static function ( $params ) use ( $admin ) {
				$admin();
				$id   = Validate::require_string( $params['id'], 'id', array( 'min' => 1, 'max' => 64 ) );
				$body = Http::json_body();
				$dis  = Validate::optional_bool( Http::field( $body, 'disabled' ), 'disabled', null );
				if ( null === $dis ) {
					Validate::fail( 'disabled 必须是布尔值' );
				}

				$found = Db::get_user_by_id( $id );
				if ( ! $found ) {
					throw new HttpException( 404, 'not_found', '用户不存在' );
				}
				Db::set_user_disabled( $id, $dis );
				Auth::log_admin_action(
					'user.toggle_disabled',
					'用户 ' . $found['user']['username'] . '（' . $id . '）-> ' . ( $dis ? '禁用' : '启用' )
				);
				Log::info(
					'后台修改用户禁用状态',
					array(
						'userId'   => $id,
						'disabled' => $dis,
					)
				);
				return self::ok(
					array(
						'ok'   => true,
						'user' => Db::get_user_by_id( $id )['user'],
					)
				);
			}
		);

		// DELETE /admin/api/users/:id。
		$r->delete(
			'/admin/api/users/:id',
			static function ( $params ) use ( $admin ) {
				$admin();
				$id    = Validate::require_string( $params['id'], 'id', array( 'min' => 1, 'max' => 64 ) );
				$found = Db::get_user_by_id( $id );
				if ( ! $found ) {
					throw new HttpException( 404, 'not_found', '用户不存在' );
				}
				Db::delete_user( $id );
				Auth::log_admin_action( 'user.delete', '用户 ' . $found['user']['username'] . '（' . $id . '）' );
				Log::warn(
					'后台删除用户',
					array(
						'userId'   => $id,
						'username' => $found['user']['username'],
					)
				);
				return self::ok( array( 'ok' => true ) );
			}
		);

		// GET /admin/api/packs —— 表情包统计。
		$r->get(
			'/admin/api/packs',
			static function () use ( $admin ) {
				$admin();
				$disk = Db::get_disk_usage();
				return self::ok(
					array(
						'totalPacks'        => Db::count_emoji_packs(),
						'totalItems'        => Db::count_emoji_items(),
						'emojiBytes'        => $disk['emojiBytes'],
						'emojiFiles'        => $disk['emojiFiles'],
						'maxPacksPerCouple' => Config::limit( 'EMOJI_MAX_PACKS' ),
						'maxItemsPerPack'   => Config::limit( 'EMOJI_MAX_ITEMS_PER_PACK' ),
						'maxItemBytes'      => Config::limit( 'EMOJI_MAX_BYTES' ),
					)
				);
			}
		);

		// GET /admin/api/logs?limit=100。
		$r->get(
			'/admin/api/logs',
			static function () use ( $admin ) {
				$admin();
				$limit = Validate::query_int(
					Http::query( 'limit' ),
					'limit',
					array(
						'defaultValue' => 100,
						'min'          => 1,
						'max'          => 500,
					)
				);
				return self::ok( array( 'logs' => Db::list_admin_logs( $limit ) ) );
			}
		);

		// GET /admin/api/system —— 附加系统信息（后台页面展示用）。
		$r->get(
			'/admin/api/system',
			static function () use ( $admin ) {
				$admin();
				$db_ok = false;
				try {
					$db_ok = Db::ping_db();
				} catch ( \Throwable $e ) {
					$db_ok = false;
				}
				$emoji    = EmojiStore::status();
				$uploads  = wp_get_upload_dir();

				return self::ok(
					array(
						'php'                     => PHP_VERSION,
						'platform'                => PHP_OS . ' ' . php_uname( 'm' ),
						'sapi'                    => PHP_SAPI,
						'pid'                     => function_exists( 'getmypid' ) ? getmypid() : 0,
						'uptimeSeconds'           => WsCompat::uptime_seconds(),
						'memoryBytes'             => memory_get_usage( true ),
						'wsRunning'               => WsCompat::is_running(),
						'wsOnline'                => WsCompat::online_count(),
						'websocket'               => array(
							'supported' => false,
							'note'      => 'WordPress 是请求-响应模型，无法常驻 WebSocket 进程；聊天走 App 的 5 秒轮询兜底。',
						),
						'env'                     => array(
							'wordpress'   => get_bloginfo( 'version' ),
							'plugin'      => COUPLE_SPACE_VERSION,
							'tablePrefix' => ( function () {
								global $wpdb;
								return $wpdb->prefix;
							} )(),
							'emojiDir'    => $emoji['dir'],
							'uploadsDir'  => isset( $uploads['basedir'] ) ? $uploads['basedir'] : '',
							'trustProxy'  => (bool) Config::option( 'trustProxy', true ),
						),
						'database'                => array(
							'engine'        => Db::server_engine(),
							'version'       => Db::server_version(),
							'host'          => defined( 'DB_HOST' ) ? (string) DB_HOST : '',
							'name'          => defined( 'DB_NAME' ) ? (string) DB_NAME : '',
							'user'          => defined( 'DB_USER' ) ? (string) DB_USER : '',
							'charset'       => Config::db_charset(),
							'connected'     => $db_ok,
							'schemaVersion' => (int) Db::get_setting( 'schema_version' ),
						),
						'timezone'                => wp_timezone_string(),
						'jwtSecretConfigured'     => ( '' !== (string) Config::option( 'jwtSecret', '' ) ),
						'jwtSecretSource'         => Config::jwt_secret_source(),
						'adminPasswordConfigured' => Settings::admin_password_in_database() || '' !== (string) Config::option( 'initialAdminPassword', '' ),
						// 后台密码只暴露来源，绝不返回哈希/明文。
						'adminPasswordInDatabase' => Settings::admin_password_in_database(),
						'adminPasswordSource'     => Settings::admin_password_source(),
						'installed'               => Db::has_core_tables(),
						'configFile'              => null,
						'appServerUrls'           => \CoupleSpace\Plugin::app_server_urls(),
					)
				);
			}
		);
	}
}
