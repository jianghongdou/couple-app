# 情侣空间 · 自建服务端

Node.js 实现的情侣 App 服务端，完整实现 [`docs/API.md`](../docs/API.md) v1 契约。

同时提供四类服务：

| 入口 | 说明 |
| --- | --- |
| `/api/*` | REST API（鉴权、情侣关系、同步、聊天、表情、AI） |
| `/ws?token=<jwt>` | WebSocket 实时推送（消息、已读、输入中、在线状态、同步通知） |
| `/admin` | 后台管理单页（内联 CSS/JS，深色主题，中文界面） |
| `/api/emoji/blob/:id` | 表情图片二进制（支持 `?token=` 鉴权，供 iOS `AsyncImage` 使用） |

---

## 1. 快速开始（本地）

```bash
cd server

# 1) 安装依赖
npm install

# 2) 配置环境变量
cp .env.example .env       # Windows: copy .env.example .env
#   编辑 .env，至少填写 JWT_SECRET 与 ADMIN_PASSWORD

# 3) 生成一个安全的 JWT_SECRET
node -e "console.log(require('crypto').randomBytes(32).toString('hex'))"

# 4) 启动
npm start
```

启动后：

- 健康检查 <http://127.0.0.1:8787/api/health>
- 后台管理 <http://127.0.0.1:8787/admin>
- 客户端 Base URL：`http://127.0.0.1:8787/api`

要求 **Node 20 或更高版本**（已在 Node 24 上验证）。

### 常用命令

| 命令 | 作用 |
| --- | --- |
| `npm start` | 启动服务 |
| `npm run dev` | 开发模式（文件变更自动重启） |
| `npm test` | 端到端冒烟测试（237 项断言） |
| `npm run test:all` | 全量验证（冒烟 + 启动 + 配置 + AI + 边界行为） |
| `npm run init-db` | 只建库建表并写入默认设置，不启动服务 |
| `npm run fetch-sqlite` | 从镜像下载 better-sqlite3 预编译二进制（网络受限时使用） |

---

## 2. 环境变量

| 变量 | 默认 | 必填 | 说明 |
| --- | --- | --- | --- |
| `PORT` | `8787` | 否 | 监听端口 |
| `DATA_DIR` | `./data` | 否 | SQLite 与表情文件目录，相对路径基于 `server/` 解析 |
| `JWT_SECRET` | — | **是** | 用户 token 签名密钥，≥32 位随机串。缺失或过短会拒绝启动 |
| `ADMIN_PASSWORD` | — | **是** | 后台管理登录密码，至少 6 位。缺失会拒绝启动 |
| `ADMIN_JWT_SECRET` | 回退 `JWT_SECRET` | 否 | 后台 token 签名密钥，建议单独设置以完全隔离 |
| `PUBLIC_BASE_URL` | 空 | 否 | 表情绝对地址前缀，留空则返回相对地址 `/api/emoji/blob/xxx` |
| `TRUST_PROXY` | `1` | 否 | 是否信任反向代理的 `X-Forwarded-For`；直接暴露公网且无代理时设为 `0` |
| `LOG_LEVEL` | `info` | 否 | `debug` / `info` / `warn` / `error` / `silent` |

> 缺少 `JWT_SECRET` 或 `ADMIN_PASSWORD` 时服务会打印中文错误清单并 **以退出码 1 退出**，不会带着坏配置运行。

---

## 3. 生产部署

### 方式一：PM2（推荐单机部署）

```bash
cd server
npm install --omit=dev
cp .env.example .env && vim .env

# 建库（可选，首次启动也会自动建）
npm run init-db

pm2 start ecosystem.config.cjs
pm2 logs couple-server
pm2 save && pm2 startup      # 开机自启
```

### 方式二：Docker Compose

```bash
cd server
cp .env.example .env && vim .env
docker compose up -d
docker compose logs -f
```

数据持久化在命名卷 `couple-data` 中（数据库 + 表情图片）。

### 方式三：Nginx 反向代理 + HTTPS（必需）

