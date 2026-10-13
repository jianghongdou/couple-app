import SwiftUI

/// 内置"抖音风格"表情渲染
struct StickerView: View {
    let sticker: BuiltinSticker
    var size: CGFloat = 90

    var body: some View {
        ZStack {
            RoundedRectangle(cornerRadius: size * 0.22, style: .continuous)
                .fill(
                    LinearGradient(
                        colors: [
                            Color(hex: sticker.colorHex).opacity(0.95),
                            Color(hex: sticker.colorHex).opacity(0.65)
                        ],
                        startPoint: .topLeading,
                        endPoint: .bottomTrailing
                    )
                )
            VStack(spacing: size * 0.02) {
                Text(sticker.emoji)
                    .font(.system(size: size * 0.38))
                Text(sticker.text)
                    .font(.system(size: size * 0.15, weight: .bold))
                    .foregroundColor(.white)
                    .lineLimit(1)
                    .minimumScaleFactor(0.6)
                    .padding(.horizontal, 2)
            }
        }
        .frame(width: size, height: size)
    }
}

/// 聊天中的一条消息
struct MessageRow: View {
    let message: ChatMessage
    let isMine: Bool
    let partnerEmoji: String
    let accent: Color
    let onRetry: () -> Void

    var body: some View {
        if message.isSystem {
            HStack {
                Spacer()
                Text(message.body)
                    .font(.system(size: 11))
                    .foregroundColor(.secondary)
                    .padding(.horizontal, 12)
                    .padding(.vertical, 6)
                    .background(Capsule().fill(Color.gray.opacity(0.12)))
                Spacer()
            }
            .padding(.vertical, 2)
        } else {
            HStack(alignment: .bottom, spacing: 8) {
                if isMine { Spacer(minLength: 50) }

                if !isMine {
                    EmojiAvatarView(emoji: partnerEmoji, size: 32, ringColor: AppTheme.purple)
                }

                VStack(alignment: isMine ? .trailing : .leading, spacing: 4) {
                    content
                    footer
                }

                if !isMine { Spacer(minLength: 50) }
            }
        }
    }

    @ViewBuilder
    private var content: some View {
        if message.kind == "text" {
            Text(message.body)
                .font(.system(size: 15))
                .foregroundColor(isMine ? .white : .primary)
                .padding(.horizontal, 13)
                .padding(.vertical, 9)
                .background(
                    RoundedRectangle(cornerRadius: 16, style: .continuous)
                        .fill(isMine ? accent : Color.gray.opacity(0.16))
                )
                .textSelection(.enabled)
        } else if message.kind == "image", let itemId = message.emojiItemId {
            EmojiImageView(itemId: itemId, size: 110)
        } else if message.kind == "emoji", let stickerId = message.stickerId {
            if let sticker = BuiltinStickers.sticker(stickerId) {
                StickerView(sticker: sticker, size: 84)
            } else {
                Text("😀").font(.system(size: 40))
            }
        } else if message.kind == "emoji", let emoji = message.unicodeEmoji {
            Text(emoji).font(.system(size: 40))
        } else {
            Text(message.body)
                .font(.system(size: 15))
                .padding(.horizontal, 13)
                .padding(.vertical, 9)
                .background(
                    RoundedRectangle(cornerRadius: 16, style: .continuous)
                        .fill(Color.gray.opacity(0.16))
                )
        }
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
            if isMine && message.readAt > 0 && !message.pending {
                Text("已读")
                    .font(.system(size: 10))
                    .foregroundColor(AppTheme.mint)
            }
        }
        .contentShape(Rectangle())
        .onTapGesture {
            if message.failed { onRetry() }
        }
    }
}

extension ChatMessage {
    /// 内置表情 id
    var stickerId: String? {
        if kind == "emoji", body.hasPrefix("sticker:") {
            return String(body.dropFirst(8))
        }
        return nil
    }
}
