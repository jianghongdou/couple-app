import SwiftUI
import SceneKit

/// 外部让 3D 宠物做的动作（带序号，保证连续同样的动作也能重新触发）
enum Pet3DAction: Equatable {
    case none
    case happy(Int)
    case eat(Int)
    case sleep(Int)
    /// 被点了一下（形变抖动 + 高光闪）
    case poke(Int)
}

/// 宠物页的 Q 版 3D 宠物入口。
///
/// - 用 `UIViewRepresentable` 包一层 `SCNView`（SwiftUI 自带的 `SceneView` 无法控制
///   `preferredFramesPerSecond` / `isPlaying`，不满足性能要求）
/// - 相机固定在场景里，用户拖拽的是宠物本体（yaw / pitch），不是相机
/// - SceneKit 初始化失败时回退到静态 emoji，保证不白屏、不崩溃
struct Pet3DView: View {
    /// 宠物数据（等级 / 物种 / 心情 / 饱食度 / 精力）
    let pet: PetState
    /// 点一下宠物的回调
    var onReaction: (() -> Void)?
    /// 手指拖动回调：(水平位移, 垂直位移)，单位是点（可选）
    var onDrag: ((CGFloat, CGFloat) -> Void)?
    /// 由外部按钮触发的动作（喂食 / 陪玩 / 睡觉）
    var action: Pet3DAction = .none

    @Environment(\.colorScheme) private var colorScheme
    @Environment(\.scenePhase) private var scenePhase

    /// SceneKit 初始化失败时置 true → 回退静态展示
    @State private var buildFailed = false
    @State private var dragPitch: CGFloat = -5
    @State private var dragYaw: CGFloat = 16
    @State private var showHint = true

    private var visual: Pet3DVisualState {
        Pet3DVisualState.make(pet: pet, isDark: colorScheme == .dark)
    }

    var body: some View {
        ZStack {
            if buildFailed {
                Pet3DFallbackView(emoji: pet.emoji, name: pet.name)
            } else {
                Pet3DRepresentable(
                    visual: visual,
                    pitch: dragPitch,
                    yaw: dragYaw,
                    isActive: scenePhase == .active,
                    action: action,
                    onBuildFailure: {
                        // 极端情况（设备不支持 Metal / 显存不足）回退静态形象。
                        // 延到下一个 runloop，避免在视图更新过程中改状态。
                        DispatchQueue.main.async {
                            withAnimation(.easeInOut(duration: 0.25)) { buildFailed = true }
                        }
                    }
                )
                // 换物种时耳朵 / 尾巴 / 呆毛的几何结构会变，让 SwiftUI 重建整个场景，
                // 保证「奶猫 → 独角兽」这类整体换装是真的换了一整套部件。
                .id(pet.species)
                .contentShape(Rectangle())
                .gesture(
                    DragGesture(minimumDistance: 8)
                        .onChanged { value in
                            // 水平方向 → yaw（可 360° 转），垂直方向 → pitch（±34°）
                            let deltaYaw = value.translation.width * 0.55
                            let deltaPitch = value.translation.height * 0.40
                            dragYaw = Self.wrapDegrees(16 + deltaYaw)
                            dragPitch = Self.clampPitch(-5 + deltaPitch)
                            onDrag?(deltaYaw, deltaPitch)
                        }
                )
                .simultaneousGesture(
                    TapGesture().onEnded {
                        onReaction?()
                    }
                )

                // 操作提示：3 秒后淡出
                if showHint {
                    VStack {
                        Spacer()
                        Text("拖动可以 360° 转，点一下它会理你")
                            .font(.system(size: 11))
                            .padding(.horizontal, 12)
                            .padding(.vertical, 6)
                            .background(Capsule().fill(Color.black.opacity(0.28)))
                            .foregroundColor(.white)
                            .padding(.bottom, 8)
                    }
                    .allowsHitTesting(false)
                }
            }
        }
        .onAppear {
            // 进场后稍微延迟一下再淡出提示，避免和首帧渲染抢时间
            DispatchQueue.main.asyncAfter(deadline: .now() + 3.0) {
                withAnimation(.easeInOut(duration: 0.4)) { showHint = false }
            }
        }
    }

    // MARK: - 角度工具

    private static func clampPitch(_ value: CGFloat) -> CGFloat {
        max(-34, min(34, value))
    }

