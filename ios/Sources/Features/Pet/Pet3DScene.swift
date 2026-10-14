import Foundation
import SwiftUI
import SceneKit
import Metal

// MARK: - 3D 宠物状态

/// 3D 形象需要的全部「状态输入」。
/// 直接由 PetState（等级 / 心情 / 饱食度 / 精力）+ 主题明暗派生，不改动任何数据模型。
struct Pet3DVisualState: Equatable {
    /// 成长阶段（等级 → 体型比例 / 颜色深浅）
    var stage: PetGrowthStage
    /// 物种造型
    var style: PetSpeciesStyle
    /// 心情（决定表情与动画频率）
    var mood: Pet3DMood
    /// 主题明暗（决定灯光 / 地面）
    var isDark: Bool
    /// 亲密度 0...1（决定是否主动蹭向镜头）
    var intimacy: Double

    /// 从宠物数据派生。
    /// 亲密度 = 等级进度 40% + 心情 40% + 精力 20%：
    /// 常来照顾、心情好、精力足的宠物更黏人。
    static func make(pet: PetState, isDark: Bool) -> Pet3DVisualState {
        let levelProgress = max(0, min(1, pet.levelProgress))
        let moodiness = max(0, min(1, Double(pet.mood) / 100.0))
        let vitality = max(0, min(1, Double(pet.energy) / 100.0))
        let intimacy = levelProgress * 0.4 + moodiness * 0.4 + vitality * 0.2
        return Pet3DVisualState(
            stage: PetGrowthStage.stage(forLevel: pet.level),
            style: PetSpeciesStyle.make(for: pet.species),
            mood: Pet3DMood.derive(from: pet),
            isDark: isDark,
            intimacy: intimacy
        )
    }
}

// MARK: - 控制器：SceneKit 场景 + 全部动画

/// 宠物 3D 形象控制器。
/// 负责场景搭建、动作编排、粒子特效与性能控制：
/// - 30 FPS 帧率上限、低多边形（球体 segments ≤ 32）
/// - 页面不可见 / 退到后台时暂停渲染（`isPlaying = false`）
/// - SceneKit 初始化失败时返回 nil，由界面回退到静态展示
final class Pet3DController: ObservableObject {

    /// 渲染用的 SceneKit 视图
    let scnView: SCNView
    let scene: SCNScene

    private let builder: Pet3DBuilder
    private let petNode: SCNNode
    private let partDict: [String: SCNNode]

    private var visual: Pet3DVisualState
    /// 实际生效的心情（睡觉是独立状态，会覆盖数据推导出来的心情）
    private var effectiveMood: Pet3DMood
    private var currentStyle: PetSpeciesStyle

    /// 「睡觉」按钮触发的小睡
    private var isNapActive = false
    private var napUntil: Date = .distantPast

    /// 连点节流
    private var lastPoke: Date = .distantPast

    // MARK: - 构造

    /// 构造入口：默认姿态是「微微侧头」的三头身正面视角
    init?(visual: Pet3DVisualState) {
        // SceneKit 依赖 Metal：没有可用设备（极端情况）就直接失败，交给界面兜底
        guard MTLCreateSystemDefaultDevice() != nil else { return nil }

        let brightness: Pet3DBrightness = visual.isDark ? .dark : .light
        let builder = Pet3DBuilder(style: visual.style, stage: visual.stage, brightness: brightness)
        let result = builder.build()
        guard !result.petNode.childNodes.isEmpty else { return nil }

        self.builder = builder
        self.scene = result.scene
        self.petNode = result.petNode
        self.partDict = result.partDict
        self.visual = visual
        self.effectiveMood = visual.mood
        self.currentStyle = visual.style

        let view = SCNView(frame: .zero)
        view.scene = result.scene
        view.backgroundColor = .clear
        view.isOpaque = false
        // 性能：限制 30 帧、关抗锯齿、不把相机交给用户
        view.preferredFramesPerSecond = 30
        view.antialiasingMode = .none
        view.allowsCameraControl = false
        view.autoenablesDefaultLighting = false
        view.rendersContinuously = true
        view.isPlaying = true
        self.scnView = view

        applyColors()
        applyMood()
        startIdle()
    }

