import SwiftUI
import UIKit

// MARK: - 通用卡片

struct SectionCard<Content: View>: View {
    let title: String
    let icon: String
    let subtitle: String
    let content: Content

    init(title: String, icon: String, subtitle: String = "", @ViewBuilder content: () -> Content) {
        self.title = title
        self.icon = icon
        self.subtitle = subtitle
        self.content = content()
    }

    var body: some View {
        VStack(alignment: .leading, spacing: 12) {
            HStack(spacing: 8) {
                Image(systemName: icon)
                    .font(.system(size: 15, weight: .semibold))
                    .foregroundColor(AppTheme.pink)
                Text(title)
                    .font(.system(size: 16, weight: .semibold))
                Spacer()
                if !subtitle.isEmpty {
                    Text(subtitle)
                        .font(.caption)
                        .foregroundColor(.secondary)
                }
            }
            content
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .card()
    }
}

// MARK: - 按钮

struct PrimaryButtonStyle: ButtonStyle {
    var color: Color = AppTheme.pink
    var enabled: Bool = true

    func makeBody(configuration: Configuration) -> some View {
        configuration.label
            .font(.system(size: 16, weight: .semibold))
            .foregroundColor(.white)
            .frame(maxWidth: .infinity)
            .padding(.vertical, 14)
            .background(
                RoundedRectangle(cornerRadius: 16, style: .continuous)
                    .fill(enabled ? color : Color.gray.opacity(0.45))
            )
            .opacity(configuration.isPressed ? 0.82 : 1)
            .scaleEffect(configuration.isPressed ? 0.98 : 1)
    }
}

struct SoftButtonStyle: ButtonStyle {
    var color: Color = AppTheme.pink

    func makeBody(configuration: Configuration) -> some View {
        configuration.label
            .font(.system(size: 15, weight: .medium))
            .foregroundColor(color)
            .padding(.horizontal, 14)
            .padding(.vertical, 9)
            .background(
                RoundedRectangle(cornerRadius: 12, style: .continuous)
                    .fill(color.opacity(0.14))
            )
            .opacity(configuration.isPressed ? 0.7 : 1)
    }
}

// MARK: - 头像

struct EmojiAvatarView: View {
    let emoji: String
    var size: CGFloat = 44
    var ringColor: Color = AppTheme.pink

    var body: some View {
        Text(emoji.isEmpty ? "🐻" : emoji)
            .font(.system(size: size * 0.52))
            .frame(width: size, height: size)
            .background(
                Circle().fill(
                    LinearGradient(
                        colors: [ringColor.opacity(0.25), ringColor.opacity(0.12)],
                        startPoint: .topLeading,
                        endPoint: .bottomTrailing
                    )
                )
            )
            .overlay(Circle().stroke(ringColor.opacity(0.5), lineWidth: 1.5))
    }
}

// MARK: - 进度条

struct ProgressBarView: View {
    var value: Double
    var color: Color = AppTheme.pink
    var height: CGFloat = 8

    var body: some View {
        GeometryReader { geo in
            ZStack(alignment: .leading) {
                Capsule().fill(Color.gray.opacity(0.18))
                Capsule()
                    .fill(color)
                    .frame(width: max(0, min(1, value)) * geo.size.width)
            }
        }
        .frame(height: height)
    }
}

/// 带标签的进度条（宠物状态）
struct StatusBarView: View {
    let icon: String
    let label: String
    let value: Int
    let color: Color

    var body: some View {
        HStack(spacing: 8) {
            Text(icon).font(.system(size: 14))
            Text(label)
                .font(.system(size: 12))
                .foregroundColor(.secondary)
                .frame(width: 42, alignment: .leading)
            ProgressBarView(value: Double(value) / 100.0, color: color, height: 7)
            Text("\(value)")
                .font(.system(size: 12, weight: .medium))
                .foregroundColor(.secondary)
                .frame(width: 26, alignment: .trailing)
        }
    }
}

// MARK: - 小标签

struct TagChip: View {
    let text: String
    var color: Color = AppTheme.pink
    var filled: Bool = false

    var body: some View {
        Text(text)
            .font(.system(size: 12, weight: .medium))
            .foregroundColor(filled ? .white : color)
            .padding(.horizontal, 10)
            .padding(.vertical, 5)
            .background(
                Capsule().fill(filled ? color : color.opacity(0.13))
            )
    }
}

// MARK: - 空状态

struct EmptyStateView: View {
    let icon: String
    let title: String
    let message: String

    var body: some View {
        VStack(spacing: 10) {
            Text(icon).font(.system(size: 44))
            Text(title)
                .font(.system(size: 16, weight: .semibold))
            Text(message)
                .font(.system(size: 13))
                .foregroundColor(.secondary)
                .multilineTextAlignment(.center)
        }
        .frame(maxWidth: .infinity)
        .padding(.vertical, 32)
    }
}

// MARK: - 柱状图

struct MiniBarChart: View {
    let values: [Double]
    let labels: [String]
    var color: Color = AppTheme.pink
    var height: CGFloat = 130

