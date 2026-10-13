# 情侣空间服务端 · PHP 版

> 这是 Node 版服务端（`server/`）的 **纯 PHP 重写**。
> 接口契约与 `docs/API.md` 完全一致，**iOS 客户端一行都不用改**。
>
> - 不需要 Composer，不需要 `vendor/`，不依赖任何第三方库
> - 只需要 PHP 内置扩展：`pdo_sqlite`、`openssl`、`mbstring`、`curl`、`zlib`
> - 数据库是单文件 SQLite（`data/couple.sqlite`，开启 WAL），不用 MySQL
> - 兼容 **PHP 8.1** 及以上（宝塔常见版本即可）

---

## 1. 目录结构

```
server-php/
├── README.md              本文档（中文部署说明）
├── config.example.php     配置模板（复制为 config.php 后填写）
├── .gitignore             忽略 config.php / data/ / 日志
├── public/
│   ├── index.php          REST 入口（伪静态与 /index.php/api/... 兜底都走它）
│   ├── admin.php          后台管理单页（内联 CSS/JS，深色主题，全中文）
│   └── emoji/             内置公共表情包目录（可选，放 <包名>/<图片> 即可）
├── src/                   自己的分层
│   ├── Bootstrap.php      启动引导（自动加载 / 配置校验 / 路由分发 / 错误处理）
│   ├── Config.php         配置加载与启动校验
│   ├── Db.php             数据库层（唯一接触 SQL 的文件）
│   ├── Http.php           请求解析与响应输出
│   ├── Router.php         按路径段匹配的极简路由器
│   ├── Jwt.php            手写 JWT HS256（base64url + hash_hmac + hash_equals）
│   ├── Auth.php           用户 / 后台鉴权
│   ├── RateLimit.php      SQLite 表实现的限流
│   ├── Settings.php       settings 表读写 + DeepSeek Key 加密/掩码
│   ├── DeepSeek.php       DeepSeek 代理（超时重试 + JSON 容错解析）
│   ├── PublicEmoji.php    内置公共表情包同步
│   ├── EmojiStore.php     表情文件磁盘存取（路径完全由服务端决定）
│   ├── WsOutbox.php       实时推送事件队列（REST → WS 常驻进程）
│   ├── WsHub.php          WebSocket 在线状态（跨进程只读视图）
│   ├── WsServer.php       纯 PHP WebSocket 服务端（事件循环 + 帧编解码）
│   ├── Runtime.php / Log.php / Time.php / HttpException.php / AiError.php
│   ├── routes/            REST 路由：Meta / Auth / Couple / Sync / Chat / Emoji / Ai / Admin
│   └── util/              Ids（id 生成）/ Validate（参数校验）/ Image（magic bytes）
├── bin/
│   ├── ws-server.php      WebSocket 常驻进程
│   └── console.php        命令行工具（自检 / 建库 / 同步表情 / 清理 / 本地起服务）
├── data/                  运行时生成：数据库 + 表情文件 + 日志（不进 git）
└── test/smoke.php         端到端测试（php test/smoke.php）
```

---

## 2. 最快上手（命令行 / 本地调试）

```bash
cd server-php
cp config.example.php config.php
# 编辑 config.php：至少填 jwt_secret 与 admin_password
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"   # 生成 jwt_secret

php bin/console.php check        # 配置自检（不启动服务）
php bin/console.php init-db      # 建表（也可以跳过，首次请求会自动建）

# 1) 启动 REST（本地调试用；生产用 nginx + PHP-FPM）
php -S 0.0.0.0:8787 -t public

# 2) 另开一个终端启动 WebSocket 常驻进程
php bin/ws-server.php            # 默认 0.0.0.0:8900
```

浏览器打开：

- 后台管理： <http://127.0.0.1:8787/admin>
- 健康检查： <http://127.0.0.1:8787/api/health>
- 客户端元信息： <http://127.0.0.1:8787/api/meta>

> 内置服务器（`php -S`）会自动把不存在的路径交给 `public/index.php`，
> 所以 `http://127.0.0.1:8787/api/health` 与
> `http://127.0.0.1:8787/index.php/api/health` **两种写法都能用**。

### 跑端到端测试

```bash
php test/smoke.php
# 会真实启动 php -S 127.0.0.1:8899 与 bin/ws-server.php（8900），
# 逐条断言后用 curl/原生 socket 校验，最后把结果写入 test-report.txt
```

---

## 3. 宝塔面板部署（推荐）

### 3.1 上传与站点

1. 把 `server-php/` 整个目录上传到站点根目录，例如 `/www/wwwroot/couple.example.com/server-php`。
2. 宝塔 → 网站 → 添加站点（PHP 版本选 **8.1 / 8.2 / 8.3** 均可）。
3. 站点设置 → **网站目录** → 运行目录选择 `/server-php/public`，保存。
   （运行目录必须是 `public`，否则 `src/`、`data/` 会被浏览器直接访问）
4. 站点设置 → **配置文件**，加上伪静态规则。

### 3.2 伪静态规则（nginx）

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

### 3.3 配置

在站点根目录（`server-php/`）新建 `config.php`（从 `config.example.php` 复制）：

```php
<?php
return [
    'jwt_secret'      => '至少32位随机串',        // 必填
    'admin_password'  => '后台管理密码',           // 必填，≥6 位
    'deepseek_api_key'=> '',                     // 可留空，登录后台在「AI 设置」里填
    'data_dir'        => './data',               // 建议写到站点外，见下
    'public_base_url' => 'https://couple.example.com',
    'timezone'        => 'Asia/Shanghai',
    'ws_host'         => '127.0.0.1',
    'ws_port'         => 8900,
];
```

