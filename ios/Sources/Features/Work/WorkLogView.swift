import SwiftUI

/// 上班 / 加班记录与工资统计
struct WorkLogView: View {
    @EnvironmentObject var session: SessionManager
    @EnvironmentObject var store: LocalStore
    @EnvironmentObject var toast: ToastCenter
    @EnvironmentObject var sync: SyncEngine
    @EnvironmentObject var live: LiveActivityController

    @State private var month = Date()
    @State private var editing: WorkLog?
    @State private var showNewSheet = false
    @State private var showSalarySettings = false

    private var monthKey: String { DateUtils.monthKey(month) }
    private var todayKey: String { DateUtils.dateKey() }
    private var settings: UserSettings { store.state.settings }
    private var accent: Color { AppTheme.accent(for: settings.accentKey) }
    private var summary: LocalStore.SalarySummary {
        store.salarySummary(ownerId: session.myId, month: monthKey)
    }
    private var todayLog: WorkLog? { store.workLog(ownerId: session.myId, dateKey: todayKey) }
    private var logs: [WorkLog] { store.workLogs(month: monthKey).filter { $0.ownerId == session.myId } }

    var body: some View {
        ScrollView {
            VStack(spacing: 16) {
                monthSelector
                todayCard
                salaryCard
                settingsCard
                recordsCard
            }
            .padding(.horizontal, 16)
            .padding(.vertical, 16)
        }
        .background(AppBackground())
        .navigationTitle("加班与工资")
        .navigationBarTitleDisplayMode(.inline)
        .toolbar {
            ToolbarItem(placement: .navigationBarTrailing) {
                Button {
                    showNewSheet = true
                } label: {
                    Image(systemName: "plus.circle.fill")
                }
            }
        }
        .sheet(item: $editing) { log in
            WorkLogEditView(log: log).appEnvironment()
        }
        .sheet(isPresented: $showNewSheet) {
            WorkLogEditView(log: nil).appEnvironment()
        }
        .sheet(isPresented: $showSalarySettings) {
            SalarySettingsView().appEnvironment()
        }
    }

    // MARK: - 月份

    private var monthSelector: some View {
        HStack {
            Button {
                month = DateUtils.calendarRef.date(byAdding: .month, value: -1, to: month) ?? month
            } label: {
                Image(systemName: "chevron.left")
            }
            Spacer()
            Text(DateUtils.monthTitle(month))
                .font(.system(size: 15, weight: .semibold))
            Spacer()
            Button {
                month = DateUtils.calendarRef.date(byAdding: .month, value: 1, to: month) ?? month
            } label: {
                Image(systemName: "chevron.right")
            }
        }
        .buttonStyle(.plain)
        .foregroundColor(accent)
        .padding(.horizontal, 4)
    }

    // MARK: - 今日打卡

