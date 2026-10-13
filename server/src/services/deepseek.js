// DeepSeek 服务：调用大模型、超时/重试、JSON 容错解析与字段强校验。
//
// 设计要点：
//   1. Key 只从 settings 表读取（后台页面写入），客户端永远拿不到。
//   2. 系统提示词要求模型「只输出 JSON」，但服务端仍做容错解析。
//   3. 记账/月度报告只做「文本 → 结构化 JSON」转换，服务端不落库明细。
import { readSettings } from './settings.js';
import { log } from '../util/log.js';
import { LIMITS } from '../config.js';

// 请求超时（毫秒）
const REQUEST_TIMEOUT_MS = 45_000;
// 最大重试次数（不含首次）
const MAX_RETRIES = 2;
// 重试基础退避（毫秒），指数增长
const RETRY_BASE_DELAY_MS = 600;

/** AI 调用异常 */
export class AiError extends Error {
  constructor(message, errorCode = 'server_error', status = 500) {
    super(message);
    this.name = 'AiError';
    this.errorCode = errorCode;
    this.status = status;
  }
}

/** 睡眠 */
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

/**
 * 判断错误是否值得重试（网络抖动 / 5xx / 429 可重试；4xx 参数错误不重试）
 * @param {number|null} status
 * @param {Error} err
 */
function shouldRetry(status, err) {
  if (status === null) return true; // 网络层错误
  if (status === 429) return true;
  if (status >= 500) return true;
  if (err && err.name === 'AbortError') return true;
  return false;
}

/**
 * 调用 DeepSeek Chat Completions 接口。
 *
 * @param {object} opts
 * @param {Array<{role:string, content:string}>} opts.messages
 * @param {number} [opts.timeoutMs]
 * @param {number} [opts.maxRetries]
 * @param {number} [opts.maxTokens]
 * @param {number} [opts.temperature]
 * @returns {Promise<{content:string, model:string, usage:object|null}>}
 */
export async function chatCompletion(opts) {
  const {
    messages,
    timeoutMs = REQUEST_TIMEOUT_MS,
    maxRetries = MAX_RETRIES,
    maxTokens = 2048,
    temperature = 0.2,
  } = opts;

  const settings = readSettings();
  const apiKey = settings.deepseekApiKey;
  if (!apiKey) {
    throw new AiError('AI 未配置', 'forbidden', 403);
  }

  const baseUrl = String(settings.deepseekBaseUrl || 'https://api.deepseek.com').replace(/\/+$/, '');
  const model = String(settings.deepseekModel || 'deepseek-chat');
  const url = `${baseUrl}/chat/completions`;

  let lastError = null;

  for (let attempt = 0; attempt <= maxRetries; attempt += 1) {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), timeoutMs);
    let status = null;

    try {
      const res = await fetch(url, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Authorization: `Bearer ${apiKey}`,
        },
        body: JSON.stringify({
          model,
          messages,
          stream: false,
          temperature,
          max_tokens: maxTokens,
        }),
        signal: controller.signal,
      });
      status = res.status;

      const text = await res.text();

      if (!res.ok) {
        // 401/403 说明 Key 无效，直接抛出，不重试
        if (res.status === 401 || res.status === 403) {
          log.warn('DeepSeek 鉴权失败', { status: res.status });
          throw new AiError('DeepSeek API Key 无效或无权限', 'forbidden', 403);
        }
        lastError = new AiError(
          `DeepSeek 接口返回 ${res.status}: ${text.slice(0, 300)}`,
          res.status === 429 ? 'rate_limited' : 'server_error',
          res.status === 429 ? 429 : 502
        );
        if (attempt < maxRetries && shouldRetry(status, null)) {
          await sleep(RETRY_BASE_DELAY_MS * 2 ** attempt);
          continue;
        }
        throw lastError;
      }

      let json;
      try {
        json = JSON.parse(text);
      } catch {
        throw new AiError('DeepSeek 返回内容不是合法 JSON', 'server_error', 502);
      }

      const content = json?.choices?.[0]?.message?.content;
      if (typeof content !== 'string' || content.trim() === '') {
        lastError = new AiError('DeepSeek 返回内容为空', 'server_error', 502);
        if (attempt < maxRetries) {
          await sleep(RETRY_BASE_DELAY_MS * 2 ** attempt);
          continue;
        }
        throw lastError;
      }

      return {
        content,
        model: json?.model || model,
        usage: json?.usage || null,
      };
    } catch (err) {
      clearTimeout(timer);
      // 已经是业务异常且不应重试，直接抛出
      if (err instanceof AiError && !shouldRetry(status, err)) throw err;
      if (err instanceof AiError && (err.status === 403 || err.status === 429)) throw err;

      lastError = err instanceof AiError ? err : new AiError(`调用 DeepSeek 失败：${err.message}`, 'server_error', 502);

      const isAbort = err?.name === 'AbortError';
      if (isAbort) {
        lastError = new AiError('调用 DeepSeek 超时', 'server_error', 504);
      }

      if (attempt < maxRetries && shouldRetry(status, err)) {
        log.warn('DeepSeek 调用失败，准备重试', {
          attempt: attempt + 1,
          error: err.message,
          timeout: isAbort,
        });
        await sleep(RETRY_BASE_DELAY_MS * 2 ** attempt);
        continue;
      }
      throw lastError;
    } finally {
      clearTimeout(timer);
    }
  }

  throw lastError || new AiError('调用 DeepSeek 失败', 'server_error', 502);
}

