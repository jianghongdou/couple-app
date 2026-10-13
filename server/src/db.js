// 数据库层：唯一接触 SQL 的文件。
// 建表 / 迁移 / 所有查询封装都在这里。
//
// 全部使用 better-sqlite3 的参数绑定（prepare + run/get/all 的绑定参数），
// 严禁字符串拼接 SQL 值。
import fs from 'node:fs';
import path from 'node:path';
import Database from 'better-sqlite3';
import { CONFIG, DATA_DIR, DB_FILE, DEFAULTS, LIMITS, PUBLIC_EMOJI_DIR } from './config.js';
import { log } from './util/log.js';
import {
  newUserId,
  newCoupleId,
  newMessageId,
  newPackId,
  newEmojiId,
  newInviteCode,
  isValidEmojiId,
} from './util/ids.js';

/** @type {import('better-sqlite3').Database | null} */
let db = null;

// ---------------------------------------------------------------------------
// 建表与迁移
// ---------------------------------------------------------------------------

// API.md §10 末尾列表：users、couples、messages、emoji_packs、emoji_items、
// sync_objects、settings、ai_usage、admin_logs
const SCHEMA_SQL = `
-- 用户表
CREATE TABLE IF NOT EXISTS users (
  id             TEXT PRIMARY KEY,
  username       TEXT NOT NULL UNIQUE,
  password_hash  TEXT NOT NULL,
  nickname       TEXT NOT NULL DEFAULT '',
  avatar_emoji   TEXT NOT NULL DEFAULT '',
  couple_id      TEXT,
  role           TEXT NOT NULL DEFAULT 'user',
  disabled       INTEGER NOT NULL DEFAULT 0,
  created_at     INTEGER NOT NULL,
  updated_at     INTEGER NOT NULL,
  last_seen_at   INTEGER
);
CREATE INDEX IF NOT EXISTS idx_users_couple ON users(couple_id);

-- 情侣空间表
CREATE TABLE IF NOT EXISTS couples (
  id                TEXT PRIMARY KEY,
  invite_code       TEXT NOT NULL UNIQUE,
  together_date     INTEGER,
  anniversary_title TEXT NOT NULL DEFAULT '在一起',
  created_at        INTEGER NOT NULL,
  updated_at        INTEGER NOT NULL,
  -- 解绑时间：非空表示已解绑，数据保留 30 天（API.md §3）
  dissolved_at      INTEGER
);
CREATE INDEX IF NOT EXISTS idx_couples_active ON couples(dissolved_at);

-- 消息表
CREATE TABLE IF NOT EXISTS messages (
  id          TEXT PRIMARY KEY,
  client_id   TEXT,
  couple_id   TEXT NOT NULL,
  sender_id   TEXT NOT NULL,
  kind        TEXT NOT NULL,
  body        TEXT NOT NULL DEFAULT '',
  meta        TEXT,
  created_at  INTEGER NOT NULL,
  read_at     INTEGER,
  deleted_at  INTEGER
);
-- clientId 幂等：同一情侣空间内 client_id 唯一
CREATE UNIQUE INDEX IF NOT EXISTS idx_messages_client
  ON messages(couple_id, client_id) WHERE client_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_messages_couple_time ON messages(couple_id, created_at);

-- 表情包
CREATE TABLE IF NOT EXISTS emoji_packs (
  id             TEXT PRIMARY KEY,
  name           TEXT NOT NULL,
  owner_id       TEXT NOT NULL,
  couple_id      TEXT NOT NULL,
  is_public      INTEGER NOT NULL DEFAULT 0,
  is_system      INTEGER NOT NULL DEFAULT 0,
  created_at     INTEGER NOT NULL,
  updated_at     INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_packs_couple ON emoji_packs(couple_id);
CREATE INDEX IF NOT EXISTS idx_packs_owner ON emoji_packs(owner_id);

-- 表情条目（图片二进制存磁盘，元数据存这里）
CREATE TABLE IF NOT EXISTS emoji_items (
  id          TEXT PRIMARY KEY,
  pack_id     TEXT NOT NULL,
  owner_id    TEXT NOT NULL,
  couple_id   TEXT NOT NULL,
  mime        TEXT NOT NULL,
  size        INTEGER NOT NULL,
  name        TEXT NOT NULL DEFAULT '',
  created_at  INTEGER NOT NULL,
  updated_at  INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_items_pack ON emoji_items(pack_id);

-- 通用同步对象（火花/宠物/签到/愿望/加班记录/设置）
CREATE TABLE IF NOT EXISTS sync_objects (
  couple_id   TEXT NOT NULL,
  kind        TEXT NOT NULL,
  id          TEXT NOT NULL,
  owner_id    TEXT NOT NULL,
  updated_by  TEXT NOT NULL,
  updated_at  INTEGER NOT NULL,
  deleted     INTEGER NOT NULL DEFAULT 0,
  payload     TEXT,
  created_at  INTEGER NOT NULL,
  PRIMARY KEY (couple_id, kind, id)
);
CREATE INDEX IF NOT EXISTS idx_sync_pull ON sync_objects(couple_id, updated_at);

-- 服务端设置（键值对）
CREATE TABLE IF NOT EXISTS settings (
  key         TEXT PRIMARY KEY,
  value       TEXT,
  updated_at  INTEGER NOT NULL
);

-- AI 调用计数
CREATE TABLE IF NOT EXISTS ai_usage (
  id          TEXT PRIMARY KEY,
  user_id     TEXT,
  endpoint    TEXT NOT NULL,
  model       TEXT,
  ok          INTEGER NOT NULL DEFAULT 1,
  prompt_len  INTEGER NOT NULL DEFAULT 0,
  created_at  INTEGER NOT NULL,
  day         TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_ai_usage_day ON ai_usage(day, user_id);

-- 后台操作日志
CREATE TABLE IF NOT EXISTS admin_logs (
  id          TEXT PRIMARY KEY,
  action      TEXT NOT NULL,
  detail      TEXT,
  ip          TEXT,
  created_at  INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_admin_logs_time ON admin_logs(created_at);
`;

