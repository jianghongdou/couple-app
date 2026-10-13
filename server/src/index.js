// 服务入口：express + http + ws 挂载
//
// 同时服务：
//   - REST API      /api/*
//   - WebSocket     /ws?token=<jwt>
//   - 后台页面      /admin
//   - 表情二进制    /api/emoji/blob/:id
import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import express from 'express';
import { APP_VERSION, CONFIG, ADMIN_HTML_PATH, PUBLIC_EMOJI_DIR, assertConfig, ensureDirs } from './config.js';
import { initDb, closeDb, purgeDissolvedCouples, syncPublicEmojiPacks } from './db.js';
import { setLevel, log } from './util/log.js';
import { ValidationError } from './util/validate.js';
import { attachWebSocket, closeWebSocket, onlineCount } from './ws.js';
import { LIMITS } from './config.js';
import { sniffImageMime } from './routes/emoji.js';

import authRoutes from './routes/auth.js';
import coupleRoutes from './routes/couple.js';
import syncRoutes from './routes/sync.js';
import chatRoutes from './routes/chat.js';
import emojiRoutes from './routes/emoji.js';
import aiRoutes from './routes/ai.js';
import metaRoutes from './routes/meta.js';
import adminRoutes from './routes/admin.js';

// ---------------------------------------------------------------------------
// 启动前校验与初始化
// ---------------------------------------------------------------------------

// 缺少 JWT_SECRET / ADMIN_PASSWORD 时明确报错退出
assertConfig();
setLevel(CONFIG.logLevel);
// 确保 data/ 与表情目录存在（启动时自动创建）
ensureDirs();
initDb();

// 同步内置公共表情包（public/emoji/<包名>/<图片>）
// 这些包对所有用户可见（API.md §6 的 isPublic=true）
try {
  const seeded = syncPublicEmojiPacks(PUBLIC_EMOJI_DIR, {
    sniffMime: sniffImageMime,
    allowedMimes: LIMITS.EMOJI_MIMES,
  });
  if (seeded.packs > 0) {
    log.info('已同步内置公共表情包', seeded);
  }
} catch (err) {
  // 公共表情同步失败不应阻止服务启动
  log.warn('同步内置公共表情包失败', { error: err.message });
}

// ---------------------------------------------------------------------------
// Express 应用
// ---------------------------------------------------------------------------

const app = express();

// 信任反向代理的 X-Forwarded-For（TRUST_PROXY，默认 1）
app.set('trust proxy', CONFIG.trustProxy ? 1 : false);
// 关闭 x-powered-by，减少指纹
app.disable('x-powered-by');

// JSON 解析：表情走 base64，放宽到 4MB（业务上限 1MB 图片）
app.use(express.json({ limit: '4mb' }));

// 统一安全响应头
app.use((req, res, next) => {
  res.setHeader('X-Content-Type-Options', 'nosniff');
  res.setHeader('Referrer-Policy', 'no-referrer');
  // 接口响应明确不允许被任何缓存代理缓存
  if (req.path.startsWith('/api/') || req.path.startsWith('/admin/api/')) {
    res.setHeader('Cache-Control', 'no-store');
  }
  next();
});

// 访问日志（debug 级别）
app.use((req, res, next) => {
  const startedAt = Date.now();
  res.on('finish', () => {
    log.debug('HTTP 请求', {
      method: req.method,
      path: req.originalUrl.split('?')[0],
      status: res.statusCode,
      ms: Date.now() - startedAt,
      ip: req.ip,
    });
  });
  next();
});

// 后台页面：读取一次并缓存，注入 <base href="/admin/"> 保证静态资源相对路径正确
let adminHtmlCache = null;
function getAdminHtml() {
  if (adminHtmlCache === null) {
    adminHtmlCache = fs.readFileSync(ADMIN_HTML_PATH, 'utf8');
  }
  // 注入 base 标签（若尚未注入）
  if (!adminHtmlCache.includes('data-injected-base')) {
    adminHtmlCache = adminHtmlCache.replace(
      /<head([^>]*)>/i,
      (m) => `${m}\n  <base href="/admin/" data-injected-base>`
    );
  }
  return adminHtmlCache;
}

app.get(['/admin', '/admin/'], (req, res, next) => {
  try {
    res.setHeader('Content-Type', 'text/html; charset=utf-8');
    res.setHeader('Cache-Control', 'no-store');
    res.send(getAdminHtml());
  } catch (err) {
    log.error('读取后台页面失败', { error: err.message });
    next(err);
  }
});

// 后台接口
app.use('/admin/api', adminRoutes);

// REST API
app.use('/api/auth', authRoutes);
app.use('/api/couple', coupleRoutes);
app.use('/api/sync', syncRoutes);
app.use('/api/chat', chatRoutes);
app.use('/api/emoji', emojiRoutes);
app.use('/api/ai', aiRoutes);
// /api/health、/api/meta 等公开接口
app.use('/api', metaRoutes);

// 静态资源（内置公共表情包目录 + 后台页面同目录资源）
app.use('/static', express.static(path.join(PUBLIC_EMOJI_DIR, '..'), { fallthrough: true, index: false }));

