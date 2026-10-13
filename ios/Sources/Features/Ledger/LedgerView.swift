import SwiftUI

/// AI 记账（数据只存本机）
struct LedgerView: View {
    @EnvironmentObject var session: SessionManager
    @EnvironmentObject var store: LocalStore
    @EnvironmentObject var toast: ToastCenter

    @State private var month = Date()
    @State private var showAIInput = false
    @State private var showManualAdd = false
    @State private var editingEntry: LedgerEntry?
    @State private var reportText = ""
    @State private var showReport = false
    @State private var isGeneratingReport = false

    private var monthKey: String { DateUtils.monthKey(month) }
    private var summary: LedgerSummary { store.ledgerSummary(month: monthKey) }
    private var accent: Color { AppTheme.accent(for: store.state.settings.accentKey) }

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(spacing: 16) {
                    monthSelector
                    summaryCard
                    aiEntryCard
                    if !summary.byCategory.isEmpty {
                        categoryCard
                    }
                    trendCard
                    entriesSection
                    privacyCard
                }
                .padding(.horizontal, 16)
                .padding(.vertical, 16)
            }
            .background(AppBackground())
            .navigationTitle("账本")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .navigationBarTrailing) {
                    Menu {
                        Button {
                            showAIInput = true
                        } label: {
                            Label("AI 记一笔", systemImage: "wand.and.stars")
                        }
                        Button {
                            showManualAdd = true
                        } label: {
                            Label("手动记一笔", systemImage: "square.and.pencil")
                        }
                        Button {
                            generateReport()
                        } label: {
                            Label("生成 AI 月报", systemImage: "doc.text.magnifyingglass")
                        }
                    } label: {
                        Image(systemName: "plus.circle.fill")
                    }
                }
            }
            .sheet(isPresented: $showAIInput) {
                LedgerAIInputView().appEnvironment()
            }
            .sheet(isPresented: $showManualAdd) {
                LedgerManualEntryView(entry: nil).appEnvironment()
            }
            .sheet(item: $editingEntry) { entry in
                LedgerManualEntryView(entry: entry).appEnvironment()
            }
            .sheet(isPresented: $showReport) {
                reportSheet.appEnvironment()
            }
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

    // MARK: - 概览

    private var summaryCard: some View {
        VStack(spacing: 14) {
            HStack(spacing: 10) {
                StatTile(title: "支出", value: MoneyFormat.short(summary.expense), unit: "元", color: AppTheme.pink, icon: "arrow.up.right")
                StatTile(title: "收入", value: MoneyFormat.short(summary.income), unit: "元", color: AppTheme.mint, icon: "arrow.down.left")
            }
            HStack(spacing: 10) {
                StatTile(title: "结余", value: MoneyFormat.short(summary.balance), unit: "元", color: summary.balance >= 0 ? AppTheme.mint : AppTheme.red, icon: "equal.circle")
                StatTile(title: "笔数", value: "\(summary.count)", unit: "笔", color: AppTheme.purple, icon: "list.number")
            }

            if store.state.settings.monthlyBudget > 0 {
                VStack(alignment: .leading, spacing: 6) {
                    HStack {
                        Text("预算进度")
                            .font(.system(size: 12))
                            .foregroundColor(.secondary)
                        Spacer()
                        Text("\(MoneyFormat.text(summary.expense)) / \(MoneyFormat.text(store.state.settings.monthlyBudget))")
                            .font(.system(size: 12, weight: .medium))
                    }
                    ProgressBarView(
                        value: summary.expense / max(1, store.state.settings.monthlyBudget),
                        color: summary.expense > store.state.settings.monthlyBudget ? AppTheme.red : AppTheme.mint
                    )
                    Text(summary.expense > store.state.settings.monthlyBudget
                         ? "已超出预算 \(MoneyFormat.text(summary.expense - store.state.settings.monthlyBudget))"
                         : "还可以花 \(MoneyFormat.text(store.state.settings.monthlyBudget - summary.expense))")
                        .font(.system(size: 11))
                        .foregroundColor(.secondary)
                }
            }
        }
        .card()
    }

    // MARK: - AI 入口

    private var aiEntryCard: some View {
        Button {
            showAIInput = true
        } label: {
            HStack(spacing: 12) {
                ZStack {
                    Circle()
                        .fill(AppTheme.brandGradient)
                        .frame(width: 44, height: 44)
                    Text("🤖").font(.system(size: 22))
                }
                VStack(alignment: .leading, spacing: 3) {
                    Text("用一句话记账")
                        .font(.system(size: 15, weight: .semibold))
                        .foregroundColor(.primary)
                    Text("例如：午饭25，打车18.5，买菜83.6")
                        .font(.system(size: 12))
                        .foregroundColor(.secondary)
                }
                Spacer()
                Image(systemName: "chevron.right")
                    .font(.system(size: 13))
                    .foregroundColor(.secondary)
            }
            .padding(14)
            .background(
                RoundedRectangle(cornerRadius: 18, style: .continuous)
                    .fill(accent.opacity(0.1))
            )
        }
        .buttonStyle(.plain)
    }

    // MARK: - 分类

    private var categoryCard: some View {
        SectionCard(title: "支出分类", icon: "chart.pie.fill", subtitle: monthKey) {
            VStack(spacing: 10) {
                ForEach(summary.byCategory.prefix(8), id: \.category) { item in
                    HStack(spacing: 10) {
                        Text(item.emoji).font(.system(size: 18)).frame(width: 26)
                        VStack(alignment: .leading, spacing: 4) {
                            HStack {
                                Text(item.category)
                                    .font(.system(size: 13, weight: .medium))
                                Spacer()
                                Text(MoneyFormat.text(item.amount))
                                    .font(.system(size: 13, weight: .medium))
                                    .foregroundColor(AppTheme.pink)
                            }
                            ProgressBarView(
                                value: summary.maxCategoryAmount > 0 ? item.amount / summary.maxCategoryAmount : 0,
                                color: AppTheme.pink,
                                height: 6
                            )
                        }
                    }
                }
            }
        }
    }

    // MARK: - 趋势

    private var trendCard: some View {
        SectionCard(title: "最近 7 天支出", icon: "chart.bar.fill") {
            let data = store.dailyExpense(days: 7)
            MiniBarChart(
                values: data.map { $0.amount },
                labels: data.map { String($0.key.suffix(2)) },
                color: accent
            )
            HStack {
                Text("日均 \(MoneyFormat.text(summary.averagePerDay))")
                    .font(.system(size: 11))
                    .foregroundColor(.secondary)
                Spacer()
                Text("本月共 \(summary.count) 笔")
                    .font(.system(size: 11))
                    .foregroundColor(.secondary)
            }
        }
    }

    // MARK: - 明细

    private var entriesSection: some View {
        let entries = store.ledgerEntries(month: monthKey)
        return SectionCard(title: "明细", icon: "list.bullet.rectangle", subtitle: "\(entries.count) 笔") {
            if entries.isEmpty {
                EmptyStateView(icon: "🧾", title: "这个月还没有记账", message: "用上面的一句话记账，1 秒记完")
            } else {
                VStack(spacing: 0) {
                    ForEach(entries, id: \.id) { entry in
                        Button {
                            editingEntry = entry
                        } label: {
                            HStack(spacing: 10) {
                                Text(entry.categoryEmoji)
                                    .font(.system(size: 20))
                                    .frame(width: 28)
                                VStack(alignment: .leading, spacing: 2) {
                                    Text(entry.note.isEmpty ? entry.category : entry.note)
                                        .font(.system(size: 14))
                                        .foregroundColor(.primary)
                                        .lineLimit(1)
                                    HStack(spacing: 6) {
                                        Text(entry.date)
                                            .font(.system(size: 11))
                                            .foregroundColor(.secondary)
                                        if entry.source == "ai" {
                                            TagChip(text: "AI", color: AppTheme.purple)
                                        }
                                        Text(entry.category)
                                            .font(.system(size: 11))
                                            .foregroundColor(.secondary)
                                    }
                                }
                                Spacer()
                                Text((entry.isExpense ? "-" : "+") + MoneyFormat.text(entry.amount))
                                    .font(.system(size: 14, weight: .semibold))
                                    .foregroundColor(entry.isExpense ? AppTheme.pink : AppTheme.mint)
                            }
                            .padding(.vertical, 8)
                            .contentShape(Rectangle())
                        }
                        .buttonStyle(.plain)
                        .contextMenu {
                            Button(role: .destructive) {
                                store.deleteLedgerEntry(entry.id)
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

    // MARK: - 隐私

    private var privacyCard: some View {
        HStack(alignment: .top, spacing: 8) {
            Image(systemName: "lock.fill")
                .foregroundColor(AppTheme.mint)
            VStack(alignment: .leading, spacing: 3) {
                Text("账目只存在这台手机上")
                    .font(.system(size: 13, weight: .semibold))
                Text("服务器只负责把你说的话转成金额，不会保存任何账单内容；也不会同步给对方的账本。")
                    .font(.system(size: 11))
                    .foregroundColor(.secondary)
            }
            Spacer(minLength: 0)
        }
        .card()
    }

    // MARK: - 月报

    private var reportSheet: some View {
        NavigationStack {
            ScrollView {
                VStack(alignment: .leading, spacing: 12) {
                    if isGeneratingReport {
                        HStack(spacing: 8) {
                            ProgressView()
                            Text("AI 正在总结…")
                                .font(.system(size: 13))
                                .foregroundColor(.secondary)
                        }
                    } else {
                        Text(reportText.isEmpty ? "暂时没有内容" : reportText)
                            .font(.system(size: 14))
                            .lineSpacing(4)
                    }
                }
                .padding(18)
            }
            .background(AppBackground())
            .navigationTitle("AI 月度总结")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("关闭") { showReport = false }
                }
            }
        }
    }

    private func generateReport() {
        isGeneratingReport = true
        reportText = ""
        showReport = true
        let targetMonth = monthKey
        let targetSummary = summary
        Task {
            let text = await LedgerAIService.shared.monthlyReport(month: targetMonth, summary: targetSummary)
            await MainActor.run {
                reportText = text
                isGeneratingReport = false
            }
        }
    }
}
