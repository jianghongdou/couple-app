import SwiftUI
import UIKit
import PhotosUI

/// 宠物养成
struct PetView: View {
    @EnvironmentObject var session: SessionManager
    @EnvironmentObject var store: LocalStore
    @EnvironmentObject var toast: ToastCenter
    @EnvironmentObject var sync: SyncEngine

    @State private var showRename = false
    @State private var showShop = false
    @State private var newName = ""
    @State private var photoItem: PhotosPickerItem?
    @State private var isLoadingPhoto = false
    @State private var bubbleText = ""
    /// 喂食 / 陪玩成功后给 3D 形象发一个动作（带序号，连点也能重复触发）
    @State private var petAction: Pet3DAction = .none

    private var pet: PetState { store.state.pet }

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(spacing: 16) {
                    petStage
                    statusCard
                    actionButtons
                    infoCard
                }
                .padding(.horizontal, 16)
                .padding(.vertical, 16)
            }
            .background(AppBackground())
            .navigationTitle("我的宠物")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .navigationBarTrailing) {
                    HStack(spacing: 14) {
                        Button {
                            newName = pet.name
                            showRename = true
                        } label: {
                            Image(systemName: "pencil")
                        }
                        Button {
                            showShop = true
                        } label: {
                            Image(systemName: "bag.fill")
                        }
                    }
                }
            }
            .alert("给宠物改个名字", isPresented: $showRename) {
                TextField("名字", text: $newName)
                Button("确定") {
                    store.renamePet(newName)
                    sync.requestSync()
                }
                Button("取消", role: .cancel) { }
            }
            .sheet(isPresented: $showShop) {
                PetShopView().appEnvironment()
            }
            .onAppear {
                store.applyPetDecayIfNeeded()
            }
            .onChange(of: photoItem) { item in
                guard let item = item else { return }
                loadPhoto(item)
            }
        }
    }

    // MARK: - 宠物展示

    private var petStage: some View {
        VStack(spacing: 12) {
            HStack {
                TagChip(text: "Lv.\(pet.level)", color: AppTheme.purple, filled: true)
                TagChip(text: PetGrowthStage.stage(forLevel: pet.level).title, color: AppTheme.mint)
                Spacer()
                // 自定义照片：现在退居成右上角的小头像，不再顶替 3D 形象
                if let url = store.customPetImageURL(), let image = UIImage(contentsOfFile: url.path) {
                    Image(uiImage: image)
                        .resizable()
                        .scaledToFill()
                        .frame(width: 30, height: 30)
                        .clipShape(Circle())
                        .overlay(Circle().stroke(AppTheme.pink.opacity(0.5), lineWidth: 1.5))
                }
                HStack(spacing: 4) {
                    Text("🪙").font(.system(size: 13))
                    Text("\(pet.coins)")
                        .font(.system(size: 14, weight: .semibold))
                        .foregroundColor(AppTheme.coin)
                }
            }

            // Q 版 3D 宠物（SceneKit）
            Pet3DView(
                pet: pet,
                onReaction: { reactToTouch() },
                action: petAction
            )
            .frame(height: 250)

            if !bubbleText.isEmpty {
                Text(bubbleText)
                    .font(.system(size: 13))
                    .padding(.horizontal, 14)
                    .padding(.vertical, 8)
                    .background(
                        Capsule().fill(AppTheme.pink.opacity(0.14))
                    )
                    .transition(.opacity)
            }

            Text("\(pet.name) · \(pet.moodLine)")
                .font(.system(size: 14, weight: .medium))
                .multilineTextAlignment(.center)

            HStack(spacing: 4) {
                Text("经验")
                    .font(.system(size: 11))
                    .foregroundColor(.secondary)
                ProgressBarView(value: pet.levelProgress, color: AppTheme.purple, height: 6)
                    .frame(width: 120)
                Text("\(pet.expInLevel)/100")
                    .font(.system(size: 11))
                    .foregroundColor(.secondary)
            }
        }
        .frame(maxWidth: .infinity)
        .card(padding: 20)
    }

    // MARK: - 状态

    private var statusCard: some View {
        SectionCard(title: "状态", icon: "heart.text.square.fill", subtitle: pet.statesSummary) {
            VStack(spacing: 10) {
                StatusBarView(icon: "🍚", label: "饱食度", value: pet.hunger, color: AppTheme.orange)
                StatusBarView(icon: "😊", label: "心情值", value: pet.mood, color: AppTheme.pink)
                StatusBarView(icon: "⚡️", label: "精力值", value: pet.energy, color: AppTheme.mint)
            }
            HStack(spacing: 10) {
                StatTile(title: "饱食", value: pet.hungerState, color: AppTheme.orange)
                StatTile(title: "心情", value: pet.moodState, color: AppTheme.pink)
                StatTile(title: "精力", value: pet.energyState, color: AppTheme.mint)
            }
            Text("每 2 小时饱食度和心情会自然下降 1 点，精力会恢复。3D 宠物会跟着等级长大、跟着心情换表情，常来看看它吧。")
                .font(.system(size: 11))
                .foregroundColor(.secondary)
        }
    }

    // MARK: - 操作

    private var actionButtons: some View {
        VStack(spacing: 12) {
            HStack(spacing: 10) {
                actionButton(icon: "🍚", title: "喂食", subtitle: "10 金币", color: AppTheme.orange) {
                    runPetAction(.eat) { store.feedPet() }
                }
                actionButton(icon: "🎾", title: "陪玩", subtitle: "赚金币", color: AppTheme.pink) {
                    runPetAction(.happy) { store.playWithPet() }
                }
                actionButton(icon: "😴", title: "睡觉", subtitle: "恢复精力", color: AppTheme.blue) {
                    runPetAction(.sleep) { store.sleepPet() }
                }
            }

            HStack(spacing: 10) {
                PhotosPicker(selection: $photoItem, matching: .images) {
                    VStack(spacing: 6) {
                        Image(systemName: "photo.badge.plus")
                            .font(.system(size: 20))
                        Text(isLoadingPhoto ? "处理中…" : "换成我的宠物照片")
                            .font(.system(size: 12, weight: .medium))
                    }
                    .frame(maxWidth: .infinity)
                    .padding(.vertical, 14)
                    .background(
                        RoundedRectangle(cornerRadius: 16, style: .continuous)
                            .fill(AppTheme.mint.opacity(0.14))
                    )
                    .foregroundColor(AppTheme.mint)
                }

                Button {
                    showShop = true
                } label: {
                    VStack(spacing: 6) {
                        Image(systemName: "sparkles")
                            .font(.system(size: 20))
                        Text("更换形象")
                            .font(.system(size: 12, weight: .medium))
                    }
                    .frame(maxWidth: .infinity)
                    .padding(.vertical, 14)
                    .background(
                        RoundedRectangle(cornerRadius: 16, style: .continuous)
                            .fill(AppTheme.purple.opacity(0.14))
                    )
                    .foregroundColor(AppTheme.purple)
                }
                .buttonStyle(.plain)
            }

            Button {
                let gained = store.addSpark(points: 3, reason: "看宠物")
                sync.requestSync()
                toast.show(gained > 0 ? "陪宠物待了一会儿，火花 +\(gained)" : "今天火花值已满")
                bubbleText = pet.moodLine
                Haptics.soft()
                petAction = .happy(nextToken())
            } label: {
                Text("陪它待一会儿")
            }
            .buttonStyle(PrimaryButtonStyle(color: AppTheme.purple))
        }
    }

    private func actionButton(icon: String, title: String, subtitle: String, color: Color, action: @escaping () -> Void) -> some View {
        Button(action: action) {
            VStack(spacing: 6) {
                Text(icon).font(.system(size: 22))
                Text(title)
                    .font(.system(size: 13, weight: .semibold))
                Text(subtitle)
                    .font(.system(size: 10))
                    .foregroundColor(.secondary)
            }
            .frame(maxWidth: .infinity)
            .padding(.vertical, 12)
            .background(
                RoundedRectangle(cornerRadius: 16, style: .continuous)
                    .fill(color.opacity(0.13))
            )
            .foregroundColor(color)
        }
        .buttonStyle(.plain)
    }

    // MARK: - 说明

    private var infoCard: some View {
        SectionCard(title: "养成说明", icon: "info.circle") {
            VStack(alignment: .leading, spacing: 8) {
                infoRow("3D 伙伴", "大脑袋三头身，等级越高越长大、颜色越奶")
                infoRow("互动方式", "拖动可以 360° 转，点一下它会抖一抖")
                infoRow("金币来源", "签到、陪玩、记账、完成愿望")
                infoRow("经验来源", "喂食 +6、陪玩 +12、睡觉 +3")
                infoRow("双人共享", "宠物由你们两个人共同照顾，状态会同步")
                infoRow("自定义形象", "上传的照片会显示在右上角小头像（只存本机）")
            }
        }
    }

    private func infoRow(_ title: String, _ text: String) -> some View {
        HStack(alignment: .top, spacing: 8) {
            Text(title)
                .font(.system(size: 12, weight: .medium))
                .frame(width: 66, alignment: .leading)
            Text(text)
                .font(.system(size: 12))
                .foregroundColor(.secondary)
            Spacer(minLength: 0)
        }
    }

    // MARK: - 交互

    /// 点一下宠物：触感反馈 + 让 3D 形象做形变抖动 / 高光闪的反应
    private func reactToTouch() {
        Haptics.tap()
        petAction = .poke(nextToken())
        clearTrigger(after: 0.8)
        // 用心情对应的反应台词，比固定文案更有「活着」的感觉
        bubbleText = Pet3DMood.derive(from: pet).reactionLine
    }

    private func handle(_ result: LocalStore.PetActionResult) {
        switch result {
        case .success(let message):
            Haptics.success()
            toast.success(message)
            bubbleText = message
            sync.requestSync()
        case .noCoins:
            Haptics.error()
            toast.error("金币不够啦，去签到或记账赚金币吧")
        case .full(let message):
            toast.show(message)
        case .fail(let message):
            Haptics.warning()
            toast.show(message)
        }
    }

    /// 宠物动作分类：决定 3D 形象跳哪段动画
    private enum PetActionKind {
        case eat
        case happy
        case sleep
    }

    /// 执行一次宠物动作，成功后让 3D 形象播对应动画
    private func runPetAction(_ kind: PetActionKind, _ action: () -> LocalStore.PetActionResult) {
        let result = action()
        handle(result)
        guard case .success = result else { return }
        switch kind {
        case .eat:
            petAction = .eat(nextToken())
            clearTrigger(after: 2.2)
        case .happy:
            petAction = .happy(nextToken())
            clearTrigger(after: 1.6)
        case .sleep:
            petAction = .sleep(nextToken())
            clearTrigger(after: 2.4)
        }
    }

    /// 动作播完就把触发值清掉，避免每次刷新都重播
    private func clearTrigger(after seconds: Double) {
        DispatchQueue.main.asyncAfter(deadline: .now() + seconds) {
            petAction = .none
        }
    }

    private func nextToken() -> Int {
        Int(Date().timeIntervalSince1970 * 1000)
    }

    private func loadPhoto(_ item: PhotosPickerItem) {
        isLoadingPhoto = true
        Task {
            var data: Data?
            if let raw = try? await item.loadTransferable(type: Data.self) {
                if let image = UIImage(data: raw) {
                    data = ImageTools.compress(image, maxBytes: 500_000, maxDimension: 800)
                }
            }
            // 转成 let 再进并发闭包
            let photoData = data
            await MainActor.run {
                isLoadingPhoto = false
                if let photoData = photoData, let _ = store.saveCustomPetImage(photoData) {
                    Haptics.success()
                    toast.success("宠物照片已更新（只存在本机）")
                    sync.requestSync()
                } else {
                    toast.error("照片处理失败，换一张试试")
                }
            }
        }
    }
}

