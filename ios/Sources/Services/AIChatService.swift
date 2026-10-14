import Foundation
import Combine

/// AI 聊天服务：AI 伙伴列表 + 与某个伙伴的会话。
///
/// 设计要点（与既有情侣聊天**完全隔离**）：
/// - 情侣聊天仍然由 `ChatService` + `LocalStore.state.messages` 负责，本服务一个字都不写；
/// - AI 消息只在本服务内存里维护（服务端已落库），**不写入 LocalStore**、
///   不参与 `SyncEngine` 同步、不产生未读角标、不触发情侣聊天的横幅；
/// - 风格与 `ChatService` 保持一致：`ObservableObject` + `@Published`，在 App 入口
///   用 `@StateObject` 注入环境（`CoupleAppMain`），视图里用 `@EnvironmentObject` 读取。
@MainActor
final class AIChatService: ObservableObject {
    static let shared = AIChatService()

    // MARK: - 发布状态

    /// 我的 AI 伙伴列表（服务端 §AI-1）
    @Published private(set) var partners: [AIPartner] = []
    /// 可用供应商（服务端只读；接口缺失时为空数组）
    @Published private(set) var providers: [AIProviderOption] = []
    /// 对话消息（升序）
    @Published private(set) var messages: [AIMessage] = []
    /// 当前选中的 AI 伙伴
    @Published private(set) var activePartnerId: String = ""
    /// 当前会话是否正在加载（首屏 / 上翻历史 / 下拉刷新）
    @Published private(set) var isLoading = false
    /// 发送中
    @Published private(set) var isSending = false
    /// 等待 AI 回复
    @Published private(set) var isTyping = false
    /// 作者/页面提示（中文）
    @Published var errorText = ""
    /// 当前会话是否还有更早的历史
    @Published private(set) var hasMoreHistory = false
    /// 服务端还没有这些 AI 聊天接口（老服务端）→ 页面显示引导
    @Published private(set) var featureUnsupported = false
    /// 服务端没有配置任何供应商 → 显示「请在服务端后台添加」
    @Published private(set) var serverNeedsProvider = false
    /// 最近一次发送的用量（用于小字提示，可忽略）
    @Published private(set) var lastUsageText = ""

    // MARK: - 私有状态

    /// 失败的用户消息 clientId（用于「点消息重发」）
    private var failedClientIds: Set<String> = []
    private var didFirstLoad = false
    /// 每次切换会话 / 发起新加载都会变，用来丢弃过期请求的结果
    private var loadToken = UUID()

    private init() {}

    // MARK: - 可用性

    /// 是否具备联网登录条件（单机模式 / 未登录 = 不可用）
    var canUseNetwork: Bool {
        !DeviceConfig.shared.token.isEmpty && !DeviceConfig.shared.userId.isEmpty
    }

    /// 页面引导文案（不需要引导时为空）
    var guidanceText: String {
        if !canUseNetwork { return "AI 聊天需要先连接服务器并登录" }
        if featureUnsupported { return "当前服务器版本还不支持 AI 聊天，请升级服务端" }
        if serverNeedsProvider { return AIErrorMapper.notConfiguredText }
        return ""
    }

    /// 服务端层面的 AI 聊天功能是否可用（负责决定是否展示会话切换条里的 AI 头像）
    var isFeatureAvailable: Bool {
        canUseNetwork && !featureUnsupported
    }

    /// 是否还能新增 AI 伙伴（契约上限 10 个）
    var canAddPartner: Bool { partners.count < AIChatService.maxPartners }

    static let maxPartners = 10

    /// 按 id 取伙伴
    func partner(id: String) -> AIPartner? {
        partners.first { $0.id == id }
    }

    /// 当前会话的伙伴
    var activePartner: AIPartner? {
        partner(id: activePartnerId)
    }

    /// 当前会话是否处于「已选中伙伴」状态
    var hasActivePartner: Bool { activePartner != nil }

    /// 该伙伴是否已停用（停用后服务端会 403）
    var activePartnerDisabled: Bool {
        guard let partner = activePartner else { return false }
        return !partner.enabled
    }

    /// 是否显示「服务端还没配置供应商」的提示卡片
    var needsProviderHint: Bool {
        guard canUseNetwork, !featureUnsupported else { return false }
        if serverNeedsProvider { return true }
        return providers.isEmpty
    }

    /// 退出登录 / 切换账号时清空（避免把上一个人的 AI 伙伴露出来）
    func reset() {
        partners = []
        providers = []
        messages = []
        activePartnerId = ""
        failedClientIds = []
        hasMoreHistory = false
        errorText = ""
        isTyping = false
        isSending = false
        isLoading = false
        lastUsageText = ""
        featureUnsupported = false
        serverNeedsProvider = false
        didFirstLoad = false
        self.loadToken = UUID()
    }

