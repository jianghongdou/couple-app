import SwiftUI

/// 首页「实时卡片」：展示「在一起天数 / 加班计时 / 火花」。
///
/// 说明：这就是一张普通的 App 内 SwiftUI 卡片（外观沿用了黑色胶囊造型），
/// 不涉及系统实时活动、不依赖任何小组件扩展；
/// 加班计时、火花、签到等功能逻辑本身照常记录，卡片只负责显示。
struct RealtimeCard: View {
    @EnvironmentObject var session: SessionManager
    @EnvironmentObject var store: LocalStore

    private var settings: UserSettings { store.state.settings }
    private var accent: Color { AppTheme.accent(for: settings.accentKey) }
    private var todayWorkLog: WorkLog? { store.workLog(ownerId: session.myId, dateKey: DateUtils.dateKey()) }

    var body: some View {
        VStack(alignment: .leading, spacing: 12) {
            HStack(spacing: 8) {
                Image(systemName: "iphone.gen3")
                    .foregroundColor(AppTheme.purple)
                Text("实时卡片")
                    .font(.system(size: 16, weight: .semibold))
                Spacer()
                TagChip(text: "App 内展示", color: AppTheme.purple)
            }

            cardPreview

            Picker("显示内容", selection: Binding(
                get: { settings.cardStyle },
                set: { value in
                    store.updateSettings { item in item.cardStyle = value }
                }
            )) {
                Text("在一起天数").tag("days")
                Text("加班计时").tag("overtime")
                Text("火花").tag("spark")
            }
            .pickerStyle(.segmented)

            Text("内容随本机数据自动更新；打卡记录、火花值都在「加班工资」「火花」里照常保存。")
                .font(.system(size: 11))
                .foregroundColor(.secondary)
        }
        .card()
    }

    // MARK: - 卡片预览

    private var cardPreview: some View {
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
                Image(systemName: "arrow.triangle.2.circlepath")
                    .font(.system(size: 10))
                    .foregroundColor(.secondary)
                Text("这张卡片只在 App 内显示")
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
        if settings.cardStyle == "overtime", let log = todayWorkLog, log.isRunning {
            TimelineView(.periodic(from: Date(), by: 1)) { context in
                Text(elapsedText(from: log.startAt, now: context.date))
                    .font(.system(size: 14, weight: .semibold, design: .rounded))
                    .monospacedDigit()
                    .foregroundColor(.white)
            }
        } else if settings.cardStyle == "spark" {
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
        switch settings.cardStyle {
        case "overtime": return "💼"
        case "spark": return "🔥"
        default: return "❤️"
        }
    }

    private var previewTitle: String {
        switch settings.cardStyle {
        case "overtime": return "加班中"
        case "spark": return "火花 \(store.state.spark.points)"
        default:
            return session.togetherDays > 0 ? "在一起 \(session.togetherDays) 天" : "在一起"
        }
    }

    private var previewSubtitle: String {
        switch settings.cardStyle {
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
}
