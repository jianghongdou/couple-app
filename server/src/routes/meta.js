// 公开接口：健康检查与客户端元信息（API.md §1）
import express from 'express';
import { APP_VERSION, APP_DISPLAY_NAME } from '../config.js';
import { pingDb, getStats } from '../db.js';
import { readSettings } from '../services/settings.js';
import { onlineCount as wsOnlineCount } from '../ws.js';
import { log } from '../util/log.js';

const router = express.Router();

// GET /api/health
// 响应 { ok, name, version, time }（API.md §1 精确结构）
router.get('/health', (req, res) => {
  res.status(200).json({
    ok: true,
    name: 'couple-server',
    version: APP_VERSION,
    time: Date.now(),
  });
});

// GET /api/meta —— 无需鉴权，客户端启动时拉取
router.get('/meta', (req, res, next) => {
  try {
    const settings = readSettings();

    // features.ai 反映「后台 AI 开关」；是否已配置 Key 单独由 aiConfigured 表达，
    // 客户端可以据此区分「功能被关闭」和「功能开启但尚未配置」。
    const aiOn = settings.aiEnabled !== false;
    const aiConfigured = !!settings.deepseekApiKey;

    res.status(200).json({
      appName: APP_DISPLAY_NAME,
      serverVersion: APP_VERSION,
      minAppBuild: Number.isInteger(settings.minAppBuild) ? settings.minAppBuild : 1,
      latestAppBuild: Number.isInteger(settings.latestAppBuild) ? settings.latestAppBuild : 1,
      updateURL: typeof settings.updateURL === 'string' ? settings.updateURL : '',
      announcement: typeof settings.announcement === 'string' ? settings.announcement : '',
      registerOpen: settings.registerOpen !== false,
      features: {
        chat: true,
        emoji: true,
        // AI 功能总开关
        ai: aiOn,
        sync: true,
      },
      aiConfigured,
      serverTime: Date.now(),
    });
  } catch (err) {
    next(err);
  }
});

// GET /api/status —— 运维概览（公开只读，不含敏感信息）
router.get('/status', (req, res, next) => {
  try {
    const stats = getStats();
    res.status(200).json({
      ok: true,
      version: APP_VERSION,
      users: stats.users,
      couples: stats.couples,
      messages: stats.messages,
      online: wsOnlineCount(),
      dbOk: pingDb(),
      uptimeSeconds: Math.floor(process.uptime()),
      serverTime: Date.now(),
    });
  } catch (err) {
    log.error('/api/status 失败', { error: err.message });
    next(err);
  }
});

export default router;
