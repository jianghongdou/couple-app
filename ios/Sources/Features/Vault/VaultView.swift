import SwiftUI
import UIKit
import LocalAuthentication

/// 密码本（本地 AES-GCM 加密，永不联网）
struct VaultView: View {
    @EnvironmentObject var store: LocalStore
    @EnvironmentObject var toast: ToastCenter

    @State private var search = ""
    @State private var editing: VaultItem?
    @State private var showAdd = false
    @State private var revealed: Set<String> = []
    @State private var category = "全部"

    private var categories: [String] {
        ["全部"] + VaultItem.categories
    }

    private var items: [VaultItem] {
        var list = store.vaultItems
        if category != "全部" {
            list = list.filter { $0.category == category }
        }
        let key = search.trimmingCharacters(in: .whitespaces)
        if !key.isEmpty {
            list = list.filter {
                $0.title.localizedCaseInsensitiveContains(key)
                    || $0.username.localizedCaseInsensitiveContains(key)
                    || $0.note.localizedCaseInsensitiveContains(key)
            }
        }
        return list.sorted { $0.updatedAt > $1.updatedAt }
    }

    var body: some View {
        Group {
            if !store.hasVault {
                VaultCreateView()
            } else if !store.vaultUnlocked {
                VaultUnlockView()
            } else {
                unlockedList
            }
        }
        .background(AppBackground())
        .navigationTitle("密码本")
        .navigationBarTitleDisplayMode(.inline)
    }

    private var unlockedList: some View {
        ScrollView {
            VStack(spacing: 14) {
                HStack(spacing: 10) {
                    HStack(spacing: 8) {
                        Image(systemName: "magnifyingglass")
                            .foregroundColor(.secondary)
                        TextField("搜索名称 / 账号 / 备注", text: $search)
                            .textInputAutocapitalization(.never)
                            .autocorrectionDisabled(true)
                    }
                    .padding(.horizontal, 12)
                    .padding(.vertical, 10)
                    .background(
                        RoundedRectangle(cornerRadius: 12, style: .continuous)
                            .fill(Color.gray.opacity(0.1))
                    )

                    Button {
                        store.lockVault()
                        Haptics.tap()
                    } label: {
                        Image(systemName: "lock.fill")
                            .foregroundColor(AppTheme.red)
                            .frame(width: 40, height: 40)
                            .background(Circle().fill(AppTheme.red.opacity(0.12)))
                    }
                    .buttonStyle(.plain)
                }

                ScrollView(.horizontal, showsIndicators: false) {
                    HStack(spacing: 8) {
                        ForEach(categories, id: \.self) { item in
                            Button {
                                category = item
                            } label: {
                                TagChip(text: item, color: AppTheme.red, filled: category == item)
                            }
                            .buttonStyle(.plain)
                        }
                    }
                }

                if items.isEmpty {
                    EmptyStateView(icon: "🔐", title: "还没有保存的密码", message: "点右上角 + 添加账号，数据只加密保存在本机")
                        .card()
                } else {
                    ForEach(items, id: \.id) { item in
                        vaultRow(item)
                    }
                }

                HStack(alignment: .top, spacing: 8) {
                    Image(systemName: "lock.shield.fill")
                        .foregroundColor(AppTheme.mint)
                    Text("密码本使用 AES-GCM 加密后存在本机，密钥由你的主密码派生，不上传服务器、不参与同步。忘记主密码将无法恢复。")
                        .font(.system(size: 11))
                        .foregroundColor(.secondary)
                }
                .card()
            }
            .padding(16)
        }
        .toolbar {
            ToolbarItem(placement: .navigationBarTrailing) {
                Button {
                    showAdd = true
                } label: {
                    Image(systemName: "plus.circle.fill")
                }
            }
        }
        .sheet(isPresented: $showAdd) {
            VaultItemEditView(item: nil).appEnvironment()
        }
        .sheet(item: $editing) { item in
            VaultItemEditView(item: item).appEnvironment()
        }
    }

