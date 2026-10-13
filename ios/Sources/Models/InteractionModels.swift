import Foundation

// MARK: - 火花系统

/// 火花（连续互动天数 + 火花值）
struct SparkState: Codable, Equatable {
    var id: String = "spark"
    /// 当前火花值（会被消耗/衰减）
    var points: Int = 0
    /// 历史累计火花值（决定等级，只增不减）
    var totalPoints: Int = 0
    /// 连续互动天数
    var days: Int = 0
    /// 历史最长连续天数
    var longestDays: Int = 0
    /// 最后一次互动时间
    var lastInteractionAt: Double = 0
    /// 最后一次"点亮"的日期
    var lastActiveDate: String = ""
    /// dateKey -> 当日获得火花值
    var history: [String: Int] = [:]
    var updatedAt: Double = 0
    var isDeleted: Bool = false

    init() {}

    init?(json: [String: Any]) {
        self.id = JSONRead.string(json, "id", "spark")
        self.points = JSONRead.int(json, "points")
        self.totalPoints = JSONRead.int(json, "totalPoints")
        self.days = JSONRead.int(json, "days")
        self.longestDays = JSONRead.int(json, "longestDays")
        self.lastInteractionAt = JSONRead.double(json, "lastInteractionAt")
        self.lastActiveDate = JSONRead.string(json, "lastActiveDate")
        self.history = JSONRead.intMap(json, "history")
        self.updatedAt = JSONRead.double(json, "updatedAt")
        self.isDeleted = JSONRead.bool(json, "isDeleted")
    }

    static let levelSteps: [Int] = [0, 60, 180, 400, 800, 1400, 2200, 3200, 4600, 6400, 8800]

    /// 等级 1...11
    var level: Int {
        var result = 1
        for (index, step) in SparkState.levelSteps.enumerated() where totalPoints >= step {
            result = index + 1
        }
        return min(result, SparkState.levelSteps.count)
    }

    var levelTitle: String {
        let titles = ["初识", "心动", "暧昧", "牵手", "热恋", "甜蜜", "默契", "依赖", "深爱", "挚爱", "永恒"]
        return titles[max(0, min(titles.count - 1, level - 1))]
    }

    /// 距离下一级还差多少
    var pointsToNextLevel: Int {
        let index = min(level, SparkState.levelSteps.count - 1)
        guard index < SparkState.levelSteps.count else { return 0 }
        return max(0, SparkState.levelSteps[index] - totalPoints)
    }

    /// 等级进度 0...1
    var levelProgress: Double {
        let current = SparkState.levelSteps[max(0, level - 1)]
        let next = level < SparkState.levelSteps.count ? SparkState.levelSteps[level] : current + 1
        guard next > current else { return 1 }
        return min(1, max(0, Double(totalPoints - current) / Double(next - current)))
    }

    /// 今天是否已经互动点亮
    var isLitToday: Bool { lastActiveDate == DateUtils.dateKey() }

    /// 连续 7 天互动记录（用于火花日历）
    func recentDays(_ count: Int = 7) -> [SparkDay] {
        var result: [SparkDay] = []
        for offset in stride(from: count - 1, through: 0, by: -1) {
            let date = DateUtils.addDays(-offset)
            let key = DateUtils.dateKey(date)
            result.append(SparkDay(key: key, points: history[key] ?? 0))
        }
        return result
    }

    /// 火花里程碑（用于成就展示）
    static let milestones: [(days: Int, title: String, emoji: String)] = [
        (1, "第一天", "✨"),
        (3, "小火苗", "🔥"),
        (7, "一周", "🔥"),
        (30, "一个月", "💫"),
        (100, "百天", "🏆"),
        (365, "一周年", "👑"),
        (1000, "千日", "💎")
    ]
}

/// 火花日历里的一天
struct SparkDay: Identifiable, Equatable {
    var key: String
    var points: Int

    var id: String { key }

    var isLit: Bool { points > 0 }
}

// MARK: - 宠物系统
/// 宠物状态
struct PetState: Codable, Equatable {
    var id: String = "pet"
    var name: String = "小奶糖"
    /// cat / dog / bunny / bear / penguin / fox / panda / custom
    var species: String = "cat"
    /// 自定义形象本地文件名（species == custom 时使用）
    var customImage: String = ""
    var exp: Int = 0
    /// 饱食度 0...100
    var hunger: Int = 70
    /// 心情 0...100
    var mood: Int = 70
    /// 精力 0...100
    var energy: Int = 70
    /// 宠物金币
    var coins: Int = 0
    var lastFedAt: Double = 0
    var lastPlayedAt: Double = 0
    var lastSleptAt: Double = 0
    var lastDecayAt: Double = 0
    /// 已解锁装饰 / 称号
    var unlocked: [String] = []
    var updatedAt: Double = 0
    var isDeleted: Bool = false