/**
 * 初始化数据库连接、建表、写入默认设置。
 * 可重复调用（幂等）。
 * @returns {import('better-sqlite3').Database}
 */
export function initDb() {
  if (db) return db;

  fs.mkdirSync(DATA_DIR, { recursive: true });

  db = new Database(DB_FILE);
  // 开启 WAL（读写并发更好）；busy_timeout 避免瞬时锁冲突
  db.pragma('journal_mode = WAL');
  db.pragma('synchronous = NORMAL');
  db.pragma('foreign_keys = ON');
  db.pragma('busy_timeout = 5000');

  db.exec(SCHEMA_SQL);
  migrate();
  seedDefaultSettings();

  db.__initializedAt = Date.now();
  log.info('数据库已就绪', { file: DB_FILE, journalMode: db.pragma('journal_mode', { simple: true }) });
  return db;
}

/**
 * 轻量迁移：为旧库补齐后加的列。SQLite 不支持 ADD COLUMN IF NOT EXISTS，
 * 因此先查 PRAGMA table_info 再决定是否新增。
 */
function migrate() {
  const ensureColumn = (table, column, definition) => {
    const cols = db.prepare(`PRAGMA table_info(${table})`).all();
    if (!cols.some((c) => c.name === column)) {
      // 表名/列名/类型均为代码内常量，不来自用户输入
      db.exec(`ALTER TABLE ${table} ADD COLUMN ${column} ${definition}`);
      log.info('数据库迁移：新增列', { table, column });
    }
  };
  ensureColumn('users', 'last_seen_at', 'INTEGER');
  ensureColumn('couples', 'dissolved_at', 'INTEGER');
  ensureColumn('messages', 'deleted_at', 'INTEGER');
  ensureColumn('emoji_packs', 'is_system', 'INTEGER NOT NULL DEFAULT 0');
}

/** 首次启动写入默认 settings（已存在则不覆盖） */
function seedDefaultSettings() {
  const now = Date.now();
  const insert = db.prepare(
    'INSERT INTO settings (key, value, updated_at) VALUES (?, ?, ?) ON CONFLICT(key) DO NOTHING'
  );
  const tx = db.transaction(() => {
    for (const [key, value] of Object.entries(DEFAULTS)) {
      insert.run(key, JSON.stringify(value), now);
    }
  });
  tx();
}

/** 获取数据库实例（必须先 initDb） */
export function getDb() {
  if (!db) throw new Error('数据库尚未初始化，请先调用 initDb()');
  return db;
}

/** 关闭数据库（测试/优雅退出用） */
export function closeDb() {
  if (db) {
    try {
      // WAL 检查点，保证数据落盘
      db.pragma('wal_checkpoint(TRUNCATE)');
    } catch {
      /* 忽略 */
    }
    db.close();
    db = null;
  }
}

// ---------------------------------------------------------------------------
// 行 -> API 对象 的映射
// ---------------------------------------------------------------------------

/**
 * user 行转 API 对象。
 * 注意：password_hash 绝不外泄（API.md 未定义该字段）。
 */
export function mapUser(row) {
  if (!row) return null;
  return {
    id: row.id,
    username: row.username,
    nickname: row.nickname ?? '',
    avatarEmoji: row.avatar_emoji ?? '',
    coupleId: row.couple_id ?? null,
    role: row.role ?? 'user',
    disabled: !!row.disabled,
    createdAt: row.created_at,
  };
}

function mapCoupleRow(row, members) {
  if (!row) return null;
  return {
    id: row.id,
    inviteCode: row.invite_code,
    togetherDate: row.together_date ?? null,
    anniversaryTitle: row.anniversary_title ?? '在一起',
    members: members ?? [],
    createdAt: row.created_at,
    updatedAt: row.updated_at,
  };
}

function mapMessage(row) {
  if (!row) return null;
  return {
    id: row.id,
    clientId: row.client_id ?? null,
    coupleId: row.couple_id,
    senderId: row.sender_id,
    kind: row.kind,
    body: row.body ?? '',
    meta: parseJson(row.meta),
    createdAt: row.created_at,
    // 撤回后 read_at 保持原值，另行用 deleted 标记
    readAt: row.read_at ?? null,
  };
}

function mapEmojiItem(row) {
  if (!row) return null;
  return {
    id: row.id,
    packId: row.pack_id,
    ownerId: row.owner_id,
    mime: row.mime,
    size: row.size,
    name: row.name ?? '',
    url: `${CONFIG.publicBaseUrl}/api/emoji/blob/${row.id}`,
    createdAt: row.created_at,
    updatedAt: row.updated_at,
  };
}

function mapEmojiPack(row, items) {
  if (!row) return null;
  return {
    id: row.id,
    name: row.name,
    ownerId: row.owner_id,
    isPublic: !!row.is_public,
    createdAt: row.created_at,
    updatedAt: row.updated_at,
    items: items ?? [],
  };
}

function mapSyncObject(row) {
  if (!row) return null;
  return {
    kind: row.kind,
    id: row.id,
    updatedAt: row.updated_at,
    deleted: !!row.deleted,
    ownerId: row.owner_id,
    updatedBy: row.updated_by,
    payload: parseJson(row.payload),
  };
}

/** 安全解析数据库中的 JSON 文本 */
function parseJson(text) {
  if (text === null || text === undefined || text === '') return null;
  try {
    return JSON.parse(text);
  } catch {
    return null;
  }
}

