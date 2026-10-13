// 表情包接口（API.md §6）
//
// 安全要点：
//   1. 图片二进制存服务端磁盘，文件名只由服务端生成的 id 决定，
//      绝不使用用户传入的路径（防路径穿越）。
//   2. blob 接口校验 id 格式 ^e_[A-Za-z0-9]+$。
//   3. 上传时校验体积 + magic bytes，不信任客户端声明的 mime。
import express from 'express';
import fs from 'node:fs';
import path from 'node:path';
import { CONFIG, LIMITS } from '../config.js';
import {
  countOwnPacks,
  createPack,
  deletePack,
  getPackRow,
  listPacksForCouple,
  getPackWithItems,
  countItemsInPack,
  insertEmojiItem,
  getEmojiItem,
  deleteEmojiItem,
  resolveEmojiFilePath,
} from '../db.js';
import { requireAuth, requireCouple, verifyUserToken } from '../middleware/auth.js';
import {
  requireString,
  validateMime,
  fail,
} from '../util/validate.js';
import { newEmojiId, isValidEmojiId } from '../util/ids.js';
import { readSettings } from '../services/settings.js';
import { log } from '../util/log.js';

const router = express.Router();

/** 统一的成功响应 */
function ok(res, payload) {
  res.status(200).json({ ...payload, serverTime: Date.now() });
}

// ---------------------------------------------------------------------------
// 图片二进制存取
// ---------------------------------------------------------------------------

/**
 * 通过 magic bytes 判断真实图片类型（不信任客户端 mime）
 * @param {Buffer} buf
 * @returns {string|null} 匹配到的 mime，无法识别返回 null
 */
export function sniffImageMime(buf) {
  if (!buf || buf.length < 12) return null;

  // JPEG: FF D8 FF
  if (buf[0] === 0xff && buf[1] === 0xd8 && buf[2] === 0xff) return 'image/jpeg';

  // PNG: 89 50 4E 47 0D 0A 1A 0A
  if (
    buf[0] === 0x89 && buf[1] === 0x50 && buf[2] === 0x4e && buf[3] === 0x47 &&
    buf[4] === 0x0d && buf[5] === 0x0a && buf[6] === 0x1a && buf[7] === 0x0a
  ) {
    return 'image/png';
  }

  // GIF: "GIF87a" / "GIF89a"
  if (buf.toString('ascii', 0, 6) === 'GIF87a' || buf.toString('ascii', 0, 6) === 'GIF89a') {
    return 'image/gif';
  }

  // WEBP: "RIFF" .... "WEBP"
  if (buf.toString('ascii', 0, 4) === 'RIFF' && buf.toString('ascii', 8, 12) === 'WEBP') {
    return 'image/webp';
  }

  return null;
}

// ---------------------------------------------------------------------------
// 表情包
// ---------------------------------------------------------------------------

// GET /api/emoji/packs —— 同一情侣空间内双方都能看到对方的包
router.get('/packs', requireAuth, requireCouple, (req, res, next) => {
  try {
    const packs = listPacksForCouple(req.user.coupleId);
    ok(res, { packs });
  } catch (err) {
    next(err);
  }
});

// POST /api/emoji/packs
router.post('/packs', requireAuth, requireCouple, (req, res, next) => {
  try {
    const name = requireString(req.body?.name, 'name', { min: 1, max: 32, trim: true });

    // 每个情侣空间最多 30 个自建包（可按后台设置调整）
    const maxPacks = Number(readSettings().maxEmojiPacks) || LIMITS.EMOJI_MAX_PACKS;
    const used = countOwnPacks(req.user.coupleId);
    if (used >= maxPacks) {
      res.status(409).json({
        error: 'conflict',
        message: `表情包数量已达上限（${maxPacks} 个）`,
        serverTime: Date.now(),
      });
      return;
    }

    const row = createPack({
      name,
      ownerId: req.user.id,
      coupleId: req.user.coupleId,
      isPublic: false,
    });

    const pack = getPackWithItems(row.id);
    log.info('创建表情包', { packId: row.id, userId: req.user.id });
    ok(res, { pack });
  } catch (err) {
    next(err);
  }
});