    private func vaultRow(_ item: VaultItem) -> some View {
        VStack(alignment: .leading, spacing: 10) {
            HStack(spacing: 10) {
                Text(item.categoryEmoji)
                    .font(.system(size: 22))
                VStack(alignment: .leading, spacing: 2) {
                    HStack(spacing: 6) {
                        Text(item.title.isEmpty ? "未命名" : item.title)
                            .font(.system(size: 15, weight: .semibold))
                        if item.favorite {
                            Text("⭐️").font(.system(size: 11))
                        }
                    }
                    if !item.username.isEmpty {
                        Text(item.username)
                            .font(.system(size: 12))
                            .foregroundColor(.secondary)
                    }
                }
                Spacer()
                Button {
                    editing = item
                } label: {
                    Image(systemName: "square.and.pencil")
                        .foregroundColor(.secondary)
                }
                .buttonStyle(.plain)
            }

            HStack(spacing: 10) {
                Text(revealed.contains(item.id) ? item.password : String(repeating: "•", count: min(12, max(6, item.password.count))))
                    .font(.system(size: 15, design: .monospaced))
                    .lineLimit(1)

                Spacer()

                Button {
                    if revealed.contains(item.id) {
                        revealed.remove(item.id)
                    } else {
                        revealed.insert(item.id)
                    }
                } label: {
                    Image(systemName: revealed.contains(item.id) ? "eye.slash" : "eye")
                        .foregroundColor(.secondary)
                }
                .buttonStyle(.plain)

                Button {
                    UIPasteboard.general.string = item.password
                    Haptics.success()
                    toast.success("密码已复制，30 秒内有效")
                    DispatchQueue.main.asyncAfter(deadline: .now() + 30) {
                        if UIPasteboard.general.string == item.password {
                            UIPasteboard.general.string = ""
                        }
                    }
                } label: {
                    Image(systemName: "doc.on.doc")
                        .foregroundColor(AppTheme.pink)
                }
                .buttonStyle(.plain)
            }

            HStack(spacing: 8) {
                ForEach(0..<4, id: \.self) { index in
                    Capsule()
                        .fill(index < item.strength ? strengthColor(item.strength) : Color.gray.opacity(0.2))
                        .frame(height: 4)
                }
                Text(item.strengthText)
                    .font(.system(size: 10))
                    .foregroundColor(.secondary)
            }
        }
        .card()
        .contextMenu {
            Button(role: .destructive) {
                store.deleteVaultItem(item.id)
                toast.show("已删除")
            } label: {
                Label("删除", systemImage: "trash")
            }
        }
    }

    private func strengthColor(_ strength: Int) -> Color {
        switch strength {
        case 0, 1: return AppTheme.red
        case 2: return AppTheme.orange
        case 3: return AppTheme.mint
        default: return AppTheme.blue
        }
    }
}

// MARK: - 创建主密码

struct VaultCreateView: View {
    @EnvironmentObject var store: LocalStore
    @EnvironmentObject var toast: ToastCenter

    @State private var password = ""
    @State private var confirm = ""
    @State private var isWorking = false
    @State private var enableBiometric = true