// ---------------------------------------------------------------------------
// JSON 容错解析
// ---------------------------------------------------------------------------

/**
 * 从模型输出中提取 JSON 文本：
 *   1. 剥离 ```json ... ``` / ``` ... ``` 代码块
 *   2. 截取首个 { 到最后一个 }
 * @param {string} text
 * @returns {string|null}
 */
export function extractJsonText(text) {
  if (typeof text !== 'string') return null;
  let s = text.trim();

  // 剥离 Markdown 代码块
  const fence = s.match(/```(?:json|JSON)?\s*([\s\S]*?)```/);
  if (fence && fence[1]) s = fence[1].trim();

  // 截取首个 { 到最后一个 }
  const start = s.indexOf('{');
  const end = s.lastIndexOf('}');
  if (start === -1 || end === -1 || end <= start) return null;
  return s.slice(start, end + 1);
}

/**
 * 容错解析模型输出为 JSON 对象
 * @param {string} text
 * @returns {object|null}
 */
export function parseJsonLoose(text) {
  const candidate = extractJsonText(text);
  if (!candidate) return null;

  // 直接解析
  try {
    const v = JSON.parse(candidate);
    return v && typeof v === 'object' ? v : null;
  } catch {
    /* 继续尝试修复 */
  }

  // 常见修复：去掉尾随逗号
  const noTrailing = candidate.replace(/,\s*([}\]])/g, '$1');
  try {
    const v = JSON.parse(noTrailing);
    return v && typeof v === 'object' ? v : null;
  } catch {
    /* 继续尝试修复 */
  }

  // 常见修复：中文全角引号 / 单引号
  const normalized = noTrailing
    .replace(/[\u201c\u201d]/g, '"')
    .replace(/[\u2018\u2019]/g, "'");
  try {
    const v = JSON.parse(normalized);
    return v && typeof v === 'object' ? v : null;
  } catch {
    return null;
  }
}

// ---------------------------------------------------------------------------
// 字段强校验与兜底
// ---------------------------------------------------------------------------

// 记账分类建议取值（API.md §7）
export const LEDGER_CATEGORIES = [
  '餐饮', '交通', '购物', '娱乐', '居住', '医疗', '学习', '人情', '工资', '理财', '其它',
];

/** 安全转数字 */
function toNumber(v) {
  if (typeof v === 'number' && Number.isFinite(v)) return v;
  if (typeof v === 'string') {
    // 支持 "25"、"25.5"、"￥25.5"、"1,234.5"
    const cleaned = v.replace(/[^\d.\-]/g, '');
    const n = Number(cleaned);
    if (Number.isFinite(n)) return n;
  }
  return null;
}

/** 保留两位小数，避免浮点误差 */
function round2(n) {
  return Math.round(n * 100) / 100;
}

/**
 * 校验并归一化单条记账条目
 * @param {unknown} raw
 * @param {string} fallbackDate
 * @returns {object|null}
 */
export function normalizeLedgerItem(raw, fallbackDate) {
  if (!raw || typeof raw !== 'object') return null;

  const amount = toNumber(raw.amount);
  // 金额必须为正数才有意义
  if (amount === null || amount <= 0) return null;

  const kindRaw = String(raw.kind ?? '').toLowerCase();
  const kind = kindRaw === 'income' || kindRaw === '收入' ? 'income' : 'expense';

  let category = typeof raw.category === 'string' ? raw.category.trim() : '';
  if (!category) category = '其它';
  if (category.length > 16) category = category.slice(0, 16);

  let note = typeof raw.note === 'string' ? raw.note.trim() : '';
  if (note.length > 100) note = note.slice(0, 100);

  let date = typeof raw.date === 'string' ? raw.date.trim() : '';
  if (!/^\d{4}-\d{2}-\d{2}$/.test(date)) date = fallbackDate;

  return { amount: round2(amount), kind, category, note, date };
}

/**
 * 校验并归一化模型返回的记账结果
 * @param {object|null} parsed
 * @param {string} fallbackDate YYYY-MM-DD
 * @param {string} currency
 * @returns {{items:Array, summary:string, total:number}}
 */
