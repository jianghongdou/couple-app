import SwiftUI
import UIKit

/// 聊天表情面板：内置表情 / 抖音风格表情 / 我的表情包
struct EmojiPanelView: View {
    let onPick: (String, String) -> Void
    let onPickImage: (String) -> Void
    let onManage: () -> Void

    @EnvironmentObject var emoji: EmojiService

    @State private var tab = 0

    private let emojiColumns = [GridItem(.adaptive(minimum: 42), spacing: 8)]
    private let stickerColumns = [GridItem(.adaptive(minimum: 76), spacing: 10)]

    init(
        onPick: @escaping (String, String) -> Void,
        onPickImage: @escaping (String) -> Void,
        onManage: @escaping () -> Void
    ) {
        self.onPick = onPick
        self.onPickImage = onPickImage
        self.onManage = onManage
    }

    var body: some View {
        VStack(spacing: 0) {
            Picker("", selection: $tab) {
                Text("内置表情").tag(0)
                Text("抖音表情").tag(1)
                Text("我的表情包").tag(2)
            }
            .pickerStyle(.segmented)
            .padding(.horizontal, 12)
            .padding(.vertical, 8)

            ScrollView {
                switch tab {
                case 1: stickerGrid
                case 2: packGrid
                default: emojiGrid
                }
            }
        }
        .background(Color(.secondarySystemBackground))
        .onAppear {
            emoji.prefetch(emoji.allItems)
        }
    }

    // MARK: - 内置 emoji

    private var emojiGrid: some View {
        VStack(alignment: .leading, spacing: 14) {
            ForEach(EmojiLibrary.groups) { group in
                VStack(alignment: .leading, spacing: 8) {
                    Text(group.title)
                        .font(.system(size: 12, weight: .semibold))
                        .foregroundColor(.secondary)
                        .padding(.horizontal, 4)
                    LazyVGrid(columns: emojiColumns, spacing: 8) {
                        ForEach(group.emojis, id: \.self) { item in
                            Button {
                                Haptics.selection()
                                onPick("emoji", item)
                            } label: {
                                Text(item)
                                    .font(.system(size: 26))
                                    .frame(width: 42, height: 42)
                            }
                            .buttonStyle(.plain)
                        }
                    }
                }
            }
        }
        .padding(12)
    }

    // MARK: - 抖音风格

    private var stickerGrid: some View {
        VStack(alignment: .leading, spacing: 10) {
            Text("内置抖音风格表情（可点右上角上传自己的表情包）")
                .font(.system(size: 11))
                .foregroundColor(.secondary)
                .padding(.horizontal, 4)

            LazyVGrid(columns: stickerColumns, spacing: 10) {
                ForEach(BuiltinStickers.all, id: \.id) { sticker in
                    Button {
                        Haptics.selection()
                        onPick("sticker", sticker.id)
                    } label: {
                        StickerView(sticker: sticker, size: 76)
                    }
                    .buttonStyle(.plain)
                }
            }
        }
        .padding(12)
    }

    // MARK: - 我的表情包

    private var packGrid: some View {
        VStack(alignment: .leading, spacing: 14) {
            HStack {
                Text("共 \(emoji.allItems.count) 张")
                    .font(.system(size: 12))
                    .foregroundColor(.secondary)
                Spacer()
                Button {
                    onManage()
                } label: {
                    Label("管理 / 上传", systemImage: "plus.circle")
                        .font(.system(size: 12))
                }
                .buttonStyle(.plain)
                .foregroundColor(AppTheme.pink)
            }
            .padding(.horizontal, 4)

            if emoji.isLoading && emoji.packs.isEmpty {
                ProgressView().frame(maxWidth: .infinity).padding(.vertical, 30)
            }

            if emoji.packs.isEmpty {
                EmptyStateView(icon: "🙂", title: "还没有表情包", message: "点「管理 / 上传」创建表情包并上传图片")
            }

            ForEach(emoji.packs, id: \.id) { pack in
                VStack(alignment: .leading, spacing: 8) {
                    HStack(spacing: 6) {
                        Text(pack.name)
                            .font(.system(size: 12, weight: .semibold))
                        if pack.ownerId == DeviceConfig.shared.userId {
                            TagChip(text: "我的", color: AppTheme.pink)
                        } else if !pack.ownerId.isEmpty {
                            TagChip(text: "TA的", color: AppTheme.purple)
                        }
                        Spacer()
                        Text("\(pack.items.count) 张")
                            .font(.system(size: 11))
                            .foregroundColor(.secondary)
                    }
                    .padding(.horizontal, 4)

                    LazyVGrid(columns: stickerColumns, spacing: 10) {
                        ForEach(pack.items, id: \.id) { item in
                            Button {
                                Haptics.selection()
                                onPickImage(item.id)
                            } label: {
                                EmojiImageView(itemId: item.id, size: 76)
                            }
                            .buttonStyle(.plain)
                        }
                    }
                }
            }
        }
        .padding(12)
    }
}
