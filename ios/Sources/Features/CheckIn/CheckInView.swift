import SwiftUI

/// 签到系统
struct CheckInView: View {
    @EnvironmentObject var session: SessionManager
    @EnvironmentObject var store: LocalStore
    @EnvironmentObject var toast: ToastCenter
    @EnvironmentObject var sync: SyncEngine

    @State private var note = ""
    @State private var mood = "😊"
    @State private var month = Date()
    @State private var selectedDay: String?

    private let moods = ["😊", "🥰", "😴", "😤", "🥺", "🤒", "🎉", "💪"]

    private var myId: String { session.myId }
    private var todayKey: String { DateUtils.dateKey() }
    private var checkedToday: Bool { store.hasCheckedIn(userId: myId, on: todayKey) }
    private var streak: Int { store.checkInStreak(userId: myId) }
    private var monthKey: String { DateUtils.monthKey(month) }

    var body: some View {
        ScrollView {
            VStack(spacing: 16) {
                todayCard
                streakCard
                calendarCard
                historyCard
            }
            .padding(.horizontal, 16)
            .padding(.vertical, 16)
        }
        .background(AppBackground())
        .navigationTitle("每日签到")
        .navigationBarTitleDisplayMode(.inline)
    }

    // MARK: - 今日签到

    private var todayCard: some View {
        VStack(spacing: 14) {
            HStack(spacing: 8) {
                Text(checkedToday ? "✅" : "📅")
                    .font(.system(size: 26))
                VStack(alignment: .leading, spacing: 2) {
                    Text(checkedToday ? "今天已经签到啦" : "今天还没签到")
                        .font(.system(size: 16, weight: .semibold))
                    Text(DateUtils.dateKey() + " " + DateUtils.weekdayName(Date()))
                        .font(.system(size: 12))
                        .foregroundColor(.secondary)
                }
                Spacer()
            }

            if !checkedToday {
                VStack(alignment: .leading, spacing: 8) {
                    Text("今天心情如何？")
                        .font(.system(size: 13, weight: .medium))
                        .foregroundColor(.secondary)
                    HStack(spacing: 8) {
                        ForEach(moods, id: \.self) { item in
                            Button {
                                mood = item
                                Haptics.selection()
                            } label: {
                                Text(item)
                                    .font(.system(size: 22))
                                    .frame(width: 38, height: 38)
                                    .background(
                                        Circle().fill(mood == item ? AppTheme.pink.opacity(0.2) : Color.gray.opacity(0.1))
                                    )
                                    .overlay(
                                        Circle().stroke(mood == item ? AppTheme.pink : Color.clear, lineWidth: 2)
                                    )
                            }
                            .buttonStyle(.plain)
                        }
                    }

                    TextField("写一句今天想说的话（可选）", text: $note, axis: .vertical)
                        .lineLimit(2...4)
                        .padding(.horizontal, 12)
                        .padding(.vertical, 10)
                        .background(
                            RoundedRectangle(cornerRadius: 12, style: .continuous)
                                .fill(Color.gray.opacity(0.1))
                        )
                }

                Button {
                    doCheckIn()
                } label: {
                    Text("立即签到，领取奖励")
                }
                .buttonStyle(PrimaryButtonStyle(color: AppTheme.mint))
            } else if let record = store.state.checkIns.first(where: { !$0.isDeleted && $0.userId == myId && $0.dateKey == todayKey }) {
                HStack(spacing: 12) {
                    Text(record.mood.isEmpty ? "😊" : record.mood)
                        .font(.system(size: 30))
                    VStack(alignment: .leading, spacing: 4) {
                        if !record.note.isEmpty {
                            Text(record.note)
                                .font(.system(size: 13))
                        }
                        Text("获得 \(record.rewardCoins) 金币 · \(record.rewardPoints) 火花值")
                            .font(.system(size: 11))
                            .foregroundColor(.secondary)
                    }
                    Spacer()
                }
                .padding(12)
                .background(
                    RoundedRectangle(cornerRadius: 14, style: .continuous)
                        .fill(AppTheme.mint.opacity(0.1))
                )
            }
        }
        .card()
    }

    // MARK: - 连续签到

    private var streakCard: some View {
        SectionCard(title: "连续签到", icon: "flame.fill", subtitle: "\(streak) 天") {
            HStack(spacing: 10) {
                StatTile(title: "连续天数", value: "\(streak)", unit: "天", color: AppTheme.orange, icon: "flame.fill")
                StatTile(title: "本月签到", value: "\(store.checkIns(userId: myId, inMonth: monthKey).count)", unit: "次", color: AppTheme.mint, icon: "checkmark.seal.fill")
                StatTile(title: "宠物金币", value: "\(store.state.pet.coins)", unit: "枚", color: AppTheme.coin, icon: "circle.hexagongrid.fill")
            }
            Text("连续签到奖励会递增：第 1 天 20 金币，之后每多一天多 2 金币（上限 50）。")
                .font(.system(size: 11))
                .foregroundColor(.secondary)
        }
    }

    // MARK: - 日历

