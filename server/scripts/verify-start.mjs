// 启动验证：按默认端口启动服务，确认无报错后停止。
//
// 检查项：
//   1. `npm start` 对应的 node src/index.js 能正常启动
//   2. /api/health、/api/meta、/admin 均可访问
//   3. 期间日志中没有 error 级别输出
//   4. SIGTERM 能优雅退出
//
// 用法： node scripts/verify-start.mjs
import { spawn } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const SERVER_DIR = path.resolve(__dirname, '..');

// 使用独立端口与数据目录，避免影响真实数据
const PORT = 8798;
const BASE = `http://127.0.0.1:${PORT}`;
const DATA_DIR = './data-verify';
const DATA_DIR_ABS = path.join(SERVER_DIR, 'data-verify');
const LOG_FILE = path.join(SERVER_DIR, 'verify-start.log');

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

function readLog() {
  try {
    return fs.readFileSync(LOG_FILE, 'utf8');
  } catch {
    return '';
  }
}

async function main() {
  fs.rmSync(LOG_FILE, { force: true });
  fs.rmSync(DATA_DIR_ABS, { recursive: true, force: true });

  const fd = fs.openSync(LOG_FILE, 'a');

  const child = spawn(process.execPath, ['src/index.js'], {
    cwd: SERVER_DIR,
    env: {
      ...process.env,
      PORT: String(PORT),
      DATA_DIR,
      JWT_SECRET: crypto.randomBytes(48).toString('hex'),
      ADMIN_PASSWORD: 'verify123',
      ADMIN_JWT_SECRET: crypto.randomBytes(48).toString('hex'),
      LOG_LEVEL: 'info',
      DOTENV_CONFIG_PATH: path.join(SERVER_DIR, '.env.nonexistent'),
    },
    // 日志写入文件（不使用管道，兼容受限环境）
    stdio: ['ignore', fd, fd],
  });

  let exited = false;
  child.on('exit', () => { exited = true; });

  // 等待启动（最多 20 秒）
  let ready = false;
  const deadline = Date.now() + 20000;
  while (Date.now() < deadline) {
    try {
      const res = await fetch(`${BASE}/api/health`);
      if (res.ok) { ready = true; break; }
    } catch {
      /* 继续等 */
    }
    await sleep(250);
  }

  console.log(ready ? '✅ 服务在默认流程下启动成功' : '❌ 服务启动失败');
  if (!ready) {
    console.log('---- 日志 ----');
    console.log(readLog());
    try { child.kill('SIGKILL'); } catch { /* 忽略 */ }
    fs.closeSync(fd);
    process.exit(1);
  }

  // 探活三个入口
  for (const [label, url, check] of [
    ['/api/health', `${BASE}/api/health`, (j) => j.ok === true],
    ['/api/meta', `${BASE}/api/meta`, (j) => typeof j.appName === 'string'],
    ['/admin', `${BASE}/admin`, null],
    ['/', `${BASE}/`, null],
  ]) {
    const res = await fetch(url);
    let pass = res.ok;
    if (check) {
      const j = await res.json();
      pass = pass && check(j);
    }
    console.log(`${pass ? '✅' : '❌'} ${label} -> HTTP ${res.status}`);
  }

  // 稳定运行 3 秒（按要求「起 3 秒后杀掉」）
  console.log('⏳ 保持运行 3 秒…');
  await sleep(3000);

  console.log(exited ? '❌ 服务意外退出' : '✅ 运行期间进程存活');

  // 优雅退出
  child.kill('SIGTERM');
  const stopDeadline = Date.now() + 6000;
  while (!exited && Date.now() < stopDeadline) await sleep(150);
  console.log(exited ? '✅ SIGTERM 后优雅退出' : '⚠️  SIGTERM 后未能及时退出');

  fs.closeSync(fd);

  // 检查日志中是否有 error / 异常
  const log = readLog();
  const errorLines = log
    .split('\n')
    .filter((l) => l.includes('"level":"error"') || /Error:|EADDRINUSE|未捕获/.test(l));
  console.log(errorLines.length === 0
    ? '✅ 日志中无 error 级别输出'
    : `❌ 日志中发现 ${errorLines.length} 条错误：\n${errorLines.join('\n')}`);

  console.log('\n---- 启动日志（完整） ----');
  console.log(log.trim());

  // 清理
  try {
    fs.rmSync(DATA_DIR_ABS, { recursive: true, force: true });
    fs.rmSync(LOG_FILE, { force: true });
    console.log('\n✅ 已清理验证数据目录与日志');
  } catch (err) {
    console.log(`\n⚠️  清理失败：${err.message}`);
  }

  process.exit(errorLines.length === 0 ? 0 : 1);
}

main().catch((err) => {
  console.error('验证脚本异常：', err);
  process.exit(1);
});
