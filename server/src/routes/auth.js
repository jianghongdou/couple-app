// 登录与账号相关接口（API.md §2）
import express from 'express';
import bcrypt from 'bcryptjs';
import { LIMITS } from '../config.js';
import {
  createUser,
  findUserByUsername,
  getUserById,
  updateUserProfile,
  updateUserPassword,
  setUserCouple,
  buildCouple,
  getCoupleByInviteCode,
  getCoupleMemberCount,
  touchUserLastSeen,
} from '../db.js';
import { signUserToken, requireAuth } from '../middleware/auth.js';
import {
  validateUsername,
  validatePassword,
  validateNickname,
  validateAvatarEmoji,
  requireString,
  fail,
} from '../util/validate.js';
import { rateLimit } from '../util/ratelimit.js';
import { isRegisterOpen } from '../services/settings.js';
import { log } from '../util/log.js';

const router = express.Router();

// bcrypt 轮数（技术要求：10 轮）
const BCRYPT_ROUNDS = 10;

/** 统一的成功响应（所有接口都带 serverTime） */
function ok(res, payload) {
  res.status(200).json({ ...payload, serverTime: Date.now() });
}

/**
 * 组装登录/注册响应：{ token, user, couple }
 * @param {object} userRow
 */
function buildAuthResponse(userRow) {
  const fresh = getUserById(userRow.id);
  const user = fresh.user;
  const couple = user.coupleId ? buildCouple(user.coupleId) : null;
  return {
    token: signUserToken(fresh.row),
    user,
    couple,
  };
}

// 登录/注册限流：同一 IP 15 分钟内最多 30 次
const authLimiter = rateLimit({
  limit: 30,
  windowMs: 15 * 60 * 1000,
  keyGenerator: (req) => `auth:${req.ip}`,
  message: '登录尝试过于频繁，请稍后再试',
});

// POST /api/auth/register
router.post('/register', authLimiter, async (req, res, next) => {
  try {
    const body = req.body || {};

    // 注册开关（API.md §2：registerOpen=false 时返回 403 forbidden）
    if (!isRegisterOpen()) {
      res.status(403).json({ error: 'forbidden', message: '当前已关闭注册', serverTime: Date.now() });
      return;
    }

    const username = validateUsername(body.username);
    const password = validatePassword(body.password);
    const nickname = body.nickname === undefined || body.nickname === null || body.nickname === ''
      ? username
      : validateNickname(body.nickname);
    const avatarEmoji = validateAvatarEmoji(body.avatarEmoji) ?? '';

    // 用户名唯一性
    if (findUserByUsername(username)) {
      res.status(409).json({ error: 'conflict', message: '该用户名已被注册', serverTime: Date.now() });
      return;
    }

    // 可选邀请码：填了就直接加入对方情侣关系
    let inviteTarget = null;
    if (body.inviteCode !== undefined && body.inviteCode !== null && body.inviteCode !== '') {
      const code = requireString(body.inviteCode, 'inviteCode', { min: 4, max: 16, trim: true }).toUpperCase();
      inviteTarget = getCoupleByInviteCode(code);
      if (!inviteTarget) {
        res.status(404).json({ error: 'not_found', message: '邀请码无效', serverTime: Date.now() });
        return;
      }
      if (inviteTarget.members.length >= LIMITS.COUPLE_MAX_MEMBERS) {
        res.status(409).json({ error: 'conflict', message: '该情侣空间已满', serverTime: Date.now() });
        return;
      }
    }

    const passwordHash = await bcrypt.hash(password, BCRYPT_ROUNDS);
    const created = createUser({ username, passwordHash, nickname, avatarEmoji });

    // 加入情侣空间
    if (inviteTarget) {
      setUserCouple(created.user.id, inviteTarget.id);
    }

    touchUserLastSeen(created.user.id);
    const payload = buildAuthResponse(created.row);
    log.info('用户注册成功', { userId: created.user.id, joined: !!inviteTarget });
    ok(res, payload);
  } catch (err) {
    next(err);
  }
});

// POST /api/auth/login
router.post('/login', authLimiter, async (req, res, next) => {
  try {
    const body = req.body || {};
    const username = requireString(body.username, 'username', { min: 1, max: 32, trim: true });
    const password = requireString(body.password, 'password', { min: 1, max: 64 });

    const found = findUserByUsername(username);
    if (!found) {
      // 统一提示，避免用户名枚举
      res.status(401).json({ error: 'unauthorized', message: '用户名或密码错误', serverTime: Date.now() });
      return;
    }

    const matched = await bcrypt.compare(password, found.row.password_hash);
    if (!matched) {
      res.status(401).json({ error: 'unauthorized', message: '用户名或密码错误', serverTime: Date.now() });
      return;
    }

    // 禁用账号：403 forbidden（API.md §2）
    if (found.row.disabled) {
      res.status(403).json({ error: 'forbidden', message: '账号已被禁用，请联系管理员', serverTime: Date.now() });
      return;
    }

    touchUserLastSeen(found.user.id);
    ok(res, buildAuthResponse(found.row));
  } catch (err) {
    next(err);
  }
});

// GET /api/auth/me
router.get('/me', requireAuth, (req, res, next) => {
  try {
    const fresh = getUserById(req.user.id);
    if (!fresh) {
      res.status(404).json({ error: 'not_found', message: '用户不存在', serverTime: Date.now() });
      return;
    }
    const couple = fresh.user.coupleId ? buildCouple(fresh.user.coupleId) : null;
    ok(res, { user: fresh.user, couple });
  } catch (err) {
    next(err);
  }
});

// PATCH /api/auth/me
router.patch('/me', requireAuth, (req, res, next) => {
  try {
    const body = req.body || {};
    if (body.nickname === undefined && body.avatarEmoji === undefined) {
      fail('请至少提供 nickname 或 avatarEmoji');
    }
    const patch = {};
    if (body.nickname !== undefined) patch.nickname = validateNickname(body.nickname);
    if (body.avatarEmoji !== undefined) patch.avatarEmoji = validateAvatarEmoji(body.avatarEmoji) ?? '';

    const updated = updateUserProfile(req.user.id, patch);
    if (!updated) {
      res.status(404).json({ error: 'not_found', message: '用户不存在', serverTime: Date.now() });
      return;
    }
    // API.md §2：响应 { "user": {...} }
    res.status(200).json({ user: updated.user, serverTime: Date.now() });
  } catch (err) {
    next(err);
  }
});

// POST /api/auth/password
router.post('/password', requireAuth, async (req, res, next) => {
  try {
    const body = req.body || {};
    const oldPassword = requireString(body.oldPassword, 'oldPassword', { min: 1, max: 64 });
    const newPassword = validatePassword(body.newPassword, 'newPassword');

    const fresh = getUserById(req.user.id);
    if (!fresh) {
      res.status(404).json({ error: 'not_found', message: '用户不存在', serverTime: Date.now() });
      return;
    }

    const matched = await bcrypt.compare(oldPassword, fresh.row.password_hash);
    if (!matched) {
      res.status(401).json({ error: 'unauthorized', message: '原密码错误', serverTime: Date.now() });
      return;
    }

    const hash = await bcrypt.hash(newPassword, BCRYPT_ROUNDS);
    updateUserPassword(req.user.id, hash);
    log.info('用户修改密码', { userId: req.user.id });
    ok(res, { ok: true });
  } catch (err) {
    next(err);
  }
});

export default router;
