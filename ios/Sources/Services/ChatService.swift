import Foundation
import Combine

/// 聊天服务：负责历史加载、发送消息、实时事件处理
final class ChatService: ObservableObject {
    static let shared = ChatService()

    @Published var isSending = false
    @Published var isLoadingHistory = false
    @Published var errorText = ""
    @Published var partnerTyping = false
    /// 聊天页面是否正在前台显示（决定收到消息是否直接标记已读）
    @Published var chatVisible = false
    /// 新消息横幅
    @Published var bannerMessage: ChatMessage?
    @Published var hasMoreHistory = false

    private var typingResetWork: DispatchWorkItem?
    private var bannerWork: DispatchWorkItem?
    private var started = false

    private init() {
        RealtimeClient.shared.onEvent = { [weak self] event in
            self?.handle(event: event)
        }
    }

    var partner: UserProfile? {
        guard let couple = SessionManager.shared.couple else { return nil }
        return couple.partner(of: DeviceConfig.shared.userId)
    }

    // MARK: - 生命周期

    func start() {
        guard !DeviceConfig.shared.token.isEmpty else { return }
        started = true
        RealtimeClient.shared.start()
        Task { await loadHistoryIfNeeded() }
    }

    func stop() {
        started = false
        RealtimeClient.shared.stop()
    }

    // MARK: - 历史

    func loadHistoryIfNeeded() async {
        if LocalStore.shared.messages.isEmpty {
            await loadMore(before: 0)
        }
    }

    func loadMore(before: Double = 0) async {
        guard !DeviceConfig.shared.token.isEmpty else { return }
        await MainActor.run { self.isLoadingHistory = true }
        do {
            let result = try await APIClient.shared.fetchMessages(before: before, limit: 50)
            await MainActor.run {
                LocalStore.shared.upsertMessages(result.messages)
                self.hasMoreHistory = result.hasMore
                self.isLoadingHistory = false
                self.errorText = ""
            }
        } catch let error as APIError {
            await MainActor.run {
                self.isLoadingHistory = false
                self.errorText = error.message
            }
        } catch {
            await MainActor.run {
                self.isLoadingHistory = false
                self.errorText = "加载聊天记录失败"
            }
        }
    }

    /// 下拉加载更早的消息
    func loadOlder() async {
        let oldest = LocalStore.shared.messages.first?.createdAt ?? 0
        guard oldest > 0 else {
            await loadMore(before: 0)
            return
        }
        await loadMore(before: oldest)
    }

    // MARK: - 发送

    func send(text: String) {
        let trimmed = text.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !trimmed.isEmpty else { return }
        send(kind: "text", body: String(trimmed.prefix(2000)))
    }

    func send(unicodeEmoji: String) {
        guard !unicodeEmoji.isEmpty else { return }
        send(kind: "emoji", body: "unicode:" + unicodeEmoji)
    }

    func send(emojiItemId: String) {
        send(kind: "image", body: "item:" + emojiItemId)
    }

    /// 内置"抖音风格"表情
    func send(stickerId: String) {
        send(kind: "emoji", body: "sticker:" + stickerId)
    }

    private func send(kind: String, body: String) {
        let myId = DeviceConfig.shared.userId
        guard !myId.isEmpty else { return }
        guard !DeviceConfig.shared.coupleId.isEmpty else {
            errorText = "还没有绑定情侣，先去「我的」里绑定吧"
            return
        }

        let clientId = "c_\(Int(DateUtils.ms()))_\(Int.random(in: 100...999))"
        let local = ChatMessage(senderId: myId, kind: kind, body: body, clientId: clientId)
        LocalStore.shared.appendPendingMessage(local)

        Task {
            do {
                let message = try await APIClient.shared.postMessage(clientId: clientId, kind: kind, body: body)
                await MainActor.run {
                    if let message = message {
                        LocalStore.shared.confirmPendingMessage(clientId: clientId, serverMessage: message)
                        LocalStore.shared.addSpark(points: 1, reason: "聊天")
                        SyncEngine.shared.requestSync()
                        self.errorText = ""
                    } else {
                        LocalStore.shared.markPendingFailed(clientId: clientId)
                        self.errorText = "发送失败，点消息可重试"
                    }
                }
            } catch let error as APIError {
                await MainActor.run {
                    LocalStore.shared.markPendingFailed(clientId: clientId)
                    self.errorText = error.message
                }
            } catch {
                await MainActor.run {
                    LocalStore.shared.markPendingFailed(clientId: clientId)
                    self.errorText = "发送失败"
                }
            }
        }
    }

