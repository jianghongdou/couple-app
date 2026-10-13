import SwiftUI

/// 火花系统
struct SparkView: View {
    @EnvironmentObject var session: SessionManager
    @EnvironmentObject var store: LocalStore
    @EnvironmentObject var toast: ToastCenter
    @EnvironmentObject var sync: SyncEngine

    @State private var flameScale: CGFloat = 1
    @State private var showRules = false

    private var spark: SparkState { store.state.spark }

    var body: some View {
        ScrollView {
            VStack(spacing: 16) {
                flameCard
                levelCard
                calendarCard
                milestonesCard
                rulesCard
            }
            .padding(.horizontal, 16)
            .padding(.vertical, 16)
        }
        .background(AppBackground())
        .navigationTitle("火花")
        .navigationBarTitleDisplayMode(.inline)
    }

    // MARK: - 火焰

    private var flameCard: some View {
        VStack(spacing: 14) {
            Text("🔥")
                .font(.system(size: 72))
                .scaleEffect(flameScale)
                .shadow(color: AppTheme.orange.opacity(0.5), radius: 18)

            HStack(alignment: .firstTextBaseline, spacing: 6) {
                Text("\(spark.points)")
                    .font(.system(size: 42, weight: .bold, design: .rounded))
                    .foregroundColor(AppTheme.orange)
                Text("火花值")
                    .font(.system(size: 13))
                    .foregroundColor(.secondary)
            }

            HStack(spacing: 8) {
                TagChip(text: "连续 \(spark.days) 天", color: AppTheme.pink, filled: true)
                TagChip(text: "最长 \(spark.longestDays) 天", color: AppTheme.purple)
                TagChip(text: spark.isLitToday ? "今日已点亮" : "今日未点亮", color: spark.isLitToday ? AppTheme.mint : .gray)
            }

            Text(spark.moodLineText)
                .font(.system(size: 12))
                .foregroundColor(.secondary)
                .multilineTextAlignment(.center)

            HStack(spacing: 10) {
                Button {
                    poke()
                } label: {
                    Label("戳一戳", systemImage: "hand.tap.fill")
                        .frame(maxWidth: .infinity)
                }
                .buttonStyle(PrimaryButtonStyle(color: AppTheme.pink))

                Button {
                    sendLove()
                } label: {
                    Label("送爱心", systemImage: "heart.fill")
                        .frame(maxWidth: .infinity)
                }
                .buttonStyle(PrimaryButtonStyle(color: AppTheme.red))
            }

            Text("每天最多可以获得 \(LocalStore.dailySparkCap) 火花值")
                .font(.system(size: 11))
                .foregroundColor(.secondary)
        }
        .frame(maxWidth: .infinity)
        .card(padding: 20)
    }

    // MARK: - 等级

    private var levelCard: some View {
        SectionCard(title: "火花等级", icon: "chart.line.uptrend.xyaxis", subtitle: "Lv.\(spark.level) \(spark.levelTitle)") {
            ProgressBarView(value: spark.levelProgress, color: AppTheme.orange, height: 10)
            HStack {
                Text("累计 \(spark.totalPoints) 火花值")
                    .font(.system(size: 11))
                    .foregroundColor(.secondary)
                Spacer()
                Text(spark.pointsToNextLevel > 0 ? "还差 \(spark.pointsToNextLevel)" : "已满级")
                    .font(.system(size: 11))
                    .foregroundColor(.secondary)
            }

            ScrollView(.horizontal, showsIndicators: false) {
                HStack(spacing: 8) {
                    ForEach(Array(SparkState.levelSteps.indices), id: \.self) { index in
                    let step = SparkState.levelSteps[index]
                    VStack(spacing: 4) {
                        ZStack {
                            Circle()
                                .fill(spark.totalPoints >= step ? AppTheme.orange : Color.gray.opacity(0.18))
                                .frame(width: 30, height: 30)
                            Text("\(index + 1)")
                                .font(.system(size: 12, weight: .semibold))
                                .foregroundColor(spark.totalPoints >= step ? .white : .secondary)
                        }
                        Text("\(step)")
                            .font(.system(size: 8))
                            .foregroundColor(.secondary)
                    }
                }
                }
            }
        }
    }

    // MARK: - 日历

