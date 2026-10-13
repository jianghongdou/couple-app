import SwiftUI

/// 全站配色与渐变
enum AppTheme {
    static let pink = Color(hex: 0xFF5C8A)
    static let pinkSoft = Color(hex: 0xFFA5BE)
    static let purple = Color(hex: 0x9B6BFF)
    static let blue = Color(hex: 0x4F9DFF)
    static let mint = Color(hex: 0x2FD6A5)
    static let orange = Color(hex: 0xFFA94D)
    static let yellow = Color(hex: 0xFFD43B)
    static let red = Color(hex: 0xFF6B6B)
    static let coin = Color(hex: 0xFFC93C)
    static let grayButton = Color(hex: 0x8E8E93)

    static let bgLight = Color(hex: 0xFFF6F9)
    static let bgLight2 = Color(hex: 0xFFEFF5)
    static let bgDark = Color(hex: 0x140F17)
    static let bgDark2 = Color(hex: 0x1D1420)

    static let storyColors: [Color] = [pink, purple, blue, mint, orange, red]

    static let brandGradient = LinearGradient(
        colors: [pink, purple],
        startPoint: .topLeading,
        endPoint: .bottomTrailing
    )

    static let warmGradient = LinearGradient(
        colors: [orange, pink],
        startPoint: .topLeading,
        endPoint: .bottomTrailing
    )

    static let coolGradient = LinearGradient(
        colors: [blue, mint],
        startPoint: .topLeading,
        endPoint: .bottomTrailing
    )

    static let coinGradient = LinearGradient(
        colors: [yellow, orange],
        startPoint: .topLeading,
        endPoint: .bottomTrailing
    )

    /// 主题色可选值（设置页用）
    static let accentKeys: [String] = ["pink", "purple", "blue", "mint", "orange", "red"]

    static func accent(for key: String) -> Color {
        switch key {
        case "purple": return purple
        case "blue": return blue
        case "mint": return mint
        case "orange": return orange
        case "red": return red
        default: return pink
        }
    }

    static func accentName(for key: String) -> String {
        switch key {
        case "purple": return "梦幻紫"
        case "blue": return "晴空蓝"
        case "mint": return "薄荷绿"
        case "orange": return "暖阳橙"
        case "red": return "心动红"
        default: return "恋爱粉"
        }
    }
}

extension Color {
    init(hex: UInt32) {
        let r = Double((hex >> 16) & 0xFF) / 255.0
        let g = Double((hex >> 8) & 0xFF) / 255.0
        let b = Double(hex & 0xFF) / 255.0
        self.init(red: r, green: g, blue: b)
    }
}

/// 全 App 统一背景
struct AppBackground: View {
    @Environment(\.colorScheme) private var scheme

    var body: some View {
        LinearGradient(
            colors: scheme == .dark
                ? [AppTheme.bgDark, AppTheme.bgDark2]
                : [AppTheme.bgLight, AppTheme.bgLight2],
            startPoint: .top,
            endPoint: .bottom
        )
        .ignoresSafeArea()
    }
}

/// 卡片容器样式
struct CardBackground: ViewModifier {
    @Environment(\.colorScheme) private var scheme
    var padding: CGFloat = 16

    func body(content: Content) -> some View {
        content
            .padding(padding)
            .background(
                RoundedRectangle(cornerRadius: 20, style: .continuous)
                    .fill(scheme == .dark ? Color.white.opacity(0.07) : Color.white.opacity(0.92))
            )
            .overlay(
                RoundedRectangle(cornerRadius: 20, style: .continuous)
                    .stroke(scheme == .dark ? Color.white.opacity(0.08) : Color.black.opacity(0.04), lineWidth: 1)
            )
            .shadow(color: Color.black.opacity(scheme == .dark ? 0.3 : 0.06), radius: 12, x: 0, y: 6)
    }
}

extension View {
    func card(padding: CGFloat = 16) -> some View {
        modifier(CardBackground(padding: padding))
    }
}
