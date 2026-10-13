<?php
namespace App;

use App\util\Ids;
use PDO;
use PDOException;

/**
 * 数据库层：唯一接触 SQL 的地方（表结构 / 迁移 / 所有查询封装）。
 *
 * 全部使用 PDO 预处理绑定，严禁把用户输入拼进 SQL。
 * 表结构参照 Node 版 server/src/db.js，另加两张「服务端内部」表：
 *   rate_limits  登录/接口限流计数（PHP 每个请求是独立进程，内存计数无效）
 *   ws_events    实时推送事件队列（REST 进程写入，bin/ws-server.php 轮询广播）
 */
class Db
{
    /** @var PDO|null */
    private static $pdo = null;

    const SCHEMA_SQL = "
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

-- 登录限流计数（内部表，非 API.md 表清单）
CREATE TABLE IF NOT EXISTS rate_limits (
  key           TEXT PRIMARY KEY,
  window_start  INTEGER NOT NULL,
  window_ms     INTEGER NOT NULL,
  count         INTEGER NOT NULL DEFAULT 0,
  prev_count    INTEGER NOT NULL DEFAULT 0,
  prev_start    INTEGER NOT NULL DEFAULT 0
);

-- 实时推送事件队列（内部表：REST 进程写，WS 常驻进程读）
CREATE TABLE IF NOT EXISTS ws_events (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  couple_id    TEXT,
  target_user  TEXT,
  except_user  TEXT,
  payload      TEXT NOT NULL,
  created_at   INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_ws_events_couple ON ws_events(couple_id);
";

    /** 初始化数据库连接、建表、迁移、写入默认设置（幂等） */
    public static function init()
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }
        Config::ensureDirs();
        $file = Config::dbFile();

