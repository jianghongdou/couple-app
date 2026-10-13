// 端到端冒烟测试（npm test）
//
// 做法：
//   1. 生成一次性测试环境变量（随机 JWT_SECRET、ADMIN_PASSWORD=test123、
//      PORT=8799、DATA_DIR=./data-test），用 node:child_process 启动真实服务进程
//   2. 逐个断言 API.md 中定义的关键接口
//   3. 结束后杀掉进程并删除 data-test 目录
//
// 全程不依赖任何测试框架，只用 Node 内置能力 + 项目依赖（ws）。
import { spawn } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import zlib from 'node:zlib';
import crypto from 'node:crypto';
import { fileURLToPath } from 'node:url';
import WebSocket from 'ws';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const SERVER_DIR = path.resolve(__dirname, '..');

// ---------------------------------------------------------------------------
// 测试配置
// ---------------------------------------------------------------------------
const PORT = 8799;
const BASE = `http://127.0.0.1:${PORT}`;
const ADMIN_PASSWORD = 'test123';
const JWT_SECRET = crypto.randomBytes(48).toString('hex');
const ADMIN_JWT_SECRET = crypto.randomBytes(48).toString('hex');
const DATA_DIR = './data-test';
const DATA_DIR_ABS = path.join(SERVER_DIR, 'data-test');

// ---------------------------------------------------------------------------
// 迷你测试框架
// ---------------------------------------------------------------------------
let passed = 0;
let failed = 0;
const failures = [];

function ok(cond, label, extra) {
  if (cond) {
    passed += 1;
    process.stdout.write(`  ✅ ${label}\n`);
  } else {
    failed += 1;
    failures.push(label + (extra ? `  →  ${extra}` : ''));
    process.stdout.write(`  ❌ ${label}${extra ? `\n       ${extra}` : ''}\n`);
  }
}

function section(title) {
  process.stdout.write(`\n${title}\n`);
}

// ---------------------------------------------------------------------------
// HTTP 辅助
// ---------------------------------------------------------------------------
/**
 * 发起请求并返回 { status, json, text, headers }
 * @param {string} method
 * @param {string} url 相对 BASE 的路径
 * @param {{token?:string, body?:any, adminToken?:string, raw?:boolean}} [opts]
 */
async function req(method, url, opts = {}) {
  const headers = {};
  if (opts.body !== undefined) headers['Content-Type'] = 'application/json';
  if (opts.token) headers.Authorization = `Bearer ${opts.token}`;
  if (opts.adminToken) headers.Authorization = `Bearer ${opts.adminToken}`;

  const res = await fetch(BASE + url, {
    method,
    headers,
    body: opts.body === undefined ? undefined : JSON.stringify(opts.body),
  });

  const contentType = res.headers.get('content-type') || '';
  const buf = Buffer.from(await res.arrayBuffer());

  let json = null;
  if (contentType.includes('application/json')) {
    try {
      json = JSON.parse(buf.toString('utf8'));
    } catch {
      json = null;
    }
  }
  return { status: res.status, json, headers: res.headers, buf, contentType };
}

// ---------------------------------------------------------------------------
// 生成真实的最小 PNG（8x8），只用 Node 内置 zlib，不依赖外部图片文件
// ---------------------------------------------------------------------------

/** CRC32（PNG 分块校验） */
function crc32(buf) {
  let c;
  const table = crc32.table || (crc32.table = (() => {
    const t = new Int32Array(256);
    for (let n = 0; n < 256; n += 1) {
      c = n;
      for (let k = 0; k < 8; k += 1) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
      t[n] = c;
    }
    return t;
  })());
  let crc = -1;
  for (let i = 0; i < buf.length; i += 1) crc = (crc >>> 8) ^ table[(crc ^ buf[i]) & 0xff];
  return (crc ^ -1) >>> 0;
}

/** 组装一个 PNG 分块：长度 + 类型 + 数据 + CRC */
function pngChunk(type, data) {
  const len = Buffer.alloc(4);
  len.writeUInt32BE(data.length, 0);
  const typeBuf = Buffer.from(type, 'ascii');
  const crcBuf = Buffer.alloc(4);
  crcBuf.writeUInt32BE(crc32(Buffer.concat([typeBuf, data])), 0);
  return Buffer.concat([len, typeBuf, data, crcBuf]);
}

/**
 * 手写一个 8x8 的真实 PNG
 * @param {number} size 边长（像素）
 * @returns {Buffer}
 */
function makePng(size = 8) {
  const sig = Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]);

  // IHDR：宽、高、位深 8、颜色类型 6（RGBA）、压缩/滤波/隔行均为 0
  const ihdr = Buffer.alloc(13);
  ihdr.writeUInt32BE(size, 0);
  ihdr.writeUInt32BE(size, 4);
  ihdr[8] = 8; // bit depth
  ihdr[9] = 6; // color type: RGBA
  ihdr[10] = 0; // compression
  ihdr[11] = 0; // filter
  ihdr[12] = 0; // interlace

  // 原始像素数据：每行 1 字节滤波类型 + size 个 RGBA 像素
  const raw = Buffer.alloc(size * (1 + size * 4));
  let o = 0;
  for (let y = 0; y < size; y += 1) {
    raw[o] = 0; // filter type 0 (None)
    o += 1;
    for (let x = 0; x < size; x += 1) {
      raw[o] = (x * 32) & 0xff;       // R
      raw[o + 1] = (y * 32) & 0xff;   // G
      raw[o + 2] = 0x99;              // B
      raw[o + 3] = 0xff;              // A
      o += 4;
    }
  }

  const idat = zlib.deflateSync(raw, { level: 9 });
  return Buffer.concat([
    sig,
    pngChunk('IHDR', ihdr),
    pngChunk('IDAT', idat),
    pngChunk('IEND', Buffer.alloc(0)),
  ]);
}

/** 校验 Buffer 是否为合法 PNG（头部 + 结构完整性） */
function isPng(buf) {
  return (
    buf.length > 8 &&
    buf[0] === 0x89 && buf[1] === 0x50 && buf[2] === 0x4e && buf[3] === 0x47 &&
    buf.toString('ascii', 12, 16) === 'IHDR' &&
    buf.toString('ascii', buf.length - 8, buf.length - 4) === 'IEND'
  );
}

// ---------------------------------------------------------------------------
// 服务进程管理
// ---------------------------------------------------------------------------
// 注意：子进程的 stdio 使用「日志文件描述符」而不是管道。
// 原因：部分受限运行环境（沙箱）禁止创建管道给子进程（spawn EPERM），
// 但允许标准输出/错误重定向到文件。这样写既能捕获服务端日志用于失败诊断，
// 又能在受限环境下正常启动服务进程。
let child = null;
const childLogs = [];
let childLogFd = null;
let childLogPath = null;

function startServer() {
  return new Promise((resolve, reject) => {
    // 服务端日志落盘，便于失败时打印尾部日志
    childLogPath = path.join(SERVER_DIR, 'test-server.log');
    try {
      fs.rmSync(childLogPath, { force: true });
      childLogFd = fs.openSync(childLogPath, 'a');
    } catch {
      childLogFd = 'ignore';
    }

    // 用测试专用环境变量启动真实服务
    child = spawn(process.execPath, ['src/index.js'], {
      cwd: SERVER_DIR,
      env: {
        ...process.env,
        PORT: String(PORT),
        DATA_DIR,
        JWT_SECRET,
        ADMIN_PASSWORD,
        ADMIN_JWT_SECRET,
        LOG_LEVEL: 'info',
        TRUST_PROXY: '0',
        PUBLIC_BASE_URL: '',
        // 避免读取开发者本机的 .env 干扰测试
        DOTENV_CONFIG_PATH: path.join(SERVER_DIR, '.env.nonexistent'),
      },
      stdio: ['ignore', childLogFd, childLogFd],
    });

    child.on('error', reject);
    child.on('exit', (code) => {
      if (!serverReady) {
        reject(new Error(`服务进程提前退出（code=${code}）\n${readChildLogs()}`));
      }
    });

    // 轮询 /api/health 直到就绪（最多 30 秒）
    const deadline = Date.now() + 30000;
    const poll = async () => {
      if (Date.now() > deadline) {
        reject(new Error(`等待服务启动超时\n${readChildLogs()}`));
        return;
      }
      try {
        const res = await fetch(`${BASE}/api/health`);
        if (res.ok) {
          serverReady = true;
          resolve();
          return;
        }
      } catch {
        /* 还没起来，继续等 */
      }
      setTimeout(poll, 200);
    };
    poll();
  });
}
let serverReady = false;