    private var calendarCard: some View {
        SectionCard(title: "最近 30 天", icon: "calendar", subtitle: "和 \(session.partnerName) 一起点亮") {
            LazyVGrid(columns: Array(repeating: GridItem(.flexible(), spacing: 6), count: 7), spacing: 8) {
                ForEach(0..<30, id: \.self) { index in
                    let date = DateUtils.addDays(-(29 - index))
                    let key = DateUtils.dateKey(date)
                    let points = spark.history[key] ?? 0
                    VStack(spacing: 3) {
                        RoundedRectangle(cornerRadius: 6, style: .continuous)
                            .fill(points > 0 ? AppTheme.orange.opacity(min(1, 0.35 + Double(points) / 40.0)) : Color.gray.opacity(0.15))
                            .frame(height: 26)
                            .overlay(
                                Text(points > 0 ? "🔥" : "")
                                    .font(.system(size: 11))
                            )
                        Text("\(DateUtils.dayNumber(date))")
                            .font(.system(size: 8))
                            .foregroundColor(.secondary)
                    }
                }
            }
        }
    }

    // MARK: - 里程碑

    private var milestonesCard: some View {
        SectionCard(title: "里程碑", icon: "trophy.fill") {
            VStack(spacing: 10) {
                ForEach(Array(SparkState.milestones.indices), id: \.self) { index in
                    let item = SparkState.milestones[index]
                    let reached = spark.longestDays >= item.days
                    HStack(spacing: 10) {
                        Text(reached ? item.emoji : "🔒")
                            .font(.system(size: 20))
                            .frame(width: 30)
                        VStack(alignment: .leading, spacing: 2) {
                            Text(item.title)
                                .font(.system(size: 14, weight: .medium))
                                .foregroundColor(reached ? .primary : .secondary)
                            Text("连续 \(item.days) 天")
                                .font(.system(size: 11))
                                .foregroundColor(.secondary)
                        }
                        Spacer()
                        if reached {
                            Image(systemName: "checkmark.circle.fill")
                                .foregroundColor(AppTheme.mint)
                        } else {
                            Text("还差 \(max(0, item.days - spark.days)) 天")
                                .font(.system(size: 11))
                                .foregroundColor(.secondary)
                        }
                    }
                }
            }
        }
    }

    private var rulesCard: some View {
        SectionCard(title: "火花规则", icon: "questionmark.circle") {
            VStack(alignment: .leading, spacing: 8) {
                rule("每天聊天、签到、喂宠物、记一笔账都能获得火花值")
                rule("每天上限 \(LocalStore.dailySparkCap) 点，避免刷分")
                rule("连续互动天数越多，签到奖励越高")
                rule("断签不会清空火花值，但连续天数会重新计算")
                rule("双方都用同一个服务器时，火花、宠物、签到会同步给彼此")
            }
        }
    }

    private func rule(_ text: String) -> some View {
        HStack(alignment: .top, spacing: 6) {
            Text("•").foregroundColor(AppTheme.orange)
            Text(text)
                .font(.system(size: 12))
                .foregroundColor(.secondary)
            Spacer(minLength: 0)
        }
    }

    // MARK: - 操作

    private func poke() {
        Haptics.medium()
        withAnimation(.spring(response: 0.3, dampingFraction: 0.5)) {
            flameScale = 1.25
        }
        DispatchQueue.main.asyncAfter(deadline: .now() + 0.25) {
            withAnimation(.spring(response: 0.3, dampingFraction: 0.7)) {
                flameScale = 1
            }
        }
        let gained = store.addSpark(points: 5, reason: "戳一戳")
        sync.requestSync()
        if gained > 0 {
            toast.success("火花 +\(gained)")
        } else {
            toast.show("今天的火花值已经拿满啦")
        }
    }

    private func sendLove() {
        Haptics.success()
        let gained = store.addSpark(points: 8, reason: "送爱心")
        sync.requestSync()
        if gained > 0 {
            toast.success("送出一颗爱心 ❤️ 火花 +\(gained)")
            ChatService.shared.send(unicodeEmoji: "❤️")
        } else {
            toast.show("今天的火花值已经拿满啦")
        }
    }
}

extension SparkState {
    /// 火花状态文案
    var moodLineText: String {
        if points == 0 { return "快点亮你们的第一簇火花吧" }
        if !isLitToday { return "今天还没有互动，火花在等你哦" }
        if days >= 100 { return "已经连续 \(days) 天了，这份坚持很浪漫" }
        if days >= 30 { return "一个月不间断，你们的火花越来越旺" }
        return "今天已经点亮，继续保持～"
    }
}