    private var calendarCard: some View {
        SectionCard(title: "签到日历", icon: "calendar") {
            HStack {
                Button {
                    month = DateUtils.calendarRef.date(byAdding: .month, value: -1, to: month) ?? month
                } label: {
                    Image(systemName: "chevron.left")
                }
                Spacer()
                Text(DateUtils.monthTitle(month))
                    .font(.system(size: 14, weight: .semibold))
                Spacer()
                Button {
                    month = DateUtils.calendarRef.date(byAdding: .month, value: 1, to: month) ?? month
                } label: {
                    Image(systemName: "chevron.right")
                }
            }
            .buttonStyle(.plain)
            .foregroundColor(AppTheme.pink)

            HStack(spacing: 0) {
                ForEach(["一", "二", "三", "四", "五", "六", "日"], id: \.self) { day in
                    Text(day)
                        .font(.system(size: 11))
                        .foregroundColor(.secondary)
                        .frame(maxWidth: .infinity)
                }
            }

            let offset = DateUtils.weekdayOffsetOfMonth(month)
            let dates = DateUtils.datesInMonth(month)
            LazyVGrid(columns: Array(repeating: GridItem(.flexible(), spacing: 4), count: 7), spacing: 6) {
                ForEach(Array(0..<offset), id: \.self) { index in
                    Color.clear.frame(height: 38)
                }
                ForEach(dates.indices, id: \.self) { index in
                    let date = dates[index]
                    let key = DateUtils.dateKey(date)
                    let mine = store.hasCheckedIn(userId: myId, on: key)
                    let partnerId = session.partner?.id ?? ""
                    let partner = partnerId.isEmpty ? false : store.hasCheckedIn(userId: partnerId, on: key)
                    dayCell(date: date, key: key, mine: mine, partner: partner)
                }
            }

            HStack(spacing: 14) {
                legend(color: AppTheme.pink, text: "我")
                legend(color: AppTheme.purple, text: session.partnerName)
                Spacer()
                Text("长按可补签（50 金币）")
                    .font(.system(size: 10))
                    .foregroundColor(.secondary)
            }
        }
    }

    private func dayCell(date: Date, key: String, mine: Bool, partner: Bool) -> some View {
        VStack(spacing: 2) {
            Text("\(DateUtils.dayNumber(date))")
                .font(.system(size: 12, weight: DateUtils.isToday(date) ? .bold : .regular))
                .foregroundColor(DateUtils.isFuture(date) ? .secondary : .primary)
            HStack(spacing: 2) {
                Circle()
                    .fill(mine ? AppTheme.pink : Color.gray.opacity(0.18))
                    .frame(width: 5, height: 5)
                if session.isPaired {
                    Circle()
                        .fill(partner ? AppTheme.purple : Color.gray.opacity(0.18))
                        .frame(width: 5, height: 5)
                }
            }
        }
        .frame(height: 38)
        .frame(maxWidth: .infinity)
        .background(
            RoundedRectangle(cornerRadius: 8, style: .continuous)
                .fill(DateUtils.isToday(date) ? AppTheme.pink.opacity(0.12) : Color.clear)
        )
        .onLongPressGesture {
            makeup(key: key, mine: mine)
        }
    }

    private func legend(color: Color, text: String) -> some View {
        HStack(spacing: 4) {
            Circle().fill(color).frame(width: 7, height: 7)
            Text(text)
                .font(.system(size: 11))
                .foregroundColor(.secondary)
        }
    }

    // MARK: - 我的记录

    private var historyCard: some View {
        SectionCard(title: "本月记录", icon: "list.bullet.rectangle") {
            let records = store.checkIns(userId: myId, inMonth: monthKey).reversed()
            if records.isEmpty {
                EmptyStateView(icon: "📭", title: "这个月还没有签到", message: "每天来签一次，坚持越久奖励越多")
            } else {
                VStack(spacing: 10) {
                    ForEach(Array(records), id: \.id) { record in
                        HStack(spacing: 10) {
                            Text(record.mood.isEmpty ? "📅" : record.mood)
                                .font(.system(size: 20))
                            VStack(alignment: .leading, spacing: 2) {
                                HStack(spacing: 6) {
                                    Text(record.dateKey)
                                        .font(.system(size: 13, weight: .medium))
                                    if record.isMakeup {
                                        TagChip(text: "补签", color: AppTheme.orange)
                                    }
                                    if record.userId != myId {
                                        TagChip(text: session.partnerName, color: AppTheme.purple)
                                    }
                                }
                                if !record.note.isEmpty {
                                    Text(record.note)
                                        .font(.system(size: 12))
                                        .foregroundColor(.secondary)
                                }
                            }
                            Spacer()
                            Text("+\(record.rewardCoins)")
                                .font(.system(size: 12, weight: .medium))
                                .foregroundColor(AppTheme.coin)
                        }
                    }
                }
            }
        }
    }

    // MARK: - 操作

    private func doCheckIn() {
        guard let record = store.checkIn(userId: myId, note: note, mood: mood, isMakeup: false) else {
            toast.show("今天已经签过啦")
            return
        }
        Haptics.success()
        note = ""
        toast.success("签到成功！+\(record.rewardCoins) 金币 · +\(record.rewardPoints) 火花值")
        sync.requestSync()
    }

    private func makeup(key: String, mine: Bool) {
        guard !mine else { return }
        guard let date = DateUtils.date(fromKey: key), !DateUtils.isFuture(date) else { return }
        let result = store.makeupCheckIn(userId: myId, dateKey: key)
        switch result {
        case .success(let message):
            Haptics.success()
            toast.success(message)
            sync.requestSync()
        case .noCoins:
            toast.error("金币不够啦，去喂宠物或签到攒金币")
        case .fail(let message):
            toast.show(message)
        case .full(let message):
            toast.show(message)
        }
    }
}
