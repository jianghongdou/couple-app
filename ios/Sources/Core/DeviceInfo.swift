import Foundation
import UIKit

/// 设备型号工具。
///
/// 背景：`UIDevice.current.model` 只会返回 "iPhone"（连机型都不分），
/// 所以这里直接读机器标识 `sysctlbyname("hw.machine")` / `uname()`，
/// 例如 "iPhone15,2"，再用下面的映射表翻译成好认的名字（"iPhone 14 Pro"）。
enum DeviceInfo {

    /// 机器标识，例如 "iPhone15,2"；模拟器返回模拟器里跑的机型标识。
    ///
    /// `uname()` 的 `machine` 字段与 `sysctlbyname("hw.machine")` 取到的是同一个值，
    /// 这里用 uname 是为了少写一次 C 字符串内存管理（真机上是 "iPhone15,2" 这种）。
    static var machineIdentifier: String {
        #if targetEnvironment(simulator)
        // 模拟器上 uname 拿到的是 Mac 的架构（arm64 / x86_64），没有参考价值；
        // Xcode 会把「模拟的是哪台设备」写进这个环境变量。
        if let simulated = ProcessInfo.processInfo.environment["SIMULATOR_MODEL_IDENTIFIER"],
           !simulated.isEmpty {
            return simulated
        }
        return "Simulator"
        #else
        var systemInfo = utsname()
        uname(&systemInfo)
        let machine = withUnsafePointer(to: &systemInfo.machine) { pointer -> String in
            pointer.withMemoryRebound(to: CChar.self, capacity: 64) { ptr in
                String(cString: ptr)
            }
        }
        return machine.isEmpty ? "Unknown" : machine
        #endif
    }

    /// 友好型号名，例如 "iPhone 14 Pro"。
    /// 映射表里查不到（例如还没收录的新机型）就原样返回机器标识，不做猜测。
    static var friendlyName: String {
        let identifier = machineIdentifier
        if let name = modelMap[identifier] { return name }
        return identifier
    }

    /// 「关于」页展示用：型号 + 系统版本，例如 "iPhone 14 Pro · iOS 17.2"
    static var displayName: String {
        "\(friendlyName) · \(UIDevice.current.systemName) \(UIDevice.current.systemVersion)"
    }

    /// 机器标识 → 友好名称映射表。
    /// 至少覆盖 iPhone 11 ~ iPhone 16 系列常见型号（含 SE 2/3 代、XR / XS）。
    private static let modelMap: [String: String] = [
        // ---- iPhone XS / XR 一代 ----
        "iPhone11,2": "iPhone XS",
        "iPhone11,4": "iPhone XS Max",
        "iPhone11,6": "iPhone XS Max",
        "iPhone11,8": "iPhone XR",

        // ---- iPhone 11 系列 ----
        "iPhone12,1": "iPhone 11",
        "iPhone12,3": "iPhone 11 Pro",
        "iPhone12,5": "iPhone 11 Pro Max",
        "iPhone12,8": "iPhone SE (第 2 代)",

        // ---- iPhone 12 系列 ----
        "iPhone13,1": "iPhone 12 mini",
        "iPhone13,2": "iPhone 12",
        "iPhone13,3": "iPhone 12 Pro",
        "iPhone13,4": "iPhone 12 Pro Max",

        // ---- iPhone 13 系列 ----
        "iPhone14,2": "iPhone 13 Pro",
        "iPhone14,3": "iPhone 13 Pro Max",
        "iPhone14,4": "iPhone 13 mini",
        "iPhone14,5": "iPhone 13",
        "iPhone14,6": "iPhone SE (第 3 代)",
        "iPhone14,7": "iPhone 14",
        "iPhone14,8": "iPhone 14 Plus",

        // ---- iPhone 14 Pro 系列 ----
        "iPhone15,2": "iPhone 14 Pro",
        "iPhone15,3": "iPhone 14 Pro Max",

        // ---- iPhone 15 系列 ----
        "iPhone15,4": "iPhone 15",
        "iPhone15,5": "iPhone 15 Plus",
        "iPhone16,1": "iPhone 15 Pro",
        "iPhone16,2": "iPhone 15 Pro Max",

        // ---- iPhone 16 系列 ----
        "iPhone17,1": "iPhone 16 Pro",
        "iPhone17,2": "iPhone 16 Pro Max",
        "iPhone17,3": "iPhone 16",
        "iPhone17,4": "iPhone 16 Plus",
        "iPhone17,5": "iPhone 16e"
    ]
}
