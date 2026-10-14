import SwiftUI

/// 登录 / 注册
///
/// 说明：页面默认不再展示服务器地址填写。
/// - 右上角有一个不显眼的小齿轮，点开就是原来的 ServerSetupView（换服务器时的兜底入口）
/// - 页面出现时会对 /api/health 做一次**静默**检测，只有连不上时才在表单上方
///   显示一小块「地址修正区」
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

    /// 静默健康检查：只做一次
    @State private var didCheckServer = false
    /// true 时才显示表单上方的地址修正区
    @State private var showFixCard = false
    /// 地址修正区里的输入框（默认已由 AppInfo.defaultServerURL 预置）
    @State private var fixText = ""
    /// 地址修正区的检测结果文案
    @State private var fixDetail = ""
    @State private var isTesting = false
    @State private var isSavingFix = false

    var body: some View {
        ScrollView {
            VStack(spacing: 18) {
                header
                if showFixCard {
                    addressFixCard
                }
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
            checkServerSilently()
        }
    }

    // MARK: - 顶部

    private var header: some View {
        VStack(spacing: 10) {
            // 右上角小齿轮：换服务器时的兜底自救入口，平时不显眼
            HStack {
                Spacer()
                Button {
                    showServerSheet = true
                } label: {
                    Image(systemName: "gearshape")
                        .font(.system(size: 14))
                        .foregroundColor(.secondary)
                        .frame(width: 32, height: 32)
                        .background(Circle().fill(Color.gray.opacity(0.12)))
                }
                .buttonStyle(.plain)
                .accessibilityLabel("服务器设置")
            }
            .frame(maxWidth: .infinity)

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

    // MARK: - 地址修正区（只在连不上服务器时出现）

    private var addressFixCard: some View {
        VStack(alignment: .leading, spacing: 10) {
            HStack(spacing: 8) {
                Image(systemName: "exclamationmark.triangle.fill")
                    .foregroundColor(AppTheme.orange)
                Text("连接不上服务器，请检查地址")
                    .font(.system(size: 14, weight: .semibold))
                Spacer()
            }

            Text("当前地址：\(device.serverURL.isEmpty ? "未设置" : device.serverURL)")
                .font(.system(size: 12, design: .monospaced))
                .foregroundColor(.secondary)
                .lineLimit(2)

            TextField("http://192.168.1.10:8787", text: $fixText)
                .textInputAutocapitalization(.never)
                .autocorrectionDisabled(true)
                .keyboardType(.URL)
                .font(.system(size: 14, design: .monospaced))
                .padding(.horizontal, 12)
                .padding(.vertical, 10)
                .background(
                    RoundedRectangle(cornerRadius: 12, style: .continuous)
                        .fill(Color.gray.opacity(0.1))
                )

            if !fixDetail.isEmpty {
                HStack(alignment: .top, spacing: 6) {
                    Image(systemName: fixDetail.hasPrefix("连接成功") ? "checkmark.circle.fill" : "xmark.circle.fill")
                        .foregroundColor(fixDetail.hasPrefix("连接成功") ? AppTheme.mint : AppTheme.red)
                    Text(fixDetail)
                        .font(.system(size: 12))
                        .foregroundColor(.secondary)
                    Spacer(minLength: 0)
                }
            }

            HStack(spacing: 10) {
                Button {
                    testFixAddress()
                } label: {
                    HStack(spacing: 6) {
                        if isTesting { ProgressView().tint(AppTheme.blue) }
                        Text("测试连接")
                    }
                }
                .buttonStyle(SoftButtonStyle(color: AppTheme.blue))
                .disabled(isTesting)

                Button {
                    saveFixAddress()
                } label: {
                    Label("保存地址", systemImage: "tray.and.arrow.down")
                }
                .buttonStyle(SoftButtonStyle(color: AppTheme.pink))
                .disabled(isSavingFix)
            }

            Text("也可以点右上角齿轮打开完整的「服务器设置」。")
                .font(.system(size: 11))
                .foregroundColor(.secondary)
        }
        .frame(maxWidth: .infinity, alignment: .leading)
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

            Text("提示：两台手机注册后输入对方的邀请码即可绑定，不需要手动填写任何服务器信息。")
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

    // MARK: - 静默健康检测

    /// 进页面时静默探一次 /api/health：
    /// - 地址为空（本机刚清过配置）不报错，直接跳过
    /// - 只有检测失败时才把地址修正区显示出来，不弹任何错误提示、不写 session.errorText
    private func checkServerSilently() {
        guard !didCheckServer else { return }
        didCheckServer = true

        guard !device.serverURL.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty else {
            return
        }

        fixText = device.serverURL
        Task {
            let result = await APIClient.shared.ping()
            await MainActor.run {
                if result.ok {
                    showFixCard = false
                } else {
                    fixDetail = result.message
                    withAnimation(.easeInOut(duration: 0.2)) {
                        showFixCard = true
                    }
                }
            }
        }
    }

    // MARK: - 地址修正区动作

    private func testFixAddress() {
        let normalized = DeviceConfig.normalizeURL(fixText)
        guard !normalized.isEmpty else {
            fixDetail = "请先填写服务器地址"
            return
        }
        isTesting = true
        Task {
            let previous = device.serverURL
            device.serverURL = normalized
            let result = await APIClient.shared.ping()
            device.serverURL = previous
            await MainActor.run {
                isTesting = false
                fixDetail = result.message
                if result.ok {
                    Haptics.success()
                } else {
                    Haptics.error()
                }
            }
        }
    }

    private func saveFixAddress() {
        let normalized = DeviceConfig.normalizeURL(fixText)
        guard !normalized.isEmpty else {
            fixDetail = "请先填写服务器地址"
            return
        }
        device.serverURL = normalized
        isSavingFix = true
        Task {
            await session.loadMeta()
            let result = await APIClient.shared.ping()
            await MainActor.run {
                isSavingFix = false
                if result.ok {
                    toast.success("服务器地址已保存")
                    withAnimation(.easeInOut(duration: 0.2)) {
                        showFixCard = false
                    }
                } else {
                    fixDetail = result.message
                    toast.error("地址已保存，但还是连不上")
                }
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
            bullet("聊天与情侣数据：保存在你们自己的服务器上")
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