// DELETE /api/emoji/packs/:id —— 只能删自己创建的包（system 包不可删）
router.delete('/packs/:id', requireAuth, requireCouple, (req, res, next) => {
  try {
    const id = requireString(req.params.id, 'id', { min: 1, max: 64 });
    const pack = getPackRow(id);

    if (!pack || (!pack.is_public && pack.couple_id !== req.user.coupleId)) {
      res.status(404).json({ error: 'not_found', message: '表情包不存在', serverTime: Date.now() });
      return;
    }
    if (pack.is_system) {
      res.status(403).json({ error: 'forbidden', message: '系统表情包不可删除', serverTime: Date.now() });
      return;
    }
    if (pack.is_public) {
      res.status(403).json({ error: 'forbidden', message: '公共表情包不可删除', serverTime: Date.now() });
      return;
    }
    if (pack.owner_id !== req.user.id) {
      res.status(403).json({ error: 'forbidden', message: '只能删除自己创建的表情包', serverTime: Date.now() });
      return;
    }

    // 先取出条目 id，删库后清理磁盘文件
    const itemIds = getPackWithItems(id)?.items.map((it) => it.id) ?? [];
    deletePack(id);
    for (const itemId of itemIds) {
      // itemId 来自数据库且格式受约束，这里再校验一次以防脏数据
      if (!isValidEmojiId(itemId)) continue;
      try {
        // 使用统一的解析函数，避免公共表情误删到用户上传目录
        const filePath = resolveEmojiFilePath({ id: itemId, owner_id: pack.owner_id, pack_id: id });
        if (filePath) fs.unlinkSync(filePath);
      } catch {
        /* 文件不存在时忽略 */
      }
    }

    log.info('删除表情包', { packId: id, userId: req.user.id, items: itemIds.length });
    ok(res, { ok: true });
  } catch (err) {
    next(err);
  }
});

// ---------------------------------------------------------------------------
// 表情条目（base64 上传）
// ---------------------------------------------------------------------------