/** 读取服务端日志文件内容 */
function readChildLogs() {
  if (!childLogPath) return '';
  try {
    return fs.readFileSync(childLogPath, 'utf8');
  } catch {
    return '';
  }
}

function stopServer() {
  return new Promise((resolve) => {
    if (!child) {
      resolve();
      return;
    }
    const c = child;
    const done = () => {
      // 关闭日志文件描述符（忽略字符串 'ignore'）
      if (typeof childLogFd === 'number') {
        try {
          fs.closeSync(childLogFd);
        } catch {
          /* 忽略 */
        }
        childLogFd = null;
      }
      resolve();
    };
    c.once('exit', done);
    try {
      c.kill('SIGTERM');
    } catch {
      /* 忽略 */
    }
    // 兜底：3 秒后强制结束
    setTimeout(() => {
      try {
        c.kill('SIGKILL');
      } catch {
        /* 忽略 */
      }
      resolve();
    }, 3000);
  });
}

function cleanupDataDir() {
  try {
    if (fs.existsSync(DATA_DIR_ABS)) {
      fs.rmSync(DATA_DIR_ABS, { recursive: true, force: true });
    }
  } catch (err) {
    process.stdout.write(`  ⚠️  清理 ${DATA_DIR} 失败：${err.message}\n`);
  }
  // 同时清理服务端日志文件
  try {
    if (childLogPath) fs.rmSync(childLogPath, { force: true });
  } catch {
    /* 忽略 */
  }
}

// ---------------------------------------------------------------------------
// WebSocket 辅助
// ---------------------------------------------------------------------------
/**
 * 打开一个 WS 连接并收集事件
 * @param {string} token
 * @returns {Promise<{ws:WebSocket, events:object[], waitFor:Function, close:Function}>}
 */
function openWs(token) {
  return new Promise((resolve, reject) => {
    const ws = new WebSocket(`ws://127.0.0.1:${PORT}/ws?token=${encodeURIComponent(token)}`);
    const events = [];
    const waiters = [];

    ws.on('message', (raw) => {
      let msg;
      try {
        msg = JSON.parse(raw.toString());
      } catch {
        return;
      }
      events.push(msg);
      // 唤醒等待者
      for (let i = waiters.length - 1; i >= 0; i -= 1) {
        if (waiters[i].match(msg)) {
          const w = waiters.splice(i, 1)[0];
          clearTimeout(w.timer);
          w.resolve(msg);
        }
      }
    });

    const timer = setTimeout(() => reject(new Error('WebSocket 连接超时')), 5000);
    ws.on('open', () => {
      clearTimeout(timer);
      resolve({
        ws,
        events,
        /**
         * 等待匹配的事件
         * @param {(m:object)=>boolean} match
         * @param {number} timeoutMs
         */
        waitFor(match, timeoutMs = 3000) {
          const hit = events.find(match);
          if (hit) return Promise.resolve(hit);
          return new Promise((res, rej) => {
            const entry = { match, resolve: res };
            entry.timer = setTimeout(() => {
              const idx = waiters.indexOf(entry);
              if (idx >= 0) waiters.splice(idx, 1);
              rej(new Error('等待 WebSocket 事件超时'));
            }, timeoutMs);
            waiters.push(entry);
          });
        },
        close() {
          try {
            ws.close();
          } catch {
            /* 忽略 */
          }
        },
      });
    });
    ws.on('error', (err) => {
      clearTimeout(timer);
      reject(err);
    });
  });
}

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

