import Foundation

/// 用户资料（来自服务端）
struct UserProfile: Codable, Identifiable, Equatable {
    var id: String = ""
    var username: String = ""
    var nickname: String = ""
    var avatarEmoji: String = "🐻"
    var coupleId: String = ""
    var role: String = "user"
    var disabled: Bool = false
    var createdAt: Double = 0

    init() {}

    init?(json: [String: Any]) {
        guard let id = JSONRead.optionalString(json, "id"), !id.isEmpty else { return nil }
        self.id = id
        self.username = JSONRead.string(json, "username")
        self.nickname = JSONRead.string(json, "nickname")
        self.avatarEmoji = JSONRead.string(json, "avatarEmoji", "🐻")
        self.coupleId = JSONRead.string(json, "coupleId")
        self.role = JSONRead.string(json, "role", "user")
        self.disabled = JSONRead.bool(json, "disabled")
        self.createdAt = JSONRead.double(json, "createdAt")
    }

    var displayName: String {
        nickname.isEmpty ? (username.isEmpty ? "TA" : username) : nickname
    }

    var emoji: String {
        avatarEmoji.isEmpty ? "🐻" : avatarEmoji
    }

    var isAdmin: Bool { role == "admin" }
}

/// 情侣关系（来自服务端）
struct CoupleInfo: Codable, Equatable {
    var id: String = ""
    var inviteCode: String = ""
    var togetherDate: Double = 0
    var anniversaryTitle: String = "在一起"
    var members: [UserProfile] = []

    init() {}

    init?(json: [String: Any]) {
        guard let id = JSONRead.optionalString(json, "id"), !id.isEmpty else { return nil }
        self.id = id
        self.inviteCode = JSONRead.string(json, "inviteCode")
        self.togetherDate = JSONRead.double(json, "togetherDate")
        self.anniversaryTitle = JSONRead.string(json, "anniversaryTitle", "在一起")
        if let arr = json["members"] as? [Any] {
            self.members = arr.compactMap { item in
                guard let d = item as? [String: Any] else { return nil }
                return UserProfile(json: d)
            }
        }
    }

    /// 已绑定（两个人都在）
    var isPaired: Bool { members.count >= 2 }

    func partner(of userId: String) -> UserProfile? {
        members.first { $0.id != userId }
    }

    var togetherDays: Int {
        togetherDate > 0 ? DateUtils.togetherDays(togetherDate) : 0
    }
}

/// 服务端元信息
struct ServerMeta: Codable, Equatable {
    var appName: String = "情侣空间"
    var serverVersion: String = "1.0.0"
    var minAppBuild: Int = 1
    var latestAppBuild: Int = 1
    var updateURL: String = ""
    var announcement: String = ""
    var registerOpen: Bool = true
    var chatEnabled: Bool = true
    var emojiEnabled: Bool = true
    var aiEnabled: Bool = true
    var syncEnabled: Bool = true
    /// AI 聊天总开关（服务端 features.aiChat；老服务端不返回则为 nil = 未知）
    var aiChatEnabled: Bool?
    /// 服务端已启用的 AI 供应商数量（服务端 features.aiProviders；老服务端不返回则为 nil）
    var aiProviderCount: Int?
    /// 服务端是否已配置可用的 AI（服务端顶层 aiConfigured；老服务端不返回则为 nil）
    var aiConfigured: Bool?
    var serverTime: Double = 0

    init() {}

    init?(json: [String: Any]) {
        self.appName = JSONRead.string(json, "appName", "情侣空间")
        self.serverVersion = JSONRead.string(json, "serverVersion", "1.0.0")
        self.minAppBuild = JSONRead.int(json, "minAppBuild", 1)
        self.latestAppBuild = JSONRead.int(json, "latestAppBuild", 1)
        self.updateURL = JSONRead.string(json, "updateURL")
        self.announcement = JSONRead.string(json, "announcement")
        self.registerOpen = JSONRead.bool(json, "registerOpen", true)
        self.serverTime = JSONRead.double(json, "serverTime")
        if let features = JSONRead.dict(json["features"]) {
            self.chatEnabled = JSONRead.bool(features, "chat", true)
            self.emojiEnabled = JSONRead.bool(features, "emoji", true)
            self.aiEnabled = JSONRead.bool(features, "ai", true)
            self.syncEnabled = JSONRead.bool(features, "sync", true)
            // 只有服务端明确返回了才认，避免误判成「功能不可用」
            if let aiChat = features["aiChat"] as? Bool { self.aiChatEnabled = aiChat }
            if features["aiProviders"] != nil { self.aiProviderCount = JSONRead.int(features, "aiProviders") }
        }
        if json["aiConfigured"] != nil { self.aiConfigured = JSONRead.bool(json, "aiConfigured") }
    }

    /// 需要强制更新
    var needsUpdate: Bool { AppInfo.buildNumber < minAppBuild }

    var hasNewVersion: Bool { AppInfo.buildNumber < latestAppBuild }
}

/// AI 服务状态
struct AIStatus: Codable, Equatable {
    var enabled: Bool = false
    var configured: Bool = false
    var model: String = ""
    var dailyLimit: Int = 0
    var usedToday: Int = 0

    init() {}

    init?(json: [String: Any]) {
        self.enabled = JSONRead.bool(json, "enabled")
        self.configured = JSONRead.bool(json, "configured")
        self.model = JSONRead.string(json, "model")
        self.dailyLimit = JSONRead.int(json, "dailyLimit")
        self.usedToday = JSONRead.int(json, "usedToday")
    }

    var available: Bool { enabled && configured }
    var remaining: Int { max(0, dailyLimit - usedToday) }
}