    /// 把角度收敛到 -180...180，避免长时间拖拽后数值过大
    private static func wrapDegrees(_ value: CGFloat) -> CGFloat {
        var result = value.truncatingRemainder(dividingBy: 360)
        if result > 180 { result -= 360 }
        if result < -180 { result += 360 }
        return result
    }
}

// MARK: - UIViewRepresentable

private struct Pet3DRepresentable: UIViewRepresentable {
    let visual: Pet3DVisualState
    let pitch: CGFloat
    let yaw: CGFloat
    let isActive: Bool
    let action: Pet3DAction
    let onBuildFailure: () -> Void

    func makeCoordinator() -> Coordinator {
        Coordinator()
    }

    func makeUIView(context: Context) -> SCNView {
        context.coordinator.ensureBuilt(visual: visual, onBuildFailure: onBuildFailure)
        // 构建失败时返回一个空的安全视图，由 SwiftUI 那层切到静态兜底
        guard let view = context.coordinator.view else {
            return SCNView(frame: .zero)
        }
        // 首个 frame 也把状态带上，避免初始就是「睡觉中」被漏掉
        context.coordinator.apply(
            visual: visual,
            pitch: pitch,
            yaw: yaw,
            isActive: isActive,
            action: action
        )
        return view
    }

    func updateUIView(_ uiView: SCNView, context: Context) {
        let coordinator = context.coordinator
        guard coordinator.view === uiView else { return }
        coordinator.apply(
            visual: visual,
            pitch: pitch,
            yaw: yaw,
            isActive: isActive,
            action: action
        )
    }

    static func dismantleUIView(_ uiView: SCNView, coordinator: Coordinator) {
        // 页面销毁：停止渲染并断开场景引用，帮助内存回收
        uiView.isPlaying = false
        uiView.scene = nil
    }

    final class Coordinator {
        private var controller: Pet3DController?
        var view: SCNView? { controller?.scnView }
        private var built = false
        private var lastVisual: Pet3DVisualState?
        private var lastAction: Pet3DAction = .none

        /// 只构建一次：失败就不再重试，直接让上层兜底
        func ensureBuilt(visual: Pet3DVisualState, onBuildFailure: () -> Void) {
            guard !built else { return }
            built = true
            guard let controller = Pet3DController(visual: visual) else {
                onBuildFailure()
                return
            }
            self.controller = controller
            self.lastVisual = visual
        }

        func apply(
            visual: Pet3DVisualState,
            pitch: CGFloat,
            yaw: CGFloat,
            isActive: Bool,
            action: Pet3DAction
        ) {
            guard let controller else { return }

            // 数据变化 → 更新体型 / 颜色 / 表情 / 亲密度
            if lastVisual != visual {
                lastVisual = visual
                controller.update(visual: visual)
            }
            controller.updateRotation(pitch: pitch, yaw: yaw)
            controller.setActive(isActive)

            // 外部动作触发（带序号，所以同样的动作连续点也会重新播）
            if action != lastAction {
                lastAction = action
                switch action {
                case .happy(_):
                    controller.playHappy()
                case .eat(_):
                    controller.playEat()
                case .sleep(_):
                    controller.nap()
                case .poke(_):
                    controller.poke()
                case .none:
                    break
                }
            }
        }
    }
}

// MARK: - 兜底：SceneKit 起不来时仍然展示宠物

/// 静态兜底展示：和改造前的宠物区域一致的视觉
struct Pet3DFallbackView: View {
    let emoji: String
    let name: String

    var body: some View {
        VStack(spacing: 8) {
            ZStack {
                Circle()
                    .fill(
                        RadialGradient(
                            colors: [AppTheme.pink.opacity(0.22), Color.clear],
                            center: .center,
                            startRadius: 10,
                            endRadius: 130
                        )
                    )
                    .frame(width: 220, height: 220)
                Text(emoji)
                    .font(.system(size: 104))
                    .shadow(color: AppTheme.pink.opacity(0.35), radius: 16, y: 8)
            }
            Text("3D 形象暂时加载不出来，先用静态形象陪你")
                .font(.system(size: 11))
                .foregroundColor(.secondary)
        }
        .frame(maxWidth: .infinity, minHeight: 240)
        .accessibilityElement(children: .combine)
        .accessibilityLabel("\(name)的静态形象")
    }
}
