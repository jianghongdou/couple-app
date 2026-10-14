# API 扩展 · AI 聊天（自定义聊天对象 + 多供应商大模型）

> 本文件是 [API.md](API.md) 的**扩展契约**，字段名/错误码/限制值以本文为准。
> 面向 iOS App 的接口**只有下面 §AI-1 ~ §AI-4**；§AI-5 是**服务端后台**自己的接口（App 不调用）。
> 所有接口沿用 API.md 的通用约定：请求头 `Authorization: Bearer <token>`、响应统一带 `serverTime`（毫秒）、
> 错误体 `{ "error": "<code>", "message": "<中文>", "serverTime": <ms> }`。

---

## 概念

- **AI 伙伴（AI partner）**：一个「自定义聊天对象」。有名字、头像 emoji、性格设定（system prompt）、
  以及使用哪个**供应商 + 模型**。存在服务端，**绑定的两个人都能看到和使用**。
- **AI 消息**：与 AI 伙伴的聊天记录，存在服务端（与情侣聊天分开存储，互不影响）。
- **供应商（provider）**：服务端后台配置的大模型接入（DeepSeek / OpenAI 兼容 / Anthropic / Gemini 等），
  **API Key 只存在服务端**，App 永远拿不到 Key，只拿到「有哪些供应商、可选模型」。

未绑定情侣的用户**也能使用 AI 聊天**（这正是没人陪着测聊天时的用法），不要求 `coupleId`。

---

## AI-1 列出 AI 伙伴

```
GET /api/ai/partners
```
**需登录**（不要求绑定情侣）。返回：

```json
{
  "ok": true,
  "partners": [
    {
      "id": "aip_xxx",
      "name": "小助手",
      "avatarEmoji": "🤖",
      "persona": "你是温柔体贴的男友，说话简短、会撒娇。",
      "providerId": "prov_deepseek",
      "providerName": "DeepSeek",
      "model": "deepseek-chat",
      "temperature": 0.8,
      "enabled": true,
      "createdAt": 1791940000000,
      "updatedAt": 1791940000000
    }
  ],
  "serverTime": 1791940000000
}
```
- 无伙伴时返回空数组（不报错）。
- `providerId`/`model` 可为空字符串：表示用服务端的**默认供应商 + 默认模型**。

---

## AI-1b 列出可用供应商（App 只读）

```
GET /api/ai/providers
```
**需登录**。返回服务端**已启用**的供应商，供 App 做「供应商 / 模型」下拉：

```json
{
  "ok": true,
  "aiChatEnabled": true,
  "providers": [
    {
      "id": "prov_deepseek",
      "name": "DeepSeek",
      "models": ["deepseek-chat", "deepseek-reasoner"],
      "defaultModel": "deepseek-chat",
      "isDefault": true
    }
  ],
  "serverTime": 1791940000000
}
```

- 只返回 `enabled=true` 的供应商；一个都没有时 `providers` 为空数组（`aiChatEnabled` 仍反映 AI 聊天总开关）。
- `models` 为空数组表示「不限制模型名，允许手填」。
- **绝不返回 `apiKey`、`baseUrl`**（Base URL 也视为敏感信息，不下发）。

---

## AI-2 新增 / 修改 / 删除 AI 伙伴

```
POST   /api/ai/partners
PATCH  /api/ai/partners/:id
DELETE /api/ai/partners/:id
```
**需登录**。请求体（POST 必填 `name`，PATCH 传要改的字段）：

| 字段 | 类型 | 约束 |
| --- | --- | --- |
| `name` | string | 1–20 字（去空白后非空） |
| `avatarEmoji` | string | 0–4 字，默认 `🤖` |
| `persona` | string | 0–2000 字（系统提示词） |
| `providerId` | string | 空或必须是服务端已启用的供应商 id |
| `model` | string | 空或必须是该供应商支持的模型名（长度 ≤64） |
| `temperature` | number | 0–2，默认 0.8 |
| `enabled` | bool | 默认 true |

- 每个用户最多 **10 个** AI 伙伴 → 超出返回 `409 conflict`
- `name` 为空或超长 → `400 invalid_input`
- `providerId` 不在可用列表 → `400 invalid_input`
- 不是自己的伙伴（或不存在）→ `404 not_found`（**不要**用 403，避免暴露存在性）
- 成功返回：`{ "ok": true, "partner": { ...同 AI-1 结构... }, "serverTime": <ms> }`；删除返回 `{ "ok": true, "serverTime": <ms> }`

---

## AI-3 与 AI 伙伴聊天（读）

```
GET /api/ai/chat/messages?partnerId=<id>&before=<ms|0>&limit=<1..100>
```
**需登录**。返回：

```json
{
  "ok": true,
  "partner": { "id": "aip_xxx", "name": "小助手", "avatarEmoji": "🤖" },
  "messages": [
    { "id": "m_xxx", "senderId": "u_me", "isMine": true,  "body": "在吗", "createdAt": 1791940000000, "clientId": "c_xxx" },
    { "id": "m_yyy", "senderId": "ai",   "isMine": false, "body": "在的呀～", "createdAt": 1791940001000, "clientId": "" }
  ],
  "hasMore": false,
  "serverTime": 1791940000000
}
```
- `messages` 按 `createdAt` **升序**；`before>0` 时返回比它更早的 `limit` 条（用于上翻历史）。
- `limit` 默认 50、最大 100，超出按 100 处理。
- `partnerId` 不是自己的 → `404 not_found`。

---

## AI-4 与 AI 伙伴聊天（发消息，服务端代发大模型）

