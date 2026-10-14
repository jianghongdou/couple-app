import SwiftUI
import UIKit

/// 聊天
///
/// 顶部新增「会话切换条」：情侣（伴侣头像）+ 各个 AI 伙伴。
/// - 情侣会话：**完全沿用原有逻辑**（ChatService + LocalStore），零改动；
/// - AI 会话：走 AIChatService，与服务端 `/api/ai/chat/messages` 通信，
///   不参与情侣同步、不写情侣聊天记录、不产生未读角标。
struct ChatView: View {
    @EnvironmentObject var session: SessionManager
    @EnvironmentObject var store: LocalStore
    @EnvironmentObject var chat: ChatService
    @EnvironmentObject var realtime: RealtimeClient
    @EnvironmentObject var toast: ToastCenter
    @EnvironmentObject var ai: AIChatService

    @State private var input = ""
    @State private var showEmojiPanel = false
    @State private var showPackManage = false
    @State private var showAIManage = false
    @State private var editingPartner: AIPartner?
    /// 用户是否已经主动选过会话（避免每次回到聊天页都跳去 AI）
    @State private var hasChosenConversation = false

    private var accent: Color { AppTheme.accent(for: store.state.settings.accentKey) }
    private var myId: String { session.myId }
    /// 当前是否在和 AI 伙伴聊天
    private var isAIMode: Bool { ai.hasActivePartner }

    var body: some View {
        NavigationStack {
            VStack(spacing: 0) {
                conversationBar
                statusBar

                if !session.isPaired && !isAIMode {
                    // 未绑定情侣：原来的引导页 + 进入 AI 伙伴管理
                    notPairedView
                } else if isAIMode {
                    aiChatBody
                } else {
                    coupleChatBody
                }
            }
            .background(AppBackground())
            .navigationTitle(navigationTitle)
            .navigationBarTitleDisplayMode(.inline)
            .toolbar { toolbarContent }
            .sheet(isPresented: $showPackManage) {
                EmojiPackManageView().appEnvironment()
            }
            .sheet(isPresented: $showAIManage) {
                AIManageView().appEnvironment()
            }
            .sheet(item: $editingPartner) { partner in
                AIPartnerEditView(partner: partner).appEnvironment()
            }
            .onAppear {
                chat.chatVisible = true
                chat.markRead()
                Task { await chat.refreshLatest() }
                // AI 伙伴列表（未登录时内部直接返回，不会网络报错）
                Task {
                    await ai.loadPartners()
                    autoSelectPartnerIfNeeded()
                }
            }
            .onDisappear {
                chat.chatVisible = false
            }
        }
    }

    /// 上次就停在 AI 会话时，回到聊天页自动恢复（不打断伴侣聊天）
    private func autoSelectPartnerIfNeeded() {
        guard !hasChosenConversation, ai.activePartnerId.isEmpty else { return }
        guard ai.isFeatureAvailable, let first = ai.partners.first else { return }
        hasChosenConversation = true
        Task {
            await ai.selectPartnerAndLoad(first.id)
        }
    }

    // MARK: - 标题

    private var navigationTitle: String {
        if let partner = ai.activePartner { return partner.displayName }
        return session.isPaired ? session.partnerName : "聊天"
    }

    // MARK: - 会话切换条

    private var conversationBar: some View {
        ScrollView(.horizontal, showsIndicators: false) {
            HStack(spacing: 12) {
                couplePill
                if ai.isFeatureAvailable {
                    ForEach(ai.partners, id: \.id) { partner in
                        partnerPill(partner)
                    }
                    addPartnerButton
                }
            }
            .padding(.horizontal, 14)
            .padding(.vertical, 9)
        }
        .background(Color.gray.opacity(0.08))
        .overlay(alignment: .bottom) {
            Divider().opacity(0.6)
        }
    }