    private var todayCard: some View {
        VStack(spacing: 14) {
            HStack {
                VStack(alignment: .leading, spacing: 3) {
                    Text("今天")
                        .font(.system(size: 16, weight: .semibold))
                    Text(DateUtils.dateKey() + " " + DateUtils.weekdayName(Date()))
                        .font(.system(size: 12))
                        .foregroundColor(.secondary)
                }
                Spacer()
                if let log = todayLog {
                    TagChip(
                        text: log.isLeave ? (log.leaveType.isEmpty ? "请假" : log.leaveType) : (log.isRunning ? "上班中" : "已下班"),
                        color: log.isRunning ? AppTheme.mint : accent,
                        filled: true
                    )
                }
            }

            if let log = todayLog {
                if log.isRunning {
                    TimelineView(.periodic(from: Date(), by: 1)) { context in
                        let seconds = context.date.timeIntervalSince1970 - log.startAt / 1000
                        VStack(spacing: 4) {
                            Text(DateUtils.duration(max(0, seconds)))
                                .font(.system(size: 34, weight: .bold, design: .rounded))
                                .monospacedDigit()
                                .foregroundColor(AppTheme.mint)
                            Text("已上班时长")
                                .font(.system(size: 11))
                                .foregroundColor(.secondary)
                        }
                    }
                } else {
                    HStack(spacing: 16) {
                        VStack(spacing: 3) {
                            Text(log.timeRangeText)
                                .font(.system(size: 16, weight: .semibold))
                            Text("打卡时间")
                                .font(.system(size: 11))
                                .foregroundColor(.secondary)
                        }
                        VStack(spacing: 3) {
                            Text(String(format: "%.1f", log.totalHours))
                                .font(.system(size: 16, weight: .semibold))
                                .foregroundColor(accent)
                            Text("工时")
                                .font(.system(size: 11))
                                .foregroundColor(.secondary)
                        }
                        VStack(spacing: 3) {
                            Text("+\(MoneyFormat.plain(log.overtimeHours))")
                                .font(.system(size: 16, weight: .semibold))
                                .foregroundColor(AppTheme.orange)
                            Text("加班")
                                .font(.system(size: 11))
                                .foregroundColor(.secondary)
                        }
                    }
                }

                HStack(spacing: 10) {
                    if log.isRunning {
                        Button {
                            clockOut()
                        } label: {
                            Label("下班打卡", systemImage: "clock.badge.checkmark")
                                .frame(maxWidth: .infinity)
                        }
                        .buttonStyle(PrimaryButtonStyle(color: AppTheme.mint))
                    } else {
                        Button {
                            editing = log
                        } label: {
                            Label("修改今天的记录", systemImage: "pencil")
                                .frame(maxWidth: .infinity)
                        }
                        .buttonStyle(PrimaryButtonStyle(color: accent))
                    }
                }
            } else {
                HStack(spacing: 10) {
                    Button {
                        clockIn()
                    } label: {
                        Label("上班打卡", systemImage: "clock.badge.checkmark")
                            .frame(maxWidth: .infinity)
                    }
                    .buttonStyle(PrimaryButtonStyle(color: accent))

                    Button {
                        showNewSheet = true
                    } label: {
                        Label("补记", systemImage: "square.and.pencil")
                            .frame(maxWidth: .infinity)
                    }
                    .buttonStyle(PrimaryButtonStyle(color: AppTheme.blue))
                }
            }

            if let log = todayLog, log.isRunning {
                Button {
                    live.startOvertimeActivity(startAtMs: log.startAt, partnerName: session.partnerName)
                    toast.show(live.lastError.isEmpty ? "灵动岛开始计时" : live.lastError)
                } label: {
                    Label("在灵动岛显示加班计时", systemImage: "iphone.gen3")
                        .frame(maxWidth: .infinity)
                }
                .buttonStyle(PrimaryButtonStyle(color: AppTheme.purple))
            }
        }
        .card()
    }

    // MARK: - 工资统计

    private var salaryCard: some View {
        SectionCard(title: "本月工资", icon: "banknote.fill", subtitle: monthKey) {
            HStack(spacing: 10) {
                StatTile(title: "出勤", value: "\(summary.workDays)", unit: "天", color: AppTheme.blue, icon: "calendar")
                StatTile(title: "正常工时", value: String(format: "%.1f", summary.totalHours), unit: "h", color: AppTheme.mint, icon: "clock")
                StatTile(title: "加班工时", value: String(format: "%.1f", summary.overtimeHours), unit: "h", color: AppTheme.orange, icon: "moon.stars")
            }
            HStack(spacing: 10) {
                StatTile(title: "基本工资", value: MoneyFormat.short(summary.basePay), unit: "元", color: AppTheme.purple, icon: "yensign.circle")
                StatTile(title: "加班工资", value: MoneyFormat.short(summary.overtimePay), unit: "元", color: AppTheme.orange, icon: "flame")
            }

            HStack {
                Text("应发合计")
                    .font(.system(size: 14, weight: .semibold))
                Spacer()
                Text(MoneyFormat.text(summary.total))
                    .font(.system(size: 22, weight: .bold, design: .rounded))
                    .foregroundColor(accent)
            }
            .padding(.vertical, 10)
            .padding(.horizontal, 12)
            .background(
                RoundedRectangle(cornerRadius: 14, style: .continuous)
                    .fill(accent.opacity(0.1))
            )

            if summary.leaveDays > 0 {
                Text("请假 \(summary.leaveDays) 天（不计工资）")
                    .font(.system(size: 11))
                    .foregroundColor(.secondary)
            }
            Text("按月统计当前登录账号（\(session.myName)）自己的记录，不包含对方的工时。")
                .font(.system(size: 11))
                .foregroundColor(.secondary)
        }
    }