// POST /api/emoji/items
router.post('/items', requireAuth, requireCouple, (req, res, next) => {
  try {
    const body = req.body || {};
    const packId = requireString(body.packId, 'packId', { min: 1, max: 64 });
    const declaredMime = validateMime(body.mime, LIMITS.EMOJI_MIMES);
    const name = body.name === undefined || body.name === null
      ? ''
      : requireString(body.name, 'name', { min: 0, max: 32, trim: true });
    const dataBase64 = requireString(body.dataBase64, 'dataBase64', { min: 1 });

    // 归属校验：包必须属于本情侣空间
    const pack = getPackRow(packId);
    if (!pack || (!pack.is_public && pack.couple_id !== req.user.coupleId)) {
      res.status(404).json({ error: 'not_found', message: '表情包不存在', serverTime: Date.now() });
      return;
    }
    if (pack.is_public || pack.is_system) {
      res.status(403).json({ error: 'forbidden', message: '公共/系统表情包不可上传', serverTime: Date.now() });
      return;
    }

    // 每个包上限 200 张（API.md §6）
    const maxItems = LIMITS.EMOJI_MAX_ITEMS_PER_PACK;
    if (countItemsInPack(packId) >= maxItems) {
      res.status(409).json({
        error: 'conflict',
        message: `该表情包已达上限（${maxItems} 张）`,
        serverTime: Date.now(),
      });
      return;
    }

    // base64 长度预检，避免先解码出巨大缓冲
    if (dataBase64.length > LIMITS.EMOJI_MAX_BASE64_CHARS) {
      fail(`图片体积超过上限（解码后最多 ${LIMITS.EMOJI_MAX_BYTES / 1024 / 1024}MB）`);
    }

    // 去掉可能存在的 data URL 前缀
    let raw = dataBase64.trim();
    const dataUrlMatch = raw.match(/^data:[^;]+;base64,(.*)$/s);
    if (dataUrlMatch) raw = dataUrlMatch[1];
    // 去掉空白字符
    raw = raw.replace(/\s/g, '');

    if (!/^[A-Za-z0-9+/]+={0,2}$/.test(raw)) {
      fail('dataBase64 不是合法的 base64 数据');
    }

    let buf;
    try {
      buf = Buffer.from(raw, 'base64');
    } catch {
      fail('dataBase64 解码失败');
    }

    // 解码后单张上限 1MB（API.md §6）
    if (buf.length === 0) fail('图片内容为空');
    if (buf.length > LIMITS.EMOJI_MAX_BYTES) {
      fail(`图片体积超过上限（解码后最多 ${LIMITS.EMOJI_MAX_BYTES / 1024 / 1024}MB，当前 ${(buf.length / 1024 / 1024).toFixed(2)}MB）`);
    }

    // magic bytes 校验：不信任客户端声明的 mime
    const sniffed = sniffImageMime(buf);
    if (!sniffed) {
      fail('文件内容不是受支持的图片格式（仅支持 JPEG / PNG / GIF / WEBP）');
    }
    if (sniffed !== declaredMime) {
      fail(`声明的 mime（${declaredMime}）与图片实际格式（${sniffed}）不一致`);
    }

    // 先落盘，再写数据库；写库失败则回滚文件
    // 文件名完全由服务端生成的 id 决定（<DATA_DIR>/emoji/<id>.bin），不含用户输入
    const itemId = newEmojiId();
    const filePath = path.join(CONFIG.emojiDir, `${itemId}.bin`);
    fs.writeFileSync(filePath, buf);

    let row;
    try {
      row = insertEmojiItem({
        id: itemId,
        packId,
        ownerId: req.user.id,
        coupleId: req.user.coupleId,
        mime: sniffed,
        size: buf.length,
        name,
      });
    } catch (err) {
      try {
        fs.unlinkSync(filePath);
      } catch {
        /* 忽略 */
      }
      throw err;
    }

    log.info('上传表情', { itemId, packId, size: buf.length, mime: sniffed, userId: req.user.id });

    const item = getEmojiItem(itemId).item;
    ok(res, { item });
  } catch (err) {
    next(err);
  }
});

// DELETE /api/emoji/items/:id —— 删除单张表情（只能删自己上传的）
//
// 说明：该接口不在 docs/API.md §6 的文档列表中（文档只定义了
// GET /packs、POST /packs、DELETE /packs/:id、POST /items、GET /blob/:id），
// 但 iOS 客户端「我的表情包 → 长按图片删除」需要它，故按客户端需求补充实现。
router.delete('/items/:id', requireAuth, (req, res, next) => {
  try {
    const id = String(req.params.id ?? '');

    // 防路径穿越：严格校验 id 格式，绝不把用户输入拼进文件路径
    if (!isValidEmojiId(id)) {
      res.status(400).json({
        error: 'invalid_input',
        message: '表情 id 格式不正确（应形如 e_xxxxx）',
        serverTime: Date.now(),
      });
      return;
    }

    const found = getEmojiItem(id);
    if (!found) {
      res.status(404).json({ error: 'not_found', message: '表情不存在', serverTime: Date.now() });
      return;
    }
    const { row } = found;

    // 公共表情包里的表情不允许删除
    const pack = getPackRow(row.pack_id);
    if (pack && pack.is_public) {
      res.status(403).json({
        error: 'forbidden',
        message: '公共表情包中的表情不可删除',
        serverTime: Date.now(),
      });
      return;
    }

    // 只能删除本人上传的表情
    if (row.owner_id !== req.user.id) {
      res.status(403).json({
        error: 'forbidden',
        message: '只能删除自己上传的表情',
        serverTime: Date.now(),
      });
      return;
    }

    // 先解析磁盘路径（必须在删库之前，之后就拿不到行了）
    const filePath = resolveEmojiFilePath(row);

    // 删除数据库记录
    deleteEmojiItem(id);

    // 删除磁盘文件；文件不存在不报错
    if (filePath) {
      try {
        fs.unlinkSync(filePath);
      } catch (err) {
        // ENOENT（文件本就不存在）属正常情况，忽略；其它错误仅记录
        if (err.code !== 'ENOENT') {
          log.warn('删除表情文件失败', { itemId: id, error: err.message });
        }
      }
    }

    log.info('删除表情', { itemId: id, packId: row.pack_id, userId: req.user.id });
    ok(res, { ok: true });
  } catch (err) {
    next(err);
  }
});

