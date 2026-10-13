import SwiftUI

/// 愿望系统
struct WishView: View {
    @EnvironmentObject var session: SessionManager
    @EnvironmentObject var store: LocalStore
    @EnvironmentObject var toast: ToastCenter
    @EnvironmentObject var sync: SyncEngine

    @State private var showAdd = false
    @State private var filter = "全部"
    @State private var contributeTarget: Wish?
    @State private var contributeCoins = 50

    private let filters = ["全部", "进行中", "已实现", "我的", "TA的"]

    private var wishes: [Wish] {
        let all = store.state.wishes.filter { !$0.isDeleted }
        switch filter {
        case "进行中": return all.filter { !$0.isDone }
        case "已实现": return all.filter { $0.isDone }
        case "我的": return all.filter { $0.ownerId == session.myId }
        case "TA的": return all.filter { $0.ownerId != session.myId }
        default: return all
        }
    }

    var body: some View {
        ScrollView {
            VStack(spacing: 14) {
                summaryCard
                filterBar

                if wishes.isEmpty {
                    EmptyStateView(icon: "🌠", title: "还没有愿望", message: "许下一个愿望，一起攒金币把它实现吧")
                        .card()
                } else {
                    ForEach(wishes, id: \.id) { wish in
                        wishCard(wish)
                    }
                }
            }
            .padding(.horizontal, 16)
            .padding(.vertical, 16)
        }
        .background(AppBackground())
        .navigationTitle("愿望清单")
        .navigationBarTitleDisplayMode(.inline)
        .toolbar {
            ToolbarItem(placement: .navigationBarTrailing) {
                Button {
                    showAdd = true
                } label: {
                    Image(systemName: "plus.circle.fill")
                }
            }
        }
        .sheet(isPresented: $showAdd) {
            WishAddView().appEnvironment()
        }
        .sheet(item: $contributeTarget) { wish in
            contributeSheet(wish).appEnvironment()
        }
    }

    // MARK: - 概览

    private var summaryCard: some View {
        SectionCard(title: "我们的愿望", icon: "sparkles", subtitle: "金币由两人共用") {
            HStack(spacing: 10) {
                StatTile(title: "进行中", value: "\(store.state.wishes.filter { !$0.isDeleted && !$0.isDone }.count)", unit: "个", color: AppTheme.purple, icon: "hourglass")
                StatTile(title: "已实现", value: "\(store.state.wishes.filter { !$0.isDeleted && $0.isDone }.count)", unit: "个", color: AppTheme.mint, icon: "checkmark.seal.fill")
                StatTile(title: "可用金币", value: "\(store.state.pet.coins)", unit: "枚", color: AppTheme.coin, icon: "circle.hexagongrid.fill")
            }
        }
    }

    private var filterBar: some View {
        ScrollView(.horizontal, showsIndicators: false) {
            HStack(spacing: 8) {
                ForEach(filters, id: \.self) { item in
                    Button {
                        filter = item
                        Haptics.selection()
                    } label: {
                        TagChip(text: item, color: AppTheme.purple, filled: filter == item)
                    }
                    .buttonStyle(.plain)
                }
            }
        }
    }

    // MARK: - 愿望卡片