export function normalizeLedgerResult(parsed, fallbackDate, currency = 'CNY') {
  const rawItems = Array.isArray(parsed?.items) ? parsed.items : [];
  const items = [];
  for (const raw of rawItems.slice(0, LIMITS.AI_MAX_ITEMS)) {
    const item = normalizeLedgerItem(raw, fallbackDate);
    if (item) items.push(item);
  }

  const total = round2(items.reduce((sum, it) => sum + (it.kind === 'expense' ? it.amount : 0), 0));

  let summary = typeof parsed?.summary === 'string' ? parsed.summary.trim() : '';
  if (summary.length > 300) summary = summary.slice(0, 300);
  if (!summary) {
    const income = round2(items.reduce((sum, it) => sum + (it.kind === 'income' ? it.amount : 0), 0));
    const expenseCount = items.filter((it) => it.kind === 'expense').length;
    const incomeCount = items.length - expenseCount;
    const parts = [];
    if (expenseCount > 0) parts.push(`${expenseCount} 笔支出，共 ${total} 元`);
    if (incomeCount > 0) parts.push(`${incomeCount} 笔收入，共 ${income} 元`);
    if (parts.length === 0) {
      const unit = currency === 'CNY' ? '元' : currency;
      summary = `未识别到有效记账条目`;
      void unit;
    } else {
      summary = parts.join('，');
    }
  }

  return { items, summary, total };
}

// ---------------------------------------------------------------------------
// 系统提示词
// ---------------------------------------------------------------------------

const LEDGER_SYSTEM_PROMPT = `你是一个中文记账信息抽取引擎。
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
9. 若完全没有可识别的金额，返回 {"items":[],"summary":"未识别到有效记账条目"}。`;

const REPORT_SYSTEM_PROMPT = `你是一位温柔、客观的中文记账分析助手。
用户会给你某个月的消费汇总统计（不是明细）。请输出一段中文分析，要求：
1. 只输出纯文本，不要输出 JSON，不要使用 Markdown 代码块。
2. 长度 120-260 字。
3. 包含：总体收支情况、占比最高的类目、一条具体可行的省钱建议。
4. 语气亲切自然，可以称呼用户为「你们」。`;

/**
 * 调用模型做记账解析
 * @param {{text:string, currency?:string, today?:string}} opts
 * @returns {Promise<{items:Array, summary:string, total:number, model:string, usage:object|null, raw:string}>}
 */
export async function parseLedger({ text, currency = 'CNY', today }) {
  const fallbackDate = today && /^\d{4}-\d{2}-\d{2}$/.test(today) ? today : new Date().toISOString().slice(0, 10);

  const userPrompt = [
    `当前日期：${fallbackDate}`,
    `货币：${currency}`,
    `用户描述：${text}`,
  ].join('\n');

  const { content, model, usage } = await chatCompletion({
    messages: [
      { role: 'system', content: LEDGER_SYSTEM_PROMPT },
      { role: 'user', content: userPrompt },
    ],
    temperature: 0,
    // 记账结果需要稳定：使用 response_format 时部分模型不支持，这里靠提示词约束
    maxTokens: 2048,
  });

  const parsed = parseJsonLoose(content);
  const normalized = normalizeLedgerResult(parsed, fallbackDate, currency);

  if (!parsed) {
    log.warn('模型输出无法解析为 JSON，已返回空结果', { raw: content.slice(0, 300) });
  }

  return { ...normalized, model, usage, raw: content };
}

/**
 * 调用模型生成月度报告
 * @param {{month:string, totals:object, byCategory:Array, days?:number}} opts
 * @returns {Promise<{text:string, model:string, usage:object|null}>}
 */
export async function monthlyReport({ month, totals, byCategory, days }) {
  const expense = toNumber(totals?.expense) ?? 0;
  const income = toNumber(totals?.income) ?? 0;

  // 类目占比：服务端先算好，减少模型算错的可能
  const cats = Array.isArray(byCategory)
    ? byCategory
        .map((c) => ({ category: String(c?.category ?? '其它'), amount: toNumber(c?.amount) ?? 0 }))
        .filter((c) => c.amount > 0)
        .sort((a, b) => b.amount - a.amount)
        .slice(0, 12)
    : [];

  const totalExpense = cats.reduce((s, c) => s + c.amount, 0) || expense;

  const lines = [
    `月份：${month}`,
    `总支出：${round2(expense)}`,
    `总收入：${round2(income)}`,
    `结余：${round2(income - expense)}`,
    `统计天数：${Number.isInteger(days) ? days : '未知'}`,
    '类目占比：',
    ...cats.map((c) => {
      const pct = totalExpense > 0 ? ((c.amount / totalExpense) * 100).toFixed(1) : '0.0';
      return `  - ${c.category}：${round2(c.amount)} 元（${pct}%）`;
    }),
  ];

  const { content, model, usage } = await chatCompletion({
    messages: [
      { role: 'system', content: REPORT_SYSTEM_PROMPT },
      { role: 'user', content: lines.join('\n') },
    ],
    temperature: 0.7,
    maxTokens: 1024,
  });

  let text = String(content || '').trim();
  // 兜底剥离可能出现的代码块
  const fence = text.match(/```(?:text|markdown)?\s*([\s\S]*?)```/);
  if (fence && fence[1]) text = fence[1].trim();
  if (text.length > 1200) text = text.slice(0, 1200);

  return { text, model, usage };
}

export default { chatCompletion, parseLedger, monthlyReport, parseJsonLoose, AiError };