    var body: some View {
        ScrollView {
            VStack(spacing: 18) {
                VStack(spacing: 10) {
                    Text("🔐").font(.system(size: 60))
                    Text("设置密码本主密码")
                        .font(.system(size: 20, weight: .bold))
                    Text("这个密码只用于加密本机的密码本，服务器不会保存，忘记后无法找回。")
                        .font(.system(size: 12))
                        .foregroundColor(.secondary)
                        .multilineTextAlignment(.center)
                }
                .padding(.top, 20)

                InputField(title: "主密码", placeholder: "至少 6 位，建议更长", text: $password, isSecure: true)
                InputField(title: "确认主密码", placeholder: "再输入一次", text: $confirm, isSecure: true)

                Toggle(isOn: $enableBiometric) {
                    VStack(alignment: .leading, spacing: 2) {
                        Text("开启 Face ID / 指纹快速解锁")
                            .font(.system(size: 14, weight: .medium))
                        Text("主密码会保存在系统钥匙串里，由系统生物识别保护")
                            .font(.system(size: 11))
                            .foregroundColor(.secondary)
                    }
                }
                .tint(AppTheme.pink)
                .card()

                if password.count > 0 && password != confirm {
                    Text("两次输入的密码不一致")
                        .font(.system(size: 12))
                        .foregroundColor(AppTheme.red)
                        .frame(maxWidth: .infinity, alignment: .leading)
                }

                Button {
                    create()
                } label: {
                    HStack(spacing: 8) {
                        if isWorking { ProgressView().tint(.white) }
                        Text("创建密码本")
                    }
                }
                .buttonStyle(PrimaryButtonStyle(
                    color: AppTheme.red,
                    enabled: !isWorking && password.count >= 6 && password == confirm
                ))
                .disabled(isWorking || password.count < 6 || password != confirm)
            }
            .padding(18)
        }
    }

    private func create() {
        isWorking = true
        let value = password
        let remember = enableBiometric
        Task {
            var error: String?
            do {
                try store.createVault(password: value)
                if remember {
                    Keychain.set(value, for: "vaultMasterPassword")
                }
            } catch {
                error = "创建失败：\(error.localizedDescription)"
            }
            await MainActor.run {
                isWorking = false
                password = ""
                confirm = ""
                if let error = error {
                    toast.error(error)
                } else {
                    Haptics.success()
                    toast.success("密码本已创建")
                }
            }
        }
    }
}

// MARK: - 解锁

struct VaultUnlockView: View {
    @EnvironmentObject var store: LocalStore
    @EnvironmentObject var toast: ToastCenter

    @State private var password = ""
    @State private var isWorking = false
    @State private var errorText = ""
    @State private var biometricAvailable = false
    @State private var hasStoredPassword = false
    @State private var attempts = 0

    var body: some View {
        ScrollView {
            VStack(spacing: 18) {
                VStack(spacing: 10) {
                    Text("🔒").font(.system(size: 60))
                    Text("密码本已锁定")
                        .font(.system(size: 20, weight: .bold))
                    Text("输入主密码解锁")
                        .font(.system(size: 12))
                        .foregroundColor(.secondary)
                }
                .padding(.top, 24)

                InputField(title: "主密码", placeholder: "输入主密码", text: $password, isSecure: true)

                if !errorText.isEmpty {
                    Text(errorText)
                        .font(.system(size: 12))
                        .foregroundColor(AppTheme.red)
                        .frame(maxWidth: .infinity, alignment: .leading)
                }

                Button {
                    unlock(with: password)
                } label: {
                    HStack(spacing: 8) {
                        if isWorking { ProgressView().tint(.white) }
                        Text("解锁")
                    }
                }
                .buttonStyle(PrimaryButtonStyle(color: AppTheme.red, enabled: !isWorking && !password.isEmpty))
                .disabled(isWorking || password.isEmpty)

                if biometricAvailable && hasStoredPassword {
                    Button {
                        unlockWithBiometrics()
                    } label: {
                        Label("用 Face ID / 指纹解锁", systemImage: "faceid")
                    }
                    .buttonStyle(PrimaryButtonStyle(color: AppTheme.blue))
                }

                Text("主密码只用于本机解密。如果忘记了，只能清空密码本重新开始（已保存的账号无法恢复）。")
                    .font(.system(size: 11))
                    .foregroundColor(.secondary)
                    .multilineTextAlignment(.center)

                if attempts >= 3 {
                    Button {
                        store.destroyVault()
                        Keychain.remove("vaultMasterPassword")
                        toast.show("密码本已清空")
                    } label: {
                        Text("忘记密码？清空密码本")
                    }
                    .buttonStyle(SoftButtonStyle(color: AppTheme.red))
                }
            }
            .padding(18)
        }
        .onAppear(perform: prepare)
    }

