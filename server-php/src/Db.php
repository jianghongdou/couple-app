<?php
namespace App;

use App\util\Ids;
use PDO;
use PDOException;

/**
 * 数据库层：唯一接触 SQL 的地方（表结构 / 迁移 / 所有查询封装）。
 *
 * 数据库为 MySQL / MariaDB（PDO pdo_mysql）。兼容性约定：
 *   - 只用 MySQL 5.7 / 8.0 与 MariaDB 10.x 都支持的语法
 *   - ENGINE=InnoDB DEFAULT CHARSET=utf8mb4，不指定 COLLATE（8.0 的 0900 专有排序规则不可用）
 *   - 不使用 JSON 类型（JSON 文本存 MEDIUMTEXT/LONGTEXT，应用层 json_encode/decode）
 *   - 不使用 CHECK 约束、窗口函数、CTE、JSON_TABLE
 *   - 主键/索引列用 VARCHAR（utf8mb4 下 VARCHAR(255) 索引约 1020 字节，远低于 3072 上限）
 *   - 时间戳一律 BIGINT 存毫秒，避免 DATETIME/TIMESTAMP 的时区坑
 *   - upsert 统一用 INSERT ... ON DUPLICATE KEY UPDATE（MySQL/MariaDB 通用）
 *
 * 全部使用 PDO 预处理绑定，严禁把用户输入拼进 SQL（仅表名/列名/索引名这些代码内常量会拼接）。
 * 表结构与字段名保持与 Node 版 server/src/db.js 一致，另加两张「服务端内部」表：
 *   rate_limits  登录/接口限流计数（PHP 每个请求是独立进程，内存计数无效）
 *   ws_events    实时推送事件队列（REST 进程写入，bin/ws-server.php 轮询广播）
 */
class Db
{
    /** @var PDO|null */
    private static $pdo = null;

    /** 建库失败时给用户的统一引导语 */
    const INSTALL_HINT = '请先在浏览器中打开安装向导（站点地址 + /install.php）完成数据库配置。';

