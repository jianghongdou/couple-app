// AI 链路验证：用一个「本地模拟 DeepSeek 服务」跑通解析 / 重试 / 容错 / 限额。
//
// 说明：真实 DeepSeek Key 无法在离线环境使用，因此这里起一个 OpenAI 兼容的
//       本地模拟服务，覆盖 deepseek.js 中的关键分支：
//         - 标准 JSON 输出
//         - 被 ```json 代码块包裹的输出
//         - 字段类型不规范（字符串金额、中文「收入」、缺字段）
//         - 前 N 次返回 500（验证重试）
//         - 一直返回非 JSON（验证容错兜底）
//         - 每日限额（429 rate_limited）
//         - 未配置 Key（403 forbidden，message "AI 未配置"）
//         - 月度报告
//
// 用法： node scripts/verify-ai.mjs
import { spawn } from 'node:child_process';
import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const SERVER_DIR = path.resolve(__dirname, '..');

// 情侣服务端（被验证对象）
const APP_PORT = 8797;
const BASE = `http://127.0.0.1:${APP_PORT}`;
// 模拟 DeepSeek（OpenAI 兼容）
const MOCK_PORT = 8796;
const MOCK_URL = `http://127.0.0.1:${MOCK_PORT}`;

const DATA_DIR = './data-ai-verify';
const DATA_DIR_ABS = path.join(SERVER_DIR, 'data-ai-verify');
const LOG_FILE = path.join(SERVER_DIR, 'verify-ai.log');

const ADMIN_PASSWORD = 'ai-verify-123';
let passed = 0;
let failed = 0;

function ok(cond, label, extra) {
  if (cond) { passed += 1; console.log(`  ✅ ${label}`); }
  else { failed += 1; console.log(`  ❌ ${label}${extra ? `\n       ${extra}` : ''}`); }
}

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

// ---------------------------------------------------------------------------
// 模拟 DeepSeek 服务
// ---------------------------------------------------------------------------
let mockMode = 'plain';
let mockHits = 0;

const mockServer = http.createServer((req, res) => {
  mockHits += 1;
  let body = '';
  req.on('data', (d) => { body += d.toString(); });
  req.on('end', () => {
    let parsed = {};
    try { parsed = JSON.parse(body); } catch { /* 忽略 */ }

    const reply = (code, obj) => {
      res.writeHead(code, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify(obj));
    };
    const content = (text) => ({ choices: [{ message: { role: 'assistant', content: text } }], model: parsed.model || 'deepseek-chat', usage: { total_tokens: 42 } });

    // 鉴权检查：模拟真实服务的 401（Key 无效）
    const auth = req.headers.authorization || '';
    if (auth !== 'Bearer sk-verify-key') {
      reply(401, { error: { message: 'Authentication Fails, Your api key is invalid' } });
      return;
    }

    // 从请求里取出用户文本，便于按内容返回确定性结果
    const messages = Array.isArray(parsed.messages) ? parsed.messages : [];
    const userText = messages.filter((m) => m.role === 'user').map((m) => String(m.content)).join('\n');

    // 后台「测试」接口使用的固定文本：始终只返回一条 25 元
    if (userText.includes('测试固定用例')) {
      reply(200, content(JSON.stringify({
        items: [{ amount: 25, kind: 'expense', category: '餐饮', note: '午饭', date: '2025-06-01' }],
        summary: '1 笔支出，共 25 元',
      })));
      return;
    }

    switch (mockMode) {
      case 'plain':
        reply(200, content(JSON.stringify({
          items: [
            { amount: 25, kind: 'expense', category: '餐饮', note: '午饭', date: '2025-06-01' },
            { amount: 18.5, kind: 'expense', category: '交通', note: '打车', date: '2025-06-01' },
            { amount: 83.6, kind: 'expense', category: '餐饮', note: '买菜', date: '2025-06-01' },
          ],
          summary: '3 笔支出，共 127.1 元',
        })));
        break;

      case 'fenced':
        reply(200, content('好的，结果如下：\n```json\n' + JSON.stringify({
          items: [{ amount: 30, kind: 'income', category: '工资', note: '奖金', date: '2025-06-02' }],
          summary: '1 笔收入',
        }) + '\n```\n希望有用！'));
        break;

      case 'messy':
        // 脏数据：字符串金额、带货币符号、中文 kind、缺失字段、非法日期、多余条目
        reply(200, content(JSON.stringify({
          items: [
            { amount: '￥1,234.50', kind: '支出', category: '', note: 'x'.repeat(200), date: 'not-a-date' },
            { amount: '88.8', kind: '收入', category: '理财', note: '红包' },
            { amount: -50, kind: 'expense', category: '餐饮' },
            { amount: 0, kind: 'expense' },
            { amount: 'abc', kind: 'expense' },
            'not-an-object',
            null,
          ],
          summary: '',
        })));
        break;

      case 'flaky':
        // 前 2 次返回 500（验证重试），第 3 次成功
        if (mockHits <= 2) reply(500, { error: { message: 'Internal Server Error' } });
        else reply(200, content(JSON.stringify({ items: [{ amount: 10, kind: 'expense', category: '其它', note: '重试成功' }], summary: '重试成功' })));
        break;

      case 'garbage':
        reply(200, content('抱歉，我无法理解你的输入。'));
        break;

      case 'report':
        reply(200, content('这个月你们一共支出 3210.5 元，餐饮占比最高……建议少点外卖。'));
        break;

      default:
        reply(200, content('{}'));
    }
  });
});

