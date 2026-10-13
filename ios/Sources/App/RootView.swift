import SwiftUI
import UIKit

/// 根视图：未登录显示登录页，已登录显示主界面
struct RootView: View {
    @EnvironmentObject var session: SessionManager
    @EnvironmentObject var store: LocalStore
    @EnvironmentObject var chat: ChatService
    @Environment(\.scenePhase) private var scenePhase

    @State private var didBootstrap = false
    @State private var showForceUpdate = false

    var body: some View {
        ZStack {
            AppBackground()

            if session.isLoggedIn {
                MainTabView()
                    .transition(.opacity)
            } else {
                LoginView()
                    .transition(.opacity)
            }

            ToastOverlay()

            if let banner = chat.bannerMessage {
                VStack {
                    MessageBanner(message: banner)
                        .padding(.horizontal, 14)
                        .padding(.top, 6)
                        .onTapGesture {
                            chat.bannerMessage = nil
                            NotificationCenter.default.post(name: .openChatTab, object: nil)
                        }
                    Spacer()
                }
                .transition(.move(edge: .top).combined(with: .opacity))
            }
        }
        .animation(.easeInOut(duration: 0.2), value: session.isLoggedIn)
        .animation(.easeOut(duration: 0.25), value: chat.bannerMessage?.id)
        .task {
            guard !didBootstrap else { return }
            didBootstrap = true
            store.applyPetDecayIfNeeded()
            await session.bootstrap()
        }
        .onChange(of: scenePhase) { phase in
            handleScenePhase(phase)
        }
        .alert("需要更新 App", isPresented: $showForceUpdate) {
            Button("知道了", role: .cancel) { }
        } message: {
            Text("服务器要求最低版本 \(session.meta?.minAppBuild ?? 1)，当前是 \(AppInfo.buildNumber)。请安装最新版 IPA。")
        }
        .onChange(of: session.meta?.minAppBuild ?? 0) { value in
            if value > AppInfo.buildNumber { showForceUpdate = true }
        }
    }

    private func handleScenePhase(_ phase: ScenePhase) {
        switch phase {
        case .active:
            store.applyPetDecayIfNeeded()
            guard session.isLoggedIn else { return }
            session.startServices()
            Task { await SyncEngine.shared.syncNow() }
            LiveActivityController.shared.refreshRunning()
        case .background:
            store.saveNow()
        case .inactive:
            store.saveNow()
        @unknown default:
            break
        }
    }
}

/// 顶部消息横幅
struct MessageBanner: View {
    let message: ChatMessage

    var body: some View {
        HStack(spacing: 10) {
            EmojiAvatarView(emoji: "💬", size: 34)
            VStack(alignment: .leading, spacing: 2) {
                Text("收到一条新消息")
                    .font(.system(size: 13, weight: .semibold))
                Text(ChatService.displayText(for: message))
                    .font(.system(size: 12))
                    .foregroundColor(.secondary)
                    .lineLimit(1)
            }
            Spacer()
            Image(systemName: "chevron.right")
                .font(.system(size: 12))
                .foregroundColor(.secondary)
        }
        .padding(12)
        .background(
            RoundedRectangle(cornerRadius: 16, style: .continuous)
                .fill(Color(.secondarySystemBackground))
        )
        .shadow(color: Color.black.opacity(0.12), radius: 12, y: 4)
    }
}

extension Notification.Name {
    static let openChatTab = Notification.Name("couple.openChatTab")
}
