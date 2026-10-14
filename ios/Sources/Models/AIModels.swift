import Foundation

// MARK: - AI 伙伴（自定义聊天对象）
//
// 字段与服务端 docs/API-AI聊天.md §AI-1 一致。
// 服务端所有接口都返回 `[String: Any]`，这里沿用项目统一的容错读取（JSONRead）解析，
// 缺字段自动回落默认值，避免服务端小改动导致整条解析失败。

struct AIPartner: Codable, Identifiable, Equatable {
    var id: String = ""
    var name: String = ""
    var avatarEmoji: String = "🤖"
    /// 性格设定（system prompt）
    var persona: String = ""
    /// 空字符串表示用服务端默认供应商
    var providerId: String = ""
    var providerName: String = ""
    /// 空字符串表示用该供应商的默认模型
    var model: String = ""
    var temperature: Double = 0.8
    var enabled: Bool = true
    var createdAt: Double = 0
    var updatedAt: Double = 0

    init() {}

    init?(json: [String: Any]) {
        guard let id = JSONRead.optionalString(json, "id"), !id.isEmpty else { return nil }
        self.id = id
        self.name = JSONRead.string(json, "name", "未命名")
        let emoji = JSONRead.string(json, "avatarEmoji")
        self.avatarEmoji = emoji.isEmpty ? "🤖" : emoji
        self.persona = JSONRead.string(json, "persona")
        self.providerId = JSONRead.string(json, "providerId")
        self.providerName = JSONRead.string(json, "providerName")
        self.model = JSONRead.string(json, "model")
        self.temperature = JSONRead.double(json, "temperature", 0.8)
        self.enabled = JSONRead.bool(json, "enabled", true)
        self.createdAt = JSONRead.double(json, "createdAt")
        self.updatedAt = JSONRead.double(json, "updatedAt")
    }

    var displayName: String { name.isEmpty ? "AI 伙伴" : name }
    var emoji: String { avatarEmoji.isEmpty ? "🤖" : avatarEmoji }
    /// 「DeepSeek · deepseek-chat」 / 「服务端默认模型」
    var modelSummary: String {
        let provider = providerName.isEmpty ? (providerId.isEmpty ? "服务端默认供应商" : providerId) : providerName
        let model = model.isEmpty ? "默认模型" : model
        return "\(provider) · \(model)"
    }
}

// MARK: - AI 聊天消息

struct AIMessage: Codable, Identifiable, Equatable {
    /// 服务端约定的 AI 回复 senderId
    static let aiSenderId = "ai"

    var id: String = ""
    var senderId: String = AIMessage.aiSenderId
    var isMine: Bool = true
    var body: String = ""
    var createdAt: Double = 0
    /// 与用户消息一起提交的幂等 id（AI 回复为空字符串）
    var clientId: String = ""
    /// 本地「发送中」标记（未落库前的乐观显示）
    var pending: Bool = false
    /// 本地「发送失败」标记
    var failed: Bool = false

    init() {}

    init?(json: [String: Any]) {
        guard let id = JSONRead.optionalString(json, "id"), !id.isEmpty else { return nil }
        self.id = id
        self.senderId = JSONRead.string(json, "senderId")
        self.isMine = JSONRead.bool(json, "isMine")
        self.body = JSONRead.string(json, "body")
        self.createdAt = JSONRead.double(json, "createdAt")
        self.clientId = JSONRead.string(json, "clientId")
        self.pending = false
        self.failed = false
    }

    /// 本地乐观显示的用户消息
    init(clientId: String, body: String, createdAt: Double) {
        self.id = "ai_local_" + clientId
        self.senderId = "me"
        self.isMine = true
        self.body = body
        self.createdAt = createdAt
        self.clientId = clientId
        self.pending = true
        self.failed = false
    }

    /// 服务端字段缺失时兜底（例如 AI 回复没带 createdAt）
    mutating func fillMissingTime(_ fallback: Double) {
        if createdAt <= 0 { createdAt = fallback }
    }

    /// 转成聊天页的通用气泡模型（AI 会话复用聊天页的滚动与布局结构）
    func toChatMessage() -> ChatMessage {
        var message = ChatMessage()
        // 加前缀，避免与情侣聊天的消息 id 撞车
        message.id = "ai_" + id
        message.clientId = clientId
        message.senderId = senderId
        message.kind = "text"
        message.body = body
        message.createdAt = createdAt
        message.pending = pending
        message.failed = failed
        return message
    }
}

// MARK: - 发送结果

struct AIUsage: Codable, Equatable {
    var provider: String = ""
    var model: String = ""
    var promptTokens: Int = 0
    var completionTokens: Int = 0

    init() {}

    init?(json: [String: Any]) {
        self.provider = JSONRead.string(json, "provider")
        self.model = JSONRead.string(json, "model")
        self.promptTokens = JSONRead.int(json, "promptTokens")
        self.completionTokens = JSONRead.int(json, "completionTokens")
    }

