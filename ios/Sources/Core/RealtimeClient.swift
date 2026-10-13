import Foundation
import Combine

/// WebSocket 事件
enum RealtimeEvent {
    case hello(coupleId: String)
    case message(ChatMessage)
    case read(userId: String, upTo: Double)
    case typing(userId: String)
    case presence(userId: String, online: Bool)
    case sync(kinds: [String], fromUserId: String)
    case error(String)
}

/// 实时通道：聊天消息、在线状态、输入中、同步通知
final class RealtimeClient: ObservableObject {
    static let shared = RealtimeClient()

    @Published var isConnected = false
    @Published var partnerOnline = false
    @Published var lastError = ""
    /// WebSocket 反复连不上时置为 true：此时客户端改用轮询拉消息（PHP 版服务端可以不跑常驻进程）
    @Published var isDegraded = false

    var onEvent: ((RealtimeEvent) -> Void)?

    private var task: URLSessionWebSocketTask?
    private var session: URLSession?
    private var pingTimer: Timer?
    private var retryCount = 0
    private var running = false
    private var lastPresence: [String: Bool] = [:]

    private init() {}

    // MARK: - 连接

    func start() {
        guard !DeviceConfig.shared.token.isEmpty else { return }
        if running && isConnected { return }
        running = true
        open()
    }

    func stop() {
        running = false
        pingTimer?.invalidate()
        pingTimer = nil
        task?.cancel(with: .goingAway, reason: nil)
        task = nil
        DispatchQueue.main.async {
            self.isConnected = false
            self.partnerOnline = false
        }
        lastPresence = [:]
    }

    private func socketURL() -> URL? {
        let base = DeviceConfig.normalizeURL(DeviceConfig.shared.serverURL)
        guard !base.isEmpty else { return nil }
        var text = base
        if text.hasPrefix("https://") {
            text = "wss://" + String(text.dropFirst(8))
        } else if text.hasPrefix("http://") {
            text = "ws://" + String(text.dropFirst(7))
        } else {
            text = "wss://" + text
        }
        return URL(string: text + "/ws")
    }

    private func open() {
        guard running, let url = socketURL() else { return }
        let token = DeviceConfig.shared.token
        guard !token.isEmpty else { return }

        var request = URLRequest(url: url)
        request.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization")
        request.timeoutInterval = 30

        let configuration = URLSessionConfiguration.default
        configuration.waitsForConnectivity = false
        let session = URLSession(configuration: configuration)
        self.session = session

        let task = session.webSocketTask(with: request)
        self.task = task
        task.resume()
        receive()
        startPing()
        setConnected(true)
    }

    private func setConnected(_ value: Bool) {
        if isConnected != value {
            DispatchQueue.main.async {
                self.isConnected = value
                if value { self.isDegraded = false }
            }
        }
    }

    private func startPing() {
        pingTimer?.invalidate()
        DispatchQueue.main.async {
            self.pingTimer = Timer.scheduledTimer(withTimeInterval: 25, repeats: true) { [weak self] _ in
                self?.sendRaw(["type": "ping"])
            }
        }
    }

    // MARK: - 收发

    private func receive() {
        task?.receive { [weak self] result in
            guard let self = self else { return }
            switch result {
            case .success(let message):
                switch message {
                case .string(let text):
                    self.handle(text: text)
                case .data(let data):
                    if let text = String(data: data, encoding: .utf8) {
                        self.handle(text: text)
                    }
                @unknown default:
                    break
                }
                self.receive()
            case .failure(let error):
                self.setConnected(false)
                self.lastError = error.localizedDescription
                self.scheduleReconnect()
            }
        }
    }

    private func handle(text: String) {
        guard let data = text.data(using: .utf8),
              let object = try? JSONSerialization.jsonObject(with: data),
              let dict = object as? [String: Any] else { return }

        let type = JSONRead.string(dict, "type")
        var event: RealtimeEvent?

        switch type {
        case "hello":
            retryCount = 0
            DispatchQueue.main.async {
                self.isDegraded = false
            }
            event = .hello(coupleId: JSONRead.string(dict, "coupleId"))
        case "message":
            if let messageDict = JSONRead.dict(dict["message"]), let message = ChatMessage(json: messageDict) {
                event = .message(message)
            }
        case "read":
            event = .read(userId: JSONRead.string(dict, "userId"), upTo: JSONRead.double(dict, "upTo"))
        case "typing":
            event = .typing(userId: JSONRead.string(dict, "userId"))
        case "presence":
            let userId = JSONRead.string(dict, "userId")
            let online = JSONRead.bool(dict, "online")
            lastPresence[userId] = online
            let anyOnline = lastPresence.values.contains(true)
            DispatchQueue.main.async {
                self.partnerOnline = anyOnline
            }
            event = .presence(userId: userId, online: online)
        case "sync":
            event = .sync(
                kinds: JSONRead.stringArray(dict, "kinds"),
                fromUserId: JSONRead.string(dict, "fromUserId")
            )
        case "error":
            event = .error(JSONRead.string(dict, "message"))
        default:
            break
        }

        if let event = event {
            DispatchQueue.main.async {
                self.onEvent?(event)
            }
        }
    }

    private func sendRaw(_ dict: [String: Any]) {
        guard let data = try? JSONSerialization.data(withJSONObject: dict),
              let text = String(data: data, encoding: .utf8) else { return }
        task?.send(.string(text)) { error in
            if let error = error {
                print("[WS] 发送失败: \(error.localizedDescription)")
            }
        }
    }

    func sendTyping() {
        sendRaw(["type": "typing"])
    }

    // MARK: - 重连

    private func scheduleReconnect() {
        guard running else { return }
        retryCount = min(retryCount + 1, 6)
        let delays: [Double] = [1, 2, 4, 8, 15, 20, 30]
        let delay = delays[min(retryCount - 1, delays.count - 1)]
        // 连续多次连不上：切到「轮询兜底」状态，并放慢重连（避免一直空耗）
        if retryCount >= 3 && !isDegraded {
            DispatchQueue.main.async {
                self.isDegraded = true
            }
        }
        DispatchQueue.main.asyncAfter(deadline: .now() + delay) { [weak self] in
            guard let self = self, self.running else { return }
            self.open()
        }
    }

    func reconnectNow() {
        task?.cancel(with: .goingAway, reason: nil)
        task = nil
        retryCount = 0
        running = true
        open()
    }
}
