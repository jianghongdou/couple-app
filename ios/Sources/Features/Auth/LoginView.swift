import SwiftUI

/// 登录 / 注册
struct LoginView: View {
    @EnvironmentObject var session: SessionManager
    @EnvironmentObject var device: DeviceConfig
    @EnvironmentObject var toast: ToastCenter

    @State private var isRegister = false
    @State private var username = ""
    @State private var password = ""
    @State private var nickname = ""
    @State private var avatarEmoji = "🐻"
    @State private var inviteCode = ""
    @State private var showServerSheet = false
    @State private var showAvatarPicker = false
    @State private var showLocalModeSheet = false

    var body: some View {
        ScrollView {
            VStack(spacing: 18) {
                header
                serverCard
                formCard
                localModeCard
                footer
            }
            .padding(.horizontal, 18)
            .padding(.top, 24)
            .padding(.bottom, 40)
        }
        .scrollDismissesKeyboard(.interactively)
        .sheet(isPresented: $showServerSheet) {
            ServerSetupView().appEnvironment()
        }
        .sheet(isPresented: $showAvatarPicker) {
            AvatarPickerView(selected: $avatarEmoji).appEnvironment()
        }
        .sheet(isPresented: $showLocalModeSheet) {
            LocalModeSetupView().appEnvironment()
        }
        .onAppear {
            if username.isEmpty { username = device.username }
        }
    }

    // MARK: - 顶部

    private var header: some View {
        VStack(spacing: 10) {
            ZStack {
                Circle()
                    .fill(AppTheme.brandGradient)
                    .frame(width: 88, height: 88)
                    .shadow(color: AppTheme.pink.opacity(0.35), radius: 16, y: 8)
                Text("💞")
                    .font(.system(size: 40))
            }
            Text(AppInfo.displayName)
                .font(.system(size: 26, weight: .bold, design: .rounded))
            Text("只属于你们两个人的小空间")
                .font(.system(size: 13))
                .foregroundColor(.secondary)
            TagChip(text: AppInfo.fullVersion, color: AppTheme.purple)
        }
        .frame(maxWidth: .infinity)
    }

    // MARK: - 服务器

    private var serverCard: some View {
        VStack(alignment: .leading, spacing: 10) {
            HStack(spacing: 8) {
                Image(systemName: "server.rack")
                    .foregroundColor(AppTheme.blue)
                Text("自建服务器")
                    .font(.system(size: 15, weight: .semibold))
                Spacer()
                Button("配置") {
                    showServerSheet = true
                }
                .buttonStyle(SoftButtonStyle(color: AppTheme.blue))
            }

            Text(device.serverURL.isEmpty ? "还没有填写服务器地址" : device.serverURL)
                .font(.system(size: 13, design: .monospaced))
                .foregroundColor(.secondary)
                .lineLimit(2)

            Text("聊天、双人同步都需要你自己的服务器。没有服务器也能用：先注册后仅本机使用（单机模式）。")
                .font(.system(size: 11))
                .foregroundColor(.secondary)
        }
        .card()
    }

    // MARK: - 表单

