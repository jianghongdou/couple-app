<?php
/**
 * AI 代理接口（API.md §7）。
 *
 * 移植自 server-php/src/routes/AiRoutes.php。
 *
 * 记账数据本身永远只存手机本地，服务端只做「文本 → 结构化 JSON」的转换，
 * 不落库（除匿名调用计数 ai_usage 外）。
 *
 * @package CoupleSpace
 */

namespace CoupleSpace\Routes;

use CoupleSpace\Auth;
use CoupleSpace\Base;
use CoupleSpace\Config;
use CoupleSpace\Db;
use CoupleSpace\DeepSeek;
use CoupleSpace\Http;
use CoupleSpace\HttpException;
use CoupleSpace\Log;
use CoupleSpace\RateLimit;
use CoupleSpace\Settings;
use CoupleSpace\Validate;

defined( 'ABSPATH' ) || exit;

/**
 * AI 接口。
 */
class AiRoutes extends Base {

	/**
	 * 注册路由。
	 *
	 * @param object $r 路由器。
	 */
	public static function register( $r ) {
		// GET /api/ai/status。
		$r->get(
			'/api/ai/status',
			static function () {
				Auth::require_user();
				$s = Settings::read_all();
				return self::ok(
					array(
						'enabled'    => ( false !== $s['aiEnabled'] ),
						'configured' => ( is_string( $s['deepseekApiKey'] ) && '' !== $s['deepseekApiKey'] ),
						'model'      => (string) ( '' !== $s['deepseekModel'] ? $s['deepseekModel'] : 'deepseek-chat' ),
						'dailyLimit' => Settings::get_ai_daily_limit(),
						'usedToday'  => Db::count_ai_usage_today(),
					)
				);
			}
		);

		// 每秒级限流，防止客户端狂刷消耗额度（30 次/分钟）。
		$limiter = static function ( $user_id ) {
			RateLimit::enforce( 'ai:' . $user_id, 30, 60000, 'AI 调用过于频繁，请稍后再试' );
		};

		/**
		 * 前置校验：AI 开关、Key 是否配置、每日限额。
		 *
		 * @return array{model:string,dailyLimit:int}
		 */
		$precheck = static function () {
			$s = Settings::read_all();
			if ( false === $s['aiEnabled'] ) {
				throw new HttpException( 403, 'forbidden', 'AI 功能已关闭' );
			}
			if ( ! is_string( $s['deepseekApiKey'] ) || '' === $s['deepseekApiKey'] ) {
				// 未配置 Key -> 403 forbidden（API.md §7）。
				throw new HttpException( 403, 'forbidden', 'AI 未配置' );
			}
			$daily_limit = Settings::get_ai_daily_limit();
			$used_today  = Db::count_ai_usage_today();
			if ( $daily_limit > 0 && $used_today >= $daily_limit ) {
				throw new HttpException( 429, 'rate_limited', '今日 AI 调用次数已达上限（' . $daily_limit . ' 次）' );
			}
			return array(
				'model'      => (string) ( '' !== $s['deepseekModel'] ? $s['deepseekModel'] : 'deepseek-chat' ),
				'dailyLimit' => $daily_limit,
			);
		};

		// POST /api/ai/parse-ledger。
		$r->post(
			'/api/ai/parse-ledger',
			static function () use ( $limiter, $precheck ) {
				$me = Auth::require_user();
				$limiter( $me['user']['id'] );
				$body = Http::json_body();

				$text = Validate::require_string(
					Http::field( $body, 'text' ),
					'text',
					array(
						'min'  => 1,
						'max'  => Config::limit( 'AI_TEXT_MAX' ),
						'trim' => true,
					)
				);
				$currency_raw = Http::field( $body, 'currency' );
				$currency     = ( null === $currency_raw || '' === $currency_raw )
					? 'CNY'
					: Validate::require_string(
						$currency_raw,
						'currency',
						array(
							'min'  => 1,
							'max'  => 8,
							'trim' => true,
						)
					);
				$today_raw = Http::field( $body, 'today' );
				$today     = ( null === $today_raw || '' === $today_raw )
					? null
					: Validate::require_date_string( $today_raw, 'today' );

				$pre = $precheck();

				try {
					$result = DeepSeek::parse_ledger(
						array(
							'text'     => $text,
							'currency' => $currency,
							'today'    => $today,
						)
					);
				} catch ( \Throwable $e ) {
					// 失败也计数，避免刷失败请求绕过限额。
					Db::record_ai_usage(
						array(
							'userId'    => $me['user']['id'],
							'endpoint'  => 'parse-ledger',
							'model'     => $pre['model'],
							'ok'        => false,
							'promptLen' => strlen( $text ),
						)
					);
					throw $e;
				}

				Db::record_ai_usage(
					array(
						'userId'    => $me['user']['id'],
						'endpoint'  => 'parse-ledger',
						'model'     => $result['model'],
						'ok'        => true,
						'promptLen' => strlen( $text ),
					)
				);
				Log::info(
					'AI 记账解析完成',
					array(
						'userId' => $me['user']['id'],
						'items'  => count( $result['items'] ),
						'model'  => $result['model'],
					)
				);

				return self::ok(
					array(
						'items'   => $result['items'],
						'summary' => $result['summary'],
						'total'   => $result['total'],
						'model'   => $result['model'],
					)
				);
			}
		);

		// POST /api/ai/monthly-report。
		$r->post(
			'/api/ai/monthly-report',
			static function () use ( $limiter, $precheck ) {
				$me = Auth::require_user();
				$limiter( $me['user']['id'] );
				$body = Http::json_body();

				$month = Validate::require_month_string( Http::field( $body, 'month' ), 'month' );

				$totals_raw = Http::field( $body, 'totals' );
				if ( ! Validate::is_plain_object( $totals_raw ) ) {
					Validate::fail( 'totals 必须是 JSON 对象' );
				}
				$expense = DeepSeek::to_number( Http::field( $totals_raw, 'expense', 0 ) );
				$income  = DeepSeek::to_number( Http::field( $totals_raw, 'income', 0 ) );
				$expense = ( null === $expense ) ? 0 : $expense;
				$income  = ( null === $income ) ? 0 : $income;
				if ( $expense < 0 ) {
					Validate::fail( 'totals.expense 必须是非负数字' );
				}
				if ( $income < 0 ) {
					Validate::fail( 'totals.income 必须是非负数字' );
				}
				$totals = array(
					'expense' => $expense,
					'income'  => $income,
				);

				$by_category     = null;
				$by_category_raw = Http::field( $body, 'byCategory' );
				if ( null !== $by_category_raw ) {
					if ( ! is_array( $by_category_raw ) ) {
						Validate::fail( 'byCategory 必须是数组' );
					}
					if ( count( $by_category_raw ) > 100 ) {
						Validate::fail( 'byCategory 最多 100 项' );
					}
					$by_category = array();
					foreach ( $by_category_raw as $i => $c ) {
						if ( ! Validate::is_plain_object( $c ) ) {
							Validate::fail( 'byCategory[' . $i . '] 必须是 JSON 对象' );
						}
						$category = Validate::require_string(
							Http::field( $c, 'category' ),
							'byCategory[' . $i . '].category',
							array(
								'min'  => 1,
								'max'  => 32,
								'trim' => true,
							)
						);
						$amount = DeepSeek::to_number( Http::field( $c, 'amount' ) );
						if ( null === $amount || $amount < 0 ) {
							Validate::fail( 'byCategory[' . $i . '].amount 必须是非负数字' );
						}
						$by_category[] = array(
							'category' => $category,
							'amount'   => $amount,
						);
					}
				}

				$days = Validate::query_int( Http::field( $body, 'days' ), 'days', array( 'min' => 1, 'max' => 366 ) );

				$pre = $precheck();

				try {
					$result = DeepSeek::monthly_report(
						array(
							'month'      => $month,
							'totals'     => $totals,
							'byCategory' => $by_category,
							'days'       => $days,
						)
					);
				} catch ( \Throwable $e ) {
					Db::record_ai_usage(
						array(
							'userId'    => $me['user']['id'],
							'endpoint'  => 'monthly-report',
							'model'     => $pre['model'],
							'ok'        => false,
							'promptLen' => 0,
						)
					);
					throw $e;
				}

				Db::record_ai_usage(
					array(
						'userId'    => $me['user']['id'],
						'endpoint'  => 'monthly-report',
						'model'     => $result['model'],
						'ok'        => true,
						'promptLen' => 0,
					)
				);
				Log::info(
					'AI 月度报告生成完成',
					array(
						'userId' => $me['user']['id'],
						'month'  => $month,
						'model'  => $result['model'],
					)
				);

				return self::ok(
					array(
						'text'  => $result['text'],
						'model' => $result['model'],
					)
				);
			}
		);
	}
}
