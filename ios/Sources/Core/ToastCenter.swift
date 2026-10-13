import SwiftUI
import UIKit
import Combine

/// 轻提示
final class ToastCenter: ObservableObject {
    static let shared = ToastCenter()

    @Published var text = ""
    @Published var isShowing = false
    @Published var isError = false

    private var work: DispatchWorkItem?

    private init() {}

    func show(_ message: String, isError: Bool = false, duration: Double = 2.2) {
        guard !message.isEmpty else { return }
        DispatchQueue.main.async {
            self.text = message
            self.isError = isError
            self.isShowing = true
            self.work?.cancel()
            let item = DispatchWorkItem { [weak self] in
                withAnimation(.easeOut(duration: 0.25)) {
                    self?.isShowing = false
                }
            }
            self.work = item
            DispatchQueue.main.asyncAfter(deadline: .now() + duration, execute: item)
        }
    }

    func success(_ message: String) { show(message, isError: false) }
    func error(_ message: String) { show(message, isError: true, duration: 3.0) }
}

/// 顶部轻提示视图，挂在根视图上
struct ToastOverlay: View {
    @EnvironmentObject var toast: ToastCenter

    var body: some View {
        VStack {
            if toast.isShowing {
                HStack(spacing: 8) {
                    Image(systemName: toast.isError ? "exclamationmark.triangle.fill" : "checkmark.circle.fill")
                        .foregroundColor(toast.isError ? AppTheme.orange : AppTheme.mint)
                    Text(toast.text)
                        .font(.subheadline)
                        .foregroundColor(.primary)
                        .lineLimit(3)
                }
                .padding(.horizontal, 16)
                .padding(.vertical, 12)
                .background(
                    Capsule().fill(Color(.secondarySystemBackground))
                )
                .shadow(color: Color.black.opacity(0.15), radius: 10, y: 4)
                .padding(.horizontal, 24)
                .transition(.move(edge: .top).combined(with: .opacity))
            }
            Spacer()
        }
        .padding(.top, 8)
        .animation(.easeOut(duration: 0.25), value: toast.isShowing)
        .allowsHitTesting(false)
    }
}
