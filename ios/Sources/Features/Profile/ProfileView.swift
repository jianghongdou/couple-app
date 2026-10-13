import SwiftUI
import UIKit

/// 我的：资料、情侣绑定、设置、关于
struct ProfileView: View {
    @EnvironmentObject var session: SessionManager
    @EnvironmentObject var store: LocalStore
    @EnvironmentObject var sync: SyncEngine
    @EnvironmentObject var live: LiveActivityController
    @EnvironmentObject var toast: ToastCenter
    @EnvironmentObject var device: DeviceConfig

    @State private var showProfileEdit = false
    @State private var showServerSheet = false
    @State private var showAnniversary = false
    @State private var inviteInput = ""
    @State private var showUnbindConfirm = false
    @State private var showWipeConfirm = false
    @State private var showLogoutConfirm = false
    @State private var anniversaryDate = Date()

    private var settings: UserSettings { store.state.settings }
    private var accent: Color { AppTheme.accent(for: settings.accentKey) }

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(spacing: 16) {
                    headerCard
                    coupleCard
                    appearanceCard
                    syncCard
                    aiCard
                    dataCard
                    aboutCard
                    logoutButton
                }
                .padding(.horizontal, 16)
                .padding(.vertical, 16)
            }
            .background(AppBackground())
            .navigationTitle("我的")
            .navigationBarTitleDisplayMode(.inline)
            .sheet(isPresented: $showProfileEdit) {
                ProfileEditView().appEnvironment()
            }
            .sheet(isPresented: $showServerSheet) {
                ServerSetupView().appEnvironment()
            }
            .sheet(isPresented: $showAnniversary) {
                anniversarySheet.appEnvironment()
            }
            .alert("解除情侣绑定？", isPresented: $showUnbindConfirm) {
                Button("解除", role: .destructive) {
                    Task { await session.leaveCouple() }
                }
                Button("取消", role: .cancel) { }
            } message: {
                Text("解除后聊天记录会从本机清除，服务器数据保留 30 天。")
            }
            .alert("清空全部本地数据？", isPresented: $showWipeConfirm) {
                Button("清空", role: .destructive) {
                    store.wipeEverything()
                    device.clearAccount()
                    session.logout()
                    toast.show("本地数据已清空")
                }
                Button("取消", role: .cancel) { }
            } message: {
                Text("会删除账本、密码本、聊天缓存等全部本机数据，且不可恢复。")
            }
            .alert("退出登录？", isPresented: $showLogoutConfirm) {
                Button("退出", role: .destructive) {
                    session.logout()
                    toast.show("已退出登录")
                }
                Button("取消", role: .cancel) { }
            } message: {
                Text("本机的记账与密码本会保留。")
            }
        }
    }

    // MARK: - 资料

    private var headerCard: some View {
        HStack(spacing: 14) {
            EmojiAvatarView(emoji: session.myEmoji, size: 64, ringColor: accent)
            VStack(alignment: .leading, spacing: 4) {
                Text(session.myName)
                    .font(.system(size: 19, weight: .bold))
                Text("@" + (session.user?.username ?? device.username))
                    .font(.system(size: 12))
                    .foregroundColor(.secondary)
                HStack(spacing: 6) {
                    TagChip(text: AppInfo.fullVersion, color: accent)
                    if session.user?.isAdmin == true {
                        TagChip(text: "管理员", color: AppTheme.purple, filled: true)
                    }
                }
            }
            Spacer()
            Button {
                showProfileEdit = true
            } label: {
                Image(systemName: "square.and.pencil")
                    .foregroundColor(accent)
                    .frame(width: 38, height: 38)
                    .background(Circle().fill(accent.opacity(0.12)))
            }
            .buttonStyle(.plain)
        }
        .card()
    }

    // MARK: - 情侣

    private var coupleCard: some View {
        SectionCard(title: "情侣关系", icon: "heart.fill", subtitle: session.isPaired ? "已绑定" : "未绑定") {
            if session.isPaired {
                HStack(spacing: 12) {
                    EmojiAvatarView(emoji: session.myEmoji, size: 40, ringColor: accent)
                    Text("💞").font(.system(size: 18))
                    EmojiAvatarView(emoji: session.partner?.emoji ?? "🐻", size: 40, ringColor: AppTheme.purple)
                    VStack(alignment: .leading, spacing: 2) {
                        Text(session.partnerName)
                            .font(.system(size: 14, weight: .medium))
                        Text(session.togetherDays > 0 ? "在一起 \(session.togetherDays) 天" : "未设置纪念日")
                            .font(.system(size: 11))
                            .foregroundColor(.secondary)
                    }
                    Spacer()
                }

                HStack(spacing: 10) {
                    Button {
                        UIPasteboard.general.string = session.inviteCode
                        toast.success("邀请码已复制：\(session.inviteCode)")
                    } label: {
                        Label("邀请码 \(session.inviteCode)", systemImage: "doc.on.doc")
                    }
                    .buttonStyle(SoftButtonStyle(color: AppTheme.blue))
                }

                HStack(spacing: 10) {
                    Button {
                        anniversaryDate = session.togetherDate > 0 ? DateUtils.date(fromMs: session.togetherDate) : Date()
                        showAnniversary = true
                    } label: {
                        Label("设置纪念日", systemImage: "calendar")
                    }
                    .buttonStyle(SoftButtonStyle(color: accent))

                    Button {
                        showUnbindConfirm = true
                    } label: {
                        Label("解除绑定", systemImage: "heart.slash")
                    }
                    .buttonStyle(SoftButtonStyle(color: AppTheme.red))
                }
            } else {
                VStack(alignment: .leading, spacing: 12) {
                    Text("两种绑定方式，两台手机都要填同一个服务器地址。")
                        .font(.system(size: 12))
                        .foregroundColor(.secondary)

                    Button {
                        Task {
                            if await session.createCouple() {
                                toast.success("情侣空间已创建")
                            }
                        }
                    } label: {
                        Label("创建情侣空间，生成邀请码", systemImage: "plus.circle")
                            .frame(maxWidth: .infinity)
                    }
                    .buttonStyle(PrimaryButtonStyle(color: accent))

                    HStack(spacing: 10) {
                        TextField("输入对方的邀请码", text: $inviteInput)
                            .textInputAutocapitalization(.characters)
                            .autocorrectionDisabled(true)
                            .padding(.horizontal, 12)
                            .padding(.vertical, 10)
                            .background(
                                RoundedRectangle(cornerRadius: 12, style: .continuous)
                                    .fill(Color.gray.opacity(0.1))
                            )
                        Button {
                            Task {
                                if await session.joinCouple(inviteCode: inviteInput) {
                                    toast.success("绑定成功")
                                    inviteInput = ""
                                }
                            }
                        } label: {
                            Text("绑定")
                        }
                        .buttonStyle(SoftButtonStyle(color: AppTheme.mint))
                    }

                    if !session.errorText.isEmpty {
                        Text(session.errorText)
                            .font(.system(size: 12))
                            .foregroundColor(AppTheme.red)
                    }
                }
            }
        }
    }

    private var anniversarySheet: some View {
        NavigationStack {
            VStack(spacing: 20) {
                Text("💞").font(.system(size: 50))
                Text("选择你们在一起的日子")
                    .font(.system(size: 15, weight: .semibold))
                DatePicker("", selection: $anniversaryDate, in: Date(timeIntervalSince1970: 0)...Date(), displayedComponents: .date)
                    .datePickerStyle(.graphical)
                    .tint(accent)

                Text("今天是在一起的第 \(DateUtils.togetherDays(DateUtils.ms(anniversaryDate))) 天")
                    .font(.system(size: 13))
                    .foregroundColor(.secondary)

                Button {
                    let value = DateUtils.ms(anniversaryDate)
                    Task {
                        await session.setTogetherDate(value)
                        showAnniversary = false
                        live.startTogetherActivity(togetherDateMs: value, partnerName: session.partnerName)
                        toast.success("纪念日已保存")
                    }
                } label: {
                    Text("保存")
                }
                .buttonStyle(PrimaryButtonStyle(color: accent))

                Spacer()
            }
            .padding(20)
            .background(AppBackground())
            .navigationTitle("纪念日")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("取消") { showAnniversary = false }
                }
            }
        }
    }

    // MARK: - 外观

    private var appearanceCard: some View {
        SectionCard(title: "外观与体验", icon: "paintpalette.fill") {
            VStack(alignment: .leading, spacing: 10) {
                Text("主题色")
                    .font(.system(size: 12))
                    .foregroundColor(.secondary)
                HStack(spacing: 12) {
                    ForEach(AppTheme.accentKeys, id: \.self) { key in
                        Button {
                            store.updateSettings { item in item.accentKey = key }
                            sync.requestSync()
                            Haptics.selection()
                        } label: {
                            Circle()
                                .fill(AppTheme.accent(for: key))
                                .frame(width: 30, height: 30)
                                .overlay(
                                    Circle()
                                        .stroke(Color.white, lineWidth: settings.accentKey == key ? 3 : 0)
                                )
                                .shadow(color: AppTheme.accent(for: key).opacity(0.5), radius: 5)
                        }
                        .buttonStyle(.plain)
                    }
                    Spacer()
                    Text(AppTheme.accentName(for: settings.accentKey))
                        .font(.system(size: 12))
                        .foregroundColor(.secondary)
                }
            }

            Toggle(isOn: Binding(
                get: { settings.inAppBannerEnabled },
                set: { value in
                    store.updateSettings { item in item.inAppBannerEnabled = value }
                    sync.requestSync()
                }
            )) {
                Text("收到消息时显示顶部横幅")
                    .font(.system(size: 14))
            }
            .tint(accent)

            Toggle(isOn: Binding(
                get: { settings.liveActivityEnabled },
                set: { value in
                    store.updateSettings { item in item.liveActivityEnabled = value }
                    sync.requestSync()
                    if !value { live.stop() }
                }
            )) {
                Text("启用灵动岛 / 锁屏实时活动")
                    .font(.system(size: 14))
            }
            .tint(accent)

            Text(live.supportDescription)
                .font(.system(size: 11))
                .foregroundColor(.secondary)
        }
    }

    // MARK: - 服务器与同步

    private var syncCard: some View {
        SectionCard(title: "服务器与同步", icon: "antenna.radiowaves.left.and.right", subtitle: sync.isSyncing ? "同步中" : "") {
            VStack(alignment: .leading, spacing: 6) {
                infoRow("服务器", device.serverURL.isEmpty ? "未设置" : device.serverURL)
                infoRow("服务端版本", session.meta?.serverVersion ?? "-")
                infoRow("同步状态", sync.statusText)
                infoRow("上次同步", sync.lastSyncAt > 0 ? DateUtils.relative(sync.lastSyncAt) : "从未")
                infoRow("同步内容", "火花 / 宠物 / 签到 / 愿望 / 加班记录")
                infoRow("永不上传", "记账、密码本")
            }

            if !sync.lastError.isEmpty {
                Text(sync.lastError)
                    .font(.system(size: 11))
                    .foregroundColor(AppTheme.red)
            }

            HStack(spacing: 10) {
                Button {
                    Task {
                        await SyncEngine.shared.syncNow()
                        toast.show(sync.statusText)
                    }
                } label: {
                    Label("立即同步", systemImage: "arrow.triangle.2.circlepath")
                }
                .buttonStyle(SoftButtonStyle(color: AppTheme.blue))

                Button {
                    showServerSheet = true
                } label: {
                    Label("服务器设置", systemImage: "server.rack")
                }
                .buttonStyle(SoftButtonStyle(color: accent))

                Button {
                    sync.resetSyncCursor()
                    toast.show("已重置同步进度，下次将全量拉取")
                } label: {
                    Label("重置同步", systemImage: "arrow.counterclockwise")
                }
                .buttonStyle(SoftButtonStyle(color: AppTheme.orange))
            }
        }
    }

    // MARK: - AI

    private var aiCard: some View {
        SectionCard(title: "AI 记账", icon: "wand.and.stars") {
            if let status = session.aiStatus {
                VStack(alignment: .leading, spacing: 6) {
                    infoRow("状态", status.available ? "可用" : (status.enabled ? "未配置 Key" : "已关闭"))
                    infoRow("模型", status.model.isEmpty ? "-" : status.model)
                    infoRow("今日用量", status.dailyLimit > 0 ? "\(status.usedToday) / \(status.dailyLimit)" : "不限")
                }
                Text(status.available
                     ? "DeepSeek 的 Key 保存在你的服务器后台，App 里不保存任何密钥。"
                     : "请让服务器管理员登录后台填写 DeepSeek API Key（见 docs/04-后台管理.md）。")
                    .font(.system(size: 11))
                    .foregroundColor(.secondary)
            } else {
                Text("暂时无法获取 AI 状态，请检查服务器连接。")
                    .font(.system(size: 12))
                    .foregroundColor(.secondary)
            }

            Button {
                Task {
                    await session.loadAIStatus()
                    toast.show("已刷新 AI 状态")
                }
            } label: {
                Label("刷新状态", systemImage: "arrow.clockwise")
            }
            .buttonStyle(SoftButtonStyle(color: AppTheme.purple))
        }
    }

    // MARK: - 数据

    private var dataCard: some View {
        SectionCard(title: "数据与隐私", icon: "lock.shield.fill") {
            VStack(alignment: .leading, spacing: 6) {
                infoRow("本机数据", "记账 \(store.state.ledger.count) 笔 · 密码本 \(store.vaultItems.count) 条 · 聊天缓存 \(store.state.messages.count) 条")
                infoRow("同步数据", "愿望 \(store.state.wishes.count) 个 · 签到 \(store.state.checkIns.count) 次 · 打卡 \(store.state.workLogs.count) 天")
            }

            ShareLink(item: store.exportJSON()) {
                Label("导出本机数据（JSON）", systemImage: "square.and.arrow.up")
            }
            .buttonStyle(SoftButtonStyle(color: AppTheme.blue))

            Button {
                showWipeConfirm = true
            } label: {
                Label("清空全部本地数据", systemImage: "trash")
            }
            .buttonStyle(SoftButtonStyle(color: AppTheme.red))
        }
    }

    // MARK: - 关于

    private var aboutCard: some View {
        SectionCard(title: "关于", icon: "info.circle") {
            VStack(alignment: .leading, spacing: 6) {
                infoRow("软件名称", AppInfo.displayName)
                infoRow("版本号", AppInfo.fullVersion)
                infoRow("构建号", AppInfo.build)
                infoRow("安装包类型", AppInfo.widgetExtensionEnabled ? "完整版（含灵动岛扩展）" : "轻量版（无扩展）")
                infoRow("灵动岛", live.isSupported ? "支持" : "不支持")
                infoRow("设备型号", AppInfo.deviceModel)
                infoRow("最低系统", "iOS 16.1")
            }

            if let meta = session.meta, !meta.announcement.isEmpty {
                VStack(alignment: .leading, spacing: 4) {
                    Text("服务器公告")
                        .font(.system(size: 12, weight: .semibold))
                    Text(meta.announcement)
                        .font(.system(size: 12))
                        .foregroundColor(.secondary)
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                .padding(10)
                .background(
                    RoundedRectangle(cornerRadius: 12, style: .continuous)
                        .fill(AppTheme.orange.opacity(0.1))
                )
            }

            if let meta = session.meta, meta.hasNewVersion, !meta.updateURL.isEmpty,
               let url = URL(string: meta.updateURL) {
                Link(destination: url) {
                    Label("发现新版本，去下载", systemImage: "arrow.down.circle")
                }
                .buttonStyle(SoftButtonStyle(color: AppTheme.mint))
            }
        }
    }

    private var logoutButton: some View {
        Button {
            showLogoutConfirm = true
        } label: {
            Text("退出登录")
        }
        .buttonStyle(PrimaryButtonStyle(color: AppTheme.grayButton))
        .padding(.top, 4)
    }

    // MARK: - 小工具

    private func infoRow(_ title: String, _ value: String) -> some View {
        HStack(alignment: .top, spacing: 8) {
            Text(title)
                .font(.system(size: 12, weight: .medium))
                .foregroundColor(.secondary)
                .frame(width: 76, alignment: .leading)
            Text(value)
                .font(.system(size: 12))
                .frame(maxWidth: .infinity, alignment: .leading)
        }
    }
}

