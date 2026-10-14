import SwiftUI

/// AI 伙伴管理：列表 + 新增 / 编辑 / 删除。
///
/// 入口有两个：
/// 1. 聊天页顶部会话切换条末尾的「＋」（直接打开编辑页）；
/// 2. 设置页 →「AI 聊天」一行（push 打开本页）。
struct AIManageView: View {
    @EnvironmentObject var ai: AIChatService
    @EnvironmentObject var toast: ToastCenter
    @Environment(\.dismiss) private var dismiss

    /// true = 用 NavigationStack 包一层（作为 sheet 打开时用）；被 push 时为 false
    var wrapsInNavigation: Bool = true

    @State private var editingPartner: AIPartner?
    @State private var pendingDelete: AIPartner?
    @State private var showDeleteAlert = false

    private let accent = AppTheme.purple

    var body: some View {
        Group {
            if wrapsInNavigation {
                NavigationStack {
                    content
                }
            } else {
                content
            }
        }
        .onAppear {
            Task { await ai.loadPartners(force: true) }
            Task { await ai.loadProviders() }
        }
        .sheet(item: $editingPartner) { partner in
            AIPartnerEditView(partner: partner).appEnvironment()
        }
        .alert("删除这个 AI 伙伴？", isPresented: $showDeleteAlert) {
            Button("删除", role: .destructive) {
                if let partner = pendingDelete {
                    Task { await delete(partner) }
                }
            }
            Button("取消", role: .cancel) { pendingDelete = nil }
        } message: {
            Text("删除后与它的聊天记录也会从服务器一起删除，无法恢复。")
        }
    }

    private var content: some View {
        ScrollView {
            VStack(spacing: 16) {
                introCard

                if !ai.isFeatureAvailable {
                    unavailableCard
                } else {
                    if ai.needsProviderHint {
                        providerHintCard
                    }

                    if !ai.errorText.isEmpty {
                        Text(ai.errorText)
                            .font(.system(size: 12))
                            .foregroundColor(AppTheme.red)
                            .frame(maxWidth: .infinity, alignment: .leading)
                            .card()
                    }

                    if ai.partners.isEmpty {
                        EmptyStateView(
                            icon: "🤖",
                            title: "还没有 AI 伙伴",
                            message: "点下面的按钮创建第一个：给它起个名字、选个 emoji、写一段性格设定就可以了"
                        )
                        .card()
                    }

                    ForEach(ai.partners, id: \.id) { partner in
                        partnerCard(partner)
                    }

                    addButton
                }
            }
            .padding(16)
        }
        .background(AppBackground())
        .navigationTitle("AI 聊天伙伴")
        .navigationBarTitleDisplayMode(.inline)
        .toolbar {
            ToolbarItem(placement: .navigationBarTrailing) {
                Button {
                    refresh()
                } label: {
                    Image(systemName: "arrow.clockwise")
                }
            }
        }
    }

    // MARK: - 说明

    private var introCard: some View {
        SectionCard(title: "AI 聊天", icon: "sparkles", subtitle: "最多 10 个") {
            VStack(alignment: .leading, spacing: 6) {
                bullet("AI 伙伴存在服务器上，绑定的两个人都能看到和使用")
                bullet("模型 Key 只存在服务端，App 里不保存、也拿不到密钥")
                bullet("与 AI 的聊天和情侣聊天完全分开，不影响、不同步")
                bullet("未绑定情侣也能使用 AI 聊天")
            }
        }
    }

    private var unavailableCard: some View {
        SectionCard(title: "暂不可用", icon: "exclamationmark.triangle.fill") {
            VStack(alignment: .leading, spacing: 10) {
                Text(ai.guidanceText.isEmpty ? "AI 聊天需要先连接服务器并登录" : ai.guidanceText)
                    .font(.system(size: 13))
                Button {
                    refresh()
                } label: {
                    Label("重试", systemImage: "arrow.clockwise")
                }
                .buttonStyle(SoftButtonStyle(color: accent))
            }
        }
    }

