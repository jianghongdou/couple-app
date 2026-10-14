<?php
/**
 * 数据库层：唯一接触 SQL 的地方（表结构 / 迁移 / 所有查询封装）。
 *
 * 移植自 server-php/src/Db.php，表结构与 SQL 逐条对齐；差异只在「连接与表名」：
 *   - 连接直接复用 WordPress 的 $wpdb（DB_NAME / DB_USER / DB_PASSWORD / DB_HOST 常量）
 *   - 自建表统一加 $wpdb->prefix 前缀（wp_couple_users / wp_couple_messages ...）
 *   - 建表/迁移放在插件激活钩子里，且使用 information_schema 检查缺失项，完全幂等
 *
 * 兼容性约定（与 server-php 一致）：
 *   - 只用 MySQL 5.7 / 8.0 与 MariaDB 10.x 都支持的语法
 *   - 不使用 JSON 类型（JSON 文本存 MEDIUMTEXT/LONGTEXT，应用层 json_encode/decode）
 *   - 不使用 CHECK 约束、窗口函数、CTE、JSON_TABLE
 *   - 时间戳一律 BIGINT 存毫秒，避免 DATETIME/TIMESTAMP 的时区坑
 *   - upsert 统一用 INSERT ... ON DUPLICATE KEY UPDATE
 *
 * @package CoupleSpace
 */

namespace CoupleSpace;

defined( 'ABSPATH' ) || exit;

/**
 * 数据访问层。
 */
class Db {

	/** 自建逻辑表名清单（不含 $wpdb->prefix）。 */
	const TABLES = array(
		'users',
		'couples',
		'messages',
		'emoji_packs',
		'emoji_items',
		'sync_objects',
		'settings',
		'ai_usage',
		'admin_logs',
		'rate_limits',
		'ai_partners',
		'ai_messages',
		'ai_providers',
	);