    /**
     * 表结构定义（DDL 方言 = MySQL/MariaDB）。
     * 每一项：[建表 SQL, 期望列 => 定义, 期望索引 => 列清单]
     *
     * 之所以把「期望列/索引」单独列出来，是为了让迁移能做到幂等：
     * 用 information_schema 查缺补漏，而不是无脑 ALTER（MySQL 没有 ADD COLUMN IF NOT EXISTS）。
     */
    private static function tables()
    {
        return [
            'users' => [
                "CREATE TABLE IF NOT EXISTS users (
  id             VARCHAR(64)  NOT NULL,
  username       VARCHAR(64)  NOT NULL,
  password_hash  VARCHAR(255) NOT NULL,
  nickname       TEXT         NOT NULL,
  avatar_emoji   VARCHAR(64)  NOT NULL DEFAULT '',
  couple_id      VARCHAR(64)  NULL,
  role           VARCHAR(32)  NOT NULL DEFAULT 'user',
  disabled       TINYINT      NOT NULL DEFAULT 0,
  created_at     BIGINT       NOT NULL,
  updated_at     BIGINT       NOT NULL,
  last_seen_at   BIGINT       NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
                [
                    'id' => "VARCHAR(64) NOT NULL",
                    'username' => "VARCHAR(64) NOT NULL",
                    'password_hash' => "VARCHAR(255) NOT NULL",
                    'nickname' => "TEXT NOT NULL",
                    'avatar_emoji' => "VARCHAR(64) NOT NULL DEFAULT ''",
                    'couple_id' => "VARCHAR(64) NULL",
                    'role' => "VARCHAR(32) NOT NULL DEFAULT 'user'",
                    'disabled' => "TINYINT NOT NULL DEFAULT 0",
                    'created_at' => "BIGINT NOT NULL",
                    'updated_at' => "BIGINT NOT NULL",
                    'last_seen_at' => "BIGINT NULL",
                ],
                [
                    'idx_users_couple' => ['couple_id'],
                ],
            ],

            'couples' => [
                "CREATE TABLE IF NOT EXISTS couples (
  id                VARCHAR(64) NOT NULL,
  invite_code       VARCHAR(32) NOT NULL,
  together_date     BIGINT      NULL,
  anniversary_title VARCHAR(128) NOT NULL DEFAULT '在一起',
  created_at        BIGINT      NOT NULL,
  updated_at        BIGINT      NOT NULL,
  dissolved_at      BIGINT      NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_couples_invite (invite_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
                [
                    'id' => "VARCHAR(64) NOT NULL",
                    'invite_code' => "VARCHAR(32) NOT NULL",
                    'together_date' => "BIGINT NULL",
                    'anniversary_title' => "VARCHAR(128) NOT NULL DEFAULT '在一起'",
                    'created_at' => "BIGINT NOT NULL",
                    'updated_at' => "BIGINT NOT NULL",
                    'dissolved_at' => "BIGINT NULL",
                ],
                [
                    'idx_couples_active' => ['dissolved_at'],
                ],
            ],

            'messages' => [
                "CREATE TABLE IF NOT EXISTS messages (
  id          VARCHAR(64)  NOT NULL,
  client_id   VARCHAR(64)  NULL,
  couple_id   VARCHAR(64)  NOT NULL,
  sender_id   VARCHAR(64)  NOT NULL,
  kind        VARCHAR(32)  NOT NULL,
  body        TEXT         NOT NULL,
  meta        MEDIUMTEXT   NULL,
  created_at  BIGINT       NOT NULL,
  read_at     BIGINT       NULL,
  deleted_at  BIGINT       NULL,
  PRIMARY KEY (id),
  UNIQUE KEY idx_messages_client (couple_id, client_id),
  KEY idx_messages_couple_time (couple_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
                [
                    'id' => "VARCHAR(64) NOT NULL",
                    'client_id' => "VARCHAR(64) NULL",
                    'couple_id' => "VARCHAR(64) NOT NULL",
                    'sender_id' => "VARCHAR(64) NOT NULL",
                    'kind' => "VARCHAR(32) NOT NULL",
                    'body' => "TEXT NOT NULL",
                    'meta' => "MEDIUMTEXT NULL",
                    'created_at' => "BIGINT NOT NULL",
                    'read_at' => "BIGINT NULL",
                    'deleted_at' => "BIGINT NULL",
                ],
                [
                    'idx_messages_client' => ['couple_id', 'client_id'],
                    'idx_messages_couple_time' => ['couple_id', 'created_at'],
                ],
            ],

            'emoji_packs' => [
                "CREATE TABLE IF NOT EXISTS emoji_packs (
  id          VARCHAR(64)  NOT NULL,
  name        VARCHAR(128) NOT NULL,
  owner_id    VARCHAR(64)  NOT NULL,
  couple_id   VARCHAR(64)  NOT NULL DEFAULT '',
  is_public   TINYINT      NOT NULL DEFAULT 0,
  is_system   TINYINT      NOT NULL DEFAULT 0,
  created_at  BIGINT       NOT NULL,
  updated_at  BIGINT       NOT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
                [
                    'id' => "VARCHAR(64) NOT NULL",
                    'name' => "VARCHAR(128) NOT NULL",
                    'owner_id' => "VARCHAR(64) NOT NULL",
                    'couple_id' => "VARCHAR(64) NOT NULL DEFAULT ''",
                    'is_public' => "TINYINT NOT NULL DEFAULT 0",
                    'is_system' => "TINYINT NOT NULL DEFAULT 0",
                    'created_at' => "BIGINT NOT NULL",
                    'updated_at' => "BIGINT NOT NULL",
                ],
                [
                    'idx_packs_couple' => ['couple_id'],
                    'idx_packs_owner' => ['owner_id'],
                ],
            ],

            'emoji_items' => [
                "CREATE TABLE IF NOT EXISTS emoji_items (
  id          VARCHAR(64)  NOT NULL,
  pack_id     VARCHAR(64)  NOT NULL,
  owner_id    VARCHAR(64)  NOT NULL,
  couple_id   VARCHAR(64)  NOT NULL DEFAULT '',
  mime        VARCHAR(64)  NOT NULL,
  size        BIGINT       NOT NULL DEFAULT 0,
  name        VARCHAR(191) NOT NULL DEFAULT '',
  created_at  BIGINT       NOT NULL,
  updated_at  BIGINT       NOT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
                [
                    'id' => "VARCHAR(64) NOT NULL",
                    'pack_id' => "VARCHAR(64) NOT NULL",
                    'owner_id' => "VARCHAR(64) NOT NULL",
                    'couple_id' => "VARCHAR(64) NOT NULL DEFAULT ''",
                    'mime' => "VARCHAR(64) NOT NULL",
                    'size' => "BIGINT NOT NULL DEFAULT 0",
                    'name' => "VARCHAR(191) NOT NULL DEFAULT ''",
                    'created_at' => "BIGINT NOT NULL",
                    'updated_at' => "BIGINT NOT NULL",
                ],
                [
                    'idx_items_pack' => ['pack_id'],
                ],
            ],

            'sync_objects' => [
                "CREATE TABLE IF NOT EXISTS sync_objects (
  couple_id   VARCHAR(64)  NOT NULL,
  kind        VARCHAR(32)  NOT NULL,
  id          VARCHAR(64)  NOT NULL,
  owner_id    VARCHAR(64)  NOT NULL,
  updated_by  VARCHAR(64)  NOT NULL,
  updated_at  BIGINT       NOT NULL,
  deleted     TINYINT      NOT NULL DEFAULT 0,
  payload     MEDIUMTEXT   NULL,
  created_at  BIGINT       NOT NULL,
  PRIMARY KEY (couple_id, kind, id),
  KEY idx_sync_pull (couple_id, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
                [
                    'couple_id' => "VARCHAR(64) NOT NULL",
                    'kind' => "VARCHAR(32) NOT NULL",
                    'id' => "VARCHAR(64) NOT NULL",
                    'owner_id' => "VARCHAR(64) NOT NULL",
                    'updated_by' => "VARCHAR(64) NOT NULL",
                    'updated_at' => "BIGINT NOT NULL",
                    'deleted' => "TINYINT NOT NULL DEFAULT 0",
                    'payload' => "MEDIUMTEXT NULL",
                    'created_at' => "BIGINT NOT NULL",
                ],
                [
                    'idx_sync_pull' => ['couple_id', 'updated_at'],
                ],
            ],

            'settings' => [
                // key 是 MySQL 保留字，必须反引号包裹
                "CREATE TABLE IF NOT EXISTS settings (
  `key`       VARCHAR(191) NOT NULL,
  `value`     LONGTEXT     NULL,
  updated_at  BIGINT       NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
                [
                    'key' => "VARCHAR(191) NOT NULL",
                    'value' => "LONGTEXT NULL",
                    'updated_at' => "BIGINT NOT NULL",
                ],
                [],
            ],

            'ai_usage' => [
                "CREATE TABLE IF NOT EXISTS ai_usage (
  id          VARCHAR(64) NOT NULL,
  user_id     VARCHAR(64) NULL,
  endpoint    VARCHAR(64) NOT NULL,
  model       VARCHAR(64) NULL,
  ok          TINYINT     NOT NULL DEFAULT 1,
  prompt_len  BIGINT      NOT NULL DEFAULT 0,
  created_at  BIGINT      NOT NULL,
  day         VARCHAR(32) NOT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
                [
                    'id' => "VARCHAR(64) NOT NULL",
                    'user_id' => "VARCHAR(64) NULL",
                    'endpoint' => "VARCHAR(64) NOT NULL",
                    'model' => "VARCHAR(64) NULL",
                    'ok' => "TINYINT NOT NULL DEFAULT 1",
                    'prompt_len' => "BIGINT NOT NULL DEFAULT 0",
                    'created_at' => "BIGINT NOT NULL",
                    'day' => "VARCHAR(32) NOT NULL",
                ],
                [
                    'idx_ai_usage_day' => ['day', 'user_id'],
                ],
            ],

            'admin_logs' => [
                "CREATE TABLE IF NOT EXISTS admin_logs (
  id          VARCHAR(64) NOT NULL,
  action      VARCHAR(64) NOT NULL,
  detail      TEXT        NULL,
  ip          VARCHAR(64) NULL,
  created_at  BIGINT      NOT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
                [
                    'id' => "VARCHAR(64) NOT NULL",
                    'action' => "VARCHAR(64) NOT NULL",
                    'detail' => "TEXT NULL",
                    'ip' => "VARCHAR(64) NULL",
                    'created_at' => "BIGINT NOT NULL",
                ],
                [
                    'idx_admin_logs_time' => ['created_at'],
                ],
            ],

            'rate_limits' => [
                "CREATE TABLE IF NOT EXISTS rate_limits (
  `key`         VARCHAR(191) NOT NULL,
  window_start  BIGINT       NOT NULL,
  window_ms     BIGINT       NOT NULL,
  count         BIGINT       NOT NULL DEFAULT 0,
  prev_count    BIGINT       NOT NULL DEFAULT 0,
  prev_start    BIGINT       NOT NULL DEFAULT 0,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
                [
                    'key' => "VARCHAR(191) NOT NULL",
                    'window_start' => "BIGINT NOT NULL",
                    'window_ms' => "BIGINT NOT NULL",
                    'count' => "BIGINT NOT NULL DEFAULT 0",
                    'prev_count' => "BIGINT NOT NULL DEFAULT 0",
                    'prev_start' => "BIGINT NOT NULL DEFAULT 0",
                ],
                [],
            ],

            'ws_events' => [
                "CREATE TABLE IF NOT EXISTS ws_events (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  couple_id    VARCHAR(64) NULL,
  target_user  VARCHAR(64) NULL,
  except_user  VARCHAR(64) NULL,
  payload      MEDIUMTEXT  NOT NULL,
  created_at   BIGINT      NOT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
                [
                    'id' => "BIGINT UNSIGNED NOT NULL AUTO_INCREMENT",
                    'couple_id' => "VARCHAR(64) NULL",
                    'target_user' => "VARCHAR(64) NULL",
                    'except_user' => "VARCHAR(64) NULL",
                    'payload' => "MEDIUMTEXT NOT NULL",
                    'created_at' => "BIGINT NOT NULL",
                ],
                [
                    'idx_ws_events_couple' => ['couple_id'],
                ],
            ],
        ];
    }

    /** 初始化数据库连接、建表、迁移、写入默认设置（幂等，可重复调用任意次） */
    public static function init()
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }
        Config::ensureDirs();

        $pdo = Database::connect([
            'host' => Config::dbHost(),
            'port' => Config::dbPort(),
            'name' => Config::dbName(),
            'user' => Config::dbUser(),
            'pass' => Config::dbPass(),
            'charset' => Config::dbCharset(),
            'ssl_ca' => (string) Config::get('db_ssl_ca', ''),
        ], true);
        self::$pdo = $pdo;

        try {
            self::createSchema();
            self::migrate();
            self::seedDefaultSettings();
        } catch (PDOException $e) {
            self::$pdo = null;
            throw $e;
        }

        Log::info('数据库已就绪', [
            'dsn' => 'mysql://' . Config::dbHost() . ':' . Config::dbPort() . '/' . Config::dbName(),
            'server' => Database::serverVersion($pdo),
        ]);
        return $pdo;
    }

    /** 建表（CREATE TABLE IF NOT EXISTS，重复执行安全） */
    private static function createSchema()
    {
        foreach (self::tables() as $table => $def) {
            self::pdo()->exec($def[0]);
        }
    }

    /**
     * 轻量迁移：为旧库补齐后加的列/索引，并记录 schema_version。
     * 用 information_schema 检查（MySQL 没有 ADD COLUMN IF NOT EXISTS），因此完全幂等。
     */
    private static function migrate()
    {
        foreach (self::tables() as $table => $def) {
            $columns = isset($def[1]) ? $def[1] : [];
            $indexes = isset($def[2]) ? $def[2] : [];
            self::ensureColumns($table, $columns);
            self::ensureIndexes($table, $indexes);
        }
        // 只在版本号真的不同时才写库（避免每个请求都产生一次写操作）
        $current = self::getSetting('schema_version');
        if ((int) $current !== (int) Config::SCHEMA_VERSION) {
            self::setSetting('schema_version', Config::SCHEMA_VERSION);
        }
    }

    /** 补齐缺失的列（表名/列名/类型均为代码内常量，不来自用户输入） */
    private static function ensureColumns($table, array $columns)
    {
        $existing = self::tableColumns($table);
        foreach ($columns as $column => $definition) {
            if (isset($existing[strtolower($column)])) {
                continue;
            }
            self::pdo()->exec('ALTER TABLE `' . $table . '` ADD COLUMN `' . $column . '` ' . $definition);
            Log::info('数据库迁移：新增列', ['table' => $table, 'column' => $column]);
        }
    }

    /** 补齐缺失的索引 */
    private static function ensureIndexes($table, array $indexes)
    {
        if (count($indexes) === 0) {
            return;
        }
        $existing = self::tableIndexes($table);
        foreach ($indexes as $name => $cols) {
            if (isset($existing[strtolower($name)])) {
                continue;
            }
            $quoted = [];
            foreach ($cols as $c) {
                $quoted[] = '`' . $c . '`';
            }
            self::pdo()->exec('ALTER TABLE `' . $table . '` ADD INDEX `' . $name . '` (' . implode(', ', $quoted) . ')');
            Log::info('数据库迁移：新增索引', ['table' => $table, 'index' => $name]);
        }
    }

    /** 当前库中的表清单（小写表名 => 真实表名） */
    public static function tableColumns($table)
    {
        $rows = self::all(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table]
        );
        $out = [];
        foreach ($rows as $r) {
            $out[strtolower((string) $r['COLUMN_NAME'])] = (string) $r['COLUMN_NAME'];
        }
        return $out;
    }

