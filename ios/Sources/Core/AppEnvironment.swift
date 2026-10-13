import SwiftUI

/// 把根视图上注入的环境对象，补给弹窗（sheet / fullScreenCover）里的内容。
///
/// 说明：虽然 iOS 14+ 的 sheet 通常会继承环境对象，但为了在真机上绝对不出现
/// 「No ObservableObject of type X found」崩溃，所有弹窗内容都显式补一次。
extension View {
    func appEnvironment() -> some View {
        self
            .environmentObject(LocalStore.shared)
            .environmentObject(DeviceConfig.shared)
            .environmentObject(SessionManager.shared)
            .environmentObject(ChatService.shared)
            .environmentObject(EmojiService.shared)
            .environmentObject(SyncEngine.shared)
            .environmentObject(LiveActivityController.shared)
            .environmentObject(ToastCenter.shared)
            .environmentObject(RealtimeClient.shared)
    }
}
