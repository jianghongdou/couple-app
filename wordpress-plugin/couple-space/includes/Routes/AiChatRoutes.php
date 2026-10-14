<?php
/**
 * AI 聊天接口（docs/API-AI聊天.md §AI-1 ~ §AI-4）。
 *
 * 与既有的「AI 记账」接口（§7 / AiRoutes.php）完全分开：
 *   - 记账接口行为一个字都不改（仍走 DeepSeek::parse_ledger）；
 *   - 本文件只负责「AI 伙伴 + 与 AI 伙伴聊天」。
 *
 * 关键约定（逐条对应契约）：
 *   - 未绑定情侣的用户**也能用**（不要求 coupleId）。
 *   - `clientId` 幂等：同 clientId 重复提交只落一条用户消息，并直接返回上次结果
 *     （AI 回复用 `c:<clientId>` 作为内部关联键）。
 *   - 上游上下文只带**最近 20 条**该会话消息。
 *   - 绝不下发供应商 Key / 原始请求体 / 上游完整响应。
 *
 * @package CoupleSpace
 */

namespace CoupleSpace\Routes;

use CoupleSpace\AiError;
use CoupleSpace\AiUpstream;
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
 * AI 伙伴与 AI 聊天接口。
 */
class AiChatRoutes extends Base {

