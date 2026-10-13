import Foundation
import Combine
import CryptoKit

/// 本地数据仓库。
/// - 记账（ledger）与密码本（vault）**只存在本机**，永不参与同步
/// - 其余数据通过 SyncEngine 与伴侣同步
final class LocalStore: ObservableObject {
    static let shared = LocalStore()

    @Published var state: PersistedState
    @Published var vaultItems: [VaultItem] = []
    @Published var vaultUnlocked: Bool = false

    let fileURL: URL
    let vaultURL: URL
    let emojiCacheDir: URL
    let imageDir: URL

    private let io = DispatchQueue(label: "com.coupleapp.localstore", qos: .utility)
    private var pendingSave: DispatchWorkItem?
    private var vaultKey: SymmetricKey?

    private init() {
        let fm = FileManager.default
        let base = AppInfo.dataDirectory
        let stateFile = base.appendingPathComponent("state.json")
        let vaultFile = base.appendingPathComponent("vault.enc")
        let emojiDir = base.appendingPathComponent("EmojiCache", isDirectory: true)
        let imgDir = base.appendingPathComponent("Images", isDirectory: true)

        try? fm.createDirectory(at: emojiDir, withIntermediateDirectories: true)
        try? fm.createDirectory(at: imgDir, withIntermediateDirectories: true)

        self.fileURL = stateFile
        self.vaultURL = vaultFile
        self.emojiCacheDir = emojiDir
        self.imageDir = imgDir

        var loaded = PersistedState()
        if let data = try? Data(contentsOf: stateFile) {
            loaded = PersistedState.decode(from: data)
        } else {
            loaded.pet.lastDecayAt = DateUtils.ms()
        }
        self.state = loaded
    }

    // MARK: - 读写

    func update(_ mutate: (inout PersistedState) -> Void) {
        mutate(&state)
        scheduleSave()
    }

    private func scheduleSave() {
        pendingSave?.cancel()
        let snapshot = state
        let url = fileURL
        let item = DispatchWorkItem {
            if let data = try? JSONEncoder().encode(snapshot) {
                do {
                    try data.write(to: url, options: .atomic)
                } catch {
                    print("[LocalStore] 保存失败: \(error)")
                }
            }
        }
        pendingSave = item
        io.asyncAfter(deadline: .now() + 0.4, execute: item)
    }

    /// 立即落盘（退到后台时调用）
    func saveNow() {
        pendingSave?.cancel()
        pendingSave = nil
        let snapshot = state
        let url = fileURL
        io.sync {
            if let data = try? JSONEncoder().encode(snapshot) {
                try? data.write(to: url, options: .atomic)
            }
        }
    }

    // MARK: - 火花系统

    /// 每日火花值上限
    static let dailySparkCap = 40

    /// 记录一次互动，返回实际获得的火花值
    @discardableResult
    func addSpark(points: Int, reason: String = "") -> Int {
        var gained = 0
        let now = DateUtils.ms()
        let today = DateUtils.dateKey()
        let yesterday = DateUtils.dateKey(DateUtils.addDays(-1))

        update { s in
            let todayPoints = s.spark.history[today] ?? 0
            let allowed = max(0, LocalStore.dailySparkCap - todayPoints)
            gained = min(max(0, points), allowed)

            s.spark.points += gained
            s.spark.totalPoints += gained
            s.spark.history[today] = todayPoints + gained
            s.spark.lastInteractionAt = now
            s.spark.updatedAt = now

            if s.spark.lastActiveDate != today {
                if s.spark.lastActiveDate == yesterday {
                    s.spark.days += 1
                } else {
                    s.spark.days = 1
                }
                s.spark.lastActiveDate = today
                s.spark.longestDays = max(s.spark.longestDays, s.spark.days)
            }
        }
        return gained
    }

    /// 把连续天数清零（断签后手动重置）
    func resetSparkStreak() {
        update { s in
            s.spark.days = 0
            s.spark.updatedAt = DateUtils.ms()
        }
    }

    // MARK: - 宠物系统

    /// 宠物形象可选列表

