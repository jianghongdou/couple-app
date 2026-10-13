import SwiftUI

enum LedgerFormat {
    static let amount: NumberFormatter = {
        let formatter = NumberFormatter()
        formatter.numberStyle = .decimal
        formatter.maximumFractionDigits = 2
        formatter.minimumFractionDigits = 0
        return formatter
    }()
}

/// AI 一句话记账
struct LedgerAIInputView: View {
    @EnvironmentObject var session: SessionManager
    @EnvironmentObject var store: LocalStore
    @EnvironmentObject var toast: ToastCenter
    @Environment(\.dismiss) private var dismiss

    @State private var text = ""
    @State private var items: [ParsedLedgerItem] = []
    @State private var included: [Bool] = []
    @State private var summaryText = ""
    @State private var engineText = ""
    @State private var errorText = ""
    @State private var isParsing = false
    @State private var isSaving = false

    private let examples = [
        "午饭25，打车18.5",
        "买菜83.6，水果32",
        "奶茶18 电影票60 打车22",
        "工资到账12000",
        "房租2500，水电184.5"
    ]

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(alignment: .leading, spacing: 16) {
                    inputCard

                    if !engineText.isEmpty {
                        HStack(spacing: 6) {
                            Image(systemName: "sparkles")
                                .foregroundColor(AppTheme.purple)
                            Text(engineText)
                                .font(.system(size: 12))
                                .foregroundColor(.secondary)
                            Spacer()
                        }
                    }

                    if isParsing {
                        HStack(spacing: 8) {
                            ProgressView()
                            Text("正在识别…")
                                .font(.system(size: 13))
                                .foregroundColor(.secondary)
                        }
                        .card()
                    }

                    if !errorText.isEmpty {
                        HStack(alignment: .top, spacing: 8) {
                            Image(systemName: "exclamationmark.triangle.fill")
                                .foregroundColor(AppTheme.orange)
                            Text(errorText)
                                .font(.system(size: 12))
                            Spacer(minLength: 0)
                        }
                        .card()
                    }

                    if !items.isEmpty {
                        resultCard
                        saveButton
                    }
                }
                .padding(18)
            }
            .background(AppBackground())
            .navigationTitle("一句话记账")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("关闭") { dismiss() }
                }
            }
            .task {
                await LedgerAIService.shared.loadStatus()
            }
        }
    }

    // MARK: - 输入

    private var inputCard: some View {
        VStack(alignment: .leading, spacing: 12) {
            Text("说一句话，AI 帮你拆成账目")
                .font(.system(size: 14, weight: .semibold))

            ZStack(alignment: .topLeading) {
                if text.isEmpty {
                    Text("例如：午饭25，打车18.5，买菜83.6")
                        .font(.system(size: 14))
                        .foregroundColor(.secondary)
                        .padding(.horizontal, 14)
                        .padding(.vertical, 16)
                }
                TextEditor(text: $text)
                    .font(.system(size: 15))
                    .frame(height: 110)
                    .scrollContentBackground(.hidden)
                    .padding(.horizontal, 10)
                    .padding(.vertical, 8)
            }
            .background(
                RoundedRectangle(cornerRadius: 14, style: .continuous)
                    .fill(Color.gray.opacity(0.1))
            )

            ScrollView(.horizontal, showsIndicators: false) {
                HStack(spacing: 8) {
                    ForEach(examples, id: \.self) { example in
                        Button {
                            text = example
                        } label: {
                            TagChip(text: example, color: AppTheme.purple)
                        }
                        .buttonStyle(.plain)
                    }
                }
            }

            Button {
                Task { await parse() }
            } label: {
                HStack(spacing: 8) {
                    if isParsing { ProgressView().tint(.white) }
                    Text("识别")
                }
            }
            .buttonStyle(PrimaryButtonStyle(
                color: AppTheme.purple,
                enabled: !isParsing && !text.trimmingCharacters(in: .whitespaces).isEmpty
            ))
            .disabled(isParsing || text.trimmingCharacters(in: .whitespaces).isEmpty)

            HStack(spacing: 6) {
                Image(systemName: "lock.shield.fill")
                    .font(.system(size: 11))
                    .foregroundColor(AppTheme.mint)
                Text("只把这句话发给服务器识别金额，账单只存本机")
                    .font(.system(size: 11))
                    .foregroundColor(.secondary)
            }
        }
        .card()
    }

    // MARK: - 识别结果

    private var resultCard: some View {
        SectionCard(title: "识别结果", icon: "checklist", subtitle: summaryText) {
            VStack(spacing: 12) {
                ForEach(items.indices, id: \.self) { index in
                    VStack(spacing: 8) {
                        HStack(spacing: 10) {
                            Button {
                                included[index].toggle()
                                Haptics.selection()
                            } label: {
                                Image(systemName: included[index] ? "checkmark.circle.fill" : "circle")
                                    .foregroundColor(included[index] ? AppTheme.mint : .secondary)
                                    .font(.system(size: 20))
                            }
                            .buttonStyle(.plain)

                            Picker("", selection: $items[index].kind) {
                                Text("支出").tag("expense")
                                Text("收入").tag("income")
                            }
                            .pickerStyle(.segmented)
                            .frame(width: 130)

                            TextField("金额", value: $items[index].amount, formatter: LedgerFormat.amount)
                                .keyboardType(.decimalPad)
                                .multilineTextAlignment(.trailing)
                                .font(.system(size: 16, weight: .semibold))
                                .frame(width: 90)
                        }

                        HStack(spacing: 8) {
                            TextField("备注", text: $items[index].note)
                                .font(.system(size: 13))
                                .padding(.horizontal, 10)
                                .padding(.vertical, 8)
                                .background(
                                    RoundedRectangle(cornerRadius: 10, style: .continuous)
                                        .fill(Color.gray.opacity(0.1))
                                )

                            Menu {
                                ForEach(categoryOptions, id: \.self) { category in
                                    Button(category) {
                                        items[index].category = category
                                    }
                                }
                            } label: {
                                TagChip(text: items[index].category, color: AppTheme.purple, filled: true)
                            }
                        }
                    }
                    .padding(.vertical, 6)

                    if index != items.count - 1 {
                        Divider().opacity(0.4)
                    }
                }
            }
        }
    }

    private var categoryOptions: [String] {
        var list = LedgerEntry.expenseCategories
        for item in LedgerEntry.incomeCategories where !list.contains(item) {
            list.append(item)
        }
        return list
    }

    private var saveButton: some View {
        VStack(spacing: 10) {
            HStack {
                Text("已选 \(included.filter { $0 }.count) 笔")
                    .font(.system(size: 12))
                    .foregroundColor(.secondary)
                Spacer()
                Text("合计 " + MoneyFormat.text(totalIncluded))
                    .font(.system(size: 13, weight: .semibold))
                    .foregroundColor(AppTheme.pink)
            }

            Button {
                save()
            } label: {
                HStack(spacing: 8) {
                    if isSaving { ProgressView().tint(.white) }
                    Text("保存到本机账本")
                }
            }
            .buttonStyle(PrimaryButtonStyle(
                color: AppTheme.pink,
                enabled: !isSaving && included.contains(true)
            ))
            .disabled(isSaving || !included.contains(true))
        }
        .card()
    }

    private var totalIncluded: Double {
        var total = 0.0
        for index in items.indices where included.indices.contains(index) && included[index] {
            total += items[index].isExpense ? items[index].amount : -items[index].amount
        }
        return total
    }

    // MARK: - 逻辑

    private func parse() async {
        isParsing = true
        errorText = ""
        let result = await LedgerAIService.shared.parse(text)
        await MainActor.run {
            isParsing = false
            engineText = LedgerAIService.shared.lastEngine
            if result.items.isEmpty {
                items = []
                included = []
                summaryText = ""
                errorText = result.errorText.isEmpty ? "没有识别到金额" : result.errorText
            } else {
                items = result.items
                included = Array(repeating: true, count: result.items.count)
                summaryText = result.summary.isEmpty ? "共 \(result.items.count) 笔" : result.summary
                errorText = ""
                Haptics.success()
            }
        }
    }

    private func save() {
        isSaving = true
        var entries: [LedgerEntry] = []
        for index in items.indices where included.indices.contains(index) && included[index] {
            let item = items[index]
            let entry = LedgerEntry(
                ownerId: session.myId,
                amount: item.amount,
                kind: item.kind,
                category: item.category,
                note: item.note,
                date: item.date.isEmpty ? DateUtils.dateKey() : item.date,
                source: "ai",
                rawText: text
            )
            entries.append(entry)
        }
        store.addLedgerEntries(entries)
        isSaving = false
        Haptics.success()
        toast.success("已记入本机账本，共 \(entries.count) 笔")
        dismiss()
    }
}