// ---------------------------------------------------------------------------
// 应用辅助
// ---------------------------------------------------------------------------
let child = null;
let childFd = null;

async function startApp() {
  childFd = fs.openSync(LOG_FILE, 'a');
  child = spawn(process.execPath, ['src/index.js'], {
    cwd: SERVER_DIR,
    env: {
      ...process.env,
      PORT: String(APP_PORT),
      DATA_DIR,
      JWT_SECRET: crypto.randomBytes(48).toString('hex'),
      ADMIN_PASSWORD,
      ADMIN_JWT_SECRET: crypto.randomBytes(48).toString('hex'),
      LOG_LEVEL: 'error',
      DOTENV_CONFIG_PATH: path.join(SERVER_DIR, '.env.nonexistent'),
    },
    stdio: ['ignore', childFd, childFd],
  });

  const deadline = Date.now() + 25000;
  while (Date.now() < deadline) {
    try {
      const r = await fetch(`${BASE}/api/health`);
      if (r.ok) return;
    } catch { /* 继续等 */ }
    await sleep(200);
  }
  throw new Error('应用启动超时\n' + fs.readFileSync(LOG_FILE, 'utf8'));
}

function stopApp() {
  return new Promise((resolve) => {
    if (!child) return resolve();
    let done = false;
    const finish = () => { if (!done) { done = true; resolve(); } };
    child.once('exit', finish);
    try { child.kill('SIGTERM'); } catch { /* 忽略 */ }
    setTimeout(() => { try { child.kill('SIGKILL'); } catch { /* 忽略 */ } finish(); }, 3000);
  });
}

async function req(method, url, opts = {}) {
  const headers = {};
  if (opts.body !== undefined) headers['Content-Type'] = 'application/json';
  if (opts.adminToken) headers.Authorization = `Bearer ${opts.adminToken}`;
  if (opts.token) headers.Authorization = `Bearer ${opts.token}`;
  const res = await fetch(BASE + url, {
    method,
    headers,
    body: opts.body === undefined ? undefined : JSON.stringify(opts.body),
  });
  let json = null;
  try { json = await res.json(); } catch { /* 忽略 */ }
  return { status: res.status, json };
}

