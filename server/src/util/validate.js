// 手写参数校验工具（不引第三方校验库）
// 所有校验失败都抛出 ValidationError，由路由层统一转换为 400 invalid_input。

/** 校验错误：对应 HTTP 400 + error: "invalid_input" */
export class ValidationError extends Error {
  constructor(message, errorCode = 'invalid_input', status = 400) {
    super(message);
    this.name = 'ValidationError';
    this.errorCode = errorCode;
    this.status = status;
  }
}

/** 抛出 400 invalid_input */
export function fail(message) {
  throw new ValidationError(message, 'invalid_input', 400);
}

/** 抛出 404 not_found */
export function failNotFound(message) {
  throw new ValidationError(message, 'not_found', 404);
}

/** 抛出 403 forbidden */
export function failForbidden(message) {
  throw new ValidationError(message, 'forbidden', 403);
}

/** 抛出 409 conflict */
export function failConflict(message) {
  throw new ValidationError(message, 'conflict', 409);
}

/** 判断是否为普通对象（非 null、非数组） */
export function isPlainObject(v) {
  return typeof v === 'object' && v !== null && !Array.isArray(v);
}

/**
 * 读取必填字符串并做长度约束
 * @param {unknown} value
 * @param {string} field 字段名（用于错误信息）
 * @param {{min?:number,max?:number,trim?:boolean,pattern?:RegExp,patternHint?:string}} [opts]
 * @returns {string}
 */
export function requireString(value, field, opts = {}) {
  const { min = 1, max = Infinity, trim = false, pattern, patternHint } = opts;
  if (typeof value !== 'string') fail(`${field} 必须是字符串`);
  const s = trim ? value.trim() : value;
  // 按 Unicode 码点计数，避免 emoji 被算成 2 个字符
  const len = [...s].length;
  if (len < min) fail(`${field} 长度不能少于 ${min} 个字符`);
  if (len > max) fail(`${field} 长度不能超过 ${max} 个字符`);
  if (pattern && !pattern.test(s)) fail(`${field} 格式不正确${patternHint ? `（${patternHint}）` : ''}`);
  return s;
}

/** 可选字符串：undefined/null 返回默认值，否则按 requireString 校验 */
export function optionalString(value, field, opts = {}) {
  if (value === undefined || value === null) return opts.defaultValue;
  return requireString(value, field, opts);
}

/**
 * 校验并归一化「毫秒时间戳」数字
 * @param {unknown} value
 * @param {string} field
 * @param {{required?:boolean,min?:number,max?:number,defaultValue?:number}} [opts]
 * @returns {number|undefined}
 */
export function requireTimestamp(value, field, opts = {}) {
  const { required = false, min = 0, max = Number.MAX_SAFE_INTEGER, defaultValue } = opts;
  if (value === undefined || value === null || value === '') {
    if (required) fail(`${field} 不能为空`);
    return defaultValue;
  }
  const n = typeof value === 'number' ? value : Number(value);
  if (!Number.isFinite(n) || !Number.isInteger(n)) fail(`${field} 必须是整数毫秒时间戳`);
  if (n < min) fail(`${field} 不能小于 ${min}`);
  if (n > max) fail(`${field} 超出允许范围`);
  return n;
}

/** 可选布尔值校验 */
export function optionalBool(value, field, defaultValue) {
  if (value === undefined || value === null) return defaultValue;
  if (typeof value !== 'boolean') fail(`${field} 必须是布尔值`);
  return value;
}

/** 必填整数校验 */
export function requireInt(value, field, opts = {}) {
  const { min = Number.MIN_SAFE_INTEGER, max = Number.MAX_SAFE_INTEGER, defaultValue } = opts;
  if (value === undefined || value === null || value === '') {
    if (defaultValue !== undefined) return defaultValue;
    fail(`${field} 不能为空`);
  }
  const n = typeof value === 'number' ? value : Number(value);
  if (!Number.isInteger(n)) fail(`${field} 必须是整数`);
  if (n < min || n > max) fail(`${field} 必须在 ${min} 到 ${max} 之间`);
  return n;
}

/** 查询参数形式的整数（来自 query string，都是字符串） */
export function queryInt(value, field, opts = {}) {
  if (value === undefined || value === null || value === '') return opts.defaultValue;
  const n = Number(value);
  if (!Number.isInteger(n)) fail(`${field} 必须是整数`);
  const { min = Number.MIN_SAFE_INTEGER, max = Number.MAX_SAFE_INTEGER } = opts;
  if (n < min) fail(`${field} 不能小于 ${min}`);
  if (n > max) fail(`${field} 不能大于 ${max}`);
  return n;
}

