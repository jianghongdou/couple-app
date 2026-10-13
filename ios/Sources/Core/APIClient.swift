import Foundation

/// 统一网络错误
struct APIError: LocalizedError, Equatable {
    var status: Int = 0
    var code: String = "network"
    var message: String = "网络异常"

    var errorDescription: String? { message }

    var isUnauthorized: Bool { status == 401 }
    var isForbidden: Bool { status == 403 }
    var isNotFound: Bool { status == 404 }
    var isRateLimited: Bool { status == 429 }

    static let offline = APIError(status: 0, code: "offline", message: "无法连接服务器，请检查服务器地址与网络")
    static let noServer = APIError(status: 0, code: "no_server", message: "还没有配置服务器地址")
}

/// 底层 HTTP 客户端。所有返回都是 `[String: Any]`，由业务层用容错读取转成模型。
final class APIClient {
    static let shared = APIClient()

    private let session: URLSession

    private init() {
        let configuration = URLSessionConfiguration.default
        configuration.timeoutIntervalForRequest = 20
        configuration.timeoutIntervalForResource = 90
        configuration.waitsForConnectivity = false
        configuration.requestCachePolicy = .reloadIgnoringLocalCacheData
        self.session = URLSession(configuration: configuration)
    }

    // MARK: - URL

    func makeURL(_ path: String, query: [String: String] = [:]) -> URL? {
        let base = DeviceConfig.normalizeURL(DeviceConfig.shared.serverURL)
        guard !base.isEmpty else { return nil }
        guard var components = URLComponents(string: base + path) else { return nil }
        if !query.isEmpty {
            components.queryItems = query
                .map { URLQueryItem(name: $0.key, value: $0.value) }
                .sorted { $0.name < $1.name }
        }
        return components.url
    }

    /// 带 token 的图片地址（供服务端表情使用）
    func authorizedURLString(_ path: String) -> String? {
        let token = DeviceConfig.shared.token
        guard let url = makeURL(path, query: token.isEmpty ? [:] : ["token": token]) else { return nil }
        return url.absoluteString
    }

    // MARK: - 请求

    @discardableResult
    func send(
        _ path: String,
        method: String = "GET",
        body: [String: Any]? = nil,
        query: [String: String] = [:],
        authorized: Bool = true
    ) async throws -> [String: Any] {
        guard let url = makeURL(path, query: query) else {
            throw APIError.noServer
        }

        var request = URLRequest(url: url)
        request.httpMethod = method
        request.setValue("application/json", forHTTPHeaderField: "Content-Type")
        request.setValue("application/json", forHTTPHeaderField: "Accept")
        request.setValue("CoupleApp-iOS/\(AppInfo.version)(\(AppInfo.build))", forHTTPHeaderField: "User-Agent")
        if authorized {
            let token = DeviceConfig.shared.token
            if !token.isEmpty {
                request.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization")
            }
        }
        if let body = body {
            do {
                request.httpBody = try JSONSerialization.data(withJSONObject: body)
            } catch {
                throw APIError(status: 0, code: "bad_body", message: "请求数据格式错误")
            }
        }

        let data: Data
        let response: URLResponse
        do {
            (data, response) = try await session.data(for: request)
        } catch {
            throw APIError.offline
        }

        guard let http = response as? HTTPURLResponse else { throw APIError.offline }

        var object: [String: Any] = [:]
        if let parsed = try? JSONSerialization.jsonObject(with: data),
           let dict = parsed as? [String: Any] {
            object = dict
        }

        if (200..<300).contains(http.statusCode) {
            return object
        }

        let code = JSONRead.string(object, "error", "http_\(http.statusCode)")
        let fallback = APIClient.defaultMessage(for: http.statusCode)
        let message = JSONRead.string(object, "message", fallback)
        throw APIError(status: http.statusCode, code: code, message: message)
    }

    static func defaultMessage(for status: Int) -> String {
        switch status {
        case 400: return "请求参数有误"
        case 401: return "登录已过期，请重新登录"
        case 403: return "没有权限执行该操作"
        case 404: return "服务器上找不到该资源"
        case 409: return "操作冲突，请刷新后重试"
        case 429: return "操作太频繁，请稍后再试"
        case 500...599: return "服务器内部错误"
        default: return "请求失败（\(status)）"
        }
    }

    /// 健康检查，用于"测试连接"
    func ping() async -> (ok: Bool, message: String) {
        do {
            let dict = try await send("/api/health", authorized: false)
            let name = JSONRead.string(dict, "name", "server")
            let version = JSONRead.string(dict, "version", "?")
            return (true, "连接成功：\(name) v\(version)")
        } catch let error as APIError {
            return (false, error.message)
        } catch {
            return (false, "连接失败")
        }
    }

    /// 下载二进制（表情图片）
    func download(_ path: String) async -> Data? {
        guard let url = makeURL(path, query: DeviceConfig.shared.token.isEmpty ? [:] : ["token": DeviceConfig.shared.token]) else {
            return nil
        }
        var request = URLRequest(url: url)
        let token = DeviceConfig.shared.token
        if !token.isEmpty {
            request.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization")
        }
        guard let (data, response) = try? await session.data(for: request) else { return nil }
        guard let http = response as? HTTPURLResponse, (200..<300).contains(http.statusCode) else { return nil }
        return data
    }
}