    func applyPetDecayIfNeeded() {
        let now = DateUtils.ms()
        update { s in
            let last = s.pet.lastDecayAt > 0 ? s.pet.lastDecayAt : now
            s.pet.lastDecayAt = now
            let hours = (now - last) / 3_600_000.0
            guard hours >= 2 else { return }
            let steps = Int(hours / 2.0)
            guard steps > 0 else { return }
            s.pet.hunger = max(0, s.pet.hunger - steps)
            s.pet.mood = max(0, s.pet.mood - steps)
            s.pet.energy = min(100, s.pet.energy + steps)
            s.pet.updatedAt = now
        }
    }

    enum PetActionResult {
        case success(String)
        case noCoins
        case full(String)
        case fail(String)
    }

    func feedPet(cost: Int = 10) -> PetActionResult {
        if state.pet.coins < cost { return .noCoins }
        if state.pet.hunger >= 100 { return .full("\(state.pet.name)已经吃得饱饱的啦") }
        var message = ""
        update { s in
            s.pet.coins -= cost
            s.pet.hunger = min(100, s.pet.hunger + 25)
            s.pet.mood = min(100, s.pet.mood + 6)
            s.pet.exp += 6
            s.pet.lastFedAt = DateUtils.ms()
            s.pet.updatedAt = DateUtils.ms()
            message = "\(s.pet.name)吃得超开心，好感 +6"
        }
        addSpark(points: 3, reason: "喂宠物")
        return .success(message)
    }

    func playWithPet() -> PetActionResult {
        if state.pet.energy < 15 { return .fail("\(state.pet.name)太累了，先让它睡一会儿") }
        var message = ""
        var coinGain = 0
        update { s in
            s.pet.mood = min(100, s.pet.mood + 20)
            s.pet.energy = max(0, s.pet.energy - 12)
            s.pet.hunger = max(0, s.pet.hunger - 5)
            s.pet.exp += 12
            coinGain = Int.random(in: 1...3)
            s.pet.coins += coinGain
            s.pet.lastPlayedAt = DateUtils.ms()
            s.pet.updatedAt = DateUtils.ms()
            message = "和\(s.pet.name)玩了一会儿，捡到 \(coinGain) 金币"
        }
        addSpark(points: 3, reason: "陪宠物玩")
        return .success(message)
    }

    func sleepPet() -> PetActionResult {
        var message = ""
        update { s in
            s.pet.energy = min(100, s.pet.energy + 35)
            s.pet.mood = min(100, s.pet.mood + 5)
            s.pet.exp += 3
            s.pet.lastSleptAt = DateUtils.ms()
            s.pet.updatedAt = DateUtils.ms()
            message = "\(s.pet.name)睡了一觉，精力恢复了"
        }
        return .success(message)
    }

    func buyPetSpecies(_ key: String, price: Int) -> PetActionResult {
        if state.pet.coins < price { return .noCoins }
        var message = ""
        update { s in
            s.pet.coins -= price
            s.pet.species = key
            if !s.pet.unlocked.contains(key) { s.pet.unlocked.append(key) }
            s.pet.updatedAt = DateUtils.ms()
            message = "已换成新伙伴！"
        }
        return .success(message)
    }

    func renamePet(_ name: String) {
        let trimmed = name.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !trimmed.isEmpty else { return }
        update { s in
            s.pet.name = String(trimmed.prefix(12))
            s.pet.updatedAt = DateUtils.ms()
        }
    }

    func addCoins(_ amount: Int) {
        update { s in
            s.pet.coins = max(0, s.pet.coins + amount)
            s.pet.updatedAt = DateUtils.ms()
        }
    }

    /// 保存自定义宠物照片，返回文件名
    func saveCustomPetImage(_ data: Data) -> String? {
        let name = "pet_\(Int(DateUtils.ms())).jpg"
        let url = imageDir.appendingPathComponent(name)
        do {
            try data.write(to: url, options: .atomic)
            update { s in
                s.pet.customImage = name
                s.pet.species = "custom"
                s.pet.updatedAt = DateUtils.ms()
            }
            return name
        } catch {
            return nil
        }
    }

    func customPetImageURL() -> URL? {
        let name = state.pet.customImage
        guard !name.isEmpty else { return nil }
        let url = imageDir.appendingPathComponent(name)
        return FileManager.default.fileExists(atPath: url.path) ? url : nil
    }

    // MARK: - 签到系统

    func hasCheckedIn(userId: String, on dateKey: String) -> Bool {
        state.checkIns.contains { !$0.isDeleted && $0.userId == userId && $0.dateKey == dateKey }
    }

