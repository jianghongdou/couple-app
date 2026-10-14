import SwiftUI

/// AI 聊天的一条消息气泡。
///
/// 刻意不复用情侣聊天的 `MessageRow`：AI 消息模型不同（没有 kind / 已读），
/// 单独一份可以让情侣聊天零改动、零回归。视觉风格保持一致。
struct AIMessageRow: View {
    let message: AIMessage
    /// 伙伴头像 emoji（AI 回复那一侧显示）
    let partnerEmoji: String
    let accent: Color
    /// 点击重发（仅失败的消息会触发）
    let onRetry: () -> Void

    var body: some View {
        HStack(alignment: .bottom, spacing: 8) {
            if message.isMine { Spacer(minLength: 50) }

            if !message.isMine {
                EmojiAvatarView(emoji: partnerEmoji, size: 32, ringColor: AppTheme.purple)
            }

            VStack(alignment: message.isMine ? .trailing : .leading, spacing: 4) {
                bubble
                footer
            }

            if !message.isMine { Spacer(minLength: 50) }
        }
    }

    private var bubble: some View {
        Text(message.body)
            .font(.system(size: 15))
            .foregroundColor(message.isMine ? .white : .primary)
            .padding(.horizontal, 13)
            .padding(.vertical, 9)
            .background(
                RoundedRectangle(cornerRadius: 16, style: .continuous)
                    .fill(message.isMine ? accent : Color.gray.opacity(0.16))
            )
            .textSelection(.enabled)
    }

    private var footer: some View {
        HStack(spacing: 5) {
            Text(DateUtils.short(message.createdAt))
                .font(.system(size: 10))
                .foregroundColor(.secondary)
            if message.pending {
                Text("发送中…")
                    .font(.system(size: 10))
                    .foregroundColor(.secondary)
            }
            if message.failed {
                Text("发送失败，点我重发")
                    .font(.system(size: 10))
                    .foregroundColor(AppTheme.red)
            }
        }
        .contentShape(Rectangle())
        .onTapGesture {
            if message.failed { onRetry() }
        }
    }
}

/// 「AI 正在输入…」气泡（等待服务端返回 reply 时显示）
struct AITypingRow: View {
    let partnerEmoji: String

    /// 用三个小圆点的透明度循环做「正在输入」动效（避免定时器泄漏）
    @State private var dimmed = false

    var body: some View {
        HStack(alignment: .bottom, spacing: 8) {
            EmojiAvatarView(emoji: partnerEmoji, size: 32, ringColor: AppTheme.purple)

            HStack(spacing: 6) {
                Text("AI 正在输入")
                    .font(.system(size: 12))
                    .foregroundColor(.secondary)
                HStack(spacing: 3) {
                    ForEach(0..<3, id: \.self) { index in
                        Circle()
                            .fill(AppTheme.purple)
                            .frame(width: 4, height: 4)
                            .opacity(dimmed ? 0.25 : 1)
                            .animation(
                                .easeInOut(duration: 0.5)
                                    .repeatForever(autoreverses: true)
                                    .delay(Double(index) * 0.15),
                                value: dimmed
                            )
                    }
                }
            }
            .padding(.horizontal, 13)
            .padding(.vertical, 9)
            .background(
                RoundedRectangle(cornerRadius: 16, style: .continuous)
                    .fill(Color.gray.opacity(0.16))
            )

            Spacer(minLength: 50)
        }
        .onAppear {
            dimmed = true
        }
    }
}
