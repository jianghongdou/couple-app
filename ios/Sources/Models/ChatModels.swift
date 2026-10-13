import Foundation

// MARK: - 聊天消息

struct ChatMessage: Codable, Identifiable, Equatable {
    var id: String = UUID().uuidString
    var clientId: String = ""
    var coupleId: String = ""
    var senderId: String = ""
    /// text / emoji / image / system
    var kind: String = "text"
    var body: String = ""
    var createdAt: Double = 0
    var readAt: Double = 0
    var pending: Bool = false
    var failed: Bool = false

    init() {}

    init(senderId: String, kind: String, body: String, clientId: String) {
        self.id = "local_" + clientId
        self.clientId = clientId
        self.senderId = senderId
        self.kind = kind
        self.body = body
        self.createdAt = DateUtils.ms()
        self.pending = true
    }

    init?(json: [String: Any]) {
        guard let id = JSONRead.optionalString(json, "id"), !id.isEmpty else { return nil }
        self.id = id
        self.clientId = JSONRead.string(json, "clientId")
        self.coupleId = JSONRead.string(json, "coupleId")
        self.senderId = JSONRead.string(json, "senderId")
        self.kind = JSONRead.string(json, "kind", "text")
        self.body = JSONRead.string(json, "body")
        self.createdAt = JSONRead.double(json, "createdAt")
        self.readAt = JSONRead.double(json, "readAt")
        self.pending = false
        self.failed = false
    }

    var isSystem: Bool { kind == "system" }

    /// 表情消息里的表情 id
    var emojiItemId: String? {
        if kind == "image" || kind == "emoji" {
            if body.hasPrefix("item:") {
                return String(body.dropFirst(5))
            }
        }
        return nil
    }

    /// 表情消息里的 unicode emoji
    var unicodeEmoji: String? {
        if kind == "emoji", body.hasPrefix("unicode:") {
            return String(body.dropFirst(8))
        }
        return nil
    }
}

// MARK: - 表情包

struct EmojiPack: Codable, Identifiable, Equatable {
    var id: String = ""
    var name: String = ""
    var ownerId: String = ""
    var isPublic: Bool = false
    var createdAt: Double = 0
    var updatedAt: Double = 0
    /// 本地内置包（不走服务端）
    var isLocal: Bool = false
    var items: [EmojiItem] = []

    init() {}

    init?(json: [String: Any]) {
        guard let id = JSONRead.optionalString(json, "id"), !id.isEmpty else { return nil }
        self.id = id
        self.name = JSONRead.string(json, "name", "未命名表情")
        self.ownerId = JSONRead.string(json, "ownerId")
        self.isPublic = JSONRead.bool(json, "isPublic")
        self.createdAt = JSONRead.double(json, "createdAt")
        self.updatedAt = JSONRead.double(json, "updatedAt")
        if let arr = json["items"] as? [Any] {
            self.items = arr.compactMap { item in
                guard let d = item as? [String: Any] else { return nil }
                return EmojiItem(json: d)
            }
        }
    }
}

struct EmojiItem: Codable, Identifiable, Equatable {
    var id: String = ""
    var packId: String = ""
    var ownerId: String = ""
    var mime: String = "image/jpeg"
    var size: Int = 0
    var name: String = ""
    /// 服务端相对路径，例如 /api/emoji/blob/e_1
    var url: String = ""
    var createdAt: Double = 0
    var updatedAt: Double = 0

    init() {}

    init?(json: [String: Any]) {
        guard let id = JSONRead.optionalString(json, "id"), !id.isEmpty else { return nil }
        self.id = id
        self.packId = JSONRead.string(json, "packId")
        self.ownerId = JSONRead.string(json, "ownerId")
        self.mime = JSONRead.string(json, "mime", "image/jpeg")
        self.size = JSONRead.int(json, "size")
        self.name = JSONRead.string(json, "name")
        self.url = JSONRead.string(json, "url")
        self.createdAt = JSONRead.double(json, "createdAt")
        self.updatedAt = JSONRead.double(json, "updatedAt")
    }

    var fileExtension: String {
        switch mime {
        case "image/png": return "png"
        case "image/gif": return "gif"
        case "image/webp": return "webp"
        default: return "jpg"
        }
    }

    var messageBody: String { "item:\(id)" }
}
