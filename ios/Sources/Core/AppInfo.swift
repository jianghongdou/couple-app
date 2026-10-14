import Foundation
import UIKit

/// App 全局静态信息（版本号、名称、能力开关）
enum AppInfo {
    /// 显示名称
    static let displayName = "情侣空间"

    /// 营销版本号，取自 Info.plist 的 CFBundleShortVersionString
    static var version: String {
        (Bundle.main.infoDictionary?["CFBundleShortVersionString"] as? String) ?? "1.0.0"
    }

    /// 构建号，取自 Info.plist 的 CFBundleVersion
    static var build: String {
        (Bundle.main.infoDictionary?["CFBundleVersion"] as? String) ?? "1"
    }

    /// 例如 v1.0.0 (1)
    static var fullVersion: String { "v\(version) (\(build))" }

    static var buildNumber: Int { Int(build) ?? 1 }

    /// 当前安装包是否内嵌了灵动岛小组件扩展
    static var widgetExtensionEnabled: Bool {
        #if ENABLE_WIDGET_EXTENSION
        return true
        #else
        return false
        #endif
    }

    /// 默认服务器地址（已在打包时预置，登录页会直接填好，用户可改）
    /// 换服务器时只改这一行，重新打包即可。
    static let defaultServerURL = "http://nb.wwoyun.cn"

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

    /// 设备型号，用于后台统计（不涉及隐私）
    static var deviceModel: String {
        #if targetEnvironment(simulator)
        return "Simulator"
        #else
        var systemInfo = utsname()
        uname(&systemInfo)
        let machine = withUnsafePointer(to: &systemInfo.machine) { pointer -> String in
            pointer.withMemoryRebound(to: CChar.self, capacity: 64) { ptr in
                String(cString: ptr)
            }
        }
        return machine
        #endif
    }
}