// ---------------------------------------------------------------------------
// 主流程
// ---------------------------------------------------------------------------
async function main() {
  console.log('========================================');
  console.log('  AI 链路验证（本地模拟 DeepSeek）');
  console.log('========================================\n');

  await new Promise((r) => mockServer.listen(MOCK_PORT, '127.0.0.1', r));
  console.log(`模拟 DeepSeek 已启动：${MOCK_URL}`);

  fs.rmSync(DATA_DIR_ABS, { recursive: true, force: true });
  fs.rmSync(LOG_FILE, { force: true });

  await startApp();
  console.log(`应用已启动：${BASE}\n`);

  // 准备：管理员登录 + 注册用户并绑定情侣
  const login = await req('POST', '/admin/api/login', { body: { password: ADMIN_PASSWORD } });
  const adminToken = login.json.token;
  ok(!!adminToken, '后台登录成功，拿到 admin token');

  const suffix = crypto.randomBytes(3).toString('hex');
  const u1 = await req('POST', '/api/auth/register', { body: { username: `ai1_${suffix}`, password: 'pass1234', nickname: '甲' } });
  const u2 = await req('POST', '/api/auth/register', { body: { username: `ai2_${suffix}`, password: 'pass1234', nickname: '乙' } });
  const token1 = u1.json.token;
  const created = await req('POST', '/api/couple/create', { token: token1 });
  await req('POST', '/api/couple/join', { token: u2.json.token, body: { inviteCode: created.json.couple.inviteCode } });
  ok(true, '已准备一对绑定用户');

  // -------------------------------------------------------------------------
  console.log('\n1. 未配置 Key 时应返回 403 forbidden + "AI 未配置"');
  {
    const r = await req('POST', '/api/ai/parse-ledger', { token: token1, body: { text: '午饭25' } });
    ok(r.status === 403, '状态码为 403', `实际 ${r.status}`);
    ok(r.json?.error === 'forbidden', 'error 为 forbidden', String(r.json?.error));
    ok(r.json?.message === 'AI 未配置', 'message 精确为 "AI 未配置"', String(r.json?.message));

    const s = await req('GET', '/api/ai/status', { token: token1 });
    ok(s.json?.configured === false, 'ai/status.configured 为 false');
    ok(s.json?.enabled === true, 'ai/status.enabled 默认 true');
  }

  // 配置指向模拟服务
  {
    const put = await req('PUT', '/admin/api/settings', {
      adminToken,
      body: { deepseekApiKey: 'sk-verify-key', deepseekBaseUrl: MOCK_URL, deepseekModel: 'deepseek-chat', aiEnabled: true, aiDailyLimit: 200 },
    });
    ok(put.status === 200, '写入模拟服务配置成功', `实际 ${put.status}`);
    const s = await req('GET', '/api/ai/status', { token: token1 });
    ok(s.json?.configured === true, '配置后 ai/status.configured 为 true');
    ok(s.json?.model === 'deepseek-chat', 'model 为 deepseek-chat');
  }

  // -------------------------------------------------------------------------
  console.log('\n2. 标准 JSON 输出解析（API.md §7 示例场景）');
  {
    mockMode = 'plain';
    const r = await req('POST', '/api/ai/parse-ledger', {
      token: token1,
      body: { text: '今天午饭25，打车18块5，买菜一共83.6', currency: 'CNY', today: '2025-06-01' },
    });
    ok(r.status === 200, '返回 200', `实际 ${r.status} ${JSON.stringify(r.json)}`);
    ok(Array.isArray(r.json?.items) && r.json.items.length === 3, 'items 有 3 条', String(r.json?.items?.length));
    ok(r.json?.items?.[0]?.amount === 25, '第 1 条 amount 为 25');
    ok(r.json?.items?.[0]?.kind === 'expense', '第 1 条 kind 为 expense');
    ok(r.json?.items?.[0]?.category === '餐饮', '第 1 条 category 为 餐饮');
    ok(r.json?.items?.[1]?.amount === 18.5, '第 2 条 amount 为 18.5');
    ok(r.json?.items?.[1]?.category === '交通', '第 2 条 category 为 交通');
    ok(r.json?.items?.[2]?.amount === 83.6, '第 3 条 amount 为 83.6');
    ok(r.json?.total === 127.1, 'total 为 127.1（25+18.5+83.6）', String(r.json?.total));
    ok(r.json?.summary === '3 笔支出，共 127.1 元', 'summary 原样返回', String(r.json?.summary));
    ok(r.json?.model === 'deepseek-chat', 'model 字段正确');
    ok(typeof r.json?.serverTime === 'number', 'serverTime 存在');
    ok(r.json?.items?.every((it) => typeof it.date === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(it.date)),
      '每条 date 均为 YYYY-MM-DD');
  }

  // -------------------------------------------------------------------------
  console.log('\n3. 剥离 ```json 代码块 + 前后多余文字');
  {
    mockMode = 'fenced';
    const r = await req('POST', '/api/ai/parse-ledger', { token: token1, body: { text: '发了奖金30', today: '2025-06-02' } });
    ok(r.status === 200, '返回 200', `实际 ${r.status}`);
    ok(r.json?.items?.length === 1, '正确解析出 1 条', String(r.json?.items?.length));
    ok(r.json?.items?.[0]?.amount === 30, 'amount 为 30', String(r.json?.items?.[0]?.amount));
    ok(r.json?.items?.[0]?.kind === 'income', 'kind 识别为 income', String(r.json?.items?.[0]?.kind));
    ok(r.json?.total === 0, '收入不计入支出 total（total=0）', String(r.json?.total));
  }

  // -------------------------------------------------------------------------
  console.log('\n4. 脏数据强校验与兜底');
  {
    mockMode = 'messy';
    const r = await req('POST', '/api/ai/parse-ledger', { token: token1, body: { text: '脏数据测试', today: '2025-06-03' } });
    ok(r.status === 200, '返回 200（不因脏数据崩溃）', `实际 ${r.status}`);
    ok(Array.isArray(r.json?.items), 'items 是数组');
    // 只有 3 条有效：'￥1,234.50'(1234.5)、'88.8'、-50? 负数应被丢弃；0 丢弃；'abc' 丢弃；非对象丢弃
    const amounts = (r.json?.items || []).map((i) => i.amount);
    ok(amounts.includes(1234.5), '从 "￥1,234.50" 中正确提取出 1234.5', JSON.stringify(amounts));
    ok(amounts.includes(88.8), '从字符串 "88.8" 中正确提取出 88.8');
    ok(!amounts.some((a) => a < 0), '负数金额被丢弃');
    ok(!amounts.some((a) => a === 0), '零金额被丢弃');
    ok(amounts.every((a) => typeof a === 'number' && Number.isFinite(a)), '所有 amount 都是有限数字');

    const first = r.json.items[0];
    ok(first.kind === 'expense', '中文「支出」归一化为 expense', String(first.kind));
    const income = (r.json.items || []).find((i) => i.note === '红包');
    ok(income?.kind === 'income', '中文「收入」归一化为 income', String(income?.kind));
    ok(first.category === '其它', '空 category 兜底为「其它」', String(first.category));
    ok([...String(first.note)].length <= 100, 'note 被截断到 100 字以内', String([...String(first.note)].length));
    ok(first.date === '2025-06-03', '非法日期回落为传入的 today', String(first.date));
    ok(typeof r.json.summary === 'string' && r.json.summary.length > 0, 'summary 为空时自动生成兜底文案', String(r.json.summary));
    ok(typeof r.json.total === 'number', 'total 是数字', String(r.json.total));
  }

  // -------------------------------------------------------------------------
  console.log('\n5. 非 JSON 输出（无法解析）应返回空结果而非报错');
  {
    mockMode = 'garbage';
    const r = await req('POST', '/api/ai/parse-ledger', { token: token1, body: { text: '随便说说', today: '2025-06-04' } });
    ok(r.status === 200, '返回 200（容错兜底）', `实际 ${r.status}`);
    ok(Array.isArray(r.json?.items) && r.json.items.length === 0, 'items 为空数组', JSON.stringify(r.json?.items));
    ok(r.json?.total === 0, 'total 为 0');
    ok(typeof r.json?.summary === 'string' && r.json.summary.length > 0, '返回兜底 summary', String(r.json?.summary));
  }

  // -------------------------------------------------------------------------
  console.log('\n6. 5xx 自动重试');
  {
    mockMode = 'flaky';
    mockHits = 0;
    const r = await req('POST', '/api/ai/parse-ledger', { token: token1, body: { text: '重试测试' } });
    ok(r.status === 200, '重试后最终成功（200）', `实际 ${r.status} ${JSON.stringify(r.json)}`);
    ok(r.json?.items?.[0]?.amount === 10, '取到第 3 次成功的结果');
    ok(mockHits === 3, `共请求模拟服务 3 次（1 首次 + 2 重试），实际 ${mockHits}`, String(mockHits));
  }

  // -------------------------------------------------------------------------
  console.log('\n7. 无效 Key -> 403 forbidden（不重试）');
  {
    // 模拟服务只接受 sk-verify-key，这里临时把保存的 Key 换成错的
    await req('PUT', '/admin/api/settings', { adminToken, body: { deepseekApiKey: 'sk-wrong-key' } });
    mockHits = 0;
    const r = await req('POST', '/api/ai/parse-ledger', { token: token1, body: { text: '鉴权测试' } });
    ok(r.status === 403, '状态码 403', `实际 ${r.status}`);
    ok(r.json?.error === 'forbidden', 'error 为 forbidden', String(r.json?.error));
    ok(mockHits === 1, `鉴权失败不重试（只请求 1 次），实际 ${mockHits}`, String(mockHits));
    // 恢复正确的 Key
    await req('PUT', '/admin/api/settings', { adminToken, body: { deepseekApiKey: 'sk-verify-key' } });
  }

  // -------------------------------------------------------------------------
  console.log('\n8. 月度报告');
  {
    mockMode = 'report';
    const r = await req('POST', '/api/ai/monthly-report', {
      token: token1,
      body: {
        month: '2025-06',
        totals: { expense: 3210.5, income: 12000 },
        byCategory: [{ category: '餐饮', amount: 980.2 }, { category: '交通', amount: 300 }],
        days: 30,
      },
    });
    ok(r.status === 200, '返回 200', `实际 ${r.status} ${JSON.stringify(r.json)}`);
    ok(typeof r.json?.text === 'string' && r.json.text.length > 0, 'text 非空');
    ok(!r.json.text.includes('```'), 'text 中已剥离代码块标记');
    ok(r.json?.model === 'deepseek-chat', 'model 字段正确');

    // 参数校验
    const badMonth = await req('POST', '/api/ai/monthly-report', { token: token1, body: { month: '2025/06', totals: { expense: 1, income: 0 } } });
    ok(badMonth.status === 400, '非法 month 格式返回 400', `实际 ${badMonth.status}`);
    const badTotals = await req('POST', '/api/ai/monthly-report', { token: token1, body: { month: '2025-06' } });
    ok(badTotals.status === 400, '缺少 totals 返回 400', `实际 ${badTotals.status}`);
  }

  // -------------------------------------------------------------------------
  console.log('\n9. 每日限额 -> 429 rate_limited');
  {
    // 把限额设为 0 表示不限制，先确认 0 = 不限制
    await req('PUT', '/admin/api/settings', { adminToken, body: { aiDailyLimit: 0 } });
    mockMode = 'plain';
    const unlimited = await req('POST', '/api/ai/parse-ledger', { token: token1, body: { text: '限额0测试' } });
    ok(unlimited.status === 200, 'aiDailyLimit=0 时不限制调用', `实际 ${unlimited.status}`);

    // 取当前已用次数，把限额设为已用量（下一次必然超限）
    const before = await req('GET', '/api/ai/status', { token: token1 });
    const used = before.json.usedToday;
    await req('PUT', '/admin/api/settings', { adminToken, body: { aiDailyLimit: used } });
    const limited = await req('POST', '/api/ai/parse-ledger', { token: token1, body: { text: '超限测试' } });
    ok(limited.status === 429, `达到限额后返回 429（已用 ${used}）`, `实际 ${limited.status} ${JSON.stringify(limited.json)}`);
    ok(limited.json?.error === 'rate_limited', 'error 为 rate_limited', String(limited.json?.error));

    const st = await req('GET', '/api/ai/status', { token: token1 });
    ok(st.json?.usedToday >= used, 'ai/status.usedToday 随调用递增', `${st.json?.usedToday}`);
    ok(st.json?.dailyLimit === used, 'ai/status.dailyLimit 反映新限额');

    // 恢复
    await req('PUT', '/admin/api/settings', { adminToken, body: { aiDailyLimit: 200 } });
  }

  // -------------------------------------------------------------------------
  console.log('\n10. AI 开关关闭 -> 403 forbidden');
  {
    await req('PUT', '/admin/api/settings', { adminToken, body: { aiEnabled: false } });
    const r = await req('POST', '/api/ai/parse-ledger', { token: token1, body: { text: '开关测试' } });
    ok(r.status === 403, '关闭 AI 后返回 403', `实际 ${r.status}`);
    ok(/关闭/.test(String(r.json?.message)), 'message 说明 AI 已关闭', String(r.json?.message));

    const meta = await req('GET', '/api/meta');
    ok(meta.json?.features?.ai === false, 'meta.features.ai 同步为 false');

    await req('PUT', '/admin/api/settings', { adminToken, body: { aiEnabled: true } });
  }

  // -------------------------------------------------------------------------
  console.log('\n11. ai_usage 记录（用于每日限额）');
  {
    const logs = await req('GET', '/admin/api/stats', { adminToken });
    ok(typeof logs.json?.aiToday === 'number' && logs.json.aiToday > 0, 'stats.aiToday 记录了调用次数', String(logs.json?.aiToday));
    ok(typeof logs.json?.aiTotal === 'number' && logs.json.aiTotal > 0, 'stats.aiTotal 记录了累计调用', String(logs.json?.aiTotal));
  }

  // -------------------------------------------------------------------------
  console.log('\n12. 后台「测试」接口（POST /admin/api/ai/test）');
  {
    mockMode = 'plain';
    const r = await req('POST', '/admin/api/ai/test', { adminToken, body: { text: '测试固定用例' } });
    ok(r.status === 200, '返回 200', `实际 ${r.status} ${JSON.stringify(r.json)}`);
    ok(r.json?.ok === true, 'ok 为 true');
    ok(Array.isArray(r.json?.items), '返回 items');
    ok(r.json?.total === 25, 'total 为 25', String(r.json?.total));
    ok(r.json?.usingTemporaryKey === false, '默认使用已保存的 Key');

    // 用临时 Key 测试（模拟服务接受 sk-verify-key）
    const r2 = await req('POST', '/admin/api/ai/test', {
      adminToken,
      body: { text: '测试固定用例', deepseekApiKey: 'sk-verify-key', deepseekModel: 'deepseek-reasoner' },
    });
    ok(r2.status === 200, '临时 Key 测试返回 200', `实际 ${r2.status}`);
    ok(r2.json?.usingTemporaryKey === true, '标记为使用临时 Key');

    // 临时 Key / 临时模型都不应被持久化
    const s = await req('GET', '/admin/api/settings', { adminToken });
    ok(s.json?.deepseekApiKeyMasked === 'sk-******-key', '临时测试后原 Key 未被覆盖（掩码不变）', String(s.json?.deepseekApiKeyMasked));
    ok(s.json?.deepseekModel === 'deepseek-chat', '临时测试后原模型未被覆盖', String(s.json?.deepseekModel));

    // 错误的临时 Key -> 受控错误
    const r3 = await req('POST', '/admin/api/ai/test', { adminToken, body: { text: 'x', deepseekApiKey: 'sk-wrong-key' } });
    ok([401, 403].includes(r3.status), `错误临时 Key 返回受控错误（${r3.status}）`, JSON.stringify(r3.json));
  }

  console.log('\n========================================');
  if (failed === 0) {
    console.log(`  ✅ AI 链路全部通过  共 ${passed} 项断言`);
  } else {
    console.log(`  ❌ 失败 ${failed} 项 / 通过 ${passed} 项`);
  }
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
  await new Promise((r) => mockServer.close(r));
  if (childFd !== null) { try { fs.closeSync(childFd); } catch { /* 忽略 */ } }
  fs.rmSync(DATA_DIR_ABS, { recursive: true, force: true });
  fs.rmSync(LOG_FILE, { force: true });
  console.log('已清理验证数据目录与日志\n');
  process.exit(exitCode);
}