    private func prepare() {
        hasStoredPassword = Keychain.get("vaultMasterPassword") != nil
        let context = LAContext()
        var error: NSError?
        biometricAvailable = context.canEvaluatePolicy(.deviceOwnerAuthenticationWithBiometrics, error: &error)
        if biometricAvailable && hasStoredPassword {
            unlockWithBiometrics()
        }
    }

    private func unlock(with value: String) {
        guard !value.isEmpty else { return }
        isWorking = true
        errorText = ""
        let input = value
        Task {
            var failure: String?
            do {
                try store.unlockVault(password: input)
            } catch {
                failure = "主密码不正确"
            }
            await MainActor.run {
                isWorking = false
                password = ""
                if let failure = failure {
                    attempts += 1
                    errorText = failure
                    Haptics.error()
                } else {
                    Haptics.success()
                    toast.success("密码本已解锁")
                }
            }
        }
    }

    private func unlockWithBiometrics() {
        guard let stored = Keychain.get("vaultMasterPassword") else { return }
        let context = LAContext()
        context.localizedReason = "解锁密码本"
        var error: NSError?
        guard context.canEvaluatePolicy(.deviceOwnerAuthenticationWithBiometrics, error: &error) else {
            return
        }
        isWorking = true
        context.evaluatePolicy(.deviceOwnerAuthenticationWithBiometrics, localizedReason: "解锁密码本") { success, _ in
            DispatchQueue.main.async {
                isWorking = false
                if success {
                    unlock(with: stored)
                }
            }
        }
    }
}

// MARK: - 新增 / 编辑条目

struct VaultItemEditView: View {
    @EnvironmentObject var store: LocalStore
    @EnvironmentObject var toast: ToastCenter
    @Environment(\.dismiss) private var dismiss

    let item: VaultItem?

    @State private var title = ""
    @State private var username = ""
    @State private var password = ""
    @State private var url = ""
    @State private var note = ""
    @State private var category = "网站"
    @State private var favorite = false
    @State private var showPassword = false

