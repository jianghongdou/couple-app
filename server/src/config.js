// 读取环境变量并做启动校验。缺少关键变量时明确报错退出。
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import dotenv from 'dotenv';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

// server/ 根目录
export const ROOT_DIR = path.resolve(__dirname, '..');

// 先加载 server/.env（若存在）
dotenv.config({ path: path.join(ROOT_DIR, '.env') });

export const APP_NAME = 'couple-server';
export const APP_VERSION = '1.0.0';
// 客户端展示用名称（API.md §1 /api/meta 的 appName）
export const APP_DISPLAY_NAME = '情侣空间';

function toInt(value, fallback) {
  const n = Number.parseInt(String(value ?? ''), 10);
  return Number.isFinite(n) ? n : fallback;
}

function toBool(value, fallback) {
  if (value === undefined || value === null || value === '') return fallback;
  const s = String(value).trim().toLowerCase();
  if (['1', 'true', 'yes', 'on'].includes(s)) return true;
  if (['0', 'false', 'no', 'off'].includes(s)) return false;
  return fallback;
}

const errors = [];

const JWT_SECRET = process.env.JWT_SECRET || '';
const ADMIN_PASSWORD = process.env.ADMIN_PASSWORD || '';

if (!JWT_SECRET) {
  errors.push('缺少环境变量 JWT_SECRET（用户 token 签名密钥，≥32 位随机串）');
} else if (JWT_SECRET.length < 16) {
  // 文档要求 ≥32 位；这里给 16 位硬下限，过短直接拒绝启动
  errors.push(`环境变量 JWT_SECRET 过短（当前 ${JWT_SECRET.length} 位，要求 ≥32 位随机串）`);
}

if (!ADMIN_PASSWORD) {
  errors.push('缺少环境变量 ADMIN_PASSWORD（后台管理登录密码）');
} else if (ADMIN_PASSWORD.length < 6) {
  errors.push(`环境变量 ADMIN_PASSWORD 过短（当前 ${ADMIN_PASSWORD.length} 位，至少 6 位）`);
}

const DATA_DIR_RAW = process.env.DATA_DIR || './data';
// 相对路径基于 server/ 根目录解析，保证从任意 cwd 启动都一致
export const DATA_DIR = path.isAbsolute(DATA_DIR_RAW)
  ? DATA_DIR_RAW
  : path.resolve(ROOT_DIR, DATA_DIR_RAW);

export const PORT = toInt(process.env.PORT, 8787);
export const ADMIN_JWT_SECRET = process.env.ADMIN_JWT_SECRET || JWT_SECRET;
export const PUBLIC_BASE_URL = (process.env.PUBLIC_BASE_URL || '').replace(/\/+$/, '');
export const TRUST_PROXY = process.env.TRUST_PROXY === undefined || process.env.TRUST_PROXY === ''
  ? true
  : toBool(process.env.TRUST_PROXY, true);
export const LOG_LEVEL = (process.env.LOG_LEVEL || 'info').toLowerCase();

// JWT 有效期：30 天（秒）
export const JWT_EXPIRES_IN_SECONDS = 30 * 24 * 60 * 60;

// 表情文件存放目录（受 DATA_DIR 控制，便于测试隔离）
export const EMOJI_DIR = path.join(DATA_DIR, 'emoji');
// 内置公共表情包目录（服务端预置，API.md §6 isPublic=true）
export const PUBLIC_EMOJI_DIR = path.join(ROOT_DIR, 'public', 'emoji');
// 后台单页
export const ADMIN_HTML_PATH = path.join(ROOT_DIR, 'public', 'admin.html');
// SQLite 数据库文件
export const DB_FILE = path.join(DATA_DIR, 'couple.sqlite');