	/**
	 * 真实表名（带 $wpdb->prefix）。
	 *
	 * @param string $name 逻辑表名（users / couples / ...）。
	 * @return string
	 */
	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . COUPLE_SPACE_TABLE_PREFIX . $name;
	}

	/** @return string 当前库中已存在的自建表数量。 */
	public static function existing_table_count() {
		global $wpdb;
		$n = 0;
		foreach ( self::TABLES as $t ) {
			if ( self::table_exists( self::table( $t ) ) ) {
				$n++;
			}
		}
		return $n;
	}

	/**
	 * 表是否存在。
	 *
	 * @param string $table 真实表名。
	 * @return bool
	 */
	public static function table_exists( $table ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
				$wpdb->dbname,
				$table
			)
		);
		return ( (int) $found ) > 0;
	}

	// ------------------------------------------------------------------
	// 建表 DDL（{p} = 带前缀的表名，{pk} = WordPress 计算的主键自增列定义）
	// ------------------------------------------------------------------

	/**
	 * 表结构定义。
	 *
	 * 每一项：[建表 SQL, 期望列 => 定义, 期望索引 => 列清单]
	 * 「期望列/索引」单独列出来是为了让迁移能做到幂等：用 information_schema 查缺补漏，
	 * 而不是无脑 ALTER（MySQL 没有 ADD COLUMN IF NOT EXISTS）。
	 *
	 * @return array<string,array{0:string,1:array<string,string>,2:array<string,array<int,string>>}>
	 */
	public static function schema() {
		return array(
			'users'        => array(
				"CREATE TABLE IF NOT EXISTS {p} (
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
) ENGINE=InnoDB {charset}",
				array(
					'id'            => 'VARCHAR(64) NOT NULL',
					'username'      => 'VARCHAR(64) NOT NULL',
					'password_hash' => 'VARCHAR(255) NOT NULL',
					'nickname'      => 'TEXT NOT NULL',
					'avatar_emoji'  => "VARCHAR(64) NOT NULL DEFAULT ''",
					'couple_id'     => 'VARCHAR(64) NULL',
					'role'          => "VARCHAR(32) NOT NULL DEFAULT 'user'",
					'disabled'      => 'TINYINT NOT NULL DEFAULT 0',
					'created_at'    => 'BIGINT NOT NULL',
					'updated_at'    => 'BIGINT NOT NULL',
					'last_seen_at'  => 'BIGINT NULL',
				),
				array(
					'idx_users_couple' => array( 'couple_id' ),
				),
			),
			'couples'      => array(
				"CREATE TABLE IF NOT EXISTS {p} (
  id                VARCHAR(64) NOT NULL,
  invite_code       VARCHAR(32) NOT NULL,
  together_date     BIGINT      NULL,
  anniversary_title VARCHAR(128) NOT NULL DEFAULT '在一起',
  created_at        BIGINT      NOT NULL,
  updated_at        BIGINT      NOT NULL,
  dissolved_at      BIGINT      NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_couples_invite (invite_code)
) ENGINE=InnoDB {charset}",
				array(
					'id'                => 'VARCHAR(64) NOT NULL',
					'invite_code'       => 'VARCHAR(32) NOT NULL',
					'together_date'     => 'BIGINT NULL',
					'anniversary_title' => "VARCHAR(128) NOT NULL DEFAULT '在一起'",
					'created_at'        => 'BIGINT NOT NULL',
					'updated_at'        => 'BIGINT NOT NULL',
					'dissolved_at'      => 'BIGINT NULL',
				),
				array(
					'idx_couples_active' => array( 'dissolved_at' ),
				),
			),
			'messages'     => array(
				"CREATE TABLE IF NOT EXISTS {p} (
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
) ENGINE=InnoDB {charset}",
				array(
					'id'         => 'VARCHAR(64) NOT NULL',
					'client_id'  => 'VARCHAR(64) NULL',
					'couple_id'  => 'VARCHAR(64) NOT NULL',
					'sender_id'  => 'VARCHAR(64) NOT NULL',
					'kind'       => 'VARCHAR(32) NOT NULL',
					'body'       => 'TEXT NOT NULL',
					'meta'       => 'MEDIUMTEXT NULL',
					'created_at' => 'BIGINT NOT NULL',
					'read_at'    => 'BIGINT NULL',
					'deleted_at' => 'BIGINT NULL',
				),
				array(
					'idx_messages_client'      => array( 'couple_id', 'client_id' ),
					'idx_messages_couple_time' => array( 'couple_id', 'created_at' ),
				),
			),
			'emoji_packs'  => array(
				"CREATE TABLE IF NOT EXISTS {p} (
  id          VARCHAR(64)  NOT NULL,
  name        VARCHAR(128) NOT NULL,
  owner_id    VARCHAR(64)  NOT NULL,
  couple_id   VARCHAR(64)  NOT NULL DEFAULT '',
  is_public   TINYINT      NOT NULL DEFAULT 0,
  is_system   TINYINT      NOT NULL DEFAULT 0,
  created_at  BIGINT       NOT NULL,
  updated_at  BIGINT       NOT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB {charset}",
				array(
					'id'         => 'VARCHAR(64) NOT NULL',
					'name'       => 'VARCHAR(128) NOT NULL',
					'owner_id'   => 'VARCHAR(64) NOT NULL',
					'couple_id'  => "VARCHAR(64) NOT NULL DEFAULT ''",
					'is_public'  => 'TINYINT NOT NULL DEFAULT 0',
					'is_system'  => 'TINYINT NOT NULL DEFAULT 0',
					'created_at' => 'BIGINT NOT NULL',
					'updated_at' => 'BIGINT NOT NULL',
				),
				array(
					'idx_packs_couple' => array( 'couple_id' ),
					'idx_packs_owner'  => array( 'owner_id' ),
				),
			),
			'emoji_items'  => array(
				"CREATE TABLE IF NOT EXISTS {p} (
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
) ENGINE=InnoDB {charset}",
				array(
					'id'         => 'VARCHAR(64) NOT NULL',
					'pack_id'    => 'VARCHAR(64) NOT NULL',
					'owner_id'   => 'VARCHAR(64) NOT NULL',
					'couple_id'  => "VARCHAR(64) NOT NULL DEFAULT ''",
					'mime'       => 'VARCHAR(64) NOT NULL',
					'size'       => 'BIGINT NOT NULL DEFAULT 0',
					'name'       => "VARCHAR(191) NOT NULL DEFAULT ''",
					'created_at' => 'BIGINT NOT NULL',
					'updated_at' => 'BIGINT NOT NULL',
				),
				array(
					'idx_items_pack' => array( 'pack_id' ),
				),
			),
			'sync_objects' => array(
				"CREATE TABLE IF NOT EXISTS {p} (
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
) ENGINE=InnoDB {charset}",
				array(
					'couple_id'  => 'VARCHAR(64) NOT NULL',
					'kind'       => 'VARCHAR(32) NOT NULL',
					'id'         => 'VARCHAR(64) NOT NULL',
					'owner_id'   => 'VARCHAR(64) NOT NULL',
					'updated_by' => 'VARCHAR(64) NOT NULL',
					'updated_at' => 'BIGINT NOT NULL',
					'deleted'    => 'TINYINT NOT NULL DEFAULT 0',
					'payload'    => 'MEDIUMTEXT NULL',
					'created_at' => 'BIGINT NOT NULL',
				),
				array(
					'idx_sync_pull' => array( 'couple_id', 'updated_at' ),
				),
			),
			'settings'     => array(
				// key 是 MySQL 保留字，必须反引号包裹。
				'CREATE TABLE IF NOT EXISTS {p} (
  `key`       VARCHAR(191) NOT NULL,
  `value`     LONGTEXT     NULL,
  updated_at  BIGINT       NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB {charset}',
				array(
					'key'        => 'VARCHAR(191) NOT NULL',
					'value'      => 'LONGTEXT NULL',
					'updated_at' => 'BIGINT NOT NULL',
				),
				array(),
			),
			'ai_usage'     => array(
				"CREATE TABLE IF NOT EXISTS {p} (
  id          VARCHAR(64) NOT NULL,
  user_id     VARCHAR(64) NULL,
  endpoint    VARCHAR(64) NOT NULL,
  model       VARCHAR(64) NULL,
  ok          TINYINT     NOT NULL DEFAULT 1,
  prompt_len  BIGINT      NOT NULL DEFAULT 0,
  created_at  BIGINT      NOT NULL,
  day         VARCHAR(32) NOT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB {charset}",
				array(
					'id'         => 'VARCHAR(64) NOT NULL',
					'user_id'    => 'VARCHAR(64) NULL',
					'endpoint'   => 'VARCHAR(64) NOT NULL',
					'model'      => 'VARCHAR(64) NULL',
					'ok'         => 'TINYINT NOT NULL DEFAULT 1',
					'prompt_len' => 'BIGINT NOT NULL DEFAULT 0',
					'created_at' => 'BIGINT NOT NULL',
					'day'        => 'VARCHAR(32) NOT NULL',
				),
				array(
					'idx_ai_usage_day' => array( 'day', 'user_id' ),
				),
			),
			'admin_logs'   => array(
				"CREATE TABLE IF NOT EXISTS {p} (
  id          VARCHAR(64) NOT NULL,
  action      VARCHAR(64) NOT NULL,
  detail      TEXT        NULL,
  ip          VARCHAR(64) NULL,
  created_at  BIGINT      NOT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB {charset}",
				array(
					'id'         => 'VARCHAR(64) NOT NULL',
					'action'     => 'VARCHAR(64) NOT NULL',
					'detail'     => 'TEXT NULL',
					'ip'         => 'VARCHAR(64) NULL',
					'created_at' => 'BIGINT NOT NULL',
				),
				array(
					'idx_admin_logs_time' => array( 'created_at' ),
				),
			),
			'rate_limits'  => array(
				'CREATE TABLE IF NOT EXISTS {p} (
  `key`         VARCHAR(191) NOT NULL,
  window_start  BIGINT       NOT NULL,
  window_ms     BIGINT       NOT NULL,
  count         BIGINT       NOT NULL DEFAULT 0,
  prev_count    BIGINT       NOT NULL DEFAULT 0,
  prev_start    BIGINT       NOT NULL DEFAULT 0,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB {charset}',
				array(
					'key'          => 'VARCHAR(191) NOT NULL',
					'window_start' => 'BIGINT NOT NULL',
					'window_ms'    => 'BIGINT NOT NULL',
					'count'        => 'BIGINT NOT NULL DEFAULT 0',
					'prev_count'   => 'BIGINT NOT NULL DEFAULT 0',
					'prev_start'   => 'BIGINT NOT NULL DEFAULT 0',
				),
				array(),
			),
			'ai_partners'  => array(
				// docs/API-AI聊天.md §AI-6。
				"CREATE TABLE IF NOT EXISTS {p} (
  id            VARCHAR(64)  NOT NULL,
  owner_id      VARCHAR(64)  NOT NULL,
  name          VARCHAR(128) NOT NULL,
  avatar_emoji  VARCHAR(64)  NOT NULL DEFAULT '🤖',
  persona       TEXT         NOT NULL,
  provider_id   VARCHAR(64)  NOT NULL DEFAULT '',
  model         VARCHAR(64)  NOT NULL DEFAULT '',
  temperature   DECIMAL(4,2) NOT NULL DEFAULT 0.80,
  enabled       TINYINT      NOT NULL DEFAULT 1,
  created_at    BIGINT       NOT NULL,
  updated_at    BIGINT       NOT NULL,
  PRIMARY KEY (id),
  KEY idx_ai_partners_owner (owner_id)
) ENGINE=InnoDB {charset}",
				array(
					'id'           => 'VARCHAR(64) NOT NULL',
					'owner_id'     => 'VARCHAR(64) NOT NULL',
					'name'         => 'VARCHAR(128) NOT NULL',
					'avatar_emoji' => "VARCHAR(64) NOT NULL DEFAULT '🤖'",
					'persona'      => 'TEXT NOT NULL',
					'provider_id'  => "VARCHAR(64) NOT NULL DEFAULT ''",
					'model'        => "VARCHAR(64) NOT NULL DEFAULT ''",
					'temperature'  => 'DECIMAL(4,2) NOT NULL DEFAULT 0.80',
					'enabled'      => 'TINYINT NOT NULL DEFAULT 1',
					'created_at'   => 'BIGINT NOT NULL',
					'updated_at'   => 'BIGINT NOT NULL',
				),
				array(
					'idx_ai_partners_owner' => array( 'owner_id' ),
				),
			),
			'ai_messages'  => array(
				// client_id 只对用户消息有值（AI 回复存 NULL），
				// 因此 UNIQUE(owner_id, client_id) 只约束用户消息的幂等。
				"CREATE TABLE IF NOT EXISTS {p} (
  id          VARCHAR(64)  NOT NULL,
  partner_id  VARCHAR(64)  NOT NULL,
  owner_id    VARCHAR(64)  NOT NULL,
  sender      VARCHAR(16)  NOT NULL,
  body        TEXT         NOT NULL,
  client_id   VARCHAR(64)  NULL,
  created_at  BIGINT       NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ai_messages_client (owner_id, client_id),
  KEY idx_ai_messages_partner_time (partner_id, created_at),
  KEY idx_ai_messages_owner_client (owner_id, client_id)
) ENGINE=InnoDB {charset}",
				array(
					'id'         => 'VARCHAR(64) NOT NULL',
					'partner_id' => 'VARCHAR(64) NOT NULL',
					'owner_id'   => 'VARCHAR(64) NOT NULL',
					'sender'     => 'VARCHAR(16) NOT NULL',
					'body'       => 'TEXT NOT NULL',
					'client_id'  => 'VARCHAR(64) NULL',
					'created_at' => 'BIGINT NOT NULL',
				),
				array(
					'uq_ai_messages_client'          => array( 'owner_id', 'client_id' ),
					'idx_ai_messages_partner_time'   => array( 'partner_id', 'created_at' ),
					'idx_ai_messages_owner_client'   => array( 'owner_id', 'client_id' ),
				),
			),
			'ai_providers' => array(
				// api_key_enc 只存 AES-256-GCM 密文（Settings::encrypt_secret），任何接口只回掩码。
				"CREATE TABLE IF NOT EXISTS {p} (
  id            VARCHAR(64)   NOT NULL,
  name          VARCHAR(128)  NOT NULL,
  type          VARCHAR(32)   NOT NULL DEFAULT 'openai',
  base_url      VARCHAR(255)  NOT NULL DEFAULT '',
  api_key_enc   TEXT          NULL,
  models_json   MEDIUMTEXT    NULL,
  default_model VARCHAR(64)   NOT NULL DEFAULT '',
  enabled       TINYINT       NOT NULL DEFAULT 1,
  is_default    TINYINT       NOT NULL DEFAULT 0,
  created_at    BIGINT        NOT NULL,
  updated_at    BIGINT        NOT NULL,
  PRIMARY KEY (id),
  KEY idx_ai_providers_default (is_default)
) ENGINE=InnoDB {charset}",
				array(
					'id'            => 'VARCHAR(64) NOT NULL',
					'name'          => 'VARCHAR(128) NOT NULL',
					'type'          => "VARCHAR(32) NOT NULL DEFAULT 'openai'",
					'base_url'      => "VARCHAR(255) NOT NULL DEFAULT ''",
					'api_key_enc'   => 'TEXT NULL',
					'models_json'   => 'MEDIUMTEXT NULL',
					'default_model' => "VARCHAR(64) NOT NULL DEFAULT ''",
					'enabled'       => 'TINYINT NOT NULL DEFAULT 1',
					'is_default'    => 'TINYINT NOT NULL DEFAULT 0',
					'created_at'    => 'BIGINT NOT NULL',
					'updated_at'    => 'BIGINT NOT NULL',
				),
				array(
					'idx_ai_providers_default' => array( 'is_default' ),
				),
			),
		);
	}

	/**
	 * 建表 + 迁移 + 写入默认设置。**幂等**，可重复调用任意次（激活钩子与每次升级都调用）。
	 *
	 * @return array{tables:int,addedColumns:int,addedIndexes:int}
	 */
	public static function install() {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();

		$added_columns = 0;
		$added_indexes = 0;

		foreach ( self::schema() as $name => $def ) {
			$table = self::table( $name );
			/*
			 * {p} 替换的是「带前缀的表名」，占位符后面紧跟的字面量（users / couples / ...）
			 * 是为了让每张表的 DDL 读起来完整（否则 10 张表的语句会一模一样）。
			 * 因此这里替换完还要把 {p}<逻辑名> 整体换成真实表名。
			 */
			$sql   = str_replace(
				array( '{p}' . $name, '{p}', '{charset}' ),
				array( $table, $table, $charset ),
				$def[0]
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- 表结构 DDL，表名来自代码常量。
			$wpdb->query( $sql );

			$columns = isset( $def[1] ) ? $def[1] : array();
			$indexes = isset( $def[2] ) ? $def[2] : array();
			$added_columns += self::ensure_columns( $table, $columns );
			$added_indexes += self::ensure_indexes( $table, $indexes );
		}

		self::seed_default_settings();

		// 只在版本号真的不同时才写库（避免每个请求都产生一次写操作）。
		$current = self::get_setting( 'schema_version' );
		if ( (int) $current !== (int) Config::SCHEMA_VERSION ) {
			self::set_setting( 'schema_version', Config::SCHEMA_VERSION );
		}

		return array(
			'tables'       => count( self::schema() ),
			'addedColumns' => $added_columns,
			'addedIndexes' => $added_indexes,
		);
	}

	/**
	 * 补齐缺失的列（表名/列名/类型均为代码内常量，不来自用户输入）。
	 *
	 * @param string                $table   真实表名。
	 * @param array<string,string>  $columns 期望列。
	 * @return int 新增列数。
	 */
	private static function ensure_columns( $table, array $columns ) {
		global $wpdb;
		$existing = self::table_columns( $table );
		$added    = 0;
		foreach ( $columns as $column => $definition ) {
			if ( isset( $existing[ strtolower( $column ) ] ) ) {
				continue;
			}
			$sql = 'ALTER TABLE `' . $table . '` ADD COLUMN `' . $column . '` ' . $definition;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( $sql );
			$added++;
			Log::info( '数据库迁移：新增列', array( 'table' => $table, 'column' => $column ) );
		}
		return $added;
	}

	/**
	 * 补齐缺失的索引。
	 *
	 * @param string                          $table   真实表名。
	 * @param array<string,array<int,string>> $indexes 期望索引。
	 * @return int 新增索引数。
	 */
	private static function ensure_indexes( $table, array $indexes ) {
		global $wpdb;
		if ( 0 === count( $indexes ) ) {
			return 0;
		}
		$existing = self::table_indexes( $table );
		$added    = 0;
		foreach ( $indexes as $name => $cols ) {
			if ( isset( $existing[ strtolower( $name ) ] ) ) {
				continue;
			}
			$quoted = array();
			foreach ( $cols as $c ) {
				$quoted[] = '`' . $c . '`';
			}
			$sql = 'ALTER TABLE `' . $table . '` ADD INDEX `' . $name . '` (' . implode( ', ', $quoted ) . ')';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( $sql );
			$added++;
			Log::info( '数据库迁移：新增索引', array( 'table' => $table, 'index' => $name ) );
		}
		return $added;
	}

	/**
	 * 当前库中的表列清单（小写列名 => 真实列名）。
	 *
	 * @param string $table 真实表名。
	 * @return array<string,string>
	 */
	public static function table_columns( $table ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
				$wpdb->dbname,
				$table
			),
			ARRAY_A
		);
		$out = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $r ) {
				$out[ strtolower( (string) $r['COLUMN_NAME'] ) ] = (string) $r['COLUMN_NAME'];
			}
		}
		return $out;
	}

	/**
	 * 当前表的索引清单（小写索引名 => 索引名）。
	 *
	 * @param string $table 真实表名。
	 * @return array<string,string>
	 */
	public static function table_indexes( $table ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
				$wpdb->dbname,
				$table
			),
			ARRAY_A
		);
		$out = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $r ) {
				$out[ strtolower( (string) $r['INDEX_NAME'] ) ] = (string) $r['INDEX_NAME'];
			}
		}
		return $out;
	}

	/**
	 * 库中是否已有核心表（后台「服务器信息」判断是否已初始化）。
	 *
	 * @return bool
	 */
	public static function has_core_tables() {
		foreach ( array( 'users', 'couples', 'settings' ) as $t ) {
			if ( ! self::table_exists( self::table( $t ) ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * 首次启用写入默认 settings（已存在的键绝不被覆盖）。
	 *
	 * 用 INSERT IGNORE（MySQL/MariaDB 通用）而不是 ON DUPLICATE KEY UPDATE：
	 * 后者会在每次升级时把 value 写回默认值，会把后台改过的设置冲掉。
	 */
	private static function seed_default_settings() {
		global $wpdb;
		$table = self::table( 'settings' );
		$now   = Time::now_ms();
		foreach ( Config::DEFAULTS as $key => $value ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query(
				$wpdb->prepare(
					'INSERT IGNORE INTO `' . $table . '` (`key`, `value`, updated_at) VALUES (%s, %s, %d)',
					$key,
					wp_json_encode( $value, JSON_UNESCAPED_UNICODE ),
					$now
				)
			);
		}
	}

	// ------------------------------------------------------------------
	// 通用查询封装（全部走 $wpdb->prepare 绑定）
	// ------------------------------------------------------------------

	/**
	 * 执行查询并返回结果集。
	 *
	 * @param string             $sql    SQL（可含占位符）。
	 * @param array<int,mixed>   $params 绑定参数。
	 * @return array<int,array<string,mixed>>
	 */
	public static function all( $sql, array $params = array() ) {
		global $wpdb;
		$prepared = self::prepare( $sql, $params );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $prepared, ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * 执行查询并返回单行。
	 *
	 * @param string           $sql    SQL。
	 * @param array<int,mixed> $params 绑定参数。
	 * @return array<string,mixed>|null
	 */
	public static function one( $sql, array $params = array() ) {
		global $wpdb;
		$prepared = self::prepare( $sql, $params );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row( $prepared, ARRAY_A );
		return ( null === $row ) ? null : $row;
	}

	/**
	 * 执行查询并返回单个标量。
	 *
	 * @param string           $sql    SQL。
	 * @param array<int,mixed> $params 绑定参数。
	 * @return mixed
	 */
	public static function scalar( $sql, array $params = array() ) {
		global $wpdb;
		$prepared = self::prepare( $sql, $params );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_var( $prepared );
	}

	/**
	 * 计数查询。
	 *
	 * @param string           $sql    SQL。
	 * @param array<int,mixed> $params 绑定参数。
	 * @return int
	 */
	public static function count( $sql, array $params = array() ) {
		return (int) self::scalar( $sql, $params );
	}

	/**
	 * 执行写操作。
	 *
	 * @param string           $sql    SQL。
	 * @param array<int,mixed> $params 绑定参数。
	 * @return int|false 影响行数 / false。
	 */
	public static function run( $sql, array $params = array() ) {
		global $wpdb;
		$prepared = self::prepare( $sql, $params );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->query( $prepared );
	}

	/**
	 * 预处理 SQL：有参数时走 $wpdb->prepare，无参数时原样返回。
	 *
	 * @param string           $sql    SQL。
	 * @param array<int,mixed> $params 绑定参数。
	 * @return string
	 */
	private static function prepare( $sql, array $params ) {
		global $wpdb;
		if ( 0 === count( $params ) ) {
			return $sql;
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- 由 $wpdb->prepare 完成绑定。
		return $wpdb->prepare( $sql, $params );
	}

	/**
	 * 最近一次数据库错误信息（排查用）。
	 *
	 * @return string
	 */
	public static function last_error() {
		global $wpdb;
		return (string) $wpdb->last_error;
	}

	/**
	 * 判断异常/错误是否为唯一键冲突（用于幂等处理）。
	 *
	 * @param string $message 错误文本。
	 * @return bool
	 */
	public static function is_conflict_error( $message ) {
		$m = strtolower( (string) $message );
		return false !== strpos( $m, 'duplicate entry' )
			|| false !== strpos( $m, 'unique constraint' )
			|| false !== strpos( $m, 'duplicate' )
			|| false !== strpos( $m, 'constraint' );
	}

	// ------------------------------------------------------------------
	// 行 -> API 对象 的映射
	// ------------------------------------------------------------------

	/**
	 * user 行转 API 对象（password_hash 绝不外泄）。
	 *
	 * @param array<string,mixed>|null $row 数据库行。
	 * @return array<string,mixed>|null
	 */
	public static function map_user( $row ) {
		if ( ! $row ) {
			return null;
		}
		return array(
			'id'          => (string) $row['id'],
			'username'    => (string) $row['username'],
			'nickname'    => isset( $row['nickname'] ) ? (string) $row['nickname'] : '',
			'avatarEmoji' => isset( $row['avatar_emoji'] ) ? (string) $row['avatar_emoji'] : '',
			'coupleId'    => ( isset( $row['couple_id'] ) && null !== $row['couple_id'] ) ? (string) $row['couple_id'] : null,
			'role'        => isset( $row['role'] ) ? (string) $row['role'] : 'user',
			'disabled'    => ! empty( $row['disabled'] ),
			'createdAt'   => (int) $row['created_at'],
		);
	}

	/**
	 * @param array<string,mixed>|null $row     couples 行。
	 * @param array<int,mixed>         $members 成员数组。
	 * @return array<string,mixed>|null
	 */
	private static function map_couple_row( $row, array $members ) {
		if ( ! $row ) {
			return null;
		}
		return array(
			'id'               => (string) $row['id'],
			'inviteCode'       => (string) $row['invite_code'],
			'togetherDate'     => ( isset( $row['together_date'] ) && null !== $row['together_date'] ) ? (int) $row['together_date'] : null,
			'anniversaryTitle' => isset( $row['anniversary_title'] ) ? (string) $row['anniversary_title'] : '在一起',
			'members'          => $members,
			'createdAt'        => (int) $row['created_at'],
			'updatedAt'        => (int) $row['updated_at'],
		);
	}

	/**
	 * @param array<string,mixed>|null $row messages 行。
	 * @return array<string,mixed>|null
	 */
	public static function map_message( $row ) {
		if ( ! $row ) {
			return null;
		}
		return array(
			'id'        => (string) $row['id'],
			'clientId'  => ( isset( $row['client_id'] ) && null !== $row['client_id'] ) ? (string) $row['client_id'] : null,
			'coupleId'  => (string) $row['couple_id'],
			'senderId'  => (string) $row['sender_id'],
			'kind'      => (string) $row['kind'],
			'body'      => isset( $row['body'] ) ? (string) $row['body'] : '',
			'meta'      => self::parse_json( isset( $row['meta'] ) ? $row['meta'] : null ),
			'createdAt' => (int) $row['created_at'],
			'readAt'    => ( isset( $row['read_at'] ) && null !== $row['read_at'] ) ? (int) $row['read_at'] : null,
		);
	}

	/**
	 * @param array<string,mixed>|null $row emoji_items 行。
	 * @return array<string,string|int>|null
	 */
	public static function map_emoji_item( $row ) {
		if ( ! $row ) {
			return null;
		}
		return array(
			'id'        => (string) $row['id'],
			'packId'    => (string) $row['pack_id'],
			'ownerId'   => (string) $row['owner_id'],
			'mime'      => (string) $row['mime'],
			'size'      => (int) $row['size'],
			'name'      => isset( $row['name'] ) ? (string) $row['name'] : '',
			'url'       => Config::emoji_url_prefix() . $row['id'],
			'createdAt' => (int) $row['created_at'],
			'updatedAt' => (int) $row['updated_at'],
		);
	}

	/**
	 * @param array<string,mixed>|null $row   emoji_packs 行。
	 * @param array<int,mixed>         $items 条目数组。
	 * @return array<string,mixed>|null
	 */
	private static function map_emoji_pack( $row, array $items ) {
		if ( ! $row ) {
			return null;
		}
		return array(
			'id'        => (string) $row['id'],
			'name'      => (string) $row['name'],
			'ownerId'   => (string) $row['owner_id'],
			'isPublic'  => ! empty( $row['is_public'] ),
			'createdAt' => (int) $row['created_at'],
			'updatedAt' => (int) $row['updated_at'],
			'items'     => $items,
		);
	}

	/**
	 * @param array<string,mixed>|null $row sync_objects 行。
	 * @return array<string,mixed>|null
	 */
	private static function map_sync_object( $row ) {
		if ( ! $row ) {
			return null;
		}
		return array(
			'kind'      => (string) $row['kind'],
			'id'        => (string) $row['id'],
			'updatedAt' => (int) $row['updated_at'],
			'deleted'   => ! empty( $row['deleted'] ),
			'ownerId'   => (string) $row['owner_id'],
			'updatedBy' => (string) $row['updated_by'],
			'payload'   => self::parse_json( isset( $row['payload'] ) ? $row['payload'] : null ),
		);
	}

	/**
	 * 安全解析数据库中的 JSON 文本（对象保持为 stdClass，便于原样回吐 {}）。
	 *
	 * @param mixed $text JSON 文本。
	 * @return mixed
	 */
	public static function parse_json( $text ) {
		if ( null === $text || '' === $text ) {
			return null;
		}
		$v = json_decode( (string) $text );
		return ( JSON_ERROR_NONE === json_last_error() ) ? $v : null;
	}

	// ------------------------------------------------------------------
	// users
	// ------------------------------------------------------------------

	/**
	 * @param string $id 用户 id。
	 * @return array{row:array<string,mixed>,user:array<string,mixed>}|null
	 */
	public static function get_user_by_id( $id ) {
		$row = self::one( 'SELECT * FROM `' . self::table( 'users' ) . '` WHERE id = %s', array( $id ) );
		return $row ? array(
			'row'  => $row,
			'user' => self::map_user( $row ),
		) : null;
	}

	/**
	 * @param string $username 用户名。
	 * @return array{row:array<string,mixed>,user:array<string,mixed>}|null
	 */
	public static function find_user_by_username( $username ) {
		$row = self::one( 'SELECT * FROM `' . self::table( 'users' ) . '` WHERE username = %s', array( $username ) );
		return $row ? array(
			'row'  => $row,
			'user' => self::map_user( $row ),
		) : null;
	}

	/**
	 * @param array<string,mixed> $data username / passwordHash / nickname / avatarEmoji。
	 * @return array{row:array<string,mixed>,user:array<string,mixed>}|null
	 */
	public static function create_user( array $data ) {
		$now = Time::now_ms();
		$id  = Ids::new_user_id();
		self::run(
			'INSERT INTO `' . self::table( 'users' ) . '` (id, username, password_hash, nickname, avatar_emoji, couple_id, role, disabled, created_at, updated_at)
			 VALUES (%s, %s, %s, %s, %s, NULL, %s, 0, %d, %d)',
			array(
				$id,
				$data['username'],
				$data['passwordHash'],
				$data['nickname'],
				isset( $data['avatarEmoji'] ) ? $data['avatarEmoji'] : '',
				'user',
				$now,
				$now,
			)
		);
		return self::get_user_by_id( $id );
	}

	/**
	 * 更新资料：只更新传入的字段。
	 *
	 * @param string              $id    用户 id。
	 * @param array<string,mixed> $patch 可含 nickname / avatarEmoji。
	 * @return array{row:array<string,mixed>,user:array<string,mixed>}|null
	 */
	public static function update_user_profile( $id, array $patch ) {
		$table = self::table( 'users' );
		$cur   = self::one( 'SELECT * FROM `' . $table . '` WHERE id = %s', array( $id ) );
		if ( ! $cur ) {
			return null;
		}
		$nickname = array_key_exists( 'nickname', $patch ) ? $patch['nickname'] : $cur['nickname'];
		$emoji    = array_key_exists( 'avatarEmoji', $patch ) ? $patch['avatarEmoji'] : $cur['avatar_emoji'];
		self::run(
			'UPDATE `' . $table . '` SET nickname = %s, avatar_emoji = %s, updated_at = %d WHERE id = %s',
			array( $nickname, $emoji, Time::now_ms(), $id )
		);
		return self::get_user_by_id( $id );
	}

	/**
	 * @param string $id           用户 id。
	 * @param string $password_hash bcrypt 哈希。
	 */
	public static function update_user_password( $id, $password_hash ) {
		self::run(
			'UPDATE `' . self::table( 'users' ) . '` SET password_hash = %s, updated_at = %d WHERE id = %s',
			array( $password_hash, Time::now_ms(), $id )
		);
	}

	/**
	 * @param string      $id        用户 id。
	 * @param string|null $couple_id 情侣空间 id（null 表示置空）。
	 */
	public static function set_user_couple( $id, $couple_id ) {
		$table = self::table( 'users' );
		if ( null === $couple_id ) {
			self::run( 'UPDATE `' . $table . '` SET couple_id = NULL, updated_at = %d WHERE id = %s', array( Time::now_ms(), $id ) );
			return;
		}
		self::run( 'UPDATE `' . $table . '` SET couple_id = %s, updated_at = %d WHERE id = %s', array( $couple_id, Time::now_ms(), $id ) );
	}

	/**
	 * @param string $id       用户 id。
	 * @param bool   $disabled 是否禁用。
	 */
	public static function set_user_disabled( $id, $disabled ) {
		self::run(
			'UPDATE `' . self::table( 'users' ) . '` SET disabled = %d, updated_at = %d WHERE id = %s',
			array( $disabled ? 1 : 0, Time::now_ms(), $id )
		);
	}

	/**
	 * @param string $id 用户 id。
	 */
	public static function touch_user_last_seen( $id ) {
		self::run( 'UPDATE `' . self::table( 'users' ) . '` SET last_seen_at = %d WHERE id = %s', array( Time::now_ms(), $id ) );
	}

	/**
	 * 删除用户（返回其原 coupleId，便于后续判断）。
	 *
	 * @param string $id 用户 id。
	 * @return string|null
	 */
	public static function delete_user( $id ) {
		global $wpdb;
		$row       = self::one( 'SELECT couple_id FROM `' . self::table( 'users' ) . '` WHERE id = %s', array( $id ) );
		$couple_id = ( $row && null !== $row['couple_id'] ) ? (string) $row['couple_id'] : null;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( 'START TRANSACTION' );
		try {
			self::run( 'DELETE FROM `' . self::table( 'emoji_items' ) . '` WHERE owner_id = %s', array( $id ) );
			self::run( 'DELETE FROM `' . self::table( 'emoji_packs' ) . '` WHERE owner_id = %s', array( $id ) );
			self::run( 'DELETE FROM `' . self::table( 'sync_objects' ) . '` WHERE owner_id = %s', array( $id ) );
			// 消息保留会话完整性，做匿名化处理。
			self::run(
				'UPDATE `' . self::table( 'messages' ) . '` SET sender_id = %s, body = %s WHERE sender_id = %s',
				array( 'u_deleted', '[用户已注销]', $id )
			);
			self::run( 'DELETE FROM `' . self::table( 'ai_usage' ) . '` WHERE user_id = %s', array( $id ) );
			self::run( 'DELETE FROM `' . self::table( 'users' ) . '` WHERE id = %s', array( $id ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( 'COMMIT' );
			return $couple_id;
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( 'ROLLBACK' );
			throw $e;
		}
	}

	/** @return int */
	public static function count_users() {
		return self::count( 'SELECT COUNT(*) AS n FROM `' . self::table( 'users' ) . '`' );
	}

	/**
	 * 后台用户列表（关键字搜索 + 分页）。
	 *
	 * @param array<string,mixed> $opts query / page / pageSize。
	 * @return array<string,mixed>
	 */
	public static function list_users( array $opts = array() ) {
		global $wpdb;
		$query     = isset( $opts['query'] ) ? (string) $opts['query'] : '';
		$page      = isset( $opts['page'] ) ? (int) $opts['page'] : 1;
		$page_size = isset( $opts['pageSize'] ) ? (int) $opts['pageSize'] : 20;
		$offset    = ( $page - 1 ) * $page_size;
		$table     = self::table( 'users' );

		$where  = '';
		$params = array();
		if ( '' !== $query ) {
			$where  = 'WHERE username LIKE %s OR nickname LIKE %s OR id LIKE %s';
			$like   = '%' . $wpdb->esc_like( $query ) . '%';
			$params = array( $like, $like, $like );
		}

		$total = self::count( 'SELECT COUNT(*) AS n FROM `' . $table . '` ' . $where, $params );
		$rows  = self::all(
			'SELECT * FROM `' . $table . '` ' . $where . ' ORDER BY created_at DESC LIMIT %d OFFSET %d',
			array_merge( $params, array( $page_size, $offset ) )
		);

		// 附带邀请码，方便后台查看。
		$couple_ids = array();
		foreach ( $rows as $r ) {
			if ( ! empty( $r['couple_id'] ) && ! in_array( $r['couple_id'], $couple_ids, true ) ) {
				$couple_ids[] = $r['couple_id'];
			}
		}
		$codes = array();
		if ( count( $couple_ids ) > 0 ) {
			$ph = implode( ', ', array_fill( 0, count( $couple_ids ), '%s' ) );
			foreach ( self::all( 'SELECT id, invite_code FROM `' . self::table( 'couples' ) . '` WHERE id IN (' . $ph . ')', $couple_ids ) as $c ) {
				$codes[ $c['id'] ] = $c['invite_code'];
			}
		}

		$users = array();
		foreach ( $rows as $r ) {
			$u               = self::map_user( $r );
			$u['inviteCode'] = ( ! empty( $r['couple_id'] ) && isset( $codes[ $r['couple_id'] ] ) ) ? $codes[ $r['couple_id'] ] : null;
			$u['lastSeenAt'] = ( isset( $r['last_seen_at'] ) && null !== $r['last_seen_at'] ) ? (int) $r['last_seen_at'] : null;
			$u['disabled']   = ! empty( $r['disabled'] );
			$users[]         = $u;
		}

		return array(
			'total'    => $total,
			'page'     => $page,
			'pageSize' => $page_size,
			'users'    => $users,
		);
	}

	// ------------------------------------------------------------------
	// couples
	// ------------------------------------------------------------------

	/**
	 * @param string $id 情侣空间 id。
	 * @return array<string,mixed>|null
	 */
	public static function get_couple_row( $id ) {
		return self::one( 'SELECT * FROM `' . self::table( 'couples' ) . '` WHERE id = %s', array( $id ) );
	}

	/**
	 * 组装完整 couple 对象（含 members 完整 user 数组）。
	 *
	 * @param string $id 情侣空间 id。
	 * @return array<string,mixed>|null
	 */
	public static function build_couple( $id ) {
		$row = self::get_couple_row( $id );
		if ( ! $row ) {
			return null;
		}
		$members = array();
		foreach ( self::all( 'SELECT * FROM `' . self::table( 'users' ) . '` WHERE couple_id = %s ORDER BY created_at ASC', array( $id ) ) as $r ) {
			$members[] = self::map_user( $r );
		}
		return self::map_couple_row( $row, $members );
	}

	/**
	 * @param string $invite_code 邀请码。
	 * @return array<string,mixed>|null
	 */
	public static function get_couple_by_invite_code( $invite_code ) {
		$row = self::one(
			'SELECT * FROM `' . self::table( 'couples' ) . '` WHERE invite_code = %s AND dissolved_at IS NULL',
			array( $invite_code )
		);
		return $row ? self::build_couple( $row['id'] ) : null;
	}

	/**
	 * @param string $couple_id 情侣空间 id。
	 * @return int
	 */
	public static function get_couple_member_count( $couple_id ) {
		return self::count( 'SELECT COUNT(*) AS n FROM `' . self::table( 'users' ) . '` WHERE couple_id = %s', array( $couple_id ) );
	}

	/**
	 * @param array<string,mixed> $opts anniversaryTitle。
	 * @return array<string,mixed>
	 */
	public static function create_couple( array $opts = array() ) {
		$title = isset( $opts['anniversaryTitle'] ) ? $opts['anniversaryTitle'] : '在一起';
		$now   = Time::now_ms();
		$id    = Ids::new_couple_id();
		$table = self::table( 'couples' );

		// 邀请码唯一性由随机 + 唯一索引保证，冲突时重试。
		for ( $attempt = 0; $attempt < 12; $attempt++ ) {
			$code   = Ids::new_invite_code();
			$exists = self::one( 'SELECT 1 FROM `' . $table . '` WHERE invite_code = %s', array( $code ) );
			if ( $exists ) {
				continue;
			}
			self::run(
				'INSERT INTO `' . $table . '` (id, invite_code, together_date, anniversary_title, created_at, updated_at)
				 VALUES (%s, %s, NULL, %s, %d, %d)',
				array( $id, $code, $title, $now, $now )
			);
			return self::build_couple( $id );
		}
		throw new \RuntimeException( '生成邀请码失败，请重试' );
	}

	/**
	 * 更新情侣空间：只更新传入字段（null 表示显式清空 togetherDate）。
	 *
	 * @param string              $id    情侣空间 id。
	 * @param array<string,mixed> $patch togetherDate / anniversaryTitle。
	 * @return array<string,mixed>|null
	 */
	public static function update_couple( $id, array $patch ) {
		$table = self::table( 'couples' );
		$cur   = self::get_couple_row( $id );
		if ( ! $cur ) {
			return null;
		}
		$date  = array_key_exists( 'togetherDate', $patch ) ? $patch['togetherDate'] : $cur['together_date'];
		$title = array_key_exists( 'anniversaryTitle', $patch ) ? $patch['anniversaryTitle'] : $cur['anniversary_title'];
		if ( null === $date ) {
			self::run(
				'UPDATE `' . $table . '` SET together_date = NULL, anniversary_title = %s, updated_at = %d WHERE id = %s',
				array( $title, Time::now_ms(), $id )
			);
		} else {
			self::run(
				'UPDATE `' . $table . '` SET together_date = %d, anniversary_title = %s, updated_at = %d WHERE id = %s',
				array( (int) $date, $title, Time::now_ms(), $id )
			);
		}
		return self::build_couple( $id );
	}

	/**
	 * 解除绑定：双方 coupleId 置空，couple 标记为已解散（数据保留 30 天）。
	 *
	 * @param string $couple_id 情侣空间 id。
	 * @return int 受影响的用户数。
	 */
	public static function dissolve_couple( $couple_id ) {
		global $wpdb;
		$now = Time::now_ms();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( 'START TRANSACTION' );
		try {
			self::run(
				'UPDATE `' . self::table( 'users' ) . '` SET couple_id = NULL, updated_at = %d WHERE couple_id = %s',
				array( $now, $couple_id )
			);
			$changes = (int) $wpdb->rows_affected;
			self::run(
				'UPDATE `' . self::table( 'couples' ) . '` SET dissolved_at = %d, updated_at = %d WHERE id = %s',
				array( $now, $now, $couple_id )
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( 'COMMIT' );
			return $changes;
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( 'ROLLBACK' );
			throw $e;
		}
	}

	/** @return int */
	public static function count_couples() {
		return self::count( 'SELECT COUNT(*) AS n FROM `' . self::table( 'couples' ) . '` WHERE dissolved_at IS NULL' );
	}

	/** @return int */
	public static function count_all_couples() {
		return self::count( 'SELECT COUNT(*) AS n FROM `' . self::table( 'couples' ) . '`' );
	}

	/**
	 * 清理超过保留期的已解散情侣数据（默认 30 天）。
	 *
	 * @param int $retention_days 保留天数。
	 * @return int 清理的情侣空间数。
	 */
	public static function purge_dissolved_couples( $retention_days = 30 ) {
		global $wpdb;
		$cutoff = Time::now_ms() - $retention_days * 86400000;
		$rows   = self::all( 'SELECT id FROM `' . self::table( 'couples' ) . '` WHERE dissolved_at IS NOT NULL AND dissolved_at < %d', array( $cutoff ) );
		if ( 0 === count( $rows ) ) {
			return 0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( 'START TRANSACTION' );
		try {
			foreach ( $rows as $r ) {
				$id = $r['id'];
				self::run( 'DELETE FROM `' . self::table( 'messages' ) . '` WHERE couple_id = %s', array( $id ) );
				self::run( 'DELETE FROM `' . self::table( 'sync_objects' ) . '` WHERE couple_id = %s', array( $id ) );
				self::run( 'DELETE FROM `' . self::table( 'emoji_items' ) . '` WHERE couple_id = %s', array( $id ) );
				self::run( 'DELETE FROM `' . self::table( 'emoji_packs' ) . '` WHERE couple_id = %s AND is_system = 0', array( $id ) );
				self::run( 'DELETE FROM `' . self::table( 'couples' ) . '` WHERE id = %s', array( $id ) );
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( 'COMMIT' );
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( 'ROLLBACK' );
			throw $e;
		}
		Log::info( '已清理过期情侣数据', array( 'count' => count( $rows ), 'retentionDays' => $retention_days ) );
		return count( $rows );
	}

	// ------------------------------------------------------------------
	// messages
	// ------------------------------------------------------------------

	/**
	 * @param array<string,mixed> $data clientId / coupleId / senderId / kind / body / meta。
	 * @return array<string,mixed>|null
	 */
	public static function insert_message( array $data ) {
		$now  = Time::now_ms();
		$id   = Ids::new_message_id();
		$meta = ( array_key_exists( 'meta', $data ) && null !== $data['meta'] )
			? wp_json_encode( $data['meta'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
			: null;
		self::run(
			'INSERT INTO `' . self::table( 'messages' ) . '` (id, client_id, couple_id, sender_id, kind, body, meta, created_at, read_at)
			 VALUES (%s, %s, %s, %s, %s, %s, %s, %d, NULL)',
			array(
				$id,
				isset( $data['clientId'] ) ? $data['clientId'] : null,
				$data['coupleId'],
				$data['senderId'],
				$data['kind'],
				isset( $data['body'] ) ? $data['body'] : '',
				$meta,
				$now,
			)
		);
		$row = self::one( 'SELECT * FROM `' . self::table( 'messages' ) . '` WHERE id = %s', array( $id ) );
		return self::map_message( $row );
	}

	/**
	 * @param string $couple_id 情侣空间 id。
	 * @param string $client_id 客户端幂等 id。
	 * @return array<string,mixed>|null
	 */
	public static function find_message_by_client_id( $couple_id, $client_id ) {
		$row = self::one(
			'SELECT * FROM `' . self::table( 'messages' ) . '` WHERE couple_id = %s AND client_id = %s',
			array( $couple_id, $client_id )
		);
		return $row ? self::map_message( $row ) : null;
	}

	/**
	 * @param string $id 消息 id。
	 * @return array{row:array<string,mixed>,message:array<string,mixed>}|null
	 */
	public static function get_message( $id ) {
		$row = self::one( 'SELECT * FROM `' . self::table( 'messages' ) . '` WHERE id = %s', array( $id ) );
		return $row ? array(
			'row'     => $row,
			'message' => self::map_message( $row ),
		) : null;
	}

	/**
	 * 拉取消息：不传 before 取最新 limit 条，返回按 createdAt 升序。
	 *
	 * @param array<string,mixed> $opts coupleId / limit / before。
	 * @return array{messages:array<int,mixed>,hasMore:bool}
	 */
	public static function list_messages( array $opts ) {
		$table  = self::table( 'messages' );
		$limit  = (int) $opts['limit'];
		$before = array_key_exists( 'before', $opts ) ? $opts['before'] : null;

		if ( null === $before ) {
			$rows = self::all(
				'SELECT * FROM `' . $table . '` WHERE couple_id = %s ORDER BY created_at DESC, id DESC LIMIT %d',
				array( $opts['coupleId'], $limit + 1 )
			);
		} else {
			$rows = self::all(
				'SELECT * FROM `' . $table . '` WHERE couple_id = %s AND created_at < %d ORDER BY created_at DESC, id DESC LIMIT %d',
				array( $opts['coupleId'], (int) $before, $limit + 1 )
			);
		}

		$has_more = count( $rows ) > $limit;
		$page     = array_slice( $rows, 0, $limit );
		$page     = array_reverse( $page );
		$messages = array();
		foreach ( $page as $r ) {
			$messages[] = self::map_message( $r );
		}
		return array(
			'messages' => $messages,
			'hasMore'  => $has_more,
		);
	}

	/**
	 * 标记已读：把 createdAt <= upTo 且对方发来的消息标为已读。
	 *
	 * @param string $couple_id 情侣空间 id。
	 * @param string $user_id   当前用户。
	 * @param int    $up_to     截止毫秒时间戳。
	 * @return int
	 */
	public static function mark_messages_read( $couple_id, $user_id, $up_to ) {
		global $wpdb;
		self::run(
			'UPDATE `' . self::table( 'messages' ) . '` SET read_at = %d
			 WHERE couple_id = %s AND sender_id != %s AND created_at <= %d AND read_at IS NULL AND deleted_at IS NULL',
			array( Time::now_ms(), $couple_id, $user_id, $up_to )
		);
		return (int) $wpdb->rows_affected;
	}

	/**
	 * 撤回消息（仅自己、24 小时内）。
	 *
	 * @param string $id 消息 id。
	 * @return bool
	 */
	public static function recall_message( $id ) {
		global $wpdb;
		self::run( 'UPDATE `' . self::table( 'messages' ) . '` SET deleted_at = %d WHERE id = %s', array( Time::now_ms(), $id ) );
		return ( (int) $wpdb->rows_affected ) > 0;
	}

	/** @return int */
	public static function count_messages() {
		return self::count( 'SELECT COUNT(*) AS n FROM `' . self::table( 'messages' ) . '`' );
	}

	/**
	 * 某用户当天已发消息数（用于 maxMessagesPerDay 限制）。
	 *
	 * @param string $user_id 用户 id。
	 * @return int
	 */
	public static function count_messages_today_by_user( $user_id ) {
		return self::count(
			'SELECT COUNT(*) AS n FROM `' . self::table( 'messages' ) . '` WHERE sender_id = %s AND created_at >= %d',
			array( $user_id, Time::today_start_ms() )
		);
	}

	/**
	 * 更新情侣空间活跃时间。
	 *
	 * @param string $couple_id 情侣空间 id。
	 */
	public static function touch_couple( $couple_id ) {
		self::run( 'UPDATE `' . self::table( 'couples' ) . '` SET updated_at = %d WHERE id = %s', array( Time::now_ms(), $couple_id ) );
	}

	// ------------------------------------------------------------------
	// emoji_packs / emoji_items
	// ------------------------------------------------------------------

	/**
	 * 该情侣空间已创建的自建包数量（不含公共包）。
	 *
	 * @param string $couple_id 情侣空间 id。
	 * @return int
	 */
	public static function count_own_packs( $couple_id ) {
		return self::count(
			'SELECT COUNT(*) AS n FROM `' . self::table( 'emoji_packs' ) . '` WHERE couple_id = %s AND is_public = 0',
			array( $couple_id )
		);
	}

	/**
	 * @param array<string,mixed> $data id / name / ownerId / coupleId / isPublic / isSystem。
	 * @return array<string,mixed>|null
	 */
	public static function create_pack( array $data ) {
		$now     = Time::now_ms();
		$pack_id = ( isset( $data['id'] ) && $data['id'] ) ? $data['id'] : Ids::new_pack_id();
		self::run(
			'INSERT INTO `' . self::table( 'emoji_packs' ) . '` (id, name, owner_id, couple_id, is_public, is_system, created_at, updated_at)
			 VALUES (%s, %s, %s, %s, %d, %d, %d, %d)',
			array(
				$pack_id,
				$data['name'],
				$data['ownerId'],
				$data['coupleId'],
				! empty( $data['isPublic'] ) ? 1 : 0,
				! empty( $data['isSystem'] ) ? 1 : 0,
				$now,
				$now,
			)
		);
		return self::get_pack_row( $pack_id );
	}

	/**
	 * @param string $pack_id 表情包 id。
	 * @return array<string,mixed>|null
	 */
	public static function get_pack_row( $pack_id ) {
		return self::one( 'SELECT * FROM `' . self::table( 'emoji_packs' ) . '` WHERE id = %s', array( $pack_id ) );
	}

	/**
	 * @param string $pack_id 表情包 id。
	 * @return int
	 */
	public static function delete_pack( $pack_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( 'START TRANSACTION' );
		try {
			self::run( 'DELETE FROM `' . self::table( 'emoji_items' ) . '` WHERE pack_id = %s', array( $pack_id ) );
			self::run( 'DELETE FROM `' . self::table( 'emoji_packs' ) . '` WHERE id = %s', array( $pack_id ) );
			$changes = (int) $wpdb->rows_affected;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( 'COMMIT' );
			return $changes;
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( 'ROLLBACK' );
			throw $e;
		}
	}

	/**
	 * 列出情侣空间可见的表情包（自己 + 伴侣 + 公共），含 items。
	 *
	 * @param string $couple_id 情侣空间 id。
	 * @return array<int,mixed>
	 */
	public static function list_packs_for_couple( $couple_id ) {
		$rows = self::all(
			'SELECT * FROM `' . self::table( 'emoji_packs' ) . '` WHERE is_public = 1 OR couple_id = %s ORDER BY is_public DESC, created_at ASC',
			array( $couple_id )
		);
		$result = array();
		foreach ( $rows as $row ) {
			$items = array();
			foreach ( self::all( 'SELECT * FROM `' . self::table( 'emoji_items' ) . '` WHERE pack_id = %s ORDER BY created_at ASC', array( $row['id'] ) ) as $it ) {
				$items[] = self::map_emoji_item( $it );
			}
			$result[] = self::map_emoji_pack( $row, $items );
		}
		return $result;
	}

	/**
	 * @param string $pack_id 表情包 id。
	 * @return array<string,mixed>|null
	 */
	public static function get_pack_with_items( $pack_id ) {
		$row = self::get_pack_row( $pack_id );
		if ( ! $row ) {
			return null;
		}
		$items = array();
		foreach ( self::all( 'SELECT * FROM `' . self::table( 'emoji_items' ) . '` WHERE pack_id = %s ORDER BY created_at ASC', array( $pack_id ) ) as $it ) {
			$items[] = self::map_emoji_item( $it );
		}
		return self::map_emoji_pack( $row, $items );
	}

	/**
	 * @param string $pack_id 表情包 id。
	 * @return int
	 */
	public static function count_items_in_pack( $pack_id ) {
		return self::count( 'SELECT COUNT(*) AS n FROM `' . self::table( 'emoji_items' ) . '` WHERE pack_id = %s', array( $pack_id ) );
	}

	/**
	 * @param array<string,mixed> $data id / packId / ownerId / coupleId / mime / size / name。
	 * @return array<string,mixed>|null
	 */
	public static function insert_emoji_item( array $data ) {
		$now     = Time::now_ms();
		$item_id = ( isset( $data['id'] ) && $data['id'] ) ? $data['id'] : Ids::new_emoji_id();
		self::run(
			'INSERT INTO `' . self::table( 'emoji_items' ) . '` (id, pack_id, owner_id, couple_id, mime, size, name, created_at, updated_at)
			 VALUES (%s, %s, %s, %s, %s, %d, %s, %d, %d)',
			array(
				$item_id,
				$data['packId'],
				$data['ownerId'],
				$data['coupleId'],
				$data['mime'],
				(int) $data['size'],
				isset( $data['name'] ) ? $data['name'] : '',
				$now,
				$now,
			)
		);
		self::run( 'UPDATE `' . self::table( 'emoji_packs' ) . '` SET updated_at = %d WHERE id = %s', array( $now, $data['packId'] ) );
		return self::one( 'SELECT * FROM `' . self::table( 'emoji_items' ) . '` WHERE id = %s', array( $item_id ) );
	}

	/**
	 * @param string $item_id 表情 id。
	 * @return array{row:array<string,mixed>,item:array<string,mixed>}|null
	 */
	public static function get_emoji_item( $item_id ) {
		$row = self::one( 'SELECT * FROM `' . self::table( 'emoji_items' ) . '` WHERE id = %s', array( $item_id ) );
		return $row ? array(
			'row'  => $row,
			'item' => self::map_emoji_item( $row ),
		) : null;
	}

	/**
	 * 删除单张表情的数据库记录（磁盘文件由调用方清理）。
	 *
	 * @param string $item_id 表情 id。
	 * @return bool
	 */
	public static function delete_emoji_item( $item_id ) {
		global $wpdb;
		$table = self::table( 'emoji_items' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( 'START TRANSACTION' );
		try {
			$row = self::one( 'SELECT pack_id FROM `' . $table . '` WHERE id = %s', array( $item_id ) );
			if ( ! $row ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->query( 'COMMIT' );
				return false;
			}
			self::run( 'DELETE FROM `' . $table . '` WHERE id = %s', array( $item_id ) );
			$changes = (int) $wpdb->rows_affected;
			self::run(
				'UPDATE `' . self::table( 'emoji_packs' ) . '` SET updated_at = %d WHERE id = %s',
				array( Time::now_ms(), $row['pack_id'] )
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( 'COMMIT' );
			return $changes > 0;
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( 'ROLLBACK' );
			throw $e;
		}
	}

	/** @return int */
	public static function count_emoji_packs() {
		return self::count( 'SELECT COUNT(*) AS n FROM `' . self::table( 'emoji_packs' ) . '`' );
	}

	/** @return int */
	public static function count_emoji_items() {
		return self::count( 'SELECT COUNT(*) AS n FROM `' . self::table( 'emoji_items' ) . '`' );
	}

	// ------------------------------------------------------------------
	// sync_objects
	// ------------------------------------------------------------------

	/**
	 * 写入同步对象（LWW：仅当本次 updatedAt >= 服务端版本才接受），事务内完成。
	 *
	 * @param array<string,mixed> $opts coupleId / userId / objects。
	 * @return array{applied:array<int,string>,conflicts:array<int,string>}
	 */
	public static function push_sync_objects( array $opts ) {
		global $wpdb;
		$couple_id = $opts['coupleId'];
		$user_id   = $opts['userId'];
		$objects   = $opts['objects'];
		$now       = Time::now_ms();
		$applied   = array();
		$conflicts = array();
		$table     = self::table( 'sync_objects' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( 'START TRANSACTION' );
		try {
			foreach ( $objects as $obj ) {
				$existing = self::one(
					'SELECT updated_at FROM `' . $table . '` WHERE couple_id = %s AND kind = %s AND id = %s',
					array( $couple_id, $obj['kind'], $obj['id'] )
				);
				if ( $existing && (int) $existing['updated_at'] > (int) $obj['updatedAt'] ) {
					// 服务端版本更新 -> 拒绝写入，返回冲突。
					$conflicts[] = $obj['id'];
					continue;
				}
				$payload = ( array_key_exists( 'payload', $obj ) && null !== $obj['payload'] )
					? wp_json_encode( $obj['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
					: null;
				self::run(
					'INSERT INTO `' . $table . '` (couple_id, kind, id, owner_id, updated_by, updated_at, deleted, payload, created_at)
					 VALUES (%s, %s, %s, %s, %s, %d, %d, %s, %d)
					 ON DUPLICATE KEY UPDATE
					   updated_by = VALUES(updated_by),
					   updated_at = VALUES(updated_at),
					   deleted    = VALUES(deleted),
					   payload    = VALUES(payload)',
					array(
						$couple_id,
						$obj['kind'],
						$obj['id'],
						$user_id,
						$user_id,
						(int) $obj['updatedAt'],
						! empty( $obj['deleted'] ) ? 1 : 0,
						$payload,
						$now,
					)
				);
				$applied[] = $obj['id'];
			}
			self::run( 'UPDATE `' . self::table( 'couples' ) . '` SET updated_at = %d WHERE id = %s', array( $now, $couple_id ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( 'COMMIT' );
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( 'ROLLBACK' );
			throw $e;
		}

		return array(
			'applied'   => $applied,
			'conflicts' => $conflicts,
		);
	}

	/**
	 * 拉取同步对象（updatedAt > since）。
	 *
	 * @param array<string,mixed> $opts coupleId / since / kinds / limit。
	 * @return array{objects:array<int,mixed>,hasMore:bool}
	 */
	public static function pull_sync_objects( array $opts ) {
		$kinds = $opts['kinds'];
		$limit = (int) $opts['limit'];
		$ph    = implode( ', ', array_fill( 0, count( $kinds ), '%s' ) );
		$params = array_merge( array( $opts['coupleId'], (int) $opts['since'] ), $kinds, array( $limit + 1 ) );
		$rows   = self::all(
			'SELECT * FROM `' . self::table( 'sync_objects' ) . '`
			 WHERE couple_id = %s AND updated_at > %d AND kind IN (' . $ph . ')
			 ORDER BY updated_at ASC, kind ASC, id ASC
			 LIMIT %d',
			$params
		);
		$has_more = count( $rows ) > $limit;
		$objects  = array();
		foreach ( array_slice( $rows, 0, $limit ) as $r ) {
			$objects[] = self::map_sync_object( $r );
		}
		return array(
			'objects' => $objects,
			'hasMore' => $has_more,
		);
	}

	/** @return int */
	public static function count_sync_objects() {
		return self::count( 'SELECT COUNT(*) AS n FROM `' . self::table( 'sync_objects' ) . '`' );
	}

	// ------------------------------------------------------------------
	// ai_partners / ai_messages / ai_providers（docs/API-AI聊天.md）
	// ------------------------------------------------------------------

	/**
	 * ai_partners 行 -> API 对象（AI-1 结构）。
	 *
	 * `providerId` / `model` 允许为空字符串，表示用服务端默认供应商 + 默认模型。
	 *
	 * @param array<string,mixed>|null $row 数据库行。
	 * @param array<string,string>     $provider_names 供应商 id => 名称。
	 * @return array<string,mixed>|null
	 */
	public static function map_ai_partner( $row, array $provider_names = array() ) {
		if ( ! $row ) {
			return null;
		}
		$provider_id = isset( $row['provider_id'] ) ? (string) $row['provider_id'] : '';
		return array(
			'id'           => (string) $row['id'],
			'name'         => isset( $row['name'] ) ? (string) $row['name'] : '',
			'avatarEmoji'  => isset( $row['avatar_emoji'] ) ? (string) $row['avatar_emoji'] : '',
			'persona'      => isset( $row['persona'] ) ? (string) $row['persona'] : '',
			'providerId'   => $provider_id,
			'providerName' => isset( $provider_names[ $provider_id ] ) ? (string) $provider_names[ $provider_id ] : '',
			'model'        => isset( $row['model'] ) ? (string) $row['model'] : '',
			'temperature'  => (float) $row['temperature'],
			'enabled'      => ! empty( $row['enabled'] ),
			'createdAt'    => (int) $row['created_at'],
			'updatedAt'    => (int) $row['updated_at'],
		);
	}

	/**
	 * ai_messages 行 -> API 对象（AI-3 / AI-4 结构）。
	 *
	 * AI 回复的 `senderId` 固定为 `"ai"`、`clientId` 为空字符串、`isMine` 为 false。
	 *
	 * @param array<string,mixed>|null $row     数据库行。
	 * @param string                   $viewer  当前用户 id（用于 isMine）。
	 * @return array<string,mixed>|null
	 */
	public static function map_ai_message( $row, $viewer = '' ) {
		if ( ! $row ) {
			return null;
		}
		$sender = isset( $row['sender'] ) ? (string) $row['sender'] : 'user';
		$is_ai  = ( 'ai' === $sender );
		$owner  = (string) $row['owner_id'];
		return array(
			'id'        => (string) $row['id'],
			'senderId'  => $is_ai ? 'ai' : $owner,
			'isMine'    => ( ! $is_ai && '' !== (string) $viewer && $owner === (string) $viewer ),
			'body'      => isset( $row['body'] ) ? (string) $row['body'] : '',
			'createdAt' => (int) $row['created_at'],
			/*
			 * clientId 只对**用户消息**有意义：它是客户端幂等键，只有用户自己能提供。
			 * AI 回复在库里也会占一个「内部关联键」槽位（避免撞 UNIQUE(owner_id, client_id)），
			 * 但那个内部键绝不回吐给 App，一律回空字符串（§AI-4 结构要求）。
			 */
			'clientId'  => ( ! $is_ai
				&& isset( $row['client_id'] )
				&& null !== $row['client_id']
				&& '' !== (string) $row['client_id']
				// 客户端没传 clientId 时，库里存的是内部占位键 `ai:<消息id>`，绝不能回吐给 App
				&& 0 !== strpos( (string) $row['client_id'], 'ai:' ) )
				? (string) $row['client_id'] : '',
		);
	}

	/**
	 * 供应商 id => 名称 映射（给伙伴列表用，避免 N+1）。
	 *
	 * @return array<string,string>
	 */
	private static function ai_provider_name_map() {
		$map = array();
		foreach ( self::all( 'SELECT id, name FROM `' . self::table( 'ai_providers' ) . '`' ) as $r ) {
			$map[ (string) $r['id'] ] = (string) $r['name'];
		}
		return $map;
	}

	/**
	 * @param string $id AI 伙伴 id。
	 * @return array<string,mixed>|null
	 */
	public static function get_ai_partner_row( $id ) {
		return self::one( 'SELECT * FROM `' . self::table( 'ai_partners' ) . '` WHERE id = %s', array( $id ) );
	}

	/**
	 * @param string $id      AI 伙伴 id。
	 * @param string $viewer  当前用户 id（用于 isMine / 权限提示）。
	 * @return array<string,mixed>|null
	 */
	public static function get_ai_partner( $id, $viewer = '' ) {
		return self::map_ai_partner( self::get_ai_partner_row( $id ), self::ai_provider_name_map() );
	}

	/**
	 * 列出某个用户的 AI 伙伴（按创建时间升序）。
	 *
	 * @param string $owner_id 用户 id。
	 * @return array<int,mixed>
	 */
	public static function list_ai_partners( $owner_id ) {
		$names = self::ai_provider_name_map();
		$out   = array();
		foreach ( self::all(
			'SELECT * FROM `' . self::table( 'ai_partners' ) . '` WHERE owner_id = %s ORDER BY created_at ASC, id ASC',
			array( $owner_id )
		) as $r ) {
			$out[] = self::map_ai_partner( $r, $names );
		}
		return $out;
	}

	/**
	 * @param string $owner_id 用户 id。
	 * @return int
	 */
	public static function count_ai_partners( $owner_id ) {
		return self::count(
			'SELECT COUNT(*) AS n FROM `' . self::table( 'ai_partners' ) . '` WHERE owner_id = %s',
			array( $owner_id )
		);
	}

	/** @return int 全部 AI 伙伴数量（后台统计）。 */
	public static function count_all_ai_partners() {
		return self::count( 'SELECT COUNT(*) AS n FROM `' . self::table( 'ai_partners' ) . '`' );
	}

	/** @return int 全部 AI 消息数量（后台统计）。 */
	public static function count_all_ai_messages() {
		return self::count( 'SELECT COUNT(*) AS n FROM `' . self::table( 'ai_messages' ) . '`' );
	}

	/**
	 * 新增 AI 伙伴。
	 *
	 * @param array<string,mixed> $data ownerId / name / avatarEmoji / persona / providerId / model / temperature / enabled。
	 * @return array<string,mixed>|null
	 */
	public static function create_ai_partner( array $data ) {
		$now = Time::now_ms();
		$id  = Ids::new_ai_partner_id();
		self::run(
			'INSERT INTO `' . self::table( 'ai_partners' ) . '`
			 (id, owner_id, name, avatar_emoji, persona, provider_id, model, temperature, enabled, created_at, updated_at)
			 VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %d, %d, %d)',
			array(
				$id,
				$data['ownerId'],
				$data['name'],
				isset( $data['avatarEmoji'] ) ? $data['avatarEmoji'] : '🤖',
				isset( $data['persona'] ) ? $data['persona'] : '',
				isset( $data['providerId'] ) ? $data['providerId'] : '',
				isset( $data['model'] ) ? $data['model'] : '',
				number_format( (float) ( isset( $data['temperature'] ) ? $data['temperature'] : 0.8 ), 2, '.', '' ),
				( ! isset( $data['enabled'] ) || $data['enabled'] ) ? 1 : 0,
				$now,
				$now,
			)
		);
		return self::get_ai_partner( $id );
	}

	/**
	 * 更新 AI 伙伴：只更新传入的字段。
	 *
	 * @param string              $id    AI 伙伴 id。
	 * @param array<string,mixed> $patch 可含 name / avatarEmoji / persona / providerId / model / temperature / enabled。
	 * @return array<string,mixed>|null
	 */
	public static function update_ai_partner( $id, array $patch ) {
		$table = self::table( 'ai_partners' );
		$cur   = self::get_ai_partner_row( $id );
		if ( ! $cur ) {
			return null;
		}
		$name   = array_key_exists( 'name', $patch ) ? $patch['name'] : $cur['name'];
		$emoji  = array_key_exists( 'avatarEmoji', $patch ) ? $patch['avatarEmoji'] : $cur['avatar_emoji'];
		$person = array_key_exists( 'persona', $patch ) ? $patch['persona'] : $cur['persona'];
		$prov   = array_key_exists( 'providerId', $patch ) ? $patch['providerId'] : $cur['provider_id'];
		$model  = array_key_exists( 'model', $patch ) ? $patch['model'] : $cur['model'];
		$temp   = array_key_exists( 'temperature', $patch ) ? $patch['temperature'] : $cur['temperature'];
		$on     = array_key_exists( 'enabled', $patch ) ? ( $patch['enabled'] ? 1 : 0 ) : ( (int) $cur['enabled'] );

		self::run(
			'UPDATE `' . $table . '`
			 SET name = %s, avatar_emoji = %s, persona = %s, provider_id = %s, model = %s,
			     temperature = %s, enabled = %d, updated_at = %d
			 WHERE id = %s',
			array(
				$name,
				$emoji,
				$person,
				$prov,
				$model,
				number_format( (float) $temp, 2, '.', '' ),
				$on,
				Time::now_ms(),
				$id,
			)
		);
		return self::get_ai_partner( $id );
	}

	/**
	 * 删除 AI 伙伴及其全部消息。
	 *
	 * @param string $id AI 伙伴 id。
	 * @return bool
	 */
	public static function delete_ai_partner( $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( 'START TRANSACTION' );
		try {
			self::run( 'DELETE FROM `' . self::table( 'ai_messages' ) . '` WHERE partner_id = %s', array( $id ) );
			self::run( 'DELETE FROM `' . self::table( 'ai_partners' ) . '` WHERE id = %s', array( $id ) );
			$changes = (int) $wpdb->rows_affected;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( 'COMMIT' );
			return $changes > 0;
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( 'ROLLBACK' );
			throw $e;
		}
	}

	/**
	 * 写入一条 AI 会话消息。
	 *
	 * client_id 上的 `UNIQUE(owner_id, client_id)` 只用于**用户消息**的 clientId 幂等；
	 * 但 MySQL 唯一索引把多个 `''` 视作同一个值（多个 NULL 才互不相同），所以**绝不能让
	 * 两条消息共存同一个 client_id 值**：调用方没给 clientId 时（典型是 AI 回复），
	 * 这里落一个互不相同的内部关联键，避免第二条回复撞唯一键而写不进去。
	 * 该内部键不会下发给 App（`map_ai_message` 对非用户消息一律回 `clientId: ""`）。
	 *
	 * @param array<string,mixed> $data partnerId / ownerId / sender(user|ai) / body / clientId。
	 * @return array<string,mixed>|null
	 */
	public static function insert_ai_message( array $data ) {
		$table  = self::table( 'ai_messages' );
		$id     = Ids::new_message_id();
		$client = isset( $data['clientId'] ) ? trim( (string) $data['clientId'] ) : '';
		if ( '' === $client ) {
			$client = 'ai:' . $id;
		}
		// created_at 必须**严格递增**：同一毫秒内连发多条时，若时间戳相同，
		// 「最近 N 条」用的 ORDER BY created_at DESC, id DESC 会因为 id 是随机串而排序不稳定，
		// 取到的窗口就会是错的（表现为上下文里混进了很旧的消息、漏了最新的几条）。
		$now  = Time::now_ms();
		$last = self::one(
			'SELECT created_at FROM `' . $table . '` WHERE owner_id = %s AND partner_id = %s ORDER BY created_at DESC LIMIT 1',
			array( $data['ownerId'], $data['partnerId'] )
		);
		if ( $last && (int) $last['created_at'] >= $now ) {
			$now = (int) $last['created_at'] + 1;
		}

		self::run(
			'INSERT INTO `' . $table . '` (id, partner_id, owner_id, sender, body, client_id, created_at)
			 VALUES (%s, %s, %s, %s, %s, %s, %d)',
			array(
				$id,
				$data['partnerId'],
				$data['ownerId'],
				$data['sender'],
				isset( $data['body'] ) ? $data['body'] : '',
				$client,
				$now,
			)
		);
		$row = self::one( 'SELECT * FROM `' . $table . '` WHERE id = %s', array( $id ) );
		return self::map_ai_message( $row, $data['ownerId'] );
	}

	/**
	 * 该 clientId 的用户消息是否已存在（并发重放预检）。
	 *
	 * 让「clientId 幂等」在并发下尽量不触发唯一键冲突，避免 MySQL 往站点错误日志里
	 * 写一条「Duplicate entry」（那是预期内的控制流，不是故障）。
	 *
	 * @param string $owner_id  用户 id。
	 * @param string $client_id 客户端幂等 id（空字符串时返回 false）。
	 * @return bool
	 */
	public static function ai_message_client_id_exists( $owner_id, $client_id ) {
		$client_id = (string) $client_id;
		// 空 clientId 与 AI 回复的内部关联键（ai:*）都不是「用户消息的幂等键」。
		if ( '' === $client_id || self::is_ai_reply_row_key( $client_id ) ) {
			return false;
		}
		return self::count(
			'SELECT COUNT(*) AS n FROM `' . self::table( 'ai_messages' ) . '` WHERE owner_id = %s AND client_id = %s',
			array( $owner_id, $client_id )
		) > 0;
	}

	/**
	 * 是否是 AI 回复占位用的内部关联键（`ai:<消息 id>`）。
	 *
	 * 这类键只为了避开 `UNIQUE(owner_id, client_id)`（MySQL 把多个 '' 视作同一个值，
	 * 多个 NULL 才互不相同），绝不参与 clientId 幂等查找，也绝不下发给 App。
	 *
	 * @param string $client_id 库里的 client_id 值。
	 * @return bool
	 */
	public static function is_ai_reply_row_key( $client_id ) {
		return 0 === strpos( (string) $client_id, 'ai:' );
	}

	/**
	 * @param string $id     消息 id。
	 * @param string $viewer 当前用户 id。
	 * @return array<string,mixed>|null
	 */
	public static function get_ai_message( $id, $viewer = '' ) {
		$row = self::one( 'SELECT * FROM `' . self::table( 'ai_messages' ) . '` WHERE id = %s', array( $id ) );
		return $row ? self::map_ai_message( $row, $viewer ) : null;
	}

	/**
	 * 按 clientId 找用户消息（幂等）。
	 *
	 * @param string $owner_id  用户 id。
	 * @param string $client_id 客户端幂等 id。
	 * @return array<string,mixed>|null
	 */
	public static function find_ai_message_by_client_id( $owner_id, $client_id ) {
		$client_id = (string) $client_id;
		// 空 clientId 与 AI 回复的内部关联键（ai:*）都不是「用户消息的幂等键」。
		if ( '' === $client_id || self::is_ai_reply_row_key( $client_id ) ) {
			return null;
		}
		$row = self::one(
			'SELECT * FROM `' . self::table( 'ai_messages' ) . '` WHERE owner_id = %s AND client_id = %s',
			array( $owner_id, $client_id )
		);
		return $row ? self::map_ai_message( $row, $owner_id ) : null;
	}

	/**
	 * 找 clientId 对应的 AI 回复（没有则说明上次上游调用没走完）。
	 *
	 * @param string $owner_id  用户 id。
	 * @param string $partner_id AI 伙伴 id。
	 * @param string $client_id 客户端幂等 id。
	 * @return array<string,mixed>|null
	 */
	public static function find_ai_reply_for_client_id( $owner_id, $partner_id, $client_id ) {
		$row = self::one(
			'SELECT * FROM `' . self::table( 'ai_messages' ) . '`
			 WHERE owner_id = %s AND partner_id = %s AND sender = %s AND client_id = %s
			 ORDER BY created_at ASC, id ASC LIMIT 1',
			array( $owner_id, $partner_id, 'ai', 'c:' . $client_id )
		);
		return $row ? self::map_ai_message( $row, $owner_id ) : null;
	}

	/**
	 * 读会话上下文：返回**最近** $limit 条消息（按 createdAt 升序返回）。
	 *
	 * 先按时间倒序取 limit 条，再翻回升序，保证「最近 20 条」语义正确。
	 *
	 * @param array<string,mixed> $opts ownerId / partnerId / limit。
	 * @return array<int,mixed>
	 */
	public static function get_recent_ai_messages( array $opts ) {
		$limit   = max( 1, (int) $opts['limit'] );
		$owner   = isset( $opts['ownerId'] ) ? (string) $opts['ownerId'] : '';
		$partner = isset( $opts['partnerId'] ) ? (string) $opts['partnerId'] : '';
		$rows    = self::all(
			'SELECT * FROM `' . self::table( 'ai_messages' ) . '`
			 WHERE owner_id = %s AND partner_id = %s
			 ORDER BY created_at DESC, id DESC LIMIT %d',
			array( $owner, $partner, $limit )
		);
		$rows = array_reverse( $rows );
		$out  = array();
		foreach ( $rows as $r ) {
			$out[] = self::map_ai_message( $r, $owner );
		}
		return $out;
	}

	/**
	 * 拉取会话消息（AI-3）：不传 before 取最新 limit 条，返回按 createdAt 升序。
	 *
	 * @param array<string,mixed> $opts ownerId / partnerId / limit / before。
	 * @return array{messages:array<int,mixed>,hasMore:bool}
	 */
	public static function list_ai_messages( array $opts ) {
		$table  = self::table( 'ai_messages' );
		$limit  = max( 1, (int) $opts['limit'] );
		$owner  = (string) $opts['ownerId'];
		$partner = (string) $opts['partnerId'];
		$before = array_key_exists( 'before', $opts ) ? $opts['before'] : null;

		if ( null === $before ) {
			$rows = self::all(
				'SELECT * FROM `' . $table . '` WHERE owner_id = %s AND partner_id = %s
				 ORDER BY created_at DESC, id DESC LIMIT %d',
				array( $owner, $partner, $limit + 1 )
			);
		} else {
			$rows = self::all(
				'SELECT * FROM `' . $table . '` WHERE owner_id = %s AND partner_id = %s AND created_at < %d
				 ORDER BY created_at DESC, id DESC LIMIT %d',
				array( $owner, $partner, (int) $before, $limit + 1 )
			);
		}

		$has_more = count( $rows ) > $limit;
		$page     = array_reverse( array_slice( $rows, 0, $limit ) );
		$messages = array();
		foreach ( $page as $r ) {
			$messages[] = self::map_ai_message( $r, $owner );
		}
		return array(
			'messages' => $messages,
			'hasMore'  => $has_more,
		);
	}

	/**
	 * 删除某个 AI 伙伴下早于指定行数的历史（保留最近 $keep 条，自动清理用）。
	 *
	 * @param string $partner_id AI 伙伴 id。
	 * @param int    $keep       保留条数。
	 * @return int 删除条数。
	 */
	public static function trim_ai_messages( $partner_id, $keep ) {
		global $wpdb;
		$keep = max( 0, (int) $keep );
		$row  = self::one(
			'SELECT created_at, id FROM `' . self::table( 'ai_messages' ) . '`
			 WHERE partner_id = %s ORDER BY created_at DESC, id DESC LIMIT 1 OFFSET %d',
			array( $partner_id, $keep )
		);
		if ( ! $row ) {
			return 0;
		}
		self::run(
			'DELETE FROM `' . self::table( 'ai_messages' ) . '` WHERE partner_id = %s AND created_at < %d',
			array( $partner_id, (int) $row['created_at'] )
		);
		return (int) $wpdb->rows_affected;
	}

	/**
	 * ai_providers 行 -> API 对象（§AI-5 结构）。**绝不返回明文 Key**。
	 *
	 * @param array<string,mixed>|null $row 数据库行。
	 * @return array<string,mixed>|null
	 */
	public static function map_ai_provider( $row ) {
		if ( ! $row ) {
			return null;
		}
		$plain  = Settings::decrypt_secret( isset( $row['api_key_enc'] ) ? $row['api_key_enc'] : '' );
		$models = self::parse_json( isset( $row['models_json'] ) ? $row['models_json'] : null );
		if ( ! is_array( $models ) ) {
			$models = array();
		}
		$clean = array();
		foreach ( $models as $m ) {
			if ( is_string( $m ) && '' !== trim( $m ) ) {
				$clean[] = trim( $m );
			}
		}
		return array(
			'id'                => (string) $row['id'],
			'name'              => (string) $row['name'],
			'type'              => (string) $row['type'],
			'baseUrl'           => (string) $row['base_url'],
			'models'            => $clean,
			'defaultModel'      => (string) $row['default_model'],
			'enabled'           => ! empty( $row['enabled'] ),
			'isDefault'         => ! empty( $row['is_default'] ),
			'apiKeyMasked'      => Settings::mask_secret( $plain ),
			'apiKeyConfigured'  => ( '' !== $plain ),
			'createdAt'         => (int) $row['created_at'],
			'updatedAt'         => (int) $row['updated_at'],
		);
	}

	/**
	 * @param string $id 供应商 id。
	 * @return array<string,mixed>|null
	 */
	public static function get_ai_provider_row( $id ) {
		return self::one( 'SELECT * FROM `' . self::table( 'ai_providers' ) . '` WHERE id = %s', array( $id ) );
	}

	/**
	 * @param string $id 供应商 id。
	 * @return array<string,mixed>|null
	 */
	public static function get_ai_provider( $id ) {
		return self::map_ai_provider( self::get_ai_provider_row( $id ) );
	}

	/**
	 * 供应商列表。
	 *
	 * @param bool $enabled_only 只要启用的。
	 * @return array<int,mixed>
	 */
	public static function list_ai_providers( $enabled_only = false ) {
		$where = $enabled_only ? ' WHERE enabled = 1' : '';
		$out   = array();
		foreach ( self::all(
			'SELECT * FROM `' . self::table( 'ai_providers' ) . '`' . $where . ' ORDER BY is_default DESC, created_at ASC, id ASC'
		) as $r ) {
			$out[] = self::map_ai_provider( $r );
		}
		return $out;
	}

	/** @return int 启用中的供应商数量（/api/meta 的 aiProviders）。 */
	public static function count_enabled_ai_providers() {
		return self::count( 'SELECT COUNT(*) AS n FROM `' . self::table( 'ai_providers' ) . '` WHERE enabled = 1' );
	}

	/** @return int 供应商总数。 */
	public static function count_ai_providers() {
		return self::count( 'SELECT COUNT(*) AS n FROM `' . self::table( 'ai_providers' ) . '`' );
	}

	/**
	 * 默认供应商（is_default=1 且启用；没有显式默认时退回第一个启用的）。
	 *
	 * @return array<string,mixed>|null
	 */
	public static function get_default_ai_provider() {
		$row = self::one( 'SELECT * FROM `' . self::table( 'ai_providers' ) . '` WHERE is_default = 1 AND enabled = 1 LIMIT 1' );
		if ( $row ) {
			return self::map_ai_provider( $row );
		}
		$row = self::one( 'SELECT * FROM `' . self::table( 'ai_providers' ) . '` WHERE enabled = 1 ORDER BY created_at ASC, id ASC LIMIT 1' );
		return $row ? self::map_ai_provider( $row ) : null;
	}

	/**
	 * 「可用供应商」：至少有一个启用的供应商（用于 ai_not_configured 判断）。
	 *
	 * @return array<string,mixed>|null
	 */
	public static function get_usable_ai_provider() {
		return self::get_default_ai_provider();
	}

	/**
	 * 新增供应商（apiKey 已在调用方加密）。
	 *
	 * @param array<string,mixed> $data name / type / baseUrl / apiKeyEnc / models / defaultModel / enabled / isDefault。
	 * @return array<string,mixed>|null
	 */
	public static function create_ai_provider( array $data ) {
		$now = Time::now_ms();
		$id  = Ids::new_ai_provider_id();
		self::run(
			'INSERT INTO `' . self::table( 'ai_providers' ) . '`
			 (id, name, type, base_url, api_key_enc, models_json, default_model, enabled, is_default, created_at, updated_at)
			 VALUES (%s, %s, %s, %s, %s, %s, %s, %d, %d, %d, %d)',
			array(
				$id,
				$data['name'],
				$data['type'],
				( isset( $data['baseUrl'] ) && '' !== $data['baseUrl'] ) ? $data['baseUrl'] : self::default_base_url_for_type( $data['type'] ),
				isset( $data['apiKeyEnc'] ) ? $data['apiKeyEnc'] : '',
				wp_json_encode( isset( $data['models'] ) ? array_values( $data['models'] ) : array(), JSON_UNESCAPED_UNICODE ),
				isset( $data['defaultModel'] ) ? $data['defaultModel'] : '',
				( ! isset( $data['enabled'] ) || $data['enabled'] ) ? 1 : 0,
				( ! empty( $data['isDefault'] ) ) ? 1 : 0,
				$now,
				$now,
			)
		);
		return self::get_ai_provider( $id );
	}

	/**
	 * 更新供应商（只更新传入字段）。
	 *
	 * @param string              $id    供应商 id。
	 * @param array<string,mixed> $patch 字段。
	 * @return array<string,mixed>|null
	 */
	public static function update_ai_provider( $id, array $patch ) {
		$table = self::table( 'ai_providers' );
		$cur   = self::get_ai_provider_row( $id );
		if ( ! $cur ) {
			return null;
		}
		$name   = array_key_exists( 'name', $patch ) ? $patch['name'] : $cur['name'];
		$type   = array_key_exists( 'type', $patch ) ? $patch['type'] : $cur['type'];
		$base   = array_key_exists( 'baseUrl', $patch ) ? $patch['baseUrl'] : $cur['base_url'];
		$key    = array_key_exists( 'apiKeyEnc', $patch ) ? $patch['apiKeyEnc'] : $cur['api_key_enc'];
		$models = array_key_exists( 'models', $patch )
			? wp_json_encode( array_values( $patch['models'] ), JSON_UNESCAPED_UNICODE )
			: $cur['models_json'];
		$dm     = array_key_exists( 'defaultModel', $patch ) ? $patch['defaultModel'] : $cur['default_model'];
		$on     = array_key_exists( 'enabled', $patch ) ? ( $patch['enabled'] ? 1 : 0 ) : (int) $cur['enabled'];
		$def    = array_key_exists( 'isDefault', $patch ) ? ( $patch['isDefault'] ? 1 : 0 ) : (int) $cur['is_default'];
		if ( '' === (string) $base ) {
			$base = self::default_base_url_for_type( $type );
		}

		self::run(
			'UPDATE `' . $table . '`
			 SET name = %s, type = %s, base_url = %s, api_key_enc = %s, models_json = %s,
			     default_model = %s, enabled = %d, is_default = %d, updated_at = %d
			 WHERE id = %s',
			array( $name, $type, $base, $key, $models, $dm, $on, $def, Time::now_ms(), $id )
		);
		return self::get_ai_provider( $id );
	}

	/**
	 * 删除供应商。
	 *
	 * @param string $id 供应商 id。
	 * @return bool
	 */
	public static function delete_ai_provider( $id ) {
		global $wpdb;
		$n = self::run( 'DELETE FROM `' . self::table( 'ai_providers' ) . '` WHERE id = %s', array( $id ) );
		return ( (int) $wpdb->rows_affected ) > 0;
	}

	/**
	 * 把某个供应商设为唯一默认（其余清掉）。
	 *
	 * @param string $id 供应商 id（空字符串表示「没有默认」）。
	 */
	public static function set_default_ai_provider( $id ) {
		$table = self::table( 'ai_providers' );
		self::run( 'UPDATE `' . $table . '` SET is_default = 0' );
		if ( '' !== (string) $id ) {
			self::run( 'UPDATE `' . $table . '` SET is_default = 1, updated_at = %d WHERE id = %s', array( Time::now_ms(), $id ) );
		}
	}

	/**
	 * 删除默认供应商后自动改选：优先第一个启用的，否则第一个存在的。
	 *
	 * @return string 新的默认供应商 id（没有则为空字符串）。
	 */
	public static function reselect_default_ai_provider() {
		$row = self::one( 'SELECT id FROM `' . self::table( 'ai_providers' ) . '` WHERE is_default = 1 LIMIT 1' );
		if ( $row ) {
			return (string) $row['id'];
		}
		$row = self::one( 'SELECT id FROM `' . self::table( 'ai_providers' ) . '` WHERE enabled = 1 ORDER BY created_at ASC, id ASC LIMIT 1' );
		if ( ! $row ) {
			$row = self::one( 'SELECT id FROM `' . self::table( 'ai_providers' ) . '` ORDER BY created_at ASC, id ASC LIMIT 1' );
		}
		if ( ! $row ) {
			return '';
		}
		self::set_default_ai_provider( (string) $row['id'] );
		return (string) $row['id'];
	}

	/**
	 * 各类型的默认 Base URL。
	 *
	 * @param string $type openai | anthropic | gemini | deepseek。
	 * @return string
	 */
	public static function default_base_url_for_type( $type ) {
		switch ( (string) $type ) {
			case 'anthropic':
				return 'https://api.anthropic.com';
			case 'gemini':
				return 'https://generativelanguage.googleapis.com';
			case 'deepseek':
				return 'https://api.deepseek.com';
			case 'openai':
			default:
				return 'https://api.openai.com';
		}
	}

	// ------------------------------------------------------------------
	// settings
	// ------------------------------------------------------------------

	/**
	 * 读取单个设置（已 JSON 反序列化）。
	 *
	 * @param string $key 键。
	 * @return mixed
	 */
	public static function get_setting( $key ) {
		$row = self::one( 'SELECT `value` FROM `' . self::table( 'settings' ) . '` WHERE `key` = %s', array( $key ) );
		if ( ! $row ) {
			return null;
		}
		return self::parse_json( $row['value'] );
	}

	/**
	 * 读取全部设置。
	 *
	 * @return array<string,mixed>
	 */
	public static function get_all_settings() {
		$out = array();
		foreach ( self::all( 'SELECT `key`, `value` FROM `' . self::table( 'settings' ) . '`' ) as $r ) {
			$out[ $r['key'] ] = self::parse_json( $r['value'] );
		}
		return $out;
	}

	/**
	 * 写入单个设置。
	 *
	 * @param string $key   键。
	 * @param mixed  $value 值（会被 JSON 序列化）。
	 */
	public static function set_setting( $key, $value ) {
		self::run(
			'INSERT INTO `' . self::table( 'settings' ) . '` (`key`, `value`, updated_at) VALUES (%s, %s, %d)
			 ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_at = VALUES(updated_at)',
			array( $key, wp_json_encode( $value, JSON_UNESCAPED_UNICODE ), Time::now_ms() )
		);
	}

	/**
	 * 批量写入设置（事务）。
	 *
	 * @param array<string,mixed> $entries 键值对。
	 */
	public static function set_settings( array $entries ) {
		global $wpdb;
		$now = Time::now_ms();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( 'START TRANSACTION' );
		try {
			foreach ( $entries as $k => $v ) {
				self::run(
					'INSERT INTO `' . self::table( 'settings' ) . '` (`key`, `value`, updated_at) VALUES (%s, %s, %d)
					 ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_at = VALUES(updated_at)',
					array( $k, wp_json_encode( $v, JSON_UNESCAPED_UNICODE ), $now )
				);
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( 'COMMIT' );
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( 'ROLLBACK' );
			throw $e;
		}
	}

	/**
	 * 删除某个设置（后台密码哈希的「救援路径」用）。
	 *
	 * @param string $key 键。
	 */
	public static function delete_setting( $key ) {
		self::run( 'DELETE FROM `' . self::table( 'settings' ) . '` WHERE `key` = %s', array( $key ) );
	}

	// ------------------------------------------------------------------
	// ai_usage
	// ------------------------------------------------------------------

	/**
	 * @param array<string,mixed> $data userId / endpoint / model / ok / promptLen / day。
	 */
	public static function record_ai_usage( array $data ) {
		$day = ( isset( $data['day'] ) && $data['day'] ) ? $data['day'] : Time::day_key();
		self::run(
			'INSERT INTO `' . self::table( 'ai_usage' ) . '` (id, user_id, endpoint, model, ok, prompt_len, created_at, day)
			 VALUES (%s, %s, %s, %s, %d, %d, %d, %s)',
			array(
				Ids::new_ai_usage_id(),
				isset( $data['userId'] ) ? $data['userId'] : null,
				$data['endpoint'],
				isset( $data['model'] ) ? $data['model'] : null,
				( ! isset( $data['ok'] ) || $data['ok'] ) ? 1 : 0,
				isset( $data['promptLen'] ) ? (int) $data['promptLen'] : 0,
				Time::now_ms(),
				$day,
			)
		);
	}

	/**
	 * @param string|null $day 日期（YYYY-MM-DD）。
	 * @return int
	 */
	public static function count_ai_usage_today( $day = null ) {
		$d = $day ? $day : Time::day_key();
		return self::count( 'SELECT COUNT(*) AS n FROM `' . self::table( 'ai_usage' ) . '` WHERE day = %s', array( $d ) );
	}

	/**
	 * @param string      $user_id 用户 id。
	 * @param string|null $day     日期。
	 * @return int
	 */
	public static function count_ai_usage_today_by_user( $user_id, $day = null ) {
		$d = $day ? $day : Time::day_key();
		return self::count(
			'SELECT COUNT(*) AS n FROM `' . self::table( 'ai_usage' ) . '` WHERE day = %s AND user_id = %s',
			array( $d, $user_id )
		);
	}

	// ------------------------------------------------------------------
	// admin_logs
	// ------------------------------------------------------------------

	/**
	 * @param array<string,mixed> $data action / detail / ip。
	 */
	public static function add_admin_log( array $data ) {
		self::run(
			'INSERT INTO `' . self::table( 'admin_logs' ) . '` (id, action, detail, ip, created_at) VALUES (%s, %s, %s, %s, %d)',
			array(
				Ids::new_log_id(),
				$data['action'],
				isset( $data['detail'] ) ? $data['detail'] : null,
				isset( $data['ip'] ) ? $data['ip'] : null,
				Time::now_ms(),
			)
		);
	}

	/**
	 * @param int $limit 条数。
	 * @return array<int,mixed>
	 */
	public static function list_admin_logs( $limit = 100 ) {
		$limit = (int) $limit;
		$out   = array();
		foreach ( self::all(
			'SELECT id, action, detail, ip, created_at FROM `' . self::table( 'admin_logs' ) . '` ORDER BY created_at DESC LIMIT %d',
			array( $limit )
		) as $r ) {
			$out[] = array(
				'id'        => (string) $r['id'],
				'action'    => (string) $r['action'],
				'detail'    => ( null !== $r['detail'] ) ? (string) $r['detail'] : null,
				'ip'        => ( null !== $r['ip'] ) ? (string) $r['ip'] : null,
				'createdAt' => (int) $r['created_at'],
			);
		}
		return $out;
	}

	// ------------------------------------------------------------------
	// 统计 / 维护
	// ------------------------------------------------------------------

	/**
	 * @return array<string,int>
	 */
	public static function get_stats() {
		return array(
			'users'        => self::count_users(),
			'couples'      => self::count_couples(),
			'couplesTotal' => self::count_all_couples(),
			'messages'     => self::count_messages(),
			'aiToday'      => self::count_ai_usage_today(),
			'aiTotal'      => self::count( 'SELECT COUNT(*) AS n FROM `' . self::table( 'ai_usage' ) . '`' ),
			'packs'        => self::count_emoji_packs(),
			'items'        => self::count_emoji_items(),
			'syncObjects'  => self::count_sync_objects(),
		);
	}

	/**
	 * 磁盘占用（字节）：表情目录 + 数据库表数据/索引大小。
	 *
	 * @return array<string,int>
	 */
	public static function get_disk_usage() {
		global $wpdb;
		$emoji_bytes = 0;
		$emoji_files = 0;
		$dir         = EmojiStore::base_dir();
		if ( is_dir( $dir ) ) {
			$names = @scandir( $dir );
			if ( is_array( $names ) ) {
				foreach ( $names as $name ) {
					if ( '.' === $name || '..' === $name ) {
						continue;
					}
					$p = $dir . '/' . $name;
					if ( is_file( $p ) ) {
						$emoji_bytes += (int) filesize( $p );
						$emoji_files++;
					}
				}
			}
		}

		$db_bytes = 0;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$db_bytes = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COALESCE(SUM(DATA_LENGTH + INDEX_LENGTH), 0) FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s',
				$wpdb->dbname
			)
		);

		return array(
			'dbBytes'    => $db_bytes,
			'emojiBytes' => $emoji_bytes,
			'emojiFiles' => $emoji_files,
			'totalBytes' => $db_bytes + $emoji_bytes,
		);
	}

	/**
	 * 数据库自检（健康检查用）。
	 *
	 * @return bool
	 */
	public static function ping_db() {
		$row = self::one( 'SELECT 1 AS ok' );
		return ( null !== $row && 1 === (int) $row['ok'] );
	}

	/** @return string 数据库服务端版本。 */
	public static function server_version() {
		global $wpdb;
		if ( method_exists( $wpdb, 'db_server_info' ) ) {
			$v = (string) $wpdb->db_server_info();
			if ( '' !== $v ) {
				return $v;
			}
		}
		return (string) self::scalar( 'SELECT VERSION()' );
	}

	/** @return string 数据库类型（MySQL / MariaDB）。 */
	public static function server_engine() {
		$v = strtolower( self::server_version() );
		if ( false !== strpos( $v, 'mariadb' ) ) {
			return 'MariaDB';
		}
		return '' === trim( $v ) ? '未知' : 'MySQL';
	}
}
