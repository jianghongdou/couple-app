<?php
/**
 * DeepSeek 服务：调用大模型、超时/重试、JSON 容错解析与字段强校验。
 *
 * 移植自 server-php/src/DeepSeek.php。唯一差异：HTTP 调用改用 WordPress 的
 * wp_remote_post()（走 WP 的 HTTP API，尊重站点代理设置、可被 WP 过滤器拦截），
 * 而不是裸露的 cURL；错误映射、重试策略、容错解析、提示词都保持不变。
 *
 * 设计要点：
 *   1. Key 只从自建 settings 表读取（后台页面写入），客户端永远拿不到。
 *   2. 系统提示词要求模型「只输出 JSON」，但服务端仍做容错解析。
 *   3. 记账/月度报告只做「文本 → 结构化 JSON」转换，服务端不落库明细。
 *
 * @package CoupleSpace
 */

namespace CoupleSpace;

defined( 'ABSPATH' ) || exit;

/**
 * DeepSeek 代理。
 */
class DeepSeek {

	/** 请求超时（秒）。 */
	const REQUEST_TIMEOUT = 45;

	/** 最大重试次数（不含首次）。 */
	const MAX_RETRIES = 2;

	/** 记账分类建议取值（API.md §7）。 */
	const LEDGER_CATEGORIES = array( '餐饮', '交通', '购物', '娱乐', '居住', '医疗', '学习', '人情', '工资', '理财', '其它' );

	/**
	 * 调用 DeepSeek Chat Completions 接口。
	 *
	 * @param array<string,mixed> $opts messages / timeout / maxRetries / maxTokens / temperature / 覆盖 Key。
	 * @return array{content:string,model:string,usage:mixed}
	 */
	public static function chat_completion( array $opts ) {
		$messages     = $opts['messages'];
		$timeout      = isset( $opts['timeout'] ) ? (int) $opts['timeout'] : self::REQUEST_TIMEOUT;
		$max_retries  = isset( $opts['maxRetries'] ) ? (int) $opts['maxRetries'] : self::MAX_RETRIES;
		$max_tokens   = isset( $opts['maxTokens'] ) ? (int) $opts['maxTokens'] : 2048;
		$temperature  = isset( $opts['temperature'] ) ? $opts['temperature'] : 0.2;

		$settings = Settings::read_all();
		$api_key  = isset( $opts['apiKey'] ) && '' !== (string) $opts['apiKey']
			? (string) $opts['apiKey']
			: $settings['deepseekApiKey'];
		if ( ! is_string( $api_key ) || '' === $api_key ) {
			throw new AiError( 'AI 未配置', 'forbidden', 403 );
		}

		$base_url = isset( $opts['baseUrl'] ) && '' !== (string) $opts['baseUrl'] ? (string) $opts['baseUrl'] : (string) $settings['deepseekBaseUrl'];
		$model    = isset( $opts['model'] ) && '' !== (string) $opts['model'] ? (string) $opts['model'] : (string) $settings['deepseekModel'];
		$base_url = rtrim( '' !== $base_url ? $base_url : 'https://api.deepseek.com', '/' );
		$model    = '' !== $model ? $model : 'deepseek-chat';
		$url      = $base_url . '/chat/completions';

		$body = wp_json_encode(
			array(
				'model'       => $model,
				'messages'    => $messages,
				'stream'      => false,
				'temperature' => $temperature,
				'max_tokens'  => $max_tokens,
			),
			JSON_UNESCAPED_UNICODE
		);

		$last_error = null;
		for ( $attempt = 0; $attempt <= $max_retries; $attempt++ ) {
			try {
				$response = wp_remote_post(
					$url,
					array(
						'timeout'     => $timeout,
						'redirection' => 0,
						'sslverify'   => true,
						'headers'     => array(
							'Content-Type'  => 'application/json',
							'Authorization' => 'Bearer ' . $api_key,
						),
						'body'        => $body,
					)
				);

				if ( is_wp_error( $response ) ) {
					$err_msg     = $response->get_error_message();
					$is_timeout  = ( false !== stripos( $err_msg, 'timed out' ) || false !== stripos( $err_msg, 'timeout' ) );
					$last_error  = new AiError(
						$is_timeout ? '调用 DeepSeek 超时' : ( '调用 DeepSeek 失败：' . $err_msg ),
						'server_error',
						$is_timeout ? 504 : 502
					);
					if ( $attempt < $max_retries ) {
						Log::warn( 'DeepSeek 调用失败，准备重试', array( 'attempt' => $attempt + 1, 'error' => $err_msg ) );
						sleep( 1 );
						continue;
					}
					throw $last_error;
				}

				$status = (int) wp_remote_retrieve_response_code( $response );
				$text   = (string) wp_remote_retrieve_body( $response );

				if ( 401 === $status || 403 === $status ) {
					Log::warn( 'DeepSeek 鉴权失败', array( 'status' => $status ) );
					throw new AiError( 'DeepSeek API Key 无效或无权限', 'forbidden', 403 );
				}

				if ( $status < 200 || $status >= 300 ) {
					$last_error = new AiError(
						'DeepSeek 接口返回 ' . $status . ': ' . mb_substr( $text, 0, 300, 'UTF-8' ),
						( 429 === $status ) ? 'rate_limited' : 'server_error',
						( 429 === $status ) ? 429 : 502
					);
					$retryable = ( 429 === $status || $status >= 500 );
					if ( $attempt < $max_retries && $retryable ) {
						sleep( 1 );
						continue;
					}
					throw $last_error;
				}

				$json = json_decode( $text, true );
				if ( ! is_array( $json ) ) {
					throw new AiError( 'DeepSeek 返回内容不是合法 JSON', 'server_error', 502 );
				}
				$content = isset( $json['choices'][0]['message']['content'] ) ? $json['choices'][0]['message']['content'] : null;
				if ( ! is_string( $content ) || '' === trim( $content ) ) {
					$last_error = new AiError( 'DeepSeek 返回内容为空', 'server_error', 502 );
					if ( $attempt < $max_retries ) {
						sleep( 1 );
						continue;
					}
					throw $last_error;
				}
				return array(
					'content' => $content,
					'model'   => ( isset( $json['model'] ) && $json['model'] ) ? $json['model'] : $model,
					'usage'   => isset( $json['usage'] ) ? $json['usage'] : null,
				);
			} catch ( AiError $e ) {
				// 鉴权/限流错误不重试，直接抛出。
				if ( 403 === $e->get_status() || 429 === $e->get_status() ) {
					throw $e;
				}
				if ( $attempt >= $max_retries ) {
					throw $e;
				}
				$last_error = $e;
				sleep( 1 );
			}
		}

		throw $last_error ? $last_error : new AiError( '调用 DeepSeek 失败', 'server_error', 502 );
	}

