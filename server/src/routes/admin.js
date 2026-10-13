// 后台管理接口（API.md §9）
//
// 所有写操作都会记入 admin_logs。
import crypto from 'node:crypto';
import express from 'express';
import bcrypt from 'bcryptjs';
import { LIMITS } from '../config.js';
import {
  listUsers,
  getUserById,
  setUserDisabled,
  updateUserPassword,
  deleteUser,
  getStats,
  getDiskUsage,
  listAdminLogs,
  countEmojiPacks,
  countEmojiItems,
  countOwnPacks,
} from '../db.js';
import {
  requireAdmin,
  verifyAdminPassword,
  signAdminToken,
  logAdminAction,
} from '../middleware/adminAuth.js';
import {
  readSettingsForAdmin,
  updateSettings,
  readSettings,
  isAiConfigured,
} from '../services/settings.js';
import { parseLedger, AiError } from '../services/deepseek.js';
import { requireString, optionalBool, queryInt, fail } from '../util/validate.js';
import { rateLimit } from '../util/ratelimit.js';
import { log } from '../util/log.js';

const router = express.Router();

const BCRYPT_ROUNDS = 10;

/** 统一的成功响应 */
function ok(res, payload) {
  res.status(200).json({ ...payload, serverTime: Date.now() });
}

// 后台登录限流：同一 IP 15 分钟最多 10 次
const loginLimiter = rateLimit({
  limit: 10,
  windowMs: 15 * 60 * 1000,
  keyGenerator: (req) => `adminlogin:${req.ip}`,
  message: '登录尝试过于频繁，请稍后再试',
});

// ---------------------------------------------------------------------------
// 登录（唯一不需要鉴权的后台接口）
// ---------------------------------------------------------------------------

// POST /admin/api/login  { password } -> { token }
router.post('/login', loginLimiter, (req, res) => {
  const password = req.body?.password;

  // 密码比较使用常量时间算法（crypto.timingSafeEqual）
  const valid = verifyAdminPassword(password);

  if (!valid) {
    logAdminAction(req, 'admin.login.failed', '密码错误');
    log.warn('后台登录失败', { ip: req.ip });
    res.status(401).json({ error: 'unauthorized', message: '密码错误', serverTime: Date.now() });
    return;
  }

  const token = signAdminToken();
  logAdminAction(req, 'admin.login.success', '登录成功');
  log.info('后台登录成功', { ip: req.ip });
  ok(res, { token });
});

// ---------------------------------------------------------------------------
// 以下全部需要后台 token
// ---------------------------------------------------------------------------
router.use(requireAdmin);

// GET /admin/api/settings
router.get('/settings', (req, res, next) => {
  try {
    ok(res, readSettingsForAdmin());
  } catch (err) {
    next(err);
  }
});

// PUT /admin/api/settings
// deepseekApiKey 传明文即覆盖，传空字符串表示清空
router.put('/settings', (req, res, next) => {
  try {
    const body = req.body || {};
    if (Object.keys(body).length === 0) fail('请求体不能为空');

    // 记录变更字段名（不记录 Key 明文）
    const changed = Object.keys(body).filter((k) => k !== 'deepseekApiKey');
    const keyChanged = Object.prototype.hasOwnProperty.call(body, 'deepseekApiKey');

    const settings = updateSettings(body);

    const detailParts = [];
    if (changed.length > 0) detailParts.push(`字段：${changed.join('、')}`);
    if (keyChanged) {
      detailParts.push(body.deepseekApiKey ? 'DeepSeek Key：已更新' : 'DeepSeek Key：已清空');
    }
    logAdminAction(req, 'settings.update', detailParts.join('；') || '无变更');

    log.info('后台更新设置', { fields: changed.join(',') || '(none)', keyChanged });
    ok(res, { ok: true, settings });
  } catch (err) {
    next(err);
  }
});

