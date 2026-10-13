import Foundation

// MARK: - 密码本（只存本地，AES-GCM 加密）

struct VaultItem: Codable, Identifiable, Equatable {
    var id: String = UUID().uuidString
    var title: String = ""
    var username: String = ""
    var password: String = ""
    var url: String = ""
    var note: String = ""
    /// 账号 / 银行卡 / 证件 / 网站 / 其它
    var category: String = "网站"
    var favorite: Bool = false
    var createdAt: Double = 0
    var updatedAt: Double = 0

    init() {}

    init(title: String, username: String, password: String, url: String, note: String, category: String) {
        self.id = "vault_\(Int(DateUtils.ms()))_\(Int.random(in: 100...999))"
        self.title = title
        self.username = username
        self.password = password
        self.url = url
        self.note = note
        self.category = category
        self.createdAt = DateUtils.ms()
        self.updatedAt = self.createdAt
    }

    init?(json: [String: Any]) {
        self.id = JSONRead.string(json, "id", UUID().uuidString)
        self.title = JSONRead.string(json, "title")
        self.username = JSONRead.string(json, "username")
        self.password = JSONRead.string(json, "password")
        self.url = JSONRead.string(json, "url")
        self.note = JSONRead.string(json, "note")
        self.category = JSONRead.string(json, "category", "网站")
        self.favorite = JSONRead.bool(json, "favorite")
        self.createdAt = JSONRead.double(json, "createdAt")
        self.updatedAt = JSONRead.double(json, "updatedAt")
    }

    var categoryEmoji: String {
        switch category {
        case "账号": return "👤"
        case "银行卡": return "💳"
        case "证件": return "🪪"
        case "网站": return "🌐"
        case "WiFi": return "📶"
        default: return "🔐"
        }
    }

    /// 密码强度评分 0...4
    var strength: Int {
        var score = 0
        if password.count >= 8 { score += 1 }
        if password.count >= 12 { score += 1 }
        let hasUpper = password.rangeOfCharacter(from: .uppercaseLetters) != nil
        let hasLower = password.rangeOfCharacter(from: .lowercaseLetters) != nil
        let hasDigit = password.rangeOfCharacter(from: .decimalDigits) != nil
        let hasSymbol = password.rangeOfCharacter(from: CharacterSet.alphanumerics.inverted) != nil
        if hasUpper && hasLower { score += 1 }
        if hasDigit || hasSymbol { score += 1 }
        return min(4, score)
    }

    var strengthText: String {
        switch strength {
        case 0, 1: return "弱"
        case 2: return "一般"
        case 3: return "强"
        default: return "很强"
        }
    }

    static let categories = ["网站", "账号", "银行卡", "证件", "WiFi", "其它"]
}
