import SwiftUI
import UIKit

/// 聊天
struct ChatView: View {
    @EnvironmentObject var session: SessionManager
    @EnvironmentObject var store: LocalStore
    @EnvironmentObject var chat: ChatService
    @EnvironmentObject var realtime: RealtimeClient
    @EnvironmentObject var toast: ToastCenter

    @State private var input = ""
    @State private var showEmojiPanel = false
    @State private var showPackManage = false

    private var accent: Color { AppTheme.accent(for: store.state.settings.accentKey) }
    private var myId: String { session.myId }
    private var messages: [ChatMessage] { store.state.messages }

    var body: some View {
        NavigationStack {
            VStack(spacing: 0) {
                statusBar

                if !session.isPaired {
                    notPairedView
                } else {
                    messageList
                    inputBar
                }
            }
            .background(AppBackground())
            .navigationTitle(session.partnerName)
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .navigationBarTrailing) {
                    Menu {
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
                    } label: {
                        Image(systemName: "ellipsis.circle")
                    }
                }
            }
            .sheet(isPresented: $showPackManage) {
                EmojiPackManageView().appEnvironment()
            }
            .onAppear {
                chat.chatVisible = true
                chat.markRead()
                Task { await chat.refreshLatest() }
            }
            .onDisappear {
                chat.chatVisible = false
            }
        }
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

            if chat.partnerTyping {
                Text("· 对方正在输入…")
                    .font(.system(size: 11))
                    .foregroundColor(accent)
            }

            Spacer()

            if !chat.errorText.isEmpty {
                Text(chat.errorText)
                    .font(.system(size: 11))
                    .foregroundColor(AppTheme.red)
                    .lineLimit(1)
            } else {
                Text("\(messages.count) 条消息")
                    .font(.system(size: 11))
                    .foregroundColor(.secondary)
            }
        }
        .padding(.horizontal, 16)
        .padding(.vertical, 6)
        .background(Color.gray.opacity(0.08))
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
            Spacer()
        }
    }

    // MARK: - 消息列表

    private var messageList: some View {
        ScrollViewReader { proxy in
            ScrollView {
                LazyVStack(spacing: 10) {
                    if chat.hasMoreHistory {
                        Button {
                            Task { await chat.loadOlder() }
                        } label: {
                            Text(chat.isLoadingHistory ? "加载中…" : "加载更早的消息")
                                .font(.system(size: 12))
                                .foregroundColor(accent)
                        }
                        .buttonStyle(.plain)
                        .padding(.vertical, 8)
                    }

                    if messages.isEmpty {
                        EmptyStateView(icon: "💬", title: "还没有聊天记录", message: "说点什么开始你们的第一句吧")
                            .padding(.top, 40)
                    }

                    ForEach(messages, id: \.id) { message in
                        MessageRow(
                            message: message,
                            isMine: message.senderId == myId,
                            partnerEmoji: session.partner?.emoji ?? "🐻",
                            accent: accent,
                            onRetry: { chat.resend(clientId: message.clientId) }
                        )
                        .id(message.id)
                    }

                    Color.clear
                        .frame(height: 1)
                        .id("bottom")
                }
                .padding(.horizontal, 14)
                .padding(.vertical, 10)
            }
            .onChange(of: messages.count) { _ in
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
                        .foregroundColor(accent)
                }
                .buttonStyle(.plain)

                TextField("说点什么…", text: $input, axis: .vertical)
                    .lineLimit(1...4)
                    .padding(.horizontal, 12)
                    .padding(.vertical, 8)
                    .background(
                        RoundedRectangle(cornerRadius: 18, style: .continuous)
                            .fill(Color.gray.opacity(0.12))
                    )
                    .onChange(of: input) { _ in
                        chat.sendTyping()
                    }

                Button {
                    sendText()
                } label: {
                    Image(systemName: "paperplane.fill")
                        .font(.system(size: 16))
                        .foregroundColor(.white)
                        .frame(width: 36, height: 36)
                        .background(Circle().fill(input.trimmingCharacters(in: .whitespaces).isEmpty ? Color.gray.opacity(0.5) : accent))
                }
                .buttonStyle(.plain)
                .disabled(input.trimmingCharacters(in: .whitespaces).isEmpty)
            }
            .padding(.horizontal, 12)
            .padding(.vertical, 8)

            if showEmojiPanel {
                EmojiPanelView(
                    onPick: { kind, value in
                        if kind == "sticker" {
                            chat.send(stickerId: value)
                        } else {
                            chat.send(unicodeEmoji: value)
                        }
                    },
                    onPickImage: { itemId in
                        chat.send(emojiItemId: itemId)
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
        chat.send(text: text)
        Haptics.tap()
    }
}
