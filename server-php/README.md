# 情侣空间服务端 · PHP 版（MySQL / MariaDB）

> 这是 Node 版服务端（`server/`）的 **纯 PHP 重写**。
> 接口契约与 `docs/API.md` 完全一致，**iOS 客户端一行都不用改**。
>
> - 不需要 Composer，不需要 `vendor/`，不依赖任何第三方库
> - 只需要 PHP 内置扩展：`pdo_mysql`、`openssl`、`mbstring`、`curl`、`json`
> - 数据库是 **MySQL / MariaDB**（宝塔面板里建的库直接用），兼容 MySQL 5.7 / 8.0 / MariaDB 10.x
> - **网页安装向导**：上传后浏览器打开站点 → 填数据库信息 + 后台密码 → 自动建库建表、
>   自动生成 `config.php`，全程不用改代码
> - 兼容 **PHP 7.4** 及以上（推荐 8.1 / 8.2 / 8.3，宝塔常见版本即可）

---

## 1. 目录结构

```
server-php/
├── README.md              本文档（中文部署说明）
├── config.example.php     配置模板（MySQL 版；正常流程用不到，安装向导会生成 config.php）
├── .gitignore             忽略 config.php / data/ / 日志（注意是锚定的 /config.php）
├── public/
│   ├── index.php          REST 入口（伪静态与 /index.php/api/... 兜底都走它）
│   ├── install.php        网页安装向导（环境自检 + 填数据库信息 + 自动生成 config.php）
│   ├── admin.php          后台管理单页（内联 CSS/JS，深色主题，全中文）
│   └── emoji/             内置公共表情包目录（可选，放 <包名>/<图片> 即可）
├── src/                   自己的分层
│   ├── Bootstrap.php      启动引导（自动加载 / 配置校验 / 路由分发 / 错误处理）
│   ├── Config.php         配置加载与启动校验（含「未安装」判定）
│   ├── Database.php       PDO MySQL 连接工厂（中文错误提示、建库）
│   ├── Installer.php      网页安装向导后端逻辑（自检 / 建库 / 生成 config.php / 锁定）
│   ├── Db.php             数据库层（唯一接触 SQL 的文件；MySQL DDL + 幂等迁移）
│   ├── Http.php           请求解析与响应输出
│   ├── Router.php         按路径段匹配的极简路由器
│   ├── Jwt.php            手写 JWT HS256（base64url + hash_hmac + hash_equals）
│   ├── Auth.php           用户 / 后台鉴权
│   ├── RateLimit.php      数据库表 rate_limits 实现的限流
│   ├── Settings.php       settings 表读写 + DeepSeek Key 加密/掩码
│   ├── DeepSeek.php       DeepSeek 代理（超时重试 + JSON 容错解析）
│   ├── PublicEmoji.php    内置公共表情包同步
│   ├── EmojiStore.php     表情文件磁盘存取（路径完全由服务端决定）
│   ├── WsOutbox.php       实时推送事件队列（REST → WS 常驻进程）
│   ├── WsHub.php          WebSocket 在线状态（跨进程只读视图）
│   ├── WsServer.php       纯 PHP WebSocket 服务端（事件循环 + 帧编解码）
│   ├── Runtime.php / Log.php / Time.php / HttpException.php / AiError.php
│   │   / InstallRequired.php / DbConnectionError.php
│   ├── routes/            REST 路由：Meta / Auth / Couple / Sync / Chat / Emoji / Ai / Admin
│   └── util/              Ids（id 生成）/ Validate（参数校验）/ Image（magic bytes）
├── bin/
│   ├── ws-server.php      WebSocket 常驻进程
│   └── console.php        命令行工具（自检 / 建表 / 重置后台密码 / 同步表情 / 清理 / 本地起服务）
├── data/                  运行时生成：表情文件 + 日志 + WS 状态（数据库在 MySQL 里）
└── test/
    ├── smoke.php          端到端测试（php test/smoke.php）
    └── install-test.php   安装向导测试（php test/install-test.php）
```

