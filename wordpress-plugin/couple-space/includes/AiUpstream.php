<?php
/**
 * 多供应商大模型适配层（docs/API-AI聊天.md §AI-5）。
 *
 * 支持的 type：
 *   - `openai`    POST {baseUrl}/chat/completions      （Bearer 鉴权，解析 choices[0].message.content）
 *   - `deepseek`  openai 的预设（默认 https://api.deepseek.com）
 *   - `anthropic` POST {baseUrl}/v1/messages           （x-api-key + anthropic-version，解析 content[0].text）
 *   - `gemini`    POST {baseUrl}/v1beta/models/{model}:generateContent?key=...
 *
 * 错误映射（§AI-4 表格，逐条对应）：
 *   - 上游 401 / 403                -> 502 upstream_error（AI 供应商鉴权失败，请检查 Key）
 *   - 上游超时 / 网络错误           -> 504 upstream_timeout（AI 供应商响应超时，请重试）
 *   - 上游 429 / 5xx / 其它非 2xx   -> 502 upstream_error（摘要 <= 200 字）
 *   - 没有可用供应商                -> 403 ai_not_configured
 *
 * 安全：API Key 只在服务端使用，绝不进入任何 API 响应；上游完整响应体也不回传
 * （只把它截断成 <= 200 字的摘要用于错误提示）。
 *
 * @package CoupleSpace
 */

namespace CoupleSpace;

defined( 'ABSPATH' ) || exit;

/**
 * 供应商类型白名单与适配器。
 */
class AiUpstream {

	/** 允许的供应商类型（§AI-5 结构）。 */
	const TYPES = array( 'openai', 'deepseek', 'anthropic', 'gemini' );

	/** 输出 token 上限的兜底值（Anthropic 的 max_tokens 必填）。 */
	const DEFAULT_MAX_TOKENS = 2048;

	/**
	 * 归一化类型：未知 / 空值一律当 openai 兼容处理。
	 *
	 * @param mixed $type 类型。
	 * @return string
	 */
	public static function normalize_type( $type ) {
		$t = is_string( $type ) ? strtolower( trim( $type ) ) : '';
		return in_array( $t, self::TYPES, true ) ? $t : 'openai';
	}

	/**
	 * 由 baseUrl 推出请求地址（§AI-5 的路径规则）。
	 *
	 * §AI-5 里 openai 写的是 `{baseUrl}/chat/completions`，而 deepseek 的示例 baseUrl 是
	 * `https://api.deepseek.com/v1`，两者要同时成立，只能允许「带版本段」与「不带版本段」
	 * 两种写法：这里只在**缺失**版本段时补上（openai/deepseek 补 /v1，gemini 补 /v1beta）。
	 *
	 * Base URL 上已带的查询串会被保留并附加在路径之后（例如 `http://host/v1?token=abc`
	 * 拼成 `http://host/v1/chat/completions?token=abc`，而不是错误的 `...?token=abc/chat/completions`）。
	 *
	 * @param string $type     类型。
	 * @param string $base_url 后台填写的 Base URL（可为空）。
	 * @param string $model    模型名（gemini 要拼进路径）。
	 * @return string
	 */
	public static function endpoint_url( $type, $base_url, $model = '' ) {
		$type = self::normalize_type( $type );
		$base = trim( (string) $base_url );
		$query = '';
		if ( '' !== $base ) {
			$qpos = strpos( $base, '?' );
			if ( false !== $qpos ) {
				$query = substr( $base, $qpos );
				$base  = substr( $base, 0, $qpos );
			}
		}
		$base = rtrim( $base, '/' );
		if ( '' === $base ) {
			$base = rtrim( Db::default_base_url_for_type( $type ), '/' );
		}

		$path = '';
		if ( 'anthropic' === $type ) {
			if ( ! preg_match( '#/v\d+$#', $base ) ) {
				$base .= '/v1';
			}
			$path = '/messages';
		} elseif ( 'gemini' === $type ) {
			if ( ! preg_match( '#/v\d+(?:beta|alpha)?$#', $base ) ) {
				$base .= '/v1beta';
			}
			$path = '/models/' . rawurlencode( (string) $model ) . ':generateContent';
		} else {
			// openai / deepseek。
			if ( ! preg_match( '#/v\d+$#', $base ) ) {
				$base .= '/v1';
			}
			$path = '/chat/completions';
		}

		return $base . $path . $query;
	}

