import SwiftUI

/// App 入口
@main
struct CoupleAppMain: App {
    @StateObject private var store = LocalStore.shared
    @StateObject private var device = DeviceConfig.shared
    @StateObject private var session = SessionManager.shared
    @StateObject private var chat = ChatService.shared
    @StateObject private var emoji = EmojiService.shared
    @StateObject private var sync = SyncEngine.shared
    @StateObject private var live = LiveActivityController.shared
    @StateObject private var toast = ToastCenter.shared
    @StateObject private var realtime = RealtimeClient.shared

    var body: some Scene {
        WindowGroup {
            RootView()
                .environmentObject(store)
                .environmentObject(device)
                .environmentObject(session)
                .environmentObject(chat)
                .environmentObject(emoji)
                .environmentObject(sync)
                .environmentObject(live)
                .environmentObject(toast)
                .environmentObject(realtime)
                .preferredColorScheme(nil)
        }
    }
}
