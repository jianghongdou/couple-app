import Foundation
import Combine
#if canImport(ActivityKit)
import ActivityKit
#endif

/// 灵动岛（实时活动）控制器
/// - 完整版（内含小组件扩展）才能真正启动灵动岛
/// - 轻量版会自动降级为 App 内的"灵动岛样式"卡片
final class LiveActivityController: ObservableObject {
    static let shared = LiveActivityController()

    @Published var isRunning = false
    @Published var statusText = ""
    @Published var lastError = ""

    private init() {
        refreshRunning()
    }

    /// 当前安装包是否支持真正的灵动岛
    var isSupported: Bool {
        #if ENABLE_WIDGET_EXTENSION
        if #available(iOS 16.1, *) {
            return ActivityAuthorizationInfo().areActivitiesEnabled
        }
        return false
        #else
        return false
        #endif
    }

    var supportDescription: String {
        #if ENABLE_WIDGET_EXTENSION
        if #available(iOS 16.1, *) {
            return ActivityAuthorizationInfo().areActivitiesEnabled
                ? "已支持灵动岛 / 锁屏实时活动"
                : "系统设置里关闭了「实时活动」，请到 设置 → 情侣空间 打开"
        }
        return "系统版本低于 iOS 16.1，不支持灵动岛"
        #else
        return "当前安装的是轻量版（无小组件扩展），灵动岛以卡片形式展示在首页"
        #endif
    }

    // MARK: - 状态刷新

    func refreshRunning() {
        #if ENABLE_WIDGET_EXTENSION
        if #available(iOS 16.1, *) {
            let activities = Activity<CoupleActivityAttributes>.activities
            DispatchQueue.main.async {
                self.isRunning = !activities.isEmpty
            }
            return
        }
        #endif
        DispatchQueue.main.async {
            self.isRunning = false
        }
    }

    // MARK: - 开启

    /// 在一起天数
    func startTogetherActivity(togetherDateMs: Double, partnerName: String, accentHex: String = "#FF5C8A") {
        let days = togetherDateMs > 0 ? DateUtils.togetherDays(togetherDateMs) : 0
        var state = CoupleActivityAttributes.ContentState(
            title: days > 0 ? "在一起 \(days) 天" : "在一起",
            subtitle: partnerName.isEmpty ? "我们" : partnerName,
            emoji: "❤️",
            startAt: togetherDateMs,
            countUp: false,
            progress: 0,
            footer: days > 0 ? "第 \(days) 天" : "设置纪念日后显示天数",
            accentHex: accentHex
        )
        state.countUp = false
        let attributes = CoupleActivityAttributes(name: LiveActivityText.appName, style: CoupleActivityAttributes.styleDays)
        start(attributes: attributes, state: state)
    }

    /// 加班计时
    func startOvertimeActivity(startAtMs: Double, partnerName: String, accentHex: String = "#FFA94D") {
        let state = CoupleActivityAttributes.ContentState(
            title: LiveActivityText.overtimeTitle,
            subtitle: partnerName.isEmpty ? "" : "为我加油：\(partnerName)",
            emoji: "💼",
            startAt: startAtMs,
            countUp: true,
            progress: 0,
            footer: "计时中",
            accentHex: accentHex
        )
        let attributes = CoupleActivityAttributes(name: LiveActivityText.appName, style: CoupleActivityAttributes.styleOvertime)
        start(attributes: attributes, state: state)
    }

    /// 火花
    func startSparkActivity(points: Int, days: Int, partnerName: String, accentHex: String = "#FF5C8A") {
        let state = CoupleActivityAttributes.ContentState(
            title: "火花 \(points)",
            subtitle: "连续 \(days) 天",
            emoji: "🔥",
            startAt: 0,
            countUp: false,
            progress: min(1, max(0, Double(points) / 100.0)),
            footer: partnerName,
            accentHex: accentHex
        )
        let attributes = CoupleActivityAttributes(name: LiveActivityText.appName, style: CoupleActivityAttributes.styleSpark)
        start(attributes: attributes, state: state)
    }

    private func start(attributes: CoupleActivityAttributes, state: CoupleActivityAttributes.ContentState) {
        #if ENABLE_WIDGET_EXTENSION
        guard #available(iOS 16.1, *) else {
            lastError = "系统版本低于 iOS 16.1，不支持灵动岛"
            return
        }
        Task { await requestActivity(attributes: attributes, state: state) }
        #else
        lastError = "当前安装的是轻量版，未包含灵动岛扩展。首页会以卡片形式展示同样的信息。"
        statusText = lastError
        #endif
    }

    #if ENABLE_WIDGET_EXTENSION
    @available(iOS 16.1, *)
    private func requestActivity(attributes: CoupleActivityAttributes, state: CoupleActivityAttributes.ContentState) async {
        await endAllInternal()
        do {
            let activity = try Activity<CoupleActivityAttributes>.request(
                attributes: attributes,
                contentState: state,
                pushType: nil
            )
            DeviceConfig.shared.liveActivityId = activity.id
            await MainActor.run {
                self.isRunning = true
                self.statusText = "灵动岛已开启"
                self.lastError = ""
            }
        } catch {
            await MainActor.run {
                self.isRunning = false
                self.lastError = "开启失败：" + error.localizedDescription
                self.statusText = self.lastError
            }
        }
    }
    #endif

    // MARK: - 更新与关闭

    func update(state: CoupleActivityAttributes.ContentState) {
        #if ENABLE_WIDGET_EXTENSION
        guard #available(iOS 16.1, *) else { return }
        Task {
            for activity in Activity<CoupleActivityAttributes>.activities {
                await activity.update(using: state)
            }
        }
        #endif
    }

    func stop() {
        #if ENABLE_WIDGET_EXTENSION
        guard #available(iOS 16.1, *) else { return }
        Task { await endAllInternal() }
        #endif
    }

    #if ENABLE_WIDGET_EXTENSION
    @available(iOS 16.1, *)
    private func endAllInternal() async {
        for activity in Activity<CoupleActivityAttributes>.activities {
            await activity.end(dismissalPolicy: .immediate)
        }
        await MainActor.run {
            self.isRunning = false
            self.statusText = "已关闭灵动岛"
            DeviceConfig.shared.liveActivityId = ""
        }
    }
    #endif
}