	// ------------------------------------------------------------------
	// JSON 容错解析
	// ------------------------------------------------------------------

	/**
	 * 从模型输出中提取 JSON 文本：
	 *   1. 剥离 ```json ... ``` / ``` ... ``` 代码块
	 *   2. 截取首个 { 到最后一个 }
	 *
	 * @param mixed $text 模型输出。
	 * @return string|null
	 */
	public static function extract_json_text( $text ) {
		if ( ! is_string( $text ) ) {
			return null;
		}
		$s = trim( $text );
		if ( preg_match( '/```(?:json|JSON)?\s*([\s\S]*?)```/', $s, $m ) && isset( $m[1] ) ) {
			$s = trim( $m[1] );
		}
		$start = strpos( $s, '{' );
		$end   = strrpos( $s, '}' );
		if ( false === $start || false === $end || $end <= $start ) {
			return null;
		}
		return substr( $s, $start, $end - $start + 1 );
	}

	/**
	 * 容错解析模型输出为数组。
	 *
	 * @param mixed $text 模型输出。
	 * @return array<string,mixed>|null
	 */
	public static function parse_json_loose( $text ) {
		$candidate = self::extract_json_text( $text );
		if ( null === $candidate ) {
			return null;
		}
		$v = json_decode( $candidate, true );
		if ( is_array( $v ) ) {
			return $v;
		}
		// 常见修复：去掉尾随逗号。
		$no_trailing = preg_replace( '/,\s*([}\]])/', '$1', $candidate );
		if ( null !== $no_trailing ) {
			$v = json_decode( $no_trailing, true );
			if ( is_array( $v ) ) {
				return $v;
			}
			// 常见修复：中文全角引号。
			$normalized = str_replace( array( "\u{201c}", "\u{201d}" ), '"', $no_trailing );
			$normalized = str_replace( array( "\u{2018}", "\u{2019}" ), "'", $normalized );
			$v          = json_decode( $normalized, true );
			if ( is_array( $v ) ) {
				return $v;
			}
		}
		return null;
	}

