import Foundation

/// 登录 / 注册 / 拉取个人信息的返回
struct AuthResult {
    var token: String = ""
    var user: UserProfile?
    var couple: CoupleInfo?
}

/// 具体接口。全部对应 docs/API.md
extension APIClient {

    // MARK: - 元信息

    func fetchMeta() async throws -> ServerMeta {
        let dict = try await send("/api/meta", authorized: false)
        return ServerMeta(json: dict) ?? ServerMeta()
    }

    // MARK: - 账号

    func register(username: String, password: String, nickname: String, avatarEmoji: String, inviteCode: String) async throws -> AuthResult {
        var body: [String: Any] = [
            "username": username,
            "password": password,
            "nickname": nickname.isEmpty ? username : nickname,
            "avatarEmoji": avatarEmoji
        ]
        if !inviteCode.isEmpty { body["inviteCode"] = inviteCode }
        let dict = try await send("/api/auth/register", method: "POST", body: body, authorized: false)
        return APIClient.authResult(from: dict)
    }

    func login(username: String, password: String) async throws -> AuthResult {
        let dict = try await send(
            "/api/auth/login",
            method: "POST",
            body: ["username": username, "password": password],
            authorized: false
        )
        return APIClient.authResult(from: dict)
    }

    func fetchMe() async throws -> AuthResult {
        let dict = try await send("/api/auth/me")
        return APIClient.authResult(from: dict)
    }

    func updateProfile(nickname: String?, avatarEmoji: String?) async throws -> UserProfile? {
        var body: [String: Any] = [:]
        if let nickname = nickname { body["nickname"] = nickname }
        if let avatarEmoji = avatarEmoji { body["avatarEmoji"] = avatarEmoji }
        let dict = try await send("/api/auth/me", method: "PATCH", body: body)
        return UserProfile(json: JSONRead.dict(dict["user"]) ?? [:])
    }

    func changePassword(old: String, new: String) async throws {
        _ = try await send("/api/auth/password", method: "POST", body: ["oldPassword": old, "newPassword": new])
    }

    private static func authResult(from dict: [String: Any]) -> AuthResult {
        var result = AuthResult()
        result.token = JSONRead.string(dict, "token")
        if let userDict = JSONRead.dict(dict["user"]) {
            result.user = UserProfile(json: userDict)
        }
        if let coupleDict = JSONRead.dict(dict["couple"]) {
            result.couple = CoupleInfo(json: coupleDict)
        }
        return result
    }

    // MARK: - 情侣关系

    func createCouple() async throws -> CoupleInfo? {
        let dict = try await send("/api/couple/create", method: "POST", body: [:])
        return CoupleInfo(json: JSONRead.dict(dict["couple"]) ?? [:])
    }

    func joinCouple(inviteCode: String) async throws -> CoupleInfo? {
        let dict = try await send("/api/couple/join", method: "POST", body: ["inviteCode": inviteCode])
        return CoupleInfo(json: JSONRead.dict(dict["couple"]) ?? [:])
    }

    func fetchCouple() async throws -> CoupleInfo? {
        let dict = try await send("/api/couple")
        return CoupleInfo(json: JSONRead.dict(dict["couple"]) ?? [:])
    }

    func updateCouple(togetherDate: Double?, anniversaryTitle: String?) async throws -> CoupleInfo? {
        var body: [String: Any] = [:]
        if let togetherDate = togetherDate { body["togetherDate"] = togetherDate }
        if let title = anniversaryTitle { body["anniversaryTitle"] = title }
        let dict = try await send("/api/couple", method: "PATCH", body: body)
        return CoupleInfo(json: JSONRead.dict(dict["couple"]) ?? [:])
    }

    func leaveCouple() async throws {
        _ = try await send("/api/couple/leave", method: "POST", body: [:])
    }

    // MARK: - 同步

    func pushSync(objects: [[String: Any]]) async throws -> [String: Any] {
        try await send("/api/sync/push", method: "POST", body: ["objects": objects])
    }