iOS 的 ATS 要求正式环境使用 HTTPS。参考 `nginx.conf.example`：

1. 替换文件中的 `api.example.com` 为你的域名
2. 用 certbot 申请证书：`certbot --nginx -d api.example.com`
3. 部署配置：

```bash
cp nginx.conf.example /etc/nginx/conf.d/couple-server.conf
nginx -t && nginx -s reload
```

该示例已包含：

- HTTP → HTTPS 跳转与 TLS 安全配置
- **WebSocket 升级**（`/ws`，`Upgrade`/`Connection` 头 + 3600s 读超时，需大于客户端 25 秒心跳）
- 表情图片长缓存（`/api/emoji/blob/`）
- `client_max_body_size 8m`（表情走 base64 上传，预留余量）
- AI 接口 120s 超时

部署后客户端 Base URL 填 `https://api.example.com/api`。

### 备份

只需备份两个位置：

- `${DATA_DIR}/couple.sqlite`（连同 `-wal`、`-shm`）
- `${DATA_DIR}/emoji/`（表情图片二进制）

推荐 `sqlite3 couple.sqlite ".backup backup.sqlite"` 做在线备份。

---

## 4. 后台管理

访问 `/admin`，用 `ADMIN_PASSWORD` 登录（token 有效期 12 小时）。

页面功能：

- **总览**：用户数、情侣数、消息数、今日 AI 调用、磁盘占用、累计 AI 调用、表情包/表情数、同步对象数、服务信息
- **AI 设置**：DeepSeek API Key（掩码显示，带保存与「测试」按钮）、模型选择（`deepseek-chat` / `deepseek-reasoner`，可手填自定义）、API 地址、AI 开关、每日限额
- **应用设置**：注册开关、公告、最低/最新版本号、更新地址、表情包上限、每日消息上限
- **用户管理**：搜索、重置密码、禁用/启用、删除（支持分页）
- **表情统计**：包数、表情数、图片文件数、占用空间
- **操作日志**：最近 100 条后台写操作记录

所有写操作都会写入 `admin_logs` 表。

### DeepSeek API Key 的安全处理

1. Key **只存在服务端**，客户端永远拿不到明文（`/api/ai/*` 只做代理）
2. 落库前使用 **AES-256-GCM 加密**，密钥由 `JWT_SECRET` 派生
3. 后台接口只返回**掩码**（如 `sk-****abcd`），响应体中不含明文
4. 后台密码比较使用 `crypto.timingSafeEqual`，防时序侧信道

> ⚠️ 若更换 `JWT_SECRET`，已加密的 DeepSeek Key 将无法解密，需要在后台重新填写。

---

## 5. 客户端对接要点

- Base URL 填 `https://你的域名/api`，所有请求带 `Authorization: Bearer <jwt>`
- 所有响应都含 `serverTime`（毫秒），客户端应用它校准时间
- **同步**：`/api/sync/pull` 后用返回的 `serverTime` 作为下次 `since`，不要用本地时间
- **表情图片**：`GET /api/emoji/blob/<e_id>?token=<jwt>`，返回带一年强缓存的图片
- **WebSocket**：`wss://你的域名/ws?token=<jwt>`，客户端每 25 秒发 `{ "type": "ping" }` 保活；断线按 1s/2s/3s… 退避重连，重连后用 `/api/chat/messages` 补齐消息

### 记账与密码本不上传

`ledger`（记账）与 `vault`（密码本）**不参与服务端同步**，只存手机本地。
服务端会明确拒绝这两类数据，并返回中文说明：

```json
{
  "error": "invalid_input",
  "message": "kind \"ledger\" 不参与服务端同步：记账数据与密码本只保存在手机本地，服务端不接收、不存储。"
}
```

AI 记账接口也只做「文本 → 结构化 JSON」的转换，**服务端不落库明细**，仅记录匿名调用计数用于每日限额。

---

## 6. 目录结构

