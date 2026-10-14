import SwiftUI
import UIKit

/// 我的：只有资料、情侣状态摘要、设置入口、退出登录
/// 具体设置项都在 SettingsView 里
struct ProfileView: View {
    @EnvironmentObject var session: SessionManager
    @EnvironmentObject var store: LocalStore
    @EnvironmentObject var toast: ToastCenter
    @EnvironmentObject var device: DeviceConfig

    @State private var showProfileEdit = false
    @State private var showLogoutConfirm = false

    private var settings: UserSettings { store.state.settings }
    private var accent: Color { AppTheme.accent(for: settings.accentKey) }

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(spacing: 16) {
                    headerCard
                    statusCard
                    settingsLink
                    logoutButton
                }
                .padding(.horizontal, 16)
                .padding(.vertical, 16)
            }
            .background(AppBackground())
            .navigationTitle("我的")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                // 右上角齿轮：另一个进设置的入口
                ToolbarItem(placement: .navigationBarTrailing) {
                    NavigationLink {
                        SettingsView()
                    } label: {
                        Image(systemName: "gearshape.fill")
                            .foregroundColor(accent)
                    }
                    .accessibilityLabel("设置")
                }
            }
            .sheet(isPresented: $showProfileEdit) {
                ProfileEditView().appEnvironment()
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

    // MARK: - 情侣状态摘要

    private var statusCard: some View {
        SectionCard(
            title: "情侣状态",
            icon: "heart.fill",
            subtitle: session.isPaired ? "已绑定" : "未绑定"
        ) {
            HStack(spacing: 12) {
                EmojiAvatarView(emoji: session.myEmoji, size: 40, ringColor: accent)
                Text("💞").font(.system(size: 18))
                EmojiAvatarView(
                    emoji: session.partner?.emoji ?? "🐻",
                    size: 40,
                    ringColor: session.isPaired ? AppTheme.purple : Color.gray.opacity(0.5)
                )
                VStack(alignment: .leading, spacing: 2) {
                    Text(session.isPaired ? session.partnerName : "还没有绑定伴侣")
                        .font(.system(size: 14, weight: .medium))
                    Text(statusDetail)
                        .font(.system(size: 11))
                        .foregroundColor(.secondary)
                }
                Spacer()
            }
        }
    }

    /// 摘要文案：只读，绑定/解绑等操作都在「设置」里
    private var statusDetail: String {
        if session.isPaired {
            return session.togetherDays > 0 ? "在一起 \(session.togetherDays) 天" : "还没设置纪念日"
        }
        return "去「设置」里创建或输入邀请码绑定"
    }

    // MARK: - 设置入口

    private var settingsLink: some View {
        NavigationLink {
            SettingsView()
        } label: {
            HStack(spacing: 12) {
                Image(systemName: "gearshape.fill")
                    .font(.system(size: 16, weight: .semibold))
                    .foregroundColor(accent)
                    .frame(width: 30)
                Text("设置")
                    .font(.system(size: 15, weight: .medium))
                    .foregroundColor(.primary)
                Spacer()
                Image(systemName: "chevron.right")
                    .font(.system(size: 12, weight: .semibold))
                    .foregroundColor(.secondary)
            }
            .card()
        }
        .buttonStyle(.plain)
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