/**
 * 校验日期字符串 YYYY-MM-DD
 * @param {unknown} value
 * @param {string} field
 * @returns {string}
 */
export function requireDateString(value, field) {
  if (typeof value !== 'string') fail(`${field} 必须是 YYYY-MM-DD 字符串`);
  if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) fail(`${field} 格式必须是 YYYY-MM-DD`);
  const [y, m, d] = value.split('-').map(Number);
  const dt = new Date(Date.UTC(y, m - 1, d));
  if (dt.getUTCFullYear() !== y || dt.getUTCMonth() !== m - 1 || dt.getUTCDate() !== d) {
    fail(`${field} 不是有效日期`);
  }
  return value;
}

/**
 * 校验 YYYY-MM 月份字符串
 * @param {unknown} value
 * @param {string} field
 * @returns {string}
 */
export function requireMonthString(value, field) {
  if (typeof value !== 'string') fail(`${field} 必须是 YYYY-MM 字符串`);
  if (!/^\d{4}-\d{2}$/.test(value)) fail(`${field} 格式必须是 YYYY-MM`);
  const m = Number(value.slice(5, 7));
  if (m < 1 || m > 12) fail(`${field} 月份必须在 01-12 之间`);
  return value;
}

/**
 * 校验 JSON 对象（用于 payload / meta）
 * @param {unknown} value
 * @param {string} field
 * @param {{allowNull?:boolean,defaultValue?:any}} [opts]
 */
export function requireJsonObject(value, field, opts = {}) {
  const { allowNull = true, defaultValue = null } = opts;
  if (value === undefined || value === null) {
    if (allowNull) return defaultValue;
    fail(`${field} 不能为空`);
  }
  if (!isPlainObject(value)) fail(`${field} 必须是 JSON 对象`);
  // 体积保护：避免超大数据写入数据库
  let serialized;
  try {
    serialized = JSON.stringify(value);
  } catch {
    fail(`${field} 无法序列化为 JSON`);
  }
  if (serialized.length > 256 * 1024) fail(`${field} 数据过大（上限 256KB）`);
  return value;
}

/** 昵称校验：1-32 字符 */
export function validateNickname(value, field = 'nickname') {
  return requireString(value, field, { min: 1, max: 32, trim: true });
}

/**
 * 头像 emoji 校验：可选，单个 emoji
 * @param {unknown} value
 * @returns {string|undefined}
 */
export function validateAvatarEmoji(value) {
  if (value === undefined || value === null || value === '') return undefined;
  if (typeof value !== 'string') fail('avatarEmoji 必须是字符串');
  // 用 Array.from 按码点切分，保证代理对（多数 emoji）算 1 个
  const chars = Array.from(value);
  if (chars.length === 0 || chars.length > 4) fail('avatarEmoji 必须是单个 emoji');
  // 粗略判断：包含非 ASCII 字符或本身就是 ASCII 表情符号
  if (value.length > 16) fail('avatarEmoji 过长');
  return value;
}

/**
 * 校验 username：3-32 位，只允许 a-zA-Z0-9_
 */
export function validateUsername(value) {
  return requireString(value, 'username', {
    min: 3,
    max: 32,
    pattern: /^[a-zA-Z0-9_]+$/,
    patternHint: '只允许字母、数字和下划线',
  });
}

/** 校验 password：6-64 位 */
export function validatePassword(value, field = 'password') {
  return requireString(value, field, { min: 6, max: 64 });
}

/**
 * 校验分页参数
 * @param {unknown} value
 * @param {number} defaultSize
 * @param {number} maxSize
 */
export function validateLimit(value, defaultSize, maxSize) {
  const n = queryInt(value, 'limit', { defaultValue: defaultSize, min: 1, max: maxSize });
  return n;
}

/** 安全的 JSON.parse，失败返回 fallback */
export function safeJsonParse(text, fallback = null) {
  if (typeof text !== 'string' || text === '') return fallback;
  try {
    return JSON.parse(text);
  } catch {
    return fallback;
  }
}

/**
 * 校验「表情包 base64 图片」的 mime 是否在白名单内
 * @param {unknown} mime
 * @param {string[]} whitelist
 */
export function validateMime(mime, whitelist) {
  if (typeof mime !== 'string') fail('mime 必须是字符串');
  if (!whitelist.includes(mime)) {
    fail(`mime 不在白名单内，仅支持：${whitelist.join('、')}`);
  }
  return mime;
}

export default {
  ValidationError,
  fail,
  isPlainObject,
  requireString,
  optionalString,
  requireTimestamp,
  requireInt,
  queryInt,
  validateUsername,
  validatePassword,
  validateNickname,
  validateAvatarEmoji,
  validateLimit,
  validateMime,
  safeJsonParse,
};