    deinit {
        scnView.isPlaying = false
    }

    // MARK: - 状态更新

    /// 数据变化时调用（等级 / 物种 / 心情 / 亲密度 / 深浅色）
    func update(visual newValue: Pet3DVisualState) {
        let old = visual
        visual = newValue

        if old.isDark != newValue.isDark {
            applyBrightness()
        }
        // 成长阶段 / 物种造型任一变化就重算比例与配色。
        // PetGrowthStage 与 PetSpeciesStyle 都是 Equatable，整体比较即可，
        // 不用逐字段比对，也不再引用不存在的 style.speciesKey。
        if old.stage != newValue.stage || old.style != newValue.style {
            builder.applyStage(newValue.stage)
            builder.applyStyle(newValue.style)
            currentStyle = newValue.style
        }

        // 小睡时间结束就自然醒
        if isNapActive, Date() >= napUntil {
            isNapActive = false
        }
        // 还在小睡窗口内就不重播入睡动作，免得把正在播的动画打断
        let isNapWindow = isNapActive && Date() < napUntil

        let wasSleeping = effectiveMood.isSleeping
        // 睡觉以数据推导的心情为准，「睡觉」按钮只额外提供一次小睡
        effectiveMood = (isNapActive || newValue.mood.isSleeping) ? .sleepy : newValue.mood

        applyMood()
        // 只在入睡 / 醒来这两个瞬间切换姿态，避免每帧都重启动画
        if effectiveMood.isSleeping != wasSleeping {
            if effectiveMood.isSleeping {
                if !isNapWindow { applySleepPose() }
            } else {
                wakePose()
            }
        } else if !effectiveMood.isSleeping {
            startIdle()
        }

        if newValue.intimacy >= 0.75 {
            startNuzzle()
        } else {
            stopNuzzle()
        }
    }

    /// 「睡觉」按钮：小睡一小会儿（数据里的精力同时也会恢复，这里做一次即时视觉反馈）
    func nap() {
        isNapActive = true
        napUntil = Date().addingTimeInterval(2.4)
        effectiveMood = .sleepy
        applyMood()
        applySleepPose()
    }

    /// 手势旋转：拖动 = 绕宠物自身转（俯仰限制 ±34°，避免看到脚底）
    func updateRotation(pitch: CGFloat, yaw: CGFloat) {
        let clampedPitch = max(-34, min(34, pitch))
        petNode.eulerAngles = Pet3DMath.vec(
            Pet3DMath.rad(clampedPitch),
            Pet3DMath.rad(yaw.truncatingRemainder(dividingBy: 360)),
            0.0
        )
    }

    /// 页面可见性 / 前后台切换（不可见时停止渲染，省电）
    func setActive(_ active: Bool) {
        scnView.isPlaying = active
    }
    // MARK: - 用户操作入口

    /// 点一下：叫声式形变抖动 + 高光闪一下 + 小星星
    func poke() {
        pokeReaction()
    }

    /// 开心：蹦一下 + 转圈 + 冒爱心
    func playHappy() {
        happyReaction()
    }

    /// 吃东西：低头张嘴 → 咀嚼 → 满足抖动
    func playEat() {
        eatReaction()
    }

    // MARK: - 灯光 / 颜色

    private func applyColors() {
        builder.applyStyle(currentStyle)
    }

    private func applyBrightness() {
        let brightness: Pet3DBrightness = visual.isDark ? .dark : .light
        partDict["pet3d.light.key"]?.light?.intensity = brightness.keyIntensity
        partDict["pet3d.light.key"]?.light?.shadowColor = brightness.shadowColor
        partDict["pet3d.light.ambient"]?.light?.intensity = brightness.ambientIntensity
        partDict["pet3d.light.fill"]?.light?.intensity = brightness.fillIntensity
        if let ground = partDict["pet3d.ground"]?.geometry?.firstMaterial {
            ground.diffuse.contents = brightness.groundColor
            ground.emission.contents = brightness.groundTint.withAlphaComponent(0.28)
        }
    }