        try {
            $pdo = new PDO('sqlite:' . $file);
        } catch (PDOException $e) {
            throw new \RuntimeException('数据库初始化失败：' . $e->getMessage()
                . "\n请确认 " . Config::dataDir() . " 目录存在且 PHP 运行用户有读写权限。", 0, $e);
        }
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, false);

        // 开启 WAL（读写并发更好）；busy_timeout 避免瞬时锁冲突
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA synchronous = NORMAL');
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');

        $pdo->exec(self::SCHEMA_SQL);
        self::$pdo = $pdo;

        self::migrate();
        self::seedDefaultSettings();
        Log::info('数据库已就绪', ['file' => $file]);
        return $pdo;
    }

    /** @return PDO */
    public static function pdo()
    {
        if (self::$pdo === null) {
            self::init();
        }
        return self::$pdo;
    }

    /** 关闭连接（CLI/测试用） */
    public static function close()
    {
        if (self::$pdo !== null) {
            try {
                self::$pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
            } catch (PDOException $e) {
                // 忽略
            }
            self::$pdo = null;
        }
    }

    /**
     * 轻量迁移：为旧库补齐后加的列。
     * SQLite 不支持 ADD COLUMN IF NOT EXISTS，因此先查 PRAGMA table_info。
     */
    private static function migrate()
    {
        $ensure = function ($table, $column, $definition) {
            $cols = self::all('PRAGMA table_info(' . $table . ')');
            foreach ($cols as $c) {
                if (isset($c['name']) && $c['name'] === $column) {
                    return;
                }
            }
            // 表名/列名/类型均为代码内常量，不来自用户输入
            self::pdo()->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition);
            Log::info('数据库迁移：新增列', ['table' => $table, 'column' => $column]);
        };
        $ensure('users', 'last_seen_at', 'INTEGER');
        $ensure('couples', 'dissolved_at', 'INTEGER');
        $ensure('messages', 'deleted_at', 'INTEGER');
        $ensure('emoji_packs', 'is_system', 'INTEGER NOT NULL DEFAULT 0');
    }

    /** 首次启动写入默认 settings（已存在则不覆盖） */
    private static function seedDefaultSettings()
    {
        $now = Time::nowMs();
        $stmt = self::pdo()->prepare(
            'INSERT INTO settings (key, value, updated_at) VALUES (?, ?, ?) ON CONFLICT(key) DO NOTHING'
        );
        foreach (Config::DEFAULTS as $key => $value) {
            $stmt->execute([$key, json_encode($value, JSON_UNESCAPED_UNICODE), $now]);
        }
    }

    // ------------------------------------------------------------------
    // 通用查询封装（全部走预处理绑定）
    // ------------------------------------------------------------------

    public static function run($sql, array $params = [])
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public static function one($sql, array $params = [])
    {
        $stmt = self::run($sql, $params);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public static function all($sql, array $params = [])
    {
        $stmt = self::run($sql, $params);
        $rows = $stmt->fetchAll();
        return is_array($rows) ? $rows : [];
    }

    public static function scalar($sql, array $params = [])
    {
        $stmt = self::run($sql, $params);
        $v = $stmt->fetchColumn();
        return $v === false ? null : $v;
    }

    public static function count($sql, array $params = [])
    {
        return (int) self::scalar($sql, $params);
    }

    // ------------------------------------------------------------------
    // 行 -> API 对象 的映射
    // ------------------------------------------------------------------

    /** user 行转 API 对象（password_hash 绝不外泄） */
    public static function mapUser($row)
    {
        if (!$row) {
            return null;
        }
        return [
            'id' => (string) $row['id'],
            'username' => (string) $row['username'],
            'nickname' => isset($row['nickname']) ? (string) $row['nickname'] : '',
            'avatarEmoji' => isset($row['avatar_emoji']) ? (string) $row['avatar_emoji'] : '',
            'coupleId' => isset($row['couple_id']) && $row['couple_id'] !== null ? (string) $row['couple_id'] : null,
            'role' => isset($row['role']) ? (string) $row['role'] : 'user',
            'disabled' => !empty($row['disabled']),
            'createdAt' => (int) $row['created_at'],
        ];
    }

    private static function mapCoupleRow($row, array $members)
    {
        if (!$row) {
            return null;
        }
        return [
            'id' => (string) $row['id'],
            'inviteCode' => (string) $row['invite_code'],
            'togetherDate' => isset($row['together_date']) && $row['together_date'] !== null
                ? (int) $row['together_date'] : null,
            'anniversaryTitle' => isset($row['anniversary_title'])
                ? (string) $row['anniversary_title'] : '在一起',
            'members' => $members,
            'createdAt' => (int) $row['created_at'],
            'updatedAt' => (int) $row['updated_at'],
        ];
    }

    public static function mapMessage($row)
    {
        if (!$row) {
            return null;
        }
        return [
            'id' => (string) $row['id'],
            'clientId' => isset($row['client_id']) && $row['client_id'] !== null
                ? (string) $row['client_id'] : null,
            'coupleId' => (string) $row['couple_id'],
            'senderId' => (string) $row['sender_id'],
            'kind' => (string) $row['kind'],
            'body' => isset($row['body']) ? (string) $row['body'] : '',
            'meta' => self::parseJson($row['meta']),
            'createdAt' => (int) $row['created_at'],
            'readAt' => isset($row['read_at']) && $row['read_at'] !== null ? (int) $row['read_at'] : null,
        ];
    }

    public static function mapEmojiItem($row)
    {
        if (!$row) {
            return null;
        }
        return [
            'id' => (string) $row['id'],
            'packId' => (string) $row['pack_id'],
            'ownerId' => (string) $row['owner_id'],
            'mime' => (string) $row['mime'],
            'size' => (int) $row['size'],
            'name' => isset($row['name']) ? (string) $row['name'] : '',
            'url' => Config::get('public_base_url') . '/api/emoji/blob/' . $row['id'],
            'createdAt' => (int) $row['created_at'],
            'updatedAt' => (int) $row['updated_at'],
        ];
    }

    private static function mapEmojiPack($row, array $items)
    {
        if (!$row) {
            return null;
        }
        return [
            'id' => (string) $row['id'],
            'name' => (string) $row['name'],
            'ownerId' => (string) $row['owner_id'],
            'isPublic' => !empty($row['is_public']),
            'createdAt' => (int) $row['created_at'],
            'updatedAt' => (int) $row['updated_at'],
            'items' => $items,
        ];
    }

    private static function mapSyncObject($row)
    {
        if (!$row) {
            return null;
        }
        return [
            'kind' => (string) $row['kind'],
            'id' => (string) $row['id'],
            'updatedAt' => (int) $row['updated_at'],
            'deleted' => !empty($row['deleted']),
            'ownerId' => (string) $row['owner_id'],
            'updatedBy' => (string) $row['updated_by'],
            'payload' => self::parseJson($row['payload']),
        ];
    }

    /** 安全解析数据库中的 JSON 文本（对象保持为 stdClass，便于原样回吐 {}） */
    public static function parseJson($text)
    {
        if ($text === null || $text === '') {
            return null;
        }
        $v = json_decode((string) $text);
        return json_last_error() === JSON_ERROR_NONE ? $v : null;
    }

    // ------------------------------------------------------------------
    // users
    // ------------------------------------------------------------------

    public static function getUserById($id)
    {
        $row = self::one('SELECT * FROM users WHERE id = ?', [$id]);
        return $row ? ['row' => $row, 'user' => self::mapUser($row)] : null;
    }

    public static function findUserByUsername($username)
    {
        $row = self::one('SELECT * FROM users WHERE username = ?', [$username]);
        return $row ? ['row' => $row, 'user' => self::mapUser($row)] : null;
    }

    public static function createUser(array $data)
    {
        $now = Time::nowMs();
        $id = Ids::newUserId();
        self::run(
            'INSERT INTO users (id, username, password_hash, nickname, avatar_emoji, couple_id, role, disabled, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, NULL, \'user\', 0, ?, ?)',
            [
                $id,
                $data['username'],
                $data['passwordHash'],
                $data['nickname'],
                isset($data['avatarEmoji']) ? $data['avatarEmoji'] : '',
                $now,
                $now,
            ]
        );
        return self::getUserById($id);
    }

    /**
     * 更新资料：只更新传入的字段（undefined 表示不改）
     * @param array $patch 可含 nickname / avatarEmoji
     */
    public static function updateUserProfile($id, array $patch)
    {
        $cur = self::one('SELECT * FROM users WHERE id = ?', [$id]);
        if (!$cur) {
            return null;
        }
        $nickname = array_key_exists('nickname', $patch) ? $patch['nickname'] : $cur['nickname'];
        $emoji = array_key_exists('avatarEmoji', $patch) ? $patch['avatarEmoji'] : $cur['avatar_emoji'];
        self::run(
            'UPDATE users SET nickname = ?, avatar_emoji = ?, updated_at = ? WHERE id = ?',
            [$nickname, $emoji, Time::nowMs(), $id]
        );
        return self::getUserById($id);
    }

    public static function updateUserPassword($id, $passwordHash)
    {
        self::run('UPDATE users SET password_hash = ?, updated_at = ? WHERE id = ?', [$passwordHash, Time::nowMs(), $id]);
    }

    public static function setUserCouple($id, $coupleId)
    {
        self::run('UPDATE users SET couple_id = ?, updated_at = ? WHERE id = ?', [$coupleId, Time::nowMs(), $id]);
    }

    public static function setUserDisabled($id, $disabled)
    {
        self::run('UPDATE users SET disabled = ?, updated_at = ? WHERE id = ?', [$disabled ? 1 : 0, Time::nowMs(), $id]);
    }

    public static function touchUserLastSeen($id)
    {
        self::run('UPDATE users SET last_seen_at = ? WHERE id = ?', [Time::nowMs(), $id]);
    }

    /** 删除用户（返回其原 coupleId，便于后续判断） */
    public static function deleteUser($id)
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $row = self::one('SELECT couple_id FROM users WHERE id = ?', [$id]);
            $coupleId = $row && $row['couple_id'] !== null ? (string) $row['couple_id'] : null;
            self::run('DELETE FROM emoji_items WHERE owner_id = ?', [$id]);
            self::run('DELETE FROM emoji_packs WHERE owner_id = ?', [$id]);
            self::run('DELETE FROM sync_objects WHERE owner_id = ?', [$id]);
            // 消息保留会话完整性，做匿名化处理
            self::run('UPDATE messages SET sender_id = ?, body = ? WHERE sender_id = ?', ['u_deleted', '[用户已注销]', $id]);
            self::run('DELETE FROM ai_usage WHERE user_id = ?', [$id]);
            self::run('DELETE FROM users WHERE id = ?', [$id]);
            $pdo->commit();
            return $coupleId;
        } catch (\Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function countUsers()
    {
        return self::count('SELECT COUNT(*) AS n FROM users');
    }

    /** 后台用户列表（关键字搜索 + 分页） */
    public static function listUsers(array $opts = [])
    {
        $query = isset($opts['query']) ? $opts['query'] : '';
        $page = isset($opts['page']) ? (int) $opts['page'] : 1;
        $pageSize = isset($opts['pageSize']) ? (int) $opts['pageSize'] : 20;
        $offset = ($page - 1) * $pageSize;

        $where = '';
        $params = [];
        if ($query !== '') {
            $where = 'WHERE username LIKE ? OR nickname LIKE ? OR id LIKE ?';
            $like = '%' . $query . '%';
            $params = [$like, $like, $like];
        }
        $total = self::count('SELECT COUNT(*) AS n FROM users ' . $where, $params);
        $rows = self::all(
            'SELECT * FROM users ' . $where . ' ORDER BY created_at DESC LIMIT ? OFFSET ?',
            array_merge($params, [$pageSize, $offset])
        );

        // 附带邀请码，方便后台查看
        $coupleIds = [];
        foreach ($rows as $r) {
            if (!empty($r['couple_id']) && !in_array($r['couple_id'], $coupleIds, true)) {
                $coupleIds[] = $r['couple_id'];
            }
        }
        $codes = [];
        if (count($coupleIds) > 0) {
            $ph = implode(',', array_fill(0, count($coupleIds), '?'));
            foreach (self::all('SELECT id, invite_code FROM couples WHERE id IN (' . $ph . ')', $coupleIds) as $c) {
                $codes[$c['id']] = $c['invite_code'];
            }
        }

        $users = [];
        foreach ($rows as $r) {
            $u = self::mapUser($r);
            $u['inviteCode'] = !empty($r['couple_id']) && isset($codes[$r['couple_id']])
                ? $codes[$r['couple_id']] : null;
            $u['lastSeenAt'] = isset($r['last_seen_at']) && $r['last_seen_at'] !== null
                ? (int) $r['last_seen_at'] : null;
            $users[] = $u;
        }

        return ['total' => $total, 'page' => $page, 'pageSize' => $pageSize, 'users' => $users];
    }

    // ------------------------------------------------------------------
    // couples
    // ------------------------------------------------------------------

    public static function getCoupleRow($id)
    {
        return self::one('SELECT * FROM couples WHERE id = ?', [$id]);
    }

    /** 组装完整 couple 对象（含 members 完整 user 数组） */
    public static function buildCouple($id)
    {
        $row = self::getCoupleRow($id);
        if (!$row) {
            return null;
        }
        $members = [];
        foreach (self::all('SELECT * FROM users WHERE couple_id = ? ORDER BY created_at ASC', [$id]) as $r) {
            $members[] = self::mapUser($r);
        }
        return self::mapCoupleRow($row, $members);
    }

    public static function getCoupleByInviteCode($inviteCode)
    {
        $row = self::one(
            'SELECT * FROM couples WHERE invite_code = ? AND dissolved_at IS NULL',
            [$inviteCode]
        );
        return $row ? self::buildCouple($row['id']) : null;
    }

    public static function getCoupleMemberCount($coupleId)
    {
        return self::count('SELECT COUNT(*) AS n FROM users WHERE couple_id = ?', [$coupleId]);
    }

    public static function createCouple(array $opts = [])
    {
        $title = isset($opts['anniversaryTitle']) ? $opts['anniversaryTitle'] : '在一起';
        $now = Time::nowMs();
        $id = Ids::newCoupleId();

        // 邀请码唯一性由随机 + 唯一索引保证，冲突时重试
        for ($attempt = 0; $attempt < 12; $attempt++) {
            $code = Ids::newInviteCode();
            $exists = self::one('SELECT 1 FROM couples WHERE invite_code = ?', [$code]);
            if ($exists) {
                continue;
            }
            self::run(
                'INSERT INTO couples (id, invite_code, together_date, anniversary_title, created_at, updated_at)
                 VALUES (?, ?, NULL, ?, ?, ?)',
                [$id, $code, $title, $now, $now]
            );
            return self::buildCouple($id);
        }
        throw new \RuntimeException('生成邀请码失败，请重试');
    }

    /**
     * 更新情侣空间：只更新传入字段（null 表示显式清空 togetherDate）
     */
    public static function updateCouple($id, array $patch)
    {
        $cur = self::getCoupleRow($id);
        if (!$cur) {
            return null;
        }
        $date = array_key_exists('togetherDate', $patch) ? $patch['togetherDate'] : $cur['together_date'];
        $title = array_key_exists('anniversaryTitle', $patch) ? $patch['anniversaryTitle'] : $cur['anniversary_title'];
        self::run(
            'UPDATE couples SET together_date = ?, anniversary_title = ?, updated_at = ? WHERE id = ?',
            [$date, $title, Time::nowMs(), $id]
        );
        return self::buildCouple($id);
    }

    /** 解除绑定：双方 coupleId 置空，couple 标记为已解散（数据保留 30 天） */
    public static function dissolveCouple($coupleId)
    {
        $pdo = self::pdo();
        $now = Time::nowMs();
        $pdo->beginTransaction();
        try {
            $stmt = self::run('UPDATE users SET couple_id = NULL, updated_at = ? WHERE couple_id = ?', [$now, $coupleId]);
            $changes = $stmt->rowCount();
            self::run('UPDATE couples SET dissolved_at = ?, updated_at = ? WHERE id = ?', [$now, $now, $coupleId]);
            $pdo->commit();
            return $changes;
        } catch (\Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function countCouples()
    {
        return self::count('SELECT COUNT(*) AS n FROM couples WHERE dissolved_at IS NULL');
    }

    public static function countAllCouples()
    {
        return self::count('SELECT COUNT(*) AS n FROM couples');
    }

    /** 清理超过保留期的已解散情侣数据（默认 30 天） */
    public static function purgeDissolvedCouples($retentionDays = 30)
    {
        $cutoff = Time::nowMs() - $retentionDays * 86400000;
        $rows = self::all('SELECT id FROM couples WHERE dissolved_at IS NOT NULL AND dissolved_at < ?', [$cutoff]);
        if (count($rows) === 0) {
            return 0;
        }
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            foreach ($rows as $r) {
                $id = $r['id'];
                self::run('DELETE FROM messages WHERE couple_id = ?', [$id]);
                self::run('DELETE FROM sync_objects WHERE couple_id = ?', [$id]);
                self::run('DELETE FROM emoji_items WHERE couple_id = ?', [$id]);
                self::run('DELETE FROM emoji_packs WHERE couple_id = ? AND is_system = 0', [$id]);
                self::run('DELETE FROM couples WHERE id = ?', [$id]);
            }
            $pdo->commit();
        } catch (\Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        Log::info('已清理过期情侣数据', ['count' => count($rows), 'retentionDays' => $retentionDays]);
        return count($rows);
    }

    // ------------------------------------------------------------------
    // messages
    // ------------------------------------------------------------------

    public static function insertMessage(array $data)
    {
        $now = Time::nowMs();
        $id = Ids::newMessageId();
        $meta = array_key_exists('meta', $data) && $data['meta'] !== null
            ? json_encode($data['meta'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : null;
        self::run(
            'INSERT INTO messages (id, client_id, couple_id, sender_id, kind, body, meta, created_at, read_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL)',
            [
                $id,
                isset($data['clientId']) ? $data['clientId'] : null,
                $data['coupleId'],
                $data['senderId'],
                $data['kind'],
                isset($data['body']) ? $data['body'] : '',
                $meta,
                $now,
            ]
        );
        $row = self::one('SELECT * FROM messages WHERE id = ?', [$id]);
        return self::mapMessage($row);
    }

    public static function findMessageByClientId($coupleId, $clientId)
    {
        $row = self::one(
            'SELECT * FROM messages WHERE couple_id = ? AND client_id = ?',
            [$coupleId, $clientId]
        );
        return $row ? self::mapMessage($row) : null;
    }

    public static function getMessage($id)
    {
        $row = self::one('SELECT * FROM messages WHERE id = ?', [$id]);
        return $row ? ['row' => $row, 'message' => self::mapMessage($row)] : null;
    }

    /**
     * 拉取消息：不传 before 取最新 limit 条，返回按 createdAt 升序
     * @return array{messages:array,hasMore:bool}
     */
    public static function listMessages(array $opts)
    {
        $coupleId = $opts['coupleId'];
        $limit = (int) $opts['limit'];
        $before = array_key_exists('before', $opts) ? $opts['before'] : null;

        if ($before === null) {
            $rows = self::all(
                'SELECT * FROM messages WHERE couple_id = ?
                 ORDER BY created_at DESC, id DESC LIMIT ?',
                [$coupleId, $limit + 1]
            );
        } else {
            $rows = self::all(
                'SELECT * FROM messages WHERE couple_id = ? AND created_at < ?
                 ORDER BY created_at DESC, id DESC LIMIT ?',
                [$coupleId, $before, $limit + 1]
            );
        }

        $hasMore = count($rows) > $limit;
        $page = array_slice($rows, 0, $limit);
        $page = array_reverse($page);
        $messages = [];
        foreach ($page as $r) {
            $messages[] = self::mapMessage($r);
        }
        return ['messages' => $messages, 'hasMore' => $hasMore];
    }

    /** 标记已读：把 createdAt <= upTo 且对方发来的消息标为已读 */
    public static function markMessagesRead($coupleId, $userId, $upTo)
    {
        $stmt = self::run(
            'UPDATE messages SET read_at = ?
             WHERE couple_id = ? AND sender_id != ? AND created_at <= ? AND read_at IS NULL AND deleted_at IS NULL',
            [Time::nowMs(), $coupleId, $userId, $upTo]
        );
        return $stmt->rowCount();
    }

    /** 撤回消息（仅自己、24 小时内） */
    public static function recallMessage($id)
    {
        $stmt = self::run('UPDATE messages SET deleted_at = ? WHERE id = ?', [Time::nowMs(), $id]);
        return $stmt->rowCount() > 0;
    }

    public static function countMessages()
    {
        return self::count('SELECT COUNT(*) AS n FROM messages');
    }

    /** 某用户当天已发消息数（用于 maxMessagesPerDay 限制） */
    public static function countMessagesTodayByUser($userId)
    {
        return self::count(
            'SELECT COUNT(*) AS n FROM messages WHERE sender_id = ? AND created_at >= ?',
            [$userId, Time::todayStartMs()]
        );
    }

    /** 更新情侣空间活跃时间 */
    public static function touchCouple($coupleId)
    {
        self::run('UPDATE couples SET updated_at = ? WHERE id = ?', [Time::nowMs(), $coupleId]);
    }

    // ------------------------------------------------------------------
    // emoji_packs / emoji_items
    // ------------------------------------------------------------------

    /** 该情侣空间已创建的自建包数量（不含公共包） */
    public static function countOwnPacks($coupleId)
    {
        return self::count('SELECT COUNT(*) AS n FROM emoji_packs WHERE couple_id = ? AND is_public = 0', [$coupleId]);
    }

    public static function createPack(array $data)
    {
        $now = Time::nowMs();
        $packId = isset($data['id']) && $data['id'] ? $data['id'] : Ids::newPackId();
        self::run(
            'INSERT INTO emoji_packs (id, name, owner_id, couple_id, is_public, is_system, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $packId,
                $data['name'],
                $data['ownerId'],
                $data['coupleId'],
                !empty($data['isPublic']) ? 1 : 0,
                !empty($data['isSystem']) ? 1 : 0,
                $now,
                $now,
            ]
        );
        return self::getPackRow($packId);
    }

    public static function getPackRow($packId)
    {
        return self::one('SELECT * FROM emoji_packs WHERE id = ?', [$packId]);
    }

    public static function deletePack($packId)
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            self::run('DELETE FROM emoji_items WHERE pack_id = ?', [$packId]);
            $stmt = self::run('DELETE FROM emoji_packs WHERE id = ?', [$packId]);
            $changes = $stmt->rowCount();
            $pdo->commit();
            return $changes;
        } catch (\Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** 列出情侣空间可见的表情包（自己 + 伴侣 + 公共），含 items */
    public static function listPacksForCouple($coupleId)
    {
        $rows = self::all(
            'SELECT * FROM emoji_packs
             WHERE is_public = 1 OR couple_id = ?
             ORDER BY is_public DESC, created_at ASC',
            [$coupleId]
        );
        $result = [];
        foreach ($rows as $row) {
            $items = [];
            foreach (self::all('SELECT * FROM emoji_items WHERE pack_id = ? ORDER BY created_at ASC', [$row['id']]) as $it) {
                $items[] = self::mapEmojiItem($it);
            }
            $result[] = self::mapEmojiPack($row, $items);
        }
        return $result;
    }

    public static function getPackWithItems($packId)
    {
        $row = self::getPackRow($packId);
        if (!$row) {
            return null;
        }
        $items = [];
        foreach (self::all('SELECT * FROM emoji_items WHERE pack_id = ? ORDER BY created_at ASC', [$packId]) as $it) {
            $items[] = self::mapEmojiItem($it);
        }
        return self::mapEmojiPack($row, $items);
    }

    public static function countItemsInPack($packId)
    {
        return self::count('SELECT COUNT(*) AS n FROM emoji_items WHERE pack_id = ?', [$packId]);
    }

    public static function insertEmojiItem(array $data)
    {
        $now = Time::nowMs();
        $itemId = isset($data['id']) && $data['id'] ? $data['id'] : Ids::newEmojiId();
        self::run(
            'INSERT INTO emoji_items (id, pack_id, owner_id, couple_id, mime, size, name, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $itemId,
                $data['packId'],
                $data['ownerId'],
                $data['coupleId'],
                $data['mime'],
                (int) $data['size'],
                isset($data['name']) ? $data['name'] : '',
                $now,
                $now,
            ]
        );
        self::run('UPDATE emoji_packs SET updated_at = ? WHERE id = ?', [$now, $data['packId']]);
        return self::one('SELECT * FROM emoji_items WHERE id = ?', [$itemId]);
    }

    public static function getEmojiItem($itemId)
    {
        $row = self::one('SELECT * FROM emoji_items WHERE id = ?', [$itemId]);
        return $row ? ['row' => $row, 'item' => self::mapEmojiItem($row)] : null;
    }

    /** 删除单张表情的数据库记录（磁盘文件由调用方清理） */
    public static function deleteEmojiItem($itemId)
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $row = self::one('SELECT pack_id FROM emoji_items WHERE id = ?', [$itemId]);
            if (!$row) {
                $pdo->commit();
                return false;
            }
            $stmt = self::run('DELETE FROM emoji_items WHERE id = ?', [$itemId]);
            $changes = $stmt->rowCount();
            self::run('UPDATE emoji_packs SET updated_at = ? WHERE id = ?', [Time::nowMs(), $row['pack_id']]);
            $pdo->commit();
            return $changes > 0;
        } catch (\Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function countEmojiPacks()
    {
        return self::count('SELECT COUNT(*) AS n FROM emoji_packs');
    }

    public static function countEmojiItems()
    {
        return self::count('SELECT COUNT(*) AS n FROM emoji_items');
    }

    // ------------------------------------------------------------------
    // sync_objects
    // ------------------------------------------------------------------

    /**
     * 写入同步对象（LWW：仅当本次 updatedAt >= 服务端版本才接受），事务内完成。
     * @return array{applied:array,conflicts:array}
     */
    public static function pushSyncObjects(array $opts)
    {
        $coupleId = $opts['coupleId'];
        $userId = $opts['userId'];
        $objects = $opts['objects'];
        $now = Time::nowMs();
        $applied = [];
        $conflicts = [];

        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            foreach ($objects as $obj) {
                $existing = self::one(
                    'SELECT updated_at FROM sync_objects WHERE couple_id = ? AND kind = ? AND id = ?',
                    [$coupleId, $obj['kind'], $obj['id']]
                );
                if ($existing && (int) $existing['updated_at'] > (int) $obj['updatedAt']) {
                    // 服务端版本更新 -> 拒绝写入，返回冲突
                    $conflicts[] = $obj['id'];
                    continue;
                }
                $payload = array_key_exists('payload', $obj) && $obj['payload'] !== null
                    ? json_encode($obj['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    : null;
                self::run(
                    'INSERT INTO sync_objects (couple_id, kind, id, owner_id, updated_by, updated_at, deleted, payload, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                     ON CONFLICT(couple_id, kind, id) DO UPDATE SET
                       updated_by = excluded.updated_by,
                       updated_at = excluded.updated_at,
                       deleted    = excluded.deleted,
                       payload    = excluded.payload',
                    [
                        $coupleId,
                        $obj['kind'],
                        $obj['id'],
                        $userId,
                        $userId,
                        (int) $obj['updatedAt'],
                        !empty($obj['deleted']) ? 1 : 0,
                        $payload,
                        $now,
                    ]
                );
                $applied[] = $obj['id'];
            }
            self::run('UPDATE couples SET updated_at = ? WHERE id = ?', [$now, $coupleId]);
            $pdo->commit();
        } catch (\Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return ['applied' => $applied, 'conflicts' => $conflicts];
    }

    /** 拉取同步对象（updatedAt > since） */
    public static function pullSyncObjects(array $opts)
    {
        $coupleId = $opts['coupleId'];
        $since = (int) $opts['since'];
        $kinds = $opts['kinds'];
        $limit = (int) $opts['limit'];
        $ph = implode(',', array_fill(0, count($kinds), '?'));
        $params = array_merge([$coupleId, $since], $kinds, [$limit + 1]);
        $rows = self::all(
            'SELECT * FROM sync_objects
             WHERE couple_id = ? AND updated_at > ? AND kind IN (' . $ph . ')
             ORDER BY updated_at ASC, kind ASC, id ASC
             LIMIT ?',
            $params
        );
        $hasMore = count($rows) > $limit;
        $objects = [];
        foreach (array_slice($rows, 0, $limit) as $r) {
            $objects[] = self::mapSyncObject($r);
        }
        return ['objects' => $objects, 'hasMore' => $hasMore];
    }

    public static function countSyncObjects()
    {
        return self::count('SELECT COUNT(*) AS n FROM sync_objects');
    }

    // ------------------------------------------------------------------
    // settings
    // ------------------------------------------------------------------

    /** 读取单个设置（已 JSON 反序列化） */
    public static function getSetting($key)
    {
        $row = self::one('SELECT value FROM settings WHERE key = ?', [$key]);
        if (!$row) {
            return null;
        }
        return self::parseJson($row['value']);
    }

    /** 读取全部设置 */
    public static function getAllSettings()
    {
        $out = [];
        foreach (self::all('SELECT key, value FROM settings') as $r) {
            $out[$r['key']] = self::parseJson($r['value']);
        }
        return $out;
    }

    public static function setSetting($key, $value)
    {
        self::run(
            'INSERT INTO settings (key, value, updated_at) VALUES (?, ?, ?)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at',
            [$key, json_encode($value, JSON_UNESCAPED_UNICODE), Time::nowMs()]
        );
    }

    /** 批量写入设置（事务） */
    public static function setSettings(array $entries)
    {
        $now = Time::nowMs();
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            foreach ($entries as $k => $v) {
                self::run(
                    'INSERT INTO settings (key, value, updated_at) VALUES (?, ?, ?)
                     ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at',
                    [$k, json_encode($v, JSON_UNESCAPED_UNICODE), $now]
                );
            }
            $pdo->commit();
        } catch (\Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    // ------------------------------------------------------------------
    // ai_usage
    // ------------------------------------------------------------------

    public static function recordAiUsage(array $data)
    {
        $day = isset($data['day']) && $data['day'] ? $data['day'] : Time::dayKey();
        self::run(
            'INSERT INTO ai_usage (id, user_id, endpoint, model, ok, prompt_len, created_at, day)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                Ids::newAiUsageId(),
                isset($data['userId']) ? $data['userId'] : null,
                $data['endpoint'],
                isset($data['model']) ? $data['model'] : null,
                !isset($data['ok']) || $data['ok'] ? 1 : 0,
                isset($data['promptLen']) ? (int) $data['promptLen'] : 0,
                Time::nowMs(),
                $day,
            ]
        );
    }

    public static function countAiUsageToday($day = null)
    {
        $d = $day ? $day : Time::dayKey();
        return self::count('SELECT COUNT(*) AS n FROM ai_usage WHERE day = ?', [$d]);
    }

    public static function countAiUsageTodayByUser($userId, $day = null)
    {
        $d = $day ? $day : Time::dayKey();
        return self::count('SELECT COUNT(*) AS n FROM ai_usage WHERE day = ? AND user_id = ?', [$d, $userId]);
    }

    // ------------------------------------------------------------------
    // admin_logs
    // ------------------------------------------------------------------

    public static function addAdminLog(array $data)
    {
        self::run(
            'INSERT INTO admin_logs (id, action, detail, ip, created_at) VALUES (?, ?, ?, ?, ?)',
            [
                Ids::newLogId(),
                $data['action'],
                isset($data['detail']) ? $data['detail'] : null,
                isset($data['ip']) ? $data['ip'] : null,
                Time::nowMs(),
            ]
        );
    }

    public static function listAdminLogs($limit = 100)
    {
        $limit = (int) $limit;
        $out = [];
        foreach (self::all(
            'SELECT id, action, detail, ip, created_at FROM admin_logs ORDER BY created_at DESC LIMIT ?',
            [$limit]
        ) as $r) {
            $out[] = [
                'id' => (string) $r['id'],
                'action' => (string) $r['action'],
                'detail' => $r['detail'] !== null ? (string) $r['detail'] : null,
                'ip' => $r['ip'] !== null ? (string) $r['ip'] : null,
                'createdAt' => (int) $r['created_at'],
            ];
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // 统计 / 维护
    // ------------------------------------------------------------------

    public static function getStats()
    {
        return [
            'users' => self::countUsers(),
            'couples' => self::countCouples(),
            'couplesTotal' => self::countAllCouples(),
            'messages' => self::countMessages(),
            'aiToday' => self::countAiUsageToday(),
            'aiTotal' => self::count('SELECT COUNT(*) AS n FROM ai_usage'),
            'packs' => self::countEmojiPacks(),
            'items' => self::countEmojiItems(),
            'syncObjects' => self::countSyncObjects(),
        ];
    }

    /** 磁盘占用（字节）：数据库 + WAL + SHM + 表情目录 */
    public static function getDiskUsage()
    {
        $sum = function ($p) {
            return is_file($p) ? (int) filesize($p) : 0;
        };
        $emojiBytes = 0;
        $emojiFiles = 0;
        $dir = Config::emojiDir();
        if (is_dir($dir)) {
            $names = @scandir($dir);
            if (is_array($names)) {
                foreach ($names as $name) {
                    if ($name === '.' || $name === '..') {
                        continue;
                    }
                    $p = $dir . '/' . $name;
                    if (is_file($p)) {
                        $emojiBytes += (int) filesize($p);
                        $emojiFiles++;
                    }
                }
            }
        }
        $dbFile = Config::dbFile();
        $dbBytes = $sum($dbFile) + $sum($dbFile . '-wal') + $sum($dbFile . '-shm');

        return [
            'dbBytes' => $dbBytes,
            'emojiBytes' => $emojiBytes,
            'emojiFiles' => $emojiFiles,
            'totalBytes' => $dbBytes + $emojiBytes,
        ];
    }

    /** 数据库自检（健康检查用） */
    public static function pingDb()
    {
        $row = self::one('SELECT 1 AS ok');
        return $row !== null && (int) $row['ok'] === 1;
    }
}