    var body: some View {
        GeometryReader { geo in
            let maxValue = max(values.max() ?? 1, 1)
            HStack(alignment: .bottom, spacing: 6) {
                ForEach(values.indices, id: \.self) { index in
                    VStack(spacing: 4) {
                        Text(values[index] > 0 ? MoneyFormat.short(values[index]) : "")
                            .font(.system(size: 9))
                            .foregroundColor(.secondary)
                        RoundedRectangle(cornerRadius: 5, style: .continuous)
                            .fill(
                                LinearGradient(colors: [color, color.opacity(0.55)], startPoint: .top, endPoint: .bottom)
                            )
                            .frame(height: max(4, CGFloat(values[index] / maxValue) * (geo.size.height - 34)))
                        Text(labels.indices.contains(index) ? labels[index] : "")
                            .font(.system(size: 9))
                            .foregroundColor(.secondary)
                    }
                    .frame(maxWidth: .infinity)
                }
            }
        }
        .frame(height: height)
    }
}

// MARK: - 统计小块

struct StatTile: View {
    let title: String
    let value: String
    var unit: String = ""
    var color: Color = AppTheme.pink
    var icon: String = ""

    var body: some View {
        VStack(alignment: .leading, spacing: 6) {
            HStack(spacing: 4) {
                if !icon.isEmpty {
                    Image(systemName: icon)
                        .font(.system(size: 11))
                        .foregroundColor(color)
                }
                Text(title)
                    .font(.system(size: 12))
                    .foregroundColor(.secondary)
            }
            HStack(alignment: .firstTextBaseline, spacing: 2) {
                Text(value)
                    .font(.system(size: 20, weight: .bold, design: .rounded))
                    .foregroundColor(color)
                if !unit.isEmpty {
                    Text(unit)
                        .font(.system(size: 11))
                        .foregroundColor(.secondary)
                }
            }
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .padding(12)
        .background(
            RoundedRectangle(cornerRadius: 14, style: .continuous)
                .fill(color.opacity(0.09))
        )
    }
}

// MARK: - 金额格式化

enum MoneyFormat {
    /// ¥12.50
    static func text(_ value: Double) -> String {
        String(format: "¥%.2f", value)
    }

    /// ¥12.5 / ¥1200
    static func short(_ value: Double) -> String {
        if value >= 10000 {
            return String(format: "%.1fw", value / 10000)
        }
        if value >= 1000 {
            return String(format: "%.0f", value)
        }
        if value == value.rounded() {
            return String(format: "%.0f", value)
        }
        return String(format: "%.1f", value)
    }

    /// 12.50
    static func plain(_ value: Double) -> String {
        String(format: "%.2f", value)
    }

    /// 1234.5 -> 1,234.50
    static func grouped(_ value: Double) -> String {
        let formatter = NumberFormatter()
        formatter.numberStyle = .decimal
        formatter.minimumFractionDigits = 2
        formatter.maximumFractionDigits = 2
        return formatter.string(from: NSNumber(value: value)) ?? plain(value)
    }
}

// MARK: - 表情图片视图

struct EmojiImageView: View {
    let itemId: String
    var size: CGFloat = 90

    @ObservedObject private var service = EmojiService.shared

    var body: some View {
        Group {
            if let url = service.localURL(for: itemId), let image = UIImage(contentsOfFile: url.path) {
                Image(uiImage: image)
                    .resizable()
                    .scaledToFit()
            } else {
                ZStack {
                    RoundedRectangle(cornerRadius: 10, style: .continuous)
                        .fill(Color.gray.opacity(0.12))
                    ProgressView()
                }
            }
        }
        .frame(width: size, height: size)
        .onAppear { service.ensureCached(itemId) }
    }
}

// MARK: - 输入框样式

struct InputField: View {
    let title: String
    let placeholder: String
    @Binding var text: String
    var isSecure: Bool = false
    var keyboard: UIKeyboardType = .default

    var body: some View {
        VStack(alignment: .leading, spacing: 6) {
            Text(title)
                .font(.system(size: 13, weight: .medium))
                .foregroundColor(.secondary)
            Group {
                if isSecure {
                    SecureField(placeholder, text: $text)
                } else {
                    TextField(placeholder, text: $text)
                        .keyboardType(keyboard)
                }
            }
            .textInputAutocapitalization(.never)
            .autocorrectionDisabled(true)
            .padding(.horizontal, 14)
            .padding(.vertical, 12)
            .background(
                RoundedRectangle(cornerRadius: 12, style: .continuous)
                    .fill(Color.gray.opacity(0.1))
            )
        }
    }
}
