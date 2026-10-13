// 配置校验与 init-db 验证
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const SERVER_DIR = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const DATA_DIR = path.join(SERVER_DIR, 'data-verify');

function run(args, env) {
  // 输出写入文件（不使用管道，兼容受限环境的 spawn 限制）
  const outFile = path.join(SERVER_DIR, 'cfg-probe.log');
  fs.rmSync(outFile, { force: true });
  const fd = fs.openSync(outFile, 'a');
  const r = spawnSync(process.execPath, args, {
    cwd: SERVER_DIR,
    env,
    stdio: ['ignore', fd, fd],
  });
  fs.closeSync(fd);
  const text = fs.readFileSync(outFile, 'utf8');
  fs.rmSync(outFile, { force: true });
  return { status: r.status, text };
}

const baseEnv = { ...process.env, DOTENV_CONFIG_PATH: path.join(SERVER_DIR, '.env.nonexistent') };

console.log('=== 1. 缺少 JWT_SECRET 时应明确报错退出 ===');
{
  const env = { ...baseEnv, ADMIN_PASSWORD: 'test123' };
  delete env.JWT_SECRET;
  const r = run(['src/index.js'], env);
  const hit = /缺少环境变量 JWT_SECRET/.test(r.text);
  console.log(`${r.status !== 0 ? '✅' : '❌'} 退出码非 0（实际 ${r.status}）`);
  console.log(`${hit ? '✅' : '❌'} 报错信息提到缺少 JWT_SECRET`);
  if (!hit) console.log(r.text);
}

console.log('\n=== 2. 缺少 ADMIN_PASSWORD 时应明确报错退出 ===');
{
  const env = { ...baseEnv, JWT_SECRET: 'x'.repeat(48) };
  delete env.ADMIN_PASSWORD;
  const r = run(['src/index.js'], env);
  const hit = /缺少环境变量 ADMIN_PASSWORD/.test(r.text);
  console.log(`${r.status !== 0 ? '✅' : '❌'} 退出码非 0（实际 ${r.status}）`);
  console.log(`${hit ? '✅' : '❌'} 报错信息提到缺少 ADMIN_PASSWORD`);
  if (!hit) console.log(r.text);
}

console.log('\n=== 3. JWT_SECRET 过短时应拒绝启动 ===');
{
  const r = run(['src/index.js'], { ...baseEnv, JWT_SECRET: 'short', ADMIN_PASSWORD: 'test123' });
  const hit = /JWT_SECRET 过短/.test(r.text);
  console.log(`${r.status !== 0 ? '✅' : '❌'} 退出码非 0（实际 ${r.status}）`);
  console.log(`${hit ? '✅' : '❌'} 报错信息提示密钥过短`);
  if (!hit) console.log(r.text);
}

console.log('\n=== 4. npm run init-db 应建库并写入默认设置 ===');
{
  fs.rmSync(DATA_DIR, { recursive: true, force: true });
  const r = run(['src/init-db.js'], {
    ...baseEnv,
    JWT_SECRET: 'y'.repeat(48),
    ADMIN_PASSWORD: 'test123',
    DATA_DIR: './data-verify',
  });
  console.log(`${r.status === 0 ? '✅' : '❌'} 退出码为 0（实际 ${r.status}）`);
  const created = fs.existsSync(path.join(DATA_DIR, 'couple.sqlite'));
  console.log(`${created ? '✅' : '❌'} 已创建 couple.sqlite`);
  const dirCreated = fs.existsSync(path.join(DATA_DIR, 'emoji'));
  console.log(`${dirCreated ? '✅' : '❌'} 已自动创建 emoji 目录`);
  const defaultsOk = /aiEnabled=true/.test(r.text) && /aiDailyLimit=200/.test(r.text) && /registerOpen=true/.test(r.text);
  console.log(`${defaultsOk ? '✅' : '❌'} 默认 settings 已写入（aiEnabled/aiDailyLimit/registerOpen）`);

  // 校验表结构完整
  const { default: Database } = await import('better-sqlite3');
  const db = new Database(path.join(DATA_DIR, 'couple.sqlite'), { readonly: true });
  const tables = db.prepare("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name").all().map((t) => t.name);
  const expected = ['admin_logs', 'ai_usage', 'couples', 'emoji_items', 'emoji_packs', 'messages', 'settings', 'sync_objects', 'users'];
  const missing = expected.filter((t) => !tables.includes(t));
  console.log(`${missing.length === 0 ? '✅' : '❌'} API.md 第 10 节要求的 9 张表齐备${missing.length ? '，缺：' + missing.join(',') : ''}`);
  const jm = db.pragma('journal_mode', { simple: true });
  console.log(`${jm === 'wal' ? '✅' : '❌'} WAL 模式已开启（journal_mode=${jm}）`);

  const settingsRows = db.prepare('SELECT key, value FROM settings').all();
  const keys = settingsRows.map((r) => r.key);
  const needKeys = ['aiEnabled', 'aiDailyLimit', 'registerOpen', 'deepseekBaseUrl', 'deepseekModel'];
  const missKeys = needKeys.filter((k) => !keys.includes(k));
  console.log(`${missKeys.length === 0 ? '✅' : '❌'} 默认设置项齐备${missKeys.length ? '，缺：' + missKeys.join(',') : ''}`);
  const baseUrl = settingsRows.find((r) => r.key === 'deepseekBaseUrl');
  console.log(`${baseUrl && JSON.parse(baseUrl.value) === 'https://api.deepseek.com' ? '✅' : '❌'} deepseekBaseUrl 默认值为 https://api.deepseek.com`);
  const model = settingsRows.find((r) => r.key === 'deepseekModel');
  console.log(`${model && JSON.parse(model.value) === 'deepseek-chat' ? '✅' : '❌'} deepseekModel 默认值为 deepseek-chat`);

  // 二次启动应保持幂等（不覆盖已有设置）
  db.close();
  const db2 = new Database(path.join(DATA_DIR, 'couple.sqlite'));
  db2.prepare("UPDATE settings SET value = ? WHERE key = 'announcement'").run(JSON.stringify('保持我'));
  db2.close();

  const r2 = run(['src/init-db.js'], {
    ...baseEnv,
    JWT_SECRET: 'y'.repeat(48),
    ADMIN_PASSWORD: 'test123',
    DATA_DIR: './data-verify',
  });
  const db3 = new Database(path.join(DATA_DIR, 'couple.sqlite'), { readonly: true });
  const ann = db3.prepare("SELECT value FROM settings WHERE key='announcement'").get();
  db3.close();
  const kept = ann && JSON.parse(ann.value) === '保持我';
  console.log(`${r2.status === 0 && kept ? '✅' : '❌'} 重复执行 init-db 不覆盖已有设置（幂等）`);

  fs.rmSync(DATA_DIR, { recursive: true, force: true });
  console.log('\n已清理验证数据目录');
}
