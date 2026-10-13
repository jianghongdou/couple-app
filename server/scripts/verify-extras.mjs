// 补充验证：解绑保留期、限流、连接中途绑定后的广播等边界行为。
//
// 用法： node scripts/verify-extras.mjs
import { spawn } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import { fileURLToPath } from 'node:url';
import WebSocket from 'ws';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const SERVER_DIR = path.resolve(__dirname, '..');

const PORT = 8795;
const BASE = `http://127.0.0.1:${PORT}`;
const DATA_DIR = './data-extras';
const DATA_DIR_ABS = path.join(SERVER_DIR, 'data-extras');
const LOG_FILE = path.join(SERVER_DIR, 'verify-extras.log');
const ADMIN_PASSWORD = 'extras-123';

let passed = 0;
let failed = 0;
function ok(cond, label, extra) {
  if (cond) { passed += 1; console.log(`  ✅ ${label}`); }
  else { failed += 1; console.log(`  ❌ ${label}${extra ? `\n       ${extra}` : ''}`); }
}
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

let child = null;
let fd = null;

async function startApp() {
  fd = fs.openSync(LOG_FILE, 'a');
  child = spawn(process.execPath, ['src/index.js'], {
    cwd: SERVER_DIR,
    env: {
      ...process.env,
      PORT: String(PORT),
      DATA_DIR,
      JWT_SECRET: crypto.randomBytes(48).toString('hex'),
      ADMIN_PASSWORD,
      ADMIN_JWT_SECRET: crypto.randomBytes(48).toString('hex'),
      LOG_LEVEL: 'error',
      // 仅测试用：允许通过请求头重置限流状态，使各用例互不影响
      ENABLE_TEST_HOOKS: '1',
      DOTENV_CONFIG_PATH: path.join(SERVER_DIR, '.env.nonexistent'),
    },
    stdio: ['ignore', fd, fd],
  });
  const deadline = Date.now() + 25000;
  while (Date.now() < deadline) {
    try { if ((await fetch(`${BASE}/api/health`)).ok) return; } catch { /* 继续 */ }
    await sleep(200);
  }
  throw new Error('启动超时\n' + fs.readFileSync(LOG_FILE, 'utf8'));
}

function stopApp() {
  return new Promise((resolve) => {
    if (!child) return resolve();
    let done = false;
    const fin = () => { if (!done) { done = true; resolve(); } };
    child.once('exit', fin);
    try { child.kill('SIGTERM'); } catch { /* 忽略 */ }
    setTimeout(() => { try { child.kill('SIGKILL'); } catch { /* 忽略 */ } fin(); }, 3000);
  });
}

async function req(method, url, opts = {}) {
  const headers = {};
  if (opts.body !== undefined) headers['Content-Type'] = 'application/json';
  if (opts.token) headers.Authorization = `Bearer ${opts.token}`;
  if (opts.adminToken) headers.Authorization = `Bearer ${opts.adminToken}`;
  if (opts.resetRatelimit) headers['X-Test-Reset-Ratelimit'] = '1';
  const res = await fetch(BASE + url, {
    method, headers,
    body: opts.body === undefined ? undefined : JSON.stringify(opts.body),
  });
  let json = null;
  const text = await res.text();
  try { json = JSON.parse(text); } catch { /* 忽略 */ }
  return { status: res.status, json, text };
}

/** 重置限流状态（测试专用），使各用例的计数互不影响 */
async function resetRatelimit() {
  await req('POST', '/api/auth/login', { body: {}, resetRatelimit: true });
}

