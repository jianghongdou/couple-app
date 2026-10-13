import Foundation
import Combine

/// 设备本地配置（只存这台手机，不参与同步）
final class DeviceConfig: ObservableObject {
    static let shared = DeviceConfig()

    private let defaults = UserDefaults.standard

    @Published var serverURL: String {
        didSet { defaults.set(serverURL, forKey: "serverURL") }
    }
    @Published var userId: String {
        didSet { defaults.set(userId, forKey: "userId") }
    }
    @Published var username: String {
        didSet { defaults.set(username, forKey: "username") }
    }
    @Published var nickname: String {
        didSet { defaults.set(nickname, forKey: "nickname") }
    }
    @Published var avatarEmoji: String {
        didSet { defaults.set(avatarEmoji, forKey: "avatarEmoji") }
    }
    @Published var coupleId: String {
        didSet { defaults.set(coupleId, forKey: "coupleId") }
    }
    @Published var onboardingDone: Bool {
        didSet { defaults.set(onboardingDone, forKey: "onboardingDone") }
    }
    @Published var hasVault: Bool {
        didSet { defaults.set(hasVault, forKey: "hasVault") }
    }
    @Published var lastSeenVersion: String {
        didSet { defaults.set(lastSeenVersion, forKey: "lastSeenVersion") }
    }
    @Published var acceptedDisclaimer: Bool {
        didSet { defaults.set(acceptedDisclaimer, forKey: "acceptedDisclaimer") }
    }
    /// 灵动岛活动 id（本机记录，用于恢复）
    @Published var liveActivityId: String {
        didSet { defaults.set(liveActivityId, forKey: "liveActivityId") }
    }

    private init() {
        self.serverURL = defaults.string(forKey: "serverURL") ?? AppInfo.defaultServerURL
        self.userId = defaults.string(forKey: "userId") ?? ""
        self.username = defaults.string(forKey: "username") ?? ""
        self.nickname = defaults.string(forKey: "nickname") ?? ""
        self.avatarEmoji = defaults.string(forKey: "avatarEmoji") ?? "🐻"
        self.coupleId = defaults.string(forKey: "coupleId") ?? ""
        self.onboardingDone = defaults.bool(forKey: "onboardingDone")
        self.hasVault = defaults.bool(forKey: "hasVault")
        self.lastSeenVersion = defaults.string(forKey: "lastSeenVersion") ?? ""
        self.acceptedDisclaimer = defaults.bool(forKey: "acceptedDisclaimer")
        self.liveActivityId = defaults.string(forKey: "liveActivityId") ?? ""
    }

    /// 登录 token 放 Keychain
    var token: String {
        get { Keychain.get("token") ?? "" }
        set {
            if newValue.isEmpty {
                Keychain.remove("token")
            } else {
                Keychain.set(newValue, for: "token")
            }
        }
    }

    var isServerConfigured: Bool {
        !serverURL.trimmingCharacters(in: .whitespaces).isEmpty
    }

    /// 规范化用户输入的服务器地址
    static func normalizeURL(_ raw: String) -> String {
        var text = raw.trimmingCharacters(in: .whitespacesAndNewlines)
        if text.isEmpty { return "" }
        if !text.lowercased().hasPrefix("http://") && !text.lowercased().hasPrefix("https://") {
            text = "http://" + text
        }
        while text.hasSuffix("/") { text.removeLast() }
        if text.hasSuffix("/api") { text.removeLast(4) }
        return text
    }

    func clearAccount() {
        userId = ""
        username = ""
        nickname = ""
        coupleId = ""
        token = ""
    }
}