    private var formCard: some View {
        VStack(spacing: 14) {
            Picker("", selection: $isRegister) {
                Text("登录").tag(false)
                Text("注册新账号").tag(true)
            }
            .pickerStyle(.segmented)

            InputField(title: "账号", placeholder: "3-32 位字母或数字", text: $username)
                .textInputAutocapitalization(.never)

            InputField(title: "密码", placeholder: "至少 6 位", text: $password, isSecure: true)

            if isRegister {
                HStack(spacing: 12) {
                    Button {
                        showAvatarPicker = true
                    } label: {
                        HStack(spacing: 8) {
                            Text(avatarEmoji).font(.system(size: 22))
                            Text("选头像")
                                .font(.system(size: 13))
                                .foregroundColor(.secondary)
                        }
                        .padding(.horizontal, 12)
                        .padding(.vertical, 10)
                        .background(
                            RoundedRectangle(cornerRadius: 12, style: .continuous)
                                .fill(Color.gray.opacity(0.1))
                        )
                    }
                    .buttonStyle(.plain)

                    Spacer()

                    Text(nickname.isEmpty ? "昵称选填" : "昵称：\(nickname)")
                        .font(.system(size: 12))
                        .foregroundColor(.secondary)
                }

                InputField(title: "昵称（可选）", placeholder: "TA 看到的名字", text: $nickname)

                InputField(title: "邀请码（可选）", placeholder: "直接绑定对方的邀请码", text: $inviteCode)
                    .textInputAutocapitalization(.characters)
            }

            if !session.errorText.isEmpty {
                HStack(spacing: 6) {
                    Image(systemName: "exclamationmark.circle.fill")
                        .foregroundColor(AppTheme.red)
                    Text(session.errorText)
                        .font(.system(size: 12))
                        .foregroundColor(AppTheme.red)
                    Spacer()
                }
            }

            Button {
                submit()
            } label: {
                HStack(spacing: 8) {
                    if session.isWorking {
                        ProgressView().tint(.white)
                    }
                    Text(isRegister ? "注册并进入" : "登录")
                }
            }
            .buttonStyle(PrimaryButtonStyle(
                color: AppTheme.pink,
                enabled: !session.isWorking && canSubmit
            ))
            .disabled(session.isWorking || !canSubmit)

            Text("提示：两台手机要互通，请确保都填写同一个服务器地址。")
                .font(.system(size: 11))
                .foregroundColor(.secondary)
                .frame(maxWidth: .infinity, alignment: .leading)
        }
        .card()
    }

    private var canSubmit: Bool {
        !username.trimmingCharacters(in: .whitespaces).isEmpty && password.count >= 6
    }

    private func submit() {
        Haptics.tap()
        Task {
            let ok: Bool
            if isRegister {
                ok = await session.register(
                    username: username.trimmingCharacters(in: .whitespaces),
                    password: password,
                    nickname: nickname,
                    avatarEmoji: avatarEmoji,
                    inviteCode: inviteCode
                )
            } else {
                ok = await session.login(
                    username: username.trimmingCharacters(in: .whitespaces),
                    password: password
                )
            }
            if ok {
                toast.success(session.infoText.isEmpty ? "登录成功" : session.infoText)
            }
        }
    }

    // MARK: - 单机模式

    private var localModeCard: some View {
        VStack(alignment: .leading, spacing: 10) {
            HStack(spacing: 8) {
                Image(systemName: "iphone")
                    .foregroundColor(AppTheme.mint)
                Text("暂时没有服务器？")
                    .font(.system(size: 15, weight: .semibold))
                Spacer()
            }
            Text("可以不登录，直接在本机使用：账本、密码本、宠物、签到、愿望、加班工资、灵动岛全都可用。只有聊天和两台手机同步需要服务器。")
                .font(.system(size: 11))
                .foregroundColor(.secondary)

            Button {
                showLocalModeSheet = true
            } label: {
                Label("本机单机使用", systemImage: "play.circle")
            }
            .buttonStyle(SoftButtonStyle(color: AppTheme.mint))
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .card()
    }

    // MARK: - 底部说明

    private var footer: some View {
        VStack(alignment: .leading, spacing: 8) {
            Text("关于你的数据")
                .font(.system(size: 14, weight: .semibold))
            bullet("记账与密码本：只保存在本机，永远不会上传")
            bullet("聊天与情侣数据：保存在你自己的服务器上")
            bullet("AI 记账：只把文字发给服务器转成结构化数据，账单原文不落库")
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .card()
    }

    private func bullet(_ text: String) -> some View {
        HStack(alignment: .top, spacing: 6) {
            Text("•").foregroundColor(AppTheme.pink)
            Text(text)
                .font(.system(size: 12))
                .foregroundColor(.secondary)
            Spacer(minLength: 0)
        }
    }
}