    init() {}

    init?(json: [String: Any]) {
        self.id = JSONRead.string(json, "id", "pet")
        self.name = JSONRead.string(json, "name", "小奶糖")
        self.species = JSONRead.string(json, "species", "cat")
        self.customImage = JSONRead.string(json, "customImage")
        self.exp = JSONRead.int(json, "exp")
        self.hunger = JSONRead.int(json, "hunger", 70)
        self.mood = JSONRead.int(json, "mood", 70)
        self.energy = JSONRead.int(json, "energy", 70)
        self.coins = JSONRead.int(json, "coins")
        self.lastFedAt = JSONRead.double(json, "lastFedAt")
        self.lastPlayedAt = JSONRead.double(json, "lastPlayedAt")
        self.lastSleptAt = JSONRead.double(json, "lastSleptAt")
        self.lastDecayAt = JSONRead.double(json, "lastDecayAt")
        self.unlocked = JSONRead.stringArray(json, "unlocked")
        self.updatedAt = JSONRead.double(json, "updatedAt")
        self.isDeleted = JSONRead.bool(json, "isDeleted")
    }

    var level: Int { min(99, exp / 100 + 1) }
    var expInLevel: Int { exp % 100 }
    var levelProgress: Double { Double(expInLevel) / 100.0 }

    var hungerState: String {
        switch hunger {
        case 80...: return "吃得好饱"
        case 50..<80: return "不太饿"
        case 25..<50: return "有点饿"
        case 1..<25: return "饿坏了"
        default: return "要饿晕了"
        }
    }

    var moodState: String {
        switch mood {
        case 80...: return "超级开心"
        case 50..<80: return "心情不错"
        case 25..<50: return "有点低落"
        case 1..<25: return "不开心"
        default: return "想要抱抱"
        }
    }

    var energyState: String {
        switch energy {
        case 80...: return "精力充沛"
        case 50..<80: return "还行"
        case 25..<50: return "有点累"
        case 1..<25: return "很困了"
        default: return "睡着了"
        }
    }

    /// 宠物形象 emoji
    var emoji: String {
        switch species {
        case "dog": return "🐶"
        case "bunny": return "🐰"
        case "bear": return "🐻"
        case "penguin": return "🐧"
        case "fox": return "🦊"
        case "panda": return "🐼"
        case "dino": return "🦖"
        case "unicorn": return "🦄"
        case "custom": return "🐾"
        default: return "🐱"
        }
    }

    /// 宠物性格文案
    var moodLine: String {
        if energy < 20 { return "\(name)困得睁不开眼了…"}
        if hunger < 20 { return "\(name)肚子在咕咕叫啦"}
        if mood < 20 { return "\(name)想要你陪陪它"}
        if mood > 85 { return "\(name)现在超幸福！"}
        if hunger > 85 { return "\(name)吃得圆滚滚的"}
        return "\(name)正在发呆，看起来很想你"
    }

    static let speciesList: [PetSpecies] = [
        PetSpecies(key: "cat", name: "奶猫", emoji: "🐱", price: 0),
        PetSpecies(key: "dog", name: "柴犬", emoji: "🐶", price: 0),
        PetSpecies(key: "bunny", name: "兔子", emoji: "🐰", price: 200),
        PetSpecies(key: "bear", name: "小熊", emoji: "🐻", price: 300),
        PetSpecies(key: "penguin", name: "企鹅", emoji: "🐧", price: 400),
        PetSpecies(key: "fox", name: "狐狸", emoji: "🦊", price: 500),
        PetSpecies(key: "panda", name: "熊猫", emoji: "🐼", price: 600),
        PetSpecies(key: "dino", name: "小恐龙", emoji: "🦖", price: 800),
        PetSpecies(key: "unicorn", name: "独角兽", emoji: "🦄", price: 1200)
    ]
}

/// 宠物形象（商店里的一种）
struct PetSpecies: Identifiable, Equatable {
    var key: String
    var name: String
    var emoji: String
    var price: Int

    var id: String { key }
}

// MARK: - 签到