/// 手动记一笔 / 编辑一笔
struct LedgerManualEntryView: View {
    @EnvironmentObject var session: SessionManager
    @EnvironmentObject var store: LocalStore
    @EnvironmentObject var toast: ToastCenter
    @Environment(\.dismiss) private var dismiss

    let entry: LedgerEntry?

    @State private var amountText = ""
    @State private var kind = "expense"
    @State private var category = "餐饮"
    @State private var note = ""
    @State private var date = Date()

    private var isEditing: Bool { entry != nil }

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(spacing: 16) {
                    Picker("", selection: $kind) {
                        Text("支出").tag("expense")
                        Text("收入").tag("income")
                    }
                    .pickerStyle(.segmented)
                    .onChange(of: kind) { value in
                        category = value == "expense" ? "餐饮" : "工资"
                    }

                    VStack(alignment: .leading, spacing: 6) {
                        Text("金额")
                            .font(.system(size: 13, weight: .medium))
                            .foregroundColor(.secondary)
                        HStack(spacing: 6) {
                            Text("¥")
                                .font(.system(size: 22, weight: .semibold))
                                .foregroundColor(AppTheme.pink)
                            TextField("0.00", text: $amountText)
                                .keyboardType(.decimalPad)
                                .font(.system(size: 26, weight: .bold, design: .rounded))
                        }
                        .padding(.horizontal, 14)
                        .padding(.vertical, 10)
                        .background(
                            RoundedRectangle(cornerRadius: 14, style: .continuous)
                                .fill(Color.gray.opacity(0.1))
                        )
                    }
                    .card()

                    VStack(alignment: .leading, spacing: 8) {
                        Text("分类")
                            .font(.system(size: 13, weight: .medium))
                            .foregroundColor(.secondary)
                        LazyVGrid(columns: [GridItem(.adaptive(minimum: 72), spacing: 8)], spacing: 8) {
                            ForEach(kind == "expense" ? LedgerEntry.expenseCategories : LedgerEntry.incomeCategories, id: \.self) { item in
                                Button {
                                    category = item
                                } label: {
                                    TagChip(text: item, color: AppTheme.pink, filled: category == item)
                                }
                                .buttonStyle(.plain)
                            }
                        }
                    }
                    .frame(maxWidth: .infinity, alignment: .leading)
                    .card()

                    InputField(title: "备注", placeholder: "例如：和 TA 一起吃火锅", text: $note)

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

                    Button {
                        save()
                    } label: {
                        Text(isEditing ? "保存修改" : "记一笔")
                    }
                    .buttonStyle(PrimaryButtonStyle(
                        color: AppTheme.pink,
                        enabled: Double(amountText) != nil && (Double(amountText) ?? 0) > 0
                    ))
                    .disabled(Double(amountText) == nil || (Double(amountText) ?? 0) <= 0)

                    if isEditing {
                        Button {
                            if let entry = entry {
                                store.deleteLedgerEntry(entry.id)
                                toast.show("已删除")
                            }
                            dismiss()
                        } label: {
                            Text("删除这笔账")
                        }
                        .buttonStyle(PrimaryButtonStyle(color: AppTheme.red))
                    }
                }
                .padding(18)
            }
            .background(AppBackground())
            .navigationTitle(isEditing ? "编辑账目" : "手动记账")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("取消") { dismiss() }
                }
            }
            .onAppear {
                if let entry = entry {
                    amountText = MoneyFormat.plain(entry.amount)
                    kind = entry.kind
                    category = entry.category
                    note = entry.note
                    if let parsed = DateUtils.date(fromKey: entry.date) {
                        date = parsed
                    }
                }
            }
        }
    }

    private func save() {
        guard let amount = Double(amountText), amount > 0 else { return }
        let dateKey = DateUtils.dateKey(date)

        if var existing = entry {
            existing.amount = amount
            existing.kind = kind
            existing.category = category
            existing.note = note
            existing.date = dateKey
            store.updateLedgerEntry(existing)
            toast.success("已保存修改")
        } else {
            let new = LedgerEntry(
                ownerId: session.myId,
                amount: amount,
                kind: kind,
                category: category,
                note: note,
                date: dateKey,
                source: "manual",
                rawText: ""
            )
            store.addLedgerEntry(new)
            toast.success("已记入本机账本")
        }
        Haptics.success()
        dismiss()
    }
}