    /// 重发失败的消息
    func resend(clientId: String) {
        guard let message = LocalStore.shared.messages.first(where: { $0.clientId == clientId }) else { return }
        LocalStore.shared.removeMessage(id: message.id)
        send(kind: message.kind, body: message.body)
    }

    func sendTyping() {
        RealtimeClient.shared.sendTyping()
    }

    // MARK: - 已读

    func markRead() {
        let myId = DeviceConfig.shared.userId
        let partnerId = partner?.id ?? ""
        let latest = LocalStore.shared.messages.last?.createdAt ?? 0
        guard latest > 0 else { return }
        LocalStore.shared.markRead(upTo: latest)
        if !partnerId.isEmpty {
            LocalStore.shared.markPartnerMessagesRead(upTo: latest, partnerId: partnerId)
        }
        Task {
            try? await APIClient.shared.markMessagesRead(upTo: latest)
        }
        _ = myId
    }

    // MARK: - 事件

    private func handle(event: RealtimeEvent) {
        let myId = DeviceConfig.shared.userId
        switch event {
        case .hello:
            SyncEngine.shared.requestSync(delay: 0.8)

        case .message(let message):
            LocalStore.shared.upsertMessages([message])
            if message.senderId != myId {
                if chatVisible {
                    markRead()
                } else {
                    showBanner(message)
                }
                Haptics.tap()
            }

        case .read(_, let upTo):
            let partnerId = partner?.id ?? ""
            LocalStore.shared.markPartnerMessagesRead(upTo: upTo, partnerId: partnerId)

        case .typing:
            partnerTyping = true
            typingResetWork?.cancel()
            let work = DispatchWorkItem { [weak self] in
                self?.partnerTyping = false
            }
            typingResetWork = work
            DispatchQueue.main.asyncAfter(deadline: .now() + 4, execute: work)

        case .presence:
            break

        case .sync:
            SyncEngine.shared.requestSync(delay: 1.0)

        case .error(let text):
            errorText = text
        }
    }

    private func showBanner(_ message: ChatMessage) {
        bannerMessage = message
        bannerWork?.cancel()
        let work = DispatchWorkItem { [weak self] in
            self?.bannerMessage = nil
        }
        bannerWork = work
        DispatchQueue.main.asyncAfter(deadline: .now() + 4, execute: work)
    }

    // MARK: - 统计

    func unreadCount() -> Int {
        let myId = DeviceConfig.shared.userId
        return LocalStore.shared.messages.filter { $0.senderId != myId && $0.readAt == 0 && !$0.isSystem }.count
    }

    func lastMessagePreview() -> String {
        guard let message = LocalStore.shared.messages.last else { return "还没有聊天记录" }
        if message.isSystem { return message.body }
        switch message.kind {
        case "emoji":
            if let emoji = message.unicodeEmoji { return emoji }
            return "[表情]"
        case "image":
            return "[表情包]"
        default:
            return message.body
        }
    }

    /// 消息在列表中的显示文本
    static func displayText(for message: ChatMessage) -> String {
        switch message.kind {
        case "emoji":
            if let emoji = message.unicodeEmoji { return emoji }
            return "[表情]"
        case "image":
            return "[表情包]"
        default:
            return message.body
        }
    }
}
