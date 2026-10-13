// 后台管理 JWT 校验中间件
//
// 与用户 token 完全隔离：
//   - 不同的 secret（ADMIN_JWT_SECRET，缺省回退 JWT_SECRET）
//   - payload 中 typ = 'admin'，用户 token 无法冒充后台
import crypto from 'node:crypto';
import jwt from 'jsonwebtoken';
import { CONFIG } from '../config.js';
import { log } from '../util/log.js';
import { addAdminLog } from '../db.js';

/** 后台 token 有效期：12 小时 */
const ADMIN_TOKEN_TTL_SECONDS = 12 * 60 * 60;

/**
 * 常量时间比较密码，避免时序侧信道。
 * 先做 SHA-256 再比较，保证长度不同也不会提前返回。
 * @param {string} a 输入值
 * @param {string} b 期望值
 * @returns {boolean}
 */
export function timingSafeCompare(a, b) {
  const ha = crypto.createHash('sha256').update(String(a ?? ''), 'utf8').digest();
  const hb = crypto.createHash('sha256').update(String(b ?? ''), 'utf8').digest();
  return crypto.timingSafeEqual(ha, hb);
}

/**
 * 校验后台密码
 * @param {unknown} password
 * @returns {boolean}
 */
export function verifyAdminPassword(password) {
  if (typeof password !== 'string' || password === '') return false;
  return timingSafeCompare(password, CONFIG.adminPassword);
}

/**
 * 签发后台 token
 * @returns {string}
 */
export function signAdminToken() {
  return jwt.sign({ typ: 'admin', role: 'admin' }, CONFIG.adminJwtSecret, {
    expiresIn: ADMIN_TOKEN_TTL_SECONDS,
  });
}

/**
 * 后台接口鉴权中间件
 */
export function requireAdmin(req, res, next) {
  const header = req.headers.authorization;
  let token = null;
  if (typeof header === 'string' && header.startsWith('Bearer ')) {
    token = header.slice(7).trim();
  }
  if (!token) {
    res.status(401).json({ error: 'unauthorized', message: '缺少后台登录凭证', serverTime: Date.now() });
    return;
  }
  try {
    const payload = jwt.verify(token, CONFIG.adminJwtSecret);
    if (!payload || payload.typ !== 'admin') throw new Error('token 类型不匹配');
    req.admin = { role: 'admin' };
    next();
  } catch (err) {
    log.debug('后台 token 校验失败', { error: err.message });
    res.status(401).json({ error: 'unauthorized', message: '后台登录已过期，请重新登录', serverTime: Date.now() });
  }
}

/**
 * 记录后台操作日志（所有写操作都必须调用）
 * @param {import('express').Request} req
 * @param {string} action
 * @param {string} [detail]
 */
export function logAdminAction(req, action, detail) {
  try {
    addAdminLog({
      action,
      detail: detail === undefined ? null : String(detail).slice(0, 500),
      ip: req.ip || null,
    });
  } catch (err) {
    log.error('写入后台操作日志失败', { error: err.message });
  }
}

export default { requireAdmin, verifyAdminPassword, signAdminToken, timingSafeCompare, logAdminAction };
