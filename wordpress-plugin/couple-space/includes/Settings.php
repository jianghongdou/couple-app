<?php
/**
 * 自建 settings 表读写服务。
 *
 * 移植自 server-php/src/Settings.php，安全要点完全保留：
 *   1. DeepSeek API Key 落库前用 AES-256-GCM 加密（密钥由 jwt_secret 派生），
 *      即使数据库泄漏也不会直接暴露明文 Key。
 *   2. 对外（后台页面 / 后台接口）一律只返回掩码，绝不返回明文。
 *   3. 后台管理员密码以 bcrypt 哈希存在自建 settings.adminPasswordHash，
 *      数据库里没有哈希时回退到 WordPress 选项里的初始密码。
 *
 * @package CoupleSpace
 */

namespace CoupleSpace;

defined( 'ABSPATH' ) || exit;

/**
 * 设置服务。
 */
class Settings {

	/** 加密标记前缀，便于识别历史明文值（兼容旧数据）。 */
	const ENC_PREFIX = 'enc:v1:';

	/** 后台管理员密码哈希在自建 settings 表中的键。 */
	const ADMIN_PASSWORD_HASH_KEY = 'adminPasswordHash';

	/** 绝不进入 read_all() 的敏感键。 */
	const INTERNAL_KEYS = array( 'adminPasswordHash' );

	/** @var array<string,mixed>|null 请求内缓存。 */
	private static $cache = null;

	/**
	 * 由 jwt_secret 派生 32 字节加密密钥。
	 *
	 * @return string
	 */
	private static function derive_key() {
		return hash( 'sha256', 'couple-server:settings:' . Config::jwt_secret(), true );
	}

	/**
	 * 加密字符串（AES-256-GCM）。
	 * 输出格式： enc:v1:<iv_base64>:<tag_base64>:<cipher_base64>
	 *
	 * @param string $plain 明文。
	 * @return string
	 */
	public static function encrypt_secret( $plain ) {
		if ( ! is_string( $plain ) || '' === $plain ) {
			return '';
		}
		$iv     = random_bytes( 12 );
		$tag    = '';
		$cipher = openssl_encrypt( $plain, 'aes-256-gcm', self::derive_key(), OPENSSL_RAW_DATA, $iv, $tag );
		if ( false === $cipher ) {
			throw new \RuntimeException( 'DeepSeek Key 加密失败' );
		}
		return self::ENC_PREFIX . base64_encode( $iv ) . ':' . base64_encode( $tag ) . ':' . base64_encode( $cipher );
	}

