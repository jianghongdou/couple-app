import Foundation

/// 落盘的全部本地数据。
/// 编码用 Codable 自动合成；解码走 `init?(json:)` 手工容错，缺字段不会导致整体失败。
struct PersistedState: Codable {
    var spark: SparkState = SparkState()
    var pet: PetState = PetState()
    var checkIns: [CheckInRecord] = []
    var wishes: [Wish] = []
    var ledger: [LedgerEntry] = []
    var workLogs: [WorkLog] = []
    var messages: [ChatMessage] = []
    var emojiPacks: [EmojiPack] = []
    var settings: UserSettings = UserSettings()
    /// 上次成功同步的时间戳（用服务端 serverTime）
    var syncStamp: Double = 0
    /// 聊天已读位置
    var lastReadAt: Double = 0
    /// 未发送成功的草稿
    var chatDraft: String = ""
    /// 心情日记：dateKey -> 文本
    var notes: [String: String] = [:]
    /// 数据格式版本
    var schemaVersion: Int = 1

    init() {}

    init(json: [String: Any]) {
        if let d = JSONRead.dict(json["spark"]) { self.spark = SparkState(json: d) ?? SparkState() }
        if let d = JSONRead.dict(json["pet"]) { self.pet = PetState(json: d) ?? PetState() }
        if let d = JSONRead.dict(json["settings"]) { self.settings = UserSettings(json: d) ?? UserSettings() }
        self.checkIns = JSONRead.objects(json, "checkIns") { CheckInRecord(json: $0) }
        self.wishes = JSONRead.objects(json, "wishes") { Wish(json: $0) }
        self.ledger = JSONRead.objects(json, "ledger") { LedgerEntry(json: $0) }
        self.workLogs = JSONRead.objects(json, "workLogs") { WorkLog(json: $0) }
        self.messages = JSONRead.objects(json, "messages") { ChatMessage(json: $0) }
        self.emojiPacks = JSONRead.objects(json, "emojiPacks") { EmojiPack(json: $0) }
        self.syncStamp = JSONRead.double(json, "syncStamp")
        self.lastReadAt = JSONRead.double(json, "lastReadAt")
        self.chatDraft = JSONRead.string(json, "chatDraft")
        self.notes = [String: String]()
        if let raw = json["notes"] as? [String: Any] {
            for (key, value) in raw {
                if let text = value as? String { self.notes[key] = text }
            }
        }
        self.schemaVersion = JSONRead.int(json, "schemaVersion", 1)
    }

    static func decode(from data: Data) -> PersistedState {
        guard let object = try? JSONSerialization.jsonObject(with: data),
              let dict = object as? [String: Any] else {
            return PersistedState()
        }
        return PersistedState(json: dict)
    }
}