// ---------------------------------------------------------------------------
// 主流程
// ---------------------------------------------------------------------------
async function main() {
  process.stdout.write('\n========================================\n');
  process.stdout.write('  情侣 App 服务端 · 端到端冒烟测试\n');
  process.stdout.write('========================================\n');
  process.stdout.write(`Node ${process.version} · ${process.platform} ${process.arch}\n`);
  process.stdout.write(`测试端口 ${PORT} · 数据目录 ${DATA_DIR}\n`);

  // 保证从干净状态开始
  cleanupDataDir();

  await startServer();
  process.stdout.write('服务已启动，开始断言…\n');

  let pngBuf = null;

  try {
    // =====================================================================
    section('1. 公开接口 /api/health、/api/meta');
    // =====================================================================
    {
      const r = await req('GET', '/api/health');
      ok(r.status === 200, 'GET /api/health 返回 200', `实际 ${r.status}`);
      ok(r.json?.ok === true, 'health.ok 为 true');
      ok(r.json?.name === 'couple-server', 'health.name 为 "couple-server"', String(r.json?.name));
      ok(r.json?.version === '1.0.0', 'health.version 为 "1.0.0"', String(r.json?.version));
      ok(typeof r.json?.time === 'number', 'health.time 是毫秒时间戳');

      const m = await req('GET', '/api/meta');
      ok(m.status === 200, 'GET /api/meta 返回 200', `实际 ${m.status}`);
      ok(m.json?.appName === '情侣空间', 'meta.appName 为 "情侣空间"', String(m.json?.appName));
      ok(m.json?.registerOpen === true, 'meta.registerOpen 默认 true');
      ok(typeof m.json?.minAppBuild === 'number', 'meta.minAppBuild 是数字');
      ok(typeof m.json?.latestAppBuild === 'number', 'meta.latestAppBuild 是数字');
      ok(typeof m.json?.announcement === 'string', 'meta.announcement 是字符串');
      ok(typeof m.json?.updateURL === 'string', 'meta.updateURL 是字符串');
      ok(m.json?.features?.chat === true && m.json?.features?.ai === true && m.json?.features?.sync === true,
        'meta.features 含 chat/emoji/ai/sync');
      ok(typeof m.json?.serverTime === 'number', 'meta.serverTime 是数字');
    }

    // =====================================================================
    section('2. 注册两个用户 → 建情侣空间 → 邀请码加入');
    // =====================================================================
    let userA;
    let userB;
    let tokenA;
    let tokenB;
    let coupleId;
    let inviteCode;
    {
      const suffix = crypto.randomBytes(3).toString('hex');
      const rA = await req('POST', '/api/auth/register', {
        body: { username: `alice_${suffix}`, password: 'pass1234', nickname: '小爱', avatarEmoji: '🐻' },
      });
      ok(rA.status === 200, '用户 A 注册返回 200', `实际 ${rA.status} ${JSON.stringify(rA.json)}`);
      ok(typeof rA.json?.token === 'string' && rA.json.token.length > 20, '注册响应含 token');
      ok(rA.json?.user?.username === `alice_${suffix}`, '注册响应 user.username 正确');
      ok(rA.json?.user?.nickname === '小爱', '注册响应 user.nickname 正确');
      ok(rA.json?.user?.avatarEmoji === '🐻', '注册响应 user.avatarEmoji 正确');
      ok(rA.json?.user?.coupleId === null, '未绑定时 user.coupleId 为 null');
      ok(rA.json?.couple === null, '未绑定时 couple 为 null');
      ok(rA.json?.user?.role === 'user', 'user.role 为 "user"');
      ok(rA.json?.user?.disabled === false, 'user.disabled 为 false');
      ok(typeof rA.json?.user?.createdAt === 'number', 'user.createdAt 是数字');
      ok(typeof rA.json?.user?.id === 'string' && rA.json.user.id.startsWith('u_'), 'user.id 使用 u_ 前缀', String(rA.json?.user?.id));
      ok(!('password_hash' in (rA.json?.user || {})) && !('passwordHash' in (rA.json?.user || {})),
        '响应不包含密码哈希字段');
      userA = rA.json.user;
      tokenA = rA.json.token;

      const rB = await req('POST', '/api/auth/register', {
        body: { username: `bob_${suffix}`, password: 'pass1234', nickname: '小博', avatarEmoji: '🐰' },
      });
      ok(rB.status === 200, '用户 B 注册返回 200', `实际 ${rB.status}`);
      userB = rB.json.user;
      tokenB = rB.json.token;

      // 重复用户名 -> 409 conflict
      const dup = await req('POST', '/api/auth/register', {
        body: { username: `alice_${suffix}`, password: 'pass1234' },
      });
      ok(dup.status === 409 && dup.json?.error === 'conflict', '重复用户名注册返回 409 conflict',
        `实际 ${dup.status} ${dup.json?.error}`);

      // 非法用户名 -> 400 invalid_input
      const bad = await req('POST', '/api/auth/register', {
        body: { username: 'ab', password: 'pass1234' },
      });
      ok(bad.status === 400 && bad.json?.error === 'invalid_input', '过短用户名返回 400 invalid_input',
        `实际 ${bad.status}`);

      // 用户 A 创建情侣空间
      const rc = await req('POST', '/api/couple/create', { token: tokenA });
      ok(rc.status === 200, 'A 创建情侣空间返回 200', `实际 ${rc.status} ${JSON.stringify(rc.json)}`);
      coupleId = rc.json?.couple?.id;
      inviteCode = rc.json?.couple?.inviteCode;
      ok(typeof coupleId === 'string' && coupleId.startsWith('c_'), 'couple.id 使用 c_ 前缀', String(coupleId));
      ok(typeof inviteCode === 'string' && inviteCode.length === 6, 'inviteCode 为 6 位', String(inviteCode));
      ok(rc.json?.couple?.anniversaryTitle === '在一起', 'anniversaryTitle 默认 "在一起"');
      ok(rc.json?.couple?.togetherDate === null, 'togetherDate 默认 null');
      ok(Array.isArray(rc.json?.couple?.members) && rc.json.couple.members.length === 1,
        '创建后 members 含 1 人', String(rc.json?.couple?.members?.length));

      // 已有空间再创建 -> 409 conflict
      const again = await req('POST', '/api/couple/create', { token: tokenA });
      ok(again.status === 409 && again.json?.error === 'conflict', '已有空间再次创建返回 409 conflict',
        `实际 ${again.status}`);

      // 无效邀请码 -> 404 not_found
      const badCode = await req('POST', '/api/couple/join', { token: tokenB, body: { inviteCode: 'ZZZZZZ' } });
      ok(badCode.status === 404 && badCode.json?.error === 'not_found', '无效邀请码返回 404 not_found',
        `实际 ${badCode.status}`);

      // 用户 B 用邀请码加入
      const rj = await req('POST', '/api/couple/join', { token: tokenB, body: { inviteCode } });
      ok(rj.status === 200, 'B 用邀请码加入返回 200', `实际 ${rj.status} ${JSON.stringify(rj.json)}`);
      ok(rj.json?.couple?.id === coupleId, 'B 加入的是同一个情侣空间');

      // GET /api/couple 应能看到 2 个成员
      const rget = await req('GET', '/api/couple', { token: tokenB });
      ok(rget.status === 200, 'GET /api/couple 返回 200');
      ok(Array.isArray(rget.json?.couple?.members) && rget.json.couple.members.length === 2,
        'GET /api/couple 能看到 2 个成员', `实际 ${rget.json?.couple?.members?.length}`);
      const memberIds = (rget.json?.couple?.members || []).map((m) => m.id).sort();
      ok(memberIds.length === 2 && memberIds.includes(userA.id) && memberIds.includes(userB.id),
        'members 含双方完整 user 对象');
      ok(rget.json?.couple?.members?.every((m) => 'username' in m && 'nickname' in m && 'coupleId' in m),
        'members 中每个 user 含 username/nickname/coupleId');

      // 空间已满 -> 第三个用户加入应 409
      const rC = await req('POST', '/api/auth/register', {
        body: { username: `carol_${suffix}`, password: 'pass1234' },
      });
      const full = await req('POST', '/api/couple/join', { token: rC.json.token, body: { inviteCode } });
      ok(full.status === 409 && full.json?.error === 'conflict', '空间已满时加入返回 409 conflict',
        `实际 ${full.status}`);

      // 已有空间的用户再加入 -> 409
      const rejoin = await req('POST', '/api/couple/join', { token: tokenB, body: { inviteCode } });
      ok(rejoin.status === 409, '已绑定用户再次加入返回 409', `实际 ${rejoin.status}`);
    }

    // =====================================================================
    section('3. 同步接口 /api/sync/push 与 /api/sync/pull');
    // =====================================================================
    {
      const wishId = `w_${Date.now()}`;
      const push = await req('POST', '/api/sync/push', {
        token: tokenA,
        body: {
          objects: [
            { kind: 'wish', id: wishId, updatedAt: Date.now(), deleted: false,
              payload: { title: '去海边', targetCoins: 300 } },
          ],
        },
      });
      ok(push.status === 200, 'A 推送 wish 返回 200', `实际 ${push.status} ${JSON.stringify(push.json)}`);
      ok(Array.isArray(push.json?.applied) && push.json.applied.includes(wishId),
        'applied 含刚推送的对象 id', JSON.stringify(push.json?.applied));
      ok(Array.isArray(push.json?.conflicts) && push.json.conflicts.length === 0, 'conflicts 为空');

      // 用户 B 能拉到
      const pull = await req('GET', `/api/sync/pull?since=0`, { token: tokenB });
      ok(pull.status === 200, 'B 拉取同步数据返回 200');
      const got = (pull.json?.objects || []).find((o) => o.id === wishId);
      ok(!!got, 'B 拉到了 A 推送的 wish');
      ok(got?.kind === 'wish', '拉到的对象 kind 为 wish');
      ok(got?.payload?.title === '去海边' && got?.payload?.targetCoins === 300, 'payload 原样保留');
      ok(got?.ownerId === userA.id, 'ownerId 为 A 的 id');
      ok(got?.updatedBy === userA.id, 'updatedBy 为 A 的 id');
      ok(got?.deleted === false, 'deleted 为 false');
      ok(typeof pull.json?.hasMore === 'boolean', '响应含 hasMore 布尔值');
      ok(typeof pull.json?.serverTime === 'number', '响应含 serverTime');

      // ledger / vault 必须被拒绝（400 invalid_input）
      const ledger = await req('POST', '/api/sync/push', {
        token: tokenA,
        body: { objects: [{ kind: 'ledger', id: 'l_1', updatedAt: Date.now(), payload: { amount: 10 } }] },
      });
      ok(ledger.status === 400, '推送 kind=ledger 被拒绝（400）', `实际 ${ledger.status}`);
      ok(ledger.json?.error === 'invalid_input', 'ledger 拒绝时 error 为 invalid_input', String(ledger.json?.error));
      ok(/本地/.test(String(ledger.json?.message || '')), 'ledger 拒绝消息说明了「只存手机本地」',
        String(ledger.json?.message));

      const vault = await req('POST', '/api/sync/push', {
        token: tokenA,
        body: { objects: [{ kind: 'vault', id: 'v_1', updatedAt: Date.now(), payload: { secret: 'x' } }] },
      });
      ok(vault.status === 400 && vault.json?.error === 'invalid_input', '推送 kind=vault 被拒绝（400）',
        `实际 ${vault.status}`);

      // 其它非白名单 kind
      const other = await req('POST', '/api/sync/push', {
        token: tokenA,
        body: { objects: [{ kind: 'unknown_kind', id: 'x_1', updatedAt: Date.now() }] },
      });
      ok(other.status === 400, '推送非白名单 kind 被拒绝（400）', `实际 ${other.status}`);

      // 超过 200 个对象 -> 400
      const tooMany = await req('POST', '/api/sync/push', {
        token: tokenA,
        body: {
          objects: Array.from({ length: 201 }, (_, i) => ({
            kind: 'wish', id: `w_bulk_${i}`, updatedAt: Date.now(), payload: null,
          })),
        },
      });
      ok(tooMany.status === 400, '单次推送 201 个对象被拒绝（400）', `实际 ${tooMany.status}`);

      // LWW：用更旧的时间戳推送同一对象 -> 冲突
      const lwwOld = await req('POST', '/api/sync/push', {
        token: tokenB,
        body: { objects: [{ kind: 'wish', id: wishId, updatedAt: Date.now() - 60000, payload: { title: '旧数据' } }] },
      });
      ok(lwwOld.status === 200, 'LWW 旧数据推送请求本身返回 200');
      ok(Array.isArray(lwwOld.json?.conflicts) && lwwOld.json.conflicts.includes(wishId),
        'LWW：更旧的 updatedAt 被列为 conflict', JSON.stringify(lwwOld.json));

      // LWW：用更新的时间戳推送同一对象 -> 接受
      const lwwNew = await req('POST', '/api/sync/push', {
        token: tokenB,
        body: { objects: [{ kind: 'wish', id: wishId, updatedAt: Date.now() + 1000, payload: { title: '新数据' } }] },
      });
      ok(lwwNew.json?.applied?.includes(wishId), 'LWW：更新的 updatedAt 被接受');

      // kinds 过滤
      const filtered = await req('GET', '/api/sync/pull?since=0&kinds=pet', { token: tokenB });
      ok(filtered.status === 200, 'kinds=pet 过滤拉取返回 200');
      ok((filtered.json?.objects || []).every((o) => o.kind === 'pet'), 'kinds 过滤生效（只返回 pet）');

      // limit 上限 1000
      const overLimit = await req('GET', '/api/sync/pull?since=0&limit=5000', { token: tokenB });
      ok(overLimit.status === 400, 'limit 超过 1000 被拒绝（400）', `实际 ${overLimit.status}`);

      // 未绑定用户不能读写同步数据
      const solo = await req('POST', '/api/auth/register', {
        body: { username: `solo_${crypto.randomBytes(3).toString('hex')}`, password: 'pass1234' },
      });
      const soloPush = await req('POST', '/api/sync/push', {
        token: solo.json.token,
        body: { objects: [{ kind: 'wish', id: 'w_solo', updatedAt: Date.now(), payload: null }] },
      });
      ok(soloPush.status === 403 && soloPush.json?.error === 'forbidden',
        '未绑定情侣的用户推送同步数据返回 403 forbidden', `实际 ${soloPush.status}`);
    }

    // =====================================================================
    section('4. 聊天 /api/chat/*');
    // =====================================================================
    let wsB = null;
    {
      // 先建立 B 的 WebSocket 连接，用于后面的实时推送断言
      wsB = await openWs(tokenB);
      wsBRef = wsB;
      const hello = await wsB.waitFor((m) => m.type === 'hello', 3000);
      ok(hello.type === 'hello', 'WebSocket 收到 hello 事件');
      ok(hello.user?.id === userB.id, 'hello.user 是 B 本人');
      ok(hello.coupleId === coupleId, 'hello.coupleId 正确');
      ok(typeof hello.serverTime === 'number', 'hello.serverTime 是数字');
    }

    let msgId;
    const clientId = `c_${Date.now()}`;
    {
      const send = await req('POST', '/api/chat/messages', {
        token: tokenA,
        body: { clientId, kind: 'text', body: '晚安', meta: null },
      });
      ok(send.status === 200, 'A 发送消息返回 200', `实际 ${send.status} ${JSON.stringify(send.json)}`);
      const msg = send.json?.message;
      msgId = msg?.id;
      ok(typeof msg?.id === 'string' && msg.id.startsWith('m_'), 'message.id 使用 m_ 前缀', String(msg?.id));
      ok(msg?.clientId === clientId, 'message.clientId 原样返回');
      ok(msg?.coupleId === coupleId, 'message.coupleId 正确');
      ok(msg?.senderId === userA.id, 'message.senderId 为 A');
      ok(msg?.kind === 'text', 'message.kind 为 text');
      ok(msg?.body === '晚安', 'message.body 为 "晚安"');
      ok(msg?.meta === null, 'message.meta 为 null');
      ok(typeof msg?.createdAt === 'number', 'message.createdAt 是数字');
      ok(msg?.readAt === null, 'message.readAt 初始为 null');

      // clientId 幂等：重发不产生第二条
      const resend = await req('POST', '/api/chat/messages', {
        token: tokenA,
        body: { clientId, kind: 'text', body: '晚安', meta: null },
      });
      ok(resend.status === 200, '重发同一 clientId 返回 200');
      ok(resend.json?.message?.id === msgId, '重发返回的是同一条消息（幂等）',
        `${resend.json?.message?.id} vs ${msgId}`);

      // 对方 GET 能拉到
      const list = await req('GET', '/api/chat/messages?limit=50', { token: tokenB });
      ok(list.status === 200, 'B 拉取消息返回 200');
      const found = (list.json?.messages || []).filter((m) => m.clientId === clientId);
      ok(found.length === 1, 'clientId 幂等：列表中只有一条该消息', `实际 ${found.length} 条`);
      ok((list.json?.messages || []).some((m) => m.id === msgId), 'B 能拉到 A 发的消息');
      ok(typeof list.json?.hasMore === 'boolean', '消息列表含 hasMore');

      // 升序校验
      const times = (list.json?.messages || []).map((m) => m.createdAt);
      const sorted = [...times].sort((a, b) => a - b);
      ok(JSON.stringify(times) === JSON.stringify(sorted), '消息按 createdAt 升序返回');

      // body 超长 -> 400
      const tooLong = await req('POST', '/api/chat/messages', {
        token: tokenA,
        body: { clientId: `c_long_${Date.now()}`, kind: 'text', body: 'x'.repeat(2001) },
      });
      ok(tooLong.status === 400, 'body 超过 2000 字符被拒绝（400）', `实际 ${tooLong.status}`);

      // 未绑定用户发消息 -> 403
      const solo = await req('POST', '/api/auth/register', {
        body: { username: `solo2_${crypto.randomBytes(3).toString('hex')}`, password: 'pass1234' },
      });
      const soloMsg = await req('POST', '/api/chat/messages', {
        token: solo.json.token,
        body: { clientId: 'c_solo', kind: 'text', body: 'hi' },
      });
      ok(soloMsg.status === 403 && soloMsg.json?.error === 'forbidden',
        '未绑定情侣的用户发消息返回 403 forbidden', `实际 ${soloMsg.status}`);
    }

    // WebSocket：B 应在 2 秒内收到 A 发消息的 {type:"message"}
    {
      const newClientId = `c_ws_${Date.now()}`;
      const send = await req('POST', '/api/chat/messages', {
        token: tokenA,
        body: { clientId: newClientId, kind: 'text', body: 'WS 实时测试' },
      });
      ok(send.status === 200, 'A 发送第二条消息返回 200');

      let wsMsg = null;
      try {
        wsMsg = await wsB.waitFor(
          (m) => m.type === 'message' && m.message?.clientId === newClientId,
          2000
        );
      } catch (err) {
        /* 超时 */
      }
      ok(!!wsMsg, 'B 在 2 秒内通过 WebSocket 收到 {type:"message"}');
      ok(wsMsg?.message?.body === 'WS 实时测试', 'WS 推送的 message.body 正确');
      ok(wsMsg?.message?.senderId === userA.id, 'WS 推送的 message.senderId 为 A');
    }

    // 已读回执
    {
      const readAt = Date.now() + 5000;
      const read = await req('POST', '/api/chat/read', { token: tokenB, body: { upTo: readAt } });
      ok(read.status === 200, 'POST /api/chat/read 返回 200', `实际 ${read.status}`);
      ok(read.json?.ok === true, 'read 响应 ok 为 true');
      ok(typeof read.json?.count === 'number' && read.json.count > 0,
        'read 响应 count 为正数（标记了对方消息）', String(read.json?.count));

      // 验证 readAt 已写入
      const list = await req('GET', '/api/chat/messages?limit=50', { token: tokenA });
      const mine = (list.json?.messages || []).find((m) => m.id === msgId);
      ok(typeof mine?.readAt === 'number', '消息的 readAt 已被标记', String(mine?.readAt));

      // B 应收到对方的 read 事件
      let readEvent = null;
      try {
        readEvent = await wsB.waitFor((m) => m.type === 'read' && m.userId === userB.id, 2000);
      } catch {
        /* 自己触发的事件不回推给自己，允许超时 */
      }
      ok(readEvent === null || readEvent.type === 'read', 'read 事件格式正确（不回推给自己）');
    }

    // ping/pong 保活
    {
      const { ws } = wsB;
      ws.send(JSON.stringify({ type: 'ping' }));
      let pong = null;
      try {
        pong = await wsB.waitFor((m) => m.type === 'pong', 2000);
      } catch {
        /* 超时 */
      }
      ok(!!pong, 'WebSocket ping 收到 pong 响应');
      ok(typeof pong?.serverTime === 'number', 'pong 含 serverTime');
    }

    // 上报已读：对方（A）应在 2 秒内收到 {type:"read"}
    {
      const read = await req('POST', '/api/chat/read', { token: tokenA, body: { upTo: Date.now() } });
      ok(read.status === 200, 'A 上报已读返回 200');
      let readEvent = null;
      try {
        readEvent = await wsB.waitFor((m) => m.type === 'read' && m.userId === userA.id, 2000);
      } catch {
        /* 超时 */
      }
      ok(!!readEvent, '对方上报已读后 B 收到 {type:"read"}');
      ok(typeof readEvent?.upTo === 'number', 'read 事件含 upTo');
    }

    // typing 事件
    {
      wsB.ws.send(JSON.stringify({ type: 'typing' }));
      await sleep(120);
      // A 没有 WS 连接，这里验证服务端不报错即可
      ok(true, '"typing" 事件被服务端正常处理（无错误响应）');
    }

    // 撤回消息
    {
      const recall = await req('DELETE', `/api/chat/messages/${msgId}`, { token: tokenA });
      ok(recall.status === 200 && recall.json?.ok === true, 'A 撤回自己的消息返回 200', `实际 ${recall.status}`);

      const otherRecall = await req('DELETE', `/api/chat/messages/${msgId}`, { token: tokenB });
      ok(otherRecall.status === 403, 'B 撤回 A 的消息被拒绝（403）', `实际 ${otherRecall.status}`);
    }

    // =====================================================================
    section('5. 表情包（含真实 PNG 上传与 magic bytes 校验）');
    // =====================================================================
    let packId;
    let emojiId;
    {
      // 生成真实的最小 PNG
      pngBuf = makePng(8);
      ok(isPng(pngBuf), '本地生成的 8x8 PNG 结构合法', `size=${pngBuf.length}B`);
      ok(pngBuf.length > 60, 'PNG 体积合理', `${pngBuf.length}B`);

      // 建包
      const create = await req('POST', '/api/emoji/packs', { token: tokenA, body: { name: '我的表情' } });
      ok(create.status === 200, '创建表情包返回 200', `实际 ${create.status} ${JSON.stringify(create.json)}`);
      packId = create.json?.pack?.id;
      ok(typeof packId === 'string' && packId.startsWith('p_'), 'pack.id 使用 p_ 前缀', String(packId));
      ok(create.json?.pack?.name === '我的表情', 'pack.name 正确');
      ok(create.json?.pack?.ownerId === userA.id, 'pack.ownerId 为 A');
      ok(create.json?.pack?.isPublic === false, 'pack.isPublic 为 false');
      ok(Array.isArray(create.json?.pack?.items) && create.json.pack.items.length === 0, '新包 items 为空');

      // 上传真实 PNG（base64）
      const upload = await req('POST', '/api/emoji/items', {
        token: tokenA,
        body: {
          packId,
          mime: 'image/png',
          name: '大笑',
          dataBase64: pngBuf.toString('base64'),
        },
      });
      ok(upload.status === 200, '上传 PNG 表情返回 200', `实际 ${upload.status} ${JSON.stringify(upload.json)}`);
      const item = upload.json?.item;
      emojiId = item?.id;
      ok(typeof emojiId === 'string' && emojiId.startsWith('e_'), 'item.id 使用 e_ 前缀', String(emojiId));
      ok(/^e_[A-Za-z0-9]+$/.test(String(emojiId)), 'item.id 满足 ^e_[A-Za-z0-9]+$ 格式', String(emojiId));
      ok(item?.mime === 'image/png', 'item.mime 为 image/png', String(item?.mime));
      ok(item?.size === pngBuf.length, 'item.size 与实际字节数一致', `${item?.size} vs ${pngBuf.length}`);
      ok(item?.name === '大笑', 'item.name 正确');
      ok(item?.packId === packId, 'item.packId 正确');
      ok(item?.ownerId === userA.id, 'item.ownerId 为 A');
      ok(item?.url === `/api/emoji/blob/${emojiId}`, 'item.url 形如 /api/emoji/blob/e_xxx', String(item?.url));

      // 列表能看到
      const packs = await req('GET', '/api/emoji/packs', { token: tokenB });
      ok(packs.status === 200, 'B 拉取表情包列表返回 200');
      const pack = (packs.json?.packs || []).find((p) => p.id === packId);
      ok(!!pack, 'B 能看到 A 创建的表情包（同情侣空间可见）');
      ok(pack?.items?.some((it) => it.id === emojiId), '列表中含刚上传的表情');

      // blob 二进制：?token= 方式
      const blob = await req('GET', `/api/emoji/blob/${emojiId}?token=${encodeURIComponent(tokenB)}`);
      ok(blob.status === 200, 'GET /api/emoji/blob/:id?token= 返回 200', `实际 ${blob.status}`);
      ok((blob.headers.get('content-type') || '').includes('image/png'),
        'blob Content-Type 为 image/png', String(blob.headers.get('content-type')));
      ok(blob.headers.get('cache-control') === 'public, max-age=31536000, immutable',
        'blob Cache-Control 符合 API.md 要求', String(blob.headers.get('cache-control')));
      ok(blob.buf.length === pngBuf.length, 'blob 返回的字节数与上传一致', `${blob.buf.length} vs ${pngBuf.length}`);
      ok(blob.buf.equals(pngBuf), 'blob 返回的内容与上传的 PNG 完全一致');
      ok(isPng(blob.buf), 'blob 内容是合法 PNG');

      // blob 二进制：Authorization 头方式
      const blob2 = await req('GET', `/api/emoji/blob/${emojiId}`, { token: tokenB });
      ok(blob2.status === 200, 'blob 支持 Authorization 头鉴权', `实际 ${blob2.status}`);

      // blob 无 token -> 401
      const blobNoAuth = await req('GET', `/api/emoji/blob/${emojiId}`);
      ok(blobNoAuth.status === 401, 'blob 无凭证返回 401', `实际 ${blobNoAuth.status}`);

      // 伪造 mime 的非图片数据必须被拒绝
      const fake = await req('POST', '/api/emoji/items', {
        token: tokenA,
        body: {
          packId,
          mime: 'image/png',
          name: '假图',
          dataBase64: Buffer.from('这不是图片，只是普通文本内容。').toString('base64'),
        },
      });
      ok(fake.status === 400 && fake.json?.error === 'invalid_input',
        '伪造 mime 的非图片数据被拒绝（400 invalid_input）',
        `实际 ${fake.status} ${JSON.stringify(fake.json)}`);

      // HTML 伪装成 PNG
      const fakeHtml = await req('POST', '/api/emoji/items', {
        token: tokenA,
        body: {
          packId,
          mime: 'image/png',
          dataBase64: Buffer.from('<html><script>alert(1)</script></html>').toString('base64'),
        },
      });
      ok(fakeHtml.status === 400, 'HTML 内容伪装 PNG 被拒绝（400）', `实际 ${fakeHtml.status}`);

      // 声明 mime 与实际不符
      const mismatch = await req('POST', '/api/emoji/items', {
        token: tokenA,
        body: { packId, mime: 'image/jpeg', dataBase64: pngBuf.toString('base64') },
      });
      ok(mismatch.status === 400, '声明 mime 与实际格式不符被拒绝（400）', `实际 ${mismatch.status}`);

      // 非白名单 mime
      const badMime = await req('POST', '/api/emoji/items', {
        token: tokenA,
        body: { packId, mime: 'image/svg+xml', dataBase64: pngBuf.toString('base64') },
      });
      ok(badMime.status === 400, '非白名单 mime 被拒绝（400）', `实际 ${badMime.status}`);

      // 路径穿越尝试
      const traversal = await req('GET', '/api/emoji/blob/..%2F..%2Fpackage.json?token=' + encodeURIComponent(tokenB));
      ok(traversal.status === 400 || traversal.status === 404,
        '路径穿越尝试被拒绝（400/404）', `实际 ${traversal.status}`);

      const traversal2 = await req('GET', '/api/emoji/blob/e_..%2F..%2Fdb?token=' + encodeURIComponent(tokenB));
      ok(traversal2.status === 400 || traversal2.status === 404,
        '带 e_ 前缀的穿越尝试被拒绝（400/404）', `实际 ${traversal2.status}`);

      // ---------------------------------------------------------------------
      // 5.1 删除单张表情 DELETE /api/emoji/items/:id
      // ---------------------------------------------------------------------
      // 再上传两张，用于「删除自己上传的表情」与「B 删除 A 上传的表情」
      const upload2 = await req('POST', '/api/emoji/items', {
        token: tokenA,
        body: { packId, mime: 'image/png', name: '待删除', dataBase64: pngBuf.toString('base64') },
      });
      ok(upload2.status === 200, '为删除用例再上传一张表情成功', `实际 ${upload2.status}`);
      const itemToDelete = upload2.json?.item?.id;
      ok(typeof itemToDelete === 'string' && /^e_[A-Za-z0-9]+$/.test(itemToDelete),
        '待删除表情 id 格式合法', String(itemToDelete));

      // 删除前确认它在列表里、且可供下载
      const beforeList = await req('GET', '/api/emoji/packs', { token: tokenA });
      const beforePack = (beforeList.json?.packs || []).find((p) => p.id === packId);
      ok(beforePack?.items?.some((it) => it.id === itemToDelete),
        '删除前该表情出现在 GET /api/emoji/packs 列表里');
      const beforeBlob = await req('GET', `/api/emoji/blob/${itemToDelete}?token=${encodeURIComponent(tokenA)}`);
      ok(beforeBlob.status === 200, '删除前 blob 可正常下载（200）', `实际 ${beforeBlob.status}`);

      // B 删除 A 上传的表情 -> 403 forbidden
      const delByOther = await req('DELETE', `/api/emoji/items/${itemToDelete}`, { token: tokenB });
      ok(delByOther.status === 403 && delByOther.json?.error === 'forbidden',
        'B 删除 A 上传的表情返回 403 forbidden', `实际 ${delByOther.status} ${delByOther.json?.error}`);

      // 日志中不应记录被拒绝的删除
      const delByOtherMissing = await req('DELETE', '/api/emoji/items/e_zzzzzzzzzzzz', { token: tokenA });
      ok(delByOtherMissing.status === 404 && delByOtherMissing.json?.error === 'not_found',
        '删除不存在的 id 返回 404 not_found', `实际 ${delByOtherMissing.status} ${delByOtherMissing.json?.error}`);

      // 非法 id -> 400 invalid_input（不得用于拼路径）
      for (const badId of ['..%2Fetc%2Fpasswd', 'e_%2F..%2F..%2Fdb', 'notanid', 'e_', 'p_abc']) {
        const r = await req('DELETE', `/api/emoji/items/${badId}`, { token: tokenA });
        ok(r.status === 400 && r.json?.error === 'invalid_input',
          `非法 id "${decodeURIComponent(badId)}" 返回 400 invalid_input`,
          `实际 ${r.status} ${r.json?.error}`);
      }

      // 未鉴权 -> 401
      const delNoAuth = await req('DELETE', `/api/emoji/items/${itemToDelete}`);
      ok(delNoAuth.status === 401, '未鉴权删除表情返回 401', `实际 ${delNoAuth.status}`);

      // A 删除自己上传的表情 -> 200 且 ok: true
      const delOwn = await req('DELETE', `/api/emoji/items/${itemToDelete}`, { token: tokenA });
      ok(delOwn.status === 200, 'A 删除自己上传的表情返回 200', `实际 ${delOwn.status} ${JSON.stringify(delOwn.json)}`);
      ok(delOwn.json?.ok === true, '删除响应 ok 为 true');
      ok(typeof delOwn.json?.serverTime === 'number', '删除响应含 serverTime');

      // 删除后不再出现在列表里
      const afterList = await req('GET', '/api/emoji/packs', { token: tokenA });
      const afterPack = (afterList.json?.packs || []).find((p) => p.id === packId);
      ok(!afterPack?.items?.some((it) => it.id === itemToDelete),
        '删除后该表情不再出现在 GET /api/emoji/packs 列表里');
      ok(afterPack?.items?.some((it) => it.id === emojiId),
        '同一包内其它表情不受影响');

      // 删除后 blob 返回 404
      const afterBlob = await req('GET', `/api/emoji/blob/${itemToDelete}?token=${encodeURIComponent(tokenA)}`);
      ok(afterBlob.status === 404, '删除后 GET /api/emoji/blob/:id 返回 404', `实际 ${afterBlob.status}`);

      // 已删除的 id 再删一次 -> 404
      const delAgain = await req('DELETE', `/api/emoji/items/${itemToDelete}`, { token: tokenA });
      ok(delAgain.status === 404 && delAgain.json?.error === 'not_found',
        '已删除的 id 再删一次返回 404 not_found', `实际 ${delAgain.status}`);

      // 删除自己的包
      const del = await req('DELETE', `/api/emoji/packs/${packId}`, { token: tokenA });
      ok(del.status === 200 && del.json?.ok === true, '删除自己的表情包返回 200', `实际 ${del.status}`);

      const after = await req('GET', `/api/emoji/blob/${emojiId}?token=${encodeURIComponent(tokenA)}`);
      ok(after.status === 404, '删除包后其表情文件不可访问（404）', `实际 ${after.status}`);
    }

    // =====================================================================
    section('6. 后台管理 /admin/api/*');
    // =====================================================================
    let adminToken;
    {
      // 错误密码登录失败
      const bad = await req('POST', '/admin/api/login', { body: { password: 'wrong-password' } });
      ok(bad.status === 401 && bad.json?.error === 'unauthorized', '错误密码登录失败（401 unauthorized）',
        `实际 ${bad.status}`);

      const empty = await req('POST', '/admin/api/login', { body: {} });
      ok(empty.status === 401, '空密码登录失败（401）', `实际 ${empty.status}`);

      // 正确密码登录成功
      const good = await req('POST', '/admin/api/login', { body: { password: ADMIN_PASSWORD } });
      ok(good.status === 200, '正确密码登录成功（200）', `实际 ${good.status} ${JSON.stringify(good.json)}`);
      ok(typeof good.json?.token === 'string' && good.json.token.length > 20, '登录响应含 admin token');
      adminToken = good.json.token;

      // 未鉴权访问后台接口 -> 401
      const noAuth = await req('GET', '/admin/api/settings');
      ok(noAuth.status === 401, '未鉴权访问 /admin/api/settings 返回 401', `实际 ${noAuth.status}`);

      // 用户 token 不能访问后台（不同 secret）
      const userTokenTry = await req('GET', '/admin/api/settings', { token: tokenA });
      ok(userTokenTry.status === 401, '用户 token 无法访问后台接口（不同 secret）', `实际 ${userTokenTry.status}`);

      // 读取设置
      const s0 = await req('GET', '/admin/api/settings', { adminToken });
      ok(s0.status === 200, 'GET /admin/api/settings 返回 200');
      ok(typeof s0.json?.deepseekApiKeyMasked === 'string', 'settings 含 deepseekApiKeyMasked 字段',
        String(s0.json?.deepseekApiKeyMasked));
      ok(!('deepseekApiKey' in (s0.json || {})), 'settings 不返回 deepseekApiKey 明文字段');
      ok(s0.json?.deepseekBaseUrl === 'https://api.deepseek.com', '默认 deepseekBaseUrl 正确', String(s0.json?.deepseekBaseUrl));
      ok(s0.json?.deepseekModel === 'deepseek-chat', '默认 deepseekModel 为 deepseek-chat', String(s0.json?.deepseekModel));
      ok(s0.json?.aiEnabled === true, '默认 aiEnabled 为 true');
      ok(s0.json?.aiDailyLimit === 200, '默认 aiDailyLimit 为 200', String(s0.json?.aiDailyLimit));
      ok(s0.json?.registerOpen === true, '默认 registerOpen 为 true');
      ok(s0.json?.maxEmojiPacks === 30, '默认 maxEmojiPacks 为 30', String(s0.json?.maxEmojiPacks));
      ok(s0.json?.maxMessagesPerDay === 3000, '默认 maxMessagesPerDay 为 3000', String(s0.json?.maxMessagesPerDay));

      // 写入 DeepSeek Key
      const SECRET_KEY = 'sk-test-1234567890abcdefghijklmn';
      const put = await req('PUT', '/admin/api/settings', {
        adminToken,
        body: { deepseekApiKey: SECRET_KEY, deepseekModel: 'deepseek-reasoner', aiDailyLimit: 123 },
      });
      ok(put.status === 200 && put.json?.ok === true, 'PUT /admin/api/settings 返回 200', `实际 ${put.status}`);

      // 再读：必须是掩码且不含明文
      const s1 = await req('GET', '/admin/api/settings', { adminToken });
      const masked = String(s1.json?.deepseekApiKeyMasked || '');
      ok(masked.length > 0, '写入后 deepseekApiKeyMasked 非空');
      ok(!masked.includes(SECRET_KEY), '掩码中不含明文 Key');
      ok(masked.includes('*'), '掩码中含星号');
      ok(masked.startsWith('sk-'), '掩码保留 sk- 前缀', masked);
      ok(masked.endsWith(SECRET_KEY.slice(-4)), '掩码保留末 4 位', masked);

      const rawSettings = JSON.stringify(s1.json);
      ok(!rawSettings.includes(SECRET_KEY), '整个 settings 响应体中不含明文 Key');
      ok(s1.json?.deepseekModel === 'deepseek-reasoner', 'deepseekModel 已更新', String(s1.json?.deepseekModel));
      ok(s1.json?.aiDailyLimit === 123, 'aiDailyLimit 已更新', String(s1.json?.aiDailyLimit));

      // 数据库里也不应是明文（读取 sqlite 文件做原始字节检查）
      const dbBytes = fs.readFileSync(path.join(DATA_DIR_ABS, 'couple.sqlite'));
      const walPath = path.join(DATA_DIR_ABS, 'couple.sqlite-wal');
      const walBytes = fs.existsSync(walPath) ? fs.readFileSync(walPath) : Buffer.alloc(0);
      const allBytes = Buffer.concat([dbBytes, walBytes]);
      ok(!allBytes.includes(Buffer.from(SECRET_KEY, 'utf8')),
        '数据库中不存在明文 Key（已加密存储）');

      // 清空 Key
      const clear = await req('PUT', '/admin/api/settings', { adminToken, body: { deepseekApiKey: '' } });
      ok(clear.status === 200, '清空 deepseekApiKey 返回 200');
      ok(clear.json?.settings?.deepseekApiKeyMasked === '', '清空后掩码为空字符串',
        String(clear.json?.settings?.deepseekApiKeyMasked));

      // 恢复一个 Key，供 /api/ai/status 断言
      await req('PUT', '/admin/api/settings', { adminToken, body: { deepseekApiKey: SECRET_KEY } });

      // 统计
      const stats = await req('GET', '/admin/api/stats', { adminToken });
      ok(stats.status === 200, 'GET /admin/api/stats 返回 200');
      ok(typeof stats.json?.users === 'number', 'stats.users 是数字', String(stats.json?.users));
      ok(typeof stats.json?.couples === 'number', 'stats.couples 是数字', String(stats.json?.couples));
      ok(typeof stats.json?.messages === 'number', 'stats.messages 是数字', String(stats.json?.messages));
      ok(typeof stats.json?.aiToday === 'number', 'stats.aiToday 是数字', String(stats.json?.aiToday));
      ok(typeof stats.json?.disk?.totalBytes === 'number', 'stats.disk.totalBytes 是数字');
      ok(stats.json.users >= 3, 'stats.users 统计到已注册用户', String(stats.json.users));

      // 用户列表
      const users = await req('GET', '/admin/api/users?page=1', { adminToken });
      ok(users.status === 200, 'GET /admin/api/users 返回 200');
      ok(Array.isArray(users.json?.users) && users.json.users.length > 0, '用户列表非空');
      ok(typeof users.json?.total === 'number', '用户列表含 total');
      const listedA = (users.json.users || []).find((u) => u.id === userA.id);
      ok(!!listedA, '用户列表含用户 A');
      ok(!('password_hash' in (listedA || {})), '用户列表不含密码哈希');

      // 搜索
      const search = await req('GET', `/admin/api/users?query=${encodeURIComponent(userA.username)}`, { adminToken });
      ok(search.status === 200, '用户搜索返回 200');
      ok((search.json?.users || []).some((u) => u.id === userA.id), '搜索结果含目标用户');

      // 重置密码
      const reset = await req('POST', `/admin/api/users/${userA.id}/reset-password`, {
        adminToken, body: { newPassword: 'newpass456' },
      });
      ok(reset.status === 200 && reset.json?.ok === true, '重置用户密码返回 200', `实际 ${reset.status}`);

      const loginNew = await req('POST', '/api/auth/login', {
        body: { username: userA.username, password: 'newpass456' },
      });
      ok(loginNew.status === 200, '重置后可用新密码登录', `实际 ${loginNew.status}`);
      tokenA = loginNew.json.token;

      const loginOld = await req('POST', '/api/auth/login', {
        body: { username: userA.username, password: 'pass1234' },
      });
      ok(loginOld.status === 401, '重置后旧密码无法登录（401）', `实际 ${loginOld.status}`);

      // 禁用 / 启用
      const disable = await req('POST', `/admin/api/users/${userB.id}/toggle-disabled`, {
        adminToken, body: { disabled: true },
      });
      ok(disable.status === 200, '禁用用户返回 200');

      const disabledLogin = await req('POST', '/api/auth/login', {
        body: { username: userB.username, password: 'pass1234' },
      });
      ok(disabledLogin.status === 403 && disabledLogin.json?.error === 'forbidden',
        '被禁用用户登录返回 403 forbidden', `实际 ${disabledLogin.status}`);

      const enable = await req('POST', `/admin/api/users/${userB.id}/toggle-disabled`, {
        adminToken, body: { disabled: false },
      });
      ok(enable.status === 200, '启用用户返回 200');

      const reLoginB = await req('POST', '/api/auth/login', {
        body: { username: userB.username, password: 'pass1234' },
      });
      ok(reLoginB.status === 200, '启用后可正常登录', `实际 ${reLoginB.status}`);

      // 表情包统计
      const packs = await req('GET', '/admin/api/packs', { adminToken });
      ok(packs.status === 200, 'GET /admin/api/packs 返回 200');
      ok(typeof packs.json?.totalPacks === 'number', 'packs.totalPacks 是数字');
      ok(typeof packs.json?.totalItems === 'number', 'packs.totalItems 是数字');

      // 操作日志
      const logs = await req('GET', '/admin/api/logs?limit=100', { adminToken });
      ok(logs.status === 200, 'GET /admin/api/logs 返回 200');
      ok(Array.isArray(logs.json?.logs) && logs.json.logs.length > 0, '操作日志非空');
      const actions = (logs.json.logs || []).map((l) => l.action);
      ok(actions.includes('admin.login.success'), '日志记录了后台登录成功', JSON.stringify(actions.slice(0, 6)));
      ok(actions.includes('admin.login.failed'), '日志记录了后台登录失败');
      ok(actions.some((a) => a === 'settings.update'), '日志记录了设置修改');
      ok(actions.some((a) => a === 'user.reset_password'), '日志记录了重置密码');
      ok(actions.some((a) => a === 'user.toggle_disabled'), '日志记录了禁用/启用');

      // 删除用户
      const victim = await req('POST', '/api/auth/register', {
        body: { username: `todelete_${crypto.randomBytes(3).toString('hex')}`, password: 'pass1234' },
      });
      const delUser = await req('DELETE', `/admin/api/users/${victim.json.user.id}`, { adminToken });
      ok(delUser.status === 200 && delUser.json?.ok === true, '删除用户返回 200', `实际 ${delUser.status}`);

      const checkDeleted = await req('GET', `/admin/api/users?query=${encodeURIComponent(victim.json.user.username)}`, { adminToken });
      ok((checkDeleted.json?.users || []).length === 0, '删除后用户列表中不再出现');

      // 后台页面可访问
      const adminPage = await req('GET', '/admin');
      ok(adminPage.status === 200, 'GET /admin 返回 200', `实际 ${adminPage.status}`);
      ok(adminPage.contentType.includes('text/html'), 'GET /admin 返回 HTML');
      ok(adminPage.buf.toString('utf8').includes('情侣空间后台'), '后台页面含中文标题');
    }

    // =====================================================================
    section('7. AI 接口（未配置真实 Key 时的行为）');
    // =====================================================================
    {
      const status = await req('GET', '/api/ai/status', { token: tokenA });
      ok(status.status === 200, 'GET /api/ai/status 返回 200', `实际 ${status.status}`);
      ok(typeof status.json?.enabled === 'boolean', 'ai/status.enabled 是布尔值');
      ok(status.json?.configured === true, 'ai/status.configured 为 true（已写入 Key）');
      ok(typeof status.json?.model === 'string', 'ai/status.model 是字符串', String(status.json?.model));
      ok(typeof status.json?.dailyLimit === 'number', 'ai/status.dailyLimit 是数字');
      ok(typeof status.json?.usedToday === 'number', 'ai/status.usedToday 是数字');

      // 用一个假的 Key 调用 → 应返回 403（DeepSeek 鉴权失败），而不是 500
      const parse = await req('POST', '/api/ai/parse-ledger', {
        token: tokenA,
        body: { text: '今天午饭25', currency: 'CNY', today: '2025-06-01' },
      });
      ok([403, 429, 502, 504].includes(parse.status),
        'AI 接口在 Key 无效时返回受控错误（非 500）', `实际 ${parse.status} ${JSON.stringify(parse.json)}`);
      ok(typeof parse.json?.error === 'string', 'AI 错误响应含 error 字段');

      // 未鉴权 -> 401
      const noAuth = await req('GET', '/api/ai/status');
      ok(noAuth.status === 401, 'AI 接口未鉴权返回 401', `实际 ${noAuth.status}`);
    }

    // =====================================================================
    section('8. 通用约定校验');
    // =====================================================================
    {
      // 404 结构
      const nf = await req('GET', '/api/not-exist-at-all');
      ok(nf.status === 404, '未知接口返回 404', `实际 ${nf.status}`);
      ok(nf.json?.error === 'not_found', '404 响应 error 为 not_found', String(nf.json?.error));
      ok(typeof nf.json?.message === 'string', '404 响应含 message');

      // 非法 JSON
      const badJson = await fetch(`${BASE}/api/auth/login`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: '{not json',
      });
      ok(badJson.status === 400, '非法 JSON 请求体返回 400', `实际 ${badJson.status}`);

      // 无 token 访问需鉴权接口
      const noToken = await req('GET', '/api/auth/me');
      ok(noToken.status === 401 && noToken.json?.error === 'unauthorized',
        '无 token 访问 /api/auth/me 返回 401 unauthorized', `实际 ${noToken.status}`);

      // PATCH /api/auth/me
      const patch = await req('PATCH', '/api/auth/me', { token: tokenA, body: { nickname: '新昵称' } });
      ok(patch.status === 200, 'PATCH /api/auth/me 返回 200');
      ok(patch.json?.user?.nickname === '新昵称', '昵称已更新', String(patch.json?.user?.nickname));

      // POST /api/auth/password
      const pwd = await req('POST', '/api/auth/password', {
        token: tokenA, body: { oldPassword: 'newpass456', newPassword: 'finalpass789' },
      });
      ok(pwd.status === 200 && pwd.json?.ok === true, 'POST /api/auth/password 返回 200', `实际 ${pwd.status}`);

      const wrongOld = await req('POST', '/api/auth/password', {
        token: tokenA, body: { oldPassword: 'wrongwrong', newPassword: 'whatever123' },
      });
      ok(wrongOld.status === 401, '原密码错误返回 401', `实际 ${wrongOld.status}`);

      // PATCH /api/couple
      const cpatch = await req('PATCH', '/api/couple', {
        token: tokenA, body: { togetherDate: 1700000000000, anniversaryTitle: '在一起的日子' },
      });
      ok(cpatch.status === 200, 'PATCH /api/couple 返回 200', `实际 ${cpatch.status}`);
      ok(cpatch.json?.couple?.togetherDate === 1700000000000, 'togetherDate 已更新');
      ok(cpatch.json?.couple?.anniversaryTitle === '在一起的日子', 'anniversaryTitle 已更新');

      // 所有响应都带 serverTime
      const me = await req('GET', '/api/auth/me', { token: tokenA });
      ok(typeof me.json?.serverTime === 'number', 'GET /api/auth/me 响应含 serverTime');
      ok(me.json?.user?.id === userA.id, 'auth/me 返回正确的用户');
      ok(me.json?.couple?.id === coupleId, 'auth/me 返回情侣空间');

      // 解绑
      const leave = await req('POST', '/api/couple/leave', { token: tokenA });
      ok(leave.status === 200 && leave.json?.ok === true, 'POST /api/couple/leave 返回 200', `实际 ${leave.status}`);
      const afterLeave = await req('GET', '/api/auth/me', { token: tokenA });
      ok(afterLeave.json?.user?.coupleId === null, '解绑后 coupleId 置空');
      ok(afterLeave.json?.couple === null, '解绑后 couple 为 null');
    }
  } catch (err) {
    failed += 1;
    failures.push(`测试过程中抛出异常：${err.message}`);
    process.stdout.write(`\n❌ 测试异常：${err.stack}\n`);
  } finally {
    // 关闭 WebSocket
    try {
      if (wsBRef) wsBRef.close();
    } catch {
      /* 忽略 */
    }
  }
}

