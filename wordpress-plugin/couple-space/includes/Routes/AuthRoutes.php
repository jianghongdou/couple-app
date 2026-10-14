<?php
/**
 * 登录与账号相关接口（API.md §2）。
 *
 * 移植自 server-php/src/routes/AuthRoutes.php，逐条对齐字段名、错误码与文案。
 *
 * @package CoupleSpace
 */

namespace CoupleSpace\Routes;

use CoupleSpace\Auth;
use CoupleSpace\Base;
use CoupleSpace\Config;
use CoupleSpace\Db;
use CoupleSpace\Http;
use CoupleSpace\HttpException;
use CoupleSpace\Log;
use CoupleSpace\RateLimit;
use CoupleSpace\Settings;
use CoupleSpace\Validate;

defined( 'ABSPATH' ) || exit;

/**
 * 账号接口。
 */
class AuthRoutes extends Base {

	/**
	 * 注册路由。
	 *
	 * @param object $r 路由器。
	 */
	public static function register( $r ) {
		// 登录/注册限流：同一 IP 15 分钟内最多 30 次。
		$limiter = static function () {
			RateLimit::enforce( 'auth:' . Http::client_ip(), 30, 900000, '登录尝试过于频繁，请稍后再试' );
		};

		// POST /api/auth/register。
		$r->post(
			'/api/auth/register',
			static function () use ( $limiter ) {
				$limiter();
				$body = Http::json_body();

				// 注册开关（registerOpen=false 时返回 403 forbidden）。
				if ( ! Settings::is_register_open() ) {
					throw new HttpException( 403, 'forbidden', '当前已关闭注册' );
				}

				$username     = Validate::validate_username( Http::field( $body, 'username' ) );
				$password     = Validate::validate_password( Http::field( $body, 'password' ) );
				$nickname_raw = Http::field( $body, 'nickname' );
				$nickname     = ( null === $nickname_raw || '' === $nickname_raw )
					? $username
					: Validate::validate_nickname( $nickname_raw );
				$avatar_emoji = Validate::validate_avatar_emoji( Http::field( $body, 'avatarEmoji' ) );
				if ( null === $avatar_emoji ) {
					$avatar_emoji = '';
				}

				// 用户名唯一性。
				if ( Db::find_user_by_username( $username ) ) {
					throw new HttpException( 409, 'conflict', '该用户名已被注册' );
				}

				// 可选邀请码：填了就直接加入对方情侣关系。
				$invite_target = null;
				$invite_raw    = Http::field( $body, 'inviteCode' );
				if ( null !== $invite_raw && '' !== $invite_raw ) {
					$code = strtoupper(
						Validate::require_string(
							$invite_raw,
							'inviteCode',
							array(
								'min'  => 4,
								'max'  => 16,
								'trim' => true,
							)
						)
					);
					$invite_target = Db::get_couple_by_invite_code( $code );
					if ( ! $invite_target ) {
						throw new HttpException( 404, 'not_found', '邀请码无效' );
					}
					if ( count( $invite_target['members'] ) >= Config::limit( 'COUPLE_MAX_MEMBERS' ) ) {
						throw new HttpException( 409, 'conflict', '该情侣空间已满' );
					}
				}

				$hash    = password_hash( $password, PASSWORD_BCRYPT, array( 'cost' => Auth::BCRYPT_ROUNDS ) );
				$created = Db::create_user(
					array(
						'username'     => $username,
						'passwordHash' => $hash,
						'nickname'     => $nickname,
						'avatarEmoji'  => $avatar_emoji,
					)
				);

				if ( $invite_target ) {
					Db::set_user_couple( $created['user']['id'], $invite_target['id'] );
				}

				Db::touch_user_last_seen( $created['user']['id'] );
				Log::info( '用户注册成功', array( 'userId' => $created['user']['id'], 'joined' => ( null !== $invite_target ) ) );

				$fresh  = Db::get_user_by_id( $created['user']['id'] );
				$user   = $fresh['user'];
				$couple = $user['coupleId'] ? Db::build_couple( $user['coupleId'] ) : null;
				return self::ok(
					array(
						'token'  => Auth::sign_user_token( $fresh['row'] ),
						'user'   => $user,
						'couple' => $couple,
					)
				);
			}
		);

		// POST /api/auth/login。
		$r->post(
			'/api/auth/login',
			static function () use ( $limiter ) {
				$limiter();
				$body     = Http::json_body();
				$username = Validate::require_string(
					Http::field( $body, 'username' ),
					'username',
					array(
						'min'  => 1,
						'max'  => 32,
						'trim' => true,
					)
				);
				$password = Validate::require_string(
					Http::field( $body, 'password' ),
					'password',
					array(
						'min' => 1,
						'max' => 64,
					)
				);

				$found = Db::find_user_by_username( $username );
				// 统一提示，避免用户名枚举。
				if ( ! $found || ! password_verify( $password, $found['row']['password_hash'] ) ) {
					throw new HttpException( 401, 'unauthorized', '用户名或密码错误' );
				}
				if ( ! empty( $found['row']['disabled'] ) ) {
					throw new HttpException( 403, 'forbidden', '账号已被禁用，请联系管理员' );
				}

				Db::touch_user_last_seen( $found['user']['id'] );
				$fresh  = Db::get_user_by_id( $found['user']['id'] );
				$user   = $fresh['user'];
				$couple = $user['coupleId'] ? Db::build_couple( $user['coupleId'] ) : null;
				return self::ok(
					array(
						'token'  => Auth::sign_user_token( $fresh['row'] ),
						'user'   => $user,
						'couple' => $couple,
					)
				);
			}
		);

		// GET /api/auth/me。
		$r->get(
			'/api/auth/me',
			static function () {
				$found = Auth::require_user();
				$fresh = Db::get_user_by_id( $found['user']['id'] );
				if ( ! $fresh ) {
					throw new HttpException( 404, 'not_found', '用户不存在' );
				}
				$couple = $fresh['user']['coupleId'] ? Db::build_couple( $fresh['user']['coupleId'] ) : null;
				return self::ok(
					array(
						'user'   => $fresh['user'],
						'couple' => $couple,
					)
				);
			}
		);

		// PATCH /api/auth/me。
		$r->patch(
			'/api/auth/me',
			static function () {
				$me   = Auth::require_user();
				$body = Http::json_body();
				if ( ! Http::has( $body, 'nickname' ) && ! Http::has( $body, 'avatarEmoji' ) ) {
					Validate::fail( '请至少提供 nickname 或 avatarEmoji' );
				}
				$patch = array();
				if ( Http::has( $body, 'nickname' ) ) {
					$patch['nickname'] = Validate::validate_nickname( Http::field( $body, 'nickname' ) );
				}
				if ( Http::has( $body, 'avatarEmoji' ) ) {
					$emoji                = Validate::validate_avatar_emoji( Http::field( $body, 'avatarEmoji' ) );
					$patch['avatarEmoji'] = ( null === $emoji ) ? '' : $emoji;
				}
				$updated = Db::update_user_profile( $me['user']['id'], $patch );
				if ( ! $updated ) {
					throw new HttpException( 404, 'not_found', '用户不存在' );
				}
				return self::ok( array( 'user' => $updated['user'] ) );
			}
		);

		// POST /api/auth/password。
		$r->post(
			'/api/auth/password',
			static function () {
				$me   = Auth::require_user();
				$body = Http::json_body();
				$old  = Validate::require_string(
					Http::field( $body, 'oldPassword' ),
					'oldPassword',
					array(
						'min' => 1,
						'max' => 64,
					)
				);
				$new = Validate::validate_password( Http::field( $body, 'newPassword' ), 'newPassword' );

				$fresh = Db::get_user_by_id( $me['user']['id'] );
				if ( ! $fresh ) {
					throw new HttpException( 404, 'not_found', '用户不存在' );
				}
				if ( ! password_verify( $old, $fresh['row']['password_hash'] ) ) {
					throw new HttpException( 401, 'unauthorized', '原密码错误' );
				}
				$hash = password_hash( $new, PASSWORD_BCRYPT, array( 'cost' => Auth::BCRYPT_ROUNDS ) );
				Db::update_user_password( $me['user']['id'], $hash );
				Log::info( '用户修改密码', array( 'userId' => $me['user']['id'] ) );
				return self::ok( array( 'ok' => true ) );
			}
		);
	}
}
