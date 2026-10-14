<?php
/**
 * 常量与配置：业务限制值、同步 kind 白名单、默认设置、密钥来源。
 *
 * 移植自 server-php/src/Config.php，但不再需要 config.php：
 *   - 数据库连接直接复用 WordPress 的 DB_NAME / DB_USER / DB_PASSWORD / DB_HOST
 *   - 可配置项存在 WordPress 选项 COUPLE_SPACE_OPTION（单个数组选项）
 *   - JWT 签名密钥默认由 WP 的 AUTH_KEY 派生（可用 WP-CLI 设置独立密钥）
 *
 * @package CoupleSpace
 */

namespace CoupleSpace;

defined( 'ABSPATH' ) || exit;

/**
 * 配置中心。
 */
class Config {

	/** 用户 token 有效期：30 天（秒）。 */
	const JWT_EXPIRES_IN_SECONDS = 2592000;

	/** 后台 token 有效期：12 小时（秒）。 */
	const ADMIN_TOKEN_TTL_SECONDS = 43200;

	/** 当前数据库结构版本（写在自建 settings 表的 schema_version）。 */
	const SCHEMA_VERSION = 3;

	/** 同步 kind 白名单（API.md §4）。ledger / vault 明确拒绝。 */
	const SYNC_KINDS = array( 'spark', 'pet', 'checkin', 'wish', 'worklog', 'settings' );

	/** 明确拒绝且需要给出解释的 kind（只存手机本地）。 */
	const SYNC_REJECTED_KINDS = array( 'ledger', 'vault' );

	/** 表情 mime 白名单（API.md §6）。 */
	const EMOJI_MIMES = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' );

	/** 消息 kind 白名单（API.md §5）。 */
	const MESSAGE_KINDS = array( 'text', 'emoji', 'image', 'system' );

	/** 客户端可主动发送的 kind（system 由服务端产生）。 */
	const CLIENT_MESSAGE_KINDS = array( 'text', 'emoji', 'image' );

	/** 业务限制值（与 docs/API.md 严格一致）。 */
	const LIMITS = array(
		'USERNAME_MIN'             => 3,
		'USERNAME_MAX'             => 32,
		'PASSWORD_MIN'             => 6,
		'PASSWORD_MAX'             => 64,
		'SYNC_MAX_OBJECTS'         => 200,      // §4 单次最多 200 个对象。
		'SYNC_PULL_DEFAULT'        => 500,      // §4 limit 默认 500。
		'SYNC_PULL_MAX'            => 1000,     // §4 limit 上限 1000。
		'CHAT_BODY_MAX'            => 2000,     // §5 body 长度上限 2000 字符。
		'CHAT_PAGE_DEFAULT'        => 50,       // §5 默认 50 条。
		'CHAT_RECALL_WINDOW_MS'    => 86400000, // §5 撤回 24 小时内。
		'COUPLE_MAX_MEMBERS'       => 2,        // §3 情侣空间 2 人。
		'COUPLE_RETENTION_DAYS'    => 30,       // §3 解绑后数据保留 30 天。
		'EMOJI_MAX_PACKS'          => 30,       // §6 每个情侣空间最多 30 个自建包。
		'EMOJI_MAX_ITEMS_PER_PACK' => 200,      // §6 每个包上限 200 张。
		'EMOJI_MAX_BYTES'          => 1048576,  // §6 解码后单张上限 1MB。
		'AI_TEXT_MAX'              => 2000,
		'AI_DAILY_LIMIT_DEFAULT'   => 200,
		'AI_MAX_ITEMS'             => 200,
		// AI 聊天扩展（docs/API-AI聊天.md）。
		'AI_PARTNER_MAX'           => 10,       // §AI-2 每个用户最多 10 个 AI 伙伴。
		'AI_PARTNER_NAME_MAX'      => 20,       // §AI-2 name 1-20 字。
		'AI_PARTNER_PERSONA_MAX'   => 2000,     // §AI-2 persona 0-2000 字。
		'AI_PROVIDER_MODEL_MAX'    => 64,       // §AI-2 model 长度 <= 64。
		'AI_CHAT_CONTEXT_MAX'      => 20,       // §AI-4 最近 20 条上下文。
		'AI_CHAT_LIMIT_DEFAULT'    => 50,       // §AI-3 limit 默认 50。
		'AI_CHAT_LIMIT_MAX'        => 100,      // §AI-3 limit 最大 100。
		'AI_CHAT_RATE_LIMIT'       => 20,       // §AI-4 同一用户 1 分钟最多 20 次。
		'AI_CHAT_RATE_WINDOW_MS'   => 60000,    // §AI-4 限流窗口 1 分钟。
		'AI_CHAT_REPLY_MAX'        => 4000,     // AI 回复落库长度上限（防御上游超长输出）。
		'AI_UPSTREAM_TIMEOUT'      => 30,       // §AI-4 供应商超时阈值 30 秒 -> 504。
		'AI_UPSTREAM_ERROR_MAX'    => 200,      // §AI-4 上游错误摘要 <= 200 字。
		'AI_EMPTY_REPLY_TEXT'      => '（AI 没有返回内容）',
		'JSON_FIELD_MAX_BYTES'     => 262144,   // payload / meta 上限 256KB。
		'BODY_MAX_BYTES'           => 4194304,  // 请求体上限 4MB（表情走 base64）。
	);