    /// 情侣会话（伴侣头像 + 昵称）
    private var couplePill: some View {
        Button {
            selectCouple()
        } label: {
            HStack(spacing: 8) {
                EmojiAvatarView(
                    emoji: session.isPaired ? (session.partner?.emoji ?? "🐻") : "💌",
                    size: 34,
                    ringColor: accent
                )
                VStack(alignment: .leading, spacing: 1) {
                    Text(session.isPaired ? session.partnerName : "情侣")
                        .font(.system(size: 13, weight: .semibold))
                        .foregroundColor(isAIMode ? .primary : .white)
                    Text("情侣聊天")
                        .font(.system(size: 10))
                        .foregroundColor(isAIMode ? .secondary : Color.white.opacity(0.85))
                }
            }
            .padding(.horizontal, 10)
            .padding(.vertical, 5)
            .background(
                Capsule().fill(isAIMode ? Color.gray.opacity(0.12) : accent)
            )
            .overlay(
                Capsule().stroke(isAIMode ? Color.gray.opacity(0.25) : Color.clear, lineWidth: 1)
            )
        }
        .buttonStyle(.plain)
    }

    /// 一个 AI 伙伴
    private func partnerPill(_ partner: AIPartner) -> some View {
        let selected = ai.activePartnerId == partner.id
        return Button {
            selectPartner(partner)
        } label: {
            HStack(spacing: 8) {
                EmojiAvatarView(emoji: partner.emoji, size: 34, ringColor: AppTheme.purple)
                VStack(alignment: .leading, spacing: 1) {
                    Text(partner.displayName)
                        .font(.system(size: 13, weight: .semibold))
                        .foregroundColor(selected ? .white : .primary)
                        .lineLimit(1)
                    Text(partner.enabled ? "AI 伙伴" : "已停用")
                        .font(.system(size: 10))
                        .foregroundColor(selected ? Color.white.opacity(0.85) : .secondary)
                }
            }
            .padding(.horizontal, 10)
            .padding(.vertical, 5)
            .background(
                Capsule().fill(selected ? AppTheme.purple : Color.gray.opacity(0.12))
            )
            .overlay(
                Capsule().stroke(selected ? Color.clear : Color.gray.opacity(0.25), lineWidth: 1)
            )
        }
        .buttonStyle(.plain)
        .contextMenu {
            Button {
                editingPartner = partner
            } label: {
                Label("编辑", systemImage: "square.and.pencil")
            }
            Button {
                showAIManage = true
            } label: {
                Label("管理 AI 伙伴", systemImage: "person.crop.circle.badge.gearshape")
            }
        }
    }

    /// 末尾的「＋」：新增 AI 伙伴
    private var addPartnerButton: some View {
        Button {
            if ai.canAddPartner {
                editingPartner = AIPartner()
            } else {
                toast.error("最多只能创建 10 个 AI 伙伴")
            }
        } label: {
            VStack(spacing: 2) {
                Image(systemName: "plus")
                    .font(.system(size: 15, weight: .semibold))
                Text(ai.canAddPartner ? "新增" : "已满 10 个")
                    .font(.system(size: 10))
            }
            .frame(width: 54, height: 46)
            .foregroundColor(ai.canAddPartner ? AppTheme.purple : Color.secondary)
            .background(
                RoundedRectangle(cornerRadius: 12, style: .continuous)
                    .fill(AppTheme.purple.opacity(ai.canAddPartner ? 0.12 : 0.06))
            )
        }
        .buttonStyle(.plain)
    }

    // MARK: - 状态条

