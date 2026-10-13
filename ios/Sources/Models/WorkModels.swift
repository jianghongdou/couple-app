import Foundation

// MARK: - 加班 / 工资记录

/// 每日上班加班记录（含工资计算）
struct WorkLog: Codable, Identifiable, Equatable {
    var id: String = UUID().uuidString
    var ownerId: String = ""
    /// yyyy-MM-dd
    var dateKey: String = ""
    var startAt: Double = 0
    var endAt: Double = 0
    /// 加班小时数（手动填写，独立于上下班时间）
    var overtimeHours: Double = 0
    /// 时薪
    var hourlyRate: Double = 0
    /// 加班倍率
    var overtimeRate: Double = 1.5
    var isLeave: Bool = false
    /// 事假 / 病假 / 年假
    var leaveType: String = ""
    var note: String = ""
    var salaryPaid: Bool = false
    var updatedAt: Double = 0
    var isDeleted: Bool = false

    init() {}

    init(ownerId: String, dateKey: String, startAt: Double, endAt: Double, overtimeHours: Double, hourlyRate: Double, overtimeRate: Double, note: String, isLeave: Bool, leaveType: String) {
        self.id = "work_\(ownerId)_\(dateKey)"
        self.ownerId = ownerId
        self.dateKey = dateKey
        self.startAt = startAt
        self.endAt = endAt
        self.overtimeHours = overtimeHours
        self.hourlyRate = hourlyRate
        self.overtimeRate = overtimeRate
        self.note = note
        self.isLeave = isLeave
        self.leaveType = leaveType
        self.updatedAt = DateUtils.ms()
    }

    init?(json: [String: Any]) {
        self.id = JSONRead.string(json, "id", UUID().uuidString)
        self.ownerId = JSONRead.string(json, "ownerId")
        self.dateKey = JSONRead.string(json, "dateKey")
        self.startAt = JSONRead.double(json, "startAt")
        self.endAt = JSONRead.double(json, "endAt")
        self.overtimeHours = JSONRead.double(json, "overtimeHours")
        self.hourlyRate = JSONRead.double(json, "hourlyRate")
        self.overtimeRate = JSONRead.double(json, "overtimeRate", 1.5)
        self.isLeave = JSONRead.bool(json, "isLeave")
        self.leaveType = JSONRead.string(json, "leaveType")
        self.note = JSONRead.string(json, "note")
        self.salaryPaid = JSONRead.bool(json, "salaryPaid")
        self.updatedAt = JSONRead.double(json, "updatedAt")
        self.isDeleted = JSONRead.bool(json, "isDeleted")
    }

    /// 正常工时（小时）
    var workedHours: Double {
        guard endAt > startAt else { return 0 }
        var seconds = (endAt - startAt) / 1000.0
        // 扣除 1 小时午休
        if seconds > 6 * 3600 { seconds -= 3600 }
        return DateUtils.hours(seconds)
    }

    var basePay: Double { isLeave ? 0 : workedHours * hourlyRate }
    var overtimePay: Double { isLeave ? 0 : overtimeHours * hourlyRate * overtimeRate }
    var totalPay: Double { basePay + overtimePay }
    var totalHours: Double { isLeave ? 0 : workedHours + overtimeHours }

    /// 该记录是否在计时中（未填下班时间）
    var isRunning: Bool { endAt <= startAt }

    var timeRangeText: String {
        if isLeave { return leaveType.isEmpty ? "请假" : leaveType }
        if isRunning { return "上班中…" }
        return "\(DateUtils.clock(startAt)) - \(DateUtils.clock(endAt))"
    }

    static let leaveTypes = ["事假", "病假", "年假", "调休", "婚假", "产假"]
}