    // MARK: - 规则设置

    private var settingsCard: some View {
        SectionCard(title: "计算规则", icon: "slider.horizontal.3") {
            HStack(spacing: 10) {
                StatTile(title: "时薪", value: MoneyFormat.short(settings.hourlyRate), unit: "元", color: AppTheme.blue)
                StatTile(title: "加班倍率", value: String(format: "%.1fx", settings.overtimeRate), color: AppTheme.orange)
                StatTile(title: "发薪日", value: "\(settings.salaryDay)", unit: "号", color: AppTheme.purple)
            }
            Button {
                showSalarySettings = true
            } label: {
                Label("修改计算规则", systemImage: "gearshape")
            }
            .buttonStyle(SoftButtonStyle(color: accent))
        }
    }

    // MARK: - 记录列表

    private var recordsCard: some View {
        SectionCard(title: "打卡记录", icon: "list.bullet.rectangle", subtitle: "\(logs.count) 条") {
            if logs.isEmpty {
                EmptyStateView(icon: "🗓️", title: "本月还没有记录", message: "点右上角 + 补记一天，或直接上班打卡")
            } else {
                VStack(spacing: 0) {
                    ForEach(logs, id: \.id) { log in
                        Button {
                            editing = log
                        } label: {
                            HStack(spacing: 10) {
                                VStack(spacing: 2) {
                                    Text(String(log.dateKey.suffix(5)))
                                        .font(.system(size: 13, weight: .medium))
                                    Text(DateUtils.weekdayName(DateUtils.date(fromKey: log.dateKey) ?? Date()))
                                        .font(.system(size: 10))
                                        .foregroundColor(.secondary)
                                }
                                .frame(width: 46)

                                VStack(alignment: .leading, spacing: 3) {
                                    Text(log.timeRangeText)
                                        .font(.system(size: 13))
                                        .foregroundColor(.primary)
                                    HStack(spacing: 6) {
                                        if log.overtimeHours > 0 {
                                            TagChip(text: "加班 \(String(format: "%.1f", log.overtimeHours))h", color: AppTheme.orange)
                                        }
                                        if log.isLeave {
                                            TagChip(text: log.leaveType.isEmpty ? "请假" : log.leaveType, color: AppTheme.blue)
                                        }
                                        if !log.note.isEmpty {
                                            Text(log.note)
                                                .font(.system(size: 11))
                                                .foregroundColor(.secondary)
                                                .lineLimit(1)
                                        }
                                    }
                                }
                                Spacer()
                                Text(MoneyFormat.text(log.totalPay))
                                    .font(.system(size: 13, weight: .semibold))
                                    .foregroundColor(accent)
                            }
                            .padding(.vertical, 8)
                            .contentShape(Rectangle())
                        }
                        .buttonStyle(.plain)
                        .contextMenu {
                            Button(role: .destructive) {
                                store.deleteWorkLog(log.id)
                                sync.requestSync()
                                toast.show("已删除")
                            } label: {
                                Label("删除", systemImage: "trash")
                            }
                        }
                        Divider().opacity(0.4)
                    }
                }
            }
        }
    }

    // MARK: - 打卡

    private func clockIn() {
        let startMs = DateUtils.timestamp(
            dateKey: todayKey,
            hour: settings.workStartHour,
            minute: settings.workStartMinute
        )
        let actualStart = min(startMs, DateUtils.ms())
        let log = WorkLog(
            ownerId: session.myId,
            dateKey: todayKey,
            startAt: actualStart,
            endAt: 0,
            overtimeHours: 0,
            hourlyRate: settings.hourlyRate,
            overtimeRate: settings.overtimeRate,
            note: "",
            isLeave: false,
            leaveType: ""
        )
        store.upsertWorkLog(log)
        sync.requestSync()
        Haptics.success()
        toast.success("上班打卡成功：" + DateUtils.clock(actualStart))
    }

