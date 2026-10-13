// settings 表读写服务。
//
// 安全要点：
//   1. DeepSeek API Key 落库前用 AES-256-GCM 加密（密钥由 JWT_SECRET 派生），
//      即使数据库文件泄漏也不会直接暴露明文 Key。
//   2. 对外（后台接口）一律只返回掩码，绝不返回明文。
//   3. 明文 Key 仅存在于服务端内存与 settings 表的加密值中。
import crypto from 'node:crypto';
import { CONFIG, DEFAULTS } from '../config.js';
import { getAllSettings, setSetting, setSettings, getSetting } from '../db.js';
import { log } from '../util/log.js';

// ---------------------------------------------------------------------------
// 加密 / 解密
// ---------------------------------------------------------------------------

// 加密标记前缀，便于识别历史明文值（兼容旧数据）
const ENC_PREFIX = 'enc:v1:';

/** 由 JWT_SECRET 派生 32 字节加密密钥 */
function deriveKey() {
  return crypto.createHash('sha256').update(`couple-server:settings:${CONFIG.jwtSecret}`).digest();
}

/**
 * 加密字符串（AES-256-GCM）
 * 输出格式： enc:v1:<iv_base64>:<tag_base64>:<cipher_base64>
 * @param {string} plain
 * @returns {string}
 */
export function encryptSecret(plain) {
  if (typeof plain !== 'string' || plain === '') return '';
  const iv = crypto.randomBytes(12);
  const cipher = crypto.createCipheriv('aes-256-gcm', deriveKey(), iv);
  const enc = Buffer.concat([cipher.update(plain, 'utf8'), cipher.final()]);
  const tag = cipher.getAuthTag();
  return `${ENC_PREFIX}${iv.toString('base64')}:${tag.toString('base64')}:${enc.toString('base64')}`;
}

/**
 * 解密字符串；非加密格式（历史明文）原样返回。
 * @param {string} value
 * @returns {string}
 */
export function decryptSecret(value) {
  if (typeof value !== 'string' || value === '') return '';
  if (!value.startsWith(ENC_PREFIX)) return value; // 兼容历史明文
  try {
    const parts = value.slice(ENC_PREFIX.length).split(':');
    if (parts.length !== 3) return '';
    const [ivB64, tagB64, dataB64] = parts;
    const decipher = crypto.createDecipheriv('aes-256-gcm', deriveKey(), Buffer.from(ivB64, 'base64'));
    decipher.setAuthTag(Buffer.from(tagB64, 'base64'));
    return Buffer.concat([decipher.update(Buffer.from(dataB64, 'base64')), decipher.final()]).toString('utf8');
  } catch (err) {
    // 密钥变更或数据损坏
    log.warn('DeepSeek Key 解密失败（可能是 JWT_SECRET 已变更）', { error: err.message });
    return '';
  }
}

/**
 * 生成掩码：只保留首尾少量字符，中间用星号。
 * 例：sk-1234567890abcd -> sk-****abcd
 * @param {string} plain 明文 Key
 * @returns {string} 掩码；未配置时返回空字符串
 */
export function maskSecret(plain) {
  if (typeof plain !== 'string' || plain === '') return '';
  const len = plain.length;
  if (len <= 4) return '*'.repeat(len);
  if (len <= 8) return `${plain.slice(0, 2)}${'*'.repeat(len - 4)}${plain.slice(-2)}`;
  const head = plain.startsWith('sk-') ? 'sk-' : plain.slice(0, 3);
  const tail = plain.slice(-4);
  const stars = '*'.repeat(Math.max(4, Math.min(12, len - head.length - 4)));
  return `${head}${stars}${tail}`;
}

// ---------------------------------------------------------------------------
// 读取 / 写入
// ---------------------------------------------------------------------------