---

## 2. 宝塔面板部署（推荐流程，4 步）

### 步骤 1：在宝塔里建数据库

宝塔面板 → **数据库** → 添加数据库：

- 数据库名：例如 `couple`
- 用户名 / 密码：记下来（安装向导要填）
- 访问权限：**本地服务器**（数据库与网站在同一台机器时）

> 也可以先不建库 —— 安装向导里勾选「数据库不存在时自动创建」就会自动建
> （需要该数据库账号有 `CREATE` 权限；宝塔里默认就有）。

### 步骤 2：上传代码

1. 把 `server-php/` 整个目录上传到站点根目录，例如
   `/www/wwwroot/couple.example.com/server-php`。
2. 宝塔 → 网站 → 添加站点（PHP 版本选 **8.1 / 8.2 / 8.3**）。
3. 站点设置 → **网站目录** → 运行目录选择 `/server-php/public`，保存。
   （运行目录必须是 `public`，否则 `src/`、`data/`、`config.php` 会被浏览器直接访问）
4. 站点设置 → **PHP 扩展**：确认 `pdo_mysql` 已启用（宝塔默认已启用）。
5. 站点设置 → **配置文件**，加上伪静态规则（见 2.1）。

### 步骤 3：浏览器打开安装向导

访问 `https://你的域名/install.php`（或 `http://IP:端口/install.php`）：

1. 页面顶部会显示 **环境自检**：PHP 版本、`pdo_mysql`、`curl`/`openssl`/`mbstring`、
   代码目录是否可写、数据库连接测试结果。
2. 填写：数据库主机（本机 `127.0.0.1`）、端口（宝塔显示的端口，默认 3306）、库名、
   用户名、密码；勾选「数据库不存在时自动创建」。
3. 设置后台管理员密码（≥6 位，输两遍）；站点地址可选（填了表情返回绝对地址）。
4. 点「开始安装」→ 程序自动建库、建表、写入默认设置、生成 `config.php`
   （含随机 `jwt_secret`，权限尽量 0640）。
5. 成功页会给出后台地址 `/admin.php`、自检链接 `/api/health`，以及下一步提示。

### 步骤 4：登录后台、按需启动 WebSocket

- 后台管理：`https://你的域名/admin.php`（用刚才设置的后台密码登录）
- 健康检查：`https://你的域名/api/health` 应返回 `{"ok":true,...}`
- 实时推送（可选）：见 2.3

> **安装完成后 `install.php` 会自动锁定**：再次访问返回 `403 已安装。如需重装请先删除
> config.php`。想彻底关掉可以删除 `public/install.php`。

### 2.1 伪静态规则（nginx）

```nginx
# 后台接口与业务接口都交给 public/index.php
location / {
    try_files $uri $uri/ /index.php$is_args$args;
}

# 让 PHP 能拿到正确的 PATH_INFO（部分环境需要）
location ~ \.php(/|$) {
    fastcgi_pass unix:/tmp/php-cgi-81.sock;   # 按宝塔实际 socket 名调整
    fastcgi_index index.php;
    fastcgi_split_path_info ^(.+\.php)(/.*)$;
    fastcgi_param PATH_INFO $fastcgi_path_info;
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
}
```

> 说明：即使伪静态没配好，客户端把 Base URL 写成
> `https://域名/index.php/api` 也能正常工作（`public/index.php` 同时支持两种形式）。

### 2.2 目录权限（重要）

安装向导生成 `config.php` 需要**代码目录可写**：

```bash
chown -R www:www /www/wwwroot/你的站点/server-php
chmod -R 755 /www/wwwroot/你的站点/server-php
```

表情文件、日志、WS 状态写在 `data/`（默认 `server-php/data`）：