    private var isEditing: Bool { item != nil }

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(spacing: 16) {
                    InputField(title: "名称", placeholder: "例如：微信 / 银行卡 / WiFi", text: $title)
                    InputField(title: "账号", placeholder: "手机号 / 邮箱 / 卡号", text: $username)

                    VStack(alignment: .leading, spacing: 6) {
                        HStack {
                            Text("密码")
                                .font(.system(size: 13, weight: .medium))
                                .foregroundColor(.secondary)
                            Spacer()
                            Button {
                                password = VaultItemEditView.generatePassword()
                                showPassword = true
                                Haptics.selection()
                            } label: {
                                Label("随机生成", systemImage: "dice")
                                    .font(.system(size: 12))
                            }
                            .buttonStyle(.plain)
                            .foregroundColor(AppTheme.pink)
                        }
                        HStack(spacing: 8) {
                            if showPassword {
                                TextField("密码", text: $password)
                                    .textInputAutocapitalization(.never)
                                    .autocorrectionDisabled(true)
                                    .font(.system(size: 15, design: .monospaced))
                            } else {
                                SecureField("密码", text: $password)
                                    .font(.system(size: 15, design: .monospaced))
                            }
                            Button {
                                showPassword.toggle()
                            } label: {
                                Image(systemName: showPassword ? "eye.slash" : "eye")
                                    .foregroundColor(.secondary)
                            }
                            .buttonStyle(.plain)
                        }
                        .padding(.horizontal, 14)
                        .padding(.vertical, 12)
                        .background(
                            RoundedRectangle(cornerRadius: 12, style: .continuous)
                                .fill(Color.gray.opacity(0.1))
                        )
                    }

                    InputField(title: "网址 / 位置（可选）", placeholder: "https://example.com", text: $url, keyboard: .URL)

                    VStack(alignment: .leading, spacing: 8) {
                        Text("分类")
                            .font(.system(size: 13, weight: .medium))
                            .foregroundColor(.secondary)
                        LazyVGrid(columns: [GridItem(.adaptive(minimum: 72), spacing: 8)], spacing: 8) {
                            ForEach(VaultItem.categories, id: \.self) { value in
                                Button {
                                    category = value
                                } label: {
                                    TagChip(text: value, color: AppTheme.red, filled: category == value)
                                }
                                .buttonStyle(.plain)
                            }
                        }
                    }
                    .frame(maxWidth: .infinity, alignment: .leading)

                    VStack(alignment: .leading, spacing: 6) {
                        Text("备注（可选）")
                            .font(.system(size: 13, weight: .medium))
                            .foregroundColor(.secondary)
                        TextField("例如：绑定手机、密保问题", text: $note, axis: .vertical)
                            .lineLimit(2...5)
                            .padding(.horizontal, 14)
                            .padding(.vertical, 12)
                            .background(
                                RoundedRectangle(cornerRadius: 12, style: .continuous)
                                    .fill(Color.gray.opacity(0.1))
                            )
                    }

                    Toggle(isOn: $favorite) {
                        Text("标记为常用")
                            .font(.system(size: 14, weight: .medium))
                    }
                    .tint(AppTheme.pink)
                    .card()

                    Button {
                        save()
                    } label: {
                        Text(isEditing ? "保存修改" : "保存到密码本")
                    }
                    .buttonStyle(PrimaryButtonStyle(
                        color: AppTheme.red,
                        enabled: !title.trimmingCharacters(in: .whitespaces).isEmpty
                    ))
                    .disabled(title.trimmingCharacters(in: .whitespaces).isEmpty)

                    if isEditing {
                        Button {
                            if let item = item {
                                store.deleteVaultItem(item.id)
                                toast.show("已删除")
                            }
                            dismiss()
                        } label: {
                            Text("删除这条记录")
                        }
                        .buttonStyle(PrimaryButtonStyle(color: AppTheme.grayButton))
                    }
                }
                .padding(18)
            }
            .background(AppBackground())
            .navigationTitle(isEditing ? "编辑条目" : "新增条目")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("取消") { dismiss() }
                }
            }
            .onAppear {
                if let item = item {
                    title = item.title
                    username = item.username
                    password = item.password
                    url = item.url
                    note = item.note
                    category = item.category
                    favorite = item.favorite
                }
            }
        }
    }

    private func save() {
        var value = item ?? VaultItem()
        if item == nil {
            value = VaultItem(
                title: title.trimmingCharacters(in: .whitespaces),
                username: username,
                password: password,
                url: url,
                note: note,
                category: category
            )
        } else {
            value.title = title.trimmingCharacters(in: .whitespaces)
            value.username = username
            value.password = password
            value.url = url
            value.note = note
            value.category = category
        }
        value.favorite = favorite
        store.upsertVaultItem(value)
        Haptics.success()
        toast.success("已加密保存到本机")
        dismiss()
    }

    /// 生成一个高强度的随机密码
    static func generatePassword(length: Int = 18) -> String {
        let lower = Array("abcdefghijkmnopqrstuvwxyz")
        let upper = Array("ABCDEFGHJKLMNPQRSTUVWXYZ")
        let digits = Array("23456789")
        let symbols = Array("!@#$%^&*-_=+?")
        let all = lower + upper + digits + symbols

        var result: [Character] = [
            lower.randomElement() ?? "a",
            upper.randomElement() ?? "A",
            digits.randomElement() ?? "2",
            symbols.randomElement() ?? "!"
        ]
        while result.count < length {
            result.append(all.randomElement() ?? "x")
        }
        return String(result.shuffled())
    }
}