// 业务限制值（与 API.md 严格一致）
export const LIMITS = {
  USERNAME_MIN: 3,
  USERNAME_MAX: 32,
  PASSWORD_MIN: 6,
  PASSWORD_MAX: 64,
  SYNC_MAX_OBJECTS: 200,       // §4 单次最多 200 个对象
  SYNC_PULL_DEFAULT: 500,      // §4 limit 默认 500
  SYNC_PULL_MAX: 1000,         // §4 limit 上限 1000
  CHAT_BODY_MAX: 2000,         // §5 body 长度上限 2000 字符
  CHAT_PAGE_DEFAULT: 50,       // §5 默认 50 条
  CHAT_RECALL_WINDOW_MS: 24 * 60 * 60 * 1000, // §5 撤回 24 小时内
  COUPLE_MAX_MEMBERS: 2,       // §3 情侣空间 2 人
  COUPLE_RETENTION_DAYS: 30,   // §3 解绑后数据保留 30 天
  EMOJI_MAX_PACKS: 30,         // §6 每个情侣空间最多 30 个自建包
  EMOJI_MAX_ITEMS_PER_PACK: 200, // §6 每个包上限 200 张
  EMOJI_MAX_BYTES: 1024 * 1024,  // §6 解码后单张上限 1MB
  EMOJI_MAX_BASE64_CHARS: Math.ceil((1024 * 1024) / 3) * 4 + 16,
  EMOJI_MIMES: ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
  AI_TEXT_MAX: 2000,
  AI_DAILY_LIMIT_DEFAULT: 200,
  AI_MAX_ITEMS: 200,
};

// 同步 kind 白名单（§4）。ledger / vault 明确拒绝。
export const SYNC_KINDS = ['spark', 'pet', 'checkin', 'wish', 'worklog', 'settings'];
// 明确拒绝且需要给出解释的 kind
export const SYNC_REJECTED_KINDS = ['ledger', 'vault'];

// DeepSeek 默认配置（首次启动写入 settings 表）
export const DEFAULTS = {
  aiEnabled: true,
  aiDailyLimit: LIMITS.AI_DAILY_LIMIT_DEFAULT,
  registerOpen: true,
  deepseekBaseUrl: 'https://api.deepseek.com',
  deepseekModel: 'deepseek-chat',
  announcement: '',
  minAppBuild: 1,
  latestAppBuild: 1,
  updateURL: '',
  maxEmojiPacks: LIMITS.EMOJI_MAX_PACKS,
  maxMessagesPerDay: 3000,
  deepseekApiKey: '',
};

export const CONFIG = {
  appName: APP_NAME,
  appVersion: APP_VERSION,
  displayName: APP_DISPLAY_NAME,
  port: PORT,
  dataDir: DATA_DIR,
  dbFile: DB_FILE,
  emojiDir: EMOJI_DIR,
  jwtSecret: JWT_SECRET,
  adminJwtSecret: ADMIN_JWT_SECRET,
  adminPassword: ADMIN_PASSWORD,
  publicBaseUrl: PUBLIC_BASE_URL,
  trustProxy: TRUST_PROXY,
  logLevel: LOG_LEVEL,
  jwtExpiresInSeconds: JWT_EXPIRES_IN_SECONDS,
};

// 启动校验：有任何配置错误则打印全部错误并退出（不带着坏配置运行）
export function assertConfig() {
  if (errors.length > 0) {
    const msg = [
      '❌ 启动失败：环境配置不完整或非法。',
      ...errors.map((e, i) => `   ${i + 1}) ${e}`),
      '',
      '请复制 .env.example 为 .env 并填写：',
      '   JWT_SECRET=<至少 32 位随机串>',
      '   ADMIN_PASSWORD=<后台管理密码>',
      '生成随机密钥可执行： node -e "console.log(require(\'crypto\').randomBytes(32).toString(\'hex\'))"',
    ].join('\n');
    // 直接写 stderr，避免依赖日志模块的初始化顺序
    process.stderr.write(msg + '\n');
    process.exit(1);
  }
}

// 确保数据目录存在（启动时自动创建）
export function ensureDirs() {
  for (const dir of [DATA_DIR, EMOJI_DIR]) {
    fs.mkdirSync(dir, { recursive: true });
  }
  fs.mkdirSync(PUBLIC_EMOJI_DIR, { recursive: true });
}

export default CONFIG;
