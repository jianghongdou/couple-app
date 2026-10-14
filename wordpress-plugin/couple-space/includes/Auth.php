<?php
/**
 * 鉴权：用户 token 与后台 token 使用不同密钥、不同 typ，互不通用。
 *
 * 移植自 server-php/src/Auth.php。
 * 注意：App 后台登录用的是本插件自己的 JWT 体系，与 WordPress 用户无关；
 * 插件后台页面（wp-admin）用的是 WordPress 自己的登录与权限（current_user_can）。
 *
 * @package CoupleSpace
 */

namespace CoupleSpace;

defined( 'ABSPATH' ) || exit;

/**
 * 鉴权服务。
 */
class Auth {

	/** bcrypt 轮数（与 server-php / Node 版一致：10 轮）。 */
	const BCRYPT_ROUNDS = 10;

	/**
	 * 生成用户 token（有效期 30 天）。
	 *
	 * @param array<string,mixed> $user users 表原始行。
	 * @return string
	 */
	public static function sign_user_token( array $user ) {
		return Jwt::sign(
			array(
				'sub'      => $user['id'],
				'username' => $user['username'],
				'role'     => isset( $user['role'] ) ? $user['role'] : 'user',
				'typ'      => 'user',
			),
			Config::jwt_secret(),
			Config::JWT_EXPIRES_IN_SECONDS
		);
	}

	/**
	 * 校验用户 token。
	 *
	 * @param string $token token。
	 * @return array{row:array<string,mixed>,user:array<string,mixed>}|null
	 */
	public static function verify_user_token( $token ) {
		$payload = Jwt::verify( $token, Config::jwt_secret() );
		if ( ! $payload || ! isset( $payload['typ'] ) || 'user' !== $payload['typ'] || empty( $payload['sub'] ) ) {
			return null;
		}
		return Db::get_user_by_id( $payload['sub'] );
	}

	/**
	 * 必须登录：返回 ['row'=>..,'user'=>..]，否则抛 401/403。
	 *
	 * @return array{row:array<string,mixed>,user:array<string,mixed>}
	 */
	public static function require_user() {
		$token = Http::extract_token();
		if ( null === $token ) {
			throw new HttpException( 401, 'unauthorized', '缺少登录凭证' );
		}
		$found = self::verify_user_token( $token );
		if ( ! $found ) {
			throw new HttpException( 401, 'unauthorized', '登录已过期，请重新登录' );
		}
		if ( ! empty( $found['row']['disabled'] ) ) {
			throw new HttpException( 403, 'forbidden', '账号已被禁用' );
		}
		return $found;
	}

	/**
	 * 要求已绑定情侣关系（未绑定 -> 403 forbidden）。
	 *
	 * @param array<string,mixed> $user user 对象。
	 * @return string 情侣空间 id。
	 */
	public static function require_couple( $user ) {
		if ( empty( $user['coupleId'] ) ) {
			throw new HttpException( 403, 'forbidden', '尚未绑定情侣关系' );
		}
		return $user['coupleId'];
	}

	// ------------------------------------------------------------------
	// 后台（App 后台 /admin/api/*）
	// ------------------------------------------------------------------

	/**
	 * 常量时间比较密码，避免时序侧信道。
	 * 先做 SHA-256 再比较，保证长度不同也不会提前返回。
	 *
	 * @param string $a 值一。
	 * @param string $b 值二。
	 * @return bool
	 */
	public static function timing_safe_compare( $a, $b ) {
		$ha = hash( 'sha256', (string) $a, true );
		$hb = hash( 'sha256', (string) $b, true );
		return hash_equals( $ha, $hb );
	}

	/**
	 * 生成后台密码哈希（bcrypt）。
	 *
	 * @param string $password 明文密码。
	 * @return string
	 */
	public static function hash_admin_password( $password ) {
		return password_hash( (string) $password, PASSWORD_BCRYPT, array( 'cost' => self::BCRYPT_ROUNDS ) );
	}

	/**
	 * 校验后台管理员密码。
	 *
	 *   1) 数据库里**有** settings.adminPasswordHash → 只认它（password_verify）。
	 *   2) 数据库里**没有**哈希 → 与 WordPress 选项里的初始密码做常数时间比较；
	 *      一旦验证成功，立刻把该密码哈希写入数据库（自动迁移），
	 *      用户无需任何额外操作就能开始用「后台自助改密码」。
	 *
	 * @param mixed $password 明文密码。
	 * @return bool
	 */
	public static function verify_admin_password( $password ) {
		if ( ! is_string( $password ) || '' === $password ) {
			return false;
		}

		$hash = Settings::admin_password_hash();
		if ( '' !== $hash ) {
			return password_verify( $password, $hash );
		}

		$initial = (string) Config::option( 'initialAdminPassword', '' );
		if ( '' === $initial || ! self::timing_safe_compare( $password, $initial ) ) {
			return false;
		}

		// 选项里的初始密码校验通过 → 自动迁入数据库（失败也不影响本次登录）。
		self::migrate_admin_password_to_database( $password );
		return true;
	}

	/**
	 * 把明文后台密码哈希后写入数据库（首次迁移 / 重置）。
	 * 任何失败都只记日志，不抛出：调用方（登录、改密码校验）不应因此失败。
	 *
	 * @param string $password 明文密码。
	 * @return bool
	 */
	public static function migrate_admin_password_to_database( $password ) {
		try {
			Settings::set_admin_password_hash( self::hash_admin_password( $password ) );
			Log::info( '后台密码已自动迁入数据库（选项里的初始密码作为初始密码）' );
			return true;
		} catch ( \Throwable $e ) {
			Log::warn( '后台密码自动迁入数据库失败（本次仍按选项里的初始密码校验）', array( 'error' => $e->getMessage() ) );
			return false;
		}
	}

	/**
	 * 签发后台 token。
	 *
	 * @return string
	 */
	public static function sign_admin_token() {
		return Jwt::sign(
			array(
				'typ'  => 'admin',
				'role' => 'admin',
			),
			Config::admin_jwt_secret(),
			Config::ADMIN_TOKEN_TTL_SECONDS
		);
	}

	/**
	 * 后台接口鉴权（只认 Authorization 头）。
	 *
	 * @return bool
	 */
	public static function require_admin() {
		$token = Http::bearer_token();
		if ( null === $token ) {
			throw new HttpException( 401, 'unauthorized', '缺少后台登录凭证' );
		}
		$payload = Jwt::verify( $token, Config::admin_jwt_secret() );
		if ( ! $payload || ! isset( $payload['typ'] ) || 'admin' !== $payload['typ'] ) {
			throw new HttpException( 401, 'unauthorized', '后台登录已过期，请重新登录' );
		}
		return true;
	}

	/**
	 * 记录后台操作日志（所有写操作都必须调用）。
	 *
	 * @param string      $action 动作。
	 * @param string|null $detail 详情。
	 * @param string|null $ip     IP。
	 */
	public static function log_admin_action( $action, $detail = null, $ip = null ) {
		try {
			Db::add_admin_log(
				array(
					'action' => $action,
					'detail' => ( null === $detail ) ? null : mb_substr( (string) $detail, 0, 500, 'UTF-8' ),
					'ip'     => ( null === $ip ) ? Http::client_ip() : $ip,
				)
			);
		} catch ( \Throwable $e ) {
			Log::error( '写入后台操作日志失败', array( 'error' => $e->getMessage() ) );
		}
	}
}