	// ------------------------------------------------------------------
	// 字段强校验与兜底
	// ------------------------------------------------------------------

	/**
	 * 安全转数字（支持 "25"、"25.5"、"￥25.5"、"1,234.5"）。
	 *
	 * @param mixed $v 值。
	 * @return int|float|null
	 */
	public static function to_number( $v ) {
		if ( is_int( $v ) || is_float( $v ) ) {
			return is_finite( (float) $v ) ? $v : null;
		}
		if ( is_string( $v ) ) {
			$cleaned = preg_replace( '/[^\d.\-]/', '', $v );
			if ( null === $cleaned || '' === $cleaned || ! is_numeric( $cleaned ) ) {
				return null;
			}
			return $cleaned + 0;
		}
		return null;
	}

	/**
	 * 保留两位小数，避免浮点误差。
	 *
	 * @param float $n 数字。
	 * @return float
	 */
	public static function round2( $n ) {
		return round( $n, 2 );
	}

	/**
	 * 校验并归一化单条记账条目。
	 *
	 * @param mixed  $raw           模型返回的条目。
	 * @param string $fallback_date 兜底日期。
	 * @return array<string,mixed>|null
	 */
	public static function normalize_ledger_item( $raw, $fallback_date ) {
		if ( ! is_array( $raw ) ) {
			return null;
		}
		$amount = self::to_number( isset( $raw['amount'] ) ? $raw['amount'] : null );
		if ( null === $amount || $amount <= 0 ) {
			return null;
		}
		$kind_raw = strtolower( (string) ( isset( $raw['kind'] ) ? $raw['kind'] : '' ) );
		$kind     = ( 'income' === $kind_raw || '收入' === $kind_raw ) ? 'income' : 'expense';

		$category = ( isset( $raw['category'] ) && is_string( $raw['category'] ) ) ? trim( $raw['category'] ) : '';
		if ( '' === $category ) {
			$category = '其它';
		}
		if ( mb_strlen( $category, 'UTF-8' ) > 16 ) {
			$category = mb_substr( $category, 0, 16, 'UTF-8' );
		}

		$note = ( isset( $raw['note'] ) && is_string( $raw['note'] ) ) ? trim( $raw['note'] ) : '';
		if ( mb_strlen( $note, 'UTF-8' ) > 100 ) {
			$note = mb_substr( $note, 0, 100, 'UTF-8' );
		}

		$date = ( isset( $raw['date'] ) && is_string( $raw['date'] ) ) ? trim( $raw['date'] ) : '';
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			$date = $fallback_date;
		}

