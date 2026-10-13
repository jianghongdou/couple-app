import Foundation

/// 用户偏好设置（会同步给伴侣的部分）
struct UserSettings: Codable, Equatable {
    var id: String = "settings"
    /// 主题色 key
    var accentKey: String = "pink"
    /// 在一起的日子（本机也可以单独设置）
    var togetherDate: Double = 0
    /// 月度预算，0 表示不限制
    var monthlyBudget: Double = 0
    /// 默认时薪
    var hourlyRate: Double = 30
    /// 默认加班倍率
    var overtimeRate: Double = 1.5
    /// 默认上班时间（小时）
    var workStartHour: Int = 9
    var workStartMinute: Int = 0
    /// 默认下班时间（小时）
    var workEndHour: Int = 18
    var workEndMinute: Int = 0
    /// 发薪日
    var salaryDay: Int = 15
    /// 是否开启灵动岛
    var liveActivityEnabled: Bool = true
    /// 灵动岛显示样式：days / overtime / spark
    var liveActivityStyle: String = "days"
    /// 是否开启消息通知（前台横幅）
    var inAppBannerEnabled: Bool = true
    /// 灵动岛显示的标题
    var liveActivityTitle: String = ""
    /// 最近一次同步时间
    var lastSyncAt: Double = 0
    var updatedAt: Double = 0
    var isDeleted: Bool = false

    init() {}

    init?(json: [String: Any]) {
        self.id = JSONRead.string(json, "id", "settings")
        self.accentKey = JSONRead.string(json, "accentKey", "pink")
        self.togetherDate = JSONRead.double(json, "togetherDate")
        self.monthlyBudget = JSONRead.double(json, "monthlyBudget")
        self.hourlyRate = JSONRead.double(json, "hourlyRate", 30)
        self.overtimeRate = JSONRead.double(json, "overtimeRate", 1.5)
        self.workStartHour = JSONRead.int(json, "workStartHour", 9)
        self.workStartMinute = JSONRead.int(json, "workStartMinute")
        self.workEndHour = JSONRead.int(json, "workEndHour", 18)
        self.workEndMinute = JSONRead.int(json, "workEndMinute")
        self.salaryDay = JSONRead.int(json, "salaryDay", 15)
        self.liveActivityEnabled = JSONRead.bool(json, "liveActivityEnabled", true)
        self.liveActivityStyle = JSONRead.string(json, "liveActivityStyle", "days")
        self.inAppBannerEnabled = JSONRead.bool(json, "inAppBannerEnabled", true)
        self.liveActivityTitle = JSONRead.string(json, "liveActivityTitle")
        self.lastSyncAt = JSONRead.double(json, "lastSyncAt")
        self.updatedAt = JSONRead.double(json, "updatedAt")
        self.isDeleted = JSONRead.bool(json, "isDeleted")
    }
}

/// 同步对象的种类（服务端白名单）
enum SyncKind {
    static let spark = "spark"
    static let pet = "pet"
    static let checkin = "checkin"
    static let wish = "wish"
    static let worklog = "worklog"
    static let settings = "settings"

    static let all: [String] = [spark, pet, checkin, wish, worklog, settings]
    /// 永远不上传的本地数据（记账 / 密码本）
    static let localOnly: [String] = ["ledger", "vault"]
}
