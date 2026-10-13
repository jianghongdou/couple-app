import Foundation
import Combine

/// 同步引擎：把火花 / 宠物 / 签到 / 愿望 / 加班记录 / 设置同步给伴侣。
/// **记账与密码本永远不上传**（见 SyncKind.localOnly）。
final class SyncEngine: ObservableObject {
    static let shared = SyncEngine()

    @Published var isSyncing = false
    @Published var lastSyncAt: Double = 0
    @Published var statusText = "尚未同步"
    @Published var lastError = ""
    @Published var isEnabled = true

    private var timer: Timer?
    private var autoSyncTask: Task<Void, Never>?
    private let maxBatch = 200

    private init() {}

    // MARK: - 生命周期

    func startAutoSync() {
        stopAutoSync()
        guard DeviceConfig.shared.token.isEmpty == false else { return }
        DispatchQueue.main.async {
            self.timer = Timer.scheduledTimer(withTimeInterval: 45, repeats: true) { [weak self] _ in
                Task { await self?.syncNow() }
            }
        }
        Task { await syncNow() }
    }

    func stopAutoSync() {
        timer?.invalidate()
        timer = nil
        autoSyncTask?.cancel()
        autoSyncTask = nil
    }

    /// 本地改动后调用：延迟合并，避免频繁请求
    func requestSync(delay: Double = 2.5) {
        autoSyncTask?.cancel()
        autoSyncTask = Task { [weak self] in
            let nanoseconds = UInt64(max(0.5, delay) * 1_000_000_000)
            try? await Task.sleep(nanoseconds: nanoseconds)
            if Task.isCancelled { return }
            await self?.syncNow()
        }
    }

    // MARK: - 主流程

    func syncNow() async {
        guard isEnabled else { return }
        guard !DeviceConfig.shared.token.isEmpty else {
            await updateStatus("未登录，无法同步")
            return
        }
        guard !DeviceConfig.shared.coupleId.isEmpty else {
            await updateStatus("还没有绑定情侣，暂不同步")
            return
        }
        guard !isSyncing else { return }
        await setSyncing(true)
        do {
            try await push()
            try await pull()
            let now = DateUtils.ms()
            await MainActor.run {
                self.lastSyncAt = now
                self.statusText = "已同步 " + DateUtils.relative(now)
                self.lastError = ""
                LocalStore.shared.update { state in
                    state.settings.lastSyncAt = now
                }
            }
        } catch let error as APIError {
            await MainActor.run {
                self.lastError = error.message
                self.statusText = "同步失败：" + error.message
            }
        } catch {
            await MainActor.run {
                self.lastError = "同步失败"
                self.statusText = "同步失败"
            }
        }
        await setSyncing(false)
    }

    private func setSyncing(_ value: Bool) async {
        await MainActor.run { self.isSyncing = value }
    }

    private func updateStatus(_ text: String) async {
        await MainActor.run { self.statusText = text }
    }

    // MARK: - 上行

    private func push() async throws {
        let objects = localSyncObjects()
        guard !objects.isEmpty else { return }
        var index = 0
        while index < objects.count {
            let end = min(index + maxBatch, objects.count)
            let chunk = Array(objects[index..<end])
            _ = try await APIClient.shared.pushSync(objects: chunk)
            index = end
        }
    }

    /// 把本地数据转成同步对象数组
    func localSyncObjects() -> [[String: Any]] {
        let state = LocalStore.shared.state
        var result: [[String: Any]] = []

        result.append(syncObject(kind: SyncKind.spark, id: state.spark.id, updatedAt: state.spark.updatedAt, deleted: state.spark.isDeleted, payload: state.spark.jsonObject))
        result.append(syncObject(kind: SyncKind.pet, id: state.pet.id, updatedAt: state.pet.updatedAt, deleted: state.pet.isDeleted, payload: state.pet.jsonObject))
        result.append(syncObject(kind: SyncKind.settings, id: state.settings.id, updatedAt: state.settings.updatedAt, deleted: state.settings.isDeleted, payload: state.settings.jsonObject))

        for item in state.checkIns where !item.isDeleted {
            result.append(syncObject(kind: SyncKind.checkin, id: item.id, updatedAt: item.updatedAt, deleted: false, payload: item.jsonObject))
        }
        for item in state.wishes {
            result.append(syncObject(kind: SyncKind.wish, id: item.id, updatedAt: item.updatedAt, deleted: item.isDeleted, payload: item.jsonObject))
        }
        for item in state.workLogs {
            result.append(syncObject(kind: SyncKind.worklog, id: item.id, updatedAt: item.updatedAt, deleted: item.isDeleted, payload: item.jsonObject))
        }

        // 过滤掉从未改动过的空对象
        return result.filter { object in
            let updatedAt = JSONRead.double(object, "updatedAt")
            let deleted = JSONRead.bool(object, "deleted")
            return updatedAt > 0 || deleted
        }
    }