    // MARK: - 表情（心情 / 饱食度 → 嘴型与眼睛大小）

    private func applyMood() {
        let mood = effectiveMood

        if let eyes = partDict[Pet3DPart.eyes] {
            // 心情决定的眼睛大小放在外层；眨眼的压扁放在每个眼珠自己的 anchor 上，两者互不干扰
            eyes.scale = Pet3DMath.vec(1.0, 1.0, 1.0)
            if mood.isSleeping {
                eyes.removeAction(forKey: "blinkLoop")
                for anchor in eyes.childNodes {
                    anchor.scale = Pet3DMath.vec(1.0, 1.0, 1.0)
                    anchor.runAction(Pet3DController.blinkScaleY(from: 1.0, to: 0.07, duration: 0.30), forKey: "blink")
                }
            } else {
                // 醒来：先清掉旧的眨眼循环，否则 startBlinking 的「已有循环」判断会拦住重启
                eyes.removeAction(forKey: "blinkLoop")
                for anchor in eyes.childNodes {
                    anchor.removeAction(forKey: "blink")
                    anchor.scale = Pet3DMath.vec(1.0, mood.eyeScale, 1.0)
                }
                startBlinking()
            }
        }

        if let mouth = partDict[Pet3DPart.mouth] {
            mouth.scale = Pet3DMath.vec(1.0, 1.0, 1.0)
            switch mood {
            case .happy, .normal:
                setMouth(.smile)
            case .hungry, .unhappy:
                setMouth(.frown)
            case .sleepy:
                setMouth(.neutral)
            }
        }

        startBreathing(for: mood)
        startFloating()
    }

    private enum MouthKind {
        case smile
        case neutral
        case open
        case frown
    }

    private func setMouth(_ kind: MouthKind) {
        partDict[Pet3DPart.smile]?.opacity = kind == .smile ? 1 : 0
        partDict[Pet3DPart.neutral]?.opacity = kind == .neutral ? 1 : 0
        partDict[Pet3DPart.open]?.opacity = kind == .open ? 1 : 0
        partDict[Pet3DPart.frown]?.opacity = kind == .frown ? 1 : 0
    }

    // MARK: - 待机动画（呼吸 / 浮动 / 摇尾巴 / 眨眼）

    private func startIdle() {
        startBreathing(for: effectiveMood)
        startFloating()
        startTailWag()
        if effectiveMood.isSleeping {
            applySleepPose()
        } else {
            startBlinking()
        }
    }

    /// 呼吸：身体轻微缩放（从肚子中心往两边撑）
    private func startBreathing(for mood: Pet3DMood) {
        guard let body = partDict[Pet3DPart.body] else { return }
        body.removeAction(forKey: "breath")
        let cycle = 1.35 / mood.animSpeed
        let up = SCNAction.scale(to: 1.035, duration: cycle * 0.5)
        up.timingMode = .easeInEaseOut
        let down = SCNAction.scale(to: 0.985, duration: cycle * 0.5)
        down.timingMode = .easeInEaseOut
        body.runAction(SCNAction.repeatForever(SCNAction.sequence([up, down])), forKey: "breath")
    }

    /// 浮动：整体轻轻上下（睡觉时幅度更小、更慢）
    private func startFloating() {
        guard let base = partDict[Pet3DPart.base] else { return }
        base.removeAction(forKey: "float")
        let duration = 2.10 / effectiveMood.animSpeed
        let amplitude: CGFloat = effectiveMood.isSleeping ? 0.035 : 0.075
        let up = SCNAction.moveBy(x: 0, y: amplitude, z: 0, duration: duration * 0.5)
        up.timingMode = .easeInEaseOut
        let down = SCNAction.moveBy(x: 0, y: -amplitude, z: 0, duration: duration * 0.5)
        down.timingMode = .easeInEaseOut
        base.runAction(SCNAction.repeatForever(SCNAction.sequence([up, down])), forKey: "float")
    }