// ---------------------------------------------------------------------------
// 图片二进制
// ---------------------------------------------------------------------------

/**
 * blob 接口鉴权：Authorization 头 或 ?token=（API.md §6，供 iOS AsyncImage 使用）
 * 这里不用 requireAuth，因为需要自定义 401 响应。
 */
function authForBlob(req, res, next) {
  let token = null;
  const header = req.headers.authorization;
  if (typeof header === 'string' && header.startsWith('Bearer ')) {
    token = header.slice(7).trim();
  }
  if (!token && typeof req.query.token === 'string' && req.query.token) {
    token = req.query.token;
  }
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
  next();
}

// GET /api/emoji/blob/:id
router.get('/blob/:id', authForBlob, (req, res, next) => {
  try {
    const id = String(req.params.id ?? '');

    // 防路径穿越第一道关卡：严格校验 id 格式（^e_[A-Za-z0-9]+$）
    if (!isValidEmojiId(id)) {
      res.status(400).json({ error: 'invalid_input', message: '表情 id 格式不正确', serverTime: Date.now() });
      return;
    }

    const found = getEmojiItem(id);
    if (!found) {
      res.status(404).json({ error: 'not_found', message: '表情不存在', serverTime: Date.now() });
      return;
    }

    const { row, item } = found;

    // 只能访问同情侣空间或公共表情（API.md §6）
    // 公共内置表情（is_public 包内条目）对所有已登录用户开放
    const pack = getPackRow(row.pack_id);
    const isPublic = !!pack && pack.is_public === 1;
    if (!isPublic && (!row.couple_id || row.couple_id !== req.user.coupleId)) {
      res.status(403).json({ error: 'forbidden', message: '无权访问该表情', serverTime: Date.now() });
      return;
    }

    // 文件路径完全由服务端解析：用户上传 -> DATA_DIR/emoji/<id>.bin，
    // 公共内置 -> public/emoji/<包名>/<文件名>。绝不拼接客户端传入的路径。
    const resolved = resolveEmojiFilePath(row);
    if (!resolved) {
      log.error('表情文件路径解析失败，已拒绝', { id });
      res.status(400).json({ error: 'invalid_input', message: '表情 id 格式不正确', serverTime: Date.now() });
      return;
    }

    if (!fs.existsSync(resolved)) {
      res.status(404).json({ error: 'not_found', message: '表情文件不存在', serverTime: Date.now() });
      return;
    }

    // Content-Type 用数据库记录的 mime（上传时已按 magic bytes 校验）
    res.setHeader('Content-Type', item.mime);
    res.setHeader('Content-Length', String(item.size));
    // API.md §6 要求的长缓存
    res.setHeader('Cache-Control', 'public, max-age=31536000, immutable');
    res.setHeader('X-Content-Type-Options', 'nosniff');

    const stream = fs.createReadStream(resolved);
    stream.on('error', (err) => {
      log.error('读取表情文件失败', { id, error: err.message });
      if (!res.headersSent) {
        res.status(500).json({ error: 'server_error', message: '读取图片失败', serverTime: Date.now() });
      } else {
        res.end();
      }
    });
    stream.pipe(res);
  } catch (err) {
    next(err);
  }
});

export default router;