    // MARK: - 拉取伙伴

    /// 打开 AI 伙伴管理页 / 首次进入聊天页时调用
    func loadPartners(force: Bool = false) async {
        if didFirstLoad && !force { return }
        guard canUseNetwork else {
            featureUnsupported = false
            partners = []
            return
        }
        do {
            let list = try await APIClient.shared.fetchAIPartners()
            didFirstLoad = true
            partners = list.sorted { $0.createdAt < $1.createdAt }
            featureUnsupported = false
            // 明确拿到空列表说明接口是通的，是否需要配置供应商由 providers 判断
            errorText = ""
        } catch let error as APIError {
            let info = AIErrorMapper.info(from: error)
            if error.status == 404 {
                // 老服务端：这些接口还不存在
                featureUnsupported = true
                partners = []
            } else {
                errorText = info.message
            }
        } catch {
            errorText = "加载 AI 伙伴失败，请检查网络"
        }
    }

    /// 拉取可用供应商（服务端没有该接口时容错为空数组，不报错）
    func loadProviders() async {
        guard canUseNetwork else {
            providers = []
            serverNeedsProvider = false
            return
        }
        do {
            let list = try await APIClient.shared.fetchAIProviders()
            providers = list
            if !list.isEmpty { serverNeedsProvider = false }
        } catch let error as APIError {
            providers = []
            if error.status == 404 {
                // 服务端没有 /api/ai/providers：不崩、不弹错，按「没有供应商」处理
                serverNeedsProvider = true
            } else {
                let info = AIErrorMapper.info(from: error)
                serverNeedsProvider = true
                if !info.message.isEmpty { errorText = info.message }
            }
        } catch {
            providers = []
            serverNeedsProvider = true
        }
    }

    // MARK: - 会话切换

    /// 选中某个 AI 伙伴（清掉情侣聊天的错误提示，避免串台）
    func selectPartner(_ id: String) {
        guard activePartnerId != id else { return }
        activePartnerId = id
        messages = []
        failedClientIds = []
        hasMoreHistory = false
        errorText = ""
        isTyping = false
        lastUsageText = ""
        loadToken = UUID()
    }

    /// 切换回情侣会话（AI 侧只做清理，不动情侣聊天数据）
    func clearActivePartner() {
        guard !activePartnerId.isEmpty else { return }
        activePartnerId = ""
        messages = []
        failedClientIds = []
        hasMoreHistory = false
        errorText = ""
        isTyping = false
        lastUsageText = ""
        loadToken = UUID()
    }

    /// 选中伙伴并立刻加载消息（给「回到聊天页自动恢复」用）
    func selectPartnerAndLoad(_ id: String) async {
        guard activePartnerId != id else { return }
        selectPartner(id)
        await loadLatest()
    }

    // MARK: - 读取消息

    /// 载入当前会话最新一屏消息
    func loadLatest() async {
        guard let partner = activePartner else { return }
        let token = UUID()
        loadToken = token
        isLoading = true

        do {
            let result = try await APIClient.shared.fetchAIChatMessages(partnerId: partner.id, before: 0, limit: 50)
            guard isCurrent(token, partnerId: partner.id) else { return }
            let now = DateUtils.ms()
            var list = result.messages
            for index in list.indices { list[index].fillMissingTime(now) }
            messages = mergeIncoming(list, into: messages)
            hasMoreHistory = result.hasMore
            failedClientIds = failedClientIds.subtracting(messages.map { $0.clientId })
            errorText = ""
            serverNeedsProvider = false
            featureUnsupported = false
            isLoading = false
        } catch let error as APIError {
            guard isCurrent(token, partnerId: partner.id) else { return }
            isLoading = false
            // 这里即使 404 也不隐藏切换条（可能只是这个伙伴被删了，其它伙伴还能用）
            errorText = AIErrorMapper.info(from: error).message
        } catch {
            guard isCurrent(token, partnerId: partner.id) else { return }
            isLoading = false
            errorText = "加载聊天记录失败"
        }
    }

    /// 上翻更早的历史
    func loadOlder() async {
        guard let partner = activePartner else { return }
        let oldest = messages.first?.createdAt ?? 0
        guard oldest > 0, !isLoading else { return }
        let token = UUID()
        loadToken = token
        isLoading = true

        do {
            let result = try await APIClient.shared.fetchAIChatMessages(partnerId: partner.id, before: oldest, limit: 50)
            guard isCurrent(token, partnerId: partner.id) else { return }
            let now = DateUtils.ms()
            var list = result.messages
            for index in list.indices { list[index].fillMissingTime(now) }
            messages = mergeIncoming(list, into: messages)
            hasMoreHistory = result.hasMore
            isLoading = false
            errorText = ""
        } catch let error as APIError {
            guard isCurrent(token, partnerId: partner.id) else { return }
            isLoading = false
            errorText = AIErrorMapper.info(from: error).message
        } catch {
            guard isCurrent(token, partnerId: partner.id) else { return }
            isLoading = false
            errorText = "加载更早的消息失败"
        }
    }

