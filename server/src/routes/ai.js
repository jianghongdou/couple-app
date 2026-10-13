// AI 代理接口（API.md §7）
//
// 记账数据本身永远只存手机本地，服务端只做「文本 → 结构化 JSON」的转换，
// 不落库（除匿名调用计数 ai_usage 外）。
import express from 'express';
import { LIMITS } from '../config.js';
import { requireAuth } from '../middleware/auth.js';
import {
  requireString,
  requireDateString,
  requireMonthString,
  isPlainObject,
  queryInt,
  fail,
} from '../util/validate.js';
import { readSettings, getAiDailyLimit } from '../services/settings.js';
import { parseLedger, monthlyReport, AiError } from '../services/deepseek.js';
import {
  recordAiUsage,
  countAiUsageToday,
  countAiUsageTodayByUser,
} from '../db.js';
import { rateLimit } from '../util/ratelimit.js';
import { log } from '../util/log.js';

const router = express.Router();

/** 统一的成功响应 */
function ok(res, payload) {
  res.status(200).json({ ...payload, serverTime: Date.now() });
}

// 额外的每秒级限流，防止客户端狂刷消耗额度
const aiLimiter = rateLimit({
  limit: 30,
  windowMs: 60 * 1000,
  keyGenerator: (req) => `ai:${req.user?.id || req.ip}`,
  message: 'AI 调用过于频繁，请稍后再试',
});

/**
 * 前置校验：AI 开关、Key 是否配置、每日限额
 * 返回 model 名称；不通过时直接写响应并返回 null
 * @param {import('express').Request} req
 * @param {import('express').Response} res
 * @returns {{model:string, dailyLimit:number}|null}
 */
function precheck(req, res) {
  const settings = readSettings();

  if (settings.aiEnabled === false) {
    res.status(403).json({ error: 'forbidden', message: 'AI 功能已关闭', serverTime: Date.now() });
    return null;
  }

  // 未配置 Key -> 403 forbidden（API.md §7）
  if (!settings.deepseekApiKey) {
    res.status(403).json({ error: 'forbidden', message: 'AI 未配置', serverTime: Date.now() });
    return null;
  }

  const dailyLimit = getAiDailyLimit();
  const usedToday = countAiUsageToday();

  // 超出每日限额 -> 429 rate_limited（API.md §7）
  if (dailyLimit > 0 && usedToday >= dailyLimit) {
    res.status(429).json({
      error: 'rate_limited',
      message: `今日 AI 调用次数已达上限（${dailyLimit} 次）`,
      serverTime: Date.now(),
    });
    return null;
  }

  return { model: String(settings.deepseekModel || 'deepseek-chat'), dailyLimit };
}

// GET /api/ai/status
// 响应 { enabled, configured, model, dailyLimit, usedToday }
router.get('/status', requireAuth, (req, res, next) => {
  try {
    const settings = readSettings();
    ok(res, {
      enabled: settings.aiEnabled !== false,
      configured: !!settings.deepseekApiKey,
      model: String(settings.deepseekModel || 'deepseek-chat'),
      dailyLimit: getAiDailyLimit(),
      usedToday: countAiUsageToday(),
    });
  } catch (err) {
    next(err);
  }
});

// POST /api/ai/parse-ledger
router.post('/parse-ledger', requireAuth, aiLimiter, async (req, res, next) => {
  const body = req.body || {};
  let text;
  let currency;
  let today;
  try {
    text = requireString(body.text, 'text', { min: 1, max: LIMITS.AI_TEXT_MAX, trim: true });
    currency = body.currency === undefined || body.currency === null || body.currency === ''
      ? 'CNY'
      : requireString(body.currency, 'currency', { min: 1, max: 8, trim: true });
    today = body.today === undefined || body.today === null || body.today === ''
      ? undefined
      : requireDateString(body.today, 'today');
  } catch (err) {
    next(err);
    return;
  }

  const pre = precheck(req, res);
  if (!pre) return;

  try {
    const result = await parseLedger({ text, currency, today });

    // 记录调用（用于每日限额统计）
    recordAiUsage({
      userId: req.user.id,
      endpoint: 'parse-ledger',
      model: result.model,
      ok: true,
      promptLen: text.length,
    });

    log.info('AI 记账解析完成', {
      userId: req.user.id,
      items: result.items.length,
      model: result.model,
    });

    // API.md §7 响应结构
    ok(res, {
      items: result.items,
      summary: result.summary,
      total: result.total,
      model: result.model,
    });
  } catch (err) {
    // 失败也计数，避免刷失败请求绕过限额
    recordAiUsage({
      userId: req.user.id,
      endpoint: 'parse-ledger',
      model: pre.model,
      ok: false,
      promptLen: text.length,
    });
    next(err);
  }
});

// POST /api/ai/monthly-report
router.post('/monthly-report', requireAuth, aiLimiter, async (req, res, next) => {
  const body = req.body || {};
  let month;
  let totals;
  let byCategory;
  let days;
  try {
    month = requireMonthString(body.month, 'month');

    if (!isPlainObject(body.totals)) fail('totals 必须是 JSON 对象');
    const expense = Number(body.totals.expense ?? 0);
    const income = Number(body.totals.income ?? 0);
    if (!Number.isFinite(expense) || expense < 0) fail('totals.expense 必须是非负数字');
    if (!Number.isFinite(income) || income < 0) fail('totals.income 必须是非负数字');
    totals = { expense, income };

    if (body.byCategory !== undefined && body.byCategory !== null) {
      if (!Array.isArray(body.byCategory)) fail('byCategory 必须是数组');
      if (body.byCategory.length > 100) fail('byCategory 最多 100 项');
      byCategory = body.byCategory.map((c, i) => {
        if (!isPlainObject(c)) fail(`byCategory[${i}] 必须是 JSON 对象`);
        const category = requireString(c.category, `byCategory[${i}].category`, { min: 1, max: 32, trim: true });
        const amount = Number(c.amount);
        if (!Number.isFinite(amount) || amount < 0) fail(`byCategory[${i}].amount 必须是非负数字`);
        return { category, amount };
      });
    }

    days = queryInt(body.days, 'days', { min: 1, max: 366 });
  } catch (err) {
    next(err);
    return;
  }

  const pre = precheck(req, res);
  if (!pre) return;

  try {
    const result = await monthlyReport({ month, totals, byCategory, days });

    recordAiUsage({
      userId: req.user.id,
      endpoint: 'monthly-report',
      model: result.model,
      ok: true,
      promptLen: 0,
    });

    log.info('AI 月度报告生成完成', { userId: req.user.id, month, model: result.model });

    // API.md §7 响应 { "text": ..., "model": ... }
    ok(res, { text: result.text, model: result.model });
  } catch (err) {
    recordAiUsage({
      userId: req.user.id,
      endpoint: 'monthly-report',
      model: pre.model,
      ok: false,
      promptLen: 0,
    });
    next(err);
  }
});

export { AiError };
export default router;