// 根路径：给一个简短的说明页，避免直接访问报 404
app.get('/', (req, res) => {
  res.setHeader('Content-Type', 'text/html; charset=utf-8');
  res.send(
    `<!doctype html><html lang="zh-CN"><head><meta charset="utf-8">` +
      `<meta name="viewport" content="width=device-width,initial-scale=1">` +
      `<title>情侣空间服务端</title>` +
      `<style>body{background:#0f1115;color:#e6e8eb;font-family:-apple-system,BlinkMacSystemFont,"PingFang SC",sans-serif;` +
      `display:flex;align-items:center;justify-content:center;height:100vh;margin:0}` +
      `.box{text-align:center;line-height:1.9}a{color:#ff7a9c;text-decoration:none}` +
      `code{background:#1b1f27;padding:2px 6px;border-radius:4px}</style></head><body><div class="box">` +
      `<h1>情侣空间服务端</h1><p>版本 <code>${APP_VERSION}</code> · 在线设备 <code>${onlineCount()}</code></p>` +
      `<p><a href="/admin">进入后台管理</a></p>` +
      `<p><a href="/api/health">/api/health</a> · <a href="/api/meta">/api/meta</a></p>` +
      `</div></body></html>`
  );
});

// 404：未匹配的接口统一返回 not_found
app.use((req, res) => {
  res.status(404).json({
    error: 'not_found',
    message: `接口不存在：${req.method} ${req.path}`,
    serverTime: Date.now(),
  });
});

// 统一错误处理：把异常映射为 API.md 规定的错误码
// eslint-disable-next-line no-unused-vars -- express 通过 4 个参数识别错误中间件
app.use((err, req, res, next) => {
  // 参数校验错误 -> 400 invalid_input（或自定义 code/status）
  if (err instanceof ValidationError) {
    res.status(err.status || 400).json({
      error: err.errorCode || 'invalid_input',
      message: err.message,
      serverTime: Date.now(),
    });
    return;
  }

  // JSON 解析失败
  if (err && (err.type === 'entity.parse.failed' || err instanceof SyntaxError)) {
    res.status(400).json({ error: 'invalid_input', message: '请求体不是合法的 JSON', serverTime: Date.now() });
    return;
  }

  // 请求体过大
  if (err && err.type === 'entity.too.large') {
    res.status(413).json({
      error: 'invalid_input',
      message: `请求体过大（上限 ${LIMITS.EMOJI_MAX_BYTES / 1024 / 1024 + 3}MB）`,
      serverTime: Date.now(),
    });
    return;
  }

  // SQLite 约束冲突
  if (err && String(err.code || '').startsWith('SQLITE_CONSTRAINT')) {
    log.warn('数据库约束冲突', { error: err.message });
    res.status(409).json({ error: 'conflict', message: '数据冲突，请刷新后重试', serverTime: Date.now() });
    return;
  }

  const status = Number(err?.status) || 500;
  if (status >= 500) {
    log.error('未处理的服务端异常', {
      error: err?.message,
      stack: err?.stack?.split('\n').slice(0, 4).join(' | '),
      path: req.originalUrl,
    });
  }
  res.status(status >= 400 && status < 600 ? status : 500).json({
    error: status === 403 ? 'forbidden' : status === 401 ? 'unauthorized' : 'server_error',
    message: status >= 500 ? '服务器内部错误' : err?.message || '请求失败',
    serverTime: Date.now(),
  });
});

// ---------------------------------------------------------------------------
// HTTP + WebSocket
// ---------------------------------------------------------------------------

const server = http.createServer(app);
attachWebSocket(server, { path: '/ws' });

// 每天清理一次超过保留期的已解绑情侣数据（默认 30 天）
const purgeTimer = setInterval(() => {
  try {
    purgeDissolvedCouples(LIMITS.COUPLE_RETENTION_DAYS);
  } catch (err) {
    log.error('清理过期数据失败', { error: err.message });
  }
}, 24 * 60 * 60 * 1000);
if (typeof purgeTimer.unref === 'function') purgeTimer.unref();

let shuttingDown = false;

/** 优雅退出 */
function shutdown(signal) {
  if (shuttingDown) return;
  shuttingDown = true;
  log.info('收到退出信号，开始优雅关闭', { signal });

  // 停止接受新连接
  server.close(() => {
    log.info('HTTP 服务已关闭');
  });

  closeWebSocket();

  // 给在途请求一点时间
  setTimeout(() => {
    try {
      closeDb();
    } catch (err) {
      log.error('关闭数据库失败', { error: err.message });
    }
    log.info('服务已退出');
    process.exit(0);
  }, 300).unref();
}

process.on('SIGTERM', () => shutdown('SIGTERM'));
process.on('SIGINT', () => shutdown('SIGINT'));

// 未捕获异常：记录但尽量不崩（避免单点异常导致服务不可用）
process.on('unhandledRejection', (reason) => {
  log.error('未处理的 Promise 拒绝', { error: reason instanceof Error ? reason.message : String(reason) });
});
process.on('uncaughtException', (err) => {
  log.error('未捕获异常', { error: err.message, stack: err.stack?.split('\n').slice(0, 4).join(' | ') });
});

// 端口被占用等启动错误：明确报错退出
server.on('error', (err) => {
  if (err.code === 'EADDRINUSE') {
    log.error(`端口 ${CONFIG.port} 已被占用，请更换 PORT 或结束占用进程`);
  } else {
    log.error('HTTP 服务启动失败', { error: err.message });
  }
  process.exit(1);
});

server.listen(CONFIG.port, () => {
  log.info('服务已启动', {
    version: APP_VERSION,
    port: CONFIG.port,
    dataDir: CONFIG.dataDir,
    adminUrl: `http://127.0.0.1:${CONFIG.port}/admin`,
    wsPath: '/ws',
  });
  // 控制台友好提示（非 JSON，便于人工确认）
  process.stdout.write(
    `\n✅ 情侣空间服务端已启动  v${APP_VERSION}\n` +
      `   本机地址： http://127.0.0.1:${CONFIG.port}\n` +
      `   后台管理： http://127.0.0.1:${CONFIG.port}/admin\n` +
      `   健康检查： http://127.0.0.1:${CONFIG.port}/api/health\n` +
      `   数据目录： ${CONFIG.dataDir}\n\n`
  );
});

export { app, server };