	/**
	 * 注册路由。
	 *
	 * @param object $r 路由器。
	 */
	public static function register( $r ) {
		// ---------------------------------------------------------------
		// AI-1 列出 AI 伙伴。
		// ---------------------------------------------------------------
		$r->get(
			'/api/ai/partners',
			static function () {
				$me = Auth::require_user();
				return self::ok(
					array(
						'ok'       => true,
						'partners' => Db::list_ai_partners( $me['user']['id'] ),
					)
				);
			}
		);

		// ---------------------------------------------------------------
		// AI-1b 列出可用供应商（App 只读；绝不返回 apiKey / baseUrl）。
		// ---------------------------------------------------------------
		$r->get(
			'/api/ai/providers',
			static function () {
				// 需登录；不要求绑定情侣。
				Auth::require_user();

				$out = array();
				foreach ( Db::list_ai_providers( true ) as $p ) {
					$out[] = array(
						'id'           => (string) $p['id'],
						'name'         => (string) $p['name'],
						// models 为空数组表示「不限制模型名，允许手填」。
						'models'       => array_values( $p['models'] ),
						'defaultModel' => (string) $p['defaultModel'],
						'isDefault'    => (bool) $p['isDefault'],
					);
				}

				return self::ok(
					array(
						'ok'            => true,
						'aiChatEnabled' => (bool) Settings::is_ai_chat_enabled(),
						'providers'     => $out,
					)
				);
			}
		);

		// ---------------------------------------------------------------
		// AI-2 新增 AI 伙伴。
		// ---------------------------------------------------------------
		$r->post(
			'/api/ai/partners',
			static function () {
				$me   = Auth::require_user();
				$body = Http::json_body();

				$fields = self::validate_partner_fields( $body, array( 'name' ) );
				if ( Db::count_ai_partners( $me['user']['id'] ) >= Config::limit( 'AI_PARTNER_MAX' ) ) {
					throw new HttpException(
						409,
						'conflict',
						'每个用户最多只能创建 ' . Config::limit( 'AI_PARTNER_MAX' ) . ' 个 AI 伙伴'
					);
				}

				$partner = Db::create_ai_partner(
					array_merge(
						$fields,
						array( 'ownerId' => $me['user']['id'] )
					)
				);
				Log::info(
					'新增 AI 伙伴',
					array(
						'userId'    => $me['user']['id'],
						'partnerId' => $partner ? $partner['id'] : '',
					)
				);
				return self::ok(
					array(
						'ok'      => true,
						'partner' => $partner,
					)
				);
			}
		);

		// ---------------------------------------------------------------
		// AI-2 修改 AI 伙伴（只改传入字段）。
		// ---------------------------------------------------------------
		$r->patch(
			'/api/ai/partners/:id',
			static function ( $params ) {
				$me      = Auth::require_user();
				$id      = Validate::require_string( $params['id'], 'id', array( 'min' => 1, 'max' => 64 ) );
				$current = self::require_own_partner( $id, $me['user']['id'] );

				$body = Http::json_body();
				if ( 0 === count( Http::fields( $body ) ) ) {
					Validate::fail( '请求体不能为空' );
				}
				$fields = self::validate_partner_fields( $body, array(), $current );

				$partner = Db::update_ai_partner( $id, $fields );
				Log::info( '修改 AI 伙伴', array( 'userId' => $me['user']['id'], 'partnerId' => $id ) );
				return self::ok(
					array(
						'ok'       => true,
						'partner'  => $partner,
					)
				);
			}
		);

		// ---------------------------------------------------------------
		// AI-2 删除 AI 伙伴（连同它的消息）。
		// ---------------------------------------------------------------
		$r->delete(
			'/api/ai/partners/:id',
			static function ( $params ) {
				$me = Auth::require_user();
				$id = Validate::require_string( $params['id'], 'id', array( 'min' => 1, 'max' => 64 ) );
				// 不是自己的伙伴（或不存在）统一 404（不暴露存在性）。
				self::require_own_partner( $id, $me['user']['id'] );

				Db::delete_ai_partner( $id );
				Log::info( '删除 AI 伙伴', array( 'userId' => $me['user']['id'], 'partnerId' => $id ) );
				return self::ok( array( 'ok' => true ) );
			}
		);

		// ---------------------------------------------------------------
		// AI-3 与 AI 伙伴聊天（读）。
		// ---------------------------------------------------------------
		$r->get(
			'/api/ai/chat/messages',
			static function () {
				$me         = Auth::require_user();
				$partner_id = Validate::require_string( Http::query( 'partnerId' ), 'partnerId', array( 'min' => 1, 'max' => 64 ) );
				$partner    = self::require_own_partner( $partner_id, $me['user']['id'] );

				$before_raw = Http::query( 'before' );
				$before     = ( null === $before_raw || '' === $before_raw || 0 === (int) $before_raw )
					? null
					: Validate::query_int( $before_raw, 'before', array( 'min' => 1 ) );
				$limit      = Validate::query_int(
					Http::query( 'limit' ),
					'limit',
					array(
						'defaultValue' => Config::limit( 'AI_CHAT_LIMIT_DEFAULT' ),
						'min'          => 1,
					)
				);
				// §AI-3：limit 最大 100，超出按 100 处理。
				$limit = min( (int) $limit, Config::limit( 'AI_CHAT_LIMIT_MAX' ) );

				$result = Db::list_ai_messages(
					array(
						'ownerId'   => $me['user']['id'],
						'partnerId' => $partner_id,
						'before'    => $before,
						'limit'     => $limit,
					)
				);

				return self::ok(
					array(
						'ok'       => true,
						'partner'  => array(
							'id'          => $partner['id'],
							'name'        => $partner['name'],
							'avatarEmoji' => $partner['avatarEmoji'],
						),
						'messages' => $result['messages'],
						'hasMore'  => $result['hasMore'],
					)
				);
			}
		);

		// ---------------------------------------------------------------
		// AI-4 与 AI 伙伴聊天（发消息，服务端代发大模型）。
		// ---------------------------------------------------------------
		$r->post(
			'/api/ai/chat/messages',
			static function () {
				$me   = Auth::require_user();
				$body = Http::json_body();

				if ( ! Settings::is_ai_chat_enabled() ) {
					throw new HttpException( 403, 'forbidden', 'AI 聊天已关闭' );
				}

				$partner_id = Validate::require_string( Http::field( $body, 'partnerId' ), 'partnerId', array( 'min' => 1, 'max' => 64 ) );
				$partner    = self::require_own_partner( $partner_id, $me['user']['id'] );

				// §AI-4：伙伴被停用 -> 403 forbidden（该伙伴已停用）。
				if ( empty( $partner['enabled'] ) ) {
					throw new HttpException( 403, 'forbidden', '该伙伴已停用' );
				}

				// body 为空或 > 2000 字 -> 400 invalid_input（先校验，不消耗限流额度）。
				$text = Validate::require_string(
					Http::field( $body, 'body' ),
					'body',
					array(
						'min'  => 1,
						'max'  => Config::limit( 'AI_TEXT_MAX' ),
						'trim' => true,
					)
				);

				$client_raw = Http::field( $body, 'clientId' );
				$client_id  = '';
				if ( null !== $client_raw && '' !== $client_raw ) {
					$client_id = Validate::require_string( $client_raw, 'clientId', array( 'min' => 1, 'max' => 128, 'trim' => true ) );
				}

				// 幂等早期返回：同 clientId 且上次已拿到回复 -> 直接返回上次结果。
				if ( '' !== $client_id ) {
					$cached = self::cached_result( $me['user']['id'], $partner_id, $client_id );
					if ( null !== $cached ) {
						return self::ok( $cached );
					}
				}

				// §AI-4：同一用户 1 分钟最多 20 次。
				RateLimit::enforce(
					'aichat:' . $me['user']['id'],
					Config::limit( 'AI_CHAT_RATE_LIMIT' ),
					Config::limit( 'AI_CHAT_RATE_WINDOW_MS' ),
					'AI 调用过于频繁，请稍后再试'
				);

				// 每用户每日 AI 消息上限（默认 200；统计「用户消息 + AI 回复」）。
				$daily_limit = Settings::get_ai_daily_limit();
				if ( $daily_limit > 0 ) {
					$used = Db::count_ai_usage_today_by_user( $me['user']['id'] );
					if ( $used >= $daily_limit ) {
						throw new HttpException( 429, 'rate_limited', '今日 AI 调用次数已达上限（' . $daily_limit . ' 次）' );
					}
				}

				// 供应商与模型：伙伴指定 > 后台默认供应商/默认模型。
				$target = Settings::resolve_ai_target( $partner );
				if ( null === $target || null === $target['provider'] ) {
					throw new HttpException( 403, 'ai_not_configured', 'AI 未配置，请到服务端后台添加供应商' );
				}
				$model = (string) $target['model'];
				if ( '' === $model ) {
					throw new HttpException( 403, 'ai_not_configured', 'AI 未配置，请到服务端后台添加供应商' );
				}
				$provider = Settings::with_plain_api_key( $target['provider'] );

				// 1) 先把用户消息落库（唯一索引保证 clientId 幂等）。
				$message = null;
				if ( '' !== $client_id && Db::ai_message_client_id_exists( $me['user']['id'], $client_id ) ) {
					// 并发重放：已经存在同 clientId 的用户消息。
					$cached = self::cached_result( $me['user']['id'], $partner_id, $client_id );
					if ( null !== $cached ) {
						return self::ok( $cached );
					}
					$message = Db::find_ai_message_by_client_id( $me['user']['id'], $client_id );
				}

				if ( null === $message ) {
					try {
						$message = Db::insert_ai_message(
							array(
								'partnerId' => $partner_id,
								'ownerId'   => $me['user']['id'],
								'sender'    => 'user',
								'body'      => $text,
								'clientId'  => $client_id,
							)
						);
					} catch ( \Throwable $e ) {
						if ( Db::is_conflict_error( $e->getMessage() ) && '' !== $client_id ) {
							// 极窄窗口下的并发插入：返回已存在的那条（仍然幂等）。
							$cached = self::cached_result( $me['user']['id'], $partner_id, $client_id );
							if ( null !== $cached ) {
								return self::ok( $cached );
							}
							$message = Db::find_ai_message_by_client_id( $me['user']['id'], $client_id );
						}
						if ( null === $message ) {
							throw $e;
						}
					}
				}

				// 2) 组装上下文：persona 作为 system prompt + 最近 20 条会话消息（§AI-4）。
				$messages = self::build_upstream_messages( $partner, $me['user']['id'], $partner_id );

				$usage_provider = isset( $provider['name'] ) ? (string) $provider['name'] : '';
				$usage_model    = $model;

				try {
					$res = AiUpstream::chat(
						$provider,
						$messages,
						array(
							'model'       => $model,
							'temperature' => (float) $partner['temperature'],
							'maxTokens'   => Config::limit( 'AI_TEXT_MAX' ),
						)
					);
				} catch ( AiError $e ) {
					// 失败也计数，避免刷失败请求绕过每日限额。
					Db::record_ai_usage(
						array(
							'userId'    => $me['user']['id'],
							'endpoint'  => 'ai-chat',
							'model'     => $usage_model,
							'ok'        => false,
							'promptLen' => mb_strlen( $text, 'UTF-8' ),
						)
					);
					Log::warn(
						'AI 聊天上游调用失败',
						array(
							'userId'    => $me['user']['id'],
							'partnerId' => $partner_id,
							'status'    => $e->get_status(),
							'errorCode' => $e->get_error_code(),
						)
					);
					throw new HttpException(
						$e->get_status() ? $e->get_status() : 502,
						$e->get_error_code() ? $e->get_error_code() : 'upstream_error',
						$e->getMessage()
					);
				}

				/*
				 * 3) AI 回复落库（空内容也落一条占位文案，接口仍返回 200）。
				 *
				 * client_id 上的 UNIQUE(owner_id, client_id) 只用来约束**用户消息**的幂等，
				 * 而 MySQL 的唯一索引把多个 NULL 视为互不相同、却把多个 '' 视为相同：
				 * 若两条 AI 回复都写 ''，同一会话的第二条回复就会撞唯一键，
				 * 导致回复落库失败、接口返回 reply=null（用户消息却已经写进去了）。
				 * 因此这里不传 clientId，由 Db::insert_ai_message 落一个**互不相同的内部关联键**；
				 * 带 clientId 的请求用 `c:<clientId>` 关联，供幂等重放查找（见 cached_result）。
				 */
				$reply_text = (string) $res['content'];
				if ( '' === trim( $reply_text ) ) {
					$reply_text = '（AI 没有返回内容）';
				}
				if ( mb_strlen( $reply_text, 'UTF-8' ) > Config::limit( 'AI_CHAT_REPLY_MAX' ) ) {
					$reply_text = mb_substr( $reply_text, 0, Config::limit( 'AI_CHAT_REPLY_MAX' ), 'UTF-8' );
				}

				$reply = Db::insert_ai_message(
					array(
						'partnerId' => $partner_id,
						'ownerId'   => $me['user']['id'],
						'sender'    => 'ai',
						'body'      => $reply_text,
						'clientId'  => ( '' !== $client_id ) ? ( 'c:' . $client_id ) : '',
					)
				);
				if ( ! is_array( $reply ) || '' === (string) $reply['id'] ) {
					// 回复写库失败绝不能被当成「成功」蒙混过去（否则会话会丢一条 AI 回复）。
					Log::error(
						'AI 回复落库失败',
						array(
							'userId'    => $me['user']['id'],
							'partnerId' => $partner_id,
							'dbError'   => Db::last_error(),
						)
					);
					throw new HttpException( 500, 'server_error', 'AI 回复保存失败，请重试' );
				}

				$usage_model = ( isset( $res['model'] ) && '' !== $res['model'] ) ? (string) $res['model'] : $model;
				Db::record_ai_usage(
					array(
						'userId'    => $me['user']['id'],
						'endpoint'  => 'ai-chat',
						'model'     => $usage_model,
						'ok'        => true,
						'promptLen' => mb_strlen( $text, 'UTF-8' ),
					)
				);
				Log::info(
					'AI 聊天完成',
					array(
						'userId'    => $me['user']['id'],
						'partnerId' => $partner_id,
						'model'     => $usage_model,
						'latencyMs' => isset( $res['latencyMs'] ) ? (int) $res['latencyMs'] : 0,
					)
				);

				return self::ok(
					array(
						'ok'      => true,
						'message' => $message,
						'reply'   => $reply,
						'usage'   => array(
							'provider'         => $usage_provider,
							'model'            => $usage_model,
							'promptTokens'     => (int) $res['promptTokens'],
							'completionTokens' => (int) $res['completionTokens'],
						),
					)
				);
			}
		);
	}