    /// 摇尾巴：尾巴根节点 + 每一节都甩，形成波浪
    private func startTailWag() {
        guard let tail = partDict[Pet3DPart.tail] else { return }
        let speed = effectiveMood.animSpeed
        let amplitude: CGFloat = effectiveMood.isSleeping ? 0.05 : 0.30

        tail.removeAction(forKey: "wag")
        let wag = SCNAction.sequence([
            SCNAction.rotateBy(x: 0, y: amplitude, z: 0, duration: 0.44 / speed),
            SCNAction.rotateBy(x: 0, y: -amplitude * 2, z: 0, duration: 0.88 / speed),
            SCNAction.rotateBy(x: 0, y: amplitude, z: 0, duration: 0.44 / speed)
        ])
        tail.runAction(SCNAction.repeatForever(wag), forKey: "wag")

        for index in 0..<4 {
            guard let segment = partDict["pet3d.tail.seg\(index)"] else { continue }
            segment.removeAction(forKey: "wagSeg")
            let small = SCNAction.sequence([
                SCNAction.rotateBy(x: 0, y: amplitude * 0.5, z: 0, duration: 0.35 / speed),
                SCNAction.rotateBy(x: 0, y: -amplitude, z: 0, duration: 0.70 / speed),
                SCNAction.rotateBy(x: 0, y: amplitude * 0.5, z: 0, duration: 0.35 / speed)
            ])
            segment.runAction(SCNAction.repeatForever(small), forKey: "wagSeg")
        }
    }

    /// 只沿 Y 轴缩放的眨眼动作。
    /// SceneKit 的 `SCNAction` 没有 `scaleY`（只有整体的 `scale(to:duration:)`），
    /// 这里用 `customAction` 手写插值：只压扁眼睛自身的 Y 轴，不动 X / Z。
    private static func blinkScaleY(from base: CGFloat, to target: CGFloat, duration: TimeInterval) -> SCNAction {
        SCNAction.customAction(duration: duration) { (node: SCNNode, elapsed: CGFloat) in
            let progress: CGFloat = duration > 0 ? elapsed / CGFloat(duration) : 1
            let t = max(0, min(1, progress))
            var scale = node.scale
            scale.y = Float(base + (target - base) * t)
            node.scale = scale
        }
    }

    /// 随机眨眼：间隔随机，心情越好眨得越勤
    private func startBlinking() {
        guard !effectiveMood.isSleeping else { return }
        guard let eyes = partDict[Pet3DPart.eyes] else { return }
        guard eyes.action(forKey: "blinkLoop") == nil else { return }
        let delay = Double.random(in: 1.4...max(2.0, effectiveMood.blinkDelay))
        // 眨眼是作用在 eyes 容器上的（applyMood 里已经把容器的 scale 复位成 1），
        // 心情决定的「眼睛大小」在各自的 anchor 上，两者互不干扰。
        let close = Pet3DController.blinkScaleY(from: 1.0, to: 0.10, duration: 0.07)
        let open = Pet3DController.blinkScaleY(from: 0.10, to: 1.0, duration: 0.11)
        open.timingMode = .easeOut
        let cycle = SCNAction.sequence([close, open, SCNAction.wait(duration: delay)])
        let loop = SCNAction.repeatForever(cycle)
        eyes.runAction(loop, forKey: "blinkLoop")
    }

