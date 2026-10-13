// id 生成：形如 u_xxx / c_xxx / m_xxx / p_xxx / e_xxx
//
// 组成：base36 毫秒时间戳(高 7 位) + 进程内自增计数(3 位) + 随机后缀(4 位)
// 特点：
//   - 字符集固定 [0-9a-z]，满足表情 id 正则 ^e_[A-Za-z0-9]+$
//   - 前缀 + 时间戳，天然近似有序，便于排查
//   - 计数器 + 随机后缀，避免同一毫秒内并发调用产生重复
import { randomInt } from 'node:crypto';

const ALPHABET = '0123456789abcdefghijklmnopqrstuvwxyz';

// 进程内自增计数，36^3 = 46656
let counter = randomInt(0, 46656);

function randomSuffix(len) {
  let out = '';
  for (let i = 0; i < len; i += 1) {
    out += ALPHABET[randomInt(0, ALPHABET.length)];
  }
  return out;
}

/**
 * 生成带前缀的 id
 * @param {string} prefix 形如 'u'、'c'、'm'、'p'、'e'
 * @returns {string} 例如 'u_m1k2j3h0a7x9'
 */
export function makeId(prefix) {
  counter = (counter + 1) % 46656;
  const ts = Date.now().toString(36);
  const seq = counter.toString(36).padStart(3, '0');
  return `${prefix}_${ts}${seq}${randomSuffix(4)}`;
}

/** 用户 id：u_ 前缀 */
export const newUserId = () => makeId('u');
/** 情侣空间 id：c_ 前缀 */
export const newCoupleId = () => makeId('c');
/** 消息 id：m_ 前缀 */
export const newMessageId = () => makeId('m');
/** 表情包 id：p_ 前缀 */
export const newPackId = () => makeId('p');
/** 表情 id：e_ 前缀（blob 文件名直接由该 id 决定） */
export const newEmojiId = () => makeId('e');
/** 同步对象 id：s_ 前缀（仅用于内部日志） */
export const newSyncId = () => makeId('s');

// 表情 id 的合法格式，用于 blob 接口防路径穿越
export const EMOJI_ID_RE = /^e_[A-Za-z0-9]+$/;

/**
 * 校验表情 id 格式（防路径穿越的第一道关卡）
 * @param {unknown} id
 * @returns {boolean}
 */
export function isValidEmojiId(id) {
  return typeof id === 'string' && id.length <= 64 && EMOJI_ID_RE.test(id);
}

/**
 * 生成 6 位邀请码（大写字母 + 数字，排除易混淆字符 0/O/1/I）
 * @returns {string}
 */
export function newInviteCode() {
  const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
  let out = '';
  for (let i = 0; i < 6; i += 1) out += chars[randomInt(0, chars.length)];
  return out;
}

/**
 * 生成随机密钥（用于文档示例）
 * @param {number} bytes
 * @returns {string}
 */
export function randomSecret(bytes = 32) {
  return randomSuffix(bytes);
}

export default { makeId, newUserId, newCoupleId, newMessageId, newPackId, newEmojiId, newInviteCode };