// POST /admin/api/ai/test —— 用当前（或临时传入的）Key 试跑一条文本
router.post('/ai/test', async (req, res, next) => {
  try {
    const body = req.body || {};
    const text = body.text === undefined || body.text === null || String(body.text).trim() === ''
      ? '今天午饭25，打车18块5'
      : requireString(body.text, 'text', { min: 1, max: LIMITS.AI_TEXT_MAX, trim: true });
    const today = new Date().toISOString().slice(0, 10);

    // 允许临时传入 Key / 模型用于「先测通再保存」的场景
    const overrideKey = typeof body.deepseekApiKey === 'string' ? body.deepseekApiKey.trim() : '';
    const overrideModel = typeof body.deepseekModel === 'string' ? body.deepseekModel.trim() : '';
    const overrideBaseUrl = typeof body.deepseekBaseUrl === 'string' ? body.deepseekBaseUrl.trim() : '';

    if (overrideKey || overrideModel || overrideBaseUrl) {
      // 先保存当前「明文」配置，测试后原样恢复。
      // 注意：必须用 readSettings()（返回解密后的明文），不能用 readSettingsForAdmin()
      // 的掩码值，否则会把 Key 覆盖成 "sk-****abcd" 这样的掩码。
      const saved = readSettings();
      const patch = {};
      if (overrideKey) patch.deepseekApiKey = overrideKey;
      if (overrideModel) patch.deepseekModel = overrideModel;
      if (overrideBaseUrl) patch.deepseekBaseUrl = overrideBaseUrl;
      updateSettings(patch);
      try {
        const result = await parseLedger({ text, currency: 'CNY', today });
        logAdminAction(req, 'ai.test', `临时配置测试成功，识别 ${result.items.length} 条`);
        ok(res, {
          ok: true,
          usingTemporaryKey: true,
          text: result.summary,
          items: result.items,
          total: result.total,
          model: result.model,
        });
      } finally {
        // 恢复原配置（明文；空字符串表示清空）
        updateSettings({
          deepseekApiKey: saved.deepseekApiKey || '',
          deepseekModel: saved.deepseekModel,
          deepseekBaseUrl: saved.deepseekBaseUrl,
        });
      }
      return;
    }

    if (!isAiConfigured()) {
      res.status(403).json({ error: 'forbidden', message: 'AI 未配置', serverTime: Date.now() });
      return;
    }

    const result = await parseLedger({ text, currency: 'CNY', today });
    logAdminAction(req, 'ai.test', `测试成功，识别 ${result.items.length} 条`);
    ok(res, {
      ok: true,
      usingTemporaryKey: false,
      text: result.summary,
      items: result.items,
      total: result.total,
      model: result.model,
    });
  } catch (err) {
    if (err instanceof AiError) {
      logAdminAction(req, 'ai.test.failed', err.message);
      res.status(err.status || 502).json({ error: err.errorCode || 'server_error', message: err.message, serverTime: Date.now() });
      return;
    }
    next(err);
  }
});

// GET /admin/api/stats —— 用户数/情侣数/消息数/今日 AI 调用/磁盘占用
router.get('/stats', (req, res, next) => {
  try {
    const stats = getStats();
    const disk = getDiskUsage();
    ok(res, {
      users: stats.users,
      couples: stats.couples,
      couplesTotal: stats.couplesTotal,
      messages: stats.messages,
      aiToday: stats.aiToday,
      aiTotal: stats.aiTotal,
      packs: stats.packs,
      items: stats.items,
      syncObjects: stats.syncObjects,
      disk: {
        dbBytes: disk.dbBytes,
        emojiBytes: disk.emojiBytes,
        emojiFiles: disk.emojiFiles,
        totalBytes: disk.totalBytes,
      },
      uptimeSeconds: Math.floor(process.uptime()),
    });
  } catch (err) {
    next(err);
  }
});

// GET /admin/api/users?query=&page=
router.get('/users', (req, res, next) => {
  try {
    const query = typeof req.query.query === 'string' ? req.query.query.trim().slice(0, 64) : '';
    const page = queryInt(req.query.page, 'page', { defaultValue: 1, min: 1, max: 100000 });
    const pageSize = queryInt(req.query.pageSize, 'pageSize', { defaultValue: 20, min: 1, max: 100 });

    const result = listUsers({ query, page, pageSize });
    ok(res, result);
  } catch (err) {
    next(err);
  }
});