// ---------------------------------------------------------------------------
// users
// ---------------------------------------------------------------------------

export function getUserById(id) {
  const row = getDb().prepare('SELECT * FROM users WHERE id = ?').get(id);
  return row ? { row, user: mapUser(row) } : null;
}

export function findUserByUsername(username) {
  const row = getDb().prepare('SELECT * FROM users WHERE username = ?').get(username);
  return row ? { row, user: mapUser(row) } : null;
}

export function createUser({ username, passwordHash, nickname, avatarEmoji = '' }) {
  const now = Date.now();
  const id = newUserId();
  getDb()
    .prepare(
      `INSERT INTO users (id, username, password_hash, nickname, avatar_emoji, couple_id, role, disabled, created_at, updated_at)
       VALUES (?, ?, ?, ?, ?, NULL, 'user', 0, ?, ?)`
    )
    .run(id, username, passwordHash, nickname, avatarEmoji, now, now);
  return getUserById(id);
}

export function updateUserProfile(id, { nickname, avatarEmoji }) {
  const dbh = getDb();
  const cur = dbh.prepare('SELECT * FROM users WHERE id = ?').get(id);
  if (!cur) return null;
  const nextNickname = nickname === undefined ? cur.nickname : nickname;
  const nextEmoji = avatarEmoji === undefined ? cur.avatar_emoji : avatarEmoji;
  dbh
    .prepare('UPDATE users SET nickname = ?, avatar_emoji = ?, updated_at = ? WHERE id = ?')
    .run(nextNickname, nextEmoji, Date.now(), id);
  return getUserById(id);
}

export function updateUserPassword(id, passwordHash) {
  getDb()
    .prepare('UPDATE users SET password_hash = ?, updated_at = ? WHERE id = ?')
    .run(passwordHash, Date.now(), id);
}

export function setUserCouple(id, coupleId) {
  getDb()
    .prepare('UPDATE users SET couple_id = ?, updated_at = ? WHERE id = ?')
    .run(coupleId, Date.now(), id);
}

export function setUserDisabled(id, disabled) {
  getDb()
    .prepare('UPDATE users SET disabled = ?, updated_at = ? WHERE id = ?')
    .run(disabled ? 1 : 0, Date.now(), id);
}

export function touchUserLastSeen(id) {
  getDb().prepare('UPDATE users SET last_seen_at = ? WHERE id = ?').run(Date.now(), id);
}

export function deleteUser(id) {
  const dbh = getDb();
  const tx = dbh.transaction(() => {
    // 先记录其情侣空间，便于后续判断是否需要解散
    const row = dbh.prepare('SELECT couple_id FROM users WHERE id = ?').get(id);
    const coupleId = row?.couple_id ?? null;
    // 表情条目与包
    dbh.prepare('DELETE FROM emoji_items WHERE owner_id = ?').run(id);
    dbh.prepare('DELETE FROM emoji_packs WHERE owner_id = ?').run(id);
    // 同步对象
    dbh.prepare('DELETE FROM sync_objects WHERE owner_id = ?').run(id);
    // 消息：保留会话完整性，做匿名化处理（发送者置为已删除标记）
    dbh.prepare('UPDATE messages SET sender_id = ?, body = ? WHERE sender_id = ?').run('u_deleted', '[用户已注销]', id);
    dbh.prepare('DELETE FROM ai_usage WHERE user_id = ?').run(id);
    dbh.prepare('DELETE FROM users WHERE id = ?').run(id);
    return coupleId;
  });
  return tx();
}

export function countUsers() {
  return getDb().prepare('SELECT COUNT(*) AS n FROM users').get().n;
}

/**
 * 后台用户列表（支持关键字搜索 + 分页）
 * @param {{query?:string, page?:number, pageSize?:number}} opts
 */
export function listUsers({ query = '', page = 1, pageSize = 20 } = {}) {
  const dbh = getDb();
  const offset = (page - 1) * pageSize;
  const like = `%${query}%`;
  const where = query
    ? 'WHERE username LIKE ? OR nickname LIKE ? OR id LIKE ?'
    : '';
  const params = query ? [like, like, like] : [];

  const total = dbh.prepare(`SELECT COUNT(*) AS n FROM users ${where}`).get(...params).n;
  const rows = dbh
    .prepare(`SELECT * FROM users ${where} ORDER BY created_at DESC LIMIT ? OFFSET ?`)
    .all(...params, pageSize, offset);

  // 附带情侣信息，方便后台查看
  const coupleIds = [...new Set(rows.map((r) => r.couple_id).filter(Boolean))];
  const couples = new Map();
  if (coupleIds.length > 0) {
    const placeholders = coupleIds.map(() => '?').join(',');
    for (const c of dbh
      .prepare(`SELECT id, invite_code FROM couples WHERE id IN (${placeholders})`)
      .all(...coupleIds)) {
      couples.set(c.id, c.invite_code);
    }
  }

  return {
    total,
    page,
    pageSize,
    users: rows.map((r) => ({
      ...mapUser(r),
      inviteCode: r.couple_id ? couples.get(r.couple_id) ?? null : null,
      lastSeenAt: r.last_seen_at ?? null,
    })),
  };
}

// ---------------------------------------------------------------------------
// couples
// ---------------------------------------------------------------------------

export function getCoupleRow(id) {
  return getDb().prepare('SELECT * FROM couples WHERE id = ?').get(id);
}

/** 组装完整 couple 对象（含 members 完整 user 数组） */
export function buildCouple(id) {
  const row = getCoupleRow(id);
  if (!row) return null;
  const members = getDb()
    .prepare('SELECT * FROM users WHERE couple_id = ? ORDER BY created_at ASC')
    .all(id)
    .map(mapUser);
  return mapCoupleRow(row, members);
}