    /// 连续签到天数（从今天或昨天往前数）
    func checkInStreak(userId: String) -> Int {
        let keys = Set(state.checkIns.filter { !$0.isDeleted && $0.userId == userId }.map { $0.dateKey })
        guard !keys.isEmpty else { return 0 }
        var streak = 0
        var cursor = Date()
        if !keys.contains(DateUtils.dateKey(cursor)) {
            cursor = DateUtils.addDays(-1, to: cursor)
        }
        while keys.contains(DateUtils.dateKey(cursor)) {
            streak += 1
            cursor = DateUtils.addDays(-1, to: cursor)
        }
        return streak
    }

    func checkIn(userId: String, note: String, mood: String, isMakeup: Bool) -> CheckInRecord? {
        let key = DateUtils.dateKey()
        if hasCheckedIn(userId: userId, on: key) { return nil }
        let streak = checkInStreak(userId: userId) + 1
        let coins = 20 + min(30, streak * 2)
        let points = 10 + min(20, streak)
        let record = CheckInRecord(
            userId: userId,
            dateKey: key,
            note: note,
            mood: mood,
            rewardCoins: coins,
            rewardPoints: points,
            isMakeup: isMakeup
        )
        update { s in
            s.checkIns.append(record)
            s.pet.coins += coins
            s.pet.updatedAt = DateUtils.ms()
        }
        addSpark(points: points, reason: "签到")
        return record
    }

    /// 补签（消耗金币）
    func makeupCheckIn(userId: String, dateKey: String, cost: Int = 50) -> PetActionResult {
        if hasCheckedIn(userId: userId, on: dateKey) { return .fail("那天已经签过啦") }
        if state.pet.coins < cost { return .noCoins }
        var record: CheckInRecord?
        update { s in
            s.pet.coins -= cost
            let item = CheckInRecord(
                userId: userId,
                dateKey: dateKey,
                note: "补签",
                mood: "🩹",
                rewardCoins: 0,
                rewardPoints: 5,
                isMakeup: true
            )
            s.checkIns.append(item)
            s.pet.updatedAt = DateUtils.ms()
            record = item
        }
        if record == nil { return .fail("补签失败") }
        return .success("补签成功，花了 \(cost) 金币")
    }

    func checkIns(userId: String, inMonth month: String) -> [CheckInRecord] {
        state.checkIns
            .filter { !$0.isDeleted && $0.userId == userId && DateUtils.monthKey(fromDateKey: $0.dateKey) == month }
            .sorted { $0.dateKey < $1.dateKey }
    }

    // MARK: - 愿望系统

    @discardableResult
    func addWish(ownerId: String, title: String, detail: String, category: String, targetCoins: Int, isSurprise: Bool) -> Wish {
        let wish = Wish(ownerId: ownerId, title: title, detail: detail, category: category, targetCoins: targetCoins, isSurprise: isSurprise)
        update { s in
            s.wishes.append(wish)
        }
        addSpark(points: 5, reason: "许下愿望")
        return wish
    }

    func contributeToWish(_ wishId: String, coins: Int) -> PetActionResult {
        guard let index = state.wishes.firstIndex(where: { $0.id == wishId }) else { return .fail("愿望不存在") }
        if state.wishes[index].isDone { return .fail("这个愿望已经实现啦") }
        if state.pet.coins < coins { return .noCoins }
        var message = ""
        update { s in
            guard let idx = s.wishes.firstIndex(where: { $0.id == wishId }) else { return }
            s.pet.coins -= coins
            s.wishes[idx].savedCoins += coins
            s.wishes[idx].updatedAt = DateUtils.ms()
            s.pet.updatedAt = DateUtils.ms()
            message = "已存入 \(coins) 金币"
        }
        addSpark(points: 2, reason: "为愿望存钱")
        return .success(message)
    }

    func fulfillWish(_ wishId: String) {
        update { s in
            guard let idx = s.wishes.firstIndex(where: { $0.id == wishId }) else { return }
            s.wishes[idx].status = "done"
            s.wishes[idx].fulfillAt = DateUtils.ms()
            s.wishes[idx].updatedAt = DateUtils.ms()
        }
        addSpark(points: 10, reason: "实现愿望")
    }

    func deleteWish(_ wishId: String) {
        update { s in
            guard let idx = s.wishes.firstIndex(where: { $0.id == wishId }) else { return }
            s.wishes[idx].isDeleted = true
            s.wishes[idx].updatedAt = DateUtils.ms()
        }
    }

