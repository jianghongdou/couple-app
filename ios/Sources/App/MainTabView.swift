import SwiftUI

/// 主界面：5 个标签页
struct MainTabView: View {
    @EnvironmentObject var session: SessionManager
    @EnvironmentObject var store: LocalStore
    @EnvironmentObject var chat: ChatService

    @State private var selection = 0

    var body: some View {
        TabView(selection: $selection) {
            HomeView()
                .tabItem { Label("首页", systemImage: "house.fill") }
                .tag(0)

            ChatView()
                .tabItem { Label("聊天", systemImage: "bubble.left.and.bubble.right.fill") }
                .badge(chat.unreadCount())
                .tag(1)

            PetView()
                .tabItem { Label("宠物", systemImage: "pawprint.fill") }
                .tag(2)

            LedgerView()
                .tabItem { Label("账本", systemImage: "creditcard.fill") }
                .tag(3)

            ProfileView()
                .tabItem { Label("我的", systemImage: "person.crop.circle.fill") }
                .tag(4)
        }
        .tint(AppTheme.accent(for: store.state.settings.accentKey))
        .onReceive(NotificationCenter.default.publisher(for: .openChatTab)) { _ in
            selection = 1
        }
    }
}
