import Foundation

/// App 全局静态信息（版本号、名称、能力开关）
enum AppInfo {
    /// 显示名称
    static let displayName = "情侣空间"

    /// 营销版本号，取自 Info.plist 的 CFBundleShortVersionString
    /// （由 project.yml 的 MARKETING_VERSION 写入，目前是 1.0.8）
    static var version: String {
        (Bundle.main.infoDictionary?["CFBundleShortVersionString"] as? String) ?? "1.0.8"
    }

    /// 构建号，取自 Info.plist 的 CFBundleVersion
    /// （由 project.yml 的 CURRENT_PROJECT_VERSION 写入，目前是 8）
    static var build: String {
        (Bundle.main.infoDictionary?["CFBundleVersion"] as? String) ?? "8"
    }

    /// 例如 v1.0.8 (8)
    static var fullVersion: String { "v\(version) (\(build))" }

    static var buildNumber: Int { Int(build) ?? 1 }

    /// 当前安装的是轻量版。
    /// 两个 target 现在代码完全一样，只能靠 Bundle id / 产品名区分：
    /// 轻量版是 com.coupleapp.ios.lite、PRODUCT_NAME = CoupleAppLite。
    static var isLiteBuild: Bool {
        let bundleId = (Bundle.main.bundleIdentifier ?? "").lowercased()
        let productName = ((Bundle.main.infoDictionary?["CFBundleName"] as? String) ?? "").lowercased()
        return bundleId.hasSuffix(".lite") || productName.hasSuffix("lite")
    }

    /// 安装包类型文案（两个 target 的代码完全一样，只有 Bundle id / 显示名不同）
    static var installTypeDescription: String {
        isLiteBuild ? "轻量版" : "完整版"
    }

    /// 默认服务器地址（打包时预置，界面上只读展示）
    /// 必须用 https：站点会把 http 301 跳到 https，跳转后 POST 请求体会丢，
    /// 导致 AI 聊天等功能连不上。换服务器时只改这一行，重新打包即可。
    static let defaultServerURL = "https://nb.wwoyun.cn"

    /// 本地数据目录
    static var dataDirectory: URL {
        let fm = FileManager.default
        let base = fm.urls(for: .applicationSupportDirectory, in: .userDomainMask).first
            ?? URL(fileURLWithPath: NSTemporaryDirectory())
        let dir = base.appendingPathComponent("CoupleApp", isDirectory: true)
        if !fm.fileExists(atPath: dir.path) {
            try? fm.createDirectory(at: dir, withIntermediateDirectories: true)
        }
        return dir
    }

    /// 设备型号（友好名称 + 系统版本），例如 "iPhone 14 Pro · iOS 17.2"
    static var deviceModel: String { DeviceInfo.displayName }

    /// 设备机器标识，例如 "iPhone15,2"（后台统计用，不涉及隐私）
    static var deviceMachineIdentifier: String { DeviceInfo.machineIdentifier }
}
