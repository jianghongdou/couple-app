import Foundation
import Combine

/// 会话管理：登录状态、情侣信息、服务端元信息
final class SessionManager: ObservableObject {
    static let shared = SessionManager()

    @Published var user: UserProfile?
    @Published var couple: CoupleInfo?
    @Published var isLoggedIn = false
    @Published var isWorking = false
    @Published var errorText = ""
    @Published var infoText = ""
    @Published var meta: ServerMeta?
    @Published var aiStatus: AIStatus?

    private init() {}

    // MARK: - 便捷属性

    var myId: String { user?.id ?? DeviceConfig.shared.userId }

    var myName: String {
        if let user = user { return user.displayName }
        return DeviceConfig.shared.nickname.isEmpty ? "我" : DeviceConfig.shared.nickname
    }

    var myEmoji: String {
        if let user = user { return user.emoji }
        return DeviceConfig.shared.avatarEmoji.isEmpty ? "🐻" : DeviceConfig.shared.avatarEmoji
    }

    var partner: UserProfile? {
        guard let couple = couple else { return nil }
        return couple.partner(of: myId)
    }

    var partnerName: String { partner?.displayName ?? "TA" }

    var isPaired: Bool { (couple?.members.count ?? 0) >= 2 }

    var inviteCode: String { couple?.inviteCode ?? "" }

    /// 在一起的天数（优先服务端设置）
    var togetherDate: Double {
        if let date = couple?.togetherDate, date > 0 { return date }
        return LocalStore.shared.state.settings.togetherDate
    }

    var togetherDays: Int { togetherDate > 0 ? DateUtils.togetherDays(togetherDate) : 0 }

    // MARK: - 启动

    /// App 启动时调用：有 token 就拉取资料
    func bootstrap() async {
        if DeviceConfig.shared.token.isEmpty {
            if DeviceConfig.shared.userId.hasPrefix("local_") {
                await MainActor.run {
                    self.restoreLocalUser()
                    self.isLoggedIn = true
                }
            } else {
                await MainActor.run { self.isLoggedIn = false }
            }
            return
        }
        await refreshAll()
    }

    /// 冷启动时恢复单机模式的本地用户
    private func restoreLocalUser() {
        var profile = UserProfile()
        profile.id = DeviceConfig.shared.userId
        profile.username = DeviceConfig.shared.username.isEmpty ? DeviceConfig.shared.userId : DeviceConfig.shared.username
        profile.nickname = DeviceConfig.shared.nickname
        profile.avatarEmoji = DeviceConfig.shared.avatarEmoji.isEmpty ? "🐻" : DeviceConfig.shared.avatarEmoji
        profile.role = "local"
        self.user = profile
        self.couple = nil
    }

    func refreshAll() async {
        guard !DeviceConfig.shared.token.isEmpty else { return }
        await MainActor.run { self.isWorking = true }
        do {
            let result = try await APIClient.shared.fetchMe()
            await MainActor.run {
                self.apply(result)
                self.errorText = ""
                self.isWorking = false
            }
            await loadMeta()
            await loadAIStatus()
            startServices()
        } catch let error as APIError {
            await MainActor.run {
                self.isWorking = false
                if error.isUnauthorized {
                    self.forceLogout(reason: "登录已过期，请重新登录")
                } else {
                    self.errorText = error.message
                    // 网络不通时仍然让用户看到本地数据
                    self.isLoggedIn = !DeviceConfig.shared.token.isEmpty
                }
            }
        } catch {
            await MainActor.run {
                self.isWorking = false
                self.errorText = "连接服务器失败"
                self.isLoggedIn = !DeviceConfig.shared.token.isEmpty
            }
        }
    }

    private func apply(_ result: AuthResult) {
        if let user = result.user {
            self.user = user
            DeviceConfig.shared.userId = user.id
            DeviceConfig.shared.username = user.username
            DeviceConfig.shared.nickname = user.nickname
            DeviceConfig.shared.avatarEmoji = user.emoji
            if !user.coupleId.isEmpty {
                DeviceConfig.shared.coupleId = user.coupleId
            }
        }
        if let couple = result.couple {
            self.couple = couple
            DeviceConfig.shared.coupleId = couple.id
            if couple.togetherDate > 0 {
                LocalStore.shared.updateSettings { settings in
                    settings.togetherDate = couple.togetherDate
                }
            }
        } else {
            self.couple = nil
            DeviceConfig.shared.coupleId = ""
        }
        self.isLoggedIn = true
    }