	/**
	 * 解密字符串；非加密格式（历史明文）原样返回。
	 *
	 * @param mixed $value 加密值。
	 * @return string
	 */
	public static function decrypt_secret( $value ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return '';
		}
		if ( 0 !== strpos( $value, self::ENC_PREFIX ) ) {
			return $value; // 兼容历史明文。
		}
		$rest  = substr( $value, strlen( self::ENC_PREFIX ) );
		$parts = explode( ':', $rest );
		if ( 3 !== count( $parts ) ) {
			return '';
		}
		$iv   = base64_decode( $parts[0], true );
		$tag  = base64_decode( $parts[1], true );
		$data = base64_decode( $parts[2], true );
		if ( false === $iv || false === $tag || false === $data ) {
			return '';
		}
		$plain = openssl_decrypt( $data, 'aes-256-gcm', self::derive_key(), OPENSSL_RAW_DATA, $iv, $tag );
		if ( false === $plain ) {
			Log::warn( 'DeepSeek Key 解密失败（可能是 jwt_secret 已变更）' );
			return '';
		}
		return $plain;
	}

	/**
	 * 生成掩码：只保留首尾少量字符，中间用星号。
	 * 例：sk-1234567890abcd -> sk-****abcd
	 *
	 * @param string $plain 明文。
	 * @return string
	 */
	public static function mask_secret( $plain ) {
		if ( ! is_string( $plain ) || '' === $plain ) {
			return '';
		}
		$len = strlen( $plain );
		if ( $len <= 4 ) {
			return str_repeat( '*', $len );
		}
		if ( $len <= 8 ) {
			return substr( $plain, 0, 2 ) . str_repeat( '*', $len - 4 ) . substr( $plain, -2 );
		}
		$head  = ( 0 === strpos( $plain, 'sk-' ) ) ? 'sk-' : substr( $plain, 0, 3 );
		$tail  = substr( $plain, -4 );
		$stars = str_repeat( '*', max( 4, min( 12, $len - strlen( $head ) - 4 ) ) );
		return $head . $stars . $tail;
	}

	/** 对外暴露的设置字段（与 API.md §9 的 GET /admin/api/settings 响应一致）。 */
	const PUBLIC_KEYS = array(
		'deepseekBaseUrl',
		'deepseekModel',
		'aiEnabled',
		'aiDailyLimit',
		'aiChatEnabled',
		'aiProviderId',
		'aiProviderModel',
		'registerOpen',
		'announcement',
		'minAppBuild',
		'latestAppBuild',
		'updateURL',
		'maxEmojiPacks',
		'maxMessagesPerDay',
	);

	/**
	 * 读取全部设置（含解密后的明文 Key，仅供服务端内部使用）。
	 *
	 * @return array<string,mixed>
	 */
	public static function read_all() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}
		// WordPress 选项里的同名值作为初始值（激活时从选项种子写入自建表）。
		$out = Config::DEFAULTS;
		foreach ( Config::options() as $k => $v ) {
			if ( array_key_exists( $k, $out ) ) {
				$out[ $k ] = $v;
			}
		}

		$raw = Db::get_all_settings();
		foreach ( $raw as $k => $v ) {
			if ( in_array( $k, self::INTERNAL_KEYS, true ) ) {
				continue; // 后台密码哈希等敏感键不进 read_all()。
			}
			$out[ $k ] = $v;
		}
		$out['deepseekApiKey'] = self::decrypt_secret( isset( $raw['deepseekApiKey'] ) ? $raw['deepseekApiKey'] : '' );
		self::$cache             = $out;
		return self::$cache;
	}

	/** 清空请求内缓存（写入后调用）。 */
	public static function flush() {
		self::$cache = null;
	}

	/**
	 * 后台接口用的设置视图：Key 只返回掩码。
	 *
	 * @return array<string,mixed>
	 */
	public static function for_admin() {
		$s    = self::read_all();
		$view = array(
			'deepseekApiKeyMasked'     => self::mask_secret( $s['deepseekApiKey'] ),
			'deepseekApiKeyConfigured' => '' !== $s['deepseekApiKey'],
			// 后台密码只暴露「存在哪」，绝不返回哈希本身。
			'adminPasswordInDatabase'  => self::admin_password_in_database(),
			'adminPasswordSource'      => self::admin_password_source(),
		);
		foreach ( self::PUBLIC_KEYS as $key ) {
			$view[ $key ] = array_key_exists( $key, $s ) ? $s[ $key ] : null;
		}
		return $view;
	}

	// ------------------------------------------------------------------
	// 后台管理员密码
	// ------------------------------------------------------------------

	/**
	 * 读取后台密码哈希（数据库里没有则返回空字符串）。
	 * 绕开 read_all() 的缓存与视图，保证改完密码立刻生效。
	 *
	 * @return string
	 */
	public static function admin_password_hash() {
		try {
			$v = Db::get_setting( self::ADMIN_PASSWORD_HASH_KEY );
		} catch ( \Throwable $e ) {
			Log::warn( '读取后台密码哈希失败', array( 'error' => $e->getMessage() ) );
			return '';
		}
		return is_string( $v ) ? trim( $v ) : '';
	}

	/** @return bool 后台密码是否已存于数据库。 */
	public static function admin_password_in_database() {
		return '' !== self::admin_password_hash();
	}

	/** @return string 后台密码来源：database（数据库哈希）/ option（选项里的初始密码）。 */
	public static function admin_password_source() {
		return self::admin_password_in_database() ? 'database' : 'option';
	}

	/**
	 * 写入后台密码哈希（bcrypt）。
	 *
	 * @param string $hash password_hash() 的产物。
	 */
	public static function set_admin_password_hash( $hash ) {
		if ( ! is_string( $hash ) || '' === $hash ) {
			throw new \RuntimeException( '后台密码哈希不能为空' );
		}
		Db::set_setting( self::ADMIN_PASSWORD_HASH_KEY, $hash );
		self::flush();
	}

	/**
	 * 删除后台密码哈希 —— 后台密码回到 WordPress 选项里的初始密码（忘记密码的救援路径）。
	 */
	public static function clear_admin_password_hash() {
		Db::delete_setting( self::ADMIN_PASSWORD_HASH_KEY );
		self::flush();
	}

	/** 后台密码哈希在自建 settings 表里的长度下限（bcrypt 一律 60 位）。 */
	const ADMIN_HASH_MIN_LENGTH = 20;

	/**
	 * 确保自建 settings 表里有可用的后台密码哈希。
	 *
	 * 场景（插件首次启用、或数据库里的哈希被清掉、或表被重建）：
	 *   选项 initialAdminPassword 存在  -> 存它的 bcrypt 哈希
	 *   选项不存在                      -> 生成一个随机初始密码写进选项，再存它的哈希
	 *
	 * 幂等：已经有可用哈希时什么都不做（绝不覆盖用户改过的密码）。
	 *
	 * @return bool 本次是否写入了新的哈希。
	 */
	public static function ensure_admin_password_seeded() {
		$existing = self::admin_password_hash();
		if ( strlen( $existing ) >= self::ADMIN_HASH_MIN_LENGTH ) {
			return false;
		}

		// 数据库里放着一个明显不是 bcrypt 哈希的脏值，先清掉。
		if ( '' !== $existing ) {
			self::clear_admin_password_hash();
		}

		$initial = (string) Config::option( 'initialAdminPassword', '' );
		if ( strlen( $initial ) < Config::limit( 'PASSWORD_MIN' ) ) {
			// 选项里没有（或太短）→ 生成一个随机初始密码并写回选项，供用户在后台查看。
			$initial = Config::random_password();
			Config::update_options( array( 'initialAdminPassword' => $initial ) );
		}

		self::set_admin_password_hash( Auth::hash_admin_password( $initial ) );
		Log::info( '已写入初始后台密码哈希（可在插件后台或 WP-CLI 里修改）' );
		return true;
	}

	/** 允许通过后台修改的字段及其类型。 */
	const WRITABLE = array(
		'deepseekApiKey'    => 'string',
		'deepseekBaseUrl'   => 'string',
		'deepseekModel'     => 'string',
		'aiEnabled'         => 'boolean',
		'aiDailyLimit'      => 'int',
		'aiChatEnabled'     => 'boolean',
		'aiProviderId'      => 'string',
		'aiProviderModel'   => 'string',
		'registerOpen'      => 'boolean',
		'announcement'      => 'string',
		'minAppBuild'       => 'int',
		'latestAppBuild'    => 'int',
		'updateURL'         => 'string',
		'maxEmojiPacks'     => 'int',
		'maxMessagesPerDay' => 'int',
	);

	/**
	 * 更新设置（只处理传入的字段），返回更新后的后台视图。
	 *
	 * @param array<string,mixed> $patch 要修改的字段。
	 * @return array<string,mixed>
	 */
	public static function update( array $patch ) {
		$entries = array();
		foreach ( $patch as $key => $value ) {
			if ( ! isset( self::WRITABLE[ $key ] ) ) {
				continue; // 忽略未知字段，避免脏数据。
			}
			$type = self::WRITABLE[ $key ];
			if ( 'string' === $type ) {
				if ( null === $value ) {
					continue;
				}
				if ( ! is_string( $value ) && ! is_numeric( $value ) ) {
					continue;
				}
				$entries[ $key ] = (string) $value;
			} elseif ( 'boolean' === $type ) {
				if ( ! is_bool( $value ) ) {
					continue;
				}
				$entries[ $key ] = $value;
			} else { // int。
				if ( is_bool( $value ) || null === $value || '' === $value ) {
					continue;
				}
				if ( ! is_int( $value ) && ! ( is_float( $value ) && floor( $value ) === $value )
					&& ! ( is_string( $value ) && preg_match( '/^\d+$/', $value ) ) ) {
					continue;
				}
				$n = (int) $value;
				if ( $n < 0 ) {
					continue;
				}
				$entries[ $key ] = $n;
			}
		}

		// Key 单独处理：加密后落库；空字符串表示清空。
		if ( array_key_exists( 'deepseekApiKey', $entries ) ) {
			$plain = trim( $entries['deepseekApiKey'] );
			unset( $entries['deepseekApiKey'] );
			Db::set_setting( 'deepseekApiKey', ( '' === $plain ) ? '' : self::encrypt_secret( $plain ) );
		}
		if ( count( $entries ) > 0 ) {
			Db::set_settings( $entries );
		}
		self::flush();
		return self::for_admin();
	}

	/** 首次启用时把 WordPress 选项里的初始值写入自建 settings 表（已有值不动）。 */
	public static function seed_from_options() {
		$existing = Db::get_all_settings();
		$entries  = array();
		foreach ( Config::DEFAULTS as $key => $default ) {
			if ( array_key_exists( $key, $existing ) ) {
				continue;
			}
			$entries[ $key ] = $default;
		}
		if ( count( $entries ) > 0 ) {
			Db::set_settings( $entries );
			self::flush();
		}
	}

	/** @return bool AI 是否已配置 Key。 */
	public static function is_ai_configured() {
		$s = self::read_all();
		return '' !== $s['deepseekApiKey'];
	}

	/** @return bool AI 功能是否开启。 */
	public static function is_ai_enabled() {
		$s = self::read_all();
		return false !== $s['aiEnabled'];
	}

	/** @return bool AI 聊天总开关（docs/API-AI聊天.md §AI-5 第 5 条）。 */
	public static function is_ai_chat_enabled() {
		$s = self::read_all();
		return ! array_key_exists( 'aiChatEnabled', $s ) || false !== $s['aiChatEnabled'];
	}

	/** @return string 默认供应商 id（空表示没有显式默认）。 */
	public static function ai_default_provider_id() {
		$s = self::read_all();
		return isset( $s['aiProviderId'] ) && is_string( $s['aiProviderId'] ) ? $s['aiProviderId'] : '';
	}

	/** @return string 默认模型（空表示用供应商的 defaultModel）。 */
	public static function ai_default_model() {
		$s = self::read_all();
		return isset( $s['aiProviderModel'] ) && is_string( $s['aiProviderModel'] ) ? $s['aiProviderModel'] : '';
	}

	/**
	 * 解析出「这次要用哪个供应商 + 哪个模型」（§AI-4 第 2 步）。
	 *
	 * 优先级：伙伴指定的 providerId/model > 后台设置的默认供应商/默认模型 > 默认供应商的 defaultModel。
	 *
	 * @param array<string,mixed>|null $partner AI 伙伴对象（可为 null）。
	 * @return array{provider:array<string,mixed>|null,model:string}|null
	 */
	public static function resolve_ai_target( $partner = null ) {
		$provider = null;

		$want_id = ( is_array( $partner ) && isset( $partner['providerId'] ) ) ? (string) $partner['providerId'] : '';
		if ( '' === $want_id ) {
			$want_id = self::ai_default_provider_id();
		}
		if ( '' !== $want_id ) {
			$candidate = Db::get_ai_provider( $want_id );
			if ( $candidate && ! empty( $candidate['enabled'] ) ) {
				$provider = $candidate;
			}
		}
		if ( null === $provider ) {
			$provider = Db::get_default_ai_provider();
		}
		if ( null === $provider ) {
			return null;
		}

		$model = ( is_array( $partner ) && isset( $partner['model'] ) ) ? trim( (string) $partner['model'] ) : '';
		if ( '' === $model ) {
			$model = self::ai_default_model();
		}
		if ( '' === $model && isset( $provider['defaultModel'] ) && '' !== (string) $provider['defaultModel'] ) {
			$model = (string) $provider['defaultModel'];
		}
		if ( '' === $model && isset( $provider['models'][0] ) && '' !== (string) $provider['models'][0] ) {
			$model = (string) $provider['models'][0];
		}

		return array(
			'provider' => $provider,
			'model'    => $model,
		);
	}

	/**
	 * 供应商对象的内部视图：带上解密后的明文 Key（**只允许服务端内部使用**）。
	 *
	 * @param array<string,mixed> $provider 供应商对象。
	 * @return array<string,mixed>
	 */
	public static function with_plain_api_key( array $provider ) {
		$key = '';
		if ( isset( $provider['id'] ) ) {
			$row = Db::get_ai_provider_row( $provider['id'] );
			if ( $row ) {
				$key = self::decrypt_secret( isset( $row['api_key_enc'] ) ? $row['api_key_enc'] : '' );
			}
		}
		$provider['apiKey'] = $key;
		return $provider;
	}

	/** @return int 当前 AI 每日限额（0 表示不限制）。 */
	public static function get_ai_daily_limit() {
		$s = self::read_all();
		$n = $s['aiDailyLimit'];
		if ( is_int( $n ) && $n >= 0 ) {
			return $n;
		}
		if ( is_numeric( $n ) && (int) $n == $n && (int) $n >= 0 ) { // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison
			return (int) $n;
		}
		return Config::DEFAULTS['aiDailyLimit'];
	}

	/** @return bool 注册是否开放。 */
	public static function is_register_open() {
		$s = self::read_all();
		return false !== $s['registerOpen'];
	}

	/** @return int 每个情侣空间允许的自建表情包上限。 */
	public static function max_emoji_packs() {
		$s = self::read_all();
		$n = isset( $s['maxEmojiPacks'] ) ? (int) $s['maxEmojiPacks'] : 0;
		return $n > 0 ? $n : Config::LIMITS['EMOJI_MAX_PACKS'];
	}

	/** @return int 每日消息上限（0 或非正数表示不限制）。 */
	public static function max_messages_per_day() {
		$s = self::read_all();
		$n = isset( $s['maxMessagesPerDay'] ) ? (int) $s['maxMessagesPerDay'] : 0;
		return $n > 0 ? $n : 0;
	}
}
