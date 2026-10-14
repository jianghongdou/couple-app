# 情侣 App 服务端 API 契约 v1

> 本文档是 **iOS 客户端** 与 **Node 服务端** 之间的唯一真实来源。
> 任何一方改动接口，都必须先改本文档。

## 0. 通用约定

- Base URL：`https://你的域名/api`，本地调试可用 `http://192.168.x.x:8787/api`
- 请求体与响应体统一为 `application/json; charset=utf-8`（表情图片上传除外）
- 时间字段统一使用 **Unix 毫秒时间戳（number）**，字段名以 `At` 结尾；日期字段用 `YYYY-MM-DD` 字符串，字段名以 `Date` 或 `Key` 结尾
- 鉴权头：`Authorization: Bearer <jwt>`
- 成功：HTTP 200 + JSON 对象；失败：HTTP 4xx/5xx + `{ "error": "错误码", "message": "中文或英文提示" }`
- 常用错误码：`unauthorized`(401)、`forbidden`(403)、`not_found`(404)、`invalid_input`(400)、`conflict`(409)、`rate_limited`(429)、`server_error`(500)
- 所有接口都会返回 `serverTime`（毫秒），客户端用于校准时间

---

## 1. 公开接口

### GET /api/health
```json
{ "ok": true, "name": "couple-server", "version": "1.0.0", "time": 1735000000000 }
```

### GET /api/meta
无需鉴权。客户端启动时拉取，用于版本号显示、公告、开关判断。
```json
{
  "appName": "情侣空间",
  "serverVersion": "1.0.0",
  "minAppBuild": 1,
  "latestAppBuild": 3,
  "updateURL": "https://example.com/download",
  "announcement": "欢迎使用",
  "registerOpen": true,
  "features": { "chat": true, "emoji": true, "ai": true, "sync": true },
  "serverTime": 1735000000000
}
```

---

## 2. 登录与账号

### POST /api/auth/register
请求：
```json
{ "username": "xiaoming", "password": "123456", "nickname": "小明", "avatarEmoji": "🐻", "inviteCode": "AB12CD" }
```
- `username`：3–32 位，只允许 `a-zA-Z0-9_`
- `password`：6–64 位
- `avatarEmoji`：可选，单个 emoji
- `inviteCode`：可选。填了就直接加入对方的情侣关系
- `registerOpen=false` 时返回 403 `forbidden`

响应：
```json
{
  "token": "eyJhbGciOi...",
  "user": { "id": "u_1", "username": "xiaoming", "nickname": "小明", "avatarEmoji": "🐻", "coupleId": "c_1", "role": "user", "disabled": false, "createdAt": 1735000000000 },
  "couple": { "id": "c_1", "inviteCode": "AB12CD", "togetherDate": null, "anniversaryTitle": "在一起", "members": [ /* user 数组 */ ] },
  "serverTime": 1735000000000
}
```
`couple` 在未绑定时为 `null`。

### POST /api/auth/login
请求 `{ "username": "...", "password": "..." }`，响应同 `register`。
账号被禁用返回 403 `forbidden`；密码错误返回 401 `unauthorized`。

### GET /api/auth/me
响应 `{ "user": {...}, "couple": {...}|null, "serverTime": ... }`

### PATCH /api/auth/me
请求 `{ "nickname": "新昵称", "avatarEmoji": "🐰" }`（都可选），响应 `{ "user": {...} }`

### POST /api/auth/password
请求 `{ "oldPassword": "...", "newPassword": "..." }`，响应 `{ "ok": true }`

---

## 3. 情侣关系

### POST /api/couple/create
未绑定用户创建情侣空间。响应 `{ "couple": {...} }`（含 6 位 `inviteCode`）
已有伴侣返回 409 `conflict`。

### POST /api/couple/join
请求 `{ "inviteCode": "AB12CD" }`，响应 `{ "couple": {...} }`
- 邀请码无效 → 404 `not_found`
- 该空间已满（2 人）→ 409 `conflict`

### GET /api/couple
响应 `{ "couple": {...} }`，`members` 含双方完整 user 对象。

### PATCH /api/couple
请求 `{ "togetherDate": 1700000000000, "anniversaryTitle": "在一起" }`（都可选）
响应 `{ "couple": {...} }`

### POST /api/couple/leave
解除绑定，双方 `coupleId` 置空（数据保留在服务端 30 天）。响应 `{ "ok": true }`