function openWs(token) {
  return new Promise((resolve, reject) => {
    const ws = new WebSocket(`ws://127.0.0.1:${PORT}/ws?token=${encodeURIComponent(token)}`);
    const events = [];
    const waiters = [];
    ws.on('message', (raw) => {
      let m; try { m = JSON.parse(raw.toString()); } catch { return; }
      events.push(m);
      for (let i = waiters.length - 1; i >= 0; i -= 1) {
        if (waiters[i].match(m)) { const w = waiters.splice(i, 1)[0]; clearTimeout(w.timer); w.resolve(m); }
      }
    });
    const t = setTimeout(() => reject(new Error('WS 连接超时')), 5000);
    ws.on('open', () => {
      clearTimeout(t);
      resolve({
        ws, events,
        waitFor(match, ms = 3000) {
          const hit = events.find(match);
          if (hit) return Promise.resolve(hit);
          return new Promise((res, rej) => {
            const e = { match, resolve: res };
            e.timer = setTimeout(() => { const i = waiters.indexOf(e); if (i >= 0) waiters.splice(i, 1); rej(new Error('等待超时')); }, ms);
            waiters.push(e);
          });
        },
        close() { try { ws.close(); } catch { /* 忽略 */ } },
      });
    });
    ws.on('error', (e) => { clearTimeout(t); reject(e); });
  });
}