- 权限不足时接口会返回 `服务器内部错误`，日志在 `data/logs/app.log` 里能看到原因
- 想放到站点外（更安全）：把 `config.php` 里的 `data_dir` 改成绝对路径，
  例如 `/www/wwwroot/couple-data`，并同样授权给 `www`

### 2.3 WebSocket 常驻（可选，但推荐）

WebSocket 由独立进程提供，**必须常驻**（宝塔 → 软件商店 → 「进程守护管理器」/ supervisor）：

- 名称：`couple-ws`
- 启动用户：`www`
- 启动目录：`/www/wwwroot/你的站点/server-php`
- 启动命令：`/www/server/php/81/bin/php bin/ws-server.php`
- 进程数量：1

然后给站点加反向代理（宝塔 → 网站 → 反向代理，或在 nginx 配置里加）：

```nginx
location /ws {
    proxy_pass http://127.0.0.1:8900;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $host;
    proxy_read_timeout 3600s;
}
```

客户端连接地址：`wss://你的域名/ws?token=<jwt>`（也支持 `Authorization: Bearer` 头）。

> 没有常驻 WebSocket 也不影响主流程：REST 写入照常成功，客户端重连后通过
> `/api/chat/messages` 补齐历史消息（API.md §8 的降级约定）。

### 2.4 HTTPS

宝塔 → 网站 → SSL → Let's Encrypt 申请证书并开启强制 HTTPS。
iOS 对明文 HTTP 有限制，生产环境务必上 HTTPS（`wss://`）。

---

## 3. 不想让安装向导写文件怎么办？

安装向导需要往 `server-php/` 目录写一个 `config.php`。如果服务器不允许 PHP 写代码目录
（例如 `open_basedir` 限制、目录属主是 root 且不想改），有三种办法：

### 办法 A：按页面提示改权限（最简单）

安装向导检测到目录不可写时，会在页面上给出：

```bash
chown -R www:www /www/wwwroot/你的站点/server-php
```

改完权限重新提交即可。

### 办法 B：复制页面给出的配置内容（不改权限）

数据库连接校验通过后，安装向导会在页面上显示**可复制的 `config.php` 内容**
（只有在「目录不可写」这个兜底场景、且数据库已校验通过后才显示）。
在宝塔「文件」里新建 `server-php/config.php`，把内容粘进去，保存并设为 0640 即可。

### 办法 C：手工复制模板（完全跳过向导）

```bash
cd server-php
cp config.example.php config.php
# 编辑 config.php：填 db_host / db_port / db_name / db_user / db_pass、
#                 jwt_secret（≥32 位随机串）、admin_password（≥6 位）
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"   # 生成 jwt_secret
```

建表不用手动做：`config.php` 就绪后第一次访问任意接口都会自动建表
（也可以显式执行 `php bin/console.php init-db`）。

> 三种办法都支持用**环境变量**代替 `config.php`（宝塔「PHP 配置 → 环境变量」）：
> `DB_HOST`、`DB_PORT`、`DB_NAME`、`DB_USER`、`DB_PASS`、`DB_CHARSET`、
> `JWT_SECRET`、`ADMIN_PASSWORD`、`ADMIN_JWT_SECRET`、`DEEPSEEK_API_KEY`、`DATA_DIR`、
> `PUBLIC_BASE_URL`、`TIMEZONE`、`LOG_LEVEL`、`TRUST_PROXY`、`WS_HOST`、`WS_PORT`。
> 环境变量优先级高于 `config.php`。

---

## 4. 最快上手（命令行 / 本地调试）

```bash
cd server-php

# 1) 先在浏览器完成安装向导（生成 config.php）
php -S 127.0.0.1:8787 -t public
#    打开 http://127.0.0.1:8787/install.php 填数据库信息

# 2) 自检（会显示数据库类型、版本、连接状态）
php bin/console.php check

# 3) 启动 REST（本地调试用；生产用 nginx + PHP-FPM）
php bin/console.php serve --port=8787
#    或者： php -S 0.0.0.0:8787 -t public

# 4) 另开一个终端启动 WebSocket 常驻进程
php bin/ws-server.php            # 默认 0.0.0.0:8900
```