    /// 亲密度高时主动蹭向镜头
    private func startNuzzle() {
        guard let nuzzle = partDict["pet3d.nuzzle"] else { return }
        guard nuzzle.action(forKey: "nuzzle") == nil else { return }
        let near = SCNAction.moveBy(x: 0, y: 0, z: 0.16, duration: 1.5)
        near.timingMode = .easeInEaseOut
        let far = SCNAction.moveBy(x: 0, y: 0, z: -0.16, duration: 1.5)
        far.timingMode = .easeInEaseOut
        let swayLeft = SCNAction.rotateBy(x: 0, y: 0, z: 0.10, duration: 1.5)
        swayLeft.timingMode = .easeInEaseOut
        let swayRight = SCNAction.rotateBy(x: 0, y: 0, z: -0.10, duration: 1.5)
        swayRight.timingMode = .easeInEaseOut
        let wait = SCNAction.wait(duration: 3.0)
        let loop = SCNAction.repeatForever(SCNAction.sequence([near, swayLeft, far, swayRight, wait]))
        nuzzle.runAction(loop, forKey: "nuzzle")
    }

    private func stopNuzzle() {
        guard let nuzzle = partDict["pet3d.nuzzle"] else { return }
        guard nuzzle.action(forKey: "nuzzle") != nil else { return }
        nuzzle.removeAction(forKey: "nuzzle")
        // 回到原本的位置与姿态
        nuzzle.position = Pet3DMath.vec(0.0, 0.0, 0.0)
        nuzzle.eulerAngles = Pet3DMath.vec(0.0, 0.0, 0.0)
    }

    // MARK: - 开心：蹦一下 + 转一圈 + 冒爱心

    private func happyReaction() {
        guard let spin = partDict[Pet3DPart.spin], spin.action(forKey: "happy") == nil else { return }
        Pet3DParticleFactory.burst(Pet3DParticleFactory.makeHeartBurst, on: petNode, tag: "hearts")

        let jump = SCNAction.sequence([
            SCNAction.moveBy(x: 0, y: 0.38, z: 0, duration: 0.24),
            SCNAction.moveBy(x: 0, y: -0.38, z: 0, duration: 0.26)
        ])
        jump.timingMode = .easeOut

        let spinAction = SCNAction.rotateBy(x: 0, y: .pi * 2, z: 0, duration: 0.70)
        spinAction.timingMode = .easeInEaseOut

        let happy = SCNAction.group([jump, spinAction])
        let finish = SCNAction.run { [weak self] _ in
            guard let self else { return }
            Pet3DParticleFactory.burst(Pet3DParticleFactory.makeHeartBurst, on: self.petNode, tag: "hearts")
        }
        spin.runAction(SCNAction.sequence([happy, finish]), forKey: "happy")
    }

    // MARK: - 吃东西：低头张嘴 → 咀嚼 → 满足抖动

    private func eatReaction() {
        guard let head = partDict[Pet3DPart.head], head.action(forKey: "headAnim") == nil else { return }
        Pet3DParticleFactory.burst(Pet3DParticleFactory.makeSparkleBurst, on: petNode, tag: "sparkles")

        let down = SCNAction.rotateTo(x: Pet3DMath.rad(16), y: 0, z: 0, duration: 0.24)
        down.timingMode = .easeOut

        let openMouth = SCNAction.scale(to: 1.32, duration: 0.16)
        openMouth.timingMode = .easeOut

        let chewUp = SCNAction.scale(to: 0.84, duration: 0.10)
        let chewDown = SCNAction.scale(to: 1.16, duration: 0.10)
        let chewing = SCNAction.repeatForever(SCNAction.sequence([chewUp, chewDown, SCNAction.wait(duration: 0.05)]))

        let settle = SCNAction.sequence([
            SCNAction.scale(to: 1.0, duration: 0.10),
            SCNAction.wait(duration: 0.18),
            SCNAction.rotateTo(x: 0, y: 0, z: 0, duration: 0.30)
        ])
        settle.timingMode = .easeInEaseOut

        let sequenceAction = SCNAction.sequence([
            openMouth,
            SCNAction.wait(duration: 0.30),
            SCNAction.run { [weak self] _ in
                guard let self else { return }
                self.setMouth(.open)
                self.partDict[Pet3DPart.mouth]?.runAction(chewing, forKey: "chewing")
            },
            SCNAction.wait(duration: 1.10),
            SCNAction.run { [weak self] _ in
                guard let self else { return }
                self.partDict[Pet3DPart.mouth]?.removeAction(forKey: "chewing")
                self.applyMood()
            },
            settle
        ])
        head.runAction(SCNAction.group([down, sequenceAction]), forKey: "headAnim")
    }

