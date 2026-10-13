#!/usr/bin/env node
/**
 * 服务端环境自检：一眼看出「为什么起不来」。
 *
 * 用法：
 *   npm run doctor
 *
 * 检查项：
 *   1. Node 版本（需 20+）
 *   2. better-sqlite3 原生模块是否存在、平台是否匹配、能否真正加载
 *   3. .env 是否配置（JWT_SECRET / ADMIN_PASSWORD / PORT）
 *   4. 数据目录是否可写
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const __dirname = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(__dirname, '..');

const results = [];
const ok = (name, detail = '') => results.push({ level: 'ok', name, detail });
const warn = (name, detail = '') => results.push({ level: 'warn', name, detail });
const bad = (name, detail = '') => results.push({ level: 'bad', name, detail });

// ---------------------------------------------------------------------------
// 1) Node 版本
// ---------------------------------------------------------------------------
const nodeMajor = Number(process.version.replace(/^v/, '').split('.')[0]);
if (nodeMajor >= 20) {
  ok(`Node 版本 ${process.version}`);
} else {
  bad(`Node 版本 ${process.version} 过低`, '需要 Node 20 及以上（better-sqlite3 v12 的要求）');
}

ok(`运行平台 ${process.platform} / ${process.arch}`);
ok(`工作目录 ${ROOT}`);

// ---------------------------------------------------------------------------
// 2) better-sqlite3 原生模块
// ---------------------------------------------------------------------------
const nativePath = path.join(
  ROOT,
  'node_modules',
  'better-sqlite3',
  'build',
  'Release',
  'better_sqlite3.node'
);

/** 读文件头判断原生二进制是哪个平台的 */
function detectBinaryPlatform(file) {
  try {
    const fd = fs.openSync(file, 'r');
    const buf = Buffer.alloc(4);
    fs.readSync(fd, buf, 0, 4, 0);
    fs.closeSync(fd);
    if (buf[0] === 0x4d && buf[1] === 0x5a) return 'win32'; // MZ
    if (buf[0] === 0x7f && buf[1] === 0x45) return 'linux'; // ELF
    const machO = [0xcf, 0xce, 0xca, 0xfe];
    if (machO.includes(buf[0])) return 'darwin'; // Mach-O
    return 'unknown';
  } catch {
    return 'unknown';
  }
}

const platformName = { win32: 'Windows', linux: 'Linux', darwin: 'macOS', unknown: '未知' };

if (!fs.existsSync(nativePath)) {
  bad('better-sqlite3 原生模块不存在', '执行：npm install（网络受限时再执行 npm run fetch-sqlite）');
} else {
  const binPlatform = detectBinaryPlatform(nativePath);
  const sizeMB = (fs.statSync(nativePath).size / 1024 / 1024).toFixed(2);
  const mismatch =
    (binPlatform === 'win32' && process.platform !== 'win32') ||
    (binPlatform === 'linux' && process.platform !== 'linux') ||
    (binPlatform === 'darwin' && process.platform !== 'darwin');

  if (mismatch) {
    bad(
      `原生模块平台不匹配：文件是 ${platformName[binPlatform]} 版，当前却是 ${platformName[process.platform]}`,
      [
        '这是把本机（例如 Windows）已经装好的 node_modules 一起上传到服务器导致的。',
        '在 server 目录下执行：',
        '    rm -rf node_modules && npm install',
        '（仍然失败再执行： npm run fetch-sqlite）',
      ].join('\n     ')
    );
  } else {
    ok(`原生模块文件平台正确（${platformName[binPlatform]}，${sizeMB} MB）`);
  }

  // ⚠️ better-sqlite3 的原生库是在 new Database() 时才由 bindings 加载的，
  //    所以必须真的建一个内存库试跑，才能确认「服务器一定能起来」。
  try {
    const Database = require('better-sqlite3');
    const probe = new Database(':memory:');
    const row = probe.prepare('SELECT 1 AS n').get();
    probe.close();
    if (row && row.n === 1) ok('better-sqlite3 可以正常使用（内存库试跑通过）');
    else warn('better-sqlite3 试跑结果异常', JSON.stringify(row));
  } catch (err) {
    const first = String(err && err.message ? err.message : err).split('\n')[0];
    bad(
      'better-sqlite3 无法使用',
      [
        `${err && err.code ? err.code + ': ' : ''}${first}`,
        '常见原因与处理：',
        '    1) 平台不匹配 → rm -rf node_modules && npm install',
        '    2) 二进制缺失   → npm run fetch-sqlite',
        '    3) 源码编译失败 → apt install -y python3 make g++ 后 npm install --build-from-source',
      ].join('\n     ')
    );
  }
}

