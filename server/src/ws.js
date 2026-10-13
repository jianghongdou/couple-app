// WebSocket 连接管理、在线状态与事件广播。
//
// 连接方式： /ws?token=<jwt>（也支持 Authorization 头）
// 服务端 -> 客户端事件（API.md §8）：
//   hello / message / read / typing / presence / sync / pong / error
// 客户端 -> 服务端事件：
//   typing / ping
import { WebSocketServer } from 'ws';
import { verifyUserToken } from './middleware/auth.js';
import { getUserById, touchUserLastSeen } from './db.js';
import { log } from './util/log.js';

/** @type {WebSocketServer|null} */
let wss = null;

/**
 * 连接表：userId -> Set<WebSocket>
 * 同一用户允许多端同时在线（iPhone + iPad）
 * @type {Map<string, Set<import('ws').WebSocket>>}
 */
const connections = new Map();

/** 用户最后的在线状态变化时间：userId -> lastSeen(ms) */
const lastSeen = new Map();

// 心跳：服务端主动 ping，30 秒一轮，剔除死连接
const HEARTBEAT_INTERVAL_MS = 30_000;
let heartbeatTimer = null;

/**
 * 发送 JSON 事件（失败则忽略，避免因单个坏连接影响广播）
 * @param {import('ws').WebSocket} ws
 * @param {object} payload
 */
function send(ws, payload) {
  if (ws.readyState !== ws.OPEN) return false;
  try {
    ws.send(JSON.stringify(payload));
    return true;
  } catch (err) {
    log.debug('WebSocket 发送失败', { error: err.message });
    return false;
  }
}

/**
 * 给指定用户的所有连接发送事件
 * @param {string} userId
 * @param {object} payload
 */
export function sendToUser(userId, payload) {
  const set = connections.get(userId);
  if (!set || set.size === 0) return 0;
  let n = 0;
  for (const ws of set) {
    if (send(ws, payload)) n += 1;
  }
  return n;
}

/**
 * 给某个情侣空间的所有在线成员广播事件
 * @param {string} coupleId
 * @param {object} payload
 * @param {string} [exceptUserId] 排除某个用户（例如事件发起者）
 */
export function broadcastToCouple(coupleId, payload, exceptUserId) {
  if (!coupleId) return 0;
  let n = 0;
  for (const [userId, set] of connections) {
    if (exceptUserId && userId === exceptUserId) continue;
    for (const ws of set) {
      // 连接上记录了 coupleId，避免为已解绑的用户继续推送
      if (ws.coupleId !== coupleId) continue;
      if (send(ws, payload)) n += 1;
    }
  }
  return n;
}

/** 某用户是否在线 */
export function isOnline(userId) {
  const set = connections.get(userId);
  return !!set && set.size > 0;
}

/** 在线用户数 */
export function onlineCount() {
  return connections.size;
}

/** 最近一次在线时间 */
export function getLastSeen(userId) {
  return lastSeen.get(userId) ?? null;
}

/**
 * 广播 presence 事件给伴侣
 * @param {import('ws').WebSocket} ws
 * @param {boolean} online
 */
function announcePresence(ws, online) {
  if (!ws.coupleId) return;
  broadcastToCouple(
    ws.coupleId,
    {
      type: 'presence',
      userId: ws.userId,
      online,
      lastSeen: Date.now(),
    },
    ws.userId
  );
}

function register(ws) {
  let set = connections.get(ws.userId);
  if (!set) {
    set = new Set();
    connections.set(ws.userId, set);
  }
  const wasOffline = set.size === 0;
  set.add(ws);
  if (wasOffline) {
    lastSeen.set(ws.userId, Date.now());
    announcePresence(ws, true);
  }
}

function unregister(ws) {
  const set = connections.get(ws.userId);
  if (!set) return;
  set.delete(ws);
  if (set.size === 0) {
    connections.delete(ws.userId);
    lastSeen.set(ws.userId, Date.now());
    announcePresence(ws, false);
  }
}

/**
 * 附加 WebSocket 服务到 HTTP server。
 * @param {import('node:http').Server} server
 * @param {{path?:string}} [opts]
 * @returns {WebSocketServer}
 */
