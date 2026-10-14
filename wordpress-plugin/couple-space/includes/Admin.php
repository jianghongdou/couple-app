<?php
/**
 * WordPress 后台管理界面与表单处理。
 *
 * 一个顶级菜单「情侣空间」+ 5 个分区：
 *   总览      —— 当前应填的 App 服务器地址、WebSocket 说明、后台密码来源
 *   AI 设置   —— Key / 模型 / 开关 / 每日限额 / 测试按钮
 *   应用设置  —— 开放注册、公告、最低/最新版本号、更新地址、表情包上限、每日消息上限
 *   用户管理  —— 搜索 / 重置密码 / 启用禁用 / 删除
 *   统计      —— 用户数、情侣数、消息数、AI 调用、磁盘占用
 *   操作日志  —— admin_logs
 *   服务器信息—— 数据库与表状态、密码来源、PHP/WP 环境
 *
 * 权限与 CSRF：全部走 current_user_can('manage_options') + check_admin_referer()。
 *
 * @package CoupleSpace
 */

namespace CoupleSpace;

defined( 'ABSPATH' ) || exit;

/**
 * 后台界面。
 */
class Admin {

	/** 顶级菜单 slug。 */
	const SLUG = 'couple-space';

	/** 每页条数。 */
	const PER_PAGE = 20;

	/** 引导后台。 */
	public static function boot() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_couple_space_settings', array( __CLASS__, 'handle_settings' ) );
		add_action( 'admin_post_couple_space_app_settings', array( __CLASS__, 'handle_app_settings' ) );
		add_action( 'admin_post_couple_space_admin_password', array( __CLASS__, 'handle_admin_password' ) );
		add_action( 'admin_post_couple_space_user_action', array( __CLASS__, 'handle_user_action' ) );
		add_action( 'admin_post_couple_space_ai_test', array( __CLASS__, 'handle_ai_test' ) );
		add_action( 'admin_post_couple_space_secret', array( __CLASS__, 'handle_secret' ) );

