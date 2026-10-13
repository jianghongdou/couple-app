<?php
namespace App;

/**
 * DeepSeek 服务：调用大模型、超时/重试、JSON 容错解析与字段强校验。
 *
 * 设计要点：
 *   1. Key 只从 settings 表读取（后台页面写入），客户端永远拿不到。
 *   2. 系统提示词要求模型「只输出 JSON」，但服务端仍做容错解析。
 *   3. 记账/月度报告只做「文本 → 结构化 JSON」转换，服务端不落库明细。
 */
class DeepSeek
{
    /** 请求超时（秒） */
    const REQUEST_TIMEOUT = 45;
    /** 最大重试次数（不含首次） */
    const MAX_RETRIES = 2;
    /** 重试基础退避（毫秒），指数增长 */
    const RETRY_BASE_DELAY_MS = 600;

    /** 记账分类建议取值（API.md §7） */
    const LEDGER_CATEGORIES = ['餐饮', '交通', '购物', '娱乐', '居住', '医疗', '学习', '人情', '工资', '理财', '其它'];

    /**
     * 调用 DeepSeek Chat Completions 接口。
     *
     * @param array $opts messages / timeoutMs / maxRetries / maxTokens / temperature
     * @return array{content:string,model:string,usage:mixed}
     */
    public static function chatCompletion(array $opts)
    {
        $messages = $opts['messages'];
        $timeout = isset($opts['timeoutMs']) ? (int) $opts['timeoutMs'] : self::REQUEST_TIMEOUT * 1000;
        $maxRetries = isset($opts['maxRetries']) ? (int) $opts['maxRetries'] : self::MAX_RETRIES;
        $maxTokens = isset($opts['maxTokens']) ? (int) $opts['maxTokens'] : 2048;
        $temperature = isset($opts['temperature']) ? $opts['temperature'] : 0.2;

        $settings = Settings::readAll();
        $apiKey = $settings['deepseekApiKey'];
        if (!is_string($apiKey) || $apiKey === '') {
            throw new AiError('AI 未配置', 'forbidden', 403);
        }

        $baseUrl = rtrim((string) ($settings['deepseekBaseUrl'] !== '' ? $settings['deepseekBaseUrl'] : 'https://api.deepseek.com'), '/');
        $model = (string) ($settings['deepseekModel'] !== '' ? $settings['deepseekModel'] : 'deepseek-chat');
        $url = $baseUrl . '/chat/completions';

        $body = json_encode([
            'model' => $model,
            'messages' => $messages,
            'stream' => false,
            'temperature' => $temperature,
            'max_tokens' => $maxTokens,
        ], JSON_UNESCAPED_UNICODE);

        $lastError = null;
        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            $status = null;
            try {
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $body,
                    CURLOPT_HTTPHEADER => [
                        'Content-Type: application/json',
                        'Authorization: Bearer ' . $apiKey,
                    ],
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT_MS => $timeout,
                    CURLOPT_CONNECTTIMEOUT_MS => min(10000, $timeout),
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                    CURLOPT_FOLLOWLOCATION => false,
                ]);
                $text = curl_exec($ch);
                $errno = curl_errno($ch);
                $errmsg = curl_error($ch);
                $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                curl_close($ch);

                if ($errno !== 0 || $text === false) {
                    // 28 = CURLE_OPERATION_TIMEDOUT（用数字避免旧版本常量别名问题）
                    $isTimeout = ($errno === 28);
                    $lastError = new AiError(
                        $isTimeout ? '调用 DeepSeek 超时' : ('调用 DeepSeek 失败：' . $errmsg),
                        'server_error',
                        $isTimeout ? 504 : 502
                    );
                    if ($attempt < $maxRetries) {
                        Log::warn('DeepSeek 调用失败，准备重试', ['attempt' => $attempt + 1, 'error' => $errmsg]);
                        usleep(self::RETRY_BASE_DELAY_MS * 1000 * (1 << $attempt));
                        continue;
                    }
                    throw $lastError;
                }

                if ($status === 401 || $status === 403) {
                    Log::warn('DeepSeek 鉴权失败', ['status' => $status]);
                    throw new AiError('DeepSeek API Key 无效或无权限', 'forbidden', 403);
                }

