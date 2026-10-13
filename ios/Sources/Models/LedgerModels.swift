import Foundation

// MARK: - 记账（只存本地，永不上传）

/// 一笔账
struct LedgerEntry: Codable, Identifiable, Equatable {
    var id: String = UUID().uuidString
    var ownerId: String = ""
    var amount: Double = 0
    /// expense / income
    var kind: String = "expense"
    var category: String = "其它"
    var note: String = ""
    /// yyyy-MM-dd
    var date: String = ""
    var at: Double = 0
    /// manual / ai
    var source: String = "manual"
    /// AI 识别的原始文本
    var rawText: String = ""
    /// 是否已同步到（本地）月度统计之外的自定义标签
    var tags: [String] = []
    var isDeleted: Bool = false

    init() {}

    init(ownerId: String, amount: Double, kind: String, category: String, note: String, date: String, source: String, rawText: String) {
        self.id = "ledger_\(Int(DateUtils.ms()))_\(Int.random(in: 100...999))"
        self.ownerId = ownerId
        self.amount = abs(amount)
        self.kind = kind
        self.category = category
        self.note = note
        self.date = date.isEmpty ? DateUtils.dateKey() : date
        self.at = DateUtils.ms()
        self.source = source
        self.rawText = rawText
    }

    init?(json: [String: Any]) {
        self.id = JSONRead.string(json, "id", UUID().uuidString)
        self.ownerId = JSONRead.string(json, "ownerId")
        self.amount = abs(JSONRead.double(json, "amount"))
        self.kind = JSONRead.string(json, "kind", "expense")
        self.category = JSONRead.string(json, "category", "其它")
        self.note = JSONRead.string(json, "note")
        self.date = JSONRead.string(json, "date")
        self.at = JSONRead.double(json, "at")
        self.source = JSONRead.string(json, "source", "manual")
        self.rawText = JSONRead.string(json, "rawText")
        self.tags = JSONRead.stringArray(json, "tags")
        self.isDeleted = JSONRead.bool(json, "isDeleted")
    }

    var isExpense: Bool { kind == "expense" }
    var signedAmount: Double { isExpense ? -amount : amount }

    var categoryEmoji: String {
        switch category {
        case "餐饮": return "🍜"
        case "交通": return "🚕"
        case "购物": return "🛍️"
        case "娱乐": return "🎮"
        case "居住": return "🏠"
        case "医疗": return "💊"
        case "学习": return "📚"
        case "人情": return "🎁"
        case "工资": return "💼"
        case "理财": return "📈"
        case "旅行": return "✈️"
        case "宠物": return "🐾"
        default: return "💳"
        }
    }

    static let expenseCategories = ["餐饮", "交通", "购物", "娱乐", "居住", "医疗", "学习", "人情", "旅行", "宠物", "其它"]
    static let incomeCategories = ["工资", "奖金", "理财", "红包", "兼职", "其它"]
}

/// 分类金额（用于饼图 / 排行）
struct CategoryAmount: Codable, Identifiable, Equatable {
    var category: String = "其它"
    var amount: Double = 0

    var id: String { category }

    var emoji: String {
        switch category {
        case "餐饮": return "🍜"
        case "交通": return "🚕"
        case "购物": return "🛍️"
        case "娱乐": return "🎮"
        case "居住": return "🏠"
        case "医疗": return "💊"
        case "学习": return "📚"
        case "人情": return "🎁"
        case "工资": return "💼"
        case "理财": return "📈"
        case "旅行": return "✈️"
        case "宠物": return "🐾"
        default: return "💳"
        }
    }
}

/// 某月统计结果
struct LedgerSummary: Equatable {
    var month: String = ""
    var expense: Double = 0
    var income: Double = 0
    var count: Int = 0
    var byCategory: [CategoryAmount] = []

    var balance: Double { income - expense }

    var averagePerDay: Double {
        let days = max(1, Calendar.current.component(.day, from: Date()))
        return expense / Double(days)
    }

    var maxCategoryAmount: Double {
        byCategory.map { $0.amount }.max() ?? 0
    }
}

/// AI 解析出来的一条待确认账目
struct ParsedLedgerItem: Codable, Equatable {
    var amount: Double = 0
    var kind: String = "expense"
    var category: String = "其它"
    var note: String = ""
    var date: String = ""

    init() {}

    init?(json: [String: Any]) {
        let amount = JSONRead.double(json, "amount")
        guard amount > 0 else { return nil }
        self.amount = amount
        self.kind = JSONRead.string(json, "kind", "expense")
        self.category = JSONRead.string(json, "category", "其它")
        self.note = JSONRead.string(json, "note")
        self.date = JSONRead.string(json, "date")
    }

    var isExpense: Bool { kind == "expense" }
}

/// AI 解析整体结果
struct ParsedLedgerResult: Equatable {
    var items: [ParsedLedgerItem] = []
    var summary: String = ""
    var total: Double = 0
    var model: String = ""
    var errorText: String = ""

    var isOK: Bool { !items.isEmpty && errorText.isEmpty }
}