		return array(
			'amount'   => self::round2( $amount ),
			'kind'     => $kind,
			'category' => $category,
			'note'     => $note,
			'date'     => $date,
		);
	}

	/**
	 * 校验并归一化模型返回的记账结果。
	 *
	 * @param mixed  $parsed        模型解析结果。
	 * @param string $fallback_date 兜底日期。
	 * @return array{items:array<int,mixed>,summary:string,total:float}
	 */
	public static function normalize_ledger_result( $parsed, $fallback_date ) {
		$raw_items = ( is_array( $parsed ) && isset( $parsed['items'] ) && is_array( $parsed['items'] ) ) ? $parsed['items'] : array();
		$items     = array();
		foreach ( array_slice( $raw_items, 0, Config::limit( 'AI_MAX_ITEMS' ) ) as $raw ) {
			$item = self::normalize_ledger_item( $raw, $fallback_date );
			if ( null !== $item ) {
				$items[] = $item;
			}
		}

		$total = 0.0;
		foreach ( $items as $it ) {
			if ( 'expense' === $it['kind'] ) {
				$total += $it['amount'];
			}
		}
		$total = self::round2( $total );

		$summary = ( is_array( $parsed ) && isset( $parsed['summary'] ) && is_string( $parsed['summary'] ) )
			? trim( $parsed['summary'] ) : '';
		if ( mb_strlen( $summary, 'UTF-8' ) > 300 ) {
			$summary = mb_substr( $summary, 0, 300, 'UTF-8' );
		}
		if ( '' === $summary ) {
			$income        = 0.0;
			$expense_count = 0;
			foreach ( $items as $it ) {
				if ( 'income' === $it['kind'] ) {
					$income += $it['amount'];
				} else {
					$expense_count++;
				}
			}
			$income       = self::round2( $income );
			$income_count = count( $items ) - $expense_count;
			$parts        = array();
			if ( $expense_count > 0 ) {
				$parts[] = $expense_count . ' 笔支出，共 ' . self::num_text( $total ) . ' 元';
			}
			if ( $income_count > 0 ) {
				$parts[] = $income_count . ' 笔收入，共 ' . self::num_text( $income ) . ' 元';
			}
			$summary = ( 0 === count( $parts ) ) ? '未识别到有效记账条目' : implode( '，', $parts );
		}

		return array(
			'items'   => $items,
			'summary' => $summary,
			'total'   => $total,
		);
	}

	/**
	 * 数字转文本（整数不带小数点，与 server-php 输出保持一致）。
	 *
	 * @param mixed $n 数字。
	 * @return string
	 */
	private static function num_text( $n ) {
		if ( is_int( $n ) ) {
			return (string) $n;
		}
		return (string) $n;
	}

	// ------------------------------------------------------------------
	// 系统提示词
	// ------------------------------------------------------------------

	/** 记账解析提示词。 */
	const LEDGER_SYSTEM_PROMPT = '你是一个中文记账信息抽取引擎。
用户的输入是自然语言描述的一笔或多笔收入/支出。

严格要求：
1. 只输出一个 JSON 对象，不要输出任何解释、前后缀、Markdown 代码块或多余文字。
2. JSON 结构固定为：
{"items":[{"amount":25,"kind":"expense","category":"餐饮","note":"午饭","date":"2025-06-01"}],"summary":"3 笔支出，共 127.1 元"}
3. amount 必须是数字（不要带货币符号和千分位），保留两位小数以内。
4. kind 只能是 "expense"（支出）或 "income"（收入）。
5. category 只能从以下取值中选择最贴切的一个：
   "餐饮"、"交通"、"购物"、"娱乐"、"居住"、"医疗"、"学习"、"人情"、"工资"、"理财"、"其它"
6. note 是简短的中文备注，不超过 20 个字。
7. date 必须是 YYYY-MM-DD 格式；如果用户没有说明日期，使用给定日期。
8. summary 是一句中文总结，说明笔数与金额合计。
9. 若完全没有可识别的金额，返回 {"items":[],"summary":"未识别到有效记账条目"}。';

	/** 月度报告提示词。 */
	const REPORT_SYSTEM_PROMPT = '你是一位温柔、客观的中文记账分析助手。
