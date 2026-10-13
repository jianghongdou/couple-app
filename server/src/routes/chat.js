// 聊天接口（API.md §5）
import express from 'express';
import { LIMITS } from '../config.js';
import {
  insertMessage,
  findMessageByClientId,
  getMessage,
  listMessages,
  markMessagesRead,
  recallMessage,
  countMessagesTodayByUser,
  touchCouple,
} from '../db.js';
import { requireAuth, requireCouple } from '../middleware/auth.js';
import {
  requireString,
  requireTimestamp,
  requireJsonObject,
  queryInt,
  fail,
} from '../util/validate.js';
import { readSettings } from '../services/settings.js';
import { broadcastToCouple } from '../ws.js';
import { log } from '../util/log.js';

const router = express.Router();

// 消息 kind 白名单（API.md §5）
const MESSAGE_KINDS = ['text', 'emoji', 'image', 'system'];
// 客户端可主动发送的 kind（system 由服务端产生）
const CLIENT_KINDS = ['text', 'emoji', 'image'];

/** 统一的成功响应 */
function ok(res, payload) {
  res.status(200).json({ ...payload, serverTime: Date.now() });
}

// GET /api/chat/messages?before=<ms>&limit=50
router.get('/messages', requireAuth, requireCouple, (req, res, next) => {
  try {
    const before = queryInt(req.query.before, 'before', { min: 0 });
    const limit = queryInt(req.query.limit, 'limit', {
      defaultValue: LIMITS.CHAT_PAGE_DEFAULT,
      min: 1,
      max: 200,
    });

    const { messages, hasMore } = listMessages({
      coupleId: req.user.coupleId,
      before,
      limit,
    });

    // 返回按 createdAt 升序（API.md §5）
    ok(res, { messages, hasMore });
  } catch (err) {
    next(err);
  }
});

// POST /api/chat/messages
router.post('/messages', requireAuth, requireCouple, (req, res, next) => {
  try {
    const body = req.body || {};

    // clientId 幂等：重复提交返回已存在的那条消息
    const clientId = requireString(body.clientId, 'clientId', { min: 1, max: 128 });
    const existing = findMessageByClientId(req.user.coupleId, clientId);
    if (existing) {
      ok(res, { message: existing });
      return;
    }

    const kind = requireString(body.kind, 'kind', { min: 1, max: 16, trim: true });
    if (!MESSAGE_KINDS.includes(kind)) {
      fail(`kind 不在允许列表中，仅支持：${MESSAGE_KINDS.join('、')}`);
    }
    if (!CLIENT_KINDS.includes(kind)) {
      fail('system 类型消息由服务端生成，客户端不能直接发送');
    }

    // body 长度上限 2000 字符（API.md §5）
    let messageBody = '';
    if (body.body !== undefined && body.body !== null) {
      if (typeof body.body !== 'string') fail('body 必须是字符串');
      messageBody = body.body;
      if ([...messageBody].length > LIMITS.CHAT_BODY_MAX) {
        fail(`body 长度不能超过 ${LIMITS.CHAT_BODY_MAX} 个字符`);
      }
    }

    // 文本消息必须有内容
    if (kind === 'text' && messageBody.trim() === '') {
      fail('text 类型消息的 body 不能为空');
    }
    // 图片/表情消息 body 必须形如 item:<表情id> 或 unicode:<emoji>
    if (kind === 'emoji' || kind === 'image') {
      if (messageBody.trim() === '') fail(`${kind} 类型消息的 body 不能为空`);
      if (!/^(item|unicode):/.test(messageBody)) {
        fail(`${kind} 类型消息的 body 必须是 "item:<表情id>" 或 "unicode:<emoji>" 格式`);
      }
    }

    const meta = requireJsonObject(body.meta, 'meta', { allowNull: true, defaultValue: null });

    // 每日消息总数限制（settings.maxMessagesPerDay）
    const maxPerDay = Number(readSettings().maxMessagesPerDay);
    if (Number.isInteger(maxPerDay) && maxPerDay > 0) {
      const used = countMessagesTodayByUser(req.user.id);
      if (used >= maxPerDay) {
        res.status(429).json({
          error: 'rate_limited',
          message: `今日消息条数已达上限（${maxPerDay} 条）`,
          serverTime: Date.now(),
        });
        return;
      }
    }

    let message;
    try {
      message = insertMessage({
        clientId,
        coupleId: req.user.coupleId,
        senderId: req.user.id,
        kind,
        body: messageBody,
        meta,
      });
    } catch (err) {
      // 并发下同一 clientId 同时插入：唯一索引冲突 -> 返回已存在的那条（仍然幂等）
      if (String(err.code || '').startsWith('SQLITE_CONSTRAINT')) {
        const found = findMessageByClientId(req.user.coupleId, clientId);
        if (found) {
          ok(res, { message: found });
          return;
        }
      }
      throw err;
    }

    touchCouple(req.user.coupleId);

    // 实时推送给伴侣（也回推给自己，保证多端一致）
    broadcastToCouple(req.user.coupleId, { type: 'message', message });

    log.info('发送消息', {
      messageId: message.id,
      coupleId: req.user.coupleId,
      senderId: req.user.id,
      kind,
    });
    ok(res, { message });
  } catch (err) {
    next(err);
  }
});

// POST /api/chat/read —— 把 createdAt <= upTo 且对方发来的消息标记为已读
router.post('/read', requireAuth, requireCouple, (req, res, next) => {
  try {
    const upTo = requireTimestamp(req.body?.upTo, 'upTo', { required: true });
    const count = markMessagesRead(req.user.coupleId, req.user.id, upTo);

    // 通知对方：我已读到哪里（API.md §8 type: "read"）
    broadcastToCouple(
      req.user.coupleId,
      { type: 'read', userId: req.user.id, upTo },
      req.user.id
    );

    ok(res, { ok: true, count });
  } catch (err) {
    next(err);
  }
});

// DELETE /api/chat/messages/:id —— 撤回自己发的消息（24 小时内）
router.delete('/messages/:id', requireAuth, requireCouple, (req, res, next) => {
  try {
    const id = requireString(req.params.id, 'id', { min: 1, max: 128 });
    const found = getMessage(id);

    if (!found || found.row.couple_id !== req.user.coupleId) {
      res.status(404).json({ error: 'not_found', message: '消息不存在', serverTime: Date.now() });
      return;
    }
    if (found.row.sender_id !== req.user.id) {
      res.status(403).json({ error: 'forbidden', message: '只能撤回自己发送的消息', serverTime: Date.now() });
      return;
    }
    if (found.row.deleted_at) {
      // 已撤回，幂等返回成功
      ok(res, { ok: true });
      return;
    }
    // 24 小时内（API.md §5）
    if (Date.now() - found.row.created_at > LIMITS.CHAT_RECALL_WINDOW_MS) {
      res.status(403).json({
        error: 'forbidden',
        message: '超过 24 小时的消息不能撤回',
        serverTime: Date.now(),
      });
      return;
    }

    recallMessage(id);

    // 通知双方刷新该消息
    broadcastToCouple(req.user.coupleId, {
      type: 'message_deleted',
      messageId: id,
      userId: req.user.id,
    });

    log.info('撤回消息', { messageId: id, userId: req.user.id });
    ok(res, { ok: true });
  } catch (err) {
    next(err);
  }
});

export default router;