浏览器打开：

- 安装向导： <http://127.0.0.1:8787/install.php>
- 后台管理： <http://127.0.0.1:8787/admin.php>
- 健康检查： <http://127.0.0.1:8787/api/health>
- 客户端元信息： <http://127.0.0.1:8787/api/meta>

> 内置服务器（`php -S`）会自动把不存在的路径交给 `public/index.php`，
> 所以 `http://127.0.0.1:8787/api/health` 与
> `http://127.0.0.1:8787/index.php/api/health` **两种写法都能用**。
>
> 还没安装时，访问 `/api/health`、`/admin.php` 不会 500：
> 页面会直接渲染安装向导（HTTP 503）；iOS 客户端这类非 HTML 请求会收到
> 结构化 JSON：`{"error":"db_unavailable","message":"服务尚未完成安装…","serverTime":…}`。

### 跑测试

```bash
php test/install-test.php    # 网页安装向导测试（独立测试库 couple_test_install）
php test/smoke.php           # 端到端测试（会真实启动 php -S 8899 与 ws-server 8900）

# 两个测试都会先 DROP + CREATE 自己的测试库，跑完 DROP 掉，不留脏数据。
# 结果写入 test-report.txt（先跑 install-test 再跑 smoke 时，smoke 会把安装报告一起合并进去）。
# 数据库连接默认 127.0.0.1:3307 / couple / couplepass123，
# 可用 TEST_DB_HOST / TEST_DB_PORT / TEST_DB_NAME / TEST_DB_USER / TEST_DB_PASS 覆盖。
```

---

## 5. 数据库说明（MySQL / MariaDB）

### 5.1 连接参数

| 配置项 | 环境变量 | 默认值 | 说明 |
| --- | --- | --- | --- |
| `db_host` | `DB_HOST` | `127.0.0.1` | 数据库主机 |
| `db_port` | `DB_PORT` | `3306` | 数据库端口（宝塔里可能不同） |
| `db_name` | `DB_NAME` | — | 数据库名（安装向导生成） |
| `db_user` | `DB_USER` | — | 数据库用户名 |
| `db_pass` | `DB_PASS` | — | 数据库密码 |
| `db_charset` | `DB_CHARSET` | `utf8mb4` | 固定 utf8mb4（支持 emoji） |
| `db_ssl_ca` | `DB_SSL_CA` | 空 | 需要 TLS 时填 CA 证书路径 |

DSN 形如：`mysql:host=127.0.0.1;port=3306;dbname=couple;charset=utf8mb4`

### 5.2 表结构

共 11 张表（字段名与 Node 版一致）：

| 表 | 用途 |
| --- | --- |
| `users` | 用户 |
| `couples` | 情侣空间（邀请码、纪念日） |
| `messages` | 聊天消息（幂等 client_id、已读、撤回） |
| `emoji_packs` / `emoji_items` | 表情包与表情元数据（图片本体在 `data/emoji/`） |
| `sync_objects` | 通用同步对象（火花/宠物/签到/愿望/加班/设置） |
| `settings` | 服务端键值设置（含 `schema_version`、后台密码哈希 `adminPasswordHash`） |
| `ai_usage` | AI 调用计数 |
| `admin_logs` | 后台操作日志 |
| `rate_limits` | 限流计数（内部表） |
| `ws_events` | 实时推送事件队列（内部表） |

**兼容性处理**（MySQL 5.7 / 8.0 / MariaDB 10.x 都能跑）：

