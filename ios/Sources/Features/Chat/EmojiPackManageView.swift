import SwiftUI
import UIKit
import PhotosUI

/// 表情包管理：创建包、上传图片、删除
struct EmojiPackManageView: View {
    @EnvironmentObject var emoji: EmojiService
    @EnvironmentObject var toast: ToastCenter
    @Environment(\.dismiss) private var dismiss

    @State private var showCreate = false
    @State private var newPackName = ""
    @State private var isUploading = false
    @State private var uploadCount = 0
    @State private var uploadTotal = 0

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(spacing: 16) {
                    infoCard

                    if isUploading {
                        VStack(spacing: 6) {
                            Text("正在上传 \(uploadCount)/\(uploadTotal)")
                                .font(.system(size: 12))
                                .foregroundColor(.secondary)
                            ProgressBarView(
                                value: uploadTotal > 0 ? Double(uploadCount) / Double(uploadTotal) : 0,
                                color: AppTheme.pink
                            )
                        }
                        .card()
                    }

                    if !emoji.errorText.isEmpty {
                        Text(emoji.errorText)
                            .font(.system(size: 12))
                            .foregroundColor(AppTheme.red)
                            .frame(maxWidth: .infinity, alignment: .leading)
                            .card()
                    }

                    if emoji.myPacks.isEmpty && !emoji.isLoading {
                        EmptyStateView(
                            icon: "🗂️",
                            title: "还没有表情包",
                            message: "点右上角新建一个，再从相册上传你们的专属表情"
                        )
                        .card()
                    }

                    ForEach(emoji.myPacks, id: \.id) { pack in
                        packSection(pack)
                    }

                    if !emoji.partnerPacks.isEmpty {
                        SectionCard(title: "TA 的表情包", icon: "person.2.fill") {
                            VStack(alignment: .leading, spacing: 10) {
                                ForEach(emoji.partnerPacks, id: \.id) { pack in
                                    HStack {
                                        Text(pack.name)
                                            .font(.system(size: 13, weight: .medium))
                                        Spacer()
                                        Text("\(pack.items.count) 张")
                                            .font(.system(size: 11))
                                            .foregroundColor(.secondary)
                                    }
                                }
                            }
                        }
                    }
                }
                .padding(16)
            }
            .background(AppBackground())
            .navigationTitle("我的表情包")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("关闭") { dismiss() }
                }
                ToolbarItem(placement: .navigationBarTrailing) {
                    Button {
                        newPackName = ""
                        showCreate = true
                    } label: {
                        Image(systemName: "folder.badge.plus")
                    }
                }
            }
            .alert("新建表情包", isPresented: $showCreate) {
                TextField("名称，例如：我的沙雕图", text: $newPackName)
                Button("创建") {
                    let name = newPackName
                    Task {
                        if await emoji.createPack(name: name) {
                            toast.success("表情包已创建")
                        } else {
                            toast.error(emoji.errorText.isEmpty ? "创建失败，检查服务器连接" : emoji.errorText)
                        }
                    }
                }
                Button("取消", role: .cancel) { }
            }
            .task {
                await emoji.refresh()
            }
            .refreshable {
                await emoji.refresh()
            }
        }
    }

    // MARK: - 说明

    private var infoCard: some View {
        SectionCard(title: "怎么玩", icon: "lightbulb.fill") {
            VStack(alignment: .leading, spacing: 6) {
                bullet("先建一个表情包，再从相册选图上传")
                bullet("单张自动压缩到 400KB 以内，聊天里发出去不会糊")
                bullet("支持 JPG / PNG / GIF / WebP")
                bullet("上传后 TA 也能在聊天里用你的表情")
                bullet("表情文件都存在你自己的服务器上")
            }
        }
    }

    private func bullet(_ text: String) -> some View {
        HStack(alignment: .top, spacing: 6) {
            Text("•").foregroundColor(AppTheme.pink)
            Text(text)
                .font(.system(size: 12))
                .foregroundColor(.secondary)
            Spacer(minLength: 0)
        }
    }

    // MARK: - 单个表情包

    private func packSection(_ pack: EmojiPack) -> some View {
        SectionCard(title: pack.name, icon: "square.grid.2x2.fill", subtitle: "\(pack.items.count) 张") {
            LazyVGrid(columns: [GridItem(.adaptive(minimum: 72), spacing: 10)], spacing: 10) {
                ForEach(pack.items, id: \.id) { item in
                    EmojiImageView(itemId: item.id, size: 72)
                        .contextMenu {
                            Button(role: .destructive) {
                                Task {
                                    await emoji.deleteItem(id: item.id)
                                    toast.show("已删除")
                                }
                            } label: {
                                Label("删除", systemImage: "trash")
                            }
                        }
                }

                PackUploadPicker(pack: pack) { items in
                    upload(items, to: pack)
                }
            }

            HStack(spacing: 10) {
                Text("长按图片可以删除")
                    .font(.system(size: 11))
                    .foregroundColor(.secondary)
                Spacer()
                Button {
                    Task {
                        await emoji.deletePack(id: pack.id)
                        toast.show("表情包已删除")
                    }
                } label: {
                    Label("删除整包", systemImage: "trash")
                        .font(.system(size: 12))
                }
                .buttonStyle(SoftButtonStyle(color: AppTheme.red))
            }
        }
    }

    // MARK: - 上传

    private func upload(_ items: [PhotosPickerItem], to pack: EmojiPack) {
        isUploading = true
        uploadCount = 0
        uploadTotal = items.count

        Task {
            var success = 0
            var failed = 0
            for item in items {
                var payload: Data?
                var mime = "image/jpeg"

                if let raw = try? await item.loadTransferable(type: Data.self) {
                    if let detected = ImageTools.detectMime(raw) {
                        if detected == "image/gif" && raw.count <= 400_000 {
                            payload = raw
                            mime = detected
                        } else if let image = UIImage(data: raw) {
                            payload = ImageTools.normalizedJPEG(image)
                            mime = "image/jpeg"
                        }
                    } else if let image = UIImage(data: raw) {
                        payload = ImageTools.normalizedJPEG(image)
                    }
                }

                if let payload = payload {
                    let ok = await emoji.upload(
                        data: payload,
                        mime: mime,
                        name: "表情\(success + 1)",
                        packId: pack.id
                    )
                    if ok { success += 1 } else { failed += 1 }
                } else {
                    failed += 1
                }
                await MainActor.run { uploadCount += 1 }
            }

            await MainActor.run {
                isUploading = false
                uploadTotal = 0
                Haptics.success()
                if success > 0 {
                    toast.success("成功上传 \(success) 张表情" + (failed > 0 ? "，\(failed) 张失败" : ""))
                } else {
                    toast.error("上传失败，请检查服务器连接")
                }
            }
        }
    }
}

/// 每个表情包独立的相册选择按钮
struct PackUploadPicker: View {
    let pack: EmojiPack
    let onPicked: ([PhotosPickerItem]) -> Void

    @State private var items: [PhotosPickerItem] = []

    var body: some View {
        PhotosPicker(selection: $items, maxSelectionCount: 9, matching: .images) {
            VStack(spacing: 4) {
                Image(systemName: "plus")
                    .font(.system(size: 18))
                Text("上传")
                    .font(.system(size: 11))
            }
            .frame(width: 72, height: 72)
            .background(
                RoundedRectangle(cornerRadius: 12, style: .continuous)
                    .fill(AppTheme.pink.opacity(0.12))
            )
            .foregroundColor(AppTheme.pink)
        }
        .onChange(of: items) { value in
            if !value.isEmpty {
                onPicked(value)
                items = []
            }
        }
    }
}