	/**
	 * 组装请求（URL + 头 + body），便于单测与排查（**不含 Key 的输出路径**）。
	 *
	 * @param array<string,mixed> $provider 供应商对象（含明文 Key 时用 apiKey 字段覆盖）。
	 * @param array<int,mixed>    $messages 形如 [['role'=>'user','content'=>'...']]。
	 * @param string              $model    模型名。
	 * @param float               $temperature 温度。
	 * @param int                 $max_tokens 输出上限。
	 * @return array{url:string,headers:array<string,string>,body:string}
	 */
	public static function build_request( array $provider, array $messages, $model, $temperature, $max_tokens = self::DEFAULT_MAX_TOKENS ) {
		$type     = self::normalize_type( isset( $provider['type'] ) ? $provider['type'] : 'openai' );
		$base_url = isset( $provider['baseUrl'] ) ? (string) $provider['baseUrl'] : '';
		$api_key  = isset( $provider['apiKey'] ) ? (string) $provider['apiKey'] : '';
		$url      = self::endpoint_url( $type, $base_url, $model );
		$headers  = array( 'Content-Type' => 'application/json' );

		if ( 'anthropic' === $type ) {
			$headers['x-api-key']           = $api_key;
			$headers['anthropic-version']   = '2023-06-01';
		} elseif ( 'gemini' === $type ) {
			$url .= ( false === strpos( $url, '?' ) ? '?' : '&' ) . 'key=' . rawurlencode( $api_key );
		} else {
			$headers['Authorization'] = 'Bearer ' . $api_key;
		}

		return array(
			'url'     => $url,
			'headers' => $headers,
			'body'    => (string) self::encode_body( $type, $messages, $model, $temperature, $max_tokens ),
		);
	}

	/**
	 * 按类型组装 JSON body。
	 *
	 * @param string           $type        类型。
	 * @param array<int,mixed> $messages    消息数组。
	 * @param string           $model       模型名。
	 * @param float            $temperature 温度。
	 * @param int              $max_tokens  输出上限。
	 * @return string JSON。
	 */
	private static function encode_body( $type, array $messages, $model, $temperature, $max_tokens ) {
		$temp = (float) $temperature;

		if ( 'anthropic' === $type ) {
			// Anthropic：system 独立成字段，其余 role 只允许 user / assistant。
			$system  = '';
			$turns   = array();
			foreach ( $messages as $m ) {
				$role    = isset( $m['role'] ) ? (string) $m['role'] : 'user';
				$content = isset( $m['content'] ) ? (string) $m['content'] : '';
				if ( 'system' === $role ) {
					$system = ( '' === $system ) ? $content : ( $system . "\n" . $content );
					continue;
				}
				$turns[] = array(
					'role'    => ( 'assistant' === $role ) ? 'assistant' : 'user',
					'content' => $content,
				);
			}
			if ( 0 === count( $turns ) ) {
				$turns[] = array(
					'role'    => 'user',
					'content' => '你好',
				);
			}
			$payload = array(
				'model'       => (string) $model,
				'max_tokens'  => max( 1, (int) $max_tokens ),
				'messages'    => $turns,
				'temperature' => $temp,
			);
			if ( '' !== $system ) {
				$payload['system'] = $system;
			}
			return (string) wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}

		if ( 'gemini' === $type ) {
			// Gemini：contents[].parts[].text，system 走 systemInstruction。
			$system = '';
			$items  = array();
			foreach ( $messages as $m ) {
				$role    = isset( $m['role'] ) ? (string) $m['role'] : 'user';
				$content = isset( $m['content'] ) ? (string) $m['content'] : '';
				if ( 'system' === $role ) {
					$system = ( '' === $system ) ? $content : ( $system . "\n" . $content );
					continue;
				}
				$items[] = array(
					'role'  => ( 'assistant' === $role ) ? 'model' : 'user',
					'parts' => array( array( 'text' => $content ) ),
				);
			}
			if ( 0 === count( $items ) ) {
				$items[] = array(
					'role'  => 'user',
					'parts' => array( array( 'text' => '你好' ) ),
				);
			}
			$payload = array(
				'contents'         => $items,
				'generationConfig' => array(
					'temperature'     => $temp,
					'maxOutputTokens' => max( 1, (int) $max_tokens ),
				),
			);
			if ( '' !== $system ) {
				$payload['systemInstruction'] = array( 'parts' => array( array( 'text' => $system ) ) );
			}
			return (string) wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}

		// openai / deepseek（OpenAI 兼容）。
		return (string) wp_json_encode(
			array(
				'model'       => (string) $model,
				'messages'    => array_values( $messages ),
				'stream'      => false,
				'temperature' => $temp,
				'max_tokens'  => max( 1, (int) $max_tokens ),
			),
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);
	}

