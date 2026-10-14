<?php
/**
 * WordPress 后台：AI 供应商管理 + AI 聊天设置（docs/API-AI聊天.md §AI-5）。
 *
 * 权限与 CSRF 沿用现有写法：current_user_can('manage_options') + check_admin_referer()。
 * API Key 只显示掩码，任何页面/表单回显都不出现明文（留空 = 不修改，勾选 = 清空）。
 *
 * @package CoupleSpace
 */

namespace CoupleSpace;

defined( 'ABSPATH' ) || exit;

/**
 * AI 供应商与 AI 聊天后台。
 */
class AiAdmin {

	/** 供应商名称长度上限。 */
	const NAME_MAX = 64;

	/**
	 * 解析「每行一个或逗号分隔」的模型列表。
	 *
	 * @param mixed $raw 原始输入。
	 * @return array<int,string>
	 */
	public static function parse_models( $raw ) {
		$text = is_string( $raw ) ? $raw : '';
		$text = str_replace( array( "\r\n", "\r", '，', '、', ';', '；' ), "\n", $text );
		$text = str_replace( ',', "\n", $text );
		$out  = array();
		foreach ( explode( "\n", $text ) as $line ) {
			$m = trim( $line );
			if ( '' === $m || in_array( $m, $out, true ) ) {
				continue;
			}
			if ( Validate::length( $m ) > Config::limit( 'AI_PROVIDER_MODEL_MAX' ) ) {
				continue;
			}
			$out[] = $m;
		}
		return $out;
	}

	/**
	 * 类型中文名。
	 *
	 * @param string $type 类型。
	 * @return string
	 */
	public static function type_label( $type ) {
		switch ( AiUpstream::normalize_type( $type ) ) {
			case 'deepseek':
				return 'DeepSeek';
			case 'anthropic':
				return 'Anthropic';
			case 'gemini':
				return 'Gemini';
			case 'openai':
			default:
				return 'OpenAI 兼容';
		}
	}

	// ------------------------------------------------------------------
	// 页面
	// ------------------------------------------------------------------

