<?php
/**
 * 通用数据同步接口（API.md §4）。
 *
 * 移植自 server-php/src/routes/SyncRoutes.php。
 *
 * 重要：记账（ledger）与密码本（vault）不参与同步，只存手机本地。
 * 服务端明确拒绝这两个 kind，并返回可读的中文原因。
 *
 * @package CoupleSpace
 */

namespace CoupleSpace\Routes;

use CoupleSpace\Auth;
use CoupleSpace\Base;
use CoupleSpace\Config;
use CoupleSpace\Db;
use CoupleSpace\Http;
use CoupleSpace\Log;
use CoupleSpace\Validate;
use CoupleSpace\WsOutbox;

defined( 'ABSPATH' ) || exit;

/**
 * 同步接口。
 */
class SyncRoutes extends Base {

	/**
	 * 注册路由。
	 *
	 * @param object $r 路由器。
	 */
	public static function register( $r ) {
		// POST /api/sync/push。
		$r->post(
			'/api/sync/push',
			static function () {
				$me        = Auth::require_user();
				$couple_id = Auth::require_couple( $me['user'] );
				$body      = Http::json_body();
				$objects   = Http::field( $body, 'objects' );

				if ( ! is_array( $objects ) ) {
					Validate::fail( 'objects 必须是数组' );
				}
				if ( 0 === count( $objects ) ) {
					Validate::fail( 'objects 不能为空数组' );
				}
				if ( count( $objects ) > Config::limit( 'SYNC_MAX_OBJECTS' ) ) {
					Validate::fail( '单次最多推送 ' . Config::limit( 'SYNC_MAX_OBJECTS' ) . ' 个对象，当前 ' . count( $objects ) . ' 个' );
				}

				$validated = array();
				foreach ( $objects as $i => $o ) {
					$validated[] = self::validate_sync_object( $o, $i );
				}

				$result = Db::push_sync_objects(
					array(
						'coupleId' => $couple_id,
						'userId'   => $me['user']['id'],
						'objects'  => $validated,
					)
				);

				// 给伴侣广播 {type:"sync"}（API.md §8）；插件无 WebSocket，此处为空实现。
				if ( count( $result['applied'] ) > 0 ) {
					$kinds = array();
					foreach ( $validated as $v ) {
						if ( in_array( $v['id'], $result['applied'], true ) && ! in_array( $v['kind'], $kinds, true ) ) {
							$kinds[] = $v['kind'];
						}
					}
					WsOutbox::to_couple(
						$couple_id,
						array(
							'type'       => 'sync',
							'kinds'      => $kinds,
							'fromUserId' => $me['user']['id'],
						),
						$me['user']['id']
					);
				}

				Log::info(
					'同步推送完成',
					array(
						'userId'    => $me['user']['id'],
						'coupleId'  => $couple_id,
						'applied'   => count( $result['applied'] ),
						'conflicts' => count( $result['conflicts'] ),
					)
				);

				return self::ok(
					array(
						'applied'   => $result['applied'],
						'conflicts' => $result['conflicts'],
					)
				);
			}
		);

		// GET /api/sync/pull?since=<ms>&kinds=spark,pet&limit=500。
		$r->get(
			'/api/sync/pull',
			static function () {
				$me        = Auth::require_user();
				$couple_id = Auth::require_couple( $me['user'] );

				$since = Validate::query_int( Http::query( 'since' ), 'since', array( 'defaultValue' => 0, 'min' => 0 ) );

				// kinds 可选，逗号分隔，缺省为全部白名单。
				$kinds     = Config::SYNC_KINDS;
				$raw_kinds = Http::query( 'kinds' );
				if ( is_string( $raw_kinds ) && '' !== trim( $raw_kinds ) ) {
					$requested = array();
					foreach ( explode( ',', $raw_kinds ) as $s ) {
						$s = trim( $s );
						if ( '' !== $s ) {
							$requested[] = $s;
						}
					}
					if ( 0 === count( $requested ) ) {
						Validate::fail( 'kinds 参数不能为空' );
					}
					$rejected = array();
					foreach ( $requested as $k ) {
						if ( in_array( $k, Config::SYNC_REJECTED_KINDS, true ) ) {
							$rejected[] = $k;
						}
					}
					if ( count( $rejected ) > 0 ) {
						Validate::fail(
							'kind "' . implode( '、', $rejected )
							. '" 不参与服务端同步：记账数据与密码本只保存在手机本地，服务端不接收。'
						);
					}
					$invalid = array();
					foreach ( $requested as $k ) {
						if ( ! in_array( $k, Config::SYNC_KINDS, true ) ) {
							$invalid[] = $k;
						}
					}
					if ( count( $invalid ) > 0 ) {
						Validate::fail(
							'kinds 包含不支持的取值：' . implode( '、', $invalid )
							. '，仅支持：' . implode( '、', Config::SYNC_KINDS )
						);
					}
					$kinds = array_values( array_unique( $requested ) );
				}

				// limit 默认 500，上限 1000。
				$limit = Validate::query_int(
					Http::query( 'limit' ),
					'limit',
					array(
						'defaultValue' => Config::limit( 'SYNC_PULL_DEFAULT' ),
						'min'          => 1,
						'max'          => Config::limit( 'SYNC_PULL_MAX' ),
					)
				);

				$result = Db::pull_sync_objects(
					array(
						'coupleId' => $couple_id,
						'since'    => $since,
						'kinds'    => $kinds,
						'limit'    => $limit,
					)
				);

				// 客户端应使用服务端返回的 serverTime 作为下次 since。
				return self::ok(
					array(
						'objects' => $result['objects'],
						'hasMore' => $result['hasMore'],
					)
				);
			}
		);
	}

	/**
	 * 校验单个同步对象。
	 *
	 * @param mixed $raw   原始对象。
	 * @param int   $index 下标。
	 * @return array<string,mixed>
	 */
	private static function validate_sync_object( $raw, $index ) {
		$at = 'objects[' . $index . ']';
		if ( ! Validate::is_plain_object( $raw ) ) {
			Validate::fail( $at . ' 必须是一个 JSON 对象' );
		}

		$kind = Validate::require_string(
			Http::field( $raw, 'kind' ),
			$at . '.kind',
			array(
				'min'  => 1,
				'max'  => 32,
				'trim' => true,
			)
		);
		if ( ! in_array( $kind, Config::SYNC_KINDS, true ) ) {
			if ( in_array( $kind, Config::SYNC_REJECTED_KINDS, true ) ) {
				// 这两类数据只存手机本地（API.md §4）。
				Validate::fail( 'kind "' . $kind . '" 不参与服务端同步：记账数据与密码本只保存在手机本地，服务端不接收、不存储。' );
			}
			Validate::fail( $at . '.kind 不在允许列表中，仅支持：' . implode( '、', Config::SYNC_KINDS ) );
		}

		$id         = Validate::require_string( Http::field( $raw, 'id' ), $at . '.id', array( 'min' => 1, 'max' => 128 ) );
		$updated_at = Validate::require_timestamp( Http::field( $raw, 'updatedAt' ), $at . '.updatedAt', array( 'required' => true ) );
		$deleted    = Validate::optional_bool( Http::field( $raw, 'deleted' ), $at . '.deleted', false );
		$payload    = Validate::require_json_object(
			Http::field( $raw, 'payload' ),
			$at . '.payload',
			array(
				'allowNull'    => true,
				'defaultValue' => null,
			)
		);

		return array(
			'kind'      => $kind,
			'id'        => $id,
			'updatedAt' => $updated_at,
			'deleted'   => $deleted,
			'payload'   => $payload,
		);
	}
}
