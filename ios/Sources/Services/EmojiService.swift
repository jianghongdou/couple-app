import Foundation
import Combine

/// 表情包服务：列表拉取、上传、缓存、删除
final class EmojiService: ObservableObject {
    static let shared = EmojiService()

    @Published var packs: [EmojiPack] = []
    @Published var isLoading = false
    @Published var isUploading = false
    @Published var errorText = ""
    /// 每次有新图片下载完成就 +1，用来刷新界面
    @Published var cacheVersion = 0

    private var downloading: Set<String> = []
    private let queue = DispatchQueue(label: "com.coupleapp.emoji", qos: .utility)

    private init() {}

    /// 全部表情（拉平）
    var allItems: [EmojiItem] {
        packs.flatMap { $0.items }
    }

    /// 我的表情包（可管理）
    var myPacks: [EmojiPack] {
        let myId = DeviceConfig.shared.userId
        return packs.filter { $0.ownerId == myId || $0.ownerId.isEmpty }
    }

    var partnerPacks: [EmojiPack] {
        let myId = DeviceConfig.shared.userId
        return packs.filter { !$0.ownerId.isEmpty && $0.ownerId != myId }
    }

    func item(for id: String) -> EmojiItem? {
        for pack in packs {
            if let found = pack.items.first(where: { $0.id == id }) {
                return found
            }
        }
        return nil
    }

    // MARK: - 列表

    func refresh() async {
        guard !DeviceConfig.shared.token.isEmpty else { return }
        await MainActor.run { self.isLoading = true }
        do {
            let list = try await APIClient.shared.fetchEmojiPacks()
            await MainActor.run {
                self.packs = list
                self.isLoading = false
                self.errorText = ""
                LocalStore.shared.replacePacks(list)
            }
        } catch let error as APIError {
            await MainActor.run {
                self.isLoading = false
                self.errorText = error.message
            }
        } catch {
            await MainActor.run {
                self.isLoading = false
                self.errorText = "表情包加载失败"
            }
        }
    }

    // MARK: - 管理

    func createPack(name: String) async -> Bool {
        let trimmed = name.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !trimmed.isEmpty else { return false }
        do {
            _ = try await APIClient.shared.createEmojiPack(name: String(trimmed.prefix(20)))
            await refresh()
            return true
        } catch let error as APIError {
            await MainActor.run { self.errorText = error.message }
            return false
        } catch {
            return false
        }
    }

    func deletePack(id: String) async {
        do {
            try await APIClient.shared.deleteEmojiPack(id: id)
            await refresh()
        } catch let error as APIError {
            await MainActor.run { self.errorText = error.message }
        } catch {
            await MainActor.run { self.errorText = "删除失败" }
        }
    }

    /// 上传一张表情。返回是否成功
    func upload(data: Data, mime: String, name: String, packId: String) async -> Bool {
        guard !packId.isEmpty else {
            await MainActor.run { self.errorText = "请先创建一个表情包" }
            return false
        }
        await MainActor.run { self.isUploading = true }
        do {
            let item = try await APIClient.shared.uploadEmoji(packId: packId, mime: mime, name: name, data: data)
            if let item = item {
                _ = LocalStore.shared.storeEmojiFile(id: item.id, ext: item.fileExtension, data: data)
            }
            await refresh()
            await MainActor.run {
                self.isUploading = false
                self.errorText = ""
                self.cacheVersion += 1
            }
            return true
        } catch let error as APIError {
            await MainActor.run {
                self.isUploading = false
                self.errorText = error.message
            }
            return false
        } catch {
            await MainActor.run {
                self.isUploading = false
                self.errorText = "上传失败"
            }
            return false
        }
    }

    func deleteItem(id: String) async {
        do {
            try await APIClient.shared.deleteEmojiItem(id: id)
            await refresh()
        } catch let error as APIError {
            await MainActor.run { self.errorText = error.message }
        } catch {
            await MainActor.run { self.errorText = "删除失败" }
        }
    }

    // MARK: - 本地缓存

    func localURL(for itemId: String) -> URL? {
        LocalStore.shared.cachedEmojiURL(id: itemId)
    }

    /// 确保表情已缓存到本地，供聊天界面显示
    func ensureCached(_ itemId: String) {
        if localURL(for: itemId) != nil { return }
        guard !itemId.isEmpty else { return }
        guard !downloading.contains(itemId) else { return }
        guard let item = item(for: itemId) else { return }

        downloading.insert(itemId)
        Task {
            let path = item.url.isEmpty ? "/api/emoji/blob/\(item.id)" : item.url
            let data = await APIClient.shared.download(path)
            await MainActor.run {
                self.downloading.remove(itemId)
                if let data = data, !data.isEmpty {
                    let ext = ImageTools.detectMime(data) == "image/png" ? "png"
                        : (ImageTools.detectMime(data) == "image/gif" ? "gif"
                        : (ImageTools.detectMime(data) == "image/webp" ? "webp" : "jpg"))
                    _ = LocalStore.shared.storeEmojiFile(id: itemId, ext: ext, data: data)
                    self.cacheVersion += 1
                }
            }
        }
    }

    /// 预下载整包表情（打开表情面板时调用）
    func prefetch(_ items: [EmojiItem]) {
        for item in items {
            ensureCached(item.id)
        }
    }
}
