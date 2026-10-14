import Foundation
import Combine

/// 设备本地配置（只存这台手机，不参与同步）
final class DeviceConfig: ObservableObject {
    static let shared = DeviceConfig()

    private let defaults = UserDefaults.standard

    /// 打包时预置的服务器 host（用来判断本机存下来的地址还算不算数）
    private static var defaultHost: String {
        URL(string: AppInfo.defaultServerURL)?.host ?? ""
    }

    /// 服务器地址。
    /// 现在地址写死成 https 的默认地址：任何写入都会被规范成 https，
    /// 启动时存下来的值只要不是 https、或 host 与默认地址不一致，一律回到默认地址。
    @Published var serverURL: String {
        didSet {
            let fixed = DeviceConfig.normalizeURL(serverURL)
            if fixed != serverURL {
                // 再走一次 didSet 把规范化后的值存下去（normalizeURL 是幂等的，不会无限递归）
                serverURL = fixed.isEmpty ? AppInfo.defaultServerURL : fixed
                return
            }
            defaults.set(serverURL, forKey: "serverURL")
        }
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

    private init() {
        // 老版本可能存过 http:// 或者别的服务器地址：
        // 只要不是 https:// 开头、或者 host 与打包时预置的不一致，就一律覆盖成默认地址。
        let saved = (defaults.string(forKey: "serverURL") ?? "").trimmingCharacters(in: .whitespacesAndNewlines)
        if DeviceConfig.isValidStoredServer(saved) {
            self.serverURL = DeviceConfig.normalizeURL(saved)
        } else {
            self.serverURL = AppInfo.defaultServerURL
            defaults.set(AppInfo.defaultServerURL, forKey: "serverURL")
        }
        self.userId = defaults.string(forKey: "userId") ?? ""
        self.username = defaults.string(forKey: "username") ?? ""
        self.nickname = defaults.string(forKey: "nickname") ?? ""
        self.avatarEmoji = defaults.string(forKey: "avatarEmoji") ?? "🐻"
        self.coupleId = defaults.string(forKey: "coupleId") ?? ""
        self.onboardingDone = defaults.bool(forKey: "onboardingDone")
        self.hasVault = defaults.bool(forKey: "hasVault")
        self.lastSeenVersion = defaults.string(forKey: "lastSeenVersion") ?? ""
        self.acceptedDisclaimer = defaults.bool(forKey: "acceptedDisclaimer")
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

    /// 存下来的地址是否可用：必须是 https，且 host 与打包时预置的一致
    static func isValidStoredServer(_ raw: String) -> Bool {
        let text = raw.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !text.isEmpty else { return false }
        guard text.lowercased().hasPrefix("https://") else { return false }
        guard let host = URL(string: text)?.host, !host.isEmpty else { return false }
        return host.caseInsensitiveCompare(defaultHost) == .orderedSame
    }

    /// 规范化用户输入的服务器地址。
    /// - 没有协议前缀时补 **https://**（不是 http）
    /// - 写了 http:// 也会升级成 https://（站点会 301 跳转并丢 POST body）
    /// - 去掉结尾斜杠与 /api 后缀
    static func normalizeURL(_ raw: String) -> String {
        var text = raw.trimmingCharacters(in: .whitespacesAndNewlines)
        if text.isEmpty { return "" }

        // 不管是 http://、https:// 还是别的协议前缀，一并去掉后统一换成 https://
        // （站点会把 http 301 跳到 https，跳转后 POST body 会丢，所以不能留 http）
        if let separator = text.range(of: "://") {
            text = String(text[separator.upperBound...])
        }
        text = "https://" + text

        while text.hasSuffix("/") { text.removeLast() }
        if text.lowercased().hasSuffix("/api") { text.removeLast(4) }
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