- `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`，**不写** `COLLATE=utf8mb4_0900_ai_ci`（MySQL 8 专有）
- **不用** `JSON` 类型：JSON 文本存 `MEDIUMTEXT` / `LONGTEXT`，应用层 `json_encode`/`json_decode`
- **不用** `CHECK` 约束、窗口函数、CTE、`JSON_TABLE`
- 时间戳一律 `BIGINT` 存毫秒（不用 `DATETIME`/`TIMESTAMP`，避开时区坑）
- upsert 用 `INSERT ... ON DUPLICATE KEY UPDATE`（默认设置用 `INSERT IGNORE`，保证不覆盖后台改过的值）
- 自增主键 `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY`；字符串主键 `VARCHAR(64) PRIMARY KEY`
- 索引列都用 `VARCHAR`（utf8mb4 下 `VARCHAR(255)` 索引约 1020 字节，远低于 3072 上限），不给 `TEXT` 建索引
- `key` 是 MySQL 保留字，`settings` / `rate_limits` 的该列一律用反引号包裹

### 5.3 建表 / 迁移是幂等的

- 用 `CREATE TABLE IF NOT EXISTS` + `information_schema` 检查补列 / 补索引；
  连续启动任意多次都不会报错、不会重复建表、不会丢数据
- `settings.schema_version` 记录当前表结构版本（当前为 `2`）
- `INSERT IGNORE` 写默认设置，**已存在的键绝不覆盖**（后台改过的值不会被启动流程冲掉）

---

## 6. 后台管理

登录地址：`https://你的域名/admin.php`，密码 = 安装时设置的后台密码（或你在后台自助改过的新密码）。

功能与 Node 版一致：

| 分区 | 内容 |
| --- | --- |
| 总览 | 用户数 / 情侣数 / 消息数 / 今日与累计 AI 调用 / 磁盘占用 / 表情统计 / 同步对象 / **服务器信息** |
| AI 设置 | DeepSeek API Key（只显示掩码，可测试、可清空）、模型、API 地址、AI 开关、每日限额 |
| 应用设置 | 注册开关、公告、最低/最新版本号、更新地址、表情包上限、每日消息上限 |
| 用户管理 | 搜索、分页、重置密码、禁用/启用、删除 |
| 表情统计 | 包数 / 表情数 / 文件数 / 占用，以及各项限制 |
| **安全** | 后台密码来源 + **自助修改后台密码**（当前密码 / 新密码 / 确认新密码） |
| 操作日志 | 后台所有写操作（登录、改设置、重置密码、改后台密码、禁用、删除、AI 测试） |

「服务器信息」区块显示：PHP 版本、平台、运行方式、运行时长、内存、**数据库类型与版本**、
**数据库连接状态**、字符集、表结构版本、数据目录、时区、JWT 密钥是否配置、
后台密码是否配置**及其来源**、WebSocket 是否运行（数据来自 `GET /admin/api/system`，字段只增不改）。

### 6.1 后台密码存哪里、怎么改、忘了怎么办

**存哪里**

- 后台密码以 **bcrypt 哈希**（`password_hash($p, PASSWORD_BCRYPT)`，cost 与用户密码一致）保存在
  数据库 `settings` 表的 `adminPasswordHash` 键里。
- `config.php` 的 `admin_password` 降级为**初始密码 / 兜底密码**：
  - 数据库里**没有** `adminPasswordHash` 时 → 按 `config.php` 的明文校验；
    **第一次登录成功后立刻自动把哈希写入数据库**（自动迁移），之后即在后台自助改密码。
  - 数据库里**有** `adminPasswordHash` 时 → 只认数据库里的哈希，
    `config.php` 里的旧密码**立即失效**（改过就是改掉了，不留后门）。
- **哈希和明文永远不会返回给任何接口**：`GET /admin/api/settings`、`/api/meta`、
  `GET /admin/api/system` 只返回布尔/枚举值 `adminPasswordInDatabase`（true/false）、
  `adminPasswordSource`（`"database"` / `"config"`）；`Settings::readAll()` 也不携带哈希。