    private func clockOut() {
        guard var log = todayLog else { return }
        log.endAt = DateUtils.ms()
        store.upsertWorkLog(log)
        sync.requestSync()
        Haptics.success()
        toast.success("下班打卡成功，工时 " + String(format: "%.1f", log.workedHours) + " 小时")
        if live.isRunning {
            live.stop()
        }
    }
}

/// 新增 / 编辑一条打卡记录
struct WorkLogEditView: View {
    @EnvironmentObject var session: SessionManager
    @EnvironmentObject var store: LocalStore
    @EnvironmentObject var toast: ToastCenter
    @EnvironmentObject var sync: SyncEngine
    @Environment(\.dismiss) private var dismiss

    let log: WorkLog?

    @State private var date = Date()
    @State private var startTime = Date()
    @State private var endTime = Date()
    @State private var overtimeHours = 1.0
    @State private var hourlyRate = 30.0
    @State private var overtimeRate = 1.5
    @State private var isLeave = false
    @State private var leaveType = "事假"
    @State private var note = ""
    @State private var hasEnd = true

    private var isEditing: Bool { log != nil }
    private var settings: UserSettings { store.state.settings }

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(spacing: 16) {
                    VStack(alignment: .leading, spacing: 6) {
                        Text("日期")
                            .font(.system(size: 13, weight: .medium))
                            .foregroundColor(.secondary)
                        DatePicker("", selection: $date, displayedComponents: .date)
                            .datePickerStyle(.compact)
                            .labelsHidden()
                    }
                    .frame(maxWidth: .infinity, alignment: .leading)
                    .card()

                    Toggle(isOn: $isLeave) {
                        Text("这天请假")
                            .font(.system(size: 14, weight: .medium))
                    }
                    .tint(AppTheme.blue)
                    .card()

                    if isLeave {
                        VStack(alignment: .leading, spacing: 8) {
                            Text("请假类型")
                                .font(.system(size: 13, weight: .medium))
                                .foregroundColor(.secondary)
                            LazyVGrid(columns: [GridItem(.adaptive(minimum: 68), spacing: 8)], spacing: 8) {
                                ForEach(WorkLog.leaveTypes, id: \.self) { item in
                                    Button {
                                        leaveType = item
                                    } label: {
                                        TagChip(text: item, color: AppTheme.blue, filled: leaveType == item)
                                    }
                                    .buttonStyle(.plain)
                                }
                            }
                        }
                        .frame(maxWidth: .infinity, alignment: .leading)
                        .card()
                    } else {
                        VStack(spacing: 12) {
                            HStack {
                                Text("上班时间")
                                    .font(.system(size: 14))
                                Spacer()
                                DatePicker("", selection: $startTime, displayedComponents: .hourAndMinute)
                                    .labelsHidden()
                            }
                            Divider()
                            Toggle(isOn: $hasEnd) {
                                Text("已下班")
                                    .font(.system(size: 14))
                            }
                            .tint(AppTheme.mint)
                            if hasEnd {
                                HStack {
                                    Text("下班时间")
                                        .font(.system(size: 14))
                                    Spacer()
                                    DatePicker("", selection: $endTime, displayedComponents: .hourAndMinute)
                                        .labelsHidden()
                                }
                            }
                        }
                        .card()

                        VStack(spacing: 10) {
                            HStack {
                                Text("加班时长")
                                    .font(.system(size: 14))
                                Spacer()
                                Text(String(format: "%.1f 小时", overtimeHours))
                                    .font(.system(size: 14, weight: .semibold))
                                    .foregroundColor(AppTheme.orange)
                            }
                            Slider(value: $overtimeHours, in: 0...12, step: 0.5)
                                .tint(AppTheme.orange)
                            HStack(spacing: 8) {
                                ForEach([0.0, 1.0, 2.0, 3.0], id: \.self) { value in
                                    Button {
                                        overtimeHours = value
                                    } label: {
                                        TagChip(text: value == 0 ? "不加班" : String(format: "%.0fh", value), color: AppTheme.orange, filled: overtimeHours == value)
                                    }
                                    .buttonStyle(.plain)
                                }
                            }
                        }
                        .card()
                    }

                    VStack(spacing: 12) {
                        HStack {
                            Text("时薪")
                                .font(.system(size: 14))
                            Spacer()
                            TextField("30", value: $hourlyRate, formatter: LedgerFormat.amount)
                                .keyboardType(.decimalPad)
                                .multilineTextAlignment(.trailing)
                                .frame(width: 90)
                        }
                        Divider()
                        HStack {
                            Text("加班倍率")
                                .font(.system(size: 14))
                            Spacer()
                            ForEach([1.0, 1.5, 2.0, 3.0], id: \.self) { value in
                                Button {
                                    overtimeRate = value
                                } label: {
                                    TagChip(text: String(format: "%.1fx", value), color: AppTheme.purple, filled: overtimeRate == value)
                                }
                                .buttonStyle(.plain)
                            }
                        }
                    }
                    .card()

                    InputField(title: "备注", placeholder: "例如：赶项目上线", text: $note)

                    previewCard

                    Button {
                        save()
                    } label: {
                        Text(isEditing ? "保存修改" : "保存记录")
                    }
                    .buttonStyle(PrimaryButtonStyle(color: AppTheme.pink))

                    if isEditing {
                        Button {
                            if let log = log {
                                store.deleteWorkLog(log.id)
                                sync.requestSync()
                            }
                            dismiss()
                        } label: {
                            Text("删除这条记录")
                        }
                        .buttonStyle(PrimaryButtonStyle(color: AppTheme.red))
                    }
                }
                .padding(18)
            }
            .background(AppBackground())
            .navigationTitle(isEditing ? "编辑打卡" : "补记打卡")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("取消") { dismiss() }
                }
            }
            .onAppear(perform: loadDefaults)
        }
    }

    private var previewCard: some View {
        let draft = buildDraft()
        return SectionCard(title: "工资预览", icon: "yensign.circle.fill") {
            HStack(spacing: 10) {
                StatTile(title: "工时", value: String(format: "%.1f", draft.workedHours), unit: "h", color: AppTheme.mint)
                StatTile(title: "基本", value: MoneyFormat.short(draft.basePay), unit: "元", color: AppTheme.blue)
                StatTile(title: "加班", value: MoneyFormat.short(draft.overtimePay), unit: "元", color: AppTheme.orange)
            }
            HStack {
                Text("合计")
                    .font(.system(size: 14, weight: .semibold))
                Spacer()
                Text(MoneyFormat.text(draft.totalPay))
                    .font(.system(size: 20, weight: .bold, design: .rounded))
                    .foregroundColor(AppTheme.pink)
            }
        }
    }

    // MARK: - 逻辑

    private func loadDefaults() {
        if let log = log {
            if let parsed = DateUtils.date(fromKey: log.dateKey) { date = parsed }
            startTime = DateUtils.date(fromMs: log.startAt)
            if log.endAt > log.startAt {
                endTime = DateUtils.date(fromMs: log.endAt)
                hasEnd = true
            } else {
                hasEnd = false
                endTime = Date()
            }
            overtimeHours = log.overtimeHours
            hourlyRate = log.hourlyRate > 0 ? log.hourlyRate : settings.hourlyRate
            overtimeRate = log.overtimeRate
            isLeave = log.isLeave
            leaveType = log.leaveType.isEmpty ? "事假" : log.leaveType
            note = log.note
        } else {
            hourlyRate = settings.hourlyRate
            overtimeRate = settings.overtimeRate
            let key = DateUtils.dateKey()
            startTime = DateUtils.date(fromMs: DateUtils.timestamp(dateKey: key, hour: settings.workStartHour, minute: settings.workStartMinute))
            endTime = DateUtils.date(fromMs: DateUtils.timestamp(dateKey: key, hour: settings.workEndHour, minute: settings.workEndMinute))
        }
    }

    private func buildDraft() -> WorkLog {
        let dateKey = DateUtils.dateKey(date)
        var draft = WorkLog(
            ownerId: session.myId,
            dateKey: dateKey,
            startAt: DateUtils.ms(startTime),
            endAt: hasEnd ? DateUtils.ms(endTime) : 0,
            overtimeHours: isLeave ? 0 : overtimeHours,
            hourlyRate: hourlyRate,
            overtimeRate: overtimeRate,
            note: note,
            isLeave: isLeave,
            leaveType: leaveType
        )
        if let log = log {
            draft.id = log.id
        }
        return draft
    }

    private func save() {
        var draft = buildDraft()
        if draft.endAt > 0 && draft.endAt < draft.startAt {
            // 跨零点：下班时间算到第二天
            draft.endAt += 24 * 3600 * 1000
        }
        store.upsertWorkLog(draft)
        sync.requestSync()
        Haptics.success()
        toast.success("已保存打卡记录")
        dismiss()
    }
}

