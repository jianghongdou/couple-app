import SwiftUI
import WidgetKit
import ActivityKit

/// 小组件扩展入口（只提供灵动岛 / 锁屏实时活动）
@main
struct CoupleWidgetBundle: WidgetBundle {
    var body: some Widget {
        CoupleLiveActivityWidget()
    }
}

struct CoupleLiveActivityWidget: Widget {
    var body: some WidgetConfiguration {
        ActivityConfiguration(for: CoupleActivityAttributes.self) { context in
            // 锁屏 / 通知中心样式
            LockScreenLiveView(context: context)
                .activityBackgroundTint(Color.black.opacity(0.55))
                .activitySystemActionForegroundColor(Color.white)
        } dynamicIsland: { context in
            DynamicIsland {
                DynamicIslandExpandedRegion(.leading) {
                    Text(context.state.emoji)
                        .font(.system(size: 30))
                        .padding(.leading, 4)
                }
                DynamicIslandExpandedRegion(.trailing) {
                    VStack(alignment: .trailing, spacing: 2) {
                        timerView(context)
                            .font(.system(size: 16, weight: .semibold, design: .rounded))
                            .monospacedDigit()
                        if !context.state.footer.isEmpty {
                            Text(context.state.footer)
                                .font(.system(size: 10))
                                .foregroundColor(.secondary)
                        }
                    }
                    .padding(.trailing, 4)
                }
                DynamicIslandExpandedRegion(.center) {
                    VStack(spacing: 1) {
                        Text(context.state.title)
                            .font(.system(size: 14, weight: .semibold))
                            .lineLimit(1)
                        if !context.state.subtitle.isEmpty {
                            Text(context.state.subtitle)
                                .font(.system(size: 11))
                                .foregroundColor(.secondary)
                                .lineLimit(1)
                        }
                    }
                }
                DynamicIslandExpandedRegion(.bottom) {
                    VStack(spacing: 6) {
                        ProgressView(value: min(max(context.state.progress, 0), 1))
                            .tint(accent(context))
                        Text(LiveActivityText.appName)
                            .font(.system(size: 10))
                            .foregroundColor(.secondary)
                    }
                }
            } compactLeading: {
                Text(context.state.emoji)
                    .font(.system(size: 15))
            } compactTrailing: {
                timerView(context)
                    .font(.system(size: 13, weight: .medium, design: .rounded))
                    .monospacedDigit()
                    .frame(maxWidth: 56)
            } minimal: {
                Text(context.state.emoji)
                    .font(.system(size: 14))
            }
            .keylineTint(accent(context))
        }
    }

    @ViewBuilder
    private func timerView(_ context: ActivityViewContext<CoupleActivityAttributes>) -> some View {
        if context.state.countUp && context.state.startAt > 0 {
            Text(Date(timeIntervalSince1970: context.state.startAt / 1000), style: .timer)
        } else {
            Text(context.state.title)
        }
    }

    private func accent(_ context: ActivityViewContext<CoupleActivityAttributes>) -> Color {
        Color(hexString: CoupleActivityAttributes.accentColor(hex: context.state.accentHex))
    }
}

/// 锁屏 / 通知中心视图
struct LockScreenLiveView: View {
    let context: ActivityViewContext<CoupleActivityAttributes>

    var body: some View {
        HStack(spacing: 14) {
            Text(context.state.emoji)
                .font(.system(size: 38))

            VStack(alignment: .leading, spacing: 4) {
                Text(context.state.title)
                    .font(.system(size: 15, weight: .semibold))
                    .foregroundColor(.white)
                if !context.state.subtitle.isEmpty {
                    Text(context.state.subtitle)
                        .font(.system(size: 12))
                        .foregroundColor(.white.opacity(0.75))
                }
                if context.state.progress > 0 {
                    ProgressView(value: min(max(context.state.progress, 0), 1))
                        .tint(Color(hexString: CoupleActivityAttributes.accentColor(hex: context.state.accentHex)))
                        .frame(maxWidth: 160)
                }
            }

            Spacer(minLength: 6)

            VStack(alignment: .trailing, spacing: 3) {
                if context.state.countUp && context.state.startAt > 0 {
                    Text(Date(timeIntervalSince1970: context.state.startAt / 1000), style: .timer)
                        .font(.system(size: 17, weight: .semibold, design: .rounded))
                        .monospacedDigit()
                        .foregroundColor(.white)
                } else {
                    Text("❤️")
                        .font(.system(size: 16))
                }
                if !context.state.footer.isEmpty {
                    Text(context.state.footer)
                        .font(.system(size: 10))
                        .foregroundColor(.white.opacity(0.7))
                }
            }
        }
        .padding(.horizontal, 14)
        .padding(.vertical, 12)
    }
}

/// 小组件扩展里独立使用的十六进制颜色解析
extension Color {
    init(hexString: String) {
        var text = hexString.trimmingCharacters(in: .whitespacesAndNewlines)
        text = text.replacingOccurrences(of: "#", with: "")
        var value: UInt64 = 0
        Scanner(string: text).scanHexInt64(&value)
        let r = Double((value & 0xFF0000) >> 16) / 255.0
        let g = Double((value & 0x00FF00) >> 8) / 255.0
        let b = Double(value & 0x0000FF) / 255.0
        self.init(red: r, green: g, blue: b)
    }
}