/// 宠物形象商店
struct PetShopView: View {
    @EnvironmentObject var store: LocalStore
    @EnvironmentObject var toast: ToastCenter
    @EnvironmentObject var sync: SyncEngine
    @Environment(\.dismiss) private var dismiss

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(spacing: 14) {
                    HStack(spacing: 6) {
                        Text("🪙").font(.system(size: 16))
                        Text("当前金币 \(store.state.pet.coins)")
                            .font(.system(size: 15, weight: .semibold))
                            .foregroundColor(AppTheme.coin)
                        Spacer()
                    }
                    .card()

                    LazyVGrid(columns: [GridItem(.flexible()), GridItem(.flexible())], spacing: 12) {
                        ForEach(PetState.speciesList) { item in
                            shopCell(key: item.key, name: item.name, emoji: item.emoji, price: item.price)
                        }
                    }
                }
                .padding(16)
            }
            .background(AppBackground())
            .navigationTitle("宠物形象")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("关闭") { dismiss() }
                }
            }
        }
    }

    private func shopCell(key: String, name: String, emoji: String, price: Int) -> some View {
        let owned = price == 0 || store.state.pet.unlocked.contains(key)
        let current = store.state.pet.species == key

        return Button {
            if current { return }
            let result = store.buyPetSpecies(key, price: owned ? 0 : price)
            switch result {
            case .success(let message):
                Haptics.success()
                toast.success(message)
                sync.requestSync()
            case .noCoins:
                toast.error("金币不够，还差 \(max(0, price - store.state.pet.coins)) 枚")
            case .fail(let message):
                toast.show(message)
            case .full(let message):
                toast.show(message)
            }
        } label: {
            VStack(spacing: 8) {
                Text(emoji).font(.system(size: 40))
                Text(name)
                    .font(.system(size: 13, weight: .medium))
                if current {
                    TagChip(text: "使用中", color: AppTheme.mint, filled: true)
                } else if owned {
                    TagChip(text: "已拥有", color: AppTheme.purple)
                } else {
                    HStack(spacing: 3) {
                        Text("🪙").font(.system(size: 10))
                        Text("\(price)")
                            .font(.system(size: 12, weight: .medium))
                            .foregroundColor(AppTheme.coin)
                    }
                }
            }
            .frame(maxWidth: .infinity)
            .padding(.vertical, 14)
            .background(
                RoundedRectangle(cornerRadius: 16, style: .continuous)
                    .fill(current ? AppTheme.mint.opacity(0.14) : Color.gray.opacity(0.08))
            )
        }
        .buttonStyle(.plain)
    }
}

extension PetState {
    var statesSummary: String {
        "\(hungerState) · \(moodState)"
    }
}