    // MARK: - 记账（本地专用）

    func addLedgerEntry(_ entry: LedgerEntry) {
        update { s in
            s.ledger.append(entry)
        }
        addSpark(points: 1, reason: "记一笔账")
    }

    func addLedgerEntries(_ entries: [LedgerEntry]) {
        guard !entries.isEmpty else { return }
        update { s in
            s.ledger.append(contentsOf: entries)
        }
        addSpark(points: min(5, entries.count), reason: "AI 记账")
    }

    func deleteLedgerEntry(_ id: String) {
        update { s in
            guard let idx = s.ledger.firstIndex(where: { $0.id == id }) else { return }
            s.ledger.remove(at: idx)
        }
    }

    func updateLedgerEntry(_ entry: LedgerEntry) {
        update { s in
            guard let idx = s.ledger.firstIndex(where: { $0.id == entry.id }) else { return }
            s.ledger[idx] = entry
        }
    }

    func ledgerEntries(month: String) -> [LedgerEntry] {
        state.ledger
            .filter { !$0.isDeleted && DateUtils.monthKey(fromDateKey: $0.date) == month }
            .sorted { $0.at > $1.at }
    }

    func ledgerEntries(dateKey: String) -> [LedgerEntry] {
        state.ledger
            .filter { !$0.isDeleted && $0.date == dateKey }
            .sorted { $0.at > $1.at }
    }

    func ledgerSummary(month: String) -> LedgerSummary {
        let items = ledgerEntries(month: month)
        var summary = LedgerSummary()
        summary.month = month
        summary.count = items.count
        var map: [String: Double] = [:]
        for item in items {
            if item.isExpense {
                summary.expense += item.amount
                map[item.category, default: 0] += item.amount
            } else {
                summary.income += item.amount
            }
        }
        summary.byCategory = map
            .map { CategoryAmount(category: $0.key, amount: $0.value) }
            .sorted { $0.amount > $1.amount }
        return summary
    }

    /// 最近 N 天的支出，用于柱状图
    func dailyExpense(days: Int = 7) -> [(key: String, amount: Double)] {
        var result: [(key: String, amount: Double)] = []
        for offset in stride(from: days - 1, through: 0, by: -1) {
            let date = DateUtils.addDays(-offset)
            let key = DateUtils.dateKey(date)
            let total = state.ledger
                .filter { !$0.isDeleted && $0.isExpense && $0.date == key }
                .reduce(0.0) { $0 + $1.amount }
            result.append((key: key, amount: total))
        }
        return result
    }

    // MARK: - 加班 / 工资

    func upsertWorkLog(_ log: WorkLog) {
        update { s in
            var item = log
            item.updatedAt = DateUtils.ms()
            if let idx = s.workLogs.firstIndex(where: { $0.id == item.id }) {
                s.workLogs[idx] = item
            } else {
                s.workLogs.append(item)
            }
        }
    }

    func deleteWorkLog(_ id: String) {
        update { s in
            guard let idx = s.workLogs.firstIndex(where: { $0.id == id }) else { return }
            s.workLogs[idx].isDeleted = true
            s.workLogs[idx].updatedAt = DateUtils.ms()
        }
    }

    func workLogs(month: String) -> [WorkLog] {
        state.workLogs
            .filter { !$0.isDeleted && DateUtils.monthKey(fromDateKey: $0.dateKey) == month }
            .sorted { $0.dateKey > $1.dateKey }
    }

    func workLog(ownerId: String, dateKey: String) -> WorkLog? {
        state.workLogs.first { !$0.isDeleted && $0.ownerId == ownerId && $0.dateKey == dateKey }
    }

    struct SalarySummary: Equatable {
        var workDays: Int = 0
        var totalHours: Double = 0
        var overtimeHours: Double = 0
        var basePay: Double = 0
        var overtimePay: Double = 0
        var leaveDays: Int = 0

        var total: Double { basePay + overtimePay }
    }

    func salarySummary(ownerId: String, month: String) -> SalarySummary {
        var result = SalarySummary()
        for log in state.workLogs where !log.isDeleted && log.ownerId == ownerId && DateUtils.monthKey(fromDateKey: log.dateKey) == month {
            if log.isLeave {
                result.leaveDays += 1
                continue
            }
            result.workDays += 1
            result.totalHours += log.workedHours
            result.overtimeHours += log.overtimeHours
            result.basePay += log.basePay
            result.overtimePay += log.overtimePay
        }
        return result
    }