		// AI 供应商 / AI 聊天（docs/API-AI聊天.md §AI-5）。
		add_action( 'admin_post_couple_space_ai_provider', array( 'CoupleSpace\\AiAdmin', 'handle_provider_save' ) );
		add_action( 'admin_post_couple_space_ai_provider_default', array( 'CoupleSpace\\AiAdmin', 'handle_provider_default' ) );
		add_action( 'admin_post_couple_space_ai_provider_delete', array( 'CoupleSpace\\AiAdmin', 'handle_provider_delete' ) );
		add_action( 'admin_post_couple_space_ai_provider_test', array( 'CoupleSpace\\AiAdmin', 'handle_provider_test' ) );
		add_action( 'admin_post_couple_space_ai_chat', array( 'CoupleSpace\\AiAdmin', 'handle_chat_settings' ) );
	}

	/** 注册菜单。 */
	public static function menu() {
		add_menu_page(
			'情侣空间',
			'情侣空间',
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'page_overview' ),
			'dashicons-heart',
			58
		);

		add_submenu_page( self::SLUG, '总览', '总览', 'manage_options', self::SLUG, array( __CLASS__, 'page_overview' ) );
		add_submenu_page( self::SLUG, 'AI 设置', 'AI 设置', 'manage_options', self::SLUG . '-ai', array( __CLASS__, 'page_ai' ) );
		add_submenu_page( self::SLUG, 'AI 供应商', 'AI 供应商', 'manage_options', self::SLUG . '-ai-providers', array( 'CoupleSpace\\AiAdmin', 'page_providers' ) );
		add_submenu_page( self::SLUG, 'AI 聊天', 'AI 聊天', 'manage_options', self::SLUG . '-ai-chat', array( 'CoupleSpace\\AiAdmin', 'page_chat' ) );
		add_submenu_page( self::SLUG, '应用设置', '应用设置', 'manage_options', self::SLUG . '-app', array( __CLASS__, 'page_app' ) );
		add_submenu_page( self::SLUG, '用户管理', '用户管理', 'manage_options', self::SLUG . '-users', array( __CLASS__, 'page_users' ) );
		add_submenu_page( self::SLUG, '统计', '统计', 'manage_options', self::SLUG . '-stats', array( __CLASS__, 'page_stats' ) );
		add_submenu_page( self::SLUG, '操作日志', '操作日志', 'manage_options', self::SLUG . '-logs', array( __CLASS__, 'page_logs' ) );
		add_submenu_page( self::SLUG, 'App 后台密码', 'App 后台密码', 'manage_options', self::SLUG . '-security', array( __CLASS__, 'page_security' ) );
		add_submenu_page( self::SLUG, '服务器信息', '服务器信息', 'manage_options', self::SLUG . '-server', array( __CLASS__, 'page_server' ) );
	}

	// ------------------------------------------------------------------
	// 权限与小工具
	// ------------------------------------------------------------------

	/** 校验权限（所有页面与表单处理都必须先调用）。 */
	private static function guard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '你没有权限访问该页面。', '权限不足', array( 'response' => 403 ) );
		}
	}

	/**
	 * 渲染一个视图文件。
	 *
	 * @param string              $view 视图名（不含扩展名）。
	 * @param array<string,mixed> $vars 变量。
	 */
	private static function view( $view, array $vars = array() ) {
		$file = COUPLE_SPACE_DIR . 'admin/views/' . $view . '.php';
		if ( ! is_file( $file ) ) {
			echo '<div class="notice notice-error"><p>缺少视图文件：' . esc_html( $view ) . '</p></div>';
			return;
		}
		// phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- 视图变量由插件内部传入。
		extract( $vars, EXTR_SKIP );
		include $file;
	}

	/**
	 * 渲染视图（供 AiAdmin 等外部后台模块复用）。
	 *
	 * @param string              $view 视图名（不含扩展名）。
	 * @param array<string,mixed> $vars 变量。
	 */
	public static function view_public( $view, array $vars = array() ) {
		self::view( $view, $vars );
	}

	/**
	 * 表单处理完成后跳回来源页并带上提示。
	 *
	 * @param string $page    页面 slug 后缀（ai/app/users/server/空）。
	 * @param string $notice  提示内容。
	 * @param string $type    success / error / warning / info。
	 */
	private static function redirect( $page, $notice, $type = 'success' ) {
		$slug = ( '' === $page ) ? self::SLUG : self::SLUG . '-' . $page;
		$url  = add_query_arg(
			array(
				'page'            => $slug,
				'couple_notice'   => rawurlencode( $notice ),
				'couple_notice_t' => $type,
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * 跳回后台页并带提示（供 AiAdmin 等外部后台模块复用）。
	 *
	 * @param string $page   页面 slug 后缀。
	 * @param string $notice 提示内容。
	 * @param string $type   success / error / warning / info。
	 */
	public static function redirect_public( $page, $notice, $type = 'success' ) {
		self::redirect( $page, $notice, $type );
	}

	/** 渲染提示条。 */
	public static function render_notices() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- 只读展示提示。
		$notice = isset( $_GET['couple_notice'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['couple_notice'] ) ) ) : '';
		$type   = isset( $_GET['couple_notice_t'] ) ? sanitize_key( wp_unslash( $_GET['couple_notice_t'] ) ) : 'success';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( '' === $notice ) {
			return;
		}
		$allowed = array( 'success', 'error', 'warning', 'info' );
		if ( ! in_array( $type, $allowed, true ) ) {
			$type = 'success';
		}
		echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( $notice ) . '</p></div>';
	}

	/** 字节数转可读文本。 */
	public static function human_bytes( $bytes ) {
		$bytes = (float) $bytes;
		$units = array( 'B', 'KB', 'MB', 'GB', 'TB' );
		$i     = 0;
		while ( $bytes >= 1024 && $i < count( $units ) - 1 ) {
			$bytes /= 1024;
			$i++;
		}
		return ( $i === 0 ? (string) (int) $bytes : number_format( $bytes, 2 ) ) . ' ' . $units[ $i ];
	}

	/** 毫秒时间戳转站点本地时间文本。 */
	public static function dt( $ms ) {
		if ( empty( $ms ) ) {
			return '—';
		}
		return wp_date( 'Y-m-d H:i:s', (int) floor( (int) $ms / 1000 ), wp_timezone() );
	}

	// ------------------------------------------------------------------
	// 页面
	// ------------------------------------------------------------------

	/** 总览页。 */
	public static function page_overview() {
		self::guard();
		$urls      = Plugin::app_server_urls();
		$db_ok     = false;
		try {
			$db_ok = Db::ping_db();
		} catch ( \Throwable $e ) {
			$db_ok = false;
		}
		self::view(
			'overview',
			array(
				'urls'         => $urls,
				'db_ok'        => $db_ok,
				'password_src' => Settings::admin_password_source(),
				'in_database'  => Settings::admin_password_in_database(),
				'initial_pwd'  => (string) Config::option( 'initialAdminPassword', '' ),
				'stats'        => Db::get_stats(),
			)
		);
	}

	/** AI 设置页。 */
	public static function page_ai() {
		self::guard();
		self::view(
			'ai',
			array(
				'settings' => Settings::for_admin(),
			)
		);
	}

	/** 应用设置页。 */
	public static function page_app() {
		self::guard();
		self::view(
			'app',
			array(
				'settings' => Settings::for_admin(),
			)
		);
	}

	/** 用户管理页。 */
	public static function page_users() {
		self::guard();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- 只读筛选参数（列表无需 CSRF）。
		$query = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
		$page  = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$result = Db::list_users(
			array(
				'query'    => $query,
				'page'     => $page,
				'pageSize' => self::PER_PAGE,
			)
		);
		self::view(
			'users',
			array(
				'query'  => $query,
				'page'   => $page,
				'result' => $result,
				'total'  => (int) $result['total'],
			)
		);
	}

	/** 统计页。 */
	public static function page_stats() {
		self::guard();
		$disk = Db::get_disk_usage();
		self::view(
			'stats',
			array(
				'stats' => Db::get_stats(),
				'disk'  => $disk,
			)
		);
	}

	/** 操作日志页。 */
	public static function page_logs() {
		self::guard();
		self::view(
			'logs',
			array(
				'logs' => Db::list_admin_logs( 200 ),
			)
		);
	}

	/** App 后台密码（/admin/api/login）管理页。 */
	public static function page_security() {
		self::guard();
		self::view(
			'security',
			array(
				'password_src' => Settings::admin_password_source(),
				'in_database'  => Settings::admin_password_in_database(),
				'initial_pwd'  => (string) Config::option( 'initialAdminPassword', '' ),
				'jwt_source'   => Config::jwt_secret_source(),
			)
		);
	}

	/** 服务器信息页。 */
	public static function page_server() {
		self::guard();
		$tables  = array();
		foreach ( Db::TABLES as $name ) {
			$real     = Db::table( $name );
			$tables[] = array(
				'name'    => $real,
				'exists'  => Db::table_exists( $real ),
				'columns' => count( Db::table_columns( $real ) ),
				'rows'    => Db::table_exists( $real ) ? (int) Db::scalar( 'SELECT COUNT(*) FROM `' . $real . '`' ) : 0,
			);
		}

		$db_ok = false;
		try {
			$db_ok = Db::ping_db();
		} catch ( \Throwable $e ) {
			$db_ok = false;
		}

		self::view(
			'server',
			array(
				'tables'    => $tables,
				'db_ok'     => $db_ok,
				'emoji'     => EmojiStore::status(),
				'urls'      => Plugin::app_server_urls(),
				'schema'    => (int) Db::get_setting( 'schema_version' ),
				'tables_ok' => Db::has_core_tables(),
			)
		);
	}

	// ------------------------------------------------------------------
	// 表单处理
	// ------------------------------------------------------------------

	/** AI 设置保存。 */
	public static function handle_settings() {
		self::guard();
		check_admin_referer( 'couple_space_settings' );

		$fields = array(
			'aiEnabled'       => isset( $_POST['aiEnabled'] ) ? ( '1' === (string) wp_unslash( $_POST['aiEnabled'] ) ) : false,
			'deepseekBaseUrl' => isset( $_POST['deepseekBaseUrl'] ) ? esc_url_raw( trim( (string) wp_unslash( $_POST['deepseekBaseUrl'] ) ) ) : '',
			'deepseekModel'   => isset( $_POST['deepseekModel'] ) ? sanitize_text_field( wp_unslash( $_POST['deepseekModel'] ) ) : '',
			'aiDailyLimit'    => isset( $_POST['aiDailyLimit'] ) ? max( 0, (int) $_POST['aiDailyLimit'] ) : 0,
		);

		// Key 单独处理：留空表示「不修改」，勾选 clearDeepseekApiKey 表示清空。
		$key_raw = isset( $_POST['deepseekApiKey'] ) ? trim( (string) wp_unslash( $_POST['deepseekApiKey'] ) ) : '';
		$clear   = ! empty( $_POST['clearDeepseekApiKey'] );
		if ( $clear ) {
			$fields['deepseekApiKey'] = '';
		} elseif ( '' !== $key_raw ) {
			$fields['deepseekApiKey'] = $key_raw;
		}

		$settings = Settings::update( $fields );
		Auth::log_admin_action( 'wp.settings.update', 'AI 设置：' . implode( '、', array_keys( $fields ) ) );
		self::redirect( 'ai', 'AI 设置已保存。当前 Key：' . ( $settings['deepseekApiKeyMasked'] ? $settings['deepseekApiKeyMasked'] : '未配置' ) );
	}

	/** 应用设置保存。 */
	public static function handle_app_settings() {
		self::guard();
		check_admin_referer( 'couple_space_app_settings' );

		$announcement = isset( $_POST['announcement'] ) ? wp_kses_post( (string) wp_unslash( $_POST['announcement'] ) ) : '';
		$update_url   = isset( $_POST['updateURL'] ) ? esc_url_raw( trim( (string) wp_unslash( $_POST['updateURL'] ) ) ) : '';

		$fields = array(
			'registerOpen'      => ! empty( $_POST['registerOpen'] ),
			'announcement'      => $announcement,
			'minAppBuild'       => isset( $_POST['minAppBuild'] ) ? max( 0, (int) $_POST['minAppBuild'] ) : 1,
			'latestAppBuild'    => isset( $_POST['latestAppBuild'] ) ? max( 0, (int) $_POST['latestAppBuild'] ) : 1,
			'updateURL'         => $update_url,
			'maxEmojiPacks'     => isset( $_POST['maxEmojiPacks'] ) ? max( 1, (int) $_POST['maxEmojiPacks'] ) : 30,
			'maxMessagesPerDay' => isset( $_POST['maxMessagesPerDay'] ) ? max( 0, (int) $_POST['maxMessagesPerDay'] ) : 3000,
		);

		Settings::update( $fields );

		// 信任代理头（用于限流/日志里的真实 IP）与自定义 JWT 密钥走选项层。
		$option_patch = array( 'trustProxy' => ! empty( $_POST['trustProxy'] ) );
		Config::update_options( $option_patch );

		Auth::log_admin_action( 'wp.settings.update', '应用设置：' . implode( '、', array_keys( $fields ) ) );
		self::redirect( 'app', '应用设置已保存。' );
	}

	/** 后台密码修改 / 重置。 */
	public static function handle_admin_password() {
		self::guard();
		check_admin_referer( 'couple_space_admin_password' );

		$mode     = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'change';
		$new      = isset( $_POST['newPassword'] ) ? (string) wp_unslash( $_POST['newPassword'] ) : '';
		$new2     = isset( $_POST['newPassword2'] ) ? (string) wp_unslash( $_POST['newPassword2'] ) : '';

		if ( strlen( $new ) < Config::limit( 'PASSWORD_MIN' ) || strlen( $new ) > Config::limit( 'PASSWORD_MAX' ) ) {
			self::redirect( 'server', '新密码长度必须是 6-64 位。', 'error' );
		}
		if ( $new !== $new2 ) {
			self::redirect( 'server', '两次输入的新密码不一致。', 'error' );
		}

		if ( 'reset' === $mode ) {
			// 忘记密码的救援路径：直接覆盖（仍然要求 manage_options 权限 + nonce）。
			Settings::set_admin_password_hash( Auth::hash_admin_password( $new ) );
			Auth::log_admin_action( 'wp.admin_password.reset', '通过 WordPress 后台直接重置 App 后台密码' );
			self::redirect( 'server', 'App 后台密码已重置（bcrypt 哈希已写入数据库）。' );
		}

		$current = isset( $_POST['currentPassword'] ) ? (string) wp_unslash( $_POST['currentPassword'] ) : '';
		if ( ! Auth::verify_admin_password( $current ) ) {
			Auth::log_admin_action( 'wp.admin_password.change_failed', '当前密码错误' );
			self::redirect( 'server', '当前后台密码错误。', 'error' );
		}
		if ( $current === $new ) {
			self::redirect( 'server', '新密码不能与当前密码相同。', 'error' );
		}

		Settings::set_admin_password_hash( Auth::hash_admin_password( $new ) );
		Auth::log_admin_action( 'wp.admin_password.changed', 'App 后台密码已修改' );
		self::redirect( 'server', 'App 后台密码已修改。' );
	}

	/** 用户管理动作。 */
	public static function handle_user_action() {
		self::guard();
		check_admin_referer( 'couple_space_user_action' );

		$action = isset( $_POST['user_action'] ) ? sanitize_key( wp_unslash( $_POST['user_action'] ) ) : '';
		$id     = isset( $_POST['user_id'] ) ? sanitize_text_field( wp_unslash( $_POST['user_id'] ) ) : '';
		$page   = isset( $_POST['paged'] ) ? max( 1, (int) $_POST['paged'] ) : 1;
		$query  = isset( $_POST['q'] ) ? sanitize_text_field( wp_unslash( $_POST['q'] ) ) : '';
		$back   = 'users';
		if ( '' !== $query || $page > 1 ) {
			$back = 'users';
		}

		$found = Db::get_user_by_id( $id );
		if ( ! $found ) {
			self::redirect( $back, '用户不存在。', 'error' );
		}

		switch ( $action ) {
			case 'reset_password':
				$new = isset( $_POST['newPassword'] ) ? (string) wp_unslash( $_POST['newPassword'] ) : '';
				if ( strlen( $new ) < Config::limit( 'PASSWORD_MIN' ) || strlen( $new ) > Config::limit( 'PASSWORD_MAX' ) ) {
					self::redirect( $back, '新密码长度必须是 6-64 位。', 'error' );
				}
				Db::update_user_password( $id, password_hash( $new, PASSWORD_BCRYPT, array( 'cost' => Auth::BCRYPT_ROUNDS ) ) );
				Auth::log_admin_action( 'wp.user.reset_password', '用户 ' . $found['user']['username'] . '（' . $id . '）' );
				self::redirect( $back, '已重置用户 ' . $found['user']['username'] . ' 的密码。' );
				break;

			case 'toggle_disabled':
				$disabled = ! empty( $_POST['disabled'] );
				Db::set_user_disabled( $id, $disabled );
				Auth::log_admin_action( 'wp.user.toggle_disabled', '用户 ' . $found['user']['username'] . ' -> ' . ( $disabled ? '禁用' : '启用' ) );
				self::redirect( $back, '用户 ' . $found['user']['username'] . ' 已' . ( $disabled ? '禁用' : '启用' ) . '。' );
				break;

			case 'delete':
				Db::delete_user( $id );
				Auth::log_admin_action( 'wp.user.delete', '用户 ' . $found['user']['username'] . '（' . $id . '）' );
				self::redirect( $back, '用户 ' . $found['user']['username'] . ' 已删除。' );
				break;

			default:
				self::redirect( $back, '未知操作。', 'error' );
		}
	}

	/** AI 测试按钮。 */
	public static function handle_ai_test() {
		self::guard();
		check_admin_referer( 'couple_space_ai_test' );

		$text = isset( $_POST['text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['text'] ) ) : '';
		if ( '' === trim( $text ) ) {
			$text = '今天午饭25，打车18块5';
		}

		try {
			if ( ! Settings::is_ai_configured() ) {
				self::redirect( 'ai', 'AI 未配置：请先填写 DeepSeek API Key 并保存。', 'error' );
			}
			$result = DeepSeek::parse_ledger(
				array(
					'text'     => $text,
					'currency' => 'CNY',
					'today'    => wp_date( 'Y-m-d', null, wp_timezone() ),
				)
			);
		} catch ( AiError $e ) {
			Auth::log_admin_action( 'wp.ai.test.failed', $e->getMessage() );
			self::redirect( 'ai', 'AI 测试失败：' . $e->getMessage(), 'error' );
			return;
		} catch ( \Throwable $e ) {
			Auth::log_admin_action( 'wp.ai.test.failed', $e->getMessage() );
			self::redirect( 'ai', 'AI 测试失败：' . $e->getMessage(), 'error' );
			return;
		}

		Auth::log_admin_action( 'wp.ai.test', '测试成功，识别 ' . count( $result['items'] ) . ' 条' );
		self::redirect( 'ai', 'AI 测试成功（' . $result['model'] . '）：' . $result['summary'] );
	}

	/** 修改 JWT 密钥 / 初始后台密码。 */
	public static function handle_secret() {
		self::guard();
		check_admin_referer( 'couple_space_secret' );

		$patch = array();
		if ( isset( $_POST['regenerateJwtSecret'] ) ) {
			$patch['jwtSecret'] = Ids::random_secret( 32 );
		}
		$initial = isset( $_POST['initialAdminPassword'] ) ? (string) wp_unslash( $_POST['initialAdminPassword'] ) : '';
		if ( '' !== $initial ) {
			if ( strlen( $initial ) < Config::limit( 'PASSWORD_MIN' ) || strlen( $initial ) > Config::limit( 'PASSWORD_MAX' ) ) {
				self::redirect( 'server', '初始密码长度必须是 6-64 位。', 'error' );
			}
			$patch['initialAdminPassword'] = $initial;
		}
		if ( 0 === count( $patch ) ) {
			self::redirect( 'server', '没有需要修改的内容。', 'warning' );
		}

		Config::update_options( $patch );
		Auth::log_admin_action( 'wp.secret.update', '更新：' . implode( '、', array_keys( $patch ) ) );
		self::redirect( 'server', '已更新：' . implode( '、', array_keys( $patch ) ) );
	}
}