export function getCoupleByInviteCode(inviteCode) {
  const row = getDb()
    .prepare('SELECT * FROM couples WHERE invite_code = ? AND dissolved_at IS NULL')
    .get(inviteCode);
  return row ? buildCouple(row.id) : null;
}

export function getCoupleMemberCount(coupleId) {
  return getDb().prepare('SELECT COUNT(*) AS n FROM users WHERE couple_id = ?').get(coupleId).n;
}

export function createCouple({ anniversaryTitle = '在一起' } = {}) {
  const dbh = getDb();
  const now = Date.now();
  const id = newCoupleId();

  // 邀请码唯一性由随机 + 唯一索引保证，冲突时重试
  for (let attempt = 0; attempt < 12; attempt += 1) {
    const code = newInviteCode();
    const exists = dbh.prepare('SELECT 1 FROM couples WHERE invite_code = ?').get(code);
    if (exists) continue;
    dbh
      .prepare(
        `INSERT INTO couples (id, invite_code, together_date, anniversary_title, created_at, updated_at)
         VALUES (?, ?, NULL, ?, ?, ?)`
      )
      .run(id, code, anniversaryTitle, now, now);
    return buildCouple(id);
  }
  throw new Error('生成邀请码失败，请重试');
}

export function updateCouple(id, { togetherDate, anniversaryTitle }) {
  const dbh = getDb();
  const cur = dbh.prepare('SELECT * FROM couples WHERE id = ?').get(id);
  if (!cur) return null;
  const nextDate = togetherDate === undefined ? cur.together_date : togetherDate;
  const nextTitle = anniversaryTitle === undefined ? cur.anniversary_title : anniversaryTitle;
  dbh
    .prepare('UPDATE couples SET together_date = ?, anniversary_title = ?, updated_at = ? WHERE id = ?')
    .run(nextDate, nextTitle, Date.now(), id);
  return buildCouple(id);
}

/**
 * 解除绑定：双方 coupleId 置空，couple 标记为已解散（数据保留 30 天）
 * @returns {number} 受影响用户数
 */
export function dissolveCouple(coupleId) {
  const dbh = getDb();
  const now = Date.now();
  const tx = dbh.transaction(() => {
    const info = dbh
      .prepare('UPDATE users SET couple_id = NULL, updated_at = ? WHERE couple_id = ?')
      .run(now, coupleId);
    dbh.prepare('UPDATE couples SET dissolved_at = ?, updated_at = ? WHERE id = ?').run(now, now, coupleId);
    return info.changes;
  });
  return tx();
}

export function countCouples() {
  return getDb()
    .prepare('SELECT COUNT(*) AS n FROM couples WHERE dissolved_at IS NULL')
    .get().n;
}

export function countAllCouples() {
  return getDb().prepare('SELECT COUNT(*) AS n FROM couples').get().n;
}

/**
 * 清理超过保留期的已解散情侣数据（默认 30 天，API.md §3）
 * @param {number} retentionDays
 */
export function purgeDissolvedCouples(retentionDays = LIMITS.COUPLE_RETENTION_DAYS) {
  const dbh = getDb();
  const cutoff = Date.now() - retentionDays * 24 * 60 * 60 * 1000;
  const rows = dbh
    .prepare('SELECT id FROM couples WHERE dissolved_at IS NOT NULL AND dissolved_at < ?')
    .all(cutoff);
  if (rows.length === 0) return 0;

  const tx = dbh.transaction(() => {
    for (const { id } of rows) {
      dbh.prepare('DELETE FROM messages WHERE couple_id = ?').run(id);
      dbh.prepare('DELETE FROM sync_objects WHERE couple_id = ?').run(id);
      dbh.prepare('DELETE FROM emoji_items WHERE couple_id = ?').run(id);
      dbh.prepare('DELETE FROM emoji_packs WHERE couple_id = ? AND is_system = 0').run(id);
      dbh.prepare('DELETE FROM couples WHERE id = ?').run(id);
    }
  });
  tx();
  log.info('已清理过期情侣数据', { count: rows.length, retentionDays });
  return rows.length;
}

// ---------------------------------------------------------------------------
// messages
// ---------------------------------------------------------------------------

export function insertMessage({ clientId, coupleId, senderId, kind, body, meta }) {
  const dbh = getDb();
  const now = Date.now();
  const id = newMessageId();
  dbh
    .prepare(
      `INSERT INTO messages (id, client_id, couple_id, sender_id, kind, body, meta, created_at, read_at)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL)`
    )
    .run(id, clientId ?? null, coupleId, senderId, kind, body ?? '', meta ? JSON.stringify(meta) : null, now);
  return mapMessage(dbh.prepare('SELECT * FROM messages WHERE id = ?').get(id));
}

/** 按 clientId 查已存在消息（幂等重发） */
export function findMessageByClientId(coupleId, clientId) {
  const row = getDb()
    .prepare('SELECT * FROM messages WHERE couple_id = ? AND client_id = ?')
    .get(coupleId, clientId);
  return row ? mapMessage(row) : null;
}

export function getMessage(id) {
  const row = getDb().prepare('SELECT * FROM messages WHERE id = ?').get(id);
  return row ? { row, message: mapMessage(row) } : null;
}

/**
 * 拉取消息：不传 before 取最新 limit 条，返回按 createdAt 升序
 * @param {{coupleId:string, before?:number, limit:number}} opts
 */