    private func wishCard(_ wish: Wish) -> some View {
        let isMine = wish.ownerId == session.myId
        let hidden = wish.isSurprise && !isMine && !wish.isDone

        return VStack(alignment: .leading, spacing: 12) {
            HStack(spacing: 10) {
                Text(hidden ? "🎁" : wish.categoryEmoji)
                    .font(.system(size: 26))
                VStack(alignment: .leading, spacing: 3) {
                    Text(hidden ? "TA 准备了一个惊喜" : wish.title)
                        .font(.system(size: 15, weight: .semibold))
                    HStack(spacing: 6) {
                        TagChip(text: wish.category, color: AppTheme.purple)
                        TagChip(text: isMine ? "我" : session.partnerName, color: isMine ? AppTheme.pink : AppTheme.blue)
                        if wish.isSurprise && isMine {
                            TagChip(text: "惊喜", color: AppTheme.orange)
                        }
                        if wish.isDone {
                            TagChip(text: "已实现", color: AppTheme.mint, filled: true)
                        }
                    }
                }
                Spacer()
            }

            if !hidden && !wish.detail.isEmpty {
                Text(wish.detail)
                    .font(.system(size: 13))
                    .foregroundColor(.secondary)
            }

            VStack(spacing: 6) {
                ProgressBarView(value: wish.progress, color: wish.isDone ? AppTheme.mint : AppTheme.purple, height: 9)
                HStack {
                    Text("\(wish.savedCoins) / \(wish.targetCoins) 金币")
                        .font(.system(size: 12, weight: .medium))
                        .foregroundColor(AppTheme.coin)
                    Spacer()
                    Text("\(Int(wish.progress * 100))%")
                        .font(.system(size: 12))
                        .foregroundColor(.secondary)
                }
            }

            if !wish.isDone {
                HStack(spacing: 10) {
                    Button {
                        contributeTarget = wish
                        contributeCoins = min(50, max(10, wish.remainingCoins))
                    } label: {
                        Text("存入金币")
                            .frame(maxWidth: .infinity)
                    }
                    .buttonStyle(PrimaryButtonStyle(color: AppTheme.purple))

                    Button {
                        store.fulfillWish(wish.id)
                        sync.requestSync()
                        Haptics.success()
                        toast.success("愿望实现啦，恭喜你们！")
                    } label: {
                        Text("标记实现")
                            .frame(maxWidth: .infinity)
                    }
                    .buttonStyle(PrimaryButtonStyle(color: AppTheme.mint))

                    Button {
                        store.deleteWish(wish.id)
                        sync.requestSync()
                    } label: {
                        Image(systemName: "trash")
                            .frame(width: 40)
                    }
                    .buttonStyle(PrimaryButtonStyle(color: Color.gray.opacity(0.7)))
                }
            } else if wish.fulfillAt > 0 {
                Text("实现于 " + DateUtils.short(wish.fulfillAt))
                    .font(.system(size: 11))
                    .foregroundColor(.secondary)
            }
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .card()
    }

    // MARK: - 存入金币

    private func contributeSheet(_ wish: Wish) -> some View {
        NavigationStack {
            VStack(spacing: 18) {
                Text(wish.categoryEmoji).font(.system(size: 44))
                Text(wish.title)
                    .font(.system(size: 18, weight: .semibold))
                Text("还差 \(wish.remainingCoins) 金币")
                    .font(.system(size: 13))
                    .foregroundColor(.secondary)

                HStack(spacing: 8) {
                    ForEach([10, 30, 50, 100], id: \.self) { amount in
                        Button {
                            contributeCoins = amount
                        } label: {
                            TagChip(text: "\(amount)", color: AppTheme.coin, filled: contributeCoins == amount)
                        }
                        .buttonStyle(.plain)
                    }
                }

                Stepper("存入 \(contributeCoins) 金币", value: $contributeCoins, in: 1...1000, step: 5)
                    .padding(.horizontal, 4)

                Text("当前可用：\(store.state.pet.coins) 金币")
                    .font(.system(size: 12))
                    .foregroundColor(.secondary)

                Button {
                    let result = store.contributeToWish(wish.id, coins: contributeCoins)
                    switch result {
                    case .success(let message):
                        Haptics.success()
                        toast.success(message)
                        sync.requestSync()
                        contributeTarget = nil
                    case .noCoins:
                        toast.error("金币不够，去签到或喂宠物攒金币吧")
                    case .fail(let message):
                        toast.error(message)
                    case .full(let message):
                        toast.show(message)
                    }
                } label: {
                    Text("确认存入")
                }
                .buttonStyle(PrimaryButtonStyle(color: AppTheme.purple))

                Spacer()
            }
            .padding(20)
            .background(AppBackground())
            .navigationTitle("为愿望存金币")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("取消") { contributeTarget = nil }
                }
            }
        }
    }
}