    // MARK: - 聊天

    func upsertMessages(_ list: [ChatMessage]) {
        guard !list.isEmpty else { return }
        update { s in
            var dict: [String: ChatMessage] = [:]
            for message in s.messages { dict[message.id] = message }
            for message in list {
                if let existing = dict[message.id] {
                    var merged = existing
                    merged.readAt = max(existing.readAt, message.readAt)
                    if message.createdAt > 0 { merged.createdAt = message.createdAt }
                    merged.pending = false
                    merged.failed = false
                    dict[message.id] = merged
                } else {
                    dict[message.id] = message
                }
            }
            var all = Array(dict.values).sorted { $0.createdAt < $1.createdAt }
            if all.count > 1000 { all.removeFirst(all.count - 1000) }
            s.messages = all
            s.lastReadAt = max(s.lastReadAt, DateUtils.ms())
        }
    }

    /// 本地先插入一条待发送消息
    func appendPendingMessage(_ message: ChatMessage) {
        update { s in
            s.messages.append(message)
            if s.messages.count > 1000 { s.messages.removeFirst(s.messages.count - 1000) }
        }
    }

    /// 服务端确认后替换本地临时消息
    func confirmPendingMessage(clientId: String, serverMessage: ChatMessage) {
        update { s in
            s.messages.removeAll { $0.clientId == clientId && $0.pending }
            if let idx = s.messages.firstIndex(where: { $0.id == serverMessage.id }) {
                s.messages[idx] = serverMessage
            } else {
                s.messages.append(serverMessage)
            }
            s.messages.sort { $0.createdAt < $1.createdAt }
        }
    }

    func markPendingFailed(clientId: String) {
        update { s in
            guard let idx = s.messages.firstIndex(where: { $0.clientId == clientId && $0.pending }) else { return }
            s.messages[idx].pending = false
            s.messages[idx].failed = true
        }
    }

    func removeMessage(id: String) {
        update { s in
            s.messages.removeAll { $0.id == id }
        }
    }

    func markRead(upTo: Double) {
        update { s in
            for idx in s.messages.indices where s.messages[idx].createdAt <= upTo {
                if s.messages[idx].readAt == 0 { s.messages[idx].readAt = upTo }
            }
            s.lastReadAt = max(s.lastReadAt, upTo)
        }
    }

    func markPartnerMessagesRead(upTo: Double, partnerId: String) {
        update { s in
            for idx in s.messages.indices {
                if s.messages[idx].senderId == partnerId && s.messages[idx].createdAt <= upTo && s.messages[idx].readAt == 0 {
                    s.messages[idx].readAt = upTo
                }
            }
        }
    }

    var messages: [ChatMessage] { state.messages }

    func unreadCount(partnerId: String, myId: String) -> Int {
        state.messages.filter { $0.senderId != myId && $0.readAt == 0 && !$0.isSystem }.count
    }

    // MARK: - 表情包

    func replacePacks(_ packs: [EmojiPack]) {
        update { s in
            s.emojiPacks = packs
        }
    }

    func localEmojiFile(id: String, ext: String) -> URL {
        emojiCacheDir.appendingPathComponent("\(id).\(ext)")
    }

    func cachedEmojiURL(id: String) -> URL? {
        let fm = FileManager.default
        let extensions = ["jpg", "jpeg", "png", "gif", "webp"]
        for ext in extensions {
            let url = emojiCacheDir.appendingPathComponent("\(id).\(ext)")
            if fm.fileExists(atPath: url.path) { return url }
        }
        return nil
    }

    @discardableResult
    func storeEmojiFile(id: String, ext: String, data: Data) -> URL? {
        let url = cacheDirURL(id: id, ext: ext)
        do {
            try data.write(to: url, options: .atomic)
            return url
        } catch {
            return nil
        }
    }

    private func cacheDirURL(id: String, ext: String) -> URL {
        emojiCacheDir.appendingPathComponent("\(id).\(ext)")
    }

    // MARK: - 密码本（本地加密）

    var hasVault: Bool { Keychain.getData("vaultSalt") != nil }