export function attachWebSocket(server, opts = {}) {
  const path = opts.path || '/ws';
  wss = new WebSocketServer({ noServer: true });

  // 手动处理 upgrade，便于在握手阶段做鉴权并以合适的状态码拒绝
  server.on('upgrade', (req, socket, head) => {
    let url;
    try {
      url = new URL(req.url, `http://${req.headers.host || 'localhost'}`);
    } catch {
      socket.destroy();
      return;
    }

    if (url.pathname !== path) {
      socket.write('HTTP/1.1 404 Not Found\r\n\r\n');
      socket.destroy();
      return;
    }

    // 鉴权：Authorization 头 或 ?token=
    let token = null;
    const header = req.headers.authorization;
    if (typeof header === 'string' && header.startsWith('Bearer ')) {
      token = header.slice(7).trim();
    }
    if (!token) token = url.searchParams.get('token');

    if (!token) {
      socket.write('HTTP/1.1 401 Unauthorized\r\n\r\n');
      socket.destroy();
      return;
    }

    const found = verifyUserToken(token);
    if (!found || found.row.disabled) {
      socket.write('HTTP/1.1 401 Unauthorized\r\n\r\n');
      socket.destroy();
      return;
    }

    wss.handleUpgrade(req, socket, head, (ws) => {
      ws.userId = found.user.id;
      ws.user = found.user;
      ws.coupleId = found.user.coupleId;
      wss.emit('connection', ws, req);
    });
  });

  wss.on('connection', (ws) => {
    register(ws);
    touchUserLastSeen(ws.userId);
    log.info('WebSocket 已连接', { userId: ws.userId, online: onlineCount() });

    // 握手事件：hello（API.md §8）
    send(ws, {
      type: 'hello',
      user: ws.user,
      coupleId: ws.coupleId ?? null,
      serverTime: Date.now(),
    });

    // 连接建立时把伴侣的在线状态同步给本端
    if (ws.coupleId) {
      send(ws, {
        type: 'presence',
        userId: ws.userId,
        online: true,
        lastSeen: Date.now(),
      });
    }

    ws.isAlive = true;
    ws.on('pong', () => {
      ws.isAlive = true;
    });

    ws.on('message', (raw) => {
      let msg;
      try {
        msg = JSON.parse(raw.toString());
      } catch {
        send(ws, { type: 'error', message: '消息格式必须是 JSON' });
        return;
      }
      if (!msg || typeof msg !== 'object' || typeof msg.type !== 'string') {
        send(ws, { type: 'error', message: '缺少 type 字段' });
        return;
      }

      switch (msg.type) {
        case 'ping':
          // 客户端每 25 秒 ping 一次保活（API.md §8）
          touchUserLastSeen(ws.userId);
          send(ws, { type: 'pong', serverTime: Date.now() });
          break;

        case 'typing':
          if (ws.coupleId) {
            broadcastToCouple(
              ws.coupleId,
              { type: 'typing', userId: ws.userId, at: Date.now() },
              ws.userId
            );
          }
          break;

        default:
          send(ws, { type: 'error', message: `不支持的事件类型：${msg.type}` });
      }
    });

    ws.on('close', () => {
      unregister(ws);
      log.info('WebSocket 已断开', { userId: ws.userId, online: onlineCount() });
    });

    ws.on('error', (err) => {
      log.warn('WebSocket 连接错误', { userId: ws.userId, error: err.message });
    });
  });

  // 心跳巡检：清理半开连接
  heartbeatTimer = setInterval(() => {
    if (!wss) return;
    for (const ws of wss.clients) {
      if (ws.isAlive === false) {
        ws.terminate();
        continue;
      }
      ws.isAlive = false;
      try {
        ws.ping();
      } catch {
        /* 忽略 */
      }
    }
  }, HEARTBEAT_INTERVAL_MS);
  if (typeof heartbeatTimer.unref === 'function') heartbeatTimer.unref();

  log.info('WebSocket 服务已挂载', { path });
  return wss;
}

/**
 * 用户绑定关系变化后刷新其连接上缓存的 coupleId
 * （例如刚加入情侣空间，后续广播才能命中）
 * @param {string} userId
 * @param {string|null} coupleId
 */
export function refreshUserCouple(userId, coupleId) {
  const set = connections.get(userId);
  if (!set) return;
  for (const ws of set) ws.coupleId = coupleId;
}

/** 关闭 WebSocket 服务（优雅退出/测试） */
export function closeWebSocket() {
  if (heartbeatTimer) {
    clearInterval(heartbeatTimer);
    heartbeatTimer = null;
  }
  if (wss) {
    for (const ws of wss.clients) {
      try {
        ws.close(1001, 'server shutting down');
      } catch {
        /* 忽略 */
      }
    }
    wss.close();
    wss = null;
  }
  connections.clear();
  lastSeen.clear();
}

export default {
  attachWebSocket,
  broadcastToCouple,
  sendToUser,
  isOnline,
  onlineCount,
  closeWebSocket,
  refreshUserCouple,
};