    private var statusBar: some View {
        HStack(spacing: 6) {
            Circle()
                .fill(realtime.isConnected ? AppTheme.mint : (realtime.isDegraded ? AppTheme.orange : AppTheme.red))
                .frame(width: 6, height: 6)
            Text(realtime.isConnected ? (realtime.partnerOnline ? "在线" : "已连接") : (realtime.isDegraded ? "轮询刷新中（5 秒）" : "连接中…"))
                .font(.system(size: 11))
                .foregroundColor(.secondary)

            if isAIMode {
                if ai.isSending {
                    Text("· AI 正在输入…")
                        .font(.system(size: 11))
                        .foregroundColor(AppTheme.purple)
                }
            } else if chat.partnerTyping {
                Text("· 对方正在输入…")
                    .font(.system(size: 11))
                    .foregroundColor(accent)
            }

            Spacer()

            if !currentErrorText.isEmpty {
                Text(currentErrorText)
                    .font(.system(size: 11))
                    .foregroundColor(AppTheme.red)
                    .lineLimit(1)
            } else if isAIMode {
                Text("\(ai.messages.count) 条消息")
                    .font(.system(size: 11))
                    .foregroundColor(.secondary)
            } else {
                Text("\(store.state.messages.count) 条消息")
                    .font(.system(size: 11))
                    .foregroundColor(.secondary)
            }
        }
        .padding(.horizontal, 16)
        .padding(.vertical, 6)
        .background(Color.gray.opacity(0.08))
    }

    private var currentErrorText: String {
        isAIMode ? ai.errorText : chat.errorText
    }

    // MARK: - 未绑定

    private var notPairedView: some View {
        VStack {
            Spacer()
            EmptyStateView(
                icon: "💌",
                title: "还没有绑定情侣",
                message: "去「我的」页面创建情侣空间，或输入对方的邀请码完成绑定"
            )
            if ai.isFeatureAvailable {
                Text("上面切换条里的 AI 伙伴不用绑定情侣也能直接聊")
                    .font(.system(size: 12))
                    .foregroundColor(.secondary)
                    .multilineTextAlignment(.center)
                    .padding(.horizontal, 24)
            }
            Spacer()
        }
    }

    // MARK: - 情侣会话（保持原有实现）

    private var coupleChatBody: some View {
        Group {
            messageList(messages: store.state.messages, isAIMode: false)
            inputBar
        }
    }

    // MARK: - AI 会话

    private var aiChatBody: some View {
        Group {
            if let guidance = ai.guidanceText, !guidance.isEmpty {
                aiPlaceholder(icon: "🤖", title: "AI 聊天暂不可用", message: guidance, showRetry: false)
            } else if ai.needsProviderHint {
                aiPlaceholder(
                    icon: "🛠️",
                    title: "服务端还没有配置 AI 供应商",
                    message: AIErrorMapper.notConfiguredText + "；配置好之后点下面的「重试」。",
                    showRetry: true
                )
            } else if let partner = ai.activePartner, !partner.enabled {
                aiPlaceholder(
                    icon: "💤",
                    title: "「\(partner.displayName)」已停用",
                    message: "点右上角「⋯」→「编辑这个 AI 伙伴」，把「启用」打开就能继续聊。",
                    showRetry: false
                )
            } else {
                messageList(messages: ai.messages.map { $0.toChatMessage() }, isAIMode: true)
                inputBar
            }
        }
    }

    private func aiPlaceholder(icon: String, title: String, message: String, showRetry: Bool) -> some View {
        VStack(spacing: 14) {
            Spacer()
            EmptyStateView(icon: icon, title: title, message: message)
            if showRetry {
                Button {
                    Task { await ai.refresh() }
                } label: {
                    Label("重试", systemImage: "arrow.clockwise")
                }
                .buttonStyle(SoftButtonStyle(color: AppTheme.purple))
            }
            Spacer()
        }
        .padding(.horizontal, 18)
    }

    // MARK: - 消息列表