也可以用环境变量（宝塔「PHP 配置 → 环境变量」或 `www.conf`），优先级高于 `config.php`：
`JWT_SECRET`、`ADMIN_PASSWORD`、`ADMIN_JWT_SECRET`、`DEEPSEEK_API_KEY`、`DATA_DIR`、
`PUBLIC_BASE_URL`、`TIMEZONE`、`LOG_LEVEL`、`TRUST_PROXY`、`WS_HOST`、`WS_PORT`。

> 缺 `jwt_secret` 或 `admin_password` 时，服务端会打印中文提示并**拒绝启动**，不会带病运行。

### 3.4 data 目录权限（重要）

```bash
chown -R www:www /www/wwwroot/你的站点/server-php/data
chmod -R 755 /www/wwwroot/你的站点/server-php/data
```

- `data/` 里会有：`couple.sqlite`（+ `-wal`/`-shm`）、`emoji/`（用户上传的表情）、`logs/app.log`
- 权限不足时接口会返回 `服务器内部错误`，日志在 `data/logs/app.log` 里能看到原因
- 想放到站点外（更安全）：把 `data_dir` 改成绝对路径，例如 `/www/wwwroot/couple-data`，并同样授权给 `www`

### 3.5 WebSocket 常驻

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

### 3.6 HTTPS

宝塔 → 网站 → SSL → Let's Encrypt 申请证书并开启强制 HTTPS。
iOS 对明文 HTTP 有限制，生产环境务必上 HTTPS（`wss://`）。

---

## 4. 后台管理

登录地址：`https://你的域名/admin`，密码 = `admin_password`。

功能与 Node 版一致：

| 分区 | 内容 |
| --- | --- |
| 总览 | 用户数 / 情侣数 / 消息数 / 今日与累计 AI 调用 / 磁盘占用 / 表情统计 / 同步对象 / 服务信息 |
| AI 设置 | DeepSeek API Key（只显示掩码，可测试、可清空）、模型、API 地址、AI 开关、每日限额 |
| 应用设置 | 注册开关、公告、最低/最新版本号、更新地址、表情包上限、每日消息上限 |
| 用户管理 | 搜索、分页、重置密码、禁用/启用、删除 |
| 表情统计 | 包数 / 表情数 / 文件数 / 占用，以及各项限制 |
| 操作日志 | 后台所有写操作（登录、改设置、重置密码、禁用、删除、AI 测试） |

安全说明：

- DeepSeek Key 在写入数据库前用 **AES-256-GCM** 加密（密钥由 `jwt_secret` 派生），
  数据库文件被拿走也看不到明文；后台接口只返回掩码（如 `sk-********abcd`）。
- 用户 token 与后台 token 使用**不同密钥**（`jwt_secret` / `admin_jwt_secret`），互不通用。
- 用户 token 有效期 30 天；后台 token 12 小时。

---

## 5. 日常维护命令

```bash
php bin/console.php check              # 配置/数据库/WS 状态自检
php bin/console.php stats              # 打印统计
php bin/console.php sync-public-emoji  # 同步 public/emoji 下的内置公共表情包
php bin/console.php purge              # 清理解绑 30 天以上的情侣数据 + 过期限流记录
php bin/console.php gen-secret         # 生成随机密钥
```

内置公共表情包：把图片放到 `public/emoji/<包名>/*.png|jpg|gif|webp`，
执行 `sync-public-emoji`（或重启 WS 进程）后，所有用户都能在客户端看到该包（`isPublic: true`）。

---

## 6. 与 Node 版的差异（仅实现层面，接口契约不变）

| 项 | Node 版 | PHP 版 |
| --- | --- | --- |
| 数据库 | better-sqlite3 | PDO SQLite（WAL） |
| 限流计数 | 进程内 Map | SQLite 表 `rate_limits`（PHP 每个请求是独立进程） |
| 实时推送 | 与 REST 同进程直接广播 | REST 写 `ws_events` 队列 → `bin/ws-server.php` 轮询广播（≤300ms 延迟） |
| WebSocket | `ws` 库 | 纯 PHP `stream_socket_server` + `stream_select` + 手写帧编解码 |
| 密码 | bcryptjs 10 轮 | `password_hash(PASSWORD_BCRYPT, cost=10)` |
| JWT | jsonwebtoken 库 | 手写 HS256 |
| 内部表 | — | 额外两张内部表：`rate_limits`、`ws_events`（不对外暴露） |

`docs/API.md` 定义的接口、字段、错误码、限制值**逐条保持一致**。

---

## 7. 常见问题

**Q：访问 `/api/health` 返回 404？**
A：伪静态没生效。两种解决办法：把 Base URL 改成 `https://域名/index.php/api`，
或按 3.2 配置 nginx 伪静态。

**Q：后台能登录但保存设置报 500？**
A：`data/` 目录不可写。按 3.4 授权后重试；`data/logs/app.log` 里有具体原因。

**Q：客户端连不上 WebSocket？**
A：先 `php bin/console.php check` 看 WebSocket 是否运行；再确认 nginx 的 `/ws`
反向代理与 `proxy_set_header Upgrade`；最后确认用的是 `wss://` 而不是 `ws://`。

**Q：想换数据库/迁移服务器？**
A：整个 `data/` 目录拷过去即可（SQLite 单文件）。注意 `config.php` 里的 `jwt_secret`
必须保持一致，否则已加密的 DeepSeek Key 与已签发的 token 都会失效。

**Q：并发够用吗？**
A：这是给「两个人」用的服务。SQLite + WAL 足以支撑日常使用；
WebSocket 是单进程单线程事件循环，几十个连接毫无压力。
