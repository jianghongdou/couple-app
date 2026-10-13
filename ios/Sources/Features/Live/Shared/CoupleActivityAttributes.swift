import Foundation
import ActivityKit

/// 灵动岛 / 锁屏实时活动的数据模型。
/// ⚠️ 这个文件同时被主 App 和小组件扩展编译，**不能引用 App 内的其它类型**。
struct CoupleActivityAttributes: ActivityAttributes {

    /// 会实时变化的部分
    struct ContentState: Codable, Hashable {
        /// 主标题，例如「在一起」
        var title: String
        /// 副标题，例如「小明的宝宝」
        var subtitle: String
        /// 左侧大 emoji
        var emoji: String
        /// 计时起点（毫秒时间戳）
        var startAt: Double
        /// true：从 startAt 开始正计时；false：只显示静态文字
        var countUp: Bool
        /// 进度 0...1
        var progress: Double
        /// 底部小字
        var footer: String
        /// 主题色 #RRGGBB
        var accentHex: String
    }

    /// 创建后不再变化的部分
    var name: String
    /// days / overtime / spark
    var style: String

    static let styleDays = "days"
    static let styleOvertime = "overtime"
    static let styleSpark = "spark"

    static func accentColor(hex: String) -> String {
        // 只做简单校验，避免把奇怪字符串传进 UI
        let cleaned = hex.replacingOccurrences(of: "#", with: "")
        if cleaned.count == 6, Int(cleaned, radix: 16) != nil {
            return "#" + cleaned.uppercased()
        }
        return "#FF5C8A"
    }
}

/// 小组件里显示的静态文案（不依赖主 App）
enum LiveActivityText {
    static let appName = "情侣空间"
    static let togetherPrefix = "在一起"
    static let overtimeTitle = "加班中"
    static let sparkTitle = "火花"
}