                if ($status < 200 || $status >= 300) {
                    $lastError = new AiError(
                        'DeepSeek 接口返回 ' . $status . ': ' . mb_substr((string) $text, 0, 300, 'UTF-8'),
                        $status === 429 ? 'rate_limited' : 'server_error',
                        $status === 429 ? 429 : 502
                    );
                    $retryable = ($status === 429 || $status >= 500);
                    if ($attempt < $maxRetries && $retryable) {
                        usleep(self::RETRY_BASE_DELAY_MS * 1000 * (1 << $attempt));
                        continue;
                    }
                    throw $lastError;
                }

                $json = json_decode((string) $text, true);
                if (!is_array($json)) {
                    throw new AiError('DeepSeek 返回内容不是合法 JSON', 'server_error', 502);
                }
                $content = isset($json['choices'][0]['message']['content']) ? $json['choices'][0]['message']['content'] : null;
                if (!is_string($content) || trim($content) === '') {
                    $lastError = new AiError('DeepSeek 返回内容为空', 'server_error', 502);
                    if ($attempt < $maxRetries) {
                        usleep(self::RETRY_BASE_DELAY_MS * 1000 * (1 << $attempt));
                        continue;
                    }
                    throw $lastError;
                }
                return [
                    'content' => $content,
                    'model' => isset($json['model']) && $json['model'] ? $json['model'] : $model,
                    'usage' => isset($json['usage']) ? $json['usage'] : null,
                ];
            } catch (AiError $e) {
                // 鉴权/限流错误不重试，直接抛出
                if ($e->getStatus() === 403 || $e->getStatus() === 429) {
                    throw $e;
                }
                if ($attempt >= $maxRetries) {
                    throw $e;
                }
                $lastError = $e;
                usleep(self::RETRY_BASE_DELAY_MS * 1000 * (1 << $attempt));
            }
        }

        throw $lastError ? $lastError : new AiError('调用 DeepSeek 失败', 'server_error', 502);
    }

    // ------------------------------------------------------------------
    // JSON 容错解析
    // ------------------------------------------------------------------

    /**
     * 从模型输出中提取 JSON 文本：
     *   1. 剥离 ```json ... ``` / ``` ... ``` 代码块
     *   2. 截取首个 { 到最后一个 }
     */
    public static function extractJsonText($text)
    {
        if (!is_string($text)) {
            return null;
        }
        $s = trim($text);
        if (preg_match('/```(?:json|JSON)?\s*([\s\S]*?)```/', $s, $m) && isset($m[1])) {
            $s = trim($m[1]);
        }
        $start = strpos($s, '{');
        $end = strrpos($s, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }
        return substr($s, $start, $end - $start + 1);
    }

    /** 容错解析模型输出为数组 */
    public static function parseJsonLoose($text)
    {
        $candidate = self::extractJsonText($text);
        if ($candidate === null) {
            return null;
        }
        $v = json_decode($candidate, true);
        if (is_array($v)) {
            return $v;
        }
        // 常见修复：去掉尾随逗号
        $noTrailing = preg_replace('/,\s*([}\]])/', '$1', $candidate);
        if ($noTrailing !== null) {
            $v = json_decode($noTrailing, true);
            if (is_array($v)) {
                return $v;
            }
            // 常见修复：中文全角引号 / 单引号
            $normalized = str_replace(["\u{201c}", "\u{201d}"], '"', $noTrailing);
            $normalized = str_replace(["\u{2018}", "\u{2019}"], "'", $normalized);
            $v = json_decode($normalized, true);
            if (is_array($v)) {
                return $v;
            }
        }
        return null;
    }

    // ------------------------------------------------------------------
    // 字段强校验与兜底
    // ------------------------------------------------------------------

    /** 安全转数字（支持 "25"、"25.5"、"￥25.5"、"1,234.5"） */
    public static function toNumber($v)
    {
        if (is_int($v) || is_float($v)) {
            return is_finite((float) $v) ? $v : null;
        }
        if (is_string($v)) {
            $cleaned = preg_replace('/[^\d.\-]/', '', $v);
            if ($cleaned === null || $cleaned === '' || !is_numeric($cleaned)) {
                return null;
            }
            return $cleaned + 0;
        }
        return null;
    }

    /** 保留两位小数，避免浮点误差 */
    public static function round2($n)
    {
        return round($n, 2);
    }

    /** 校验并归一化单条记账条目 */
    public static function normalizeLedgerItem($raw, $fallbackDate)
    {
        if (!is_array($raw)) {
            return null;
        }
        $amount = self::toNumber(isset($raw['amount']) ? $raw['amount'] : null);
        if ($amount === null || $amount <= 0) {
            return null;
        }
        $kindRaw = strtolower((string) (isset($raw['kind']) ? $raw['kind'] : ''));
        $kind = ($kindRaw === 'income' || $kindRaw === '收入') ? 'income' : 'expense';

        $category = isset($raw['category']) && is_string($raw['category']) ? trim($raw['category']) : '';
        if ($category === '') {
            $category = '其它';
        }
        if (mb_strlen($category, 'UTF-8') > 16) {
            $category = mb_substr($category, 0, 16, 'UTF-8');
        }

        $note = isset($raw['note']) && is_string($raw['note']) ? trim($raw['note']) : '';
        if (mb_strlen($note, 'UTF-8') > 100) {
            $note = mb_substr($note, 0, 100, 'UTF-8');
        }

        $date = isset($raw['date']) && is_string($raw['date']) ? trim($raw['date']) : '';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = $fallbackDate;
        }

        return [
            'amount' => self::round2($amount),
            'kind' => $kind,
            'category' => $category,
            'note' => $note,
            'date' => $date,
        ];
    }

    /**
     * 校验并归一化模型返回的记账结果
     * @return array{items:array,summary:string,total:float}
     */
    public static function normalizeLedgerResult($parsed, $fallbackDate, $currency = 'CNY')
    {
        $rawItems = (is_array($parsed) && isset($parsed['items']) && is_array($parsed['items'])) ? $parsed['items'] : [];
        $items = [];
        foreach (array_slice($rawItems, 0, Config::limit('AI_MAX_ITEMS')) as $raw) {
            $item = self::normalizeLedgerItem($raw, $fallbackDate);
            if ($item !== null) {
                $items[] = $item;
            }
        }

        $total = 0.0;
        foreach ($items as $it) {
            if ($it['kind'] === 'expense') {
                $total += $it['amount'];
            }
        }
        $total = self::round2($total);

        $summary = (is_array($parsed) && isset($parsed['summary']) && is_string($parsed['summary']))
            ? trim($parsed['summary']) : '';
        if (mb_strlen($summary, 'UTF-8') > 300) {
            $summary = mb_substr($summary, 0, 300, 'UTF-8');
        }
        if ($summary === '') {
            $income = 0.0;
            $expenseCount = 0;
            foreach ($items as $it) {
                if ($it['kind'] === 'income') {
                    $income += $it['amount'];
                } else {
                    $expenseCount++;
                }
            }
            $income = self::round2($income);
            $incomeCount = count($items) - $expenseCount;
            $parts = [];
            if ($expenseCount > 0) {
                $parts[] = $expenseCount . ' 笔支出，共 ' . self::numText($total) . ' 元';
            }
            if ($incomeCount > 0) {
                $parts[] = $incomeCount . ' 笔收入，共 ' . self::numText($income) . ' 元';
            }
            $summary = count($parts) === 0 ? '未识别到有效记账条目' : implode('，', $parts);
        }

        return ['items' => $items, 'summary' => $summary, 'total' => $total];
    }

    /** 数字转文本：整数不带小数点（与 JS 的输出保持一致） */
    private static function numText($n)
    {
        if (is_int($n)) {
            return (string) $n;
        }
        $s = (string) $n;
        return $s;
    }

    // ------------------------------------------------------------------
    // 系统提示词
    // ------------------------------------------------------------------

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

    const REPORT_SYSTEM_PROMPT = '你是一位温柔、客观的中文记账分析助手。