	/**
	 * 从上游响应里解析文本与 usage。
	 *
	 * @param string $type 类型。
	 * @param mixed  $json 已解码的响应。
	 * @return array{content:string,model:string,promptTokens:int,completionTokens:int}
	 */
	public static function parse_response( $type, $json ) {
		$type    = self::normalize_type( $type );
		$content = '';
		$model   = '';
		$prompt  = 0;
		$out     = 0;

		if ( ! is_array( $json ) ) {
			return array(
				'content'          => '',
				'model'            => '',
				'promptTokens'     => 0,
				'completionTokens' => 0,
			);
		}

		if ( 'anthropic' === $type ) {
			if ( isset( $json['content'] ) && is_array( $json['content'] ) ) {
				foreach ( $json['content'] as $block ) {
					if ( is_array( $block ) && isset( $block['text'] ) && is_string( $block['text'] ) ) {
						$content .= $block['text'];
					}
				}
			}
			$model = isset( $json['model'] ) && is_string( $json['model'] ) ? $json['model'] : '';
			if ( isset( $json['usage'] ) && is_array( $json['usage'] ) ) {
				$prompt = isset( $json['usage']['input_tokens'] ) ? (int) $json['usage']['input_tokens'] : 0;
				$out    = isset( $json['usage']['output_tokens'] ) ? (int) $json['usage']['output_tokens'] : 0;
			}
			return array(
				'content'          => $content,
				'model'            => $model,
				'promptTokens'     => $prompt,
				'completionTokens' => $out,
			);
		}

		if ( 'gemini' === $type ) {
			if ( isset( $json['candidates'][0]['content']['parts'] ) && is_array( $json['candidates'][0]['content']['parts'] ) ) {
				foreach ( $json['candidates'][0]['content']['parts'] as $part ) {
					if ( is_array( $part ) && isset( $part['text'] ) && is_string( $part['text'] ) ) {
						$content .= $part['text'];
					}
				}
			}
			$model = isset( $json['modelVersion'] ) && is_string( $json['modelVersion'] ) ? $json['modelVersion'] : '';
			if ( isset( $json['usageMetadata'] ) && is_array( $json['usageMetadata'] ) ) {
				$prompt = isset( $json['usageMetadata']['promptTokenCount'] ) ? (int) $json['usageMetadata']['promptTokenCount'] : 0;
				$out    = isset( $json['usageMetadata']['candidatesTokenCount'] ) ? (int) $json['usageMetadata']['candidatesTokenCount'] : 0;
			}
			return array(
				'content'          => $content,
				'model'            => $model,
				'promptTokens'     => $prompt,
				'completionTokens' => $out,
			);
		}

		// openai / deepseek。
		if ( isset( $json['choices'][0]['message']['content'] ) && is_string( $json['choices'][0]['message']['content'] ) ) {
			$content = $json['choices'][0]['message']['content'];
		}
		$model = isset( $json['model'] ) && is_string( $json['model'] ) ? $json['model'] : '';
		if ( isset( $json['usage'] ) && is_array( $json['usage'] ) ) {
			$prompt = isset( $json['usage']['prompt_tokens'] ) ? (int) $json['usage']['prompt_tokens'] : 0;
			$out    = isset( $json['usage']['completion_tokens'] ) ? (int) $json['usage']['completion_tokens'] : 0;
		}
		return array(
			'content'          => $content,
			'model'            => $model,
			'promptTokens'     => $prompt,
			'completionTokens' => $out,
		);
	}