    private func messageList(messages: [ChatMessage], isAIMode: Bool) -> some View {
        ScrollViewReader { proxy in
            ScrollView {
                LazyVStack(spacing: 10) {
                    if isAIMode && ai.hasMoreHistory {
                        Button {
                            Task { await ai.loadOlder() }
                        } label: {
                            Text(ai.isLoading ? "加载中…" : "加载更早的消息")
                                .font(.system(size: 12))
                                .foregroundColor(AppTheme.purple)
                        }
                        .buttonStyle(.plain)
                        .padding(.vertical, 8)
                    }

                    if messages.isEmpty {
                        if isAIMode {
                            EmptyStateView(icon: "🤖", title: "还没有聊天记录", message: "和它说第一句话吧，回复由服务端配置的大模型生成")
                                .padding(.top, 40)
                        } else {
                            EmptyStateView(icon: "💬", title: "还没有聊天记录", message: "说点什么开始你们的第一句吧")
                                .padding(.top, 40)
                        }
                    }

                    ForEach(messages, id: \.id) { message in
                        if isAIMode {
                            AIMessageRow(
                                message: aiMessage(for: message),
                                partnerEmoji: ai.activePartner?.emoji ?? "🤖",
                                accent: AppTheme.purple,
                                onRetry: { ai.retry(clientId: message.clientId) }
                            )
                            .id(message.id)
                        } else {
                            MessageRow(
                                message: message,
                                isMine: message.senderId == myId,
                                partnerEmoji: session.partner?.emoji ?? "🐻",
                                accent: accent,
                                onRetry: { chat.resend(clientId: message.clientId) }
                            )
                            .id(message.id)
                        }
                    }

                    if isAIMode && ai.isTyping {
                        AITypingRow(partnerEmoji: ai.activePartner?.emoji ?? "🤖")
                            .id("ai_typing")
                    }

                    Color.clear
                        .frame(height: 1)
                        .id("bottom")
                }
                .padding(.horizontal, 14)
                .padding(.vertical, 10)
            }
            .onChange(of: scrollKey(messages: messages, isAIMode: isAIMode)) { _ in
                withAnimation(.easeOut(duration: 0.2)) {
                    proxy.scrollTo("bottom", anchor: .bottom)
                }
            }
            .onAppear {
                DispatchQueue.main.asyncAfter(deadline: .now() + 0.25) {
                    proxy.scrollTo("bottom", anchor: .bottom)
                }
            }
        }
    }

    /// 滚动触发键：消息条数 + 是否正在输入
    private func scrollKey(messages: [ChatMessage], isAIMode: Bool) -> String {
        if isAIMode {
            return "\(messages.count)-\(ai.isTyping ? 1 : 0)-\(ai.activePartnerId)"
        }
        return "\(messages.count)"
    }

    /// 把显示用的通用消息还原成 AI 消息模型（只为气泡渲染）
    private func aiMessage(for message: ChatMessage) -> AIMessage {
        if let original = ai.messages.first(where: { "ai_" + $0.id == message.id }) {
            return original
        }
        var value = AIMessage()
        value.id = message.id
        value.clientId = message.clientId
        value.senderId = message.senderId
        value.isMine = message.senderId != AIMessage.aiSenderId
        value.body = message.body
        value.createdAt = message.createdAt
        value.pending = message.pending
        value.failed = message.failed
        return value
    }

    // MARK: - 输入栏