/// 新增愿望
struct WishAddView: View {
    @EnvironmentObject var session: SessionManager
    @EnvironmentObject var store: LocalStore
    @EnvironmentObject var toast: ToastCenter
    @EnvironmentObject var sync: SyncEngine
    @Environment(\.dismiss) private var dismiss

    @State private var title = ""
    @State private var detail = ""
    @State private var category = "生活"
    @State private var targetCoins = 200
    @State private var isSurprise = false

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(spacing: 16) {
                    InputField(title: "愿望名称", placeholder: "例如：一起去看海", text: $title)

                    VStack(alignment: .leading, spacing: 6) {
                        Text("详细描述（可选）")
                            .font(.system(size: 13, weight: .medium))
                            .foregroundColor(.secondary)
                        TextField("想怎么实现？什么时候去？", text: $detail, axis: .vertical)
                            .lineLimit(2...4)
                            .padding(.horizontal, 14)
                            .padding(.vertical, 12)
                            .background(
                                RoundedRectangle(cornerRadius: 12, style: .continuous)
                                    .fill(Color.gray.opacity(0.1))
                            )
                    }

                    VStack(alignment: .leading, spacing: 8) {
                        Text("分类")
                            .font(.system(size: 13, weight: .medium))
                            .foregroundColor(.secondary)
                        LazyVGrid(columns: [GridItem(.adaptive(minimum: 72), spacing: 8)], spacing: 8) {
                            ForEach(Wish.categories, id: \.self) { item in
                                Button {
                                    category = item
                                } label: {
                                    TagChip(text: item, color: AppTheme.purple, filled: category == item)
                                }
                                .buttonStyle(.plain)
                            }
                        }
                    }

                    VStack(alignment: .leading, spacing: 8) {
                        HStack {
                            Text("需要金币")
                                .font(.system(size: 13, weight: .medium))
                                .foregroundColor(.secondary)
                            Spacer()
                            Text("\(targetCoins)")
                                .font(.system(size: 15, weight: .semibold))
                                .foregroundColor(AppTheme.coin)
                        }
                        Slider(value: Binding(
                            get: { Double(targetCoins) },
                            set: { targetCoins = Int($0) }
                        ), in: 50...2000, step: 50)
                        .tint(AppTheme.coin)
                    }
                    .card()

                    Toggle(isOn: $isSurprise) {
                        VStack(alignment: .leading, spacing: 2) {
                            Text("这是惊喜")
                                .font(.system(size: 14, weight: .medium))
                            Text("开启后，在实现之前对方看不到内容")
                                .font(.system(size: 11))
                                .foregroundColor(.secondary)
                        }
                    }
                    .tint(AppTheme.pink)
                    .card()

                    Button {
                        add()
                    } label: {
                        Text("许下愿望")
                    }
                    .buttonStyle(PrimaryButtonStyle(color: AppTheme.purple, enabled: !title.trimmingCharacters(in: .whitespaces).isEmpty))
                    .disabled(title.trimmingCharacters(in: .whitespaces).isEmpty)
                }
                .padding(18)
            }
            .background(AppBackground())
            .navigationTitle("新的愿望")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("取消") { dismiss() }
                }
            }
        }
    }

    private func add() {
        let trimmed = title.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !trimmed.isEmpty else { return }
        _ = store.addWish(
            ownerId: session.myId,
            title: trimmed,
            detail: detail,
            category: category,
            targetCoins: targetCoins,
            isSurprise: isSurprise
        )
        sync.requestSync()
        Haptics.success()
        toast.success("愿望已加入清单")
        dismiss()
    }
}
