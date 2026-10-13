// 情侣关系接口（API.md §3）
import express from 'express';
import { LIMITS } from '../config.js';
import {
  buildCouple,
  createCouple,
  getCoupleByInviteCode,
  getCoupleMemberCount,
  setUserCouple,
  updateCouple,
  dissolveCouple,
  insertMessage,
  getUserById,
} from '../db.js';
import { requireAuth, requireCouple } from '../middleware/auth.js';
import { requireString, requireTimestamp, optionalString, fail } from '../util/validate.js';
import { broadcastToCouple, refreshUserCouple } from '../ws.js';
import { log } from '../util/log.js';

const router = express.Router();

/** 统一的成功响应 */
function ok(res, payload) {
  res.status(200).json({ ...payload, serverTime: Date.now() });
}

/**
 * 写入一条系统消息（API.md §5 kind: system），并实时推送。
 * 例如「你们已绑定」。
 * @param {string} coupleId
 * @param {string} senderId
 * @param {string} text
 */
function systemMessage(coupleId, senderId, text) {
  try {
    const message = insertMessage({
      clientId: null,
      coupleId,
      senderId,
      kind: 'system',
      body: text,
      meta: null,
    });
    broadcastToCouple(coupleId, { type: 'message', message });
    return message;
  } catch (err) {
    // 系统消息失败不应影响主流程
    log.warn('写入系统消息失败', { error: err.message });
    return null;
  }
}

// POST /api/couple/create —— 未绑定用户创建情侣空间
router.post('/create', requireAuth, (req, res, next) => {
  try {
    const fresh = getUserById(req.user.id);
    if (!fresh) {
      res.status(404).json({ error: 'not_found', message: '用户不存在', serverTime: Date.now() });
      return;
    }
    // 已有伴侣 -> 409 conflict
    if (fresh.user.coupleId) {
      res.status(409).json({ error: 'conflict', message: '你已经有情侣空间了', serverTime: Date.now() });
      return;
    }

    const created = createCouple({ anniversaryTitle: '在一起' });
    setUserCouple(req.user.id, created.id);
    // 已建立的 WS 连接需要同步新的 coupleId，否则后续广播收不到
    refreshUserCouple(req.user.id, created.id);

    systemMessage(created.id, req.user.id, '情侣空间已创建，把邀请码发给 TA 吧');

    const couple = buildCouple(created.id);
    log.info('创建情侣空间', { coupleId: created.id, userId: req.user.id });
    ok(res, { couple });
  } catch (err) {
    next(err);
  }
});

// POST /api/couple/join —— 用邀请码加入
router.post('/join', requireAuth, (req, res, next) => {
  try {
    const fresh = getUserById(req.user.id);
    if (!fresh) {
      res.status(404).json({ error: 'not_found', message: '用户不存在', serverTime: Date.now() });
      return;
    }
    if (fresh.user.coupleId) {
      res.status(409).json({ error: 'conflict', message: '你已经有情侣空间了', serverTime: Date.now() });
      return;
    }

    const code = requireString(req.body?.inviteCode, 'inviteCode', {
      min: 4,
      max: 16,
      trim: true,
    }).toUpperCase();

    // 邀请码无效 -> 404 not_found
    const couple = getCoupleByInviteCode(code);
    if (!couple) {
      res.status(404).json({ error: 'not_found', message: '邀请码无效', serverTime: Date.now() });
      return;
    }

    // 该空间已满（2 人）-> 409 conflict
    if (getCoupleMemberCount(couple.id) >= LIMITS.COUPLE_MAX_MEMBERS) {
      res.status(409).json({ error: 'conflict', message: '该情侣空间已满', serverTime: Date.now() });
      return;
    }

    setUserCouple(req.user.id, couple.id);
    // 已建立的 WS 连接需要同步新的 coupleId
    refreshUserCouple(req.user.id, couple.id);
    log.info('加入情侣空间', { coupleId: couple.id, userId: req.user.id });

    // 系统提示（API.md §5 示例：你们已绑定），并实时通知双方
    const nickname = fresh.user.nickname || fresh.user.username;
    systemMessage(couple.id, req.user.id, `${nickname} 加入了情侣空间，你们已绑定`);

    const full = buildCouple(couple.id);
    broadcastToCouple(couple.id, { type: 'couple', couple: full });
    ok(res, { couple: full });
  } catch (err) {
    next(err);
  }
});

// GET /api/couple —— members 含双方完整 user 对象
router.get('/', requireAuth, requireCouple, (req, res, next) => {
  try {
    const couple = buildCouple(req.user.coupleId);
    if (!couple) {
      res.status(404).json({ error: 'not_found', message: '情侣空间不存在', serverTime: Date.now() });
      return;
    }
    ok(res, { couple });
  } catch (err) {
    next(err);
  }
});

// PATCH /api/couple —— togetherDate / anniversaryTitle
router.patch('/', requireAuth, requireCouple, (req, res, next) => {
  try {
    const body = req.body || {};
    const patch = {};

    if (body.togetherDate !== undefined) {
      // 允许显式传 null 表示清空
      if (body.togetherDate === null) {
        patch.togetherDate = null;
      } else {
        patch.togetherDate = requireTimestamp(body.togetherDate, 'togetherDate', { required: true });
      }
    }
    if (body.anniversaryTitle !== undefined) {
      patch.anniversaryTitle = optionalString(body.anniversaryTitle, 'anniversaryTitle', {
        min: 1,
        max: 32,
        trim: true,
      });
    }

    if (Object.keys(patch).length === 0) {
      fail('请至少提供 togetherDate 或 anniversaryTitle');
    }

    const couple = updateCouple(req.user.coupleId, patch);
    if (!couple) {
      res.status(404).json({ error: 'not_found', message: '情侣空间不存在', serverTime: Date.now() });
      return;
    }
    broadcastToCouple(req.user.coupleId, { type: 'couple', couple });
    ok(res, { couple });
  } catch (err) {
    next(err);
  }
});

// POST /api/couple/leave —— 解除绑定，双方 coupleId 置空（数据保留 30 天）
router.post('/leave', requireAuth, requireCouple, (req, res, next) => {
  try {
    const coupleId = req.user.coupleId;
    const affected = dissolveCouple(coupleId);
    log.info('解除情侣绑定', { coupleId, userId: req.user.id, affected });

    // 通知双方（广播按旧 coupleId 查找连接，此时用户已不在该空间）
    broadcastToCouple(coupleId, { type: 'couple', couple: null });
    ok(res, { ok: true });
  } catch (err) {
    next(err);
  }
});

export default router;
