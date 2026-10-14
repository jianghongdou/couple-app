<?php
/**
 * 聊天接口（API.md §5）。
 *
 * 移植自 server-php/src/routes/ChatRoutes.php。
 * 插件无 WebSocket，消息靠客户端 5 秒轮询拉取。
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
use CoupleSpace\Settings;
use CoupleSpace\Time;
use CoupleSpace\Validate;
use CoupleSpace\WsOutbox;

defined( 'ABSPATH' ) || exit;

/**
 * 聊天接口。
 */
class ChatRoutes extends Base {

	/**
	 * 注册路由。
	 *
	 * @param object $r 路由器。
	 */
	public static function register( $r ) {
		// GET /api/chat/messages?before=<ms>&limit=50。
		$r->get(
			'/api/chat/messages',
			static function () {
				$me        = Auth::require_user();
				$couple_id = Auth::require_couple( $me['user'] );

				$before = Validate::query_int( Http::query( 'before' ), 'before', array( 'min' => 0 ) );
				$limit  = Validate::query_int(
					Http::query( 'limit' ),
					'limit',
					array(
						'defaultValue' => Config::limit( 'CHAT_PAGE_DEFAULT' ),
						'min'          => 1,
						'max'          => 200,
					)
				);

				$result = Db::list_messages(
					array(
						'coupleId' => $couple_id,
						'before'   => $before,
						'limit'    => $limit,
					)
				);
				// 返回按 createdAt 升序（API.md §5）。
				return self::ok(
					array(
						'messages' => $result['messages'],
						'hasMore'  => $result['hasMore'],
					)
				);
			}
		);

		// POST /api/chat/messages。
		$r->post(
			'/api/chat/messages',
			static function () {
				$me        = Auth::require_user();
				$couple_id = Auth::require_couple( $me['user'] );
				$body      = Http::json_body();

				// clientId 幂等：重复提交返回已存在的那条消息。
				$client_id = Validate::require_string( Http::field( $body, 'clientId' ), 'clientId', array( 'min' => 1, 'max' => 128 ) );
				$existing   = Db::find_message_by_client_id( $couple_id, $client_id );
				if ( $existing ) {
					return self::ok( array( 'message' => $existing ) );
				}

				$kind = Validate::require_string(
					Http::field( $body, 'kind' ),
					'kind',
					array(
						'min'  => 1,
						'max'  => 16,
						'trim' => true,
					)
				);
				if ( ! in_array( $kind, Config::MESSAGE_KINDS, true ) ) {
					Validate::fail( 'kind 不在允许列表中，仅支持：' . implode( '、', Config::MESSAGE_KINDS ) );
				}
				if ( ! in_array( $kind, Config::CLIENT_MESSAGE_KINDS, true ) ) {
					Validate::fail( 'system 类型消息由服务端生成，客户端不能直接发送' );
				}

				// body 长度上限 2000 字符（API.md §5）。
				$message_body = '';
				$raw_body     = Http::field( $body, 'body' );
				if ( null !== $raw_body ) {
					if ( ! is_string( $raw_body ) ) {
						Validate::fail( 'body 必须是字符串' );
					}
					$message_body = $raw_body;
					if ( Validate::length( $message_body ) > Config::limit( 'CHAT_BODY_MAX' ) ) {
						Validate::fail( 'body 长度不能超过 ' . Config::limit( 'CHAT_BODY_MAX' ) . ' 个字符' );
					}
				}
				if ( 'text' === $kind && '' === trim( $message_body ) ) {
					Validate::fail( 'text 类型消息的 body 不能为空' );
				}
				if ( 'emoji' === $kind || 'image' === $kind ) {
					if ( '' === trim( $message_body ) ) {
						Validate::fail( $kind . ' 类型消息的 body 不能为空' );
					}
					if ( ! preg_match( '/^(item|unicode):/', $message_body ) ) {
						Validate::fail( $kind . ' 类型消息的 body 必须是 "item:<表情id>" 或 "unicode:<emoji>" 格式' );
					}
				}

				$meta = Validate::require_json_object(
					Http::field( $body, 'meta' ),
					'meta',
					array(
						'allowNull'    => true,
						'defaultValue' => null,
					)
				);

				// 每日消息总数限制（settings.maxMessagesPerDay）。
				$max_per_day = Settings::max_messages_per_day();
				if ( $max_per_day > 0 ) {
					$used = Db::count_messages_today_by_user( $me['user']['id'] );
					if ( $used >= $max_per_day ) {
						throw new HttpException( 429, 'rate_limited', '今日消息条数已达上限（' . $max_per_day . ' 条）' );
					}
				}

				$message = null;
				try {
					$message = Db::insert_message(
						array(
							'clientId' => $client_id,
							'coupleId' => $couple_id,
							'senderId' => $me['user']['id'],
							'kind'     => $kind,
							'body'     => $message_body,
							'meta'     => $meta,
						)
					);
				} catch ( \Throwable $e ) {
					// 并发下同一 clientId 同时插入：唯一索引冲突 -> 返回已存在的那条（仍然幂等）。
					if ( Db::is_conflict_error( $e->getMessage() ) ) {
						$found = Db::find_message_by_client_id( $couple_id, $client_id );
						if ( $found ) {
							return self::ok( array( 'message' => $found ) );
						}
					}
					throw $e;
				}

				Db::touch_couple( $couple_id );
				// 实时推送给伴侣（也回推给自己，保证多端一致）；插件无 WebSocket，只落库。
				WsOutbox::to_couple( $couple_id, array( 'type' => 'message', 'message' => $message ) );

				Log::info(
					'发送消息',
					array(
						'messageId' => $message['id'],
						'coupleId'  => $couple_id,
						'senderId'  => $me['user']['id'],
						'kind'      => $kind,
					)
				);
				return self::ok( array( 'message' => $message ) );
			}
		);

		// POST /api/chat/read —— 把 createdAt <= upTo 且对方发来的消息标记为已读。
		$r->post(
			'/api/chat/read',
			static function () {
				$me        = Auth::require_user();
				$couple_id = Auth::require_couple( $me['user'] );
				$body      = Http::json_body();
				$up_to     = Validate::require_timestamp( Http::field( $body, 'upTo' ), 'upTo', array( 'required' => true ) );
				$count     = Db::mark_messages_read( $couple_id, $me['user']['id'], $up_to );

				WsOutbox::to_couple(
					$couple_id,
					array(
						'type'   => 'read',
						'userId' => $me['user']['id'],
						'upTo'   => $up_to,
					),
					$me['user']['id']
				);

				return self::ok(
					array(
						'ok'    => true,
						'count' => $count,
					)
				);
			}
		);

		// DELETE /api/chat/messages/:id —— 撤回自己发的消息（24 小时内）。
		$r->delete(
			'/api/chat/messages/:id',
			static function ( $params ) {
				$me        = Auth::require_user();
				$couple_id = Auth::require_couple( $me['user'] );
				$id        = Validate::require_string( $params['id'], 'id', array( 'min' => 1, 'max' => 128 ) );
				$found     = Db::get_message( $id );

				if ( ! $found || $found['row']['couple_id'] !== $couple_id ) {
					throw new HttpException( 404, 'not_found', '消息不存在' );
				}
				if ( $found['row']['sender_id'] !== $me['user']['id'] ) {
					throw new HttpException( 403, 'forbidden', '只能撤回自己发送的消息' );
				}
				if ( ! empty( $found['row']['deleted_at'] ) ) {
					// 已撤回，幂等返回成功。
					return self::ok( array( 'ok' => true ) );
				}
				if ( Time::now_ms() - (int) $found['row']['created_at'] > Config::limit( 'CHAT_RECALL_WINDOW_MS' ) ) {
					throw new HttpException( 403, 'forbidden', '超过 24 小时的消息不能撤回' );
				}

				Db::recall_message( $id );
				WsOutbox::to_couple(
					$couple_id,
					array(
						'type'      => 'message_deleted',
						'messageId' => $id,
						'userId'    => $me['user']['id'],
					)
				);

				Log::info( '撤回消息', array( 'messageId' => $id, 'userId' => $me['user']['id'] ) );
				return self::ok( array( 'ok' => true ) );
			}
		);
	}
}