- 日志（`admin_logs` / `data/logs/app.log`）只记「成功 / 失败 / 当前密码错误」等动作，**不记密码**。

**怎么改（后台自助）**

1. 登录 `/admin.php` → 「安全」区块。
2. 填「当前密码 / 新密码 / 确认新密码」→ 点「修改后台密码」。
3. 校验规则：当前密码错误 → `401 unauthorized`；新密码不在 6-64 位 → `400 invalid_input`；
   新密码与当前密码相同 → `400`（中文提示）。成功后返回 `{"ok":true,...}`，
   **当前 admin token 继续有效**（不会把自己踢出登录），已签发的其它 token 也不受影响。
4. 该接口有独立限流（同一 IP 15 分钟最多 10 次），防止用暴力方式猜「当前密码」。

对应接口：`POST /admin/api/admin-password`，body `{ "currentPassword": "...", "newPassword": "..." }`
（需要 `Authorization: Bearer <admin token>`；这是后台专用接口，不影响 iOS App 的接口契约）。

**忘了密码怎么办（救援路径）**

```bash
# 方式 1（推荐）：命令行直接重置，写入新的 bcrypt 哈希
php bin/console.php set-admin-password 新密码
php bin/console.php set-admin-password      # 不带密码则交互式输入两遍（Linux 下输入不回显）

# 方式 2：回到 config.php 里的初始密码
#   删除 settings 表里的 adminPasswordHash 记录，例如：
mysql -u couple -p couple -e "DELETE FROM settings WHERE \`key\`='adminPasswordHash';"
#   之后再用 config.php 里的 admin_password 登录一次，会自动重新迁入数据库。

# 方式 3：删掉 config.php 重装（会生成新的 jwt_secret，已签发 token 失效）
```

查看当前密码来源：

```bash
php bin/console.php check
#   … 后台密码来源： 数据库        （或 config.php）
```


安全说明：

- DeepSeek Key 在写入数据库前用 **AES-256-GCM** 加密（密钥由 `jwt_secret` 派生），
  数据库被拿走也看不到明文；后台接口只返回掩码（如 `sk-********abcd`）。
- 用户 token 与后台 token 使用**不同密钥**（`jwt_secret` / `admin_jwt_secret`），互不通用。
- 用户 token 有效期 30 天；后台 token 12 小时。
- **后台密码**以 bcrypt 哈希存数据库（`settings.adminPasswordHash`），可在后台「安全」区块自助修改；
  接口只返回来源标记（`adminPasswordInDatabase` / `adminPasswordSource`），从不回显哈希或明文；
  修改密码有独立限流，且不会让已签发的 admin token 失效（见 6.1）。
- 安装向导：CSRF token（session）校验；数据库密码不回显；已安装后一律 403。

---

## 7. 日常维护命令

```bash
php bin/console.php check              # 配置/数据库版本/连接状态/后台密码来源/WS 状态自检
php bin/console.php init-db            # 建表 + 默认设置（幂等，可重复执行）
php bin/console.php set-admin-password 新密码   # 重置后台密码（写数据库 bcrypt 哈希；不带参数则交互输入）
php bin/console.php stats              # 打印统计
php bin/console.php sync-public-emoji  # 同步 public/emoji 下的内置公共表情包
php bin/console.php purge              # 清理解绑 30 天以上的情侣数据 + 过期限流记录
php bin/console.php gen-secret         # 生成随机密钥
```

内置公共表情包：把图片放到 `public/emoji/<包名>/*.png|jpg|gif|webp`，
执行 `sync-public-emoji`（或重启 WS 进程）后，所有用户都能在客户端看到该包（`isPublic: true`）。

---

## 8. 与 Node 版的差异（仅实现层面，接口契约不变）