// ---------------------------------------------------------------------------
// 3) .env 配置
// ---------------------------------------------------------------------------
const envPath = path.join(ROOT, '.env');
let dataDirFromEnv = './data';
if (!fs.existsSync(envPath)) {
  warn('.env 不存在', '执行：cp .env.example .env 并填写 JWT_SECRET / ADMIN_PASSWORD');
} else {
  const text = fs.readFileSync(envPath, 'utf8');
  const read = (key, fallback = '') => {
    const m = text.match(new RegExp(`^\\s*${key}\\s*=\\s*(.*)$`, 'm'));
    if (!m) return fallback;
    return m[1].trim().replace(/^["']|["']$/g, '');
  };

  const jwt = read('JWT_SECRET');
  const admin = read('ADMIN_PASSWORD');
  const port = read('PORT', '8787');
  dataDirFromEnv = read('DATA_DIR', './data') || './data';

  if (jwt.length >= 32) ok('JWT_SECRET 已配置');
  else bad('JWT_SECRET 缺失或过短（需 ≥32 位随机串）', '见 .env.example 注释里的生成命令');

  if (admin.length >= 6) ok('ADMIN_PASSWORD 已配置');
  else bad('ADMIN_PASSWORD 缺失或过短（需 ≥6 位）', '这是后台管理页面的登录密码');

  ok(`监听端口 ${port}`);

  if (/^请替换|^<|^your/i.test(jwt) || /^请替换|^<|^your/i.test(admin)) {
    warn('检测到 .env 里还留着示例占位文字', '请改成你自己的随机串');
  }
}

// ---------------------------------------------------------------------------
// 4) 数据目录可写
// ---------------------------------------------------------------------------
const resolvedDataDir = path.isAbsolute(dataDirFromEnv)
  ? dataDirFromEnv
  : path.join(ROOT, dataDirFromEnv);
try {
  fs.mkdirSync(resolvedDataDir, { recursive: true });
  fs.accessSync(resolvedDataDir, fs.constants.W_OK);
  ok(`数据目录可写：${resolvedDataDir}`);
} catch (err) {
  bad(`数据目录不可写：${resolvedDataDir}`, err.message);
}

// ---------------------------------------------------------------------------
// 输出
// ---------------------------------------------------------------------------
const line = '─'.repeat(52);
process.stdout.write(`\n情侣空间服务端 · 环境自检\n${line}\n`);
for (const r of results) {
  const icon = r.level === 'ok' ? '✅' : r.level === 'warn' ? '⚠️ ' : '❌';
  process.stdout.write(`${icon} ${r.name}\n`);
  if (r.detail) process.stdout.write(`     → ${r.detail}\n`);
}
const badCount = results.filter((r) => r.level === 'bad').length;
const warnCount = results.filter((r) => r.level === 'warn').length;
process.stdout.write(`${line}\n`);
if (badCount === 0) {
  process.stdout.write(
    warnCount === 0
      ? '✅ 全部通过，可以执行 npm start\n\n'
      : `✅ 没有阻塞问题（${warnCount} 项提醒），可以执行 npm start\n\n`
  );
} else {
  process.stdout.write(`❌ 有 ${badCount} 项必须处理，按上面的 → 提示操作后重跑 npm run doctor\n\n`);
}
process.exit(badCount === 0 ? 0 : 1);