    private func syncObject(kind: String, id: String, updatedAt: Double, deleted: Bool, payload: [String: Any]) -> [String: Any] {
        return [
            "kind": kind,
            "id": id,
            "updatedAt": updatedAt,
            "deleted": deleted,
            "payload": payload
        ]
    }

    // MARK: - 下行

    /// 拉取远端数据。
    ///
    /// 这里刻意采用「每次全量拉取 + LWW 合并」而不是增量游标：
    /// 情侣数据量很小（通常几百个对象），全量拉取最稳妥 ——
    /// 不会因为手机与服务端时钟不一致或删除墓碑而漏数据。
    private func pull() async throws {
        let result = try await APIClient.shared.pullSync(since: 0, kinds: SyncKind.all, limit: 1000)

        if !result.objects.isEmpty {
            await MainActor.run {
                for object in result.objects {
                    self.apply(object)
                }
            }
        }

        let serverTime = result.serverTime > 0 ? result.serverTime : DateUtils.ms()
        await MainActor.run {
            LocalStore.shared.update { state in
                state.syncStamp = serverTime
            }
        }

        if result.hasMore {
            print("[Sync] 服务端单页对象超过 1000 个，可能有少量数据未拉取，请检查数据量")
        }
    }

    /// 应用一条远端对象（LWW：只有更新的版本才覆盖本地）
    func apply(_ object: [String: Any]) {
        let kind = JSONRead.string(object, "kind")
        let updatedAt = JSONRead.double(object, "updatedAt")
        let deleted = JSONRead.bool(object, "deleted")
        guard let payload = JSONRead.dict(object["payload"]) else { return }

        switch kind {
        case SyncKind.spark:
            guard let remote = SparkState(json: payload) else { return }
            LocalStore.shared.update { state in
                guard updatedAt > state.spark.updatedAt else { return }
                var merged = remote
                merged.isDeleted = deleted
                state.spark = merged
            }

        case SyncKind.pet:
            guard let remote = PetState(json: payload) else { return }
            LocalStore.shared.update { state in
                guard updatedAt > state.pet.updatedAt else { return }
                var merged = remote
                merged.isDeleted = deleted
                // 自定义形象是本地文件，不能被远端覆盖清空
                if merged.customImage.isEmpty { merged.customImage = state.pet.customImage }
                if merged.species == "custom" && merged.customImage.isEmpty {
                    merged.species = state.pet.species
                }
                state.pet = merged
            }

        case SyncKind.settings:
            guard let remote = UserSettings(json: payload) else { return }
            LocalStore.shared.update { state in
                guard updatedAt > state.settings.updatedAt else { return }
                state.settings = remote
            }

        case SyncKind.checkin:
            guard let remote = CheckInRecord(json: payload) else { return }
            LocalStore.shared.update { state in
                if let index = state.checkIns.firstIndex(where: { $0.id == remote.id }) {
                    guard updatedAt > state.checkIns[index].updatedAt else { return }
                    state.checkIns[index] = remote
                } else if !deleted {
                    state.checkIns.append(remote)
                }
            }

        case SyncKind.wish:
            guard let remote = Wish(json: payload) else { return }
            LocalStore.shared.update { state in
                if let index = state.wishes.firstIndex(where: { $0.id == remote.id }) {
                    guard updatedAt > state.wishes[index].updatedAt else { return }
                    state.wishes[index] = remote
                } else if !deleted {
                    state.wishes.append(remote)
                }
            }

        case SyncKind.worklog:
            guard let remote = WorkLog(json: payload) else { return }
            LocalStore.shared.update { state in
                if let index = state.workLogs.firstIndex(where: { $0.id == remote.id }) {
                    guard updatedAt > state.workLogs[index].updatedAt else { return }
                    state.workLogs[index] = remote
                } else if !deleted {
                    state.workLogs.append(remote)
                }
            }

        default:
            // 记账 / 密码本等本地数据，或未知类型，直接忽略
            break
        }
    }

    /// 清空同步进度，下次全量拉取
    func resetSyncCursor() {
        LocalStore.shared.update { state in
            state.syncStamp = 0
        }
    }
}
