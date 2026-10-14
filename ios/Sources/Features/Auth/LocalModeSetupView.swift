import SwiftUI

/// 单机模式设置：起个名字、选个头像就能直接用
struct LocalModeSetupView: View {
    @EnvironmentObject var session: SessionManager
    @EnvironmentObject var toast: ToastCenter
    @Environment(\.dismiss) private var dismiss

    @State private var nickname = ""
    @State private var avatarEmoji = "🐻"
    @State private var showAvatarPicker = false

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(spacing: 18) {
                    VStack(spacing: 10) {
                        Text("📱").font(.system(size: 54))
                        Text("单机模式")
                            .font(.system(size: 20, weight: .bold))
                        Text("数据全部保存在这台手机上，不需要服务器")
                            .font(.system(size: 12))
                            .foregroundColor(.secondary)
                    }
                    .padding(.top, 16)

                    VStack(spacing: 12) {
                        Button {
                            showAvatarPicker = true
                        } label: {
                            EmojiAvatarView(emoji: avatarEmoji, size: 72, ringColor: AppTheme.mint)
                        }
                        .buttonStyle(.plain)

                        InputField(title: "你的称呼", placeholder: "例如：小明", text: $nickname)
                    }
                    .card()

                    SectionCard(title: "单机模式下可用", icon: "checkmark.circle.fill") {
                        VStack(alignment: .leading, spacing: 6) {
                            row("✅", "AI 记账、加班工资统计（账目只存本机）")
                            row("✅", "密码本（AES-GCM 加密 + Face ID）")
                            row("✅", "宠物养成、签到、愿望、火花")
                            row("✅", "首页实时卡片（在一起天数 / 加班计时 / 火花）")
                            row("⚠️", "聊天与两台手机同步需要服务器（之后可以随时登录切换）")
                        }
                    }

                    Button {
                        session.startLocalMode(nickname: nickname, avatarEmoji: avatarEmoji)
                        toast.success("已进入单机模式")
                        dismiss()
                    } label: {
                        Text("开始使用")
                    }
                    .buttonStyle(PrimaryButtonStyle(color: AppTheme.mint))

                    Text("以后想连服务器：我的 → 退出登录 → 回到登录页直接登录即可（服务器地址已内置，不用填）。本机的账本与密码本会保留。")
                        .font(.system(size: 11))
                        .foregroundColor(.secondary)
                        .multilineTextAlignment(.center)
                }
                .padding(18)
            }
            .background(AppBackground())
            .navigationTitle("本机使用")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("取消") { dismiss() }
                }
            }
            .sheet(isPresented: $showAvatarPicker) {
                AvatarPickerView(selected: $avatarEmoji).appEnvironment()
            }
        }
    }

    private func row(_ mark: String, _ text: String) -> some View {
        HStack(alignment: .top, spacing: 6) {
            Text(mark).font(.system(size: 12))
            Text(text)
                .font(.system(size: 12))
                .foregroundColor(.secondary)
            Spacer(minLength: 0)
        }
    }
}