// POST /admin/api/users/:id/reset-password  { newPassword }
router.post('/users/:id/reset-password', async (req, res, next) => {
  try {
    const id = requireString(req.params.id, 'id', { min: 1, max: 64 });
    const newPassword = requireString(req.body?.newPassword, 'newPassword', {
      min: LIMITS.PASSWORD_MIN,
      max: LIMITS.PASSWORD_MAX,
    });

    const found = getUserById(id);
    if (!found) {
      res.status(404).json({ error: 'not_found', message: '用户不存在', serverTime: Date.now() });
      return;
    }

    const hash = await bcrypt.hash(newPassword, BCRYPT_ROUNDS);
    updateUserPassword(id, hash);

    logAdminAction(req, 'user.reset_password', `用户 ${found.user.username}（${id}）`);
    log.info('后台重置用户密码', { userId: id });
    ok(res, { ok: true });
  } catch (err) {
    next(err);
  }
});

// POST /admin/api/users/:id/toggle-disabled  { disabled }
router.post('/users/:id/toggle-disabled', (req, res, next) => {
  try {
    const id = requireString(req.params.id, 'id', { min: 1, max: 64 });
    const disabled = optionalBool(req.body?.disabled, 'disabled', undefined);
    if (disabled === undefined) fail('disabled 必须是布尔值');

    const found = getUserById(id);
    if (!found) {
      res.status(404).json({ error: 'not_found', message: '用户不存在', serverTime: Date.now() });
      return;
    }

    setUserDisabled(id, disabled);
    logAdminAction(req, 'user.toggle_disabled', `用户 ${found.user.username}（${id}）-> ${disabled ? '禁用' : '启用'}`);
    log.info('后台修改用户禁用状态', { userId: id, disabled });
    ok(res, { ok: true, user: getUserById(id).user });
  } catch (err) {
    next(err);
  }
});

// DELETE /admin/api/users/:id
router.delete('/users/:id', (req, res, next) => {
  try {
    const id = requireString(req.params.id, 'id', { min: 1, max: 64 });
    const found = getUserById(id);
    if (!found) {
      res.status(404).json({ error: 'not_found', message: '用户不存在', serverTime: Date.now() });
      return;
    }

    deleteUser(id);
    logAdminAction(req, 'user.delete', `用户 ${found.user.username}（${id}）`);
    log.warn('后台删除用户', { userId: id, username: found.user.username });
    ok(res, { ok: true });
  } catch (err) {
    next(err);
  }
});

// GET /admin/api/packs —— 表情包统计
router.get('/packs', (req, res, next) => {
  try {
    const totalPacks = countEmojiPacks();
    const totalItems = countEmojiItems();
    const disk = getDiskUsage();

    ok(res, {
      totalPacks,
      totalItems,
      emojiBytes: disk.emojiBytes,
      emojiFiles: disk.emojiFiles,
      maxPacksPerCouple: LIMITS.EMOJI_MAX_PACKS,
      maxItemsPerPack: LIMITS.EMOJI_MAX_ITEMS_PER_PACK,
      maxItemBytes: LIMITS.EMOJI_MAX_BYTES,
    });
  } catch (err) {
    next(err);
  }
});

// GET /admin/api/logs?limit=100
router.get('/logs', (req, res, next) => {
  try {
    const limit = queryInt(req.query.limit, 'limit', { defaultValue: 100, min: 1, max: 500 });
    ok(res, { logs: listAdminLogs(limit) });
  } catch (err) {
    next(err);
  }
});

// GET /admin/api/system —— 附加系统信息（后台页面展示用）
router.get('/system', (req, res, next) => {
  try {
    ok(res, {
      node: process.version,
      platform: `${process.platform} ${process.arch}`,
      pid: process.pid,
      uptimeSeconds: Math.floor(process.uptime()),
      memoryBytes: process.memoryUsage().rss,
      env: {
        port: Number(process.env.PORT) || 8787,
        logLevel: process.env.LOG_LEVEL || 'info',
        trustProxy: process.env.TRUST_PROXY !== '0',
      },
    });
  } catch (err) {
    next(err);
  }
});

export default router;