    func pullSync(since: Double, kinds: [String], limit: Int = 1000) async throws -> (objects: [[String: Any]], serverTime: Double, hasMore: Bool) {
        let dict = try await send("/api/sync/pull", query: [
            "since": String(Int(since)),
            "kinds": kinds.joined(separator: ","),
            "limit": String(limit)
        ])
        let objects = (dict["objects"] as? [Any])?.compactMap { $0 as? [String: Any] } ?? []
        return (objects, JSONRead.double(dict, "serverTime"), JSONRead.bool(dict, "hasMore"))
    }

    // MARK: - 聊天

    func fetchMessages(before: Double = 0, limit: Int = 50) async throws -> (messages: [ChatMessage], hasMore: Bool) {
        var query: [String: String] = ["limit": String(limit)]
        if before > 0 { query["before"] = String(Int(before)) }
        let dict = try await send("/api/chat/messages", query: query)
        let messages = JSONRead.objects(dict, "messages") { ChatMessage(json: $0) }
        return (messages, JSONRead.bool(dict, "hasMore"))
    }

    func postMessage(clientId: String, kind: String, body: String) async throws -> ChatMessage? {
        let dict = try await send("/api/chat/messages", method: "POST", body: [
            "clientId": clientId,
            "kind": kind,
            "body": body
        ])
        guard let messageDict = JSONRead.dict(dict["message"]) else { return nil }
        return ChatMessage(json: messageDict)
    }

    func markMessagesRead(upTo: Double) async throws {
        _ = try await send("/api/chat/read", method: "POST", body: ["upTo": upTo])
    }

    func deleteMessage(id: String) async throws {
        _ = try await send("/api/chat/messages/\(id)", method: "DELETE")
    }

    // MARK: - 表情包

    func fetchEmojiPacks() async throws -> [EmojiPack] {
        let dict = try await send("/api/emoji/packs")
        return JSONRead.objects(dict, "packs") { EmojiPack(json: $0) }
    }

    func createEmojiPack(name: String) async throws -> EmojiPack? {
        let dict = try await send("/api/emoji/packs", method: "POST", body: ["name": name])
        return EmojiPack(json: JSONRead.dict(dict["pack"]) ?? [:])
    }

    func deleteEmojiPack(id: String) async throws {
        _ = try await send("/api/emoji/packs/\(id)", method: "DELETE")
    }

    func uploadEmoji(packId: String, mime: String, name: String, data: Data) async throws -> EmojiItem? {
        let dict = try await send("/api/emoji/items", method: "POST", body: [
            "packId": packId,
            "mime": mime,
            "name": name,
            "dataBase64": data.base64EncodedString()
        ])
        return EmojiItem(json: JSONRead.dict(dict["item"]) ?? [:])
    }

    func deleteEmojiItem(id: String) async throws {
        _ = try await send("/api/emoji/items/\(id)", method: "DELETE")
    }

    // MARK: - AI

    func fetchAIStatus() async throws -> AIStatus {
        let dict = try await send("/api/ai/status")
        return AIStatus(json: dict) ?? AIStatus()
    }

    func parseLedger(text: String, today: String) async throws -> ParsedLedgerResult {
        let dict = try await send("/api/ai/parse-ledger", method: "POST", body: [
            "text": text,
            "currency": "CNY",
            "today": today
        ])
        var result = ParsedLedgerResult()
        result.items = JSONRead.objects(dict, "items") { ParsedLedgerItem(json: $0) }
        result.summary = JSONRead.string(dict, "summary")
        result.total = JSONRead.double(dict, "total")
        result.model = JSONRead.string(dict, "model")
        return result
    }

    func monthlyReport(month: String, summary: LedgerSummary, days: Int) async throws -> String {
        var categories: [[String: Any]] = []
        for item in summary.byCategory {
            categories.append(["category": item.category, "amount": item.amount])
        }
        let dict = try await send("/api/ai/monthly-report", method: "POST", body: [
            "month": month,
            "totals": ["expense": summary.expense, "income": summary.income],
            "byCategory": categories,
            "days": days
        ])
        return JSONRead.string(dict, "text")
    }
}