export function listMessages({ coupleId, before, limit }) {
  const dbh = getDb();
  let rows;
  if (before === undefined || before === null) {
    // 取最新 limit 条：先按时间倒序取，再翻回升序
    rows = dbh
      .prepare(
        `SELECT * FROM messages WHERE couple_id = ?
         ORDER BY created_at DESC, id DESC LIMIT ?`
      )
      .all(coupleId, limit + 1);
  } else {
    rows = dbh
      .prepare(
        `SELECT * FROM messages WHERE couple_id = ? AND created_at < ?
         ORDER BY created_at DESC, id DESC LIMIT ?`
      )
      .all(coupleId, before, limit + 1);
  }

  const hasMore = rows.length > limit;
  const page = rows.slice(0, limit).reverse();
  return { messages: page.map(mapMessage), hasMore };
}

/**
 * 标记已读：把 createdAt <= upTo 且对方发来的消息标为已读
 * @returns {number} 更新条数
 */
export function markMessagesRead(coupleId, userId, upTo) {
  const info = getDb()
    .prepare(
      `UPDATE messages SET read_at = ?
       WHERE couple_id = ? AND sender_id != ? AND created_at <= ? AND read_at IS NULL AND deleted_at IS NULL`
    )
    .run(Date.now(), coupleId, userId, upTo);
  return info.changes;
}

/** 撤回消息（仅自己、24 小时内） */
export function recallMessage(id) {
  const info = getDb()
    .prepare('UPDATE messages SET deleted_at = ? WHERE id = ?')
    .run(Date.now(), id);
  return info.changes > 0;
}

export function countMessages() {
  return getDb().prepare('SELECT COUNT(*) AS n FROM messages').get().n;
}

/** 某用户当天已发消息数（用于 maxMessagesPerDay 限制） */
export function countMessagesTodayByUser(userId) {
  const start = new Date();
  start.setHours(0, 0, 0, 0);
  return getDb()
    .prepare('SELECT COUNT(*) AS n FROM messages WHERE sender_id = ? AND created_at >= ?')
    .get(userId, start.getTime()).n;
}

/** 更新情侣空间活跃时间（有新消息/同步时调用） */
export function touchCouple(coupleId) {
  getDb().prepare('UPDATE couples SET updated_at = ? WHERE id = ?').run(Date.now(), coupleId);
}

// ---------------------------------------------------------------------------
// emoji_packs / emoji_items
// ---------------------------------------------------------------------------

/** 该情侣空间已创建的自建包数量（不含公共包） */
export function countOwnPacks(coupleId) {
  return getDb()
    .prepare('SELECT COUNT(*) AS n FROM emoji_packs WHERE couple_id = ? AND is_public = 0')
    .get(coupleId).n;
}

export function createPack({ name, ownerId, coupleId, isPublic = false, isSystem = false, id }) {
  const dbh = getDb();
  const now = Date.now();
  const packId = id || newPackId();
  dbh
    .prepare(
      `INSERT INTO emoji_packs (id, name, owner_id, couple_id, is_public, is_system, created_at, updated_at)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?)`
    )
    .run(packId, name, ownerId, coupleId, isPublic ? 1 : 0, isSystem ? 1 : 0, now, now);
  return getPackRow(packId);
}

export function getPackRow(packId) {
  return getDb().prepare('SELECT * FROM emoji_packs WHERE id = ?').get(packId);
}

export function deletePack(packId) {
  const dbh = getDb();
  const tx = dbh.transaction(() => {
    dbh.prepare('DELETE FROM emoji_items WHERE pack_id = ?').run(packId);
    const info = dbh.prepare('DELETE FROM emoji_packs WHERE id = ?').run(packId);
    return info.changes;
  });
  return tx();
}

/**
 * 列出情侣空间可见的表情包（自己 + 伴侣 + 公共），含 items
 * @param {string} coupleId
 */
export function listPacksForCouple(coupleId) {
  const dbh = getDb();
  const rows = dbh
    .prepare(
      `SELECT * FROM emoji_packs
       WHERE is_public = 1 OR couple_id = ?
       ORDER BY is_public DESC, created_at ASC`
    )
    .all(coupleId);

  const result = [];
  const itemsStmt = dbh.prepare('SELECT * FROM emoji_items WHERE pack_id = ? ORDER BY created_at ASC');
  for (const row of rows) {
    result.push(mapEmojiPack(row, itemsStmt.all(row.id).map(mapEmojiItem)));
  }
  return result;
}

export function getPackWithItems(packId) {
  const row = getPackRow(packId);
  if (!row) return null;
  const items = getDb()
    .prepare('SELECT * FROM emoji_items WHERE pack_id = ? ORDER BY created_at ASC')
    .all(packId)
    .map(mapEmojiItem);
  return mapEmojiPack(row, items);
}

export function countItemsInPack(packId) {
  return getDb().prepare('SELECT COUNT(*) AS n FROM emoji_items WHERE pack_id = ?').get(packId).n;
}

export function insertEmojiItem({ id, packId, ownerId, coupleId, mime, size, name }) {
  const dbh = getDb();
  const now = Date.now();
  const itemId = id || newEmojiId();
  dbh
    .prepare(
      `INSERT INTO emoji_items (id, pack_id, owner_id, couple_id, mime, size, name, created_at, updated_at)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)`
    )
    .run(itemId, packId, ownerId, coupleId, mime, size, name, now, now);
  dbh.prepare('UPDATE emoji_packs SET updated_at = ? WHERE id = ?').run(now, packId);
  return dbh.prepare('SELECT * FROM emoji_items WHERE id = ?').get(itemId);
}

/** 取表情条目（含二进制存放在磁盘，这里只取元数据） */
export function getEmojiItem(itemId) {
  const row = getDb().prepare('SELECT * FROM emoji_items WHERE id = ?').get(itemId);
  return row ? { row, item: mapEmojiItem(row) } : null;
}

/**
 * 删除单张表情的数据库记录（磁盘文件由调用方负责清理）
 * @param {string} itemId 已通过 isValidEmojiId 校验
 * @returns {boolean} 是否删除了记录
 */