    /** 当前表的索引清单（小写索引名 => 索引名） */
    public static function tableIndexes($table)
    {
        $rows = self::all(
            'SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table]
        );
        $out = [];
        foreach ($rows as $r) {
            $out[strtolower((string) $r['INDEX_NAME'])] = (string) $r['INDEX_NAME'];
        }
        return $out;
    }

    /** 库中是否已有服务端核心表（安装向导判断「是否已初始化」） */
    public static function hasCoreTables()
    {
        foreach (['users', 'couples', 'settings'] as $t) {
            if (self::scalar(
                'SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
                [$t]
            ) <= 0) {
                return false;
            }
        }
        return true;
    }

    /**
     * 首次启动写入默认 settings（已存在的键绝不被覆盖）。
     *
     * 用 INSERT IGNORE（MySQL/MariaDB 通用）而不是 ON DUPLICATE KEY UPDATE：
     * 后者在每次启动时都会把 value 写回默认值，会把后台改过的设置冲掉。
     */
    private static function seedDefaultSettings()
    {
        $now = Time::nowMs();
        $stmt = self::pdo()->prepare(
            'INSERT IGNORE INTO settings (`key`, `value`, updated_at) VALUES (?, ?, ?)'
        );
        foreach (Config::DEFAULTS as $key => $value) {
            $stmt->execute([$key, json_encode($value, JSON_UNESCAPED_UNICODE), $now]);
        }
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
        self::$pdo = null;
    }

