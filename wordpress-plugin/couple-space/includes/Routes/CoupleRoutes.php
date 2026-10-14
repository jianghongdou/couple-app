<?php
/**
 * 情侣关系接口（API.md §3）。
 *
 * 移植自 server-php/src/routes/CoupleRoutes.php。
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
use CoupleSpace\Validate;
use CoupleSpace\WsOutbox;

defined( 'ABSPATH' ) || exit;

/**
 * 情侣关系接口。
 */
class CoupleRoutes extends Base {

	/**
	 * 注册路由。
	 *
	 * @param object $r 路由器。
	 */
	public static function register( $r ) {
		/**
		 * 写入一条系统消息（API.md §5 kind: system）。
		 *
		 * 插件不做 WebSocket，所以只落库（客户端靠轮询拿到这条消息）。
		 *
		 * @param string $couple_id 情侣空间 id。
		 * @param string $sender_id 发送者。
		 * @param string $text      内容。
		 * @return array<string,mixed>|null
		 */
		$system_message = static function ( $couple_id, $sender_id, $text ) {
			try {
				$message = Db::insert_message(
					array(
						'clientId' => null,
						'coupleId' => $couple_id,
						'senderId' => $sender_id,
						'kind'     => 'system',
						'body'     => $text,
						'meta'     => null,
					)
				);
				WsOutbox::to_couple( $couple_id, array( 'type' => 'message', 'message' => $message ) );
				return $message;
			} catch ( \Throwable $e ) {
				// 系统消息失败不应影响主流程。
				Log::warn( '写入系统消息失败', array( 'error' => $e->getMessage() ) );
				return null;
			}
		};

		// POST /api/couple/create。
		$r->post(
			'/api/couple/create',
			static function () use ( $system_message ) {
				$me    = Auth::require_user();
				$fresh = Db::get_user_by_id( $me['user']['id'] );
				if ( ! $fresh ) {
					throw new HttpException( 404, 'not_found', '用户不存在' );
				}
				if ( ! empty( $fresh['user']['coupleId'] ) ) {
					throw new HttpException( 409, 'conflict', '你已经有情侣空间了' );
				}

				$created = Db::create_couple( array( 'anniversaryTitle' => '在一起' ) );
				Db::set_user_couple( $me['user']['id'], $created['id'] );
				$system_message( $created['id'], $me['user']['id'], '情侣空间已创建，把邀请码发给 TA 吧' );

				$couple = Db::build_couple( $created['id'] );
				Log::info( '创建情侣空间', array( 'coupleId' => $created['id'], 'userId' => $me['user']['id'] ) );
				return self::ok( array( 'couple' => $couple ) );
			}
		);

		// POST /api/couple/join。
		$r->post(
			'/api/couple/join',
			static function () use ( $system_message ) {
				$me    = Auth::require_user();
				$fresh = Db::get_user_by_id( $me['user']['id'] );
				if ( ! $fresh ) {
					throw new HttpException( 404, 'not_found', '用户不存在' );
				}
				if ( ! empty( $fresh['user']['coupleId'] ) ) {
					throw new HttpException( 409, 'conflict', '你已经有情侣空间了' );
				}

				$body = Http::json_body();
				$code = strtoupper(
					Validate::require_string(
						Http::field( $body, 'inviteCode' ),
						'inviteCode',
						array(
							'min'  => 4,
							'max'  => 16,
							'trim' => true,
						)
					)
				);

				$couple = Db::get_couple_by_invite_code( $code );
				if ( ! $couple ) {
					throw new HttpException( 404, 'not_found', '邀请码无效' );
				}
				if ( Db::get_couple_member_count( $couple['id'] ) >= Config::limit( 'COUPLE_MAX_MEMBERS' ) ) {
					throw new HttpException( 409, 'conflict', '该情侣空间已满' );
				}

				Db::set_user_couple( $me['user']['id'], $couple['id'] );
				Log::info( '加入情侣空间', array( 'coupleId' => $couple['id'], 'userId' => $me['user']['id'] ) );

				$nickname = ( '' !== $fresh['user']['nickname'] ) ? $fresh['user']['nickname'] : $fresh['user']['username'];
				$system_message( $couple['id'], $me['user']['id'], $nickname . ' 加入了情侣空间，你们已绑定' );

				$full = Db::build_couple( $couple['id'] );
				WsOutbox::to_couple( $couple['id'], array( 'type' => 'couple', 'couple' => $full ) );
				return self::ok( array( 'couple' => $full ) );
			}
		);

		// GET /api/couple —— members 含双方完整 user 对象。
		$r->get(
			'/api/couple',
			static function () {
				$me        = Auth::require_user();
				$couple_id = Auth::require_couple( $me['user'] );
				$couple    = Db::build_couple( $couple_id );
				if ( ! $couple ) {
					throw new HttpException( 404, 'not_found', '情侣空间不存在' );
				}
				return self::ok( array( 'couple' => $couple ) );
			}
		);

		// PATCH /api/couple —— togetherDate / anniversaryTitle。
		$r->patch(
			'/api/couple',
			static function () {
				$me        = Auth::require_user();
				$couple_id = Auth::require_couple( $me['user'] );
				$body      = Http::json_body();
				$patch     = array();

				if ( Http::has( $body, 'togetherDate' ) ) {
					$v = Http::field( $body, 'togetherDate' );
					// 允许显式传 null 表示清空。
					$patch['togetherDate'] = ( null === $v )
						? null
						: Validate::require_timestamp( $v, 'togetherDate', array( 'required' => true ) );
				}
				if ( Http::has( $body, 'anniversaryTitle' ) ) {
					$patch['anniversaryTitle'] = Validate::optional_string(
						Http::field( $body, 'anniversaryTitle' ),
						'anniversaryTitle',
						array(
							'min'  => 1,
							'max'  => 32,
							'trim' => true,
						)
					);
				}
				if ( 0 === count( $patch ) ) {
					Validate::fail( '请至少提供 togetherDate 或 anniversaryTitle' );
				}

				$couple = Db::update_couple( $couple_id, $patch );
				if ( ! $couple ) {
					throw new HttpException( 404, 'not_found', '情侣空间不存在' );
				}
				WsOutbox::to_couple( $couple_id, array( 'type' => 'couple', 'couple' => $couple ) );
				return self::ok( array( 'couple' => $couple ) );
			}
		);

		// POST /api/couple/leave —— 解除绑定，双方 coupleId 置空（数据保留 30 天）。
		$r->post(
			'/api/couple/leave',
			static function () {
				$me        = Auth::require_user();
				$couple_id = Auth::require_couple( $me['user'] );
				$affected  = Db::dissolve_couple( $couple_id );
				Log::info(
					'解除情侣绑定',
					array(
						'coupleId' => $couple_id,
						'userId'   => $me['user']['id'],
						'affected' => $affected,
					)
				);
				WsOutbox::to_couple( $couple_id, array( 'type' => 'couple', 'couple' => null ) );
				return self::ok( array( 'ok' => true ) );
			}
		);
	}
}