| 项 | Node 版 | PHP 版 |
| --- | --- | --- |
| 数据库 | better-sqlite3 | **PDO MySQL / MariaDB**（宝塔面板建库） |
| 建库方式 | 代码内建文件 | **网页安装向导**自动建库建表 + 生成 config.php |
| 限流计数 | 进程内 Map | 数据库表 `rate_limits`（PHP 每个请求是独立进程） |
| 实时推送 | 与 REST 同进程直接广播 | REST 写 `ws_events` 队列 → `bin/ws-server.php` 轮询广播（≤300ms 延迟） |
| WebSocket | `ws` 库 | 纯 PHP `stream_socket_server` + `stream_select` + 手写帧编解码 |
| 密码 | bcryptjs 10 轮 | `password_hash(PASSWORD_BCRYPT, cost=10)` |
| 后台密码 | 只读 config.php | **存数据库 `settings.adminPasswordHash`（bcrypt）**，后台页面可自助修改；`config.php` 的 `admin_password` 仅作初始/兜底密码，首次登录自动迁入数据库 |
| JWT | jsonwebtoken 库 | 手写 HS256 |
| 内部表 | — | 额外两张内部表：`rate_limits`、`ws_events`（不对外暴露） |

`docs/API.md` 定义的接口、字段、错误码、限制值**逐条保持一致**。

---

## 9. 常见问题

**Q：打开站点看到「尚未完成安装」，怎么办？**
A：这是正常的 —— 说明 `config.php` 还没生成。页面下方就是安装表单，
填数据库信息提交即可。也可以直接访问 `/install.php`。

**Q：访问 `/api/health` 返回 404？**
A：伪静态没生效。两种解决办法：把 Base URL 改成 `https://域名/index.php/api`，
或按 2.1 配置 nginx 伪静态。

**Q：安装时报「数据库账号或密码错误」？**
A：核对宝塔「数据库」页面里的用户名与密码；确认主机填 `127.0.0.1`、端口与面板一致；
确认该账号已授权访问该库。

**Q：安装时报「代码目录不可写」？**
A：`chown -R www:www /www/wwwroot/你的站点/server-php`，或者按页面给出的
「可复制的配置内容」手动创建 `config.php`（见第 3 节）。

**Q：安装完了想换数据库信息 / 重装？**
A：删除 `server-php/config.php`，再打开 `/install.php` 重新填。注意重装会生成新的
`jwt_secret`，已签发的用户 token 会失效（客户端重新登录即可），已加密的 DeepSeek Key
需要重新在后台填一次。

**Q：忘了后台管理密码怎么办？**
A：三种办法（详见 6.1）：
① `php bin/console.php set-admin-password 新密码`（直接写数据库哈希，最省事）；
② 删除 `settings` 表里的 `adminPasswordHash` 记录，回到 `config.php` 里的初始密码
（再登录一次会自动迁入数据库）；
③ 删除 `config.php` 重装。

**Q：后台密码改了之后，`config.php` 里的旧密码还能用吗？**
A：不能。数据库里一旦有 `adminPasswordHash`，就只认它 —— 这正是「改了就要真的失效」。
想回到 `config.php` 的密码只能删掉该哈希（见上一条）。

**Q：后台能登录但保存设置报 500？**
A：`data/` 目录不可写。按 2.2 授权后重试；`data/logs/app.log` 里有具体原因。

**Q：客户端连不上 WebSocket？**
A：先 `php bin/console.php check` 看 WebSocket 是否运行；再确认 nginx 的 `/ws`
反向代理与 `proxy_set_header Upgrade`；最后确认用的是 `wss://` 而不是 `ws://`。

**Q：想迁移服务器？**
A：用 `mysqldump` 导出旧库、在新库导入；把 `data/` 目录（表情文件）整个拷过去；
`config.php` 里的 `jwt_secret` **必须保持一致**，否则已加密的 DeepSeek Key 与
已签发的 token 都会失效。

```bash
mysqldump -u couple -p couple > couple.sql     # 旧服务器
mysql -u couple -p couple < couple.sql         # 新服务器
```