    /** 数据库版本字符串（后台「服务器信息」区块展示用） */
    public static function serverVersion()
    {
        try {
            return Database::serverVersion(self::pdo());
        } catch (\Exception $e) {
            return '';
        }
    }

    /** 数据库类型（MySQL / MariaDB） */
    public static function serverEngine()
    {
        try {
            return Database::engineName(self::pdo());
        } catch (\Exception $e) {
            return '未知';
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
                     ON DUPLICATE KEY UPDATE
                       updated_by = VALUES(updated_by),
                       updated_at = VALUES(updated_at),
                       deleted    = VALUES(deleted),
                       payload    = VALUES(payload)',
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
        $row = self::one('SELECT `value` FROM settings WHERE `key` = ?', [$key]);
        if (!$row) {
            return null;
        }
        return self::parseJson($row['value']);
    }

    /** 读取全部设置 */
    public static function getAllSettings()
    {
        $out = [];
        foreach (self::all('SELECT `key`, `value` FROM settings') as $r) {
            $out[$r['key']] = self::parseJson($r['value']);
        }
        return $out;
    }

    public static function setSetting($key, $value)
    {
        self::run(
            'INSERT INTO settings (`key`, `value`, updated_at) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_at = VALUES(updated_at)',
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
                    'INSERT INTO settings (`key`, `value`, updated_at) VALUES (?, ?, ?)
                     ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_at = VALUES(updated_at)',
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

    /**
     * 磁盘占用（字节）：表情目录 + 数据库表数据/索引大小。
     *
     * MySQL 下「数据库文件」由服务端管理，这里用 information_schema 统计
     * 本库的数据+索引字节数（比 SQLite 单文件更准确）。
     */
    public static function getDiskUsage()
    {
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

        $dbBytes = 0;
        try {
            $dbBytes = (int) self::scalar(
                'SELECT COALESCE(SUM(DATA_LENGTH + INDEX_LENGTH), 0) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE()'
            );
        } catch (PDOException $e) {
            $dbBytes = 0;
        }

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