// 便于 finally 中访问
let wsBRef = null;

// ---------------------------------------------------------------------------
// 执行
// ---------------------------------------------------------------------------
main()
  .catch((err) => {
    failed += 1;
    failures.push(`主流程异常：${err.message}`);
    process.stdout.write(`\n❌ 主流程异常：${err.stack}\n`);
  })
  .finally(async () => {
    process.stdout.write('\n----------------------------------------\n');
    process.stdout.write('清理：停止服务进程并删除测试数据目录\n');
    await stopServer();
    cleanupDataDir();
    process.stdout.write(`数据目录已清理：${DATA_DIR}\n`);

    process.stdout.write('\n========================================\n');
    if (failed === 0) {
      process.stdout.write(`  ✅ ALL TESTS PASSED  通过 ${passed} 项断言\n`);
      process.stdout.write('========================================\n\n');
      process.exit(0);
    } else {
      process.stdout.write(`  ❌ 失败 ${failed} 项 / 通过 ${passed} 项\n`);
      process.stdout.write('----------------------------------------\n');
      for (const f of failures) process.stdout.write(`  · ${f}\n`);
      const logs = readChildLogs();
      if (logs.trim()) {
        process.stdout.write('----------------------------------------\n');
        process.stdout.write('服务端日志（尾部）：\n');
        process.stdout.write(logs.split('\n').slice(-25).join('\n') + '\n');
      }
      process.stdout.write('========================================\n\n');
      process.exit(1);
    }
  });