    private var inputBar: some View {
        VStack(spacing: 0) {
            Divider()
            HStack(spacing: 10) {
                Button {
                    showEmojiPanel.toggle()
                    Haptics.selection()
                } label: {
                    Image(systemName: showEmojiPanel ? "keyboard" : "face.smiling")
                        .font(.system(size: 20))
                        .foregroundColor(isAIMode ? AppTheme.purple : accent)
                }
                .buttonStyle(.plain)

                TextField(isAIMode ? "和 \(ai.activePartner?.displayName ?? "AI") 聊天…" : "说点什么…", text: $input, axis: .vertical)
                    .lineLimit(1...4)
                    .padding(.horizontal, 12)
                    .padding(.vertical, 8)
                    .background(
                        RoundedRectangle(cornerRadius: 18, style: .continuous)
                            .fill(Color.gray.opacity(0.12))
                    )
                    .onChange(of: input) { _ in
                        // 情侣会话保留「正在输入」上报；AI 会话不需要上报
                        if !isAIMode { chat.sendTyping() }
                    }

                Button {
                    sendText()
                } label: {
                    Image(systemName: "paperplane.fill")
                        .font(.system(size: 16))
                        .foregroundColor(.white)
                        .frame(width: 36, height: 36)
                        .background(Circle().fill(input.trimmingCharacters(in: .whitespaces).isEmpty ? Color.gray.opacity(0.5) : (isAIMode ? AppTheme.purple : accent)))
                }
                .buttonStyle(.plain)
                .disabled(input.trimmingCharacters(in: .whitespaces).isEmpty)
            }
            .padding(.horizontal, 12)
            .padding(.vertical, 8)

            if isAIMode && showEmojiPanel {
                Text("AI 聊天目前只发文字：点表情会填进输入框，再点发送")
                    .font(.system(size: 11))
                    .foregroundColor(.secondary)
                    .frame(maxWidth: .infinity, alignment: .leading)
                    .padding(.horizontal, 14)
                    .padding(.bottom, 4)
            }

            if showEmojiPanel {
                EmojiPanelView(
                    onPick: { kind, value in
                        if isAIMode {
                            // AI 只支持文本：把表情插入输入框
                            insertIntoInput(value)
                        } else if kind == "sticker" {
                            chat.send(stickerId: value)
                        } else {
                            chat.send(unicodeEmoji: value)
                        }
                    },
                    onPickImage: { itemId in
                        if isAIMode {
                            toast.show("AI 聊天暂时只能发文字")
                        } else {
                            chat.send(emojiItemId: itemId)
                        }
                    },
                    onManage: {
                        showEmojiPanel = false
                        showPackManage = true
                    }
                )
                .frame(height: 260)
                .transition(.move(edge: .bottom))
            }
        }
        .background(Color(.systemBackground).opacity(0.95))
        .animation(.easeOut(duration: 0.2), value: showEmojiPanel)
    }

    private func sendText() {
        let text = input
        guard !text.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty else { return }
        input = ""
        if isAIMode {
            Task { await ai.send(text: text) }
        } else {
            chat.send(text: text)
        }
        Haptics.tap()
    }

    /// 把一段文本插到输入框光标位置（这里简化为追加）
    private func insertIntoInput(_ value: String) {
        input += value
        Haptics.selection()
    }

    // MARK: - 顶部菜单

    @ToolbarContentBuilder
    private var toolbarContent: some ToolbarContent {
        ToolbarItem(placement: .navigationBarTrailing) {
            Menu {
                if isAIMode {
                    Button {
                        editingPartner = ai.activePartner
                    } label: {
                        Label("编辑这个 AI 伙伴", systemImage: "square.and.pencil")
                    }
                    Button {
                        Task { await ai.loadOlder() }
                    } label: {
                        Label("加载更早的消息", systemImage: "clock.arrow.circlepath")
                    }
                    Button {
                        Task { await ai.loadLatest() }
                    } label: {
                        Label("刷新聊天记录", systemImage: "arrow.clockwise")
                    }
                } else {
                    Button {
                        showPackManage = true
                    } label: {
                        Label("管理我的表情包", systemImage: "face.smiling")
                    }
                    Button {
                        Task { await chat.loadOlder() }
                    } label: {
                        Label("加载更早的消息", systemImage: "clock.arrow.circlepath")
                    }
                    Button {
                        chat.markRead()
                    } label: {
                        Label("全部标记已读", systemImage: "checkmark.circle")
                    }
                    Button {
                        showAIManage = true
                    } label: {
                        Label("AI 伙伴管理", systemImage: "person.crop.circle.badge.gearshape")
                    }
                }
            } label: {
                Image(systemName: "ellipsis.circle")
            }
        }
    }

    // MARK: - 切换会话

    private func selectCouple() {
        guard isAIMode else { return }
        hasChosenConversation = true
        showEmojiPanel = false
        ai.clearActivePartner()
        Task { await chat.refreshLatest() }
        Haptics.selection()
    }

    private func selectPartner(_ partner: AIPartner) {
        guard ai.activePartnerId != partner.id else { return }
        hasChosenConversation = true
        showEmojiPanel = false
        ai.selectPartner(partner.id)
        if !ai.isFeatureAvailable {
            // 功能不可用时不做无意义的请求
            return
        }
        Task { await ai.loadLatest() }
        Task { await ai.loadProviders() }
        Haptics.selection()
    }
}