---

## 4. 通用数据同步（火花 / 宠物 / 签到 / 愿望 / 加班记录）

> **重要：记账数据（ledger）与密码本（vault）不参与同步，只存手机本地。**

冲突策略：**最后写入者胜（LWW）**，按对象级 `updatedAt` 比较。

允许的 `kind` 白名单：`spark`、`pet`、`checkin`、`wish`、`worklog`、`settings`
（服务端必须拒绝其它 kind，返回 400 `invalid_input`）

### POST /api/sync/push
请求：
```json
{
  "objects": [
    {
      "kind": "wish",
      "id": "w_1712345678901",
      "updatedAt": 1735000000000,
      "deleted": false,
      "payload": { "title": "去海边", "targetCoins": 300 }
    }
  ]
}
```
- 单次最多 200 个对象
- `payload` 为任意 JSON 对象（客户端自定义结构），服务端只存不解
- `deleted=true` 表示逻辑删除（墓碑）

响应：
```json
{ "applied": ["w_1712345678901"], "conflicts": [], "serverTime": 1735000000000 }
```
`conflicts` 中是服务端版本更新、拒绝了本次写入的对象 id。

### GET /api/sync/pull?since=<ms>&kinds=spark,pet&limit=500
- `since`：上次同步时间戳（毫秒）。首次传 0
- `kinds`：可选，逗号分隔，缺省为全部白名单
- `limit`：可选，默认 500，上限 1000

响应：
```json
{
  "objects": [
    { "kind": "pet", "id": "pet", "updatedAt": 1735000000000, "deleted": false,
      "ownerId": "u_1", "updatedBy": "u_1", "payload": { "level": 3 } }
  ],
  "serverTime": 1735000000000,
  "hasMore": false
}
```
客户端拉取时应使用 `serverTime` 作为下次的 `since`（不要用本地时间）。

> 实现说明（给服务端）：
> - `since=0` 必须被当作「第一次同步」，返回当前情侣空间内**全部**对象（受 `limit` 限制）。
> - 服务端建议额外维护一个自己的写入时间戳（例如 `server_updated_at`）用于 `since` 过滤，
>   避免把手机时钟与服务器时钟混用。客户端目前每次都以 `since=0` 全量拉取并按 `updatedAt` 做
>   最后写入者胜（LWW）合并，因此即使时间戳有偏差也不会丢数据。
> - `limit` 上限 1000；对象数量超过 `limit` 时 `hasMore=true`。

---

## 5. 聊天

消息结构：
```json
{
  "id": "m_1712345678901",
  "clientId": "c_1712345678901",
  "coupleId": "c_1",
  "senderId": "u_1",
  "kind": "text",
  "body": "晚安",
  "meta": null,
  "createdAt": 1735000000000,
  "readAt": null
}
```
- `kind`：`text` | `emoji` | `image` | `system`
  - `text`：body 为文字
  - `emoji`：body 为 `unicode:😀` 或 `item:<表情id>`
  - `image`：body 为 `item:<表情id>`（发送表情包图片）
  - `system`：body 为系统提示文字（如「你们已绑定」）
- `meta`：任意 JSON（可空），例如 `{ "w": 240, "h": 240 }`

### GET /api/chat/messages?before=<ms>&limit=50
- 不传 `before` 取最新 50 条
- 返回按 `createdAt` 升序

响应 `{ "messages": [ ... ], "hasMore": true, "serverTime": ... }`

### POST /api/chat/messages
请求 `{ "clientId": "c_...", "kind": "text", "body": "晚安", "meta": null }`
响应 `{ "message": {...} }`
- `clientId` 幂等：重复提交返回已存在的那条消息
- 未绑定情侣 → 403 `forbidden`
- body 长度上限 2000 字符

### POST /api/chat/read
请求 `{ "upTo": 1735000000000 }`，把 `createdAt <= upTo` 且对方发来的消息标记为已读。
响应 `{ "ok": true, "count": 3 }`

### DELETE /api/chat/messages/:id
撤回自己发的消息（24 小时内），响应 `{ "ok": true }`

---

## 6. 表情包（抖音风格表情 + 自定义上传）

图片二进制存服务端磁盘，元数据存数据库。