	/** AI 供应商页。 */
	public static function page_providers() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '你没有权限访问该页面。', '权限不足', array( 'response' => 403 ) );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- 只读的「编辑哪一条」参数。
		$edit_id = isset( $_GET['edit'] ) ? sanitize_text_field( wp_unslash( $_GET['edit'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$providers = Db::list_ai_providers( false );
		$editing   = null;
		if ( '' !== $edit_id ) {
			$editing = Db::get_ai_provider( $edit_id );
		}

		Admin::view_public(
			'ai-providers',
			array(
				'providers' => $providers,
				'editing'   => $editing,
				'default'   => Db::get_default_ai_provider(),
			)
		);
	}

	/** AI 聊天页（总开关 / 每日上限 / 默认供应商与模型）。 */
	public static function page_chat() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '你没有权限访问该页面。', '权限不足', array( 'response' => 403 ) );
		}
		$settings = Settings::for_admin();
		Admin::view_public(
			'ai-chat',
			array(
				'settings'  => $settings,
				'providers' => Db::list_ai_providers( true ),
				'stats'     => array(
					'partners'   => Db::count_all_ai_partners(),
					'messages'   => Db::count_all_ai_messages(),
					'providers'  => Db::count_ai_providers(),
					'enabled'    => Db::count_enabled_ai_providers(),
					'recentMsgs' => Db::count_all_ai_messages(),
				),
			)
		);
	}

	// ------------------------------------------------------------------
	// 表单处理
	// ------------------------------------------------------------------

	/** 保存供应商（新增 / 编辑）。 */
	public static function handle_provider_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '你没有权限执行该操作。', '权限不足', array( 'response' => 403 ) );
		}
		check_admin_referer( 'couple_space_ai_provider' );

		$id   = isset( $_POST['providerId'] ) ? sanitize_text_field( wp_unslash( $_POST['providerId'] ) ) : '';
		$name = isset( $_POST['name'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['name'] ) ) ) : '';
		$type = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : 'openai';
		$base = isset( $_POST['baseUrl'] ) ? trim( esc_url_raw( (string) wp_unslash( $_POST['baseUrl'] ) ) ) : '';
		$list = isset( $_POST['models'] ) ? (string) wp_unslash( $_POST['models'] ) : '';
		$dmod = isset( $_POST['defaultModel'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['defaultModel'] ) ) ) : '';
		$on   = ! empty( $_POST['enabled'] );
		$def  = ! empty( $_POST['isDefault'] );

		if ( '' === $name || Validate::length( $name ) > self::NAME_MAX ) {
			Admin::redirect_public( 'ai-providers', '供应商名称长度必须是 1-' . self::NAME_MAX . ' 个字符。', 'error' );
		}
		if ( ! in_array( $type, AiUpstream::TYPES, true ) ) {
			Admin::redirect_public( 'ai-providers', '供应商类型只支持 openai / deepseek / anthropic / gemini。', 'error' );
		}
		if ( '' !== $base && ! preg_match( '#^https?://#i', $base ) ) {
			Admin::redirect_public( 'ai-providers', 'Base URL 必须是 http(s):// 开头的地址。', 'error' );
		}

		$models = self::parse_models( $list );
		if ( '' !== $dmod && ! in_array( $dmod, $models, true ) ) {
			$models[] = $dmod;
		}
		if ( '' === $dmod && count( $models ) > 0 ) {
			$dmod = (string) $models[0];
		}

		// Key：留空 = 不修改；勾选 clearApiKey = 清空。
		$key_raw = isset( $_POST['apiKey'] ) ? trim( (string) wp_unslash( $_POST['apiKey'] ) ) : '';
		$clear   = ! empty( $_POST['clearApiKey'] );

		$fields = array(
			'name'         => $name,
			'type'         => $type,
			'baseUrl'      => $base,
			'models'       => $models,
			'defaultModel' => $dmod,
			'enabled'      => $on,
		);

		if ( '' !== $id ) {
			$existing = Db::get_ai_provider( $id );
			if ( ! $existing ) {
				Admin::redirect_public( 'ai-providers', '供应商不存在。', 'error' );
			}
			if ( $clear ) {
				$fields['apiKeyEnc'] = '';
			} elseif ( '' !== $key_raw ) {
				$fields['apiKeyEnc'] = Settings::encrypt_secret( $key_raw );
			}
			if ( $def ) {
				$fields['isDefault'] = true;
			}
			Db::update_ai_provider( $id, $fields );
			if ( $def ) {
				Db::set_default_ai_provider( $id );
			} elseif ( ! empty( $existing['isDefault'] ) ) {
				// 取消默认后自动改选另一个（没有则置空）。
				Db::reselect_default_ai_provider();
			}
			Auth::log_admin_action( 'wp.ai.provider.update', $name . '（' . $id . '）' );
			Admin::redirect_public( 'ai-providers', '供应商「' . $name . '」已保存。' );
		}

		$fields['apiKeyEnc'] = ( $clear || '' === $key_raw ) ? '' : Settings::encrypt_secret( $key_raw );
		$created             = Db::create_ai_provider( $fields );
		if ( ! $created ) {
			Admin::redirect_public( 'ai-providers', '供应商保存失败，请重试。', 'error' );
		}
		// 显式勾选「设为默认」，或当前还没有默认供应商时，都把它设为默认。
		if ( $def || null === Db::get_default_ai_provider() ) {
			Db::set_default_ai_provider( $created['id'] );
		}
		Auth::log_admin_action( 'wp.ai.provider.create', $name . '（' . $created['id'] . '）' );
		Admin::redirect_public( 'ai-providers', '供应商「' . $name . '」已添加。' );
	}

	/** 设为 / 取消默认供应商。 */
	public static function handle_provider_default() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '你没有权限执行该操作。', '权限不足', array( 'response' => 403 ) );
		}
		check_admin_referer( 'couple_space_ai_provider' );

		$id = isset( $_POST['providerId'] ) ? sanitize_text_field( wp_unslash( $_POST['providerId'] ) ) : '';
		if ( '' !== $id ) {
			$prov = Db::get_ai_provider( $id );
			if ( ! $prov ) {
				Admin::redirect_public( 'ai-providers', '供应商不存在。', 'error' );
			}
			if ( ! empty( $prov['isDefault'] ) ) {
				// 取消默认：自动改选另一个（没有则没有默认）。
				Db::set_default_ai_provider( '' );
				$next = Db::reselect_default_ai_provider();
				Auth::log_admin_action( 'wp.ai.provider.default', '取消默认 ' . $prov['name'] . '，改选 ' . $next );
				Admin::redirect_public( 'ai-providers', '已取消默认供应商，当前默认：' . ( '' !== $next ? $next : '无' ) . '。' );
			}
			Db::set_default_ai_provider( $id );
			Auth::log_admin_action( 'wp.ai.provider.default', '设为默认：' . $prov['name'] );
			Admin::redirect_public( 'ai-providers', '已把「' . $prov['name'] . '」设为默认供应商。' );
		}
		Admin::redirect_public( 'ai-providers', '缺少供应商 id。', 'error' );
	}

	/** 删除供应商（删除默认后自动改选）。 */
	public static function handle_provider_delete() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '你没有权限执行该操作。', '权限不足', array( 'response' => 403 ) );
		}
		check_admin_referer( 'couple_space_ai_provider' );

		$id = isset( $_POST['providerId'] ) ? sanitize_text_field( wp_unslash( $_POST['providerId'] ) ) : '';
		if ( '' === $id ) {
			Admin::redirect_public( 'ai-providers', '缺少供应商 id。', 'error' );
		}
		$prov = Db::get_ai_provider( $id );
		if ( ! $prov ) {
			Admin::redirect_public( 'ai-providers', '供应商不存在。', 'error' );
		}

		Db::delete_ai_provider( $id );
		$new_default = Db::reselect_default_ai_provider();
		Auth::log_admin_action( 'wp.ai.provider.delete', $prov['name'] . '（' . $id . '）' );
		Admin::redirect_public(
			'ai-providers',
			'已删除供应商「' . $prov['name'] . '」；当前默认：' . ( '' !== $new_default ? $new_default : '无' )
		);
	}

	/** 测试供应商：发一句「你好」，显示回复与耗时。 */
	public static function handle_provider_test() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '你没有权限执行该操作。', '权限不足', array( 'response' => 403 ) );
		}
		check_admin_referer( 'couple_space_ai_provider' );

		$id = isset( $_POST['providerId'] ) ? sanitize_text_field( wp_unslash( $_POST['providerId'] ) ) : '';
		$id = '' !== $id ? $id : Settings::ai_default_provider_id();

		$row      = '' !== $id ? Db::get_ai_provider_row( $id ) : null;
		if ( ! $row ) {
			Admin::redirect_public( 'ai-providers', '没有可测试的供应商，请先添加并保存。', 'error' );
		}
		$provider = Db::map_ai_provider( $row );
		$key      = Settings::decrypt_secret( isset( $row['api_key_enc'] ) ? $row['api_key_enc'] : '' );
		if ( '' === $key ) {
			Admin::redirect_public( 'ai-providers', '该供应商还没有配置 API Key，请先填写并保存。', 'error' );
		}

		$model = (string) $provider['defaultModel'];
		if ( '' === $model && isset( $provider['models'][0] ) ) {
			$model = (string) $provider['models'][0];
		}
		if ( '' === $model ) {
			Admin::redirect_public( 'ai-providers', '该供应商没有可用模型，请先填写模型列表与默认模型。', 'error' );
		}

		$provider['apiKey'] = $key;
		$started            = microtime( true );
		try {
			$res = AiUpstream::chat(
				$provider,
				array(
					array(
						'role'    => 'system',
						'content' => '你是一个友好的中文助手，回答尽量简短。',
					),
					array(
						'role'    => 'user',
						'content' => '你好',
					),
				),
				array(
					'model'     => $model,
					'temperature' => 0.7,
					'timeout'   => Config::limit( 'AI_UPSTREAM_TIMEOUT' ),
				)
			);
		} catch ( AiError $e ) {
			Auth::log_admin_action( 'wp.ai.provider.test.failed', $provider['name'] . '：' . $e->getMessage() );
			Admin::redirect_public( 'ai-providers', '测试失败（' . $provider['name'] . '）：' . $e->getMessage(), 'error' );
			return;
		} catch ( \Throwable $e ) {
			Auth::log_admin_action( 'wp.ai.provider.test.failed', $provider['name'] . '：' . $e->getMessage() );
			Admin::redirect_public( 'ai-providers', '测试失败（' . $provider['name'] . '）：' . $e->getMessage(), 'error' );
			return;
		}

		$latency = (int) round( ( microtime( true ) - $started ) * 1000 );
		$reply   = trim( (string) $res['content'] );
		if ( '' === $reply ) {
			$reply = '（供应商没有返回内容）';
		}
		$reply = mb_substr( $reply, 0, 200, 'UTF-8' );
		Auth::log_admin_action( 'wp.ai.provider.test', $provider['name'] . '，耗时 ' . $latency . 'ms' );
		Admin::redirect_public(
			'ai-providers',
			'测试成功（' . $provider['name'] . ' / ' . $res['model'] . '，耗时 ' . $latency . 'ms）：' . $reply
		);
	}

	/** 保存 AI 聊天设置（总开关 / 每日上限 / 默认供应商与模型）。 */
	public static function handle_chat_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '你没有权限执行该操作。', '权限不足', array( 'response' => 403 ) );
		}
		check_admin_referer( 'couple_space_ai_chat' );

		$provider_id = isset( $_POST['aiProviderId'] ) ? sanitize_text_field( wp_unslash( $_POST['aiProviderId'] ) ) : '';
		$model       = isset( $_POST['aiProviderModel'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['aiProviderModel'] ) ) ) : '';

		if ( '' !== $provider_id ) {
			$prov = Db::get_ai_provider( $provider_id );
			if ( ! $prov ) {
				Admin::redirect_public( 'ai-chat', '默认供应商不存在。', 'error' );
			}
			if ( '' !== $model && count( $prov['models'] ) > 0 && ! in_array( $model, $prov['models'], true ) ) {
				Admin::redirect_public( 'ai-chat', '默认模型不在该供应商的模型列表中。', 'error' );
			}
		}

		$fields = array(
			'aiChatEnabled'   => ! empty( $_POST['aiChatEnabled'] ),
			'aiEnabled'       => ! empty( $_POST['aiEnabled'] ),
			'aiDailyLimit'    => isset( $_POST['aiDailyLimit'] ) ? max( 0, (int) $_POST['aiDailyLimit'] ) : 200,
			'aiProviderId'    => $provider_id,
			'aiProviderModel' => $model,
		);
		Settings::update( $fields );
		Auth::log_admin_action( 'wp.ai.chat.settings', 'AI 聊天设置已保存' );
		Admin::redirect_public( 'ai-chat', 'AI 聊天设置已保存。' );
	}
}