    var isEmpty: Bool { provider.isEmpty && model.isEmpty && promptTokens == 0 && completionTokens == 0 }

    var summary: String {
        var parts: [String] = []
        if !provider.isEmpty { parts.append(provider) }
        if !model.isEmpty { parts.append(model) }
        if promptTokens > 0 || completionTokens > 0 {
            parts.append("\(promptTokens)+\(completionTokens) tokens")
        }
        return parts.joined(separator: " · ")
    }
}

struct AIChatSendResult: Equatable {
    var message: AIMessage?
    var reply: AIMessage?
    var usage: AIUsage = AIUsage()
}

// MARK: - 供应商（只读，来自 GET /api/ai/providers）

struct AIProviderOption: Codable, Identifiable, Equatable {
    var id: String = ""
    var name: String = ""
    var models: [String] = []
    var defaultModel: String = ""

    init() {}

    init?(json: [String: Any]) {
        guard let id = JSONRead.optionalString(json, "id"), !id.isEmpty else { return nil }
        self.id = id
        self.name = JSONRead.string(json, "name", id)
        self.models = JSONRead.stringArray(json, "models")
        self.defaultModel = JSONRead.string(json, "defaultModel")
        // 默认模型不在列表里也补进去，保证下拉能选中
        if !self.defaultModel.isEmpty && !self.models.contains(self.defaultModel) {
            self.models.insert(self.defaultModel, at: 0)
        }
    }

    var displayName: String { name.isEmpty ? id : name }

    /// 该供应商展示用的模型列表（过滤空白）
    var modelOptions: [String] {
        models.filter { !$0.trimmingCharacters(in: .whitespaces).isEmpty }
    }
}

// MARK: - 错误映射
//
// docs/API-AI聊天.md §AI-4 的错误码 → 中文可读提示。
// `retryable` 决定 UI 是否给出「重试」按钮。

struct AIErrorInfo: Equatable {
    var code: String
    var message: String
    var retryable: Bool

    init(code: String, message: String, retryable: Bool) {
        self.code = code
        self.message = message
        self.retryable = retryable
    }
}

enum AIErrorMapper {

    static let notConfiguredText = "服务端还没有配置 AI 供应商，请在服务端后台添加"

    static func info(from error: Error) -> AIErrorInfo {
        guard let api = error as? APIError else {
            return AIErrorInfo(code: "unknown", message: "操作失败，请重试", retryable: true)
        }

        // 契约里的业务错误码优先
        switch api.code {
        case "ai_not_configured":
            return AIErrorInfo(code: api.code, message: notConfiguredText, retryable: true)
        case "forbidden":
            // 老服务端把「未配置 AI」返回成 forbidden
            return AIErrorInfo(code: api.code, message: api.message.isEmpty ? "AI 功能暂不可用" : api.message, retryable: false)
        case "upstream_timeout":
            return AIErrorInfo(code: api.code, message: "AI 供应商响应超时，请重试", retryable: true)
        case "upstream_error":
            // 服务端的 message 已经是中文摘要（≤200 字），为空时才用兜底
            let text = api.message.isEmpty ? "AI 供应商返回错误，请检查服务端配置" : api.message
            return AIErrorInfo(code: api.code, message: text, retryable: true)
        case "rate_limited":
            return AIErrorInfo(code: api.code, message: api.message.isEmpty ? "操作太频繁，请稍后再试" : api.message, retryable: true)
        case "invalid_input":
            return AIErrorInfo(code: api.code, message: api.message.isEmpty ? "输入不合法，请检查后重试" : api.message, retryable: false)
        default:
            break
        }

        // 再按 HTTP 状态码兜底
        switch api.status {
        case 401:
            return AIErrorInfo(code: api.code, message: "登录已过期，请重新登录", retryable: false)
        case 403:
            let text = api.message.isEmpty ? "AI 功能暂不可用" : api.message
            return AIErrorInfo(code: api.code, message: text, retryable: false)
        case 404:
            // 伙伴被删除，或服务端还没有这些接口
            let text = api.message.isEmpty ? "找不到该 AI 伙伴，可能已被删除" : api.message
            return AIErrorInfo(code: api.code, message: text, retryable: false)
        case 409:
            return AIErrorInfo(code: api.code, message: "最多只能创建 10 个 AI 伙伴", retryable: false)
        case 429:
            return AIErrorInfo(code: api.code, message: api.message.isEmpty ? "操作太频繁，请稍后再试" : api.message, retryable: true)
        case 500...599:
            let text = api.message.isEmpty ? "服务器内部错误，请稍后重试" : api.message
            return AIErrorInfo(code: api.code, message: text, retryable: true)
        case 0:
            return AIErrorInfo(code: api.code, message: api.message.isEmpty ? "网络异常，请检查服务器地址与网络" : api.message, retryable: true)
        default:
            let text = api.message.isEmpty ? "请求失败，请重试" : api.message
            return AIErrorInfo(code: api.code, message: text, retryable: true)
        }
    }
}