struct CheckInRecord: Codable, Identifiable, Equatable {
    var id: String = UUID().uuidString
    var userId: String = ""
    /// yyyy-MM-dd
    var dateKey: String = ""
    var at: Double = 0
    var note: String = ""
    var mood: String = ""
    var rewardCoins: Int = 0
    var rewardPoints: Int = 0
    var isMakeup: Bool = false
    var updatedAt: Double = 0
    var isDeleted: Bool = false

    init() {}

    init(userId: String, dateKey: String, note: String, mood: String, rewardCoins: Int, rewardPoints: Int, isMakeup: Bool) {
        self.id = "\(userId)_\(dateKey)"
        self.userId = userId
        self.dateKey = dateKey
        self.at = DateUtils.ms()
        self.note = note
        self.mood = mood
        self.rewardCoins = rewardCoins
        self.rewardPoints = rewardPoints
        self.isMakeup = isMakeup
        self.updatedAt = DateUtils.ms()
    }

    init?(json: [String: Any]) {
        self.id = JSONRead.string(json, "id", UUID().uuidString)
        self.userId = JSONRead.string(json, "userId")
        self.dateKey = JSONRead.string(json, "dateKey")
        self.at = JSONRead.double(json, "at")
        self.note = JSONRead.string(json, "note")
        self.mood = JSONRead.string(json, "mood")
        self.rewardCoins = JSONRead.int(json, "rewardCoins")
        self.rewardPoints = JSONRead.int(json, "rewardPoints")
        self.isMakeup = JSONRead.bool(json, "isMakeup")
        self.updatedAt = JSONRead.double(json, "updatedAt")
        self.isDeleted = JSONRead.bool(json, "isDeleted")
    }
}

// MARK: - 愿望

struct Wish: Codable, Identifiable, Equatable {
    var id: String = UUID().uuidString
    var ownerId: String = ""
    var title: String = ""
    var detail: String = ""
    var category: String = "生活"
    /// 需要的宠物金币
    var targetCoins: Int = 100
    /// 已攒金币
    var savedCoins: Int = 0
    /// wishing / done / archived
    var status: String = "wishing"
    /// 是否惊喜（对方看不到详情）
    var isSurprise: Bool = false
    var createdAt: Double = 0
    var fulfillAt: Double = 0
    var updatedAt: Double = 0
    var isDeleted: Bool = false

    init() {}

    init(ownerId: String, title: String, detail: String, category: String, targetCoins: Int, isSurprise: Bool) {
        self.id = "wish_\(Int(DateUtils.ms()))_\(Int.random(in: 100...999))"
        self.ownerId = ownerId
        self.title = title
        self.detail = detail
        self.category = category
        self.targetCoins = max(1, targetCoins)
        self.isSurprise = isSurprise
        self.createdAt = DateUtils.ms()
        self.updatedAt = self.createdAt
    }

    init?(json: [String: Any]) {
        self.id = JSONRead.string(json, "id", UUID().uuidString)
        self.ownerId = JSONRead.string(json, "ownerId")
        self.title = JSONRead.string(json, "title")
        self.detail = JSONRead.string(json, "detail")
        self.category = JSONRead.string(json, "category", "生活")
        self.targetCoins = JSONRead.int(json, "targetCoins", 100)
        self.savedCoins = JSONRead.int(json, "savedCoins")
        self.status = JSONRead.string(json, "status", "wishing")
        self.isSurprise = JSONRead.bool(json, "isSurprise")
        self.createdAt = JSONRead.double(json, "createdAt")
        self.fulfillAt = JSONRead.double(json, "fulfillAt")
        self.updatedAt = JSONRead.double(json, "updatedAt")
        self.isDeleted = JSONRead.bool(json, "isDeleted")
    }

    var progress: Double {
        guard targetCoins > 0 else { return 0 }
        return min(1, max(0, Double(savedCoins) / Double(targetCoins)))
    }

    var isDone: Bool { status == "done" }
    var remainingCoins: Int { max(0, targetCoins - savedCoins) }

    static let categories = ["生活", "旅行", "美食", "礼物", "体验", "学习", "存钱", "其它"]

    var categoryEmoji: String {
        switch category {
        case "生活": return "🏠"
        case "旅行": return "✈️"
        case "美食": return "🍰"
        case "礼物": return "🎁"
        case "体验": return "🎢"
        case "学习": return "📚"
        case "存钱": return "💰"
        default: return "⭐️"
        }
    }
}