/// 工资计算规则
struct SalarySettingsView: View {
    @EnvironmentObject var store: LocalStore
    @EnvironmentObject var toast: ToastCenter
    @EnvironmentObject var sync: SyncEngine
    @Environment(\.dismiss) private var dismiss

    @State private var hourlyRate = 30.0
    @State private var overtimeRate = 1.5
    @State private var startHour = 9
    @State private var endHour = 18
    @State private var salaryDay = 15
    @State private var monthlyBudget = 0.0

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(spacing: 16) {
                    VStack(spacing: 12) {
                        HStack {
                            Text("时薪")
                                .font(.system(size: 14))
                            Spacer()
                            TextField("30", value: $hourlyRate, formatter: LedgerFormat.amount)
                                .keyboardType(.decimalPad)
                                .multilineTextAlignment(.trailing)
                                .frame(width: 100)
                            Text("元/小时")
                                .font(.system(size: 12))
                                .foregroundColor(.secondary)
                        }
                        Divider()
                        HStack {
                            Text("加班倍率")
                                .font(.system(size: 14))
                            Spacer()
                            ForEach([1.0, 1.5, 2.0, 3.0], id: \.self) { value in
                                Button {
                                    overtimeRate = value
                                } label: {
                                    TagChip(text: String(format: "%.1fx", value), color: AppTheme.purple, filled: overtimeRate == value)
                                }
                                .buttonStyle(.plain)
                            }
                        }
                    }
                    .card()

                    VStack(spacing: 12) {
                        Stepper("上班时间 \(startHour):00", value: $startHour, in: 0...23)
                        Stepper("下班时间 \(endHour):00", value: $endHour, in: 0...23)
                        Stepper("发薪日 每月 \(salaryDay) 号", value: $salaryDay, in: 1...28)
                    }
                    .card()

                    VStack(spacing: 12) {
                        HStack {
                            Text("月度预算")
                                .font(.system(size: 14))
                            Spacer()
                            TextField("0", value: $monthlyBudget, formatter: LedgerFormat.amount)
                                .keyboardType(.decimalPad)
                                .multilineTextAlignment(.trailing)
                                .frame(width: 100)
                            Text("元")
                                .font(.system(size: 12))
                                .foregroundColor(.secondary)
                        }
                        Text("设为 0 表示不限制，预算用在账本首页的进度条。")
                            .font(.system(size: 11))
                            .foregroundColor(.secondary)
                    }
                    .card()

                    Button {
                        store.updateSettings { item in
                            item.hourlyRate = hourlyRate
                            item.overtimeRate = overtimeRate
                            item.workStartHour = startHour
                            item.workEndHour = endHour
                            item.salaryDay = salaryDay
                            item.monthlyBudget = monthlyBudget
                        }
                        sync.requestSync()
                        Haptics.success()
                        toast.success("计算规则已保存")
                        dismiss()
                    } label: {
                        Text("保存")
                    }
                    .buttonStyle(PrimaryButtonStyle(color: AppTheme.pink))
                }
                .padding(18)
            }
            .background(AppBackground())
            .navigationTitle("计算规则")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("取消") { dismiss() }
                }
            }
            .onAppear {
                let settings = store.state.settings
                hourlyRate = settings.hourlyRate
                overtimeRate = settings.overtimeRate
                startHour = settings.workStartHour
                endHour = settings.workEndHour
                salaryDay = settings.salaryDay
                monthlyBudget = settings.monthlyBudget
            }
        }
    }
}
