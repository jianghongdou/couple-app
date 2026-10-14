import SwiftUI

/// 首页：情侣概览 + 快捷入口 + 今日数据
struct HomeView: View {
    @EnvironmentObject var session: SessionManager
    @EnvironmentObject var store: LocalStore
    @EnvironmentObject var chat: ChatService
    @EnvironmentObject var sync: SyncEngine
    @EnvironmentObject var toast: ToastCenter

    @State private var showAvatarSheet = false
    @State private var showWishAdd = false

    private var settings: UserSettings { store.state.settings }
    private var accent: Color { AppTheme.accent(for: settings.accentKey) }
    private var todayKey: String { DateUtils.dateKey() }
    private var monthKey: String { DateUtils.monthKey() }

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(spacing: 16) {
                    coupleHeader
                    realtimeCard
                    quickActions
                    todaySection
                    loveSection
                    syncFooter
                }
                .padding(.horizontal, 16)
                .padding(.top, 8)
                .padding(.bottom, 32)
            }
            .background(AppBackground())
            .navigationTitle("首页")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .navigationBarTrailing) {
                    Button {
                        Task { await SyncEngine.shared.syncNow() }
                    } label: {
                        Image(systemName: sync.isSyncing ? "arrow.triangle.2.circlepath" : "arrow.clockwise")
                    }
                    .disabled(sync.isSyncing)
                }
            }
        }
    }

    // MARK: - 情侣信息

    private var coupleHeader: some View {
        VStack(spacing: 14) {
            HStack(spacing: 16) {
                VStack(spacing: 6) {
                    Button {
                        showAvatarSheet = true
                    } label: {
                        EmojiAvatarView(emoji: session.myEmoji, size: 58, ringColor: accent)
                    }
                    .buttonStyle(.plain)
                    Text(session.myName)
                        .font(.system(size: 13, weight: .medium))
                        .lineLimit(1)
                }

                VStack(spacing: 4) {
                    if session.isPaired {
                        HStack(spacing: 6) {
                            Text("💞").font(.system(size: 18))
                            Text("\(session.togetherDays)")
                                .font(.system(size: 30, weight: .bold, design: .rounded))
                                .foregroundColor(accent)
                            Text("天").font(.system(size: 13)).foregroundColor(.secondary)
                        }
                        Text(settings.togetherDate > 0 ? "在一起的第 \(session.togetherDays) 天" : "去「我的」设置纪念日")
                            .font(.system(size: 11))
                            .foregroundColor(.secondary)
                    } else {
                        Text("还没有绑定情侣")
                            .font(.system(size: 14, weight: .semibold))
                        Text("去「我的」创建或输入邀请码")
                            .font(.system(size: 11))
                            .foregroundColor(.secondary)
                    }
                }
                .frame(maxWidth: .infinity)

                VStack(spacing: 6) {
                    EmojiAvatarView(
                        emoji: session.partner?.emoji ?? "❓",
                        size: 58,
                        ringColor: session.isPaired ? AppTheme.purple : Color.gray.opacity(0.4)
                    )
                    Text(session.partnerName)
                        .font(.system(size: 13, weight: .medium))
                        .lineLimit(1)
                }
            }

            HStack(spacing: 10) {
                TagChip(text: "🔥 火花 \(store.state.spark.points)", color: AppTheme.orange, filled: true)
                TagChip(text: "Lv.\(store.state.spark.level) \(store.state.spark.levelTitle)", color: AppTheme.purple)
                TagChip(text: "连续 \(store.state.spark.days) 天", color: AppTheme.pink)
                Spacer(minLength: 0)
            }
        }
        .card()
        .sheet(isPresented: $showAvatarSheet) {
            AvatarPickerView(selected: Binding(
                get: { session.myEmoji },
                set: { value in
                    Task { await session.updateProfile(nickname: session.myName, avatarEmoji: value) }
                    return
                }
            )).appEnvironment()
        }
    }

    // MARK: - 实时卡片（在一起天数 / 加班计时 / 火花）

    private var realtimeCard: some View {
        RealtimeCard()
    }

    // MARK: - 快捷入口

    private var quickActions: some View {
        LazyVGrid(columns: [GridItem(.flexible()), GridItem(.flexible()), GridItem(.flexible())], spacing: 12) {
            NavigationLink(destination: CheckInView()) {
                QuickActionTile(icon: "checkmark.seal.fill", title: "签到", color: AppTheme.mint, badge: todayCheckedIn ? "已签" : "")
            }
            NavigationLink(destination: WishView()) {
                QuickActionTile(icon: "sparkles", title: "愿望", color: AppTheme.purple, badge: "\(activeWishCount)")
            }
            NavigationLink(destination: WorkLogView()) {
                QuickActionTile(icon: "briefcase.fill", title: "加班工资", color: AppTheme.orange, badge: todayWorkRunning ? "计时中" : "")
            }
            NavigationLink(destination: LedgerView()) {
                QuickActionTile(icon: "yensign.circle.fill", title: "AI 记账", color: AppTheme.pink, badge: "")
            }
            NavigationLink(destination: EmojiPackManageView()) {
                QuickActionTile(icon: "face.smiling.fill", title: "表情包", color: AppTheme.blue, badge: "")
            }
            NavigationLink(destination: VaultView()) {
                QuickActionTile(icon: "lock.shield.fill", title: "密码本", color: AppTheme.red, badge: "")
            }
        }
        .buttonStyle(.plain)
    }

    private var todayCheckedIn: Bool {
        store.hasCheckedIn(userId: session.myId, on: todayKey)
    }

    private var activeWishCount: Int {
        store.state.wishes.filter { !$0.isDeleted && !$0.isDone && $0.ownerId == session.myId }.count
    }

    private var todayWorkRunning: Bool {
        if let log = store.workLog(ownerId: session.myId, dateKey: todayKey) {
            return log.isRunning
        }
        return false
    }

    // MARK: - 今日

    private var todaySection: some View {
        SectionCard(title: "今天", icon: "calendar", subtitle: DateUtils.dateKey()) {
            HStack(spacing: 10) {
                StatTile(
                    title: "今日支出",
                    value: MoneyFormat.short(todayExpense),
                    unit: "元",
                    color: AppTheme.pink,
                    icon: "arrow.up.right"
                )
                StatTile(
                    title: "今日收入",
                    value: MoneyFormat.short(todayIncome),
                    unit: "元",
                    color: AppTheme.mint,
                    icon: "arrow.down.left"
                )
            }
            HStack(spacing: 10) {
                StatTile(
                    title: "本月支出",
                    value: MoneyFormat.short(monthSummary.expense),
                    unit: "元",
                    color: AppTheme.purple,
                    icon: "calendar.badge.clock"
                )
                StatTile(
                    title: "宠物金币",
                    value: "\(store.state.pet.coins)",
                    unit: "枚",
                    color: AppTheme.coin,
                    icon: "circle.hexagongrid.fill"
                )
            }
            if settings.monthlyBudget > 0 {
                VStack(alignment: .leading, spacing: 6) {
                    HStack {
                        Text("本月预算")
                            .font(.system(size: 12))
                            .foregroundColor(.secondary)
                        Spacer()
                        Text("\(MoneyFormat.text(monthSummary.expense)) / \(MoneyFormat.text(settings.monthlyBudget))")
                            .font(.system(size: 12, weight: .medium))
                    }
                    ProgressBarView(
                        value: settings.monthlyBudget > 0 ? monthSummary.expense / settings.monthlyBudget : 0,
                        color: monthSummary.expense > settings.monthlyBudget ? AppTheme.red : AppTheme.pink
                    )
                }
            }
        }
    }

    private var todayExpense: Double {
        store.ledgerEntries(dateKey: todayKey).filter { $0.isExpense }.reduce(0) { $0 + $1.amount }
    }

    private var todayIncome: Double {
        store.ledgerEntries(dateKey: todayKey).filter { !$0.isExpense }.reduce(0) { $0 + $1.amount }
    }

    private var monthSummary: LedgerSummary {
        store.ledgerSummary(month: monthKey)
    }

    // MARK: - 恋爱数据

    private var loveSection: some View {
        VStack(spacing: 16) {
            SectionCard(title: "火花", icon: "flame.fill", subtitle: "Lv.\(store.state.spark.level)") {
                HStack(alignment: .firstTextBaseline, spacing: 6) {
                    Text("\(store.state.spark.points)")
                        .font(.system(size: 30, weight: .bold, design: .rounded))
                        .foregroundColor(AppTheme.orange)
                    Text("火花值")
                        .font(.system(size: 12))
                        .foregroundColor(.secondary)
                    Spacer()
                    Text(store.state.spark.isLitToday ? "今天已点亮 ✨" : "今天还没点亮")
                        .font(.system(size: 12))
                        .foregroundColor(store.state.spark.isLitToday ? AppTheme.mint : .secondary)
                }
                ProgressBarView(value: store.state.spark.levelProgress, color: AppTheme.orange)
                Text(store.state.spark.pointsToNextLevel > 0
                     ? "距离 Lv.\(store.state.spark.level + 1) 还差 \(store.state.spark.pointsToNextLevel) 火花值"
                     : "已经是最高的永恒等级啦")
                    .font(.system(size: 11))
                    .foregroundColor(.secondary)
                HStack(spacing: 6) {
                    ForEach(store.state.spark.recentDays(7)) { item in
                        VStack(spacing: 4) {
                            Circle()
                                .fill(item.points > 0 ? AppTheme.orange : Color.gray.opacity(0.2))
                                .frame(width: 22, height: 22)
                                .overlay(
                                    Text(item.points > 0 ? "🔥" : "")
                                        .font(.system(size: 11))
                                )
                            Text(String(item.key.suffix(2)))
                                .font(.system(size: 9))
                                .foregroundColor(.secondary)
                        }
                    }
                }
            }

            SectionCard(title: "宠物", icon: "pawprint.fill", subtitle: "Lv.\(store.state.pet.level)") {
                HStack(spacing: 14) {
                    Text(store.state.pet.emoji).font(.system(size: 42))
                    VStack(alignment: .leading, spacing: 6) {
                        Text("\(store.state.pet.name) · \(store.state.pet.moodState)")
                            .font(.system(size: 14, weight: .medium))
                        StatusBarView(icon: "🍚", label: "饱食", value: store.state.pet.hunger, color: AppTheme.orange)
                        StatusBarView(icon: "😊", label: "心情", value: store.state.pet.mood, color: AppTheme.pink)
                        StatusBarView(icon: "⚡️", label: "精力", value: store.state.pet.energy, color: AppTheme.mint)
                    }
                }
            }
        }
    }

    // MARK: - 同步状态

    private var syncFooter: some View {
        VStack(spacing: 6) {
            HStack(spacing: 6) {
                Circle()
                    .fill(sync.lastError.isEmpty ? AppTheme.mint : AppTheme.red)
                    .frame(width: 7, height: 7)
                Text(sync.statusText)
                    .font(.system(size: 11))
                    .foregroundColor(.secondary)
                Spacer()
                Text(AppInfo.fullVersion)
                    .font(.system(size: 11))
                    .foregroundColor(.secondary)
            }
            if !sync.lastError.isEmpty {
                Text(sync.lastError)
                    .font(.system(size: 11))
                    .foregroundColor(AppTheme.red)
                    .frame(maxWidth: .infinity, alignment: .leading)
            }
        }
        .padding(.horizontal, 4)
    }
}

/// 快捷入口方块
struct QuickActionTile: View {
    let icon: String
    let title: String
    let color: Color
    var badge: String = ""

    var body: some View {
        VStack(spacing: 8) {
            ZStack(alignment: .topTrailing) {
                RoundedRectangle(cornerRadius: 16, style: .continuous)
                    .fill(color.opacity(0.14))
                    .frame(height: 60)
                    .overlay(
                        Image(systemName: icon)
                            .font(.system(size: 22, weight: .medium))
                            .foregroundColor(color)
                    )
                if !badge.isEmpty {
                    Text(badge)
                        .font(.system(size: 9, weight: .semibold))
                        .foregroundColor(.white)
                        .padding(.horizontal, 6)
                        .padding(.vertical, 2)
                        .background(Capsule().fill(color))
                        .offset(x: 4, y: -4)
                }
            }
            Text(title)
                .font(.system(size: 12, weight: .medium))
                .foregroundColor(.primary)
        }
    }
}