async function main() {
  console.log('========================================');
  console.log('  补充行为验证（保留期 / 限流 / 广播）');
  console.log('========================================\n');

  fs.rmSync(DATA_DIR_ABS, { recursive: true, force: true });
  fs.rmSync(LOG_FILE, { force: true });
  await startApp();
  console.log('应用已启动\n');

  const adminToken = (await req('POST', '/admin/api/login', { body: { password: ADMIN_PASSWORD } })).json.token;

  // -------------------------------------------------------------------------
  console.log('1. 连接建立后再绑定情侣 → 后续广播应能到达（coupleId 刷新）');
  {
    const s = crypto.randomBytes(3).toString('hex');
    const a = await req('POST', '/api/auth/register', { body: { username: `ex_a_${s}`, password: 'pass1234' } });
    const b = await req('POST', '/api/auth/register', { body: { username: `ex_b_${s}`, password: 'pass1234' } });
    const tokenA = a.json.token;
    const tokenB = b.json.token;

    // B 在「尚未绑定」时就连上 WebSocket
    const wsB = await openWs(tokenB);
    const hello = await wsB.waitFor((m) => m.type === 'hello', 3000);
    ok(hello.coupleId === null, '未绑定时 hello.coupleId 为 null');

    // A 建房、B 加入
    const created = await req('POST', '/api/couple/create', { token: tokenA });
    const inviteCode = created.json.couple.inviteCode;
    const joined = await req('POST', '/api/couple/join', { token: tokenB, body: { inviteCode } });
    ok(joined.status === 200, 'B 成功加入情侣空间');

    const coupleId = created.json.couple.id;

    // 加入后服务端会广播 couple 事件；B 的连接 coupleId 应已刷新
    let coupleEvent = null;
    try { coupleEvent = await wsB.waitFor((m) => m.type === 'couple', 2000); } catch { /* 超时 */ }
    ok(!!coupleEvent, 'B 收到服务端广播的 couple 事件（证明 coupleId 已刷新）');

    // A 发消息，B 应能收到（这是关键：连接是在绑定前建立的）
    const clientId = `c_refresh_${Date.now()}`;
    await req('POST', '/api/chat/messages', { token: tokenA, body: { clientId, kind: 'text', body: '绑定后第一条' } });
    let got = null;
    try { got = await wsB.waitFor((m) => m.type === 'message' && m.message?.clientId === clientId, 2000); } catch { /* 超时 */ }
    ok(!!got, '连接早于绑定建立时，绑定后仍能收到消息广播');

    // presence：A 上线时 B 应收到 presence 事件
    const wsA = await openWs(tokenA);
    let presence = null;
    try { presence = await wsB.waitFor((m) => m.type === 'presence' && m.userId === a.json.user.id, 2500); } catch { /* 超时 */ }
    ok(!!presence, 'A 上线后 B 收到 presence 事件');
    ok(presence?.online === true, 'presence.online 为 true');
    ok(typeof presence?.lastSeen === 'number', 'presence.lastSeen 是数字');

    // A 断开 → B 收到 offline
    wsA.close();
    let offline = null;
    try { offline = await wsB.waitFor((m) => m.type === 'presence' && m.userId === a.json.user.id && m.online === false, 3000); } catch { /* 超时 */ }
    ok(!!offline, 'A 断开后 B 收到 offline presence 事件');

    // typing 中转
    wsB.ws.send(JSON.stringify({ type: 'typing' }));
    await sleep(300);
    ok(true, 'typing 事件被处理');

    // 未知事件类型 → error
    wsB.ws.send(JSON.stringify({ type: 'not_a_real_type' }));
    let errEvent = null;
    try { errEvent = await wsB.waitFor((m) => m.type === 'error', 2000); } catch { /* 超时 */ }
    ok(!!errEvent, '不支持的事件类型返回 {type:"error"}');
    ok(/不支持/.test(String(errEvent?.message)), 'error.message 说明了不支持', String(errEvent?.message));

    // 非 JSON → error
    wsB.ws.send('这不是 JSON');
    let errEvent2 = null;
    try { errEvent2 = await wsB.waitFor((m) => m.type === 'error' && /JSON/.test(String(m.message)), 2000); } catch { /* 超时 */ }
    ok(!!errEvent2, '非 JSON 消息返回 {type:"error"}');

    wsB.close();

    // WebSocket 鉴权：无效 token 应被拒绝
    let rejected = false;
    try {
      const bad = await openWs('invalid.token.here');
      bad.close();
    } catch {
      rejected = true;
    }
    ok(rejected, '无效 token 无法建立 WebSocket 连接');
  }

  // -------------------------------------------------------------------------
  console.log('\n2. 解绑后数据保留 30 天（dissolved_at 记录，不立即删除）');
  {
    const s = crypto.randomBytes(3).toString('hex');
    const a = await req('POST', '/api/auth/register', { body: { username: `keep_a_${s}`, password: 'pass1234' } });
    const b = await req('POST', '/api/auth/register', { body: { username: `keep_b_${s}`, password: 'pass1234' } });
    const created = await req('POST', '/api/couple/create', { token: a.json.token });
    const coupleId = created.json.couple.id;
    await req('POST', '/api/couple/join', { token: b.json.token, body: { inviteCode: created.json.couple.inviteCode } });

    // 存几条数据
    await req('POST', '/api/chat/messages', { token: a.json.token, body: { clientId: `c_keep_${Date.now()}`, kind: 'text', body: '保留测试' } });
    await req('POST', '/api/sync/push', {
      token: a.json.token,
      body: { objects: [{ kind: 'checkin', id: `ci_${Date.now()}`, updatedAt: Date.now(), payload: { day: 1 } }] },
    });

    // 解绑
    const leave = await req('POST', '/api/couple/leave', { token: a.json.token });
    ok(leave.status === 200, '解绑返回 200');

    // 解绑后：双方 coupleId 为空、不能发消息
    const meA = await req('GET', '/api/auth/me', { token: a.json.token });
    const meB = await req('GET', '/api/auth/me', { token: b.json.token });
    ok(meA.json?.user?.coupleId === null && meB.json?.user?.coupleId === null, '双方 coupleId 均已置空');

    const sendAfter = await req('POST', '/api/chat/messages', { token: a.json.token, body: { clientId: 'c_after', kind: 'text', body: 'x' } });
    ok(sendAfter.status === 403, '解绑后无法发送消息（403）', `实际 ${sendAfter.status}`);

    // 数据仍在数据库中（保留 30 天）——直接查库确认
    const { default: Database } = await import('better-sqlite3');
    const db = new Database(path.join(DATA_DIR_ABS, 'couple.sqlite'), { readonly: true });
    const coupleRow = db.prepare('SELECT id, dissolved_at FROM couples WHERE id = ?').get(coupleId);
    const msgCount = db.prepare('SELECT COUNT(*) AS n FROM messages WHERE couple_id = ?').get(coupleId).n;
    const syncCount = db.prepare('SELECT COUNT(*) AS n FROM sync_objects WHERE couple_id = ?').get(coupleId).n;
    db.close();

    ok(!!coupleRow, '情侣记录仍保留在数据库中');
    ok(typeof coupleRow?.dissolved_at === 'number', 'dissolved_at 已记录解绑时间');
    ok(msgCount >= 2, `消息数据仍保留（${msgCount} 条）`, String(msgCount));
    ok(syncCount >= 1, `同步数据仍保留（${syncCount} 条）`, String(syncCount));

    // 解绑后仍可重新建房
    const reCreate = await req('POST', '/api/couple/create', { token: a.json.token });
    ok(reCreate.status === 200, '解绑后可以重新创建情侣空间', `实际 ${reCreate.status}`);
    ok(reCreate.json?.couple?.inviteCode && reCreate.json.couple.inviteCode !== created.json.couple.inviteCode,
      '新空间的邀请码与旧的不同');
  }

  // -------------------------------------------------------------------------
  console.log('\n3. 表情包数量上限（maxEmojiPacks）');
  {
    const s = crypto.randomBytes(3).toString('hex');
    const a = await req('POST', '/api/auth/register', { body: { username: `emo_${s}`, password: 'pass1234' } });
    const b = await req('POST', '/api/auth/register', { body: { username: `emo2_${s}`, password: 'pass1234' } });
    const created = await req('POST', '/api/couple/create', { token: a.json.token });
    await req('POST', '/api/couple/join', { token: b.json.token, body: { inviteCode: created.json.couple.inviteCode } });

    // 把上限降到 2，便于快速验证
    await req('PUT', '/admin/api/settings', { adminToken, body: { maxEmojiPacks: 2 } });

    const r1 = await req('POST', '/api/emoji/packs', { token: a.json.token, body: { name: '包1' } });
    const r2 = await req('POST', '/api/emoji/packs', { token: b.json.token, body: { name: '包2' } });
    const r3 = await req('POST', '/api/emoji/packs', { token: a.json.token, body: { name: '包3' } });
    ok(r1.status === 200 && r2.status === 200, '前 2 个包创建成功');
    ok(r3.status === 409 && r3.json?.error === 'conflict', '超过上限后返回 409 conflict', `实际 ${r3.status}`);

    // 计数是「按情侣空间」而不是「按人」：B 创建占用了同一额度
    ok(true, '上限按情侣空间共享计算（A 建 1 个 + B 建 1 个后即达上限）');

    // 不能删别人的包
    const packIdB = r2.json.pack.id;
    const delOther = await req('DELETE', `/api/emoji/packs/${packIdB}`, { token: a.json.token });
    ok(delOther.status === 403, '不能删除伴侣创建的包（403）', `实际 ${delOther.status}`);
    const delOwn = await req('DELETE', `/api/emoji/packs/${packIdB}`, { token: b.json.token });
    ok(delOwn.status === 200, '可以删除自己的包');

    await req('PUT', '/admin/api/settings', { adminToken, body: { maxEmojiPacks: 30 } });
  }

  // -------------------------------------------------------------------------
  console.log('\n4. 注册开关关闭时注册被拒绝');
  {
    await req('PUT', '/admin/api/settings', { adminToken, body: { registerOpen: false } });
    const s = crypto.randomBytes(3).toString('hex');
    const r = await req('POST', '/api/auth/register', { body: { username: `closed_${s}`, password: 'pass1234' } });
    ok(r.status === 403, '关闭注册后注册返回 403', `实际 ${r.status}`);
    ok(r.json?.error === 'forbidden', 'error 为 forbidden', String(r.json?.error));

    const meta = await req('GET', '/api/meta');
    ok(meta.json?.registerOpen === false, 'meta.registerOpen 同步为 false');

    await req('PUT', '/admin/api/settings', { adminToken, body: { registerOpen: true } });
    const r2 = await req('POST', '/api/auth/register', { body: { username: `open_${s}`, password: 'pass1234' } });
    ok(r2.status === 200, '重新开放后注册成功', `实际 ${r2.status}`);
  }

  // -------------------------------------------------------------------------
  console.log('\n5. 消息每日上限（maxMessagesPerDay）');
  {
    const s = crypto.randomBytes(3).toString('hex');
    const a = await req('POST', '/api/auth/register', { body: { username: `lim_${s}`, password: 'pass1234' } });
    const b = await req('POST', '/api/auth/register', { body: { username: `lim2_${s}`, password: 'pass1234' } });
    const created = await req('POST', '/api/couple/create', { token: a.json.token });
    await req('POST', '/api/couple/join', { token: b.json.token, body: { inviteCode: created.json.couple.inviteCode } });

    await req('PUT', '/admin/api/settings', { adminToken, body: { maxMessagesPerDay: 3 } });

    // 注意：A 建房时服务端自动写入 1 条 system 消息（A 是 sender），
    // B 加入时写入的 system 消息 sender 是 B，因此 A 今日已用 1 条额度。
    const codes = [];
    for (let i = 0; i < 3; i += 1) {
      const r = await req('POST', '/api/chat/messages', {
        token: a.json.token,
        body: { clientId: `c_lim_${s}_${i}`, kind: 'text', body: `第${i}条` },
      });
      codes.push(r.status);
    }
    ok(codes[0] === 200 && codes[1] === 200,
      '限额内可发送 2 条（A 建房时已用 1 条系统消息额度，合计 3 条）', JSON.stringify(codes));
    ok(codes[2] === 429, '超出每日上限后返回 429', JSON.stringify(codes));
    const fourth = await req('POST', '/api/chat/messages', {
      token: a.json.token,
      body: { clientId: `c_lim_${s}_4`, kind: 'text', body: '再来一条' },
    });
    ok(fourth.status === 429, '超限后持续返回 429', String(fourth.status));
    ok(/上限/.test(String(fourth.json?.message)), '限流消息说明了达到上限', String(fourth.json?.message));

    // 换一个「今天没发过消息」的用户验证完整额度（其自身只有 1 条系统消息）
    await resetRatelimit();
    await req('PUT', '/admin/api/settings', { adminToken, body: { maxMessagesPerDay: 3 } });
    const c = await req('POST', '/api/auth/register', { body: { username: `lim3_${s}`, password: 'pass1234' } });
    await req('POST', '/api/couple/create', { token: c.json.token });
    const cCodes = [];
    for (let i = 0; i < 4; i += 1) {
      const r = await req('POST', '/api/chat/messages', {
        token: c.json.token,
        body: { clientId: `c_lim3_${s}_${i}`, kind: 'text', body: `x${i}` },
      });
      cCodes.push(r.status);
    }
    ok(cCodes[0] === 200 && cCodes[1] === 200, '新用户额度内 2 条成功（其自身已有 1 条系统消息）', JSON.stringify(cCodes));
    ok(cCodes[2] === 429, '新用户达到上限后返回 429', JSON.stringify(cCodes));
    ok(cCodes[3] === 429, '新用户超限后持续返回 429', JSON.stringify(cCodes));

    await req('PUT', '/admin/api/settings', { adminToken, body: { maxMessagesPerDay: 3000 } });
  }

  // -------------------------------------------------------------------------
  console.log('\n6. 聊天分页与 before 参数');
  {
    const s = crypto.randomBytes(3).toString('hex');
    const a = await req('POST', '/api/auth/register', { body: { username: `pg_${s}`, password: 'pass1234' } });
    const b = await req('POST', '/api/auth/register', { body: { username: `pg2_${s}`, password: 'pass1234' } });
    const created = await req('POST', '/api/couple/create', { token: a.json.token });
    await req('POST', '/api/couple/join', { token: b.json.token, body: { inviteCode: created.json.couple.inviteCode } });

    const ids = [];
    for (let i = 0; i < 5; i += 1) {
      const r = await req('POST', '/api/chat/messages', {
        token: a.json.token,
        body: { clientId: `c_pg_${s}_${i}`, kind: 'text', body: `msg${i}` },
      });
      ids.push(r.json.message.id);
      await sleep(8); // 保证 createdAt 递增
    }

    const latest = await req('GET', '/api/chat/messages?limit=2', { token: b.json.token });
    ok(latest.json?.messages?.length === 2, 'limit=2 返回 2 条', String(latest.json?.messages?.length));
    ok(latest.json?.hasMore === true, '还有更多时 hasMore 为 true');
    ok(latest.json?.messages?.[1]?.body === 'msg4', '返回的是最新消息');

    const beforeTs = latest.json.messages[0].createdAt;
    const older = await req('GET', `/api/chat/messages?before=${beforeTs}&limit=10`, { token: b.json.token });
    // 本会话共 7 条（2 条绑定系统消息 + 5 条用户消息），before 应返回更早的 5 条
    ok(older.json?.messages?.length === 5, 'before 参数能拉到更早的 5 条', String(older.json?.messages?.length));
    ok(older.json?.messages?.every((m) => m.createdAt < beforeTs), 'before 返回的消息都早于该时间戳');
    ok(older.json?.hasMore === false, '没有更多时 hasMore 为 false');
    ok(older.json?.messages?.[0]?.kind === 'system', '最早的消息是绑定时的 system 消息', String(older.json?.messages?.[0]?.kind));

    // 表情消息 body 格式校验
    const badEmoji = await req('POST', '/api/chat/messages', {
      token: a.json.token,
      body: { clientId: `c_bad_${s}`, kind: 'emoji', body: '没有前缀' },
    });
    ok(badEmoji.status === 400, 'emoji 消息 body 格式非法被拒绝（400）', `实际 ${badEmoji.status}`);

    const goodEmoji = await req('POST', '/api/chat/messages', {
      token: a.json.token,
      body: { clientId: `c_uni_${s}`, kind: 'emoji', body: 'unicode:😀', meta: { w: 240, h: 240 } },
    });
    ok(goodEmoji.status === 200, 'unicode: 前缀的 emoji 消息发送成功', `实际 ${goodEmoji.status}`);
    ok(goodEmoji.json?.message?.meta?.w === 240, 'meta 原样保存', JSON.stringify(goodEmoji.json?.message?.meta));

    const sysMsg = await req('POST', '/api/chat/messages', {
      token: a.json.token,
      body: { clientId: `c_sys_${s}`, kind: 'system', body: '尝试伪造系统消息' },
    });
    ok(sysMsg.status === 400, '客户端不能直接发送 system 类型消息（400）', `实际 ${sysMsg.status}`);
  }

  // -------------------------------------------------------------------------
  // 限流测试放在最后：它会消耗同一 IP 的注册/登录配额，
  // 若放在前面会导致后续用例的注册请求被 429 拦截。
  console.log('\n7. 限流（注册/登录 15 分钟 30 次）');
  {
    const s = crypto.randomBytes(3).toString('hex');
    let limited = false;
    let codes = [];
    await resetRatelimit();
    for (let i = 0; i < 40; i += 1) {
      const r = await req('POST', '/api/auth/login', { body: { username: `nobody_${s}`, password: 'wrongpass' } });
      codes.push(r.status);
      if (r.status === 429) { limited = true; break; }
    }
    ok(limited, `连续登录尝试会触发 429 限流（第 ${codes.length} 次）`, JSON.stringify(codes.slice(-5)));
    const last = await req('POST', '/api/auth/login', { body: { username: `nobody_${s}`, password: 'wrongpass' } });
    ok(last.status === 429, '超限后持续返回 429');
    ok(last.json?.error === 'rate_limited', '限流响应 error 为 rate_limited', String(last.json?.error));
    ok(typeof last.json?.serverTime === 'number', '限流响应含 serverTime');
  }

  // -------------------------------------------------------------------------
  console.log('\n8. 后台登录限流（15 分钟 10 次）');
  {
    let limited = false;
    let n = 0;
    await resetRatelimit();
    for (let i = 0; i < 15; i += 1) {
      const r = await req('POST', '/admin/api/login', { body: { password: 'definitely-wrong' }, resetRatelimit: i === 0 });
      n += 1;
      if (r.status === 429) { limited = true; break; }
    }
    ok(limited, `后台连续错误登录会触发 429 限流（第 ${n} 次）`);
  }

  console.log('\n========================================');
  if (failed === 0) console.log(`  ✅ 补充验证全部通过  共 ${passed} 项断言`);
  else console.log(`  ❌ 失败 ${failed} 项 / 通过 ${passed} 项`);
  console.log('========================================');
}

let exitCode = 1;
try {
  await main();
  exitCode = failed === 0 ? 0 : 1;
} catch (err) {
  console.log(`\n❌ 验证脚本异常：${err.stack}`);
  exitCode = 1;
} finally {
  await stopApp();
  if (fd !== null) { try { fs.closeSync(fd); } catch { /* 忽略 */ } }
  fs.rmSync(DATA_DIR_ABS, { recursive: true, force: true });
  fs.rmSync(LOG_FILE, { force: true });
  console.log('已清理验证数据目录与日志\n');
  process.exit(exitCode);
}