用户会给你某个月的消费汇总统计（不是明细）。请输出一段中文分析，要求：
1. 只输出纯文本，不要输出 JSON，不要使用 Markdown 代码块。
2. 长度 120-260 字。
3. 包含：总体收支情况、占比最高的类目、一条具体可行的省钱建议。
4. 语气亲切自然，可以称呼用户为「你们」。';

    /**
     * 调用模型做记账解析
     * @return array{items:array,summary:string,total:float,model:string,usage:mixed,raw:string}
     */
    public static function parseLedger(array $opts)
    {
        $text = $opts['text'];
        $currency = isset($opts['currency']) ? $opts['currency'] : 'CNY';
        $today = isset($opts['today']) ? $opts['today'] : null;

        $fallbackDate = ($today !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $today))
            ? $today : date('Y-m-d');

        $userPrompt = implode("\n", [
            '当前日期：' . $fallbackDate,
            '货币：' . $currency,
            '用户描述：' . $text,
        ]);

        $res = self::chatCompletion([
            'messages' => [
                ['role' => 'system', 'content' => self::LEDGER_SYSTEM_PROMPT],
                ['role' => 'user', 'content' => $userPrompt],
            ],
            'temperature' => 0,
            'maxTokens' => 2048,
        ]);

        $parsed = self::parseJsonLoose($res['content']);
        $normalized = self::normalizeLedgerResult($parsed, $fallbackDate, $currency);
        if ($parsed === null) {
            Log::warn('模型输出无法解析为 JSON，已返回空结果', ['raw' => mb_substr($res['content'], 0, 300, 'UTF-8')]);
        }

        return [
            'items' => $normalized['items'],
            'summary' => $normalized['summary'],
            'total' => $normalized['total'],
            'model' => $res['model'],
            'usage' => $res['usage'],
            'raw' => $res['content'],
        ];
    }

    /**
     * 调用模型生成月度报告
     * @return array{text:string,model:string,usage:mixed}
     */
    public static function monthlyReport(array $opts)
    {
        $month = $opts['month'];
        $totals = isset($opts['totals']) && is_array($opts['totals']) ? $opts['totals'] : [];
        $byCategory = isset($opts['byCategory']) && is_array($opts['byCategory']) ? $opts['byCategory'] : [];
        $days = isset($opts['days']) ? $opts['days'] : null;

        $expense = self::toNumber(isset($totals['expense']) ? $totals['expense'] : 0);
        $income = self::toNumber(isset($totals['income']) ? $totals['income'] : 0);
        $expense = $expense === null ? 0 : $expense;
        $income = $income === null ? 0 : $income;

        // 类目占比：服务端先算好，减少模型算错的可能
        $cats = [];
        foreach ($byCategory as $c) {
            if (!is_array($c)) {
                continue;
            }
            $amount = self::toNumber(isset($c['amount']) ? $c['amount'] : null);
            if ($amount === null || $amount <= 0) {
                continue;
            }
            $cats[] = [
                'category' => isset($c['category']) ? (string) $c['category'] : '其它',
                'amount' => $amount,
            ];
        }
        usort($cats, function ($a, $b) {
            if ($a['amount'] === $b['amount']) {
                return 0;
            }
            return $a['amount'] < $b['amount'] ? 1 : -1;
        });
        $cats = array_slice($cats, 0, 12);

        $totalExpense = 0.0;
        foreach ($cats as $c) {
            $totalExpense += $c['amount'];
        }
        if ($totalExpense <= 0) {
            $totalExpense = $expense;
        }

        $lines = [
            '月份：' . $month,
            '总支出：' . self::round2($expense),
            '总收入：' . self::round2($income),
            '结余：' . self::round2($income - $expense),
            '统计天数：' . ($days === null ? '未知' : $days),
            '类目占比：',
        ];
        foreach ($cats as $c) {
            $pct = $totalExpense > 0 ? number_format($c['amount'] / $totalExpense * 100, 1, '.', '') : '0.0';
            $lines[] = '  - ' . $c['category'] . '：' . self::round2($c['amount']) . ' 元（' . $pct . '%）';
        }

        $res = self::chatCompletion([
            'messages' => [
                ['role' => 'system', 'content' => self::REPORT_SYSTEM_PROMPT],
                ['role' => 'user', 'content' => implode("\n", $lines)],
            ],
            'temperature' => 0.7,
            'maxTokens' => 1024,
        ]);

        $text = trim((string) $res['content']);
        if (preg_match('/```(?:text|markdown)?\s*([\s\S]*?)```/', $text, $m) && isset($m[1])) {
            $text = trim($m[1]);
        }
        if (mb_strlen($text, 'UTF-8') > 1200) {
            $text = mb_substr($text, 0, 1200, 'UTF-8');
        }

        return ['text' => $text, 'model' => $res['model'], 'usage' => $res['usage']];
    }
}