    private var providerHintCard: some View {
        SectionCard(title: "服务端未配置供应商", icon: "wrench.and.screwdriver.fill") {
            VStack(alignment: .leading, spacing: 10) {
                Text(AIErrorMapper.notConfiguredText)
                    .font(.system(size: 13))
                Text("供应商 = 服务端后台填好的大模型接入（DeepSeek / OpenAI 兼容 / Anthropic / Gemini…）。")
                    .font(.system(size: 11))
                    .foregroundColor(.secondary)
                Button {
                    refresh()
                } label: {
                    Label("刷新供应商列表", systemImage: "arrow.clockwise")
                }
                .buttonStyle(SoftButtonStyle(color: accent))
            }
        }
    }

    // MARK: - 单个伙伴

    private func partnerCard(_ partner: AIPartner) -> some View {
        SectionCard(
            title: partner.displayName,
            icon: "person.crop.circle",
            subtitle: partner.enabled ? "已启用" : "已停用"
        ) {
            VStack(alignment: .leading, spacing: 10) {
                HStack(spacing: 12) {
                    EmojiAvatarView(emoji: partner.emoji, size: 46, ringColor: accent)
                    VStack(alignment: .leading, spacing: 3) {
                        Text(partner.modelSummary)
                            .font(.system(size: 12))
                            .foregroundColor(.secondary)
                        Text("temperature \(String(format: "%.2f", partner.temperature))")
                            .font(.system(size: 11))
                            .foregroundColor(.secondary)
                    }
                    Spacer()
                    if !partner.enabled {
                        TagChip(text: "停用", color: AppTheme.grayButton)
                    }
                }

                if !partner.persona.isEmpty {
                    Text(partner.persona)
                        .font(.system(size: 12))
                        .foregroundColor(.secondary)
                        .lineLimit(3)
                } else {
                    Text("没有写性格设定，会用服务端的默认提示词。")
                        .font(.system(size: 12))
                        .foregroundColor(.secondary)
                }

                HStack(spacing: 10) {
                    Button {
                        editingPartner = partner
                    } label: {
                        Label("编辑", systemImage: "square.and.pencil")
                            .font(.system(size: 12))
                    }
                    .buttonStyle(SoftButtonStyle(color: accent))

                    Button {
                        pendingDelete = partner
                        showDeleteAlert = true
                    } label: {
                        Label("删除", systemImage: "trash")
                            .font(.system(size: 12))
                    }
                    .buttonStyle(SoftButtonStyle(color: AppTheme.red))
                }
            }
        }
    }

    // MARK: - 新增

    private var addButton: some View {
        VStack(spacing: 8) {
            Button {
                guard ai.canAddPartner else {
                    toast.error("最多只能创建 10 个 AI 伙伴")
                    return
                }
                editingPartner = AIPartner()
            } label: {
                Label(ai.canAddPartner ? "新增 AI 伙伴" : "已达上限（10 个）", systemImage: "plus.circle.fill")
            }
            .buttonStyle(PrimaryButtonStyle(color: accent, enabled: ai.canAddPartner))

            Text("当前 \(ai.partners.count) / \(AIChatService.maxPartners) 个")
                .font(.system(size: 11))
                .foregroundColor(.secondary)
        }
        .padding(.top, 4)
    }

    // MARK: - 工具

    private func bullet(_ text: String) -> some View {
        HStack(alignment: .top, spacing: 6) {
            Text("•").foregroundColor(accent)
            Text(text)
                .font(.system(size: 12))
                .foregroundColor(.secondary)
            Spacer(minLength: 0)
        }
    }

    private func refresh() {
        Task {
            await ai.loadPartners(force: true)
            await ai.loadProviders()
        }
    }

    private func delete(_ partner: AIPartner) async {
        let ok = await ai.deletePartner(id: partner.id)
        pendingDelete = nil
        if ok {
            toast.success("已删除「\(partner.displayName)」")
        } else {
            toast.error(ai.errorText.isEmpty ? "删除失败，请重试" : ai.errorText)
        }
    }
}

// MARK: - 新增 / 编辑

/// AI 伙伴编辑页：名字 / 头像 emoji / 性格设定 / 供应商 / 模型 / temperature / 启用开关
struct AIPartnerEditView: View {
    /// id 为空 = 新增
    let partner: AIPartner

    @EnvironmentObject var ai: AIChatService
    @EnvironmentObject var toast: ToastCenter
    @Environment(\.dismiss) private var dismiss