```
server/
├── package.json            # ESM，scripts: start/dev/test/test:all/init-db
├── .env.example            # 环境变量示例
├── .npmrc                  # npm 镜像与本地缓存配置（网络受限环境用）
├── Dockerfile
├── docker-compose.yml
├── ecosystem.config.cjs    # pm2 配置
├── nginx.conf.example      # 反向代理 + WebSocket + HTTPS 示例
├── scripts/
│   ├── fetch-sqlite-binary.mjs  # 下载 better-sqlite3 预编译二进制
│   ├── verify-start.mjs         # 启动验证
│   ├── verify-config.mjs        # 配置校验 + init-db 验证
│   ├── verify-ai.mjs            # AI 链路验证（本地模拟 DeepSeek）
│   ├── verify-public-emoji.mjs  # 内置公共表情包验证
│   └── verify-extras.mjs        # 保留期 / 限流 / 广播验证
├── public/
│   ├── admin.html          # 后台单页（内联 CSS/JS，中文，深色）
│   └── emoji/              # 内置公共表情包目录（isPublic=true）
├── src/
│   ├── index.js            # 入口：express + http + ws 挂载
│   ├── config.js           # 环境变量读取与启动校验
│   ├── db.js               # 唯一接触 SQL 的文件：建表/迁移/查询封装
│   ├── ws.js               # WebSocket 连接管理、在线状态、事件广播
│   ├── init-db.js          # 建库脚本
│   ├── middleware/
│   │   ├── auth.js         # 用户 JWT 校验
│   │   └── adminAuth.js    # 后台 JWT 校验（含 timingSafeEqual）
│   ├── routes/
│   │   ├── auth.js         # §2 登录与账号
│   │   ├── couple.js       # §3 情侣关系
│   │   ├── sync.js         # §4 通用数据同步
│   │   ├── chat.js         # §5 聊天
│   │   ├── emoji.js        # §6 表情包
│   │   ├── ai.js           # §7 AI 代理
│   │   ├── meta.js         # §1 公开接口
│   │   └── admin.js        # §9 后台管理
│   ├── services/
│   │   ├── deepseek.js     # DeepSeek 调用：超时/重试/JSON 容错
│   │   └── settings.js     # settings 读写 + Key 加密与掩码
│   └── util/
│       ├── validate.js     # 手写参数校验（无第三方校验库）
│       ├── ratelimit.js    # 内存滑动窗口限流
│       ├── ids.js          # id 生成（u_/c_/m_/p_/e_ 前缀）
│       └── log.js          # 结构化日志
└── test/
    ├── smoke.mjs           # 端到端冒烟测试（npm test）
    └── run.mjs             # 全量验证入口（npm run test:all）
```

### 数据库表

`users`、`couples`、`messages`、`emoji_packs`、`emoji_items`、`sync_objects`、`settings`、`ai_usage`、`admin_logs`

已开启 WAL 模式（`journal_mode=WAL`）。

---

## 7. 安全设计

- 密码使用 **bcryptjs 10 轮** 哈希存储，接口从不返回哈希
- **所有 SQL 使用参数绑定**（`prepare` + 绑定参数），无字符串拼接 SQL
- 用户 token 与后台 token 使用**不同 secret**、不同 `typ` 声明，互不可冒充
- JWT 有效期 30 天（后台 12 小时）
- 后台密码比较使用 `crypto.timingSafeEqual`
- **表情上传双重校验**：解码后体积上限 1MB + magic bytes 校验（JPEG `FFD8FF` / PNG `89504E47` / GIF `474946` / WEBP `RIFF....WEBP`），**不信任客户端声明的 mime**；声明与实际不符也会被拒绝
- **表情 blob 防路径穿越**：文件名完全由服务端生成的 id 决定，校验 id 格式 `^e_[A-Za-z0-9]+$`，并二次断言最终路径仍位于表情目录内
- 表情消息正文、`meta`、`payload` 均有体积上限；聊天 `body` 上限 2000 字符
- 未绑定情侣关系的用户**不能读写消息与表情**（403 `forbidden`）
- 限流：登录/注册 15 分钟 30 次，后台登录 15 分钟 10 次，AI 每分钟 30 次
- 响应头 `X-Content-Type-Options: nosniff`，API 响应 `Cache-Control: no-store`

