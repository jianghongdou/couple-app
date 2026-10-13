<?php
namespace App\routes;

use App\Auth;
use App\Config;
use App\Db;
use App\DeepSeek;
use App\Http;
use App\Log;
use App\RateLimit;
use App\Settings;
use App\Time;
use App\util\Validate;

/**
 * AI 代理接口（API.md §7）
 *
 * 记账数据本身永远只存手机本地，服务端只做「文本 → 结构化 JSON」的转换，
 * 不落库（除匿名调用计数 ai_usage 外）。
 */
class AiRoutes
{
    public static function register($r)
    {
        // GET /api/ai/status
        $r->get('/api/ai/status', function () {
            Auth::requireUser();
            $s = Settings::readAll();
            Http::ok([
                'enabled' => $s['aiEnabled'] !== false,
                'configured' => is_string($s['deepseekApiKey']) && $s['deepseekApiKey'] !== '',
                'model' => (string) ($s['deepseekModel'] !== '' ? $s['deepseekModel'] : 'deepseek-chat'),
                'dailyLimit' => Settings::getAiDailyLimit(),
                'usedToday' => Db::countAiUsageToday(),
            ]);
        });

        // 每秒级限流，防止客户端狂刷消耗额度（30 次/分钟）
        $limiter = function ($userId) {
            RateLimit::enforce('ai:' . $userId, 30, 60000, 'AI 调用过于频繁，请稍后再试');
        };

        /**
         * 前置校验：AI 开关、Key 是否配置、每日限额
         * @return array{model:string,dailyLimit:int}
         */
        $precheck = function () {
            $s = Settings::readAll();
            if ($s['aiEnabled'] === false) {
                throw new \App\HttpException(403, 'forbidden', 'AI 功能已关闭');
            }
            if (!is_string($s['deepseekApiKey']) || $s['deepseekApiKey'] === '') {
                // 未配置 Key -> 403 forbidden（API.md §7）
                throw new \App\HttpException(403, 'forbidden', 'AI 未配置');
            }
            $dailyLimit = Settings::getAiDailyLimit();
            $usedToday = Db::countAiUsageToday();
            if ($dailyLimit > 0 && $usedToday >= $dailyLimit) {
                throw new \App\HttpException(429, 'rate_limited', '今日 AI 调用次数已达上限（' . $dailyLimit . ' 次）');
            }
            return [
                'model' => (string) ($s['deepseekModel'] !== '' ? $s['deepseekModel'] : 'deepseek-chat'),
                'dailyLimit' => $dailyLimit,
            ];
        };

        // POST /api/ai/parse-ledger
        $r->post('/api/ai/parse-ledger', function () use ($limiter, $precheck) {
            $me = Auth::requireUser();
            $limiter($me['user']['id']);
            $body = Http::jsonBody();

            $text = Validate::requireString(Http::field($body, 'text'), 'text', [
                'min' => 1, 'max' => Config::limit('AI_TEXT_MAX'), 'trim' => true,
            ]);
            $currencyRaw = Http::field($body, 'currency');
            $currency = ($currencyRaw === null || $currencyRaw === '')
                ? 'CNY'
                : Validate::requireString($currencyRaw, 'currency', ['min' => 1, 'max' => 8, 'trim' => true]);
            $todayRaw = Http::field($body, 'today');
            $today = ($todayRaw === null || $todayRaw === '')
                ? null
                : Validate::requireDateString($todayRaw, 'today');

            $pre = $precheck();

            try {
                $result = DeepSeek::parseLedger(['text' => $text, 'currency' => $currency, 'today' => $today]);
            } catch (\Exception $e) {
                // 失败也计数，避免刷失败请求绕过限额
                Db::recordAiUsage([
                    'userId' => $me['user']['id'],
                    'endpoint' => 'parse-ledger',
                    'model' => $pre['model'],
                    'ok' => false,
                    'promptLen' => strlen($text),
                ]);
                throw $e;
            }

            Db::recordAiUsage([
                'userId' => $me['user']['id'],
                'endpoint' => 'parse-ledger',
                'model' => $result['model'],
                'ok' => true,
                'promptLen' => strlen($text),
            ]);
            Log::info('AI 记账解析完成', [
                'userId' => $me['user']['id'],
                'items' => count($result['items']),
                'model' => $result['model'],
            ]);

            Http::ok([
                'items' => $result['items'],
                'summary' => $result['summary'],
                'total' => $result['total'],
                'model' => $result['model'],
            ]);
        });

        // POST /api/ai/monthly-report
        $r->post('/api/ai/monthly-report', function () use ($limiter, $precheck) {
            $me = Auth::requireUser();
            $limiter($me['user']['id']);
            $body = Http::jsonBody();

            $month = Validate::requireMonthString(Http::field($body, 'month'), 'month');

            $totalsRaw = Http::field($body, 'totals');
            if (!Validate::isPlainObject($totalsRaw)) {
                Validate::fail('totals 必须是 JSON 对象');
            }
            $expense = DeepSeek::toNumber(Http::field($totalsRaw, 'expense', 0));
            $income = DeepSeek::toNumber(Http::field($totalsRaw, 'income', 0));
            $expense = $expense === null ? 0 : $expense;
            $income = $income === null ? 0 : $income;
            if ($expense < 0) {
                Validate::fail('totals.expense 必须是非负数字');
            }
            if ($income < 0) {
                Validate::fail('totals.income 必须是非负数字');
            }
            $totals = ['expense' => $expense, 'income' => $income];

            $byCategory = null;
            $byCategoryRaw = Http::field($body, 'byCategory');
            if ($byCategoryRaw !== null) {
                if (!is_array($byCategoryRaw)) {
                    Validate::fail('byCategory 必须是数组');
                }
                if (count($byCategoryRaw) > 100) {
                    Validate::fail('byCategory 最多 100 项');
                }
                $byCategory = [];
                foreach ($byCategoryRaw as $i => $c) {
                    if (!Validate::isPlainObject($c)) {
                        Validate::fail('byCategory[' . $i . '] 必须是 JSON 对象');
                    }
                    $category = Validate::requireString(Http::field($c, 'category'), 'byCategory[' . $i . '].category', [
                        'min' => 1, 'max' => 32, 'trim' => true,
                    ]);
                    $amount = DeepSeek::toNumber(Http::field($c, 'amount'));
                    if ($amount === null || $amount < 0) {
                        Validate::fail('byCategory[' . $i . '].amount 必须是非负数字');
                    }
                    $byCategory[] = ['category' => $category, 'amount' => $amount];
                }
            }

            $days = Validate::queryInt(Http::field($body, 'days'), 'days', ['min' => 1, 'max' => 366]);

            $pre = $precheck();

            try {
                $result = DeepSeek::monthlyReport([
                    'month' => $month,
                    'totals' => $totals,
                    'byCategory' => $byCategory,
                    'days' => $days,
                ]);
            } catch (\Exception $e) {
                Db::recordAiUsage([
                    'userId' => $me['user']['id'],
                    'endpoint' => 'monthly-report',
                    'model' => $pre['model'],
                    'ok' => false,
                    'promptLen' => 0,
                ]);
                throw $e;
            }

            Db::recordAiUsage([
                'userId' => $me['user']['id'],
                'endpoint' => 'monthly-report',
                'model' => $result['model'],
                'ok' => true,
                'promptLen' => 0,
            ]);
            Log::info('AI 月度报告生成完成', [
                'userId' => $me['user']['id'],
                'month' => $month,
                'model' => $result['model'],
            ]);

            Http::ok(['text' => $result['text'], 'model' => $result['model']]);
        });
    }
}