	// ------------------------------------------------------------------
	// 内部工具
	// ------------------------------------------------------------------

	/**
	 * 取「自己的」AI 伙伴；不存在或不是自己的都返回 404 not_found（不暴露存在性）。
	 *
	 * @param string $id       AI 伙伴 id。
	 * @param string $owner_id 当前用户 id。
	 * @return array<string,mixed>
	 * @throws HttpException 404。
	 */
	private static function require_own_partner( $id, $owner_id ) {
		$row = Db::get_ai_partner_row( $id );
		if ( ! $row || (string) $row['owner_id'] !== (string) $owner_id ) {
			throw new HttpException( 404, 'not_found', 'AI 伙伴不存在' );
		}
		return Db::map_ai_partner( $row );
	}

	/**
	 * 校验 AI 伙伴字段（POST 必填 name；PATCH 只校验传入的字段）。
	 *
	 * @param mixed               $body     请求体。
	 * @param array<int,string>   $required 必填字段名。
	 * @param array<string,mixed> $current  PATCH 时的当前值（用于合并校验）。
	 * @return array<string,mixed> 只有「本次要写」的字段。
	 */
	private static function validate_partner_fields( $body, array $required = array(), $current = null ) {
		$out = array();

		// name：1-20 字（去空白后非空）。
		if ( Http::has( $body, 'name' ) || in_array( 'name', $required, true ) ) {
			$out['name'] = Validate::require_string(
				Http::field( $body, 'name' ),
				'name',
				array(
					'min'  => 1,
					'max'  => Config::limit( 'AI_PARTNER_NAME_MAX' ),
					'trim' => true,
				)
			);
		}

		// avatarEmoji：0-4 字，默认 🤖。
		if ( Http::has( $body, 'avatarEmoji' ) ) {
			$emoji              = Validate::validate_avatar_emoji( Http::field( $body, 'avatarEmoji' ) );
			$out['avatarEmoji'] = ( null === $emoji ) ? '🤖' : $emoji;
		}

		// persona：0-2000 字（系统提示词）。
		if ( Http::has( $body, 'persona' ) ) {
			$raw = Http::field( $body, 'persona' );
			if ( null === $raw ) {
				$out['persona'] = '';
			} else {
				if ( ! is_string( $raw ) ) {
					Validate::fail( 'persona 必须是字符串' );
				}
				if ( Validate::length( $raw ) > Config::limit( 'AI_PARTNER_PERSONA_MAX' ) ) {
					Validate::fail( 'persona 长度不能超过 ' . Config::limit( 'AI_PARTNER_PERSONA_MAX' ) . ' 个字符' );
				}
				$out['persona'] = $raw;
			}
		}

		// providerId：空或必须是已启用的供应商 id。
		$provider_id = null;
		if ( Http::has( $body, 'providerId' ) ) {
			$raw = Http::field( $body, 'providerId' );
			if ( null === $raw ) {
				$provider_id = '';
			} else {
				if ( ! is_string( $raw ) ) {
					Validate::fail( 'providerId 必须是字符串' );
				}
				$provider_id = trim( $raw );
				if ( Validate::length( $provider_id ) > 64 ) {
					Validate::fail( 'providerId 长度不能超过 64 个字符' );
				}
			}
		} elseif ( in_array( 'name', $required, true ) ) {
			$provider_id = '';
		}

		// model：空或必须是该供应商支持的模型名（长度 <= 64）。
		$model = null;
		if ( Http::has( $body, 'model' ) ) {
			$raw = Http::field( $body, 'model' );
			if ( null === $raw ) {
				$model = '';
			} else {
				if ( ! is_string( $raw ) ) {
					Validate::fail( 'model 必须是字符串' );
				}
				$model = trim( $raw );
				if ( Validate::length( $model ) > Config::limit( 'AI_PROVIDER_MODEL_MAX' ) ) {
					Validate::fail( 'model 长度不能超过 ' . Config::limit( 'AI_PROVIDER_MODEL_MAX' ) . ' 个字符' );
				}
			}
		}

		// 供应商必须是已启用的（§AI-2：providerId 不在可用列表 → 400 invalid_input）。
		$effective_provider = ( null !== $provider_id )
			? $provider_id
			: ( is_array( $current ) && isset( $current['providerId'] ) ? (string) $current['providerId'] : '' );

		if ( null !== $provider_id ) {
			if ( '' !== $provider_id ) {
				$prov = Db::get_ai_provider( $provider_id );
				if ( ! $prov || empty( $prov['enabled'] ) ) {
					Validate::fail( 'providerId 不在可用列表中' );
				}
			}
			$out['providerId'] = $provider_id;
		}

		if ( null !== $model ) {
			if ( '' !== $model && '' !== $effective_provider ) {
				$prov = Db::get_ai_provider( $effective_provider );
				if ( $prov && count( $prov['models'] ) > 0 && ! in_array( $model, $prov['models'], true ) ) {
					Validate::fail( 'model 不在该供应商支持的模型列表中' );
				}
			}
			$out['model'] = $model;
		}

		// temperature：0-2，默认 0.8。
		if ( Http::has( $body, 'temperature' ) ) {
			$raw = Http::field( $body, 'temperature' );
			if ( null === $raw ) {
				$out['temperature'] = 0.8;
			} else {
				if ( ! is_int( $raw ) && ! is_float( $raw ) ) {
					Validate::fail( 'temperature 必须是数字' );
				}
				$t = (float) $raw;
				if ( ! is_finite( $t ) || $t < 0 || $t > 2 ) {
					Validate::fail( 'temperature 必须在 0 到 2 之间' );
				}
				$out['temperature'] = $t;
			}
		} elseif ( in_array( 'name', $required, true ) ) {
			$out['temperature'] = 0.8;
		}

		// enabled：默认 true。
		if ( Http::has( $body, 'enabled' ) ) {
			$out['enabled'] = Validate::optional_bool( Http::field( $body, 'enabled' ), 'enabled', true );
		} elseif ( in_array( 'name', $required, true ) ) {
			$out['enabled'] = true;
		}

		return $out;
	}

