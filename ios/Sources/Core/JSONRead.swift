import Foundation

/// 手工 JSON 读取工具。
/// 设计原因：服务端字段会随版本增减，用 JSONDecoder 严格解码容易整条失败。
/// 全部改用 `[String: Any]` + 容错读取，缺失字段自动回落默认值。
enum JSONRead {
    static func dict(_ any: Any?) -> [String: Any]? {
        if let d = any as? [String: Any] { return d }
        return nil
    }

    static func array(_ any: Any?) -> [Any]? {
        if let a = any as? [Any] { return a }
        return nil
    }

    static func string(_ dict: [String: Any], _ key: String, _ fallback: String = "") -> String {
        if let v = dict[key] as? String { return v }
        if let v = dict[key] as? NSNumber { return v.stringValue }
        return fallback
    }

    static func optionalString(_ dict: [String: Any], _ key: String) -> String? {
        if let v = dict[key] as? String { return v }
        if let v = dict[key] as? NSNumber { return v.stringValue }
        return nil
    }

    static func int(_ dict: [String: Any], _ key: String, _ fallback: Int = 0) -> Int {
        if let v = dict[key] as? Int { return v }
        if let v = dict[key] as? NSNumber { return v.intValue }
        if let v = dict[key] as? String, let n = Int(v) { return n }
        if let v = dict[key] as? Double { return Int(v) }
        return fallback
    }

    static func double(_ dict: [String: Any], _ key: String, _ fallback: Double = 0) -> Double {
        if let v = dict[key] as? Double { return v }
        if let v = dict[key] as? NSNumber { return v.doubleValue }
        if let v = dict[key] as? String, let n = Double(v) { return n }
        if let v = dict[key] as? Int { return Double(v) }
        return fallback
    }

    static func bool(_ dict: [String: Any], _ key: String, _ fallback: Bool = false) -> Bool {
        if let v = dict[key] as? Bool { return v }
        if let v = dict[key] as? NSNumber { return v.boolValue }
        if let v = dict[key] as? String {
            let lower = v.lowercased()
            if lower == "true" || lower == "1" { return true }
            if lower == "false" || lower == "0" { return false }
        }
        return fallback
    }

    static func stringArray(_ dict: [String: Any], _ key: String) -> [String] {
        guard let arr = dict[key] as? [Any] else { return [] }
        return arr.compactMap { item -> String? in
            if let s = item as? String { return s }
            if let n = item as? NSNumber { return n.stringValue }
            return nil
        }
    }

    static func intMap(_ dict: [String: Any], _ key: String) -> [String: Int] {
        guard let raw = dict[key] as? [String: Any] else { return [:] }
        var result: [String: Int] = [:]
        for (k, v) in raw {
            if let n = v as? Int { result[k] = n }
            else if let n = v as? NSNumber { result[k] = n.intValue }
            else if let n = v as? Double { result[k] = Int(n) }
        }
        return result
    }

    /// 把任意 JSON 数组转成模型数组，单条失败自动跳过
    static func objects<T>(_ dict: [String: Any], _ key: String, _ transform: ([String: Any]) -> T?) -> [T] {
        guard let arr = dict[key] as? [Any] else { return [] }
        return arr.compactMap { item in
            guard let d = item as? [String: Any] else { return nil }
            return transform(d)
        }
    }
}

extension Encodable {
    /// 编码成可直接放进 JSON 请求体 / 同步 payload 的字典
    var jsonObject: [String: Any] {
        guard let data = try? JSONEncoder().encode(self),
              let object = try? JSONSerialization.jsonObject(with: data),
              let dict = object as? [String: Any] else {
            return [:]
        }
        return dict
    }
}

/// 手工把 Any 转成可发送的 JSON（用于把模型数组放进同步 payload）
enum JSONSend {
    static func objects(_ items: [Encodable]) -> [Any] {
        items.map { $0.jsonObject }
    }
}