	/**
	 * 调用供应商。
	 *
	 * @param array<string,mixed> $provider    供应商对象（含 name / type / baseUrl / apiKey）。
	 * @param array<int,mixed>    $messages    消息数组（含 system）。
	 * @param array<string,mixed> $opts        model / temperature / maxTokens / timeout / maxRetries。
	 * @return array{content:string,model:string,promptTokens:int,completionTokens:int,latencyMs:int,httpStatus:int}
	 * @throws AiError 鉴权/超时/其它上游错误。
	 */
	public static function chat( array $provider, array $messages, array $opts = array() ) {
		$type        = self::normalize_type( isset( $provider['type'] ) ? $provider['type'] : 'openai' );
		$api_key     = isset( $provider['apiKey'] ) ? (string) $provider['apiKey'] : '';
		$model       = isset( $opts['model'] ) ? trim( (string) $opts['model'] ) : '';
		$temperature = isset( $opts['temperature'] ) ? (float) $opts['temperature'] : 0.8;
		$max_tokens  = isset( $opts['maxTokens'] ) ? (int) $opts['maxTokens'] : self::DEFAULT_MAX_TOKENS;
		$timeout     = isset( $opts['timeout'] ) ? (int) $opts['timeout'] : Config::limit( 'AI_UPSTREAM_TIMEOUT' );
		// 契约要求「超时 -> 504」，所以默认不重试：重试会让 App 等 2~3 倍超时时间。
		$max_retries = isset( $opts['maxRetries'] ) ? (int) $opts['maxRetries'] : 0;

		if ( '' === $api_key ) {
			throw new AiError( 'AI 未配置，请到服务端后台添加供应商', 'ai_not_configured', 403 );
		}
		if ( '' === $model ) {
			throw new AiError( '供应商未配置可用模型，请到服务端后台设置默认模型', 'ai_not_configured', 403 );
		}

		$req          = self::build_request( $provider, $messages, $model, $temperature, $max_tokens );
		$last_error   = null;
		$provider_name = isset( $provider['name'] ) && '' !== (string) $provider['name'] ? (string) $provider['name'] : 'AI 供应商';

		/*
		 * PHP 默认 max_execution_time 是 30 秒，与「上游超时阈值 30 秒」正好撞在一起：
		 * 不放开的话，慢上游会先被 PHP 判成致命错误（500），而不是我们要的 504 upstream_timeout。
		 * 这里给足余量（超时值 + 缓冲），保证超时能由 wp_remote_post 触发并被正确映射。
		 */
		$limit = $timeout + 20;
		if ( function_exists( 'set_time_limit' ) && false === strpos( (string) ini_get( 'disable_functions' ), 'set_time_limit' ) ) {
			@set_time_limit( $limit ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.IniSet.MaxExecutionTime_Blacklisted
		}

		for ( $attempt = 0; $attempt <= $max_retries; $attempt++ ) {
			$started = microtime( true );
			$response = wp_remote_post(
				$req['url'],
				array(
					'timeout'     => $timeout,
					'redirection' => 0,
					'sslverify'   => true,
					'headers'     => $req['headers'],
					'body'        => $req['body'],
				)
			);
			$latency = (int) round( ( microtime( true ) - $started ) * 1000 );

			if ( is_wp_error( $response ) ) {
				$msg = (string) $response->get_error_message();
				// §AI-4：供应商超时 / 网络错误 -> 504 upstream_timeout。
				Log::warn(
					'AI 供应商调用失败（网络）',
					array(
						'provider' => $provider_name,
						'error'    => $msg,
					)
				);
				throw new AiError( 'AI 供应商响应超时，请重试', 'upstream_timeout', 504 );
			}

			$status = (int) wp_remote_retrieve_response_code( $response );
			$text   = (string) wp_remote_retrieve_body( $response );

			if ( 401 === $status || 403 === $status ) {
				Log::warn( 'AI 供应商鉴权失败', array( 'provider' => $provider_name, 'status' => $status ) );
				throw new AiError( 'AI 供应商鉴权失败，请检查 Key', 'upstream_error', 502 );
			}

			if ( $status < 200 || $status >= 300 ) {
				$summary = self::summarize_upstream_error( $text );
				Log::warn(
					'AI 供应商返回错误',
					array(
						'provider' => $provider_name,
						'status'   => $status,
					)
				);
				$last_error = new AiError(
					( 429 === $status ? 'AI 供应商限流：' : 'AI 供应商返回错误：' ) . $summary,
					'upstream_error',
					502
				);
				if ( $attempt < $max_retries ) {
					continue;
				}
				throw $last_error;
			}

			$json = json_decode( $text, true );
			if ( ! is_array( $json ) ) {
				$last_error = new AiError(
					'AI 供应商返回内容无法解析：' . self::summarize_upstream_error( $text ),
					'upstream_error',
					502
				);
				if ( $attempt < $max_retries ) {
					continue;
				}
				throw $last_error;
			}

			$parsed = self::parse_response( $type, $json );
			// 上游返回空内容不报错：调用方落库一条「（AI 没有返回内容）」并返回 200（§AI-4）。
			return array(
				'content'          => trim( (string) $parsed['content'] ),
				'model'            => ( '' !== $parsed['model'] ) ? $parsed['model'] : $model,
				'promptTokens'     => (int) $parsed['promptTokens'],
				'completionTokens' => (int) $parsed['completionTokens'],
				'latencyMs'        => $latency,
				'httpStatus'       => $status,
			);
		}

		throw ( $last_error ? $last_error : new AiError( 'AI 供应商调用失败', 'upstream_error', 502 ) );
	}

	/**
	 * 上游错误摘要（<= 200 字，§AI-4）。**绝不**回传完整响应体。
	 *
	 * @param string $text 上游响应体。
	 * @return string
	 */
	public static function summarize_upstream_error( $text ) {
		$s = trim( (string) $text );
		if ( '' === $s ) {
			return '';
		}
		$json = json_decode( $s, true );
		if ( is_array( $json ) && isset( $json['error'] ) ) {
			$err = $json['error'];
			if ( is_string( $err ) ) {
				$s = $err;
			} elseif ( is_array( $err ) && isset( $err['message'] ) && is_string( $err['message'] ) ) {
				$s = $err['message'];
			}
		}
		return mb_substr( $s, 0, Config::limit( 'AI_UPSTREAM_ERROR_MAX' ), 'UTF-8' );
	}
}