	/**
	 * 首次启用时写入自建 settings 表的默认值（与 server-php 完全一致）。
	 */
	const DEFAULTS = array(
		'aiEnabled'          => true,
		'aiDailyLimit'       => 200,
		'aiChatEnabled'      => true,
		'aiProviderId'       => '',
		'aiProviderModel'    => '',
		'registerOpen'       => true,
		'deepseekBaseUrl'    => 'https://api.deepseek.com',
		'deepseekModel'      => 'deepseek-chat',
		'announcement'       => '',
		'minAppBuild'        => 1,
		'latestAppBuild'     => 1,
		'updateURL'          => '',
		'maxEmojiPacks'      => 30,
		'maxMessagesPerDay'  => 3000,
		'deepseekApiKey'     => '',
	);

	/**
	 * 插件选项的默认值（WordPress 选项层面的配置）。
	 *
	 * 注意：deepseekApiKey **不在这里** —— 它必须经过 AES-256-GCM 加密后存进自建
	 * settings 表（见 Settings::update），放进 WordPress 选项就等于明文落库。
	 */
	const OPTION_DEFAULTS = array(
		'aiEnabled'          => true,
		'aiDailyLimit'       => 200,
		'aiChatEnabled'      => true,
		'aiProviderId'       => '',
		'aiProviderModel'    => '',
		'registerOpen'       => true,
		'deepseekBaseUrl'    => 'https://api.deepseek.com',
		'deepseekModel'      => 'deepseek-chat',
		'announcement'       => '',
		'minAppBuild'        => 1,
		'latestAppBuild'     => 1,
		'updateURL'          => '',
		'maxEmojiPacks'      => 30,
		'maxMessagesPerDay'  => 3000,
		'initialAdminPassword' => '',
		'jwtSecret'          => '',
		'adminJwtSecret'     => '',
		'trustProxy'         => true,
	);

	/** @var array<string,mixed>|null 选项缓存。 */
	private static $option = null;

	/** @var string|null jwt_secret 缓存。 */
	private static $jwt_secret = null;

	/**
	 * 读取限制值。
	 *
	 * @param string $key LIMITS 的键。
	 * @return int
	 */
	public static function limit( $key ) {
		return isset( self::LIMITS[ $key ] ) ? self::LIMITS[ $key ] : 0;
	}