---

## 8. 测试

```bash
# 端到端冒烟测试（真实启动服务进程 + 断言 API 契约）
npm test

# 全量验证（5 组）
npm run test:all
```

`npm test` 会生成一次性测试环境（随机 `JWT_SECRET`、`ADMIN_PASSWORD=test123`、`PORT=8799`、`DATA_DIR=./data-test`），
启动真实服务进程后完整走一遍：健康检查 → 注册 → 建情侣空间 → 邀请码加入 → 同步推送/拉取（含 ledger 拒绝）
→ 聊天（clientId 幂等、已读、撤回、WebSocket 实时推送）→ 表情包（**手写 8×8 真实 PNG 上传**、
magic bytes 拒绝伪造图片、路径穿越拒绝）→ 后台（掩码校验、统计、用户管理、操作日志），
最后杀掉进程并删除 `data-test`。

已验证结果：

| 验证套件 | 断言数 | 结果 |
| --- | --- | --- |
| 冒烟测试 `npm test` | 256 | ✅ ALL TESTS PASSED |
| 启动验证 | 8 | ✅ 无 error 日志、SIGTERM 优雅退出 |
| 配置验证 | 10 | ✅ 缺变量明确报错、9 张表齐备、WAL |
| AI 链路验证 | 78 | ✅ 容错解析、重试、限额、掩码 |
| 公共表情包验证 | 37 | ✅ 内置包扫描、magic bytes、隔离 |
| 补充行为验证 | 54 | ✅ 保留期、限流、广播、分页 |

最近一次全量验证：**6 组全部通过**（`npm run test:all`），输出留档在 `test-report.txt` 与
`verify-*-report.txt`。

---

## 9. 已知限制

1. **限流与在线状态为单进程内存实现**
   多实例部署时各自的计数互相独立。如需横向扩展，请把 `src/util/ratelimit.js` 换成 Redis 实现，
   并用 Redis Pub/Sub 替换 `src/ws.js` 的进程内广播。

2. **WebSocket 广播依赖单实例**
   当前 `broadcastToCouple` 只覆盖本进程内的连接。多实例需引入 Redis 适配器。

3. **无内置定时任务调度**
   已解绑情侣数据在服务启动后每 24 小时清理一次（保留 30 天）。未运行期间不会执行清理。

4. **表情上传走 base64**
   受 `express.json({ limit: '4mb' })` 限制。若要支持更大图片需同时调大该限制与 Nginx 的
   `client_max_body_size`，并相应调整 `LIMITS.EMOJI_MAX_BYTES`。

5. **`ADMIN_PASSWORD` 为明文环境变量比较**
   未做哈希存储（与 API.md 契约一致：后台只有一个管理密码）。
   若需要多管理员与审计，建议扩展为账号表 + 哈希存储。

6. **系统消息计入每日消息上限**
   建情侣空间与加入时各写入一条 `system` 消息，`senderId` 为触发操作用户，
   因此会占用该用户的 `maxMessagesPerDay` 额度。

7. **`PUBLIC_BASE_URL` 留空时表情 URL 为相对路径**
   客户端需自行拼接域名。若客户端依赖绝对地址，请设置该变量。

8. **better-sqlite3 为原生模块**
   安装时需要与 Node ABI 匹配的预编译二进制。部分网络环境无法访问 GitHub Releases，
   此时可执行 `npm run fetch-sqlite` 从 npmmirror 镜像下载（脚本会写入正确的 `build/Release/better_sqlite3.node`）。
   若仍失败，可安装构建工具后从源码编译（Debian: `apt install python3 make g++`）。

9. **AI 功能需要自行准备 DeepSeek API Key**
   仓库不含任何 Key。未配置时 `/api/ai/parse-ledger` 返回 403 `forbidden` + `message: "AI 未配置"`。

10. **`DELETE /api/emoji/items/:id` 已同步进 API.md**
    该接口原本只在客户端使用，现已在 `docs/API.md` §6 中正式定义（删除单张表情，只能删本人上传的）。