用户会给你某个月的消费汇总统计（不是明细）。请输出一段中文分析，要求：
1. 只输出纯文本，不要输出 JSON，不要使用 Markdown 代码块。
2. 长度 120-260 字。
3. 包含：总体收支情况、占比最高的类目、一条具体可行的省钱建议。
4. 语气亲切自然，可以称呼用户为「你们」。';

	/**
	 * 调用模型做记账解析。
	 *
	 * @param array<string,mixed> $opts text / currency / today。
	 * @return array{items:array<int,mixed>,summary:string,total:float,model:string,usage:mixed,raw:string}
	 */
	public static function parse_ledger( array $opts ) {
		$text     = $opts['text'];
		$currency = isset( $opts['currency'] ) ? $opts['currency'] : 'CNY';
		$today    = isset( $opts['today'] ) ? $opts['today'] : null;

		$fallback_date = ( null !== $today && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $today ) )
			? $today : Time::day_key();

		$user_prompt = implode(
			"\n",
			array(
				'当前日期：' . $fallback_date,
				'货币：' . $currency,
				'用户描述：' . $text,
			)
		);

		$res = self::chat_completion(
			array(
				'messages'    => array(
					array(
						'role'    => 'system',
						'content' => self::LEDGER_SYSTEM_PROMPT,
					),
					array(
						'role'    => 'user',
						'content' => $user_prompt,
					),
				),
				'temperature' => 0,
				'maxTokens'   => 2048,
			)
		);

		$parsed     = self::parse_json_loose( $res['content'] );
		$normalized = self::normalize_ledger_result( $parsed, $fallback_date );
		if ( null === $parsed ) {
			Log::warn( '模型输出无法解析为 JSON，已返回空结果', array( 'raw' => mb_substr( $res['content'], 0, 300, 'UTF-8' ) ) );
		}

		return array(
			'items'   => $normalized['items'],
			'summary' => $normalized['summary'],
			'total'   => $normalized['total'],
			'model'   => $res['model'],
			'usage'   => $res['usage'],
			'raw'     => $res['content'],
		);
	}

	/**
	 * 调用模型生成月度报告。
	 *
	 * @param array<string,mixed> $opts month / totals / byCategory / days。
	 * @return array{text:string,model:string,usage:mixed}
	 */
	public static function monthly_report( array $opts ) {
		$month       = $opts['month'];
		$totals      = ( isset( $opts['totals'] ) && is_array( $opts['totals'] ) ) ? $opts['totals'] : array();
		$by_category = ( isset( $opts['byCategory'] ) && is_array( $opts['byCategory'] ) ) ? $opts['byCategory'] : array();
		$days        = isset( $opts['days'] ) ? $opts['days'] : null;

		$expense = self::to_number( isset( $totals['expense'] ) ? $totals['expense'] : 0 );
		$income  = self::to_number( isset( $totals['income'] ) ? $totals['income'] : 0 );
		$expense = ( null === $expense ) ? 0 : $expense;
		$income  = ( null === $income ) ? 0 : $income;

		// 类目占比：服务端先算好，减少模型算错的可能。
		$cats = array();
		foreach ( $by_category as $c ) {
			if ( ! is_array( $c ) ) {
				continue;
			}
			$amount = self::to_number( isset( $c['amount'] ) ? $c['amount'] : null );
			if ( null === $amount || $amount <= 0 ) {
				continue;
			}
			$cats[] = array(
				'category' => isset( $c['category'] ) ? (string) $c['category'] : '其它',
				'amount'   => $amount,
			);
		}
		usort(
			$cats,
			static function ( $a, $b ) {
				if ( $a['amount'] === $b['amount'] ) {
					return 0;
				}
				return ( $a['amount'] < $b['amount'] ) ? 1 : -1;
			}
		);
		$cats = array_slice( $cats, 0, 12 );

		$total_expense = 0.0;
		foreach ( $cats as $c ) {
			$total_expense += $c['amount'];
		}
		if ( $total_expense <= 0 ) {
			$total_expense = (float) $expense;
		}

		$lines = array(
			'月份：' . $month,
			'总支出：' . self::round2( $expense ),
			'总收入：' . self::round2( $income ),
			'结余：' . self::round2( $income - $expense ),
			'统计天数：' . ( null === $days ? '未知' : $days ),
			'类目占比：',
		);
		foreach ( $cats as $c ) {
			$pct     = ( $total_expense > 0 ) ? number_format( $c['amount'] / $total_expense * 100, 1, '.', '' ) : '0.0';
			$lines[] = '  - ' . $c['category'] . '：' . self::round2( $c['amount'] ) . ' 元（' . $pct . '%）';
		}

		$res = self::chat_completion(
			array(
				'messages'    => array(
					array(
						'role'    => 'system',
						'content' => self::REPORT_SYSTEM_PROMPT,
					),
					array(
						'role'    => 'user',
						'content' => implode( "\n", $lines ),
					),
				),
				'temperature' => 0.7,
				'maxTokens'   => 1024,
			)
		);

		$text = trim( (string) $res['content'] );
		if ( preg_match( '/```(?:text|markdown)?\s*([\s\S]*?)```/', $text, $m ) && isset( $m[1] ) ) {
			$text = trim( $m[1] );
		}
		if ( mb_strlen( $text, 'UTF-8' ) > 1200 ) {
			$text = mb_substr( $text, 0, 1200, 'UTF-8' );
		}

		return array(
			'text'  => $text,
			'model' => $res['model'],
			'usage' => $res['usage'],
		);
	}
}