	/**
	 * 读取插件选项（单个数组选项，缺失的键回落到默认值）。
	 *
	 * @return array<string,mixed>
	 */
	public static function options() {
		if ( null !== self::$option ) {
			return self::$option;
		}
		$raw = get_option( COUPLE_SPACE_OPTION, array() );
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}
		// 默认值兜底 + 保留未知键（例如旧/新版本多出来的配置项），避免丢配置。
		$out = array_merge( self::OPTION_DEFAULTS, $raw );
		self::$option = $out;
		return self::$option;
	}

	/**
	 * 读取单个选项。
	 *
	 * @param string $key     键。
	 * @param mixed  $default 默认值。
	 * @return mixed
	 */
	public static function option( $key, $default = null ) {
		$all = self::options();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * 写入若干选项（合并，不会丢其它键）。
	 *
	 * @param array<string,mixed> $patch 要写入的键值。
	 * @return array<string,mixed> 写入后的完整选项。
	 */
	public static function update_options( array $patch ) {
		$all = self::options();
		foreach ( $patch as $k => $v ) {
			$all[ $k ] = $v;
		}
		update_option( COUPLE_SPACE_OPTION, $all, false );
		self::$option = $all;
		return $all;
	}

	/** 清空选项缓存。 */
	public static function flush() {
		self::$option      = null;
		self::$jwt_secret  = null;
	}

	/** 确保选项行存在（首次启用时落库默认值）。 */
	public static function ensure_options() {
		$raw = get_option( COUPLE_SPACE_OPTION, null );
		if ( ! is_array( $raw ) ) {
			$defaults = self::OPTION_DEFAULTS;
			if ( '' === $defaults['initialAdminPassword'] ) {
				// 首次启用时给一个随机初始后台密码，并把明文放进选项供用户查看/修改。
				$defaults['initialAdminPassword'] = self::random_password();
			}
			add_option( COUPLE_SPACE_OPTION, $defaults, '', false );
			self::flush();
			return;
		}
		$merged = $raw;
		$dirty  = false;
		foreach ( self::OPTION_DEFAULTS as $k => $v ) {
			if ( ! array_key_exists( $k, $merged ) ) {
				$merged[ $k ] = $v;
				$dirty        = true;
			}
		}
		if ( $dirty ) {
			update_option( COUPLE_SPACE_OPTION, $merged, false );
			self::flush();
		}
	}

	/** @return string 生成一个 12 位易读随机密码（去掉易混淆字符）。 */
	public static function random_password() {
		$chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
		$out   = '';
		$max   = strlen( $chars ) - 1;
		for ( $i = 0; $i < 12; $i++ ) {
			$out .= $chars[ random_int( 0, $max ) ];
		}
		return $out;
	}

	// ------------------------------------------------------------------
	// 密钥
	// ------------------------------------------------------------------

	/**
	 * 用户 token 签名密钥。
	 *
	 * 优先级：选项里的 jwtSecret > WordPress 的 AUTH_KEY 派生值。
	 * 这样「装完就能用」，同时允许有更高安全要求的用户换独立密钥。
	 *
	 * @return string
	 */
	public static function jwt_secret() {
		if ( null !== self::$jwt_secret ) {
			return self::$jwt_secret;
		}
		$custom = (string) self::option( 'jwtSecret', '' );
		if ( '' !== $custom ) {
			self::$jwt_secret = $custom;
			return self::$jwt_secret;
		}
		$auth = defined( 'AUTH_KEY' ) ? (string) AUTH_KEY : '';
		if ( strlen( $auth ) < 16 ) {
			// 极端情况：wp-config.php 里的 AUTH_KEY 被清空。用站点 URL + 数据库名兜底，
			// 保证 token 仍然不可预测性足够（但仍然建议用 WP-CLI 设置独立密钥）。
			$auth = 'couple-space:' . home_url() . ':' . ( defined( 'DB_NAME' ) ? DB_NAME : '' ) . ':'
				. ( defined( 'NONCE_SALT' ) ? (string) NONCE_SALT : '' );
		}
		self::$jwt_secret = hash( 'sha256', 'couple-space:jwt:' . $auth );
		return self::$jwt_secret;
	}

	/**
	 * 后台 token 签名密钥（可单独设置，缺省回退到用户密钥）。
	 *
	 * @return string
	 */
	public static function admin_jwt_secret() {
		$custom = (string) self::option( 'adminJwtSecret', '' );
		if ( '' !== $custom ) {
			return $custom;
		}
		return self::jwt_secret();
	}

	/** 签名密钥是否来自独立的选项（否则是 AUTH_KEY 派生）。 */
	public static function jwt_secret_source() {
		$custom = (string) self::option( 'jwtSecret', '' );
		if ( '' !== $custom ) {
			return 'option';
		}
		$auth = defined( 'AUTH_KEY' ) ? (string) AUTH_KEY : '';
		return strlen( $auth ) < 16 ? 'fallback' : 'wp-config';
	}

	// ------------------------------------------------------------------
	// 数据库 / 目录（全部来自 WordPress）
	// ------------------------------------------------------------------

	/** @return string 数据库主机描述（不含密码，后台/日志展示用）。 */
	public static function db_label() {
		global $wpdb;
		return (string) $wpdb->dbhost . '/' . (string) $wpdb->dbname;
	}

	/** @return string 数据库字符集。 */
	public static function db_charset() {
		return defined( 'DB_CHARSET' ) && DB_CHARSET ? (string) DB_CHARSET : 'utf8mb4';
	}

	/** @return string 表情图片存放目录（wp-content/uploads/couple-emoji）。 */
	public static function emoji_dir() {
		return EmojiStore::base_dir();
	}

	/** @return string 插件目录下的内置公共表情包目录（public/emoji）。 */
	public static function public_emoji_dir() {
		return COUPLE_SPACE_DIR . 'public/emoji';
	}

	/** @return string 插件目录下的公共资源目录（public）。 */
	public static function public_dir() {
		return COUPLE_SPACE_DIR . 'public';
	}

	/** @return string 日志文件路径（wp-content/uploads/couple-emoji/logs/app.log）。 */
	public static function log_file() {
		return EmojiStore::base_dir() . '/logs/app.log';
	}

	/** @return string 表情文件的公开访问前缀（/api/emoji/blob）。 */
	public static function emoji_url_prefix() {
		$base = rtrim( (string) self::option( 'publicBaseUrl', '' ), '/' );
		return $base . '/api/emoji/blob/';
	}

	/** 确保必要目录存在。 */
	public static function ensure_dirs() {
		EmojiStore::ensure_dirs();
		$log_dir = dirname( self::log_file() );
		if ( ! is_dir( $log_dir ) ) {
			wp_mkdir_p( $log_dir );
		}
		if ( ! is_dir( self::public_emoji_dir() ) ) {
			wp_mkdir_p( self::public_emoji_dir() );
		}
	}
}