    @State private var name = ""
    @State private var avatarEmoji = "🤖"
    @State private var persona = ""
    @State private var providerId = ""
    @State private var model = ""
    @State private var temperature = 0.8
    @State private var enabled = true
    @State private var showEmojiPicker = false
    @State private var showDeleteAlert = false
    @State private var errorText = ""
    @State private var isSaving = false
    @State private var didLoad = false

    private let accent = AppTheme.purple

    private var isNew: Bool { partner.id.isEmpty }
    private var trimmedName: String { name.trimmingCharacters(in: .whitespacesAndNewlines) }
    private var canSave: Bool { !trimmedName.isEmpty && !isSaving && ai.canUseNetwork }

    /// 预设性格模板
    private static let personas: [(title: String, emoji: String, text: String)] = [
        ("温柔男友", "🥰", "你是温柔体贴的男朋友，说话简短自然、会关心人也会撒娇，偶尔用可爱的语气词，不要长篇大论说教。"),
        ("毒舌闺蜜", "😏", "你是嘴毒心软的闺蜜，会吐槽也会护着我，说话直接、幽默、偶尔夸张，但最后总会站在我这边。"),
        ("英语陪练", "🌍", "You are a patient English conversation partner. 先用英文回复，再用一行中文给出对应翻译，并温和地指出我句子里的错误、给出更地道的说法。"),
        ("工作助理", "💼", "你是严谨高效的工作助理，回答条理清晰、先给结论再给要点，尽量用列表，不闲聊。")
    ]

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(spacing: 16) {
                    if !ai.canUseNetwork {
                        Text("AI 聊天需要先连接服务器并登录")
                            .font(.system(size: 12))
                            .foregroundColor(AppTheme.red)
                            .frame(maxWidth: .infinity, alignment: .leading)
                            .card()
                    }

                    basicCard
                    personaCard
                    modelCard
                    stateCard

                    if !errorText.isEmpty {
                        Text(errorText)
                            .font(.system(size: 12))
                            .foregroundColor(AppTheme.red)
                            .frame(maxWidth: .infinity, alignment: .leading)
                            .card()
                    }

                    saveButton

                    if !isNew {
                        Button {
                            showDeleteAlert = true
                        } label: {
                            Label("删除这个 AI 伙伴", systemImage: "trash")
                        }
                        .buttonStyle(SoftButtonStyle(color: AppTheme.red))
                    }
                }
                .padding(16)
            }
            .background(AppBackground())
            .navigationTitle(isNew ? "新增 AI 伙伴" : "编辑 AI 伙伴")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("取消") { dismiss() }
                }
            }
            .sheet(isPresented: $showEmojiPicker) {
                AvatarPickerView(selected: $avatarEmoji)
                    .appEnvironment()
            }
            .alert("删除这个 AI 伙伴？", isPresented: $showDeleteAlert) {
                Button("删除", role: .destructive) {
                    Task { await performDelete() }
                }
                Button("取消", role: .cancel) { }
            } message: {
                Text("删除后与它的聊天记录也会从服务器一起删除，无法恢复。")
            }
            .onAppear {
                loadOnce()
            }
        }
    }

    // MARK: - 基本信息

    private var basicCard: some View {
        SectionCard(title: "基本信息", icon: "person.text.rectangle") {
            VStack(alignment: .leading, spacing: 14) {
                VStack(alignment: .leading, spacing: 6) {
                    HStack {
                        Text("名字")
                            .font(.system(size: 13, weight: .medium))
                            .foregroundColor(.secondary)
                        Spacer()
                        Text("\(trimmedName.count)/20")
                            .font(.system(size: 11))
                            .foregroundColor(trimmedName.count > 20 ? AppTheme.red : .secondary)
                    }
                    TextField("例如：小助手", text: $name)
                        .padding(.horizontal, 14)
                        .padding(.vertical, 12)
                        .background(
                            RoundedRectangle(cornerRadius: 12, style: .continuous)
                                .fill(Color.gray.opacity(0.1))
                        )
                        .onChange(of: name) { value in
                            if value.count > 20 { name = String(value.prefix(20)) }
                        }
                }

                VStack(alignment: .leading, spacing: 6) {
                    Text("头像 emoji")
                        .font(.system(size: 13, weight: .medium))
                        .foregroundColor(.secondary)
                    HStack(spacing: 12) {
                        EmojiAvatarView(emoji: avatarEmoji, size: 52, ringColor: accent)
                        Button {
                            showEmojiPicker = true
                        } label: {
                            Label("选择 emoji", systemImage: "face.smiling")
                                .font(.system(size: 12))
                        }
                        .buttonStyle(SoftButtonStyle(color: accent))
                        Spacer()
                    }
                }
            }
        }
    }

    // MARK: - 性格设定

    private var personaCard: some View {
        SectionCard(title: "性格设定", icon: "text.quote", subtitle: "\(persona.count)/2000") {
            VStack(alignment: .leading, spacing: 10) {
                Text("这段文字会作为 system prompt 发给大模型，决定它的说话风格。")
                    .font(.system(size: 11))
                    .foregroundColor(.secondary)

                ScrollView(.horizontal, showsIndicators: false) {
                    HStack(spacing: 8) {
                        ForEach(Self.personas, id: \.title) { item in
                            Button {
                                // 点预设覆盖当前性格设定
                                persona = String(item.text.prefix(2000))
                                Haptics.selection()
                            } label: {
                                Text("\(item.emoji) \(item.title)")
                                    .font(.system(size: 12, weight: .medium))
                                    .foregroundColor(accent)
                                    .padding(.horizontal, 12)
                                    .padding(.vertical, 7)
                                    .background(
                                        Capsule().fill(accent.opacity(0.14))
                                    )
                            }
                            .buttonStyle(.plain)
                        }
                    }
                    .padding(.vertical, 2)
                }

                TextEditor(text: $persona)
                    .font(.system(size: 14))
                    .frame(minHeight: 130)
                    .padding(8)
                    .background(
                        RoundedRectangle(cornerRadius: 12, style: .continuous)
                            .fill(Color.gray.opacity(0.1))
                    )
                    .onChange(of: persona) { value in
                        if value.count > 2000 { persona = String(value.prefix(2000)) }
                    }

                HStack(spacing: 10) {
                    Button {
                        persona = ""
                    } label: {
                        Label("清空", systemImage: "xmark.circle")
                            .font(.system(size: 12))
                    }
                    .buttonStyle(SoftButtonStyle(color: AppTheme.grayButton))
                    Spacer()
                    Text(persona.isEmpty ? "留空表示不额外设定性格" : "点预设可覆盖当前内容")
                        .font(.system(size: 11))
                        .foregroundColor(.secondary)
                }
            }
        }
    }

    // MARK: - 供应商与模型

    private var modelCard: some View {
        SectionCard(title: "供应商与模型", icon: "cpu") {
            VStack(alignment: .leading, spacing: 12) {
                HStack {
                    Text("供应商")
                        .font(.system(size: 13, weight: .medium))
                        .foregroundColor(.secondary)
                    Spacer()
                    Button {
                        Task { await ai.loadProviders() }
                    } label: {
                        Label("刷新", systemImage: "arrow.clockwise")
                            .font(.system(size: 11))
                    }
                    .buttonStyle(.plain)
                    .foregroundColor(accent)
                }

                Picker("供应商", selection: providerSelection) {
                    Text("默认（服务端设置）").tag("")
                    ForEach(ai.providers, id: \.id) { item in
                        Text(item.displayName).tag(item.id)
                    }
                }
                .pickerStyle(.menu)
                .frame(maxWidth: .infinity, alignment: .leading)

                if !providerId.isEmpty {
                    HStack {
                        Text("模型")
                            .font(.system(size: 13, weight: .medium))
                            .foregroundColor(.secondary)
                        Spacer()
                    }
                    Picker("模型", selection: $model) {
                        Text("默认模型").tag("")
                        ForEach(selectedModels, id: \.self) { item in
                            Text(item).tag(item)
                        }
                    }
                    .pickerStyle(.menu)
                    .frame(maxWidth: .infinity, alignment: .leading)
                }

                VStack(alignment: .leading, spacing: 6) {
                    HStack {
                        Text("temperature")
                            .font(.system(size: 13, weight: .medium))
                            .foregroundColor(.secondary)
                        Spacer()
                        Text(String(format: "%.1f", temperature))
                            .font(.system(size: 12, weight: .semibold))
                            .foregroundColor(accent)
                    }
                    Slider(value: $temperature, in: 0...2, step: 0.1)
                        .tint(accent)
                    Text("越小越稳定，越大越有想象力（默认 0.8）")
                        .font(.system(size: 11))
                        .foregroundColor(.secondary)
                }

                if ai.providers.isEmpty {
                    Text(ai.needsProviderHint
                         ? AIErrorMapper.notConfiguredText + "（也可以先不选，用服务端默认）"
                         : "暂时没拿到供应商列表，可以先留空用服务端默认设置。")
                        .font(.system(size: 11))
                        .foregroundColor(.secondary)
                }
            }
        }
    }

    private var selectedModels: [String] {
        guard let provider = ai.providers.first(where: { $0.id == providerId }) else { return [] }
        return provider.modelOptions
    }

    /// 供应商下拉的绑定：只有用户主动切换时才清空模型，
    /// 避免 onAppear 回填时把已保存的模型名冲掉。
    private var providerSelection: Binding<String> {
        Binding(
            get: { providerId },
            set: { value in
                if value != providerId {
                    providerId = value
                    model = ""
                }
            }
        )
    }

    // MARK: - 启用开关

    private var stateCard: some View {
        SectionCard(title: "状态", icon: "power") {
            Toggle(isOn: $enabled) {
                VStack(alignment: .leading, spacing: 2) {
                    Text("启用这个 AI 伙伴")
                        .font(.system(size: 14))
                    Text("停用后在聊天页仍然可见，但发消息会被服务端拒绝")
                        .font(.system(size: 11))
                        .foregroundColor(.secondary)
                }
            }
            .tint(accent)
        }
    }

    private var saveButton: some View {
        Button {
            Task { await save() }
        } label: {
            Text(isSaving ? "保存中…" : (isNew ? "创建" : "保存修改"))
        }
        .buttonStyle(PrimaryButtonStyle(color: accent, enabled: canSave))
        .disabled(!canSave)
    }

    // MARK: - 行为

    private func loadOnce() {
        guard !didLoad else { return }
        didLoad = true
        name = partner.name
        avatarEmoji = partner.emoji
        persona = partner.persona
        providerId = partner.providerId
        model = partner.model
        temperature = min(2, max(0, partner.temperature))
        enabled = partner.enabled
        Task { await ai.loadProviders() }
    }

    private func save() async {
        guard canSave else { return }
        isSaving = true
        errorText = ""

        let finalName = String(trimmedName.prefix(20))
        let finalEmoji = String(avatarEmoji.prefix(4))
        let finalPersona = String(persona.prefix(2000))
        // 拿不到供应商列表时不擅自丢弃用户填的模型名；能拿到时保证模型属于该供应商
        var finalModel = String(model.prefix(64))
        if !ai.providers.isEmpty,
           !providerId.isEmpty,
           !finalModel.isEmpty,
           !selectedModels.contains(finalModel) {
            finalModel = ""
        }
        let value = min(2, max(0, temperature))

        let ok: Bool
        if isNew {
            ok = await ai.createPartner(
                name: finalName,
                avatarEmoji: finalEmoji,
                persona: finalPersona,
                providerId: providerId,
                model: finalModel,
                temperature: value,
                enabled: enabled
            )
        } else {
            ok = await ai.updatePartner(
                id: partner.id,
                name: finalName,
                avatarEmoji: finalEmoji,
                persona: finalPersona,
                providerId: providerId,
                model: finalModel,
                temperature: value,
                enabled: enabled
            )
        }

        isSaving = false
        if ok {
            toast.success(isNew ? "已创建「\(finalName)」" : "已保存")
            dismiss()
        } else {
            // 服务端 409 = 超过 10 个上限
            errorText = ai.errorText.isEmpty ? "保存失败，请重试" : ai.errorText
            toast.error(errorText)
        }
    }

    private func performDelete() async {
        let ok = await ai.deletePartner(id: partner.id)
        if ok {
            toast.success("已删除「\(partner.displayName)」")
            dismiss()
        } else {
            errorText = ai.errorText.isEmpty ? "删除失败，请重试" : ai.errorText
        }
    }
}