    // MARK: - 睡觉：闭眼 + Zzz + 呼吸变慢

    private func applySleepPose() {
        guard let head = partDict[Pet3DPart.head] else { return }
        head.removeAction(forKey: "headAnim")
        let nod = SCNAction.rotateTo(x: Pet3DMath.rad(15), y: 0, z: Pet3DMath.rad(7), duration: 0.65)
        nod.timingMode = .easeInEaseOut
        head.runAction(nod, forKey: "headAnim")

        if let mouth = partDict[Pet3DPart.mouth] {
            mouth.removeAction(forKey: "chewing")
        }
        if let eyes = partDict[Pet3DPart.eyes] {
            eyes.removeAction(forKey: "blinkLoop")
        }
        Pet3DParticleFactory.attachLoop(Pet3DParticleFactory.makeSleepZ(), on: head, tag: "zzz")
    }

    private func wakePose() {
        guard let head = partDict[Pet3DPart.head] else { return }
        Pet3DParticleFactory.stopLoop(on: head, tag: "zzz")
        head.removeAction(forKey: "headAnim")
        let up = SCNAction.rotateTo(x: 0, y: 0, z: 0, duration: 0.40)
        up.timingMode = .easeOut
        head.runAction(up, forKey: "headAnim")
    }

    // MARK: - 点一下：形变抖动 + 高光闪 + 星星

    private func pokeReaction() {
        // 0.45 秒内只响应一次，避免连点导致动画打架
        let now = Date()
        guard now.timeIntervalSince(lastPoke) > 0.45 else { return }
        lastPoke = now

        Pet3DParticleFactory.burst(Pet3DParticleFactory.makeSparkleBurst, on: petNode, tag: "sparkles")

        if let base = partDict[Pet3DPart.base] {
            base.removeAction(forKey: "poke")
            // 上面放大、下面压扁：像被戳了一下（base 的 pivot 在脚底）
            let squash = SCNAction.scale(to: 1.12, duration: 0.09)
            squash.timingMode = .easeOut
            let stretch = SCNAction.scale(to: 0.94, duration: 0.13)
            stretch.timingMode = .easeInEaseOut
            let back = SCNAction.scale(to: 1.0, duration: 0.16)
            back.timingMode = .easeOut
            base.runAction(SCNAction.sequence([squash, stretch, back]), forKey: "poke")
        }

        flashHighlight()
        flashBlush()
    }

    /// 高光闪一下：临时提高头部材质的自发光与高光强度
    private func flashHighlight() {
        guard let skull = partDict["pet3d.head.skull"]?.geometry?.firstMaterial else { return }
        let originalEmission = skull.emission.contents
        let originalSpecular = skull.specular.intensity
        skull.specular.intensity = 1.0
        skull.emission.contents = UIColor(red: 1.0, green: 0.92, blue: 0.96, alpha: 0.62)
        DispatchQueue.main.asyncAfter(deadline: .now() + 0.18) {
            skull.emission.contents = originalEmission
            skull.specular.intensity = originalSpecular
        }
    }

    /// 腮红也亮一下，配合「奶凶」的反馈
    private func flashBlush() {
        guard let blushGroup = partDict["pet3d.head.blush"] else { return }
        for child in blushGroup.childNodes {
            guard let material = child.geometry?.firstMaterial else { continue }
            material.transparency = 0.95
            DispatchQueue.main.asyncAfter(deadline: .now() + 0.25) {
                material.transparency = 0.55
            }
        }
    }
}