### GET /api/emoji/packs
响应：
```json
{
  "packs": [
    { "id": "p_1", "name": "我的表情", "ownerId": "u_1", "isPublic": false,
      "createdAt": 1735000000000, "updatedAt": 1735000000000,
      "items": [
        { "id": "e_1", "packId": "p_1", "ownerId": "u_1", "mime": "image/jpeg",
          "size": 24567, "name": "大笑", "url": "/api/emoji/blob/e_1",
          "createdAt": 1735000000000, "updatedAt": 1735000000000 }
      ] }
  ],
  "serverTime": 1735000000000
}
```
- 同一情侣空间内双方都能看到对方的包
- `isPublic=true` 为内置公共表情包（服务端目录 `public/emoji` 下预置）

### POST /api/emoji/packs
请求 `{ "name": "我的表情" }`，响应 `{ "pack": {...} }`
限制：每个情侣空间最多 30 个自建包。

### DELETE /api/emoji/packs/:id
只能删自己创建的包（`system` 包不可删）。响应 `{ "ok": true }`

### POST /api/emoji/items
请求（base64 上传，客户端已压缩到 512KB 以内）：
```json
{ "packId": "p_1", "mime": "image/jpeg", "name": "大笑", "dataBase64": "/9j/4AAQ..." }
```
- `mime` 白名单：`image/jpeg`、`image/png`、`image/gif`、`image/webp`
- 解码后单张上限 1MB；每个包上限 200 张
- 响应 `{ "item": {...} }`

### DELETE /api/emoji/items/:id
删除单张表情，**只能删除本人上传的**。
- id 必须匹配 `^e_[A-Za-z0-9]+$`，否则 400 `invalid_input`
- 表情不存在 → 404 `not_found`
- 非本人上传 → 403 `forbidden`
- 公共表情包（`isPublic=true`）中的表情 → 403 `forbidden`

响应 `{ "ok": true, "serverTime": ... }`，同时删除数据库记录与磁盘上的图片文件。

### GET /api/emoji/blob/:id
返回图片二进制（`Content-Type` 为对应 mime，带 `Cache-Control: public, max-age=31536000, immutable`）。
鉴权方式二选一：`Authorization` 头，或查询参数 `?token=<jwt>`（供 iOS `AsyncImage` 使用）。
只能访问同情侣空间或公共表情。

---

## 7. AI（DeepSeek 代理）

> DeepSeek 的 API Key **只存在服务端**，由后台管理页面填写，客户端永远拿不到 Key。
> 记账数据本身永远只存手机本地，服务端只做「文本 → 结构化 JSON」的转换，**不落库**（除匿名调用计数外）。

### GET /api/ai/status
响应 `{ "enabled": true, "configured": true, "model": "deepseek-chat", "dailyLimit": 200, "usedToday": 12 }`

### POST /api/ai/parse-ledger
请求：
```json
{ "text": "今天午饭25，打车18块5，买菜一共83.6", "currency": "CNY", "today": "2025-06-01" }
```
响应：
```json
{
  "items": [
    { "amount": 25,    "kind": "expense", "category": "餐饮", "note": "午饭",   "date": "2025-06-01" },
    { "amount": 18.5,  "kind": "expense", "category": "交通", "note": "打车",   "date": "2025-06-01" },
    { "amount": 83.6,  "kind": "expense", "category": "餐饮", "note": "买菜",   "date": "2025-06-01" }
  ],
  "summary": "3 笔支出，共 127.1 元",
  "total": 127.1,
  "model": "deepseek-chat",
  "serverTime": 1735000000000
}
```
- `kind`：`expense` | `income`
- `category` 建议取值：`餐饮`、`交通`、`购物`、`娱乐`、`居住`、`医疗`、`学习`、`人情`、`工资`、`理财`、`其它`
- 未配置 Key → 403 `forbidden`（`message: "AI 未配置"`）
- 超出每日限额 → 429 `rate_limited`

### POST /api/ai/monthly-report
请求（客户端把本地统计好的汇总发上来，服务端不接触明细）：
```json
{ "month": "2025-06", "totals": { "expense": 3210.5, "income": 12000 },
  "byCategory": [{ "category": "餐饮", "amount": 980.2 }], "days": 30 }
```
响应 `{ "text": "这个月…", "model": "deepseek-chat" }`

---

## 8. 实时推送（WebSocket）

连接：`wss://你的域名/ws?token=<jwt>`（也支持 `Authorization` 头）