export function deleteEmojiItem(itemId) {
  const dbh = getDb();
  const tx = dbh.transaction(() => {
    const row = dbh.prepare('SELECT pack_id FROM emoji_items WHERE id = ?').get(itemId);
    if (!row) return 0;
    const info = dbh.prepare('DELETE FROM emoji_items WHERE id = ?').run(itemId);
    // 同步更新所属包的 updatedAt，便于客户端增量刷新
    dbh.prepare('UPDATE emoji_packs SET updated_at = ? WHERE id = ?').run(Date.now(), row.pack_id);
    return info.changes;
  });
  return tx() > 0;
}

// ---------------------------------------------------------------------------
// 内置公共表情包（API.md §6：isPublic=true 为内置公共表情包）
// ---------------------------------------------------------------------------

/** 公共表情包固定 id 前缀，便于与用户自建包区分 */
export const PUBLIC_PACK_PREFIX = 'p_public_';

/**
 * 从磁盘目录同步内置公共表情包。
 *
 * 目录约定（服务端预置，随仓库一起发布）：
 *   public/emoji/<表情包名>/<文件名>.{jpg,jpeg,png,gif,webp}
 *
 * 每次启动都会重新扫描并覆盖同名包的内容，保证与磁盘一致；
 * 用户自建包（`p_` 前缀但不含 `p_public_`）完全不受影响。
 *
 * @param {string} dir 公共表情包根目录的绝对路径
 * @param {object} opts
 * @param {(buf:Buffer)=>string|null} opts.sniffMime 依据文件头识别真实 mime
 * @param {string[]} opts.allowedMimes mime 白名单
 * @returns {{packs:number, items:number}} 同步结果
 */
export function syncPublicEmojiPacks(dir, { sniffMime, allowedMimes }) {
  const dbh = getDb();
  const result = { packs: 0, items: 0 };

  if (!fs.existsSync(dir)) return result;

  // 读取子目录作为表情包名称（按名称排序，保证 id 映射稳定可复现）
  let entries = [];
  try {
    entries = fs
      .readdirSync(dir, { withFileTypes: true })
      .filter((e) => e.isDirectory())
      .sort((a, b) => (a.name < b.name ? -1 : a.name > b.name ? 1 : 0));
  } catch {
    return result;
  }
  if (entries.length === 0) return result;

  const now = Date.now();

  // 包 id 由目录名派生，要求目录名为 [A-Za-z0-9_-]，保证 id 合法且稳定
  const packIdFor = (dirName) => `${PUBLIC_PACK_PREFIX}${dirName.replace(/[^A-Za-z0-9_-]/g, '_')}`;
  // 表情 id 必须满足 ^e_[A-Za-z0-9]+$，因此用包序号 + 文件序号生成
  const itemIdFor = (packIdx, fileIdx) => `e_public${packIdx}${fileIdx}`;

  const clearItems = dbh.prepare('DELETE FROM emoji_items WHERE pack_id = ?');
  const upsertPack = dbh.prepare(
    `INSERT INTO emoji_packs (id, name, owner_id, couple_id, is_public, is_system, created_at, updated_at)
     VALUES (?, ?, 'system', '', 1, 1, ?, ?)
     ON CONFLICT(id) DO UPDATE SET name = excluded.name, updated_at = excluded.updated_at`
  );
  const insertItem = dbh.prepare(
    `INSERT OR REPLACE INTO emoji_items
       (id, pack_id, owner_id, couple_id, mime, size, name, created_at, updated_at)
     VALUES (?, ?, 'system', '', ?, ?, ?, ?, ?)`
  );

  const tx = dbh.transaction(() => {
    let packIdx = 0;
    for (const entry of entries) {
      const packName = entry.name;
      const packDir = path.join(dir, packName);
      const packId = packIdFor(packName);
      packIdx += 1;

      let files = [];
      try {
        files = fs
          .readdirSync(packDir, { withFileTypes: true })
          .filter((f) => f.isFile() && /\.(jpe?g|png|gif|webp)$/i.test(f.name))
          .map((f) => f.name)
          .sort();
      } catch {
        continue;
      }
      if (files.length === 0) continue;

      upsertPack.run(packId, packName, now, now);
      // 重建该包内容，保证与磁盘一致
      clearItems.run(packId);

      let fileIdx = 0;
      let added = 0;
      for (const fileName of files) {
        fileIdx += 1;
        const filePath = path.join(packDir, fileName);
        let buf;
        try {
          buf = fs.readFileSync(filePath);
        } catch {
          continue;
        }
        if (buf.length === 0 || buf.length > LIMITS.EMOJI_MAX_BYTES) continue;

        // 同样按 magic bytes 校验，非法文件直接跳过
        const mime = sniffMime(buf);
        if (!mime || !allowedMimes.includes(mime)) {
          log.warn('公共表情文件格式不受支持，已跳过', { file: filePath });
          continue;
        }

        const baseName = fileName.replace(/\.[^.]+$/, '');
        insertItem.run(
          itemIdFor(packIdx, fileIdx),
          packId,
          mime,
          buf.length,
          baseName,
          now,
          now
        );
        added += 1;
      }

      if (added > 0) {
        result.packs += 1;
        result.items += added;
      }
    }
  });
  tx();

  return result;
}

/**
 * 解析某个表情条目的磁盘文件路径。
 *
 * 规则完全由服务端决定，不含任何用户输入：
 *   - 公共内置表情（owner_id = 'system'）  -> public/emoji/<包名>/<文件名>
 *   - 用户上传表情                          -> <DATA_DIR>/emoji/<id>.bin
 *
 * 调用前必须已通过 isValidEmojiId(id) 校验。
 * @param {{id:string, pack_id:string, owner_id:string}} itemRow
 * @returns {string|null} 绝对路径；无法解析时返回 null
 */