    // MARK: - 本地单机模式（不连服务器）

    /// 是否处于单机模式
    var isLocalMode: Bool {
        DeviceConfig.shared.token.isEmpty && DeviceConfig.shared.userId.hasPrefix("local_")
    }

    /// 不登录也能用：账本、密码本、宠物、签到、愿望、加班全部可用
    func startLocalMode(nickname: String, avatarEmoji: String) {
        let identifier = "local_" + String(UUID().uuidString.prefix(8)).lowercased()
        let name = nickname.trimmingCharacters(in: .whitespacesAndNewlines)
        let finalName = name.isEmpty ? "我" : String(name.prefix(12))

        var profile = UserProfile()
        profile.id = identifier
        profile.username = identifier
        profile.nickname = finalName
        profile.avatarEmoji = avatarEmoji
        profile.role = "local"

        DeviceConfig.shared.userId = identifier
        DeviceConfig.shared.username = identifier
        DeviceConfig.shared.nickname = finalName
        DeviceConfig.shared.avatarEmoji = avatarEmoji
        DeviceConfig.shared.coupleId = ""
        DeviceConfig.shared.token = ""

        self.user = profile
        self.couple = nil
        self.isLoggedIn = true
        self.errorText = ""
        self.infoText = "已进入单机模式，账本与密码本可以正常使用"
    }

    // MARK: - 登录 / 注册

    func login(username: String, password: String) async -> Bool {
        await MainActor.run { self.isWorking = true; self.errorText = "" }
        do {
            let result = try await APIClient.shared.login(username: username, password: password)
            guard !result.token.isEmpty else {
                await MainActor.run {
                    self.isWorking = false
                    self.errorText = "服务器没有返回登录凭证"
                }
                return false
            }
            DeviceConfig.shared.token = result.token
            await MainActor.run {
                self.apply(result)
                self.isWorking = false
                self.infoText = "欢迎回来，\(self.myName)"
            }
            await loadMeta()
            await loadAIStatus()
            startServices()
            return true
        } catch let error as APIError {
            await MainActor.run {
                self.isWorking = false
                self.errorText = error.message
            }
            return false
        } catch {
            await MainActor.run {
                self.isWorking = false
                self.errorText = "登录失败"
            }
            return false
        }
    }

    func register(username: String, password: String, nickname: String, avatarEmoji: String, inviteCode: String) async -> Bool {
        await MainActor.run { self.isWorking = true; self.errorText = "" }
        do {
            let result = try await APIClient.shared.register(
                username: username,
                password: password,
                nickname: nickname,
                avatarEmoji: avatarEmoji,
                inviteCode: inviteCode
            )
            guard !result.token.isEmpty else {
                await MainActor.run {
                    self.isWorking = false
                    self.errorText = "服务器没有返回登录凭证"
                }
                return false
            }
            DeviceConfig.shared.token = result.token
            await MainActor.run {
                self.apply(result)
                self.isWorking = false
                self.infoText = "注册成功，欢迎使用"
            }
            await loadMeta()
            await loadAIStatus()
            startServices()
            return true
        } catch let error as APIError {
            await MainActor.run {
                self.isWorking = false
                self.errorText = error.message
            }
            return false
        } catch {
            await MainActor.run {
                self.isWorking = false
                self.errorText = "注册失败"
            }
            return false
        }
    }

    func logout() {
        ChatService.shared.stop()
        Task { @MainActor in AIChatService.shared.reset() }   // AIChatService 是 @MainActor，跨隔离调用需要切回主线程
        SyncEngine.shared.stopAutoSync()
        DeviceConfig.shared.clearAccount()
        LocalStore.shared.resetAfterLogout(keepLedger: true, keepVault: true)
        user = nil
        couple = nil
        isLoggedIn = false
        infoText = "已退出登录，本地记账与密码本已保留"
    }

    func forceLogout(reason: String) {
        ChatService.shared.stop()
        Task { @MainActor in AIChatService.shared.reset() }   // 同上：切回主线程再重置 AI 会话状态
        SyncEngine.shared.stopAutoSync()
        DeviceConfig.shared.clearAccount()
        user = nil
        couple = nil
        isLoggedIn = false
        errorText = reason
    }

