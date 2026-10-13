// 用户 JWT 校验中间件
import jwt from 'jsonwebtoken';
import { CONFIG } from '../config.js';
import { getUserById } from '../db.js';
import { log } from '../util/log.js';

/**
 * 生成用户 token（有效期 30 天，API.md 技术要求）
 * @param {{id:string, username:string, role:string}} user
 * @returns {string}
 */
export function signUserToken(user) {
  return jwt.sign(
    { sub: user.id, username: user.username, role: user.role || 'user', typ: 'user' },
    CONFIG.jwtSecret,
    { expiresIn: CONFIG.jwtExpiresInSeconds }
  );
}

/**
 * 从请求中提取 JWT：优先 Authorization 头，其次 query.token
 * （query 方式供 iOS AsyncImage 加载表情图片使用，API.md §6）
 * @param {import('express').Request} req
 * @returns {string|null}
 */
export function extractToken(req) {
  const header = req.headers.authorization;
  if (typeof header === 'string' && header.startsWith('Bearer ')) {
    const t = header.slice(7).trim();
    if (t) return t;
  }
  const q = req.query?.token;
  if (typeof q === 'string' && q) return q;
  return null;
}

/**
 * 校验 token 并返回用户记录（含 password_hash 的原始行）
 * @param {string} token
 * @returns {{row:object, user:object}|null}
 */
export function verifyUserToken(token) {
  try {
    const payload = jwt.verify(token, CONFIG.jwtSecret);
    if (!payload || payload.typ !== 'user' || !payload.sub) return null;
    const found = getUserById(payload.sub);
    if (!found) return null;
    return found;
  } catch (err) {
    log.debug('用户 token 校验失败', { error: err.message });
    return null;
  }
}

/**
 * 必须登录的中间件：成功时挂载 req.user / req.userRow
 */
export function requireAuth(req, res, next) {
  const token = extractToken(req);
  if (!token) {
    res.status(401).json({ error: 'unauthorized', message: '缺少登录凭证', serverTime: Date.now() });
    return;
  }
  const found = verifyUserToken(token);
  if (!found) {
    res.status(401).json({ error: 'unauthorized', message: '登录已过期，请重新登录', serverTime: Date.now() });
    return;
  }
  if (found.row.disabled) {
    res.status(403).json({ error: 'forbidden', message: '账号已被禁用', serverTime: Date.now() });
    return;
  }
  req.userRow = found.row;
  req.user = found.user;
  req.token = token;
  next();
}

/**
 * 可选登录：有 token 就解析，无 token 也放行
 */
export function optionalAuth(req, res, next) {
  const token = extractToken(req);
  if (token) {
    const found = verifyUserToken(token);
    if (found && !found.row.disabled) {
      req.userRow = found.row;
      req.user = found.user;
      req.token = token;
    }
  }
  next();
}

/**
 * 要求已绑定情侣关系（未绑定 -> 403 forbidden）
 * 用于聊天与表情接口（API.md §5、§6）
 */
export function requireCouple(req, res, next) {
  if (!req.user) {
    res.status(401).json({ error: 'unauthorized', message: '请先登录', serverTime: Date.now() });
    return;
  }
  if (!req.user.coupleId) {
    res.status(403).json({ error: 'forbidden', message: '尚未绑定情侣关系', serverTime: Date.now() });
    return;
  }
  next();
}

export default { signUserToken, requireAuth, optionalAuth, requireCouple, verifyUserToken, extractToken };