    func createVault(password: String) throws {
        let salt = CryptoBox.randomData(16)
        let key = CryptoBox.deriveKey(password: password, salt: salt)
        Keychain.setData(salt, for: "vaultSalt")
        vaultKey = key
        vaultItems = []
        DeviceConfig.shared.hasVault = true
        try persistVault()
        vaultUnlocked = true
    }

    func unlockVault(password: String) throws {
        guard let salt = Keychain.getData("vaultSalt") else {
            try createVault(password: password)
            return
        }
        let key = CryptoBox.deriveKey(password: password, salt: salt)
        if let blob = try? Data(contentsOf: vaultURL), !blob.isEmpty {
            let plain = try CryptoBox.decrypt(blob, key: key)
            guard let object = try? JSONSerialization.jsonObject(with: plain),
                  let dict = object as? [String: Any] else {
                throw CryptoBox.CryptoError.corruptData
            }
            vaultItems = JSONRead.objects(dict, "items") { VaultItem(json: $0) }
        } else {
            vaultItems = []
        }
        vaultKey = key
        vaultUnlocked = true
    }

    func lockVault() {
        vaultKey = nil
        vaultItems = []
        vaultUnlocked = false
    }

    func changeVaultPassword(from old: String, to new: String) throws {
        try unlockVault(password: old)
        let salt = CryptoBox.randomData(16)
        let key = CryptoBox.deriveKey(password: new, salt: salt)
        Keychain.setData(salt, for: "vaultSalt")
        vaultKey = key
        try persistVault()
    }

    func destroyVault() {
        vaultKey = nil
        vaultItems = []
        vaultUnlocked = false
        Keychain.remove("vaultSalt")
        try? FileManager.default.removeItem(at: vaultURL)
        DeviceConfig.shared.hasVault = false
    }

    func persistVault() throws {
        guard let key = vaultKey else { throw CryptoBox.CryptoError.badPassword }
        let payload: [String: Any] = ["items": vaultItems.map { $0.jsonObject }]
        let data = try JSONSerialization.data(withJSONObject: payload)
        let blob = try CryptoBox.encrypt(data, key: key)
        try blob.write(to: vaultURL, options: .atomic)
    }

    func upsertVaultItem(_ item: VaultItem) {
        var value = item
        value.updatedAt = DateUtils.ms()
        if value.createdAt == 0 { value.createdAt = value.updatedAt }
        if let idx = vaultItems.firstIndex(where: { $0.id == value.id }) {
            vaultItems[idx] = value
        } else {
            vaultItems.append(value)
        }
        try? persistVault()
    }

    func deleteVaultItem(_ id: String) {
        vaultItems.removeAll { $0.id == id }
        try? persistVault()
    }

    // MARK: - 设置

    func updateSettings(_ mutate: (inout UserSettings) -> Void) {
        update { s in
            mutate(&s.settings)
            s.settings.updatedAt = DateUtils.ms()
        }
    }

    // MARK: - 清理

    /// 退出登录时调用；记账与密码本默认保留
    func resetAfterLogout(keepLedger: Bool = true, keepVault: Bool = true) {
        update { s in
            s.spark = SparkState()
            s.pet = PetState()
            s.checkIns = []
            s.wishes = []
            s.workLogs = []
            s.messages = []
            s.emojiPacks = []
            s.notes = [:]
            s.syncStamp = 0
            s.lastReadAt = 0
            s.chatDraft = ""
            if !keepLedger { s.ledger = [] }
        }
        if !keepVault { destroyVault() }
        saveNow()
    }

    func wipeEverything() {
        try? FileManager.default.removeItem(at: fileURL)
        try? FileManager.default.removeItem(at: vaultURL)
        try? FileManager.default.removeItem(at: emojiCacheDir)
        try? FileManager.default.removeItem(at: imageDir)
        Keychain.removeAll()
        state = PersistedState()
        vaultItems = []
        vaultUnlocked = false
        vaultKey = nil
        saveNow()
    }

    /// 导出全部本地数据为 JSON 文本（备份用）
    func exportJSON() -> String {
        let payload: [String: Any] = [
            "导出时间": DateUtils.full(DateUtils.ms()),
            "版本": AppInfo.fullVersion,
            "数据": state.jsonObject
        ]
        guard let data = try? JSONSerialization.data(withJSONObject: payload, options: [.prettyPrinted, .sortedKeys]),
              let text = String(data: data, encoding: .utf8) else {
            return "{}"
        }
        return text
    }
}