    /// 下拉刷新
    func refresh() async {
        await loadPartners(force: true)
        await loadProviders()
        guard hasActivePartner else { return }
        await loadLatest()
    }

    /// 请求回来后仍然属于当前会话、且没有更新的加载
    private func isCurrent(_ token: UUID, partnerId: String) -> Bool {
        token == loadToken && activePartnerId == partnerId
    }

    // MARK: - 发送

    /// 发送文本。返回是否成功提交（失败时消息仍留在列表里，可点重发）
    @discardableResult
    func send(text: String) async -> Bool {
        let trimmed = text.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !trimmed.isEmpty else { return false }
        guard let partner = activePartner else {
            errorText = "请先选择一个 AI 伙伴"
            return false
        }
        guard canUseNetwork else {
            errorText = "AI 聊天需要先连接服务器并登录"
            return false
        }
        guard !isSending else { return false }
        guard trimmed.count <= 2000 else {
            errorText = "消息太长了，最多 2000 字"
            return false
        }

        let body = String(trimmed.prefix(2000))
        let clientId = "c_\(Int(DateUtils.ms()))_\(Int.random(in: 100...999))"
        let now = DateUtils.ms()
        var local = AIMessage(clientId: clientId, body: body, createdAt: now)
        local.fillMissingTime(now)

        // 乐观显示：用户消息立刻出现在列表末尾
        messages = mergeIncoming([local], into: messages)
        failedClientIds.remove(clientId)
        isSending = true
        isTyping = true
        errorText = ""

        do {
            let result = try await APIClient.shared.sendAIChatMessage(partnerId: partner.id, body: body, clientId: clientId)
            if activePartnerId == partner.id {
                var incoming: [AIMessage] = []
                let stamp = DateUtils.ms()
                if var message = result.message {
                    // 合并本地「发送中」那条，避免出现两条
                    if message.clientId.isEmpty { message.clientId = clientId }
                    if message.clientId == clientId { message.pending = false }
                    message.fillMissingTime(now)
                    incoming.append(message)
                }
                if var reply = result.reply {
                    reply.fillMissingTime(stamp)
                    incoming.append(reply)
                }
                if !incoming.isEmpty {
                    messages = mergeIncoming(incoming, into: messages)
                }
                // 服务端确认后清掉本地的发送中/失败状态
                if let index = messages.firstIndex(where: { $0.clientId == clientId && $0.pending }) {
                    messages[index].pending = false
                    messages[index].failed = false
                }
                let usage = result.usage
                lastUsageText = usage.isEmpty ? "" : usage.summary
            }
            isSending = false
            isTyping = false
            return true
        } catch {
            if activePartnerId == partner.id {
                failedClientIds.insert(clientId)
                if let index = messages.firstIndex(where: { $0.clientId == clientId }) {
                    messages[index].pending = false
                    messages[index].failed = true
                }
                let info = AIErrorMapper.info(from: error)
                errorText = info.message
                // 服务端没配供应商：让页面切到「请在服务端后台添加」的引导
                if info.code == "ai_not_configured" { serverNeedsProvider = true }
            }
            isSending = false
            isTyping = false
            return false
        }
    }

    /// 重发失败的消息（沿用同一个 clientId，服务端幂等）
    func retry(clientId: String) {
        guard let message = messages.first(where: { $0.clientId == clientId && $0.failed }),
              let partner = activePartner else { return }
        let body = message.body
        let partnerId = partner.id
        // 先把失败那条转成「发送中」，再走同一条路径
        if let index = messages.firstIndex(where: { $0.clientId == clientId }) {
            messages[index].failed = false
            messages[index].pending = true
        }
        failedClientIds.remove(clientId)
        errorText = ""

        Task { [weak self] in
            guard let self else { return }
            let ok = await self.resend(clientId: clientId, partnerId: partnerId, body: body)
            guard !ok else { return }
            guard self.activePartnerId == partnerId else { return }
            self.failedClientIds.insert(clientId)
            if let index = self.messages.firstIndex(where: { $0.clientId == clientId }) {
                self.messages[index].pending = false
                self.messages[index].failed = true
            }
        }
    }