    // MARK: - 情侣绑定

    func createCouple() async -> Bool {
        await MainActor.run { self.isWorking = true; self.errorText = "" }
        do {
            let info = try await APIClient.shared.createCouple()
            await MainActor.run {
                self.couple = info
                if let info = info {
                    DeviceConfig.shared.coupleId = info.id
                    self.infoText = "情侣空间已创建，把邀请码发给 TA 吧"
                }
                self.isWorking = false
            }
            startServices()
            return true
        } catch let error as APIError {
            await MainActor.run {
                self.isWorking = false
                self.errorText = error.message
            }
            return false
        } catch {
            await MainActor.run {
                self.isWorking = false
                self.errorText = "创建失败"
            }
            return false
        }
    }

    func joinCouple(inviteCode: String) async -> Bool {
        let code = inviteCode.trimmingCharacters(in: .whitespacesAndNewlines).uppercased()
        guard !code.isEmpty else {
            errorText = "请输入邀请码"
            return false
        }
        await MainActor.run { self.isWorking = true; self.errorText = "" }
        do {
            let info = try await APIClient.shared.joinCouple(inviteCode: code)
            await MainActor.run {
                self.couple = info
                if let info = info {
                    DeviceConfig.shared.coupleId = info.id
                    self.infoText = "绑定成功，你们已经在一起啦"
                }
                self.isWorking = false
            }
            startServices()
            return true
        } catch let error as APIError {
            await MainActor.run {
                self.isWorking = false
                self.errorText = error.message
            }
            return false
        } catch {
            await MainActor.run {
                self.isWorking = false
                self.errorText = "绑定失败"
            }
            return false
        }
    }

    func leaveCouple() async {
        do {
            try await APIClient.shared.leaveCouple()
            await MainActor.run {
                self.couple = nil
                DeviceConfig.shared.coupleId = ""
                self.infoText = "已解除绑定"
            }
            LocalStore.shared.update { state in
                state.messages = []
                state.syncStamp = 0
            }
        } catch let error as APIError {
            await MainActor.run { self.errorText = error.message }
        } catch {
            await MainActor.run { self.errorText = "解除绑定失败" }
        }
    }

    func setTogetherDate(_ ms: Double) async {
        LocalStore.shared.updateSettings { settings in
            settings.togetherDate = ms
        }
        do {
            let info = try await APIClient.shared.updateCouple(togetherDate: ms, anniversaryTitle: nil)
            await MainActor.run {
                if let info = info { self.couple = info }
                self.infoText = "纪念日已更新"
            }
        } catch {
            await MainActor.run {
                self.infoText = "纪念日已保存在本机（同步到服务器失败）"
            }
        }
    }

    func updateProfile(nickname: String, avatarEmoji: String) async {
        do {
            let updated = try await APIClient.shared.updateProfile(nickname: nickname, avatarEmoji: avatarEmoji)
            await MainActor.run {
                if let updated = updated {
                    self.user = updated
                    DeviceConfig.shared.nickname = updated.nickname
                    DeviceConfig.shared.avatarEmoji = updated.emoji
                }
                self.infoText = "资料已更新"
            }
        } catch let error as APIError {
            await MainActor.run { self.errorText = error.message }
        } catch {
            await MainActor.run { self.errorText = "更新失败" }
        }
    }

    // MARK: - 元信息

    func loadMeta() async {
        do {
            let value = try await APIClient.shared.fetchMeta()
            await MainActor.run {
                self.meta = value
                SyncEngine.shared.isEnabled = value.syncEnabled
            }
        } catch {
            // 元信息失败不影响使用
        }
    }

    func loadAIStatus() async {
        do {
            let value = try await APIClient.shared.fetchAIStatus()
            await MainActor.run { self.aiStatus = value }
        } catch {
            await MainActor.run { self.aiStatus = nil }
        }
    }

    // MARK: - 服务启动

    func startServices() {
        guard !DeviceConfig.shared.token.isEmpty else { return }
        ChatService.shared.start()
        SyncEngine.shared.startAutoSync()
        Task { await EmojiService.shared.refresh() }
    }
}
