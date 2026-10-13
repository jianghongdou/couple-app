// 通用数据同步接口（API.md §4）
//
// 重要：记账（ledger）与密码本（vault）不参与同步，只存手机本地。
// 服务端明确拒绝这两个 kind，并返回可读的中文原因。
import express from 'express';
import { LIMITS, SYNC_KINDS, SYNC_REJECTED_KINDS } from '../config.js';
import { pushSyncObjects, pullSyncObjects, getCoupleMemberCount } from '../db.js';
import { requireAuth, requireCouple } from '../middleware/auth.js';
import {
  isPlainObject,
  requireString,
  requireTimestamp,
  requireJsonObject,
  queryInt,
  optionalBool,
  fail,
} from '../util/validate.js';
import { broadcastToCouple } from '../ws.js';
import { log } from '../util/log.js';

const router = express.Router();

/** 统一的成功响应 */
function ok(res, payload) {
  res.status(200).json({ ...payload, serverTime: Date.now() });
}

/**
 * 校验单个同步对象
 * @param {unknown} raw
 * @param {number} index
 * @returns {{kind:string,id:string,updatedAt:number,deleted:boolean,payload:object|null}}
 */
function validateSyncObject(raw, index) {
  const at = `objects[${index}]`;
  if (!isPlainObject(raw)) fail(`${at} 必须是一个 JSON 对象`);

  // kind 必须在白名单内
  const kind = requireString(raw.kind, `${at}.kind`, { min: 1, max: 32, trim: true });
  if (!SYNC_KINDS.includes(kind)) {
    if (SYNC_REJECTED_KINDS.includes(kind)) {
      // 这两类数据只存手机本地，服务端不接收（API.md §4）
      fail(
        `kind "${kind}" 不参与服务端同步：记账数据与密码本只保存在手机本地，服务端不接收、不存储。`,
        'invalid_input',
        400
      );
    }
    fail(`${at}.kind 不在允许列表中，仅支持：${SYNC_KINDS.join('、')}`);
  }

  const id = requireString(raw.id, `${at}.id`, { min: 1, max: 128 });
  const updatedAt = requireTimestamp(raw.updatedAt, `${at}.updatedAt`, { required: true });
  const deleted = optionalBool(raw.deleted, `${at}.deleted`, false);
  const payload = requireJsonObject(raw.payload, `${at}.payload`, { allowNull: true, defaultValue: null });

  return { kind, id, updatedAt, deleted, payload };
}

// POST /api/sync/push
router.post('/push', requireAuth, requireCouple, (req, res, next) => {
  try {
    const body = req.body || {};
    const objects = body.objects;

    if (!Array.isArray(objects)) fail('objects 必须是数组');
    if (objects.length === 0) fail('objects 不能为空数组');
    // 单次最多 200 个对象（API.md §4）
    if (objects.length > LIMITS.SYNC_MAX_OBJECTS) {
      fail(`单次最多推送 ${LIMITS.SYNC_MAX_OBJECTS} 个对象，当前 ${objects.length} 个`);
    }

    const validated = objects.map((o, i) => validateSyncObject(o, i));

    const { applied, conflicts } = pushSyncObjects({
      coupleId: req.user.coupleId,
      userId: req.user.id,
      objects: validated,
    });

    // 通过 WS 给伴侣广播 {type:"sync"}（API.md §8）
    if (applied.length > 0) {
      const kinds = [...new Set(validated.filter((v) => applied.includes(v.id)).map((v) => v.kind))];
      broadcastToCouple(
        req.user.coupleId,
        { type: 'sync', kinds, fromUserId: req.user.id },
        req.user.id
      );
    }

    log.info('同步推送完成', {
      userId: req.user.id,
      coupleId: req.user.coupleId,
      applied: applied.length,
      conflicts: conflicts.length,
    });

    ok(res, { applied, conflicts });
  } catch (err) {
    next(err);
  }
});

// GET /api/sync/pull?since=<ms>&kinds=spark,pet&limit=500
router.get('/pull', requireAuth, requireCouple, (req, res, next) => {
  try {
    const since = queryInt(req.query.since, 'since', { defaultValue: 0, min: 0 });

    // kinds 可选，逗号分隔，缺省为全部白名单
    let kinds = SYNC_KINDS;
    const rawKinds = req.query.kinds;
    if (typeof rawKinds === 'string' && rawKinds.trim() !== '') {
      const requested = rawKinds
        .split(',')
        .map((s) => s.trim())
        .filter((s) => s !== '');
      if (requested.length === 0) {
        fail('kinds 参数不能为空');
      }
      // 过滤掉被拒绝的 kind，并给出明确说明
      const rejected = requested.filter((k) => SYNC_REJECTED_KINDS.includes(k));
      if (rejected.length > 0) {
        fail(
          `kind "${rejected.join('、')}" 不参与服务端同步：记账数据与密码本只保存在手机本地，服务端不接收。`
        );
      }
      const invalid = requested.filter((k) => !SYNC_KINDS.includes(k));
      if (invalid.length > 0) {
        fail(`kinds 包含不支持的取值：${invalid.join('、')}，仅支持：${SYNC_KINDS.join('、')}`);
      }
      kinds = [...new Set(requested)];
    }

    // limit 默认 500，上限 1000（API.md §4）
    const limit = queryInt(req.query.limit, 'limit', {
      defaultValue: LIMITS.SYNC_PULL_DEFAULT,
      min: 1,
      max: LIMITS.SYNC_PULL_MAX,
    });

    const { objects: rows, hasMore } = pullSyncObjects({
      coupleId: req.user.coupleId,
      since,
      kinds,
      limit,
    });

    // 客户端应使用服务端返回的 serverTime 作为下次 since（API.md §4）
    ok(res, { objects: rows, hasMore });
  } catch (err) {
    next(err);
  }
});

export default router;