export function resolveEmojiFilePath(itemRow) {
  if (!itemRow || !isValidEmojiId(itemRow.id)) return null;

  const base = path.resolve(CONFIG.emojiDir);
  // 仅允许 base 目录下形如 <emojiId>.bin 的文件（文件名完全由服务端 id 决定）
  const localPath = path.resolve(path.join(base, `${itemRow.id}.bin`));
  const isSafeLocal = localPath.startsWith(base + path.sep) && path.dirname(localPath) === base;

  // 用户上传表情：直接命中磁盘文件
  if (isSafeLocal && fs.existsSync(localPath)) return localPath;

  // 公共内置表情：id 形如 e_public<packIdx><fileIdx>，需要扫描目录反查文件名
  if (itemRow.owner_id === 'system') {
    const m = /^e_public(\d+)(\d+)$/.exec(itemRow.id);
    if (!m) return isSafeLocal ? localPath : null;
    const packIdx = Number(m[1]);
    const fileIdx = Number(m[2]);

    // 按与 syncPublicEmojiPacks 完全相同的排序定位，保证映射一致
    let dirs = [];
    try {
      dirs = fs
        .readdirSync(PUBLIC_EMOJI_DIR, { withFileTypes: true })
        .filter((e) => e.isDirectory())
        .map((e) => e.name)
        .sort((a, b) => (a < b ? -1 : a > b ? 1 : 0));
    } catch {
      return null;
    }

    const packDirName = dirs[packIdx - 1];
    if (!packDirName) return null;
    const packDir = path.join(PUBLIC_EMOJI_DIR, packDirName);

    let files = [];
    try {
      files = fs
        .readdirSync(packDir, { withFileTypes: true })
        .filter((f) => f.isFile() && /\.(jpe?g|png|gif|webp)$/i.test(f.name))
        .map((f) => f.name)
        .sort();
    } catch {
      return null;
    }
    const fileName = files[fileIdx - 1];
    if (!fileName) return null;

    const resolved = path.resolve(path.join(packDir, fileName));
    // 二次防御：确保仍在公共表情目录内
    const publicBase = path.resolve(PUBLIC_EMOJI_DIR);
    if (!resolved.startsWith(publicBase + path.sep)) return null;
    return resolved;
  }

  // 本地文件即使不存在也返回路径，由调用方决定返回 404
  return isSafeLocal ? localPath : null;
}

export function countEmojiPacks() {
  return getDb().prepare('SELECT COUNT(*) AS n FROM emoji_packs').get().n;
}

export function countEmojiItems() {
  return getDb().prepare('SELECT COUNT(*) AS n FROM emoji_items').get().n;
}

// ---------------------------------------------------------------------------
// sync_objects
// ---------------------------------------------------------------------------

/**
 * 写入同步对象（LWW：仅当本次 updatedAt >= 服务端版本才接受）
 * 整个过程在一个事务里，保证并发安全。
 * @param {{coupleId:string, userId:string, objects:Array}} opts
 * @returns {{applied:string[], conflicts:string[]}}
 */
export function pushSyncObjects({ coupleId, userId, objects }) {
  const dbh = getDb();
  const now = Date.now();
  const applied = [];
  const conflicts = [];

  const selectStmt = dbh.prepare(
    'SELECT updated_at FROM sync_objects WHERE couple_id = ? AND kind = ? AND id = ?'
  );
  const upsertStmt = dbh.prepare(
    `INSERT INTO sync_objects (couple_id, kind, id, owner_id, updated_by, updated_at, deleted, payload, created_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
     ON CONFLICT(couple_id, kind, id) DO UPDATE SET
       updated_by = excluded.updated_by,
       updated_at = excluded.updated_at,
       deleted    = excluded.deleted,
       payload    = excluded.payload`
  );

  const tx = dbh.transaction(() => {
    for (const obj of objects) {
      const existing = selectStmt.get(coupleId, obj.kind, obj.id);
      if (existing && existing.updated_at > obj.updatedAt) {
        // 服务端版本更新 -> 拒绝写入，返回冲突
        conflicts.push(obj.id);
        continue;
      }
      upsertStmt.run(
        coupleId,
        obj.kind,
        obj.id,
        userId,
        userId,
        obj.updatedAt,
        obj.deleted ? 1 : 0,
        obj.payload === undefined || obj.payload === null ? null : JSON.stringify(obj.payload),
        now
      );
      applied.push(obj.id);
    }
    dbh.prepare('UPDATE couples SET updated_at = ? WHERE id = ?').run(now, coupleId);
  });
  tx();

  return { applied, conflicts };
}

/**
 * 拉取同步对象（updatedAt > since）
 * @param {{coupleId:string, since:number, kinds:string[], limit:number}} opts
 */
export function pullSyncObjects({ coupleId, since, kinds, limit }) {
  const dbh = getDb();
  const placeholders = kinds.map(() => '?').join(',');
  const rows = dbh
    .prepare(
      `SELECT * FROM sync_objects
       WHERE couple_id = ? AND updated_at > ? AND kind IN (${placeholders})
       ORDER BY updated_at ASC, kind ASC, id ASC
       LIMIT ?`
    )
    .all(coupleId, since, ...kinds, limit + 1);

  const hasMore = rows.length > limit;
  return { objects: rows.slice(0, limit).map(mapSyncObject), hasMore };
}

export function countSyncObjects() {
  return getDb().prepare('SELECT COUNT(*) AS n FROM sync_objects').get().n;
}

// ---------------------------------------------------------------------------
// settings
// ---------------------------------------------------------------------------

/** 读取单个设置（JSON 反序列化） */
export function getSetting(key) {
  const row = getDb().prepare('SELECT value FROM settings WHERE key = ?').get(key);
  if (!row) return undefined;
  return parseJson(row.value);
}

