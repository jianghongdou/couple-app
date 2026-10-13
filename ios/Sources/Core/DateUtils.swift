import Foundation

/// 日期与时间工具。所有"日期键"统一使用本地时区的 yyyy-MM-dd
enum DateUtils {
    private static let calendar: Calendar = {
        var c = Calendar(identifier: .gregorian)
        c.locale = Locale(identifier: "zh_CN")
        c.timeZone = TimeZone.current
        c.firstWeekday = 2
        return c
    }()

    private static func formatter(_ pattern: String) -> DateFormatter {
        let f = DateFormatter()
        f.locale = Locale(identifier: "zh_CN")
        f.timeZone = TimeZone.current
        f.dateFormat = pattern
        return f
    }

    private static let keyFormatter = formatter("yyyy-MM-dd")
    private static let monthFormatter = formatter("yyyy-MM")
    private static let minuteFormatter = formatter("MM-dd HH:mm")
    private static let timeFormatter = formatter("HH:mm")
    private static let fullFormatter = formatter("yyyy-MM-dd HH:mm:ss")
    private static let chineseFormatter = formatter("M月d日 HH:mm")

    static var calendarRef: Calendar { calendar }

    // MARK: - 转换

    static func ms(_ date: Date = Date()) -> Double { date.timeIntervalSince1970 * 1000 }

    static func date(fromMs ms: Double) -> Date { Date(timeIntervalSince1970: ms / 1000) }

    /// yyyy-MM-dd
    static func dateKey(_ date: Date = Date()) -> String { keyFormatter.string(from: date) }

    static func dateKey(fromMs ms: Double) -> String { keyFormatter.string(from: date(fromMs: ms)) }

    /// yyyy-MM
    static func monthKey(_ date: Date = Date()) -> String { monthFormatter.string(from: date) }

    static func monthKey(fromDateKey key: String) -> String {
        guard let date = keyFormatter.date(from: key) else { return monthKey() }
        return monthFormatter.string(from: date)
    }

    static func date(fromKey key: String) -> Date? { keyFormatter.date(from: key) }

    // MARK: - 格式化

    /// 08-15 21:03
    static func short(_ ms: Double) -> String { minuteFormatter.string(from: date(fromMs: ms)) }

    /// 21:03
    static func clock(_ ms: Double) -> String { timeFormatter.string(from: date(fromMs: ms)) }

    /// 8月15日 21:03
    static func chinese(_ ms: Double) -> String { chineseFormatter.string(from: date(fromMs: ms)) }

    static func full(_ ms: Double) -> String { fullFormatter.string(from: date(fromMs: ms)) }

    /// 刚刚 / 5分钟前 / 3小时前 / 昨天 21:03 / 08-15 21:03
    static func relative(_ ms: Double) -> String {
        let now = Date()
        let date = self.date(fromMs: ms)
        let seconds = now.timeIntervalSince(date)
        if seconds < 60 { return "刚刚" }
        if seconds < 3600 { return "\(Int(seconds / 60))分钟前" }
        if calendar.isDateInToday(date) { return "\(Int(seconds / 3600))小时前" }
        if calendar.isDateInYesterday(date) { return "昨天 " + timeFormatter.string(from: date) }
        return minuteFormatter.string(from: date)
    }

    /// 时长：1小时23分
    static func duration(_ seconds: Double) -> String {
        let total = Int(max(0, seconds))
        let h = total / 3600
        let m = (total % 3600) / 60
        if h > 0 && m > 0 { return "\(h)小时\(m)分" }
        if h > 0 { return "\(h)小时" }
        if m > 0 { return "\(m)分钟" }
        return "\(total)秒"
    }

    /// 小时数（保留 1 位小数）
    static func hours(_ seconds: Double) -> Double {
        (seconds / 360.0).rounded() / 10.0
    }

    // MARK: - 计算

    static func startOfDay(_ date: Date = Date()) -> Date { calendar.startOfDay(for: date) }

    static func daysBetween(_ fromMs: Double, _ toMs: Double = DateUtils.ms()) -> Int {
        let a = calendar.startOfDay(for: date(fromMs: fromMs))
        let b = calendar.startOfDay(for: date(fromMs: toMs))
        let comps = calendar.dateComponents([.day], from: a, to: b)
        return comps.day ?? 0
    }

    /// 相差天数（含今天，用于"在一起 128 天"）
    static func togetherDays(_ fromMs: Double) -> Int {
        max(1, daysBetween(fromMs) + 1)
    }

    static func isSameDay(_ a: Double, _ b: Double) -> Bool {
        calendar.isDate(date(fromMs: a), inSameDayAs: date(fromMs: b))
    }

    static func addDays(_ days: Int, to date: Date = Date()) -> Date {
        calendar.date(byAdding: .day, value: days, to: date) ?? date
    }

    /// 本周（周一起）7 天
    static func currentWeekDates() -> [Date] {
        let today = startOfDay()
        let weekday = calendar.component(.weekday, from: today)
        // weekday: 1=周日 ... 7=周六，firstWeekday=2 时需换算
        let offset = (weekday + 5) % 7
        let monday = addDays(-offset, to: today)
        return (0..<7).map { addDays($0, to: monday) }
    }

    /// 某月所有日期
    static func datesInMonth(_ date: Date = Date()) -> [Date] {
        guard let range = calendar.range(of: .day, in: .month, for: date) else { return [] }
        let comps = calendar.dateComponents([.year, .month], from: date)
        return range.compactMap { day -> Date? in
            var c = DateComponents()
            c.year = comps.year
            c.month = comps.month
            c.day = day
            return calendar.date(from: c)
        }
    }

    /// 该月 1 号是周几（周日=0，用于日历排版）
    static func weekdayOffsetOfMonth(_ date: Date = Date()) -> Int {
        let comps = calendar.dateComponents([.year, .month], from: date)
        var c = DateComponents()
        c.year = comps.year
        c.month = comps.month
        c.day = 1
        guard let first = calendar.date(from: c) else { return 0 }
        let weekday = calendar.component(.weekday, from: first)
        return (weekday + 6) % 7
    }

    static func monthTitle(_ date: Date = Date()) -> String {
        let comps = calendar.dateComponents([.year, .month], from: date)
        return "\(comps.year ?? 0)年\(comps.month ?? 1)月"
    }

    static func dayNumber(_ date: Date) -> Int { calendar.component(.day, from: date) }

    static func isToday(_ date: Date) -> Bool { calendar.isDateInToday(date) }

    static func isFuture(_ date: Date) -> Bool { calendar.startOfDay(for: date) > startOfDay() }

    /// 星期几中文
    static func weekdayName(_ date: Date) -> String {
        let names = ["周日", "周一", "周二", "周三", "周四", "周五", "周六"]
        let weekday = calendar.component(.weekday, from: date)
        return names[max(0, min(6, weekday - 1))]
    }

    static func hour(_ ms: Double) -> Int { calendar.component(.hour, from: date(fromMs: ms)) }

    /// 把 yyyy-MM-dd + HH:mm 组合成时间戳
    static func timestamp(dateKey: String, hour: Int, minute: Int) -> Double {
        guard let base = self.date(fromKey: dateKey) else { return ms() }
        var comps = calendar.dateComponents([.year, .month, .day], from: base)
        comps.hour = hour
        comps.minute = minute
        guard let date = calendar.date(from: comps) else { return ms() }
        return ms(date)
    }
}