    private func resend(clientId: String, partnerId: String, body: String) async -> Bool {
        guard !isSending else { return false }
        isSending = true
        isTyping = true
        do {
            let result = try await APIClient.shared.sendAIChatMessage(partnerId: partnerId, body: body, clientId: clientId)
            if activePartnerId == partnerId {
                var incoming: [AIMessage] = []
                if var message = result.message {
                    if message.clientId.isEmpty { message.clientId = clientId }
                    message.pending = false
                    message.failed = false
                    message.fillMissingTime(DateUtils.ms())
                    incoming.append(message)
                }
                if var reply = result.reply {
                    reply.fillMissingTime(DateUtils.ms())
                    incoming.append(reply)
                }
                if !incoming.isEmpty { messages = mergeIncoming(incoming, into: messages) }
                if let index = messages.firstIndex(where: { $0.clientId == clientId }) {
                    messages[index].pending = false
                    messages[index].failed = false
                }
                lastUsageText = result.usage.isEmpty ? "" : result.usage.summary
            }
            isSending = false
            isTyping = false
            return true
        } catch {
            if activePartnerId == partnerId { errorText = AIErrorMapper.info(from: error).message }
            isSending = false
            isTyping = false
            return false
        }
    }

    // MARK: - 伙伴增删改

    /// 新增（成功返回 true 并刷新列表）
    func createPartner(
        name: String,
        avatarEmoji: String,
        persona: String,
        providerId: String,
        model: String,
        temperature: Double,
        enabled: Bool
    ) async -> Bool {
        guard canUseNetwork else {
            errorText = "AI 聊天需要先连接服务器并登录"
            return false
        }
        do {
            _ = try await APIClient.shared.createAIPartner(
                name: name,
                avatarEmoji: avatarEmoji,
                persona: persona,
                providerId: providerId,
                model: model,
                temperature: temperature,
                enabled: enabled
            )
            errorText = ""
            await loadPartners(force: true)
            return true
        } catch let error as APIError {
            errorText = AIErrorMapper.info(from: error).message
            return false
        } catch {
            errorText = "保存失败，请重试"
            return false
        }
    }

    /// 修改
    func updatePartner(
        id: String,
        name: String,
        avatarEmoji: String,
        persona: String,
        providerId: String,
        model: String,
        temperature: Double,
        enabled: Bool
    ) async -> Bool {
        guard canUseNetwork else {
            errorText = "AI 聊天需要先连接服务器并登录"
            return false
        }
        do {
            _ = try await APIClient.shared.updateAIPartner(
                id: id,
                name: name,
                avatarEmoji: avatarEmoji,
                persona: persona,
                providerId: providerId,
                model: model,
                temperature: temperature,
                enabled: enabled
            )
            errorText = ""
            await loadPartners(force: true)
            return true
        } catch let error as APIError {
            errorText = AIErrorMapper.info(from: error).message
            return false
        } catch {
            errorText = "保存失败，请重试"
            return false
        }
    }

    /// 删除
    func deletePartner(id: String) async -> Bool {
        do {
            try await APIClient.shared.deleteAIPartner(id: id)
            if activePartnerId == id { clearActivePartner() }
            errorText = ""
            await loadPartners(force: true)
            return true
        } catch let error as APIError {
            errorText = AIErrorMapper.info(from: error).message
            return false
        } catch {
            errorText = "删除失败，请重试"
            return false
        }
    }

    // MARK: - 合并工具

    /// 把新消息并入本地列表：同 id / 同 clientId 覆盖，其余追加，最后按时间升序。
    ///
    /// 特别处理：本地「发送中」的乐观消息 id 是 `ai_local_<clientId>`，
    /// 服务端返回的 id 不同；只要 clientId 相同就覆盖，避免出现两条。
    private func mergeIncoming(_ incoming: [AIMessage], into current: [AIMessage]) -> [AIMessage] {
        var ordered: [AIMessage] = current
        for message in incoming {
            // 1) 服务端确认过的、本地已存在的同一条
            if let index = ordered.firstIndex(where: { $0.id == message.id }) {
                var merged = message
                merged.pending = message.pending && ordered[index].pending
                ordered[index] = merged
                continue
            }
            // 2) 命中本地乐观消息
            var matched = false
            if let index = ordered.firstIndex(where: {
                $0.pending && !$0.clientId.isEmpty && $0.clientId == message.clientId
            }) {
                var merged = message
                merged.pending = false
                merged.failed = false
                if merged.createdAt <= 0 { merged.createdAt = ordered[index].createdAt }
                ordered[index] = merged
                matched = true
            }
            if matched { continue }
            // 3) 新消息
            ordered.append(message)
        }
        ordered.sort { left, right in
            if left.createdAt == right.createdAt { return left.id < right.id }
            return left.createdAt < right.createdAt
        }
        if ordered.count > 500 { ordered.removeFirst(ordered.count - 500) }
        return ordered
    }
}