/** 读取全部设置为对象 */
export function getAllSettings() {
  const rows = getDb().prepare('SELECT key, value FROM settings').all();
  const out = {};
  for (const r of rows) out[r.key] = parseJson(r.value);
  return out;
}

/** 写入单个设置（JSON 序列化） */
export function setSetting(key, value) {
  getDb()
    .prepare(
      `INSERT INTO settings (key, value, updated_at) VALUES (?, ?, ?)
       ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at`
    )
    .run(key, JSON.stringify(value), Date.now());
}

/** 批量写入设置（事务） */
export function setSettings(entries) {
  const dbh = getDb();
  const stmt = dbh.prepare(
    `INSERT INTO settings (key, value, updated_at) VALUES (?, ?, ?)
     ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at`
  );
  const now = Date.now();
  const tx = dbh.transaction(() => {
    for (const [k, v] of Object.entries(entries)) stmt.run(k, JSON.stringify(v), now);
  });
  tx();
}

// ---------------------------------------------------------------------------
// ai_usage
// ---------------------------------------------------------------------------

/** 记录一次 AI 调用 */
export function recordAiUsage({ userId, endpoint, model, ok = true, promptLen = 0, day }) {
  const dbh = getDb();
  const d = day || todayKey();
  dbh
    .prepare(
      `INSERT INTO ai_usage (id, user_id, endpoint, model, ok, prompt_len, created_at, day)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?)`
    )
    .run(newUserId().replace(/^u_/, 'a_'), userId ?? null, endpoint, model ?? null, ok ? 1 : 0, promptLen, Date.now(), d);
}

/** 当天 AI 调用总数（用于每日限额） */
export function countAiUsageToday(day) {
  const d = day || todayKey();
  return getDb().prepare('SELECT COUNT(*) AS n FROM ai_usage WHERE day = ?').get(d).n;
}

/** 某用户当天 AI 调用数 */
export function countAiUsageTodayByUser(userId, day) {
  const d = day || todayKey();
  return getDb()
    .prepare('SELECT COUNT(*) AS n FROM ai_usage WHERE day = ? AND user_id = ?')
    .get(d, userId).n;
}

/** 本地日期 YYYY-MM-DD */
export function todayKey(d = new Date()) {
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, '0');
  const day = String(d.getDate()).padStart(2, '0');
  return `${y}-${m}-${day}`;
}

// ---------------------------------------------------------------------------
// admin_logs
// ---------------------------------------------------------------------------

/** 记录后台操作日志 */
export function addAdminLog({ action, detail, ip }) {
  getDb()
    .prepare('INSERT INTO admin_logs (id, action, detail, ip, created_at) VALUES (?, ?, ?, ?, ?)')
    .run(`l_${Date.now().toString(36)}${Math.random().toString(36).slice(2, 8)}`, action, detail ?? null, ip ?? null, Date.now());
}

/** 最近操作日志 */
export function listAdminLogs(limit = 100) {
  return getDb()
    .prepare('SELECT id, action, detail, ip, created_at FROM admin_logs ORDER BY created_at DESC LIMIT ?')
    .all(limit)
    .map((r) => ({
      id: r.id,
      action: r.action,
      detail: r.detail,
      ip: r.ip,
      createdAt: r.created_at,
    }));
}

// ---------------------------------------------------------------------------
// 统计 / 维护
// ---------------------------------------------------------------------------

/** 统计汇总（后台仪表盘） */
export function getStats() {
  const dbh = getDb();
  return {
    users: dbh.prepare('SELECT COUNT(*) AS n FROM users').get().n,
    couples: dbh.prepare('SELECT COUNT(*) AS n FROM couples WHERE dissolved_at IS NULL').get().n,
    couplesTotal: dbh.prepare('SELECT COUNT(*) AS n FROM couples').get().n,
    messages: dbh.prepare('SELECT COUNT(*) AS n FROM messages').get().n,
    aiToday: countAiUsageToday(),
    aiTotal: dbh.prepare('SELECT COUNT(*) AS n FROM ai_usage').get().n,
    packs: dbh.prepare('SELECT COUNT(*) AS n FROM emoji_packs').get().n,
    items: dbh.prepare('SELECT COUNT(*) AS n FROM emoji_items').get().n,
    syncObjects: dbh.prepare('SELECT COUNT(*) AS n FROM sync_objects').get().n,
  };
}

/** 磁盘占用（字节）：数据库 + WAL + SHM + 表情目录 */
export function getDiskUsage() {
  const sumFile = (p) => {
    try {
      return fs.statSync(p).size;
    } catch {
      return 0;
    }
  };
  const emojiDir = CONFIG.emojiDir;
  let emojiBytes = 0;
  let emojiFiles = 0;
  try {
    for (const name of fs.readdirSync(emojiDir)) {
      const p = path.join(emojiDir, name);
      const st = fs.statSync(p);
      if (st.isFile()) {
        emojiBytes += st.size;
        emojiFiles += 1;
      }
    }
  } catch {
    /* 目录不存在时忽略 */
  }

  const dbBytes = sumFile(DB_FILE);
  const walBytes = sumFile(`${DB_FILE}-wal`);
  const shmBytes = sumFile(`${DB_FILE}-shm`);

  return {
    dbBytes: dbBytes + walBytes + shmBytes,
    emojiBytes,
    emojiFiles,
    totalBytes: dbBytes + walBytes + shmBytes + emojiBytes,
  };
}

/** 数据库自检（健康检查用） */
export function pingDb() {
  const row = getDb().prepare('SELECT 1 AS ok').get();
  return row?.ok === 1;
}

export default {
  initDb,
  getDb,
  closeDb,
  mapUser,
};