// 对外暴露的设置字段（与 API.md §9 的 GET /admin/api/settings 响应一致）
const PUBLIC_KEYS = [
  'deepseekBaseUrl',
  'deepseekModel',
  'aiEnabled',
  'aiDailyLimit',
  'registerOpen',
  'announcement',
  'minAppBuild',
  'latestAppBuild',
  'updateURL',
  'maxEmojiPacks',
  'maxMessagesPerDay',
];

/**
 * 读取全部设置（含解密后的明文 Key，仅供服务端内部使用）
 * @returns {object}
 */
export function readSettings() {
  const raw = getAllSettings();
  const out = { ...DEFAULTS, ...raw };
  // 解密 Key
  out.deepseekApiKey = decryptSecret(raw.deepseekApiKey ?? '');
  return out;
}

/**
 * 后台接口用的设置视图：Key 只返回掩码。
 * @returns {object}
 */
export function readSettingsForAdmin() {
  const s = readSettings();
  const view = {
    deepseekApiKeyMasked: maskSecret(s.deepseekApiKey),
    // 是否已配置 Key，便于前端显示状态
    deepseekApiKeyConfigured: !!s.deepseekApiKey,
  };
  for (const key of PUBLIC_KEYS) view[key] = s[key];
  return view;
}

// 允许通过后台修改的字段及其类型校验
const WRITABLE = {
  deepseekApiKey: 'string',
  deepseekBaseUrl: 'string',
  deepseekModel: 'string',
  aiEnabled: 'boolean',
  aiDailyLimit: 'int',
  registerOpen: 'boolean',
  announcement: 'string',
  minAppBuild: 'int',
  latestAppBuild: 'int',
  updateURL: 'string',
  maxEmojiPacks: 'int',
  maxMessagesPerDay: 'int',
};

/**
 * 更新设置（只处理传入的字段）
 * @param {object} patch
 * @returns {object} 更新后的后台视图
 */
export function updateSettings(patch) {
  const entries = {};
  for (const [key, value] of Object.entries(patch || {})) {
    const type = WRITABLE[key];
    if (!type) continue; // 忽略未知字段，避免脏数据
    if (type === 'string') {
      if (value === null || value === undefined) continue;
      entries[key] = String(value);
    } else if (type === 'boolean') {
      if (typeof value !== 'boolean') continue;
      entries[key] = value;
    } else if (type === 'int') {
      const n = Number(value);
      if (!Number.isInteger(n) || n < 0) continue;
      entries[key] = n;
    }
  }

  // Key 单独处理：加密后落库；空字符串表示清空
  if (Object.prototype.hasOwnProperty.call(entries, 'deepseekApiKey')) {
    const plain = entries.deepseekApiKey.trim();
    delete entries.deepseekApiKey;
    setSetting('deepseekApiKey', plain === '' ? '' : encryptSecret(plain));
  }

  if (Object.keys(entries).length > 0) setSettings(entries);
  return readSettingsForAdmin();
}

/** 读取单个设置原始值 */
export function getSettingValue(key, fallback) {
  const v = getSetting(key);
  return v === undefined ? fallback : v;
}

/** AI 是否可用（开关打开 + 已配置 Key） */
export function isAiConfigured() {
  const s = readSettings();
  return !!s.deepseekApiKey;
}

/** AI 功能是否开启 */
export function isAiEnabled() {
  return readSettings().aiEnabled !== false;
}

/** 当前 AI 每日限额 */
export function getAiDailyLimit() {
  const s = readSettings();
  const n = Number(s.aiDailyLimit);
  return Number.isInteger(n) && n >= 0 ? n : DEFAULTS.aiDailyLimit;
}

/** 注册是否开放 */
export function isRegisterOpen() {
  return readSettings().registerOpen !== false;
}

export default {
  readSettings,
  readSettingsForAdmin,
  updateSettings,
  encryptSecret,
  decryptSecret,
  maskSecret,
  isAiConfigured,
  isAiEnabled,
  getAiDailyLimit,
  isRegisterOpen,
};