```
POST /api/ai/chat/messages
Content-Type: application/json

{ "partnerId": "aip_xxx", "body": "在吗", "clientId": "c_abc123" }
```

**需登录**。行为：
1. 先把用户消息落库（**`clientId` 幂等**：同 `clientId` 重复提交只落一条，并直接返回上次结果）
2. 服务端调用大模型（用该伙伴的 `providerId` + `model`，缺省用服务端默认供应商/模型），把伙伴的 `persona` 作为 system prompt，
   并带上**最近 20 条**该会话消息作为上下文
3. 把 AI 回复落库，**一次性返回**（本期不做流式）

响应：

```json
{
  "ok": true,
  "message": { "id": "m_xxx", "senderId": "u_me", "isMine": true,  "body": "在吗", "createdAt": 1791940000000, "clientId": "c_abc123" },
  "reply":   { "id": "m_yyy", "senderId": "ai",   "isMine": false, "body": "在的呀～", "createdAt": 1791940001000, "clientId": "" },
  "usage": { "provider": "DeepSeek", "model": "deepseek-chat", "promptTokens": 42, "completionTokens": 18 },
  "serverTime": 1791940000000
}
```

约束与错误：

| 情况 | 返回 |
| --- | --- |
| `body` 为空或 > 2000 字 | `400 invalid_input` |
| `partnerId` 不存在/非自己的 | `404 not_found` |
| 该伙伴被 `enabled=false` | `403 forbidden`（message：该伙伴已停用） |
| 服务端**没有配置任何可用供应商** | `403 ai_not_configured`（message：AI 未配置，请到服务端后台添加供应商） |
| 供应商返回鉴权失败（Key 错） | `502 upstream_error`（message：AI 供应商鉴权失败，请检查 Key） |
| 供应商超时/网络错误 | `504 upstream_timeout`（message：AI 供应商响应超时，请重试） |
| 供应商返回其它错误 | `502 upstream_error`（message 带上游错误摘要，≤200 字） |
| 调用过于频繁 | `429 rate_limited`（同一用户 1 分钟内最多 20 次） |

- **不允许**把供应商的 Key、原始请求体、上游完整响应回传给 App（只回上面的字段）。
- AI 回复的 `senderId` 固定为字符串 `"ai"`，`isMine` 为 `false`。
- 若上游返回空内容 → 落库一条 `"（AI 没有返回内容）"` 的回复，接口仍返回 200。

---

## AI-5 服务端后台：供应商管理（App 不调用）

> WordPress 插件走 WP 后台菜单；PHP 版走 `/admin/api/*`。两者行为一致。

```
GET    /admin/api/ai/providers
POST   /admin/api/ai/providers
PATCH  /admin/api/ai/providers/:id
DELETE /admin/api/ai/providers/:id
POST   /admin/api/ai/providers/:id/test     → 用该供应商发一条 "你好" 测试，返回 { ok, reply, model, latencyMs }
POST   /admin/api/ai/providers/test-raw     → 用表单里的临时参数测试（不落库）
```

供应商结构：

```json
{
  "id": "prov_xxx",
  "name": "DeepSeek",
  "type": "openai",              // openai | anthropic | gemini | deepseek
  "baseUrl": "https://api.deepseek.com/v1",
  "models": ["deepseek-chat", "deepseek-reasoner"],
  "defaultModel": "deepseek-chat",
  "enabled": true,
  "isDefault": true,
  "apiKeyMasked": "sk-************abcd",
  "apiKeyConfigured": true,
  "createdAt": 1791940000000
}
```

要求：
1. **`type=openai` 即「OpenAI 兼容」**：任何兼容 `/v1/chat/completions` 的服务都能接（OpenAI、通义千问、Kimi、
   智谱、硅基流动、one-api、Ollama、LM Studio…），后台只需填 `baseUrl` + `apiKey` + 模型名。
2. `type=anthropic` 走 `/v1/messages`（`x-api-key` + `anthropic-version` 头）；
   `type=gemini` 走 `generateContent`；`type=deepseek` 视为 `openai` 类型的预设。
3. **API Key 一律加密存储**（AES-256-GCM，密钥由服务端密钥派生）、接口只回掩码，**任何接口都不返回明文**。
4. 默认供应商：`isDefault=true` 的那个；没有显式指定时用它。删除默认供应商时自动把另一个设为默认（没有则置空）。
5. 服务端设置里要有：**AI 聊天总开关**、**每用户每日 AI 消息上限**（默认 200）、以及给 AI 伙伴用的**默认供应商/模型**。
6. `/api/meta` 的 `features` 里新增两个字段：`aiChat: true/false`、`aiProviders: <启用供应商数量>`。

---

## AI-6 数据表（服务端）

沿用现有风格（表名带前缀：PHP 版 `wp_couple_*` / 独立版 `couple_*`）：

```
ai_partners(id, owner_id, name, avatar_emoji, persona, provider_id, model, temperature, enabled, created_at, updated_at)
ai_messages(id, partner_id, owner_id, sender, body, client_id, created_at, UNIQUE(owner_id, client_id))
ai_providers(id, name, type, base_url, api_key_enc, models_json, default_model, enabled, is_default, created_at, updated_at)
```

- `ai_messages.sender`：`"user"` 或 `"ai"`。
- `ai_messages` 与情侣聊天表**分开**，互不影响（情侣聊天接口与行为完全不变）。
- 需要索引：`ai_partners(owner_id)`、`ai_messages(partner_id, created_at)`、`ai_messages(owner_id, client_id)`。
