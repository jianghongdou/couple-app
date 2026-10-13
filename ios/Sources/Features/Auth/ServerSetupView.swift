import SwiftUI

/// 服务器地址配置
struct ServerSetupView: View {
    @EnvironmentObject var device: DeviceConfig
    @EnvironmentObject var session: SessionManager
    @EnvironmentObject var toast: ToastCenter
    @Environment(\.dismiss) private var dismiss

    @State private var text = ""
    @State private var isTesting = false
    @State private var testResult = ""

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(alignment: .leading, spacing: 16) {
                    VStack(alignment: .leading, spacing: 8) {
                        Text("服务器地址")
                            .font(.system(size: 13, weight: .medium))
                            .foregroundColor(.secondary)
                        TextField("http://192.168.1.10:8787", text: $text)
                            .textInputAutocapitalization(.never)
                            .autocorrectionDisabled(true)
                            .keyboardType(.URL)
                            .font(.system(size: 15, design: .monospaced))
                            .padding(.horizontal, 14)
                            .padding(.vertical, 12)
                            .background(
                                RoundedRectangle(cornerRadius: 12, style: .continuous)
                                    .fill(Color.gray.opacity(0.1))
                            )
                    }

                    VStack(alignment: .leading, spacing: 6) {
                        Text("填写规则")
                            .font(.system(size: 13, weight: .semibold))
                        rule("不要带 /api 后缀，程序会自动补上")
                        rule("局域网测试：http://192.168.1.10:8787")
                        rule("公网服务器：https://couple.你的域名.com（推荐，走 HTTPS）")
                        rule("不带 http:// 时程序会自动补 http://")
                    }
                    .card()

                    if !testResult.isEmpty {
                        HStack(alignment: .top, spacing: 8) {
                            Image(systemName: testResult.hasPrefix("连接成功") ? "checkmark.circle.fill" : "xmark.circle.fill")
                                .foregroundColor(testResult.hasPrefix("连接成功") ? AppTheme.mint : AppTheme.red)
                            Text(testResult)
                                .font(.system(size: 13))
                            Spacer(minLength: 0)
                        }
                        .card()
                    }

                    Button {
                        Task { await test() }
                    } label: {
                        HStack(spacing: 8) {
                            if isTesting { ProgressView().tint(.white) }
                            Text("测试连接")
                        }
                    }
                    .buttonStyle(PrimaryButtonStyle(color: AppTheme.blue, enabled: !isTesting))

                    Button {
                        save()
                    } label: {
                        Text("保存并继续")
                    }
                    .buttonStyle(PrimaryButtonStyle(color: AppTheme.pink))

                    VStack(alignment: .leading, spacing: 6) {
                        Text("忘了地址？")
                            .font(.system(size: 13, weight: .semibold))
                        Text("服务器部署方法见项目里的 docs/03-服务器部署.md，部署完成后控制台会打印监听地址。")
                            .font(.system(size: 12))
                            .foregroundColor(.secondary)
                    }
                    .card()
                }
                .padding(18)
            }
            .background(AppBackground())
            .navigationTitle("服务器设置")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("关闭") { dismiss() }
                }
            }
            .onAppear { text = device.serverURL }
        }
    }

    private func rule(_ text: String) -> some View {
        HStack(alignment: .top, spacing: 6) {
            Text("·").foregroundColor(AppTheme.blue)
            Text(text)
                .font(.system(size: 12))
                .foregroundColor(.secondary)
            Spacer(minLength: 0)
        }
    }

    /// 临时把地址写到配置里试连，再还原。标记 @MainActor 保证不跨线程改 @Published
    @MainActor
    private func test() async {
        let normalized = DeviceConfig.normalizeURL(text)
        guard !normalized.isEmpty else {
            testResult = "请先填写服务器地址"
            return
        }
        isTesting = true
        let previous = device.serverURL
        device.serverURL = normalized
        let result = await APIClient.shared.ping()
        device.serverURL = previous
        isTesting = false
        testResult = result.message
        if result.ok { Haptics.success() } else { Haptics.error() }
    }

    private func save() {
        let normalized = DeviceConfig.normalizeURL(text)
        device.serverURL = normalized
        toast.success("服务器地址已保存")
        dismiss()
        Task { await session.loadMeta() }
    }
}

/// 头像 emoji 选择
struct AvatarPickerView: View {
    @Binding var selected: String
    @Environment(\.dismiss) private var dismiss

    private let columns = [GridItem(.adaptive(minimum: 56), spacing: 12)]

    var body: some View {
        NavigationStack {
            ScrollView {
                LazyVGrid(columns: columns, spacing: 12) {
                    ForEach(EmojiLibrary.avatars, id: \.self) { emoji in
                        Button {
                            selected = emoji
                            Haptics.selection()
                            dismiss()
                        } label: {
                            Text(emoji)
                                .font(.system(size: 30))
                                .frame(width: 56, height: 56)
                                .background(
                                    Circle()
                                        .fill(emoji == selected ? AppTheme.pink.opacity(0.22) : Color.gray.opacity(0.1))
                                )
                                .overlay(
                                    Circle().stroke(emoji == selected ? AppTheme.pink : Color.clear, lineWidth: 2)
                                )
                        }
                        .buttonStyle(.plain)
                    }
                }
                .padding(18)
            }
            .background(AppBackground())
            .navigationTitle("选择头像")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("关闭") { dismiss() }
                }
            }
        }
    }
}
