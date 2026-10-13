import SwiftUI

/// 灵动岛卡片。
/// - 完整版：可以真正开启灵动岛 / 锁屏实时活动
/// - 轻量版：用同样的界面在 App 内展示，功能不受影响
struct LiveIslandCard: View {
    @EnvironmentObject var session: SessionManager
    @EnvironmentObject var store: LocalStore
    @EnvironmentObject var live: LiveActivityController
    @EnvironmentObject var toast: ToastCenter

    @State private var showStylePicker = false

    private var settings: UserSettings { store.state.settings }
    private var accent: Color { AppTheme.accent(for: settings.accentKey) }
    private var todayWorkLog: WorkLog? { store.workLog(ownerId: session.myId, dateKey: DateUtils.dateKey()) }

    var body: some View {
        VStack(alignment: .leading, spacing: 12) {
            HStack(spacing: 8) {
                Image(systemName: "iphone.gen3")
                    .foregroundColor(AppTheme.purple)
                Text("灵动岛")
                    .font(.system(size: 16, weight: .semibold))
                Spacer()
                if live.isSupported {
                    TagChip(text: live.isRunning ? "运行中" : "未开启", color: live.isRunning ? AppTheme.mint : .gray, filled: live.isRunning)
                } else {
                    TagChip(text: "卡片模式", color: AppTheme.purple)
                }
            }

            islandPreview

            if !live.isSupported {
                Text(live.supportDescription)
                    .font(.system(size: 11))
                    .foregroundColor(.secondary)
            }

            HStack(spacing: 10) {
                Button {
                    start()
                } label: {
                    Text(live.isRunning ? "更新显示" : "开启灵动岛")
                        .frame(maxWidth: .infinity)
                }
                .buttonStyle(PrimaryButtonStyle(color: AppTheme.purple))

                Button {
                    live.stop()
                    toast.show("已关闭灵动岛")
                } label: {
                    Text("关闭")
                        .frame(maxWidth: .infinity)
                }
                .buttonStyle(PrimaryButtonStyle(color: Color.gray.opacity(0.7), enabled: live.isRunning))
                .disabled(!live.isRunning)
            }

            Picker("显示内容", selection: Binding(
                get: { settings.liveActivityStyle },
                set: { value in
                    store.updateSettings { item in item.liveActivityStyle = value }
                    if live.isRunning { start() }
                }
            )) {
                Text("在一起天数").tag("days")
                Text("加班计时").tag("overtime")
                Text("火花").tag("spark")
            }
            .pickerStyle(.segmented)
        }
        .card()
    }

    // MARK: - 灵动岛预览

    private var islandPreview: some View {
        VStack(spacing: 10) {
            HStack(spacing: 10) {
                Text(previewEmoji)
                    .font(.system(size: 20))
                VStack(alignment: .leading, spacing: 1) {
                    Text(previewTitle)
                        .font(.system(size: 13, weight: .semibold))
                        .foregroundColor(.white)
                        .lineLimit(1)
                    if !previewSubtitle.isEmpty {
                        Text(previewSubtitle)
                            .font(.system(size: 10))
                            .foregroundColor(.white.opacity(0.72))
                            .lineLimit(1)
                    }
                }
                Spacer()
                previewTrailing
            }
            .padding(.horizontal, 16)
            .padding(.vertical, 12)
            .background(
                Capsule().fill(Color.black)
            )
            .overlay(
                Capsule().stroke(Color.white.opacity(0.08), lineWidth: 1)
            )

            HStack(spacing: 8) {
                Image(systemName: "lock.fill")
                    .font(.system(size: 10))
                    .foregroundColor(.secondary)
                Text("锁屏上也会显示同样的信息")
                    .font(.system(size: 10))
                    .foregroundColor(.secondary)
                Spacer()
            }
        }
        .padding(12)
        .background(
            RoundedRectangle(cornerRadius: 18, style: .continuous)
                .fill(accent.opacity(0.08))
        )
    }

    @ViewBuilder
    private var previewTrailing: some View {
        if settings.liveActivityStyle == "overtime", let log = todayWorkLog, log.isRunning {
            TimelineView(.periodic(from: Date(), by: 1)) { context in
                Text(elapsedText(from: log.startAt, now: context.date))
                    .font(.system(size: 14, weight: .semibold, design: .rounded))
                    .monospacedDigit()
                    .foregroundColor(.white)
            }
        } else if settings.liveActivityStyle == "spark" {
            Text("🔥 \(store.state.spark.points)")
                .font(.system(size: 14, weight: .semibold, design: .rounded))
                .foregroundColor(.white)
        } else {
            Text(session.togetherDays > 0 ? "\(session.togetherDays)天" : "❤️")
                .font(.system(size: 14, weight: .semibold, design: .rounded))
                .foregroundColor(.white)
        }
    }

    private var previewEmoji: String {
        switch settings.liveActivityStyle {
        case "overtime": return "💼"
        case "spark": return "🔥"
        default: return "❤️"
        }
    }

    private var previewTitle: String {
        switch settings.liveActivityStyle {
        case "overtime": return "加班中"
        case "spark": return "火花 \(store.state.spark.points)"
        default:
            return session.togetherDays > 0 ? "在一起 \(session.togetherDays) 天" : "在一起"
        }
    }

    private var previewSubtitle: String {
        switch settings.liveActivityStyle {
        case "overtime":
            return session.partnerName == "TA" ? "" : "为我加油：\(session.partnerName)"
        case "spark":
            return "连续 \(store.state.spark.days) 天"
        default:
            return session.partnerName
        }
    }

    private func elapsedText(from startMs: Double, now: Date) -> String {
        let seconds = now.timeIntervalSince1970 - startMs / 1000
        return DateUtils.duration(max(0, seconds))
    }

    // MARK: - 操作

    private func start() {
        Haptics.tap()
        switch settings.liveActivityStyle {
        case "overtime":
            let start = todayWorkLog?.startAt ?? DateUtils.ms()
            live.startOvertimeActivity(startAtMs: start, partnerName: session.partnerName)
        case "spark":
            live.startSparkActivity(
                points: store.state.spark.points,
                days: store.state.spark.days,
                partnerName: session.partnerName
            )
        default:
            live.startTogetherActivity(
                togetherDateMs: session.togetherDate,
                partnerName: session.partnerName
            )
        }
        if let error = live.lastError.isEmpty ? nil : live.lastError {
            toast.error(error)
        } else {
            toast.success(live.statusText.isEmpty ? "灵动岛已开启" : live.statusText)
        }
    }
}