/// 编辑昵称与头像
struct ProfileEditView: View {
    @EnvironmentObject var session: SessionManager
    @EnvironmentObject var toast: ToastCenter
    @Environment(\.dismiss) private var dismiss

    @State private var nickname = ""
    @State private var avatarEmoji = "🐻"
    @State private var showAvatarPicker = false
    @State private var oldPassword = ""
    @State private var newPassword = ""
    @State private var showPasswordSection = false

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(spacing: 16) {
                    VStack(spacing: 12) {
                        Button {
                            showAvatarPicker = true
                        } label: {
                            EmojiAvatarView(emoji: avatarEmoji, size: 80, ringColor: AppTheme.pink)
                        }
                        .buttonStyle(.plain)
                        Text("点上面的头像更换")
                            .font(.system(size: 11))
                            .foregroundColor(.secondary)
                        InputField(title: "昵称", placeholder: "TA 看到的名字", text: $nickname)
                    }
                    .card()

                    Button {
                        Task {
                            await session.updateProfile(
                                nickname: nickname.trimmingCharacters(in: .whitespaces),
                                avatarEmoji: avatarEmoji
                            )
                            toast.success(session.infoText.isEmpty ? "已保存" : session.infoText)
                            dismiss()
                        }
                    } label: {
                        Text("保存资料")
                    }
                    .buttonStyle(PrimaryButtonStyle(color: AppTheme.pink))

                    SectionCard(title: "修改密码", icon: "key.fill") {
                        if showPasswordSection {
                            InputField(title: "原密码", placeholder: "当前密码", text: $oldPassword, isSecure: true)
                            InputField(title: "新密码", placeholder: "至少 6 位", text: $newPassword, isSecure: true)
                            Button {
                                changePassword()
                            } label: {
                                Text("确认修改")
                            }
                            .buttonStyle(PrimaryButtonStyle(
                                color: AppTheme.blue,
                                enabled: oldPassword.count >= 6 && newPassword.count >= 6
                            ))
                            .disabled(oldPassword.count < 6 || newPassword.count < 6)
                        } else {
                            Button {
                                showPasswordSection = true
                            } label: {
                                Label("修改登录密码", systemImage: "lock.rotation")
                            }
                            .buttonStyle(SoftButtonStyle(color: AppTheme.blue))
                        }
                    }
                }
                .padding(18)
            }
            .background(AppBackground())
            .navigationTitle("编辑资料")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("关闭") { dismiss() }
                }
            }
            .sheet(isPresented: $showAvatarPicker) {
                AvatarPickerView(selected: $avatarEmoji).appEnvironment()
            }
            .onAppear {
                nickname = session.myName
                avatarEmoji = session.myEmoji
            }
        }
    }

    private func changePassword() {
        Task {
            do {
                try await APIClient.shared.changePassword(old: oldPassword, new: newPassword)
                await MainActor.run {
                    toast.success("密码已修改，请用新密码重新登录")
                    oldPassword = ""
                    newPassword = ""
                    showPasswordSection = false
                }
            } catch let error as APIError {
                await MainActor.run {
                    toast.error(error.message)
                }
            } catch {
                await MainActor.run {
                    toast.error("修改失败")
                }
            }
        }
    }
}