	/**
	 * 组装上游 messages：persona 作为 system prompt + 最近 20 条上下文（§AI-4）。
	 *
	 * @param array<string,mixed> $partner    AI 伙伴。
	 * @param string              $owner_id   用户 id。
	 * @param string              $partner_id AI 伙伴 id。
	 * @return array<int,array{role:string,content:string}>
	 */
	private static function build_upstream_messages( array $partner, $owner_id, $partner_id ) {
		$out     = array();
		$persona = isset( $partner['persona'] ) ? trim( (string) $partner['persona'] ) : '';
		if ( '' !== $persona ) {
			$out[] = array(
				'role'    => 'system',
				'content' => $persona,
			);
		}

		$recent = Db::get_recent_ai_messages(
			array(
				'ownerId'   => $owner_id,
				'partnerId' => $partner_id,
				'limit'     => Config::limit( 'AI_CHAT_CONTEXT_MAX' ),
			)
		);
		foreach ( $recent as $m ) {
			$out[] = array(
				'role'    => ( 'ai' === $m['senderId'] ) ? 'assistant' : 'user',
				'content' => (string) $m['body'],
			);
		}
		return $out;
	}

	/**
	 * 幂等结果：同 clientId 已存在用户消息 + AI 回复时返回上次结果。
	 *
	 * @param string $owner_id   用户 id。
	 * @param string $partner_id AI 伙伴 id。
	 * @param string $client_id  客户端幂等 id。
	 * @return array<string,mixed>|null
	 */
	private static function cached_result( $owner_id, $partner_id, $client_id ) {
		$message = Db::find_ai_message_by_client_id( $owner_id, $client_id );
		if ( ! $message ) {
			return null;
		}
		$reply = Db::find_ai_reply_for_client_id( $owner_id, $partner_id, $client_id );
		if ( ! $reply ) {
			// 上次上游调用没走完：让客户端重试（会重新调用大模型）。
			return null;
		}
		return array(
			'ok'      => true,
			'message' => $message,
			'reply'   => $reply,
			'usage'   => array(
				'provider'         => '',
				'model'            => '',
				'promptTokens'     => 0,
				'completionTokens' => 0,
			),
		);
	}
}