服务端 → 客户端事件：
```json
{ "type": "hello",    "user": {...}, "coupleId": "c_1", "serverTime": 1735000000000 }
{ "type": "message",  "message": {...} }
{ "type": "read",     "userId": "u_2", "upTo": 1735000000000 }
{ "type": "typing",   "userId": "u_2", "at": 1735000000000 }
{ "type": "presence", "userId": "u_2", "online": true, "lastSeen": 1735000000000 }
{ "type": "sync",     "kinds": ["wish"], "fromUserId": "u_2" }
{ "type": "pong",     "serverTime": 1735000000000 }
{ "type": "error",    "message": "..." }
```

客户端 → 服务端事件：
```json
{ "type": "typing" }
{ "type": "ping" }
```

- 客户端每 25 秒发一次 `ping` 保活
- 断线后按 1s/2s/4s/8s/15s 退避重连
- 对方离线时不影响 REST 接口写入，重连后通过 `/api/chat/messages` 补齐

---

## 9. 后台管理（网站后台）

- 页面：`GET /admin`（静态 HTML + 原生 JS，无需构建）
- 登录：`POST /admin/api/login`，请求 `{ "password": "<ADMIN_PASSWORD>" }`，响应 `{ "token": "<admin-jwt>" }`
- 之后所有请求带 `Authorization: Bearer <admin-jwt>`

| 方法 | 路径 | 说明 |
| --- | --- | --- |
| GET | /admin/api/settings | 读取全部设置 |
| PUT | /admin/api/settings | 修改设置 |
| POST | /admin/api/ai/test | 用当前 Key 试跑一条文本 |
| GET | /admin/api/stats | 用户数/情侣数/消息数/今日 AI 调用/磁盘占用 |
| GET | /admin/api/users?query=&page= | 用户列表 |
| POST | /admin/api/users/:id/reset-password | `{ "newPassword": "..." }` |
| POST | /admin/api/users/:id/toggle-disabled | `{ "disabled": true }` |
| DELETE | /admin/api/users/:id | 删除用户 |
| GET | /admin/api/packs | 表情包统计 |
| GET | /admin/api/logs?limit=100 | 最近操作日志 |

`GET /admin/api/settings` 响应（`deepseekApiKey` 只返回掩码）：
```json
{
  "deepseekApiKeyMasked": "sk-****abcd",
  "deepseekBaseUrl": "https://api.deepseek.com",
  "deepseekModel": "deepseek-chat",
  "aiEnabled": true,
  "aiDailyLimit": 200,
  "registerOpen": true,
  "announcement": "",
  "minAppBuild": 1,
  "latestAppBuild": 1,
  "updateURL": "",
  "maxEmojiPacks": 30,
  "maxMessagesPerDay": 3000
}
```

`PUT /admin/api/settings` 请求（只传要改的字段，`deepseekApiKey` 传明文即覆盖，传空字符串表示清空）：
```json
{ "deepseekApiKey": "sk-xxxx", "deepseekModel": "deepseek-chat", "aiEnabled": true }
```
响应 `{ "ok": true, "settings": { ...同上... } }`

---

## 10. 服务端环境变量

| 变量 | 默认 | 说明 |
| --- | --- | --- |
| `PORT` | 8787 | 监听端口 |
| `DATA_DIR` | ./data | SQLite 与表情文件目录 |
| `JWT_SECRET` | 必填 | 用户 token 签名密钥，≥32 位随机串 |
| `ADMIN_PASSWORD` | 必填 | 后台管理登录密码 |
| `ADMIN_JWT_SECRET` | 回退到 JWT_SECRET | 后台 token 签名密钥 |
| `PUBLIC_BASE_URL` | 空 | 用于生成表情绝对地址，可留空 |
| `TRUST_PROXY` | 1 | 是否信任反向代理的 X-Forwarded-For |
| `LOG_LEVEL` | info | 日志级别 |

数据库表：`users`、`couples`、`messages`、`emoji_packs`、`emoji_items`、`sync_objects`、`settings`、`ai_usage`、`admin_logs`。

---

## AI 聊天扩展（AI 伙伴 / 多供应商大模型）

见 **[API-AI聊天.md](API-AI聊天.md)**：自定义聊天对象（AI 伙伴）、与 AI 伙伴聊天的读写接口、服务端多供应商配置（DeepSeek / OpenAI 兼容 / Anthropic / Gemini）。