---

## 10. 故障排查

| 现象 | 原因与处理 |
| --- | --- |
| 启动即退出，提示缺少 `JWT_SECRET` | 未创建 `.env` 或未填写。参考 `.env.example` |
| `端口 8787 已被占用` | 换 `PORT`，或结束占用进程（Windows: `netstat -ano \| findstr 8787`） |
| 客户端表情图片 401 | `?token=` 未带或 token 过期；iOS 需用 `AsyncImage(url:)` 带 query token |
| 表情上传 400 | 检查是否真实图片、体积是否 ≤1MB、声明 mime 与实际格式是否一致 |
| WebSocket 连不上 | Nginx 缺少 `Upgrade`/`Connection` 头映射，或读超时小于 25 秒心跳 |
| AI 返回 403 `AI 未配置` | 后台「AI 设置」未填写 DeepSeek API Key |
| AI 返回 429 | 达到每日限额，后台调整 `每日调用限额`，0 表示不限制 |
| 同步时 `conflicts` 非空 | 属正常 LWW 行为：服务端版本更新，客户端应改为使用服务端版本 |
| 更换 `JWT_SECRET` 后 AI 报错 | Key 无法解密，需在后台重新填写 DeepSeek API Key |

---

## 11. 接口实现说明

完整契约见 [`docs/API.md`](../docs/API.md)。实现与文档**逐条对齐**，另有以下补充（不影响已定义接口，客户端可忽略）：

### 11.1 超出 API.md 的补充接口

| 接口 | 说明 |
| --- | --- |
| `DELETE /api/emoji/items/:id` | 删除单张表情（只能删本人上传的），见 API.md §6 |
| `GET /api/status` | 运维概览（用户数/情侣数/消息数/在线数），非契约接口 |
| `GET /admin/api/system` | 为后台页面展示 Node 版本、平台、内存等运行信息 |

`DELETE /api/emoji/items/:id` 行为：

- 需鉴权（`Authorization: Bearer <jwt>`）
- id 必须匹配 `^e_[A-Za-z0-9]+$`，否则 400 `invalid_input`（该 id 绝不参与文件路径拼接）
- 表情不存在 → 404 `not_found`
- 非本人上传 → 403 `forbidden`
- 公共表情包（`is_public=true`）中的表情 → 403 `forbidden`
- 成功返回 `{ "ok": true, "serverTime": <ms> }`，同时删除数据库记录与磁盘图片文件

### 11.2 已定义接口的字段/行为补充

- `/api/meta` 额外返回 `aiConfigured`（布尔），用于区分「AI 开关关闭」与「已开启但未配置 Key」
- 后台设置读写在契约的 11 个字段之外，额外返回 `deepseekApiKeyConfigured`（布尔）
- WebSocket 额外事件：`couple`（绑定关系变化）、`message_deleted`（消息撤回）
- `POST /api/chat/messages` 对 `emoji`/`image` 类型额外校验 `body` 必须为 `item:<id>` 或 `unicode:<emoji>` 格式
- 解绑后数据保留 30 天通过 `couples.dissolved_at` 实现，到期由后台任务清理

### 11.3 关于 `/api/ai/status` 与 `/api/status`

两者**挂载路径不同、互不冲突**，但命名相近容易混淆：

| 路径 | 来源 | 是否契约接口 | 内容 |
| --- | --- | --- | --- |
| `/api/ai/status` | `src/routes/ai.js`，挂载于 `/api/ai` | ✅ 是（API.md §7） | AI 开关、是否已配置 Key、模型、每日限额、今日已用次数 |
| `/api/status` | `src/routes/meta.js`，挂载于 `/api` | ❌ 否（本实现新增） | 用户数、情侣数、消息数、在线设备数、运行时长 |

Express 路由按前缀精确匹配，`/api/ai/status` 由 `aiRoutes` 先匹配（`/api/ai` 挂载在前），
不会落到 `metaRoutes` 的 `/status`。若后续要避免歧义，可把运维接口改名为
`/api/health/detail` 或 `/api/ops/status`。
