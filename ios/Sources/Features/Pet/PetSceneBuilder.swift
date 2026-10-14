import Foundation
import UIKit
import SceneKit

/// Q 版 3D 宠物的场景构建器（纯代码建模，不使用任何模型 / 图片资源）。
///
/// 骨架是固定的「三头身」结构，物种差异只体现在耳朵 / 尾巴 / 嘴鼻 / 附加部件上：
///
/// ```
/// scene
///  ├─ 环境光 / 主光(带软阴影) / 补光
///  ├─ 地面圆盘（接收阴影）
///  ├─ 相机（固定在场景里，用户拖拽的是宠物，不是相机）
///  └─ petNode            ← 手势控制 yaw / pitch
///      └─ spinNode       ← 开心时转圈
///          └─ baseNode   ← 浮动（呼吸式上下）
///              └─ nuzzleNode ← 亲密度高时蹭向镜头
///                  └─ scaleNode ← 等级对应的整体大小
///                      └─ bodyGroup ← 呼吸（缩放）
///                          ├─ squashNode ← 垫底形变（点一下的抖动）
///                          │   ├─ body 小圆肚 + belly 肚皮
///                          │   ├─ armL / armR 短手（胶囊）
///                          │   ├─ legL / legR 短脚 + 小脚掌
///                          │   └─ tail → seg0 → seg1 → seg2 → tip 圆尾巴
///                          └─ headNode ← 低头 / 点头
///                              ├─ skull 大脑袋（球）
///                              ├─ 圆耳朵（按物种换形状）+ 呆毛 + 独角
///                              ├─ eyes → eyeL / eyeR（大眼 + 双高光）
///                              ├─ blush 腮红（半透明粉扁球）
///                              ├─ muzzle + nose
///                              ├─ mouth → 4 种嘴型（笑 / 平 / 张 / 委屈）
///                              └─ 物种附加件（黑眼圈 / 背刺 / 胡须）
/// ```
final class Pet3DBuilder {

    /// 构建结果
    struct Result {
        let scene: SCNScene
        let petNode: SCNNode
        let cameraNode: SCNNode
        let partDict: [String: SCNNode]
    }

    // MARK: - 输入

    private let style: PetSpeciesStyle
    private let stage: PetGrowthStage
    private let brightness: Pet3DBrightness

    // MARK: - 尺寸参数（构建期确定，动画期复用）

    private var sizeScale: CGFloat
    private var headRatio: CGFloat
    private var bodyHeight: CGFloat { sizeScale * 0.60 }
    private var bodyRadius: CGFloat { bodyHeight * 0.44 }
    private var headRadius: CGFloat { sizeScale * headRatio * 0.46 }
    private var eyeR: CGFloat { headRadius * 0.21 * style.eyeScale }
    private var muzzleR: CGFloat { headRadius * 0.31 }

    // MARK: - 材质

    private let headMaterial = SCNMaterial()
    private let earMaterial = SCNMaterial()
    private let muzzleMaterial = SCNMaterial()
    private let limbMaterial = SCNMaterial()
    private let tailMaterial = SCNMaterial()
    private let bellyMaterial = SCNMaterial()
    private let noseMaterial = SCNMaterial()
    private let accentMaterial = SCNMaterial()
    private let eyeMaterial = SCNMaterial()
    private let pupilMaterial = SCNMaterial()

    // MARK: - 节点

    private var nodes: [String: SCNNode] = [:]

    init(style: PetSpeciesStyle, stage: PetGrowthStage, brightness: Pet3DBrightness) {
        self.style = style
        self.stage = stage
        self.brightness = brightness
        self.sizeScale = stage.sizeScale
        self.headRatio = stage.headToBody
        configureMaterials()
    }

    // MARK: - 对外入口

    /// 先按物种配色建好一套材质，之后 applyStyle 只改颜色不改结构
    private func configureMaterials() {
        for material in [headMaterial, earMaterial, muzzleMaterial, limbMaterial, tailMaterial, bellyMaterial] {
            material.lightingModel = .physicallyBased
            material.specular.contents = UIColor.white
            material.specular.intensity = 0.42
            material.roughness.contents = 0.55
            material.metalness.contents = 0.0
            material.isDoubleSided = false
        }
        // 嘴 / 瞳孔用偏软的深色，别用纯黑，Q 版看起来更亲切
        let darkColor = UIColor(red: 0.26, green: 0.19, blue: 0.26, alpha: 1)
        for material in [noseMaterial, pupilMaterial] {
            material.lightingModel = .physicallyBased
            material.specular.contents = UIColor.white
            material.specular.intensity = 0.7
            material.roughness.contents = 0.30
            material.metalness.contents = 0.0
            material.diffuse.contents = darkColor
            material.emission.contents = darkColor.withAlphaComponent(0.05)
        }
        noseMaterial.diffuse.contents = style.noseColor
        noseMaterial.emission.contents = style.noseColor.withAlphaComponent(0.05)

        // 眼白：偏冷的高光白，和身体色区分开
        eyeMaterial.lightingModel = .physicallyBased
        eyeMaterial.diffuse.contents = UIColor(red: 1.0, green: 0.99, blue: 1.0, alpha: 1)
        eyeMaterial.specular.contents = UIColor.white
        eyeMaterial.specular.intensity = 0.9
        eyeMaterial.roughness.contents = 0.18
        eyeMaterial.metalness.contents = 0.0

        accentMaterial.lightingModel = .physicallyBased
        accentMaterial.specular.contents = UIColor.white
        accentMaterial.specular.intensity = 0.35
        accentMaterial.roughness.contents = 0.6
        accentMaterial.metalness.contents = 0.0

        applyStyle(style)
    }

    func build() -> Result {
        let scene = SCNScene()

        scene.rootNode.addChildNode(makeAmbientLight())
        scene.rootNode.addChildNode(makeKeyLight())
        scene.rootNode.addChildNode(makeFillLight())
        scene.rootNode.addChildNode(makeGround())

        let cameraRig = makeCameraRig()
        scene.rootNode.addChildNode(cameraRig.pivot)
        scene.rootNode.addChildNode(cameraRig.camera)

        let pet = makePet()
        scene.rootNode.addChildNode(pet)

        return Result(scene: scene, petNode: pet, cameraNode: cameraRig.camera, partDict: nodes)
    }

    // MARK: - 灯光

    /// 环境光：整体提亮，避免深色模式下暗部死黑
    private func makeAmbientLight() -> SCNNode {
        let light = SCNLight()
        light.type = .ambient
        light.color = brightness == .dark
            ? UIColor(red: 0.62, green: 0.58, blue: 0.72, alpha: 1)
            : UIColor(red: 1.00, green: 0.97, blue: 0.98, alpha: 1)
        light.intensity = brightness.ambientIntensity
        let node = SCNNode()
        node.name = "pet3d.light.ambient"
        node.light = light
        return node
    }

    /// 主光：右上前方，负责体积感与软阴影
    private func makeKeyLight() -> SCNNode {
        let light = SCNLight()
        light.type = .directional
        light.color = UIColor(red: 1.00, green: 0.98, blue: 0.96, alpha: 1)
        light.intensity = brightness.keyIntensity
        light.castsShadow = true
        // 用延迟阴影让软阴影更自然
        light.shadowMode = .deferred
        light.shadowRadius = 15
        light.shadowSampleCount = 16
        light.shadowColor = brightness.shadowColor
        light.shadowMapSize = CGSize(width: 1024, height: 1024)
        light.orthographicScale = 4
        light.zNear = 0.5
        light.zFar = 30

        let node = SCNNode()
        node.name = "pet3d.light.key"
        node.light = light
        node.position = Pet3DMath.vec(2.7, 4.6, 3.7)
        node.look(at: Pet3DMath.vec(0.0, 1.0, 0.0))
        return node
    }

    /// 补光：左后下方，偏冷色，把轮廓勾出来
    private func makeFillLight() -> SCNNode {
        let light = SCNLight()
        light.type = .directional
        light.color = brightness == .dark
            ? UIColor(red: 0.62, green: 0.70, blue: 1.00, alpha: 1)
            : UIColor(red: 0.86, green: 0.92, blue: 1.00, alpha: 1)
        light.intensity = brightness.fillIntensity
        light.castsShadow = false
        let node = SCNNode()
        node.name = "pet3d.light.fill"
        node.light = light
        node.position = Pet3DMath.vec(-3.4, 2.0, -2.4)
        node.look(at: Pet3DMath.vec(0.0, 1.0, 0.0))
        return node
    }

    // MARK: - 地面（软阴影承接）

    private func makeGround() -> SCNNode {
        let geometry = SCNCylinder(radius: 1.60, height: 0.035)
        geometry.radialSegmentCount = 48
        geometry.heightSegmentCount = 1

        let material = SCNMaterial()
        material.lightingModel = .blinn
        material.diffuse.contents = brightness.groundColor
        material.emission.contents = brightness.groundTint.withAlphaComponent(0.28)
        material.roughness.contents = 0.95
        material.specular.contents = UIColor.white
        material.specular.intensity = 0.15

        let node = SCNNode(geometry: geometry)
        node.name = "pet3d.ground"
        node.geometry?.firstMaterial = material
        node.position = Pet3DMath.vec(0.0, -0.018, 0.0)
        node.castsShadow = false
        return node
    }

    // MARK: - 相机（固定，不交给用户）

    private func makeCameraRig() -> (pivot: SCNNode, camera: SCNNode) {
        let pivot = SCNNode()
        pivot.name = "pet3d.cameraTarget"
        pivot.position = Pet3DMath.vec(0.0, 0.95, 0.0)

        let camera = SCNCamera()
        camera.fieldOfView = 38
        camera.zNear = 0.1
        camera.zFar = 60
        camera.wantsHDR = false

        let node = SCNNode()
        node.name = "pet3d.camera"
        node.camera = camera
        node.position = Pet3DMath.vec(0.0, 1.35, 6.30)
        // 用约束让相机永远看着 pivot，避免手算旋转出错
        let lookAt = SCNLookAtConstraint(target: pivot)
        lookAt.isGimbalLockEnabled = true
        node.constraints = [lookAt]
        return (pivot, node)
    }

    // MARK: - 宠物主体

    private func makePet() -> SCNNode {
        let scaleGroup = SCNNode()
        scaleGroup.name = "pet3d.scale"
        scaleGroup.scale = Pet3DMath.vec(sizeScale, sizeScale, sizeScale)

        let bodyGroup = SCNNode()
        bodyGroup.name = Pet3DPart.body
        let bodyCenterY = bodyHeight
        // 把 pivot 放到身体中心，这样呼吸缩放是从肚子往两边撑
        bodyGroup.pivot = Pet3DMath.translation(0, bodyCenterY, 0)
        bodyGroup.position = Pet3DMath.vec(0.0, bodyCenterY, 0.0)
        bodyGroup.addChildNode(makeTorso(centerY: bodyCenterY))
        bodyGroup.addChildNode(makeHead(bodyCenterY: bodyCenterY))
        scaleGroup.addChildNode(bodyGroup)

        // 蹭人（亲密度高时靠近镜头 + 左右轻晃）
        let nuzzle = SCNNode()
        nuzzle.name = "pet3d.nuzzle"
        nuzzle.addChildNode(scaleGroup)

        // 浮动 + 点一下的形变；pivot 放在脚底，压扁时才是「往下坐」
        let base = SCNNode()
        base.name = Pet3DPart.base
        base.addChildNode(nuzzle)

        let spin = SCNNode()
        spin.name = Pet3DPart.spin
        spin.addChildNode(base)

        let pet = SCNNode()
        pet.name = Pet3DPart.pet
        // 基础朝向由 base 承担，外层 pet 只接受用户拖拽的 yaw / pitch
        pet.addChildNode(spin)
        base.eulerAngles = Pet3DMath.vec(Pet3DMath.rad(-5), Pet3DMath.rad(16), 0.0)

        register(pet)
        register(base)
        register(nuzzle)
        return pet
    }

    // MARK: - 身体

    /// 小身体：大脑袋下面的小圆肚 + 短手短脚 + 圆尾巴
    private func makeTorso(centerY: CGFloat) -> SCNNode {
        let group = SCNNode()
        group.name = "pet3d.torso"

        let squash = SCNNode()
        squash.name = Pet3DPart.squash
        // 默认 pivot 就在脚底（原点），点一下时是从脚底往上压
        group.addChildNode(squash)

        let torso = SCNNode(geometry: Pet3DGeometry.sphere(radius: bodyRadius, segments: 28))
        torso.name = "pet3d.torso.body"
        torso.geometry?.firstMaterial = limbMaterial
        torso.scale = Pet3DMath.vec(1.0, 0.96, 1.0)
        torso.position = Pet3DMath.vec(0.0, centerY, 0.0)
        torso.castsShadow = true
        squash.addChildNode(torso)

        // 肚皮：稍微浅一点，做出层次
        let belly = SCNNode(geometry: Pet3DGeometry.sphere(radius: bodyRadius * 0.66, segments: 24))
        belly.name = "pet3d.torso.belly"
        belly.geometry?.firstMaterial = bellyMaterial
        belly.scale = Pet3DMath.vec(1.0, 0.92, 0.5)
        belly.position = Pet3DMath.vec(0.0, centerY - bodyRadius * 0.06, bodyRadius * 0.68)
        belly.castsShadow = false
        squash.addChildNode(belly)

        // 企鹅：把肚皮染成大面积白，做成「黑背白肚」的企鹅配色
        if style.feature == .belly {
            let patch = SCNNode(geometry: Pet3DGeometry.sphere(radius: bodyRadius * 0.86, segments: 26))
            patch.name = "pet3d.torso.frontPatch"
            patch.geometry?.firstMaterial = bellyWhiteMaterial()
            patch.scale = Pet3DMath.vec(1.0, 0.94, 0.45)
            patch.position = Pet3DMath.vec(0.0, centerY + bodyRadius * 0.04, bodyRadius * 0.42)
            patch.castsShadow = false
            squash.addChildNode(patch)
        }

        // 短手：胶囊，微微外八
        squash.addChildNode(makeArm(side: 1, centerY: centerY))
        squash.addChildNode(makeArm(side: -1, centerY: centerY))

        // 短脚：小球（略微抬起，保证脚掌刚好落在圆盘上没有穿模）
        for side in [CGFloat(1), CGFloat(-1)] {
            let leg = SCNNode(geometry: Pet3DGeometry.sphere(radius: bodyRadius * 0.31, segments: 20))
            leg.name = side > 0 ? "pet3d.torso.legR" : "pet3d.torso.legL"
            leg.geometry?.firstMaterial = limbMaterial
            leg.position = Pet3DMath.vec(side * bodyRadius * 0.52, bodyRadius * 0.40, bodyRadius * 0.10)
            leg.castsShadow = true
            squash.addChildNode(leg)

            let paw = SCNNode(geometry: Pet3DGeometry.sphere(radius: bodyRadius * 0.24, segments: 18))
            paw.name = "pet3d.torso.paw"
            paw.geometry?.firstMaterial = muzzleMaterial
            paw.scale = Pet3DMath.vec(1.0, 0.6, 1.2)
            paw.position = Pet3DMath.vec(side * bodyRadius * 0.52, bodyRadius * 0.16, bodyRadius * 0.26)
            paw.castsShadow = true
            squash.addChildNode(paw)
        }

        // 圆尾巴
        squash.addChildNode(makeTail(centerY: centerY))

        // 小恐龙：背上也来一排小尖刺，和头顶那排连成一体
        if style.feature == .spikes {
            for index in 0..<2 {
                let spike = SCNNode(geometry: Pet3DGeometry.cone(radius: bodyRadius * 0.14,
                                                                 height: bodyRadius * 0.42,
                                                                 segments: 12))
                spike.name = "pet3d.torso.spike"
                spike.geometry?.firstMaterial = accentMaterial
                spike.position = Pet3DMath.vec(0.0, centerY + bodyRadius * (0.16 - CGFloat(index) * 0.34), -bodyRadius * 0.62)
                spike.eulerAngles = Pet3DMath.vec(Pet3DMath.rad(-24), 0.0, 0.0)
                spike.castsShadow = false
                squash.addChildNode(spike)
            }
        }
        return group
    }

    /// 企鹅的白色前襟（单独一份材质，避免和肚皮的浅色混在一起）
    private func bellyWhiteMaterial() -> SCNMaterial {
        let material = SCNMaterial()
        material.lightingModel = .physicallyBased
        material.diffuse.contents = UIColor(red: 1.00, green: 0.98, blue: 0.97, alpha: 1)
        material.emission.contents = UIColor(red: 1.00, green: 0.98, blue: 0.97, alpha: 0.06)
        material.specular.contents = UIColor.white
        material.specular.intensity = 0.42
        material.roughness.contents = 0.55
        material.metalness.contents = 0.0
        return material
    }

    private func makeArm(side: CGFloat, centerY: CGFloat) -> SCNNode {
        let radius = bodyRadius * 0.20
        let arm = SCNNode(geometry: Pet3DGeometry.capsule(radius: radius, height: radius * 2.1, segments: 18))
        arm.name = side > 0 ? "pet3d.torso.armR" : "pet3d.torso.armL"
        arm.geometry?.firstMaterial = limbMaterial
        arm.position = Pet3DMath.vec(side * bodyRadius * 0.74, centerY + bodyRadius * 0.20, bodyRadius * 0.12)
        arm.eulerAngles = Pet3DMath.vec(0.0, 0.0, Pet3DMath.rad(side * 34))
        arm.castsShadow = true

        let paw = SCNNode(geometry: Pet3DGeometry.sphere(radius: radius * 0.95, segments: 16))
        paw.name = "pet3d.torso.armPaw"
        paw.geometry?.firstMaterial = muzzleMaterial
        paw.position = Pet3DMath.vec(0.0, -radius * 1.1, 0.0)
        paw.castsShadow = true
        arm.addChildNode(paw)
        return arm
    }

    /// 圆尾巴：一串小球拼成，根节点负责摇摆
    private func makeTail(centerY: CGFloat) -> SCNNode {
        let root = SCNNode()
        root.name = Pet3DPart.tail
        root.position = Pet3DMath.vec(0.0, centerY + bodyRadius * 0.02, -bodyRadius * 0.80)

        let chain = makeTailChain()
        // chain 沿本地 +Y 生长，转 30° 后指向「后上方」
        chain.eulerAngles = Pet3DMath.vec(Pet3DMath.rad(30), 0.0, 0.0)
        root.addChildNode(chain)

        switch style.tailStyle {
        case .curved:
            root.eulerAngles = Pet3DMath.vec(0.0, 0.0, Pet3DMath.rad(14))
        case .fluffy:
            root.eulerAngles = Pet3DMath.vec(0.0, 0.0, Pet3DMath.rad(24))
        case .puff:
            root.eulerAngles = Pet3DMath.vec(0.0, 0.0, Pet3DMath.rad(6))
        case .stubby:
            root.eulerAngles = Pet3DMath.vec(0.0, 0.0, Pet3DMath.rad(-6))
        }
        return root
    }

    /// 尾巴骨节（每个骨节是一个小球，逐级缩小并挂到上一级，摇摆时会自然甩尾）
    private func makeTailChain() -> SCNNode {
        let dimensions: (radius: CGFloat, segment: CGFloat, count: Int, tipScale: CGFloat, spacing: CGFloat)
        switch style.tailStyle {
        case .curved:
            dimensions = (bodyRadius * 0.16, bodyRadius * 0.21, 3, 1.7, 0.85)
        case .fluffy:
            dimensions = (bodyRadius * 0.23, bodyRadius * 0.19, 3, 1.8, 0.75)
        case .puff:
            dimensions = (bodyRadius * 0.24, bodyRadius * 0.12, 1, 1.9, 0.6)
        case .stubby:
            dimensions = (bodyRadius * 0.18, bodyRadius * 0.14, 2, 1.5, 0.7)
        }

        var currentRadius = dimensions.radius
        let root = SCNNode()
        var parent = root
        var nodeName = "pet3d.tail.seg"

        for index in 0..<dimensions.count {
            let segment = SCNNode(geometry: Pet3DGeometry.sphere(radius: currentRadius, segments: 16))
            segment.name = "\(nodeName)\(index)"
            segment.geometry?.firstMaterial = tailMaterial
            segment.scale = Pet3DMath.vec(1.0, 1.0 + dimensions.segment * 0.35, 1.0)
            segment.position = Pet3DMath.vec(0.0, index == 0 ? 0 : currentRadius * dimensions.spacing * 3.0, 0.0)
            segment.castsShadow = true
            parent.addChildNode(segment)
            parent = segment
            currentRadius *= 0.82
        }

        let tip = SCNNode(geometry: Pet3DGeometry.sphere(radius: currentRadius * dimensions.tipScale, segments: 18))
        tip.name = "pet3d.tail.tip"
        tip.geometry?.firstMaterial = style.tailStyle == .fluffy ? bellyMaterial : tailMaterial
        tip.position = Pet3DMath.vec(0.0, currentRadius * dimensions.spacing * 3.0, 0.0)
        tip.castsShadow = true
        parent.addChildNode(tip)
        return root
    }

    // MARK: - 脑袋

    private func makeHead(bodyCenterY: CGFloat) -> SCNNode {
        let head = SCNNode()
        head.name = Pet3DPart.head
        // 头节点原点 = 脖子，低头 / 点头都绕这里转
        head.position = Pet3DMath.vec(0.0, bodyCenterY + bodyHeight * 0.42, 0.0)
        register(head)

        let skull = SCNNode(geometry: Pet3DGeometry.sphere(radius: headRadius, segments: 32))
        skull.name = "pet3d.head.skull"
        skull.geometry?.firstMaterial = headMaterial
        skull.scale = Pet3DMath.vec(1.0, 0.97, 1.0)
        skull.castsShadow = true
        head.addChildNode(skull)

        head.addChildNode(makeEars())
        if style.hasAhoge {
            head.addChildNode(makeAhoge())
        }
        if style.feature == .patches {
            for side in [CGFloat(1), CGFloat(-1)] {
                let patch = SCNNode(geometry: Pet3DGeometry.sphere(radius: headRadius * 0.40, segments: 18))
                patch.name = "pet3d.head.patch"
                patch.geometry?.firstMaterial = accentMaterial
                patch.scale = Pet3DMath.vec(0.9, 1.05, 0.26)
                patch.position = Pet3DMath.vec(side * headRadius * 0.47, headRadius * 0.21, headRadius * 0.80)
                patch.eulerAngles = Pet3DMath.vec(0.0, 0.0, Pet3DMath.rad(side * 26))
                patch.castsShadow = false
                head.addChildNode(patch)
            }
        }
        head.addChildNode(makeEyes())
        head.addChildNode(makeBlush())
        head.addChildNode(makeMuzzleAndNose())
        head.addChildNode(makeMouth())
        head.addChildNode(makeFeature(for: style.feature))
        return head
    }

    /// 圆耳朵：按物种换成尖耳 / 垂耳 / 长兔耳 / 小圆耳 / 独角
    private func makeEars() -> SCNNode {
        let group = SCNNode()
        group.name = "pet3d.head.ears"

        switch style.earStyle {
        case .pointy, .tuft:
            for side in [CGFloat(1), CGFloat(-1)] {
                let ear = SCNNode(geometry: Pet3DGeometry.cone(radius: headRadius * 0.33,
                                                               height: headRadius * 0.60,
                                                               segments: 20))
                ear.name = "pet3d.head.ear"
                ear.geometry?.firstMaterial = earMaterial
                ear.position = Pet3DMath.vec(side * headRadius * 0.53, headRadius * 0.82, 0.0)
                ear.eulerAngles = Pet3DMath.vec(0.0, 0.0, Pet3DMath.rad(side * 20))
                ear.castsShadow = true
                group.addChildNode(ear)

                let inner = SCNNode(geometry: Pet3DGeometry.cone(radius: headRadius * 0.17,
                                                                 height: headRadius * 0.34,
                                                                 segments: 16))
                inner.name = "pet3d.head.earInner"
                inner.geometry?.firstMaterial = accentMaterial
                inner.position = Pet3DMath.vec(side * headRadius * 0.53, headRadius * 0.83, headRadius * 0.15)
                inner.eulerAngles = Pet3DMath.vec(0.0, 0.0, Pet3DMath.rad(side * 20))
                inner.castsShadow = false
                group.addChildNode(inner)
            }
            if style.earStyle == .tuft {
                // 独角兽的独角：金色小圆锥，稍微往前倾
                let horn = SCNNode(geometry: Pet3DGeometry.cone(radius: headRadius * 0.12,
                                                                height: headRadius * 0.55,
                                                                segments: 16))
                horn.name = "pet3d.head.horn"
                horn.geometry?.firstMaterial = Pet3DMaterials.make(
                    color: UIColor(red: 1.00, green: 0.86, blue: 0.42, alpha: 1),
                    specular: 0.9,
                    roughness: 0.24
                )
                horn.position = Pet3DMath.vec(0.0, headRadius * 0.94, headRadius * 0.18)
                horn.eulerAngles = Pet3DMath.vec(Pet3DMath.rad(14), 0.0, 0.0)
                horn.castsShadow = true
                group.addChildNode(horn)
            }

        case .floppy:
            for side in [CGFloat(1), CGFloat(-1)] {
                let ear = SCNNode(geometry: Pet3DGeometry.sphere(radius: headRadius * 0.27, segments: 20))
                ear.name = "pet3d.head.ear"
                ear.geometry?.firstMaterial = earMaterial
                ear.scale = Pet3DMath.vec(0.85, 1.5, 0.62)
                ear.position = Pet3DMath.vec(side * headRadius * 0.82, headRadius * 0.40, -headRadius * 0.06)
                ear.eulerAngles = Pet3DMath.vec(0.0, 0.0, Pet3DMath.rad(side * 32))
                ear.castsShadow = true
                group.addChildNode(ear)
            }

        case .long:
            for side in [CGFloat(1), CGFloat(-1)] {
                let ear = SCNNode(geometry: Pet3DGeometry.capsule(radius: headRadius * 0.17,
                                                                  height: headRadius * 1.15,
                                                                  segments: 18))
                ear.name = "pet3d.head.ear"
                ear.geometry?.firstMaterial = earMaterial
                // 一边直立、一边微微弯下来，Q 版更有表情
                let tilt: CGFloat = side > 0 ? 10 : 20
                ear.position = Pet3DMath.vec(side * headRadius * 0.36, headRadius * 1.10, 0.0)
                ear.eulerAngles = Pet3DMath.vec(0.0, 0.0, Pet3DMath.rad(side * tilt))
                ear.castsShadow = true
                group.addChildNode(ear)

                let inner = SCNNode(geometry: Pet3DGeometry.capsule(radius: headRadius * 0.09,
                                                                    height: headRadius * 0.85,
                                                                    segments: 14))
                inner.name = "pet3d.head.earInner"
                inner.geometry?.firstMaterial = accentMaterial
                inner.position = Pet3DMath.vec(side * headRadius * 0.36, headRadius * 1.10, headRadius * 0.15)
                inner.eulerAngles = Pet3DMath.vec(0.0, 0.0, Pet3DMath.rad(side * tilt))
                inner.castsShadow = false
                group.addChildNode(inner)
            }

        case .round:
            for side in [CGFloat(1), CGFloat(-1)] {
                // 熊猫的耳朵是贴在头顶的两个黑色圆球，位置比小熊更高更靠内
                let isPanda = style.feature == .patches
                let earRadius = headRadius * (isPanda ? 0.30 : 0.30)
                let earX = headRadius * (isPanda ? 0.58 : 0.66)
                let earY = headRadius * (isPanda ? 0.88 : 0.80)
                let ear = SCNNode(geometry: Pet3DGeometry.sphere(radius: earRadius, segments: 20))
                ear.name = "pet3d.head.ear"
                ear.geometry?.firstMaterial = isPanda ? accentMaterial : earMaterial
                ear.scale = Pet3DMath.vec(1.0, 0.92, 0.72)
                ear.position = Pet3DMath.vec(side * earX, earY, 0.0)
                ear.castsShadow = true
                group.addChildNode(ear)

                if !isPanda {
                    let inner = SCNNode(geometry: Pet3DGeometry.sphere(radius: headRadius * 0.17, segments: 16))
                    inner.name = "pet3d.head.earInner"
                    inner.geometry?.firstMaterial = accentMaterial
                    inner.scale = Pet3DMath.vec(1.0, 0.92, 0.5)
                    inner.position = Pet3DMath.vec(side * earX, earY, headRadius * 0.20)
                    inner.castsShadow = false
                    group.addChildNode(inner)
                }
            }

        case .tiny:
            for side in [CGFloat(1), CGFloat(-1)] {
                let ear = SCNNode(geometry: Pet3DGeometry.sphere(radius: headRadius * 0.16, segments: 14))
                ear.name = "pet3d.head.ear"
                ear.geometry?.firstMaterial = earMaterial
                ear.scale = Pet3DMath.vec(1.0, 0.7, 0.5)
                ear.position = Pet3DMath.vec(side * headRadius * 0.62, headRadius * 0.72, -headRadius * 0.10)
                ear.castsShadow = true
                group.addChildNode(ear)
            }
        }
        return group
    }

    /// 呆毛：头顶一小撮，随风摇
    private func makeAhoge() -> SCNNode {
        let ahoge = SCNNode(geometry: Pet3DGeometry.cone(radius: headRadius * 0.10,
                                                         height: headRadius * 0.42,
                                                         segments: 12))
        ahoge.name = "pet3d.head.ahoge"
        ahoge.geometry?.firstMaterial = earMaterial
        ahoge.position = Pet3DMath.vec(headRadius * 0.10, headRadius * 0.95, -headRadius * 0.10)
        ahoge.eulerAngles = Pet3DMath.vec(Pet3DMath.rad(-18), 0.0, Pet3DMath.rad(-26))
        ahoge.castsShadow = true
        return ahoge
    }

    /// 大眼睛：眼白 + 深色瞳孔 + 白色高光；外层 eyes 节点负责眨眼（压扁）
    private func makeEyes() -> SCNNode {
        let eyes = SCNNode()
        eyes.name = Pet3DPart.eyes
        register(eyes)

        for side in [CGFloat(1), CGFloat(-1)] {
            // 外层定位置，内层做缩放，保证眨眼时是「眼睛自己闭上」而不是整颗头收缩
            // （心情决定的眼睛大小也作用在这一层，和眨眼互不干扰）
            let eyeScale = SCNNode()
            eyeScale.name = "pet3d.eyes.anchor"
            eyeScale.position = Pet3DMath.vec(side * headRadius * 0.42, headRadius * 0.04, headRadius * 0.86)
            eyeScale.eulerAngles = Pet3DMath.vec(0.0, 0.0, Pet3DMath.rad(side * 6))

            let eye = SCNNode(geometry: Pet3DGeometry.sphere(radius: eyeR, segments: 22))
            eye.name = "pet3d.eyes.ball"
            eye.geometry?.firstMaterial = eyeMaterial
            eye.scale = Pet3DMath.vec(0.94, 1.12, 0.68)
            eye.castsShadow = false
            eyeScale.addChildNode(eye)

            let pupil = SCNNode(geometry: Pet3DGeometry.sphere(radius: eyeR * 0.60, segments: 18))
            pupil.name = "pet3d.eyes.pupil"
            pupil.geometry?.firstMaterial = pupilMaterial
            pupil.scale = Pet3DMath.vec(1.0, 1.10, 0.60)
            pupil.position = Pet3DMath.vec(0.0, 0.0, eyeR * 0.36)
            pupil.castsShadow = false
            eyeScale.addChildNode(pupil)

            let highlight = SCNNode(geometry: Pet3DGeometry.sphere(radius: eyeR * 0.24, segments: 12))
            highlight.name = "pet3d.eyes.highlight"
            highlight.geometry?.firstMaterial = Pet3DMaterials.highlight()
            highlight.position = Pet3DMath.vec(-side * eyeR * 0.22, eyeR * 0.30, eyeR * 0.72)
            highlight.castsShadow = false
            eyeScale.addChildNode(highlight)

            let highlightSmall = SCNNode(geometry: Pet3DGeometry.sphere(radius: eyeR * 0.13, segments: 10))
            highlightSmall.name = "pet3d.eyes.highlightSmall"
            highlightSmall.geometry?.firstMaterial = Pet3DMaterials.highlight()
            highlightSmall.position = Pet3DMath.vec(side * eyeR * 0.26, -eyeR * 0.26, eyeR * 0.66)
            highlightSmall.castsShadow = false
            eyeScale.addChildNode(highlightSmall)

            eyes.addChildNode(eyeScale)
        }
        return eyes
    }

    /// 腮红：半透明粉色扁球
    private func makeBlush() -> SCNNode {
        let group = SCNNode()
        group.name = "pet3d.head.blush"
        for side in [CGFloat(1), CGFloat(-1)] {
            let blush = SCNNode(geometry: Pet3DGeometry.sphere(radius: headRadius * 0.22, segments: 16))
            blush.name = "pet3d.head.blushDot"
            blush.geometry?.firstMaterial = Pet3DMaterials.blush()
            blush.scale = Pet3DMath.vec(1.0, 0.72, 0.16)
            blush.position = Pet3DMath.vec(side * headRadius * 0.66, -headRadius * 0.24, headRadius * 0.80)
            blush.castsShadow = false
            group.addChildNode(blush)
        }
        return group
    }

    /// 嘴鼻区域
    private func makeMuzzleAndNose() -> SCNNode {
        let muzzle = SCNNode(geometry: Pet3DGeometry.sphere(radius: muzzleR, segments: 22))
        muzzle.name = "pet3d.head.muzzle"
        muzzle.geometry?.firstMaterial = muzzleMaterial
        muzzle.scale = Pet3DMath.vec(1.28, 0.78, 0.66)
        muzzle.position = Pet3DMath.vec(0.0, -headRadius * 0.26, headRadius * 0.74)
        muzzle.castsShadow = false

        let nose = SCNNode(geometry: Pet3DGeometry.sphere(radius: headRadius * 0.085, segments: 14))
        nose.name = "pet3d.head.nose"
        nose.geometry?.firstMaterial = noseMaterial
        nose.scale = Pet3DMath.vec(1.35, 0.85, 0.8)
        nose.position = Pet3DMath.vec(0.0, headRadius * 0.05, headRadius * 0.18)
        nose.castsShadow = false
        muzzle.addChildNode(nose)
        return muzzle
    }

    /// 嘴：4 种嘴型叠在一起，靠 opacity 切换（笑脸 / 平静 / 张嘴 / 委屈）。
    /// pivot 节点本身放在嘴的位置，这样「张嘴 / 咀嚼」的缩放是原地张开，不会把嘴推歪。
    private func makeMouth() -> SCNNode {
        let mouthR = headRadius * 0.26
        let mouthY = -headRadius * 0.40
        let mouthZ = headRadius * 0.98

        let pivot = SCNNode()
        pivot.name = Pet3DPart.mouth
        pivot.position = Pet3DMath.vec(0.0, mouthY, mouthZ)
        register(pivot)

        // 笑脸：圆环被压扁，只露出下半弧（所以往上抬半个环半径）
        let smile = SCNNode(geometry: Pet3DGeometry.torus(ringRadius: mouthR,
                                                          pipeRadius: mouthR * 0.10,
                                                          segments: 26))
        smile.name = Pet3DPart.smile
        smile.geometry?.firstMaterial = pupilMaterial
        smile.scale = Pet3DMath.vec(1.0, 0.62, 0.7)
        smile.position = Pet3DMath.vec(0.0, mouthR * 0.52, 0.0)
        smile.opacity = 0
        smile.castsShadow = false
        pivot.addChildNode(smile)

        // 平静：一颗小扁球
        let neutral = SCNNode(geometry: Pet3DGeometry.sphere(radius: mouthR * 0.22, segments: 16))
        neutral.name = Pet3DPart.neutral
        neutral.geometry?.firstMaterial = pupilMaterial
        neutral.scale = Pet3DMath.vec(1.5, 0.7, 0.5)
        neutral.position = Pet3DMath.vec(0.0, 0.0, 0.0)
        neutral.opacity = 1
        neutral.castsShadow = false
        pivot.addChildNode(neutral)

        // 张嘴：椭圆（吃东西时会被放大）
        let open = SCNNode(geometry: Pet3DGeometry.sphere(radius: mouthR * 0.34, segments: 18))
        open.name = Pet3DPart.open
        open.geometry?.firstMaterial = pupilMaterial
        open.scale = Pet3DMath.vec(1.1, 0.9, 0.55)
        open.position = Pet3DMath.vec(0.0, -mouthR * 0.04, 0.0)
        open.opacity = 0
        open.castsShadow = false
        pivot.addChildNode(open)

        // 委屈 / 生气：小圆点 + 上方一点弧度
        let frown = SCNNode(geometry: Pet3DGeometry.sphere(radius: mouthR * 0.20, segments: 16))
        frown.name = Pet3DPart.frown
        frown.geometry?.firstMaterial = pupilMaterial
        frown.scale = Pet3DMath.vec(1.4, 0.65, 0.5)
        frown.position = Pet3DMath.vec(0.0, mouthR * 0.10, 0.0)
        frown.opacity = 0
        frown.castsShadow = false
        pivot.addChildNode(frown)

        return pivot
    }

    /// 物种附加件：白肚皮 / 背刺 / 胡须 / 独角（独角在耳朵里一起生成）
    private func makeFeature(for feature: PetSpeciesStyle.Feature) -> SCNNode {
        let group = SCNNode()
        group.name = "pet3d.head.feature"

        switch feature {
        case .whiskers:
            for side in [CGFloat(1), CGFloat(-1)] {
                for index in 0..<3 {
                    let length = headRadius * (1.05 - CGFloat(index) * 0.16)
                    let whisker = SCNNode(geometry: Pet3DGeometry.cylinder(radius: headRadius * 0.014,
                                                                          height: length,
                                                                          segments: 6))
                    whisker.name = "pet3d.head.whisker"
                    whisker.geometry?.firstMaterial = accentMaterial
                    whisker.position = Pet3DMath.vec(
                        side * headRadius * 0.34,
                        -headRadius * 0.22 + CGFloat(1 - index) * headRadius * 0.10,
                        headRadius * 0.46
                    )
                    whisker.eulerAngles = Pet3DMath.vec(0.0, 0.0, Pet3DMath.rad(side * (-78 + CGFloat(index) * 14)))
                    whisker.castsShadow = false
                    group.addChildNode(whisker)
                }
            }

        case .spikes:
            // 小恐龙：头顶到尾巴一小排背鳍
            for index in 0..<3 {
                let spike = SCNNode(geometry: Pet3DGeometry.cone(radius: headRadius * 0.10,
                                                                 height: headRadius * 0.34,
                                                                 segments: 12))
                spike.name = "pet3d.head.spike"
                spike.geometry?.firstMaterial = accentMaterial
                spike.position = Pet3DMath.vec(0.0, headRadius * (0.82 - CGFloat(index) * 0.30), -headRadius * (0.20 + CGFloat(index) * 0.26))
                spike.eulerAngles = Pet3DMath.vec(Pet3DMath.rad(-16), 0.0, 0.0)
                spike.castsShadow = false
                group.addChildNode(spike)
            }

        case .horn, .patches, .belly, .none, .antlers:
            break
        }
        return group
    }

    // MARK: - 状态联动（等级 / 造型变化时只改材质与比例，不重建节点）

    /// 成长阶段变化：调整整体比例与脑袋占比
    func applyStage(_ newStage: PetGrowthStage) {
        sizeScale = newStage.sizeScale
        headRatio = newStage.headToBody
        nodes["pet3d.scale"]?.scale = Pet3DMath.vec(newStage.sizeScale, newStage.sizeScale, newStage.sizeScale)
        nodes[Pet3DPart.head]?.position = Pet3DMath.vec(0.0, bodyHeight + bodyHeight * 0.42, 0.0)
        nodes[Pet3DPart.body]?.position = Pet3DMath.vec(0.0, bodyHeight, 0.0)
        nodes[Pet3DPart.body]?.pivot = Pet3DMath.translation(0, bodyHeight, 0)
    }

    /// 颜色变化：等级越高颜色越浅、越「奶油」
    func applyStyle(_ newStyle: PetSpeciesStyle) {
        let base = Pet3DColor.lighten(newStyle.bodyColor, amount: stage.colorLighten)
        let accent = Pet3DColor.lighten(newStyle.accentColor, amount: stage.colorLighten * 0.5)

        headMaterial.diffuse.contents = base
        headMaterial.emission.contents = base.withAlphaComponent(0.06)
        headMaterial.specular.contents = UIColor.white

        let ear = Pet3DColor.darken(base, amount: 0.10)
        earMaterial.diffuse.contents = ear
        earMaterial.emission.contents = ear.withAlphaComponent(0.06)

        limbMaterial.diffuse.contents = base
        limbMaterial.emission.contents = base.withAlphaComponent(0.06)

        tailMaterial.diffuse.contents = ear
        tailMaterial.emission.contents = ear.withAlphaComponent(0.06)

        bellyMaterial.diffuse.contents = Pet3DColor.lighten(base, amount: 0.34)
        bellyMaterial.emission.contents = Pet3DColor.lighten(base, amount: 0.34)

        muzzleMaterial.diffuse.contents = newStyle.muzzleColor
        muzzleMaterial.emission.contents = newStyle.muzzleColor.withAlphaComponent(0.06)

        noseMaterial.diffuse.contents = newStyle.noseColor
        noseMaterial.emission.contents = newStyle.noseColor.withAlphaComponent(0.05)
        accentMaterial.diffuse.contents = accent
        accentMaterial.emission.contents = accent.withAlphaComponent(0.06)
    }

    // MARK: - 小工具

    private func register(_ node: SCNNode) {
        if let name = node.name {
            nodes[name] = node
        }
    }
}

// MARK: - 基础几何（每个球体的 segments 都 ≤ 32，保证低多边形）

enum Pet3DGeometry {
    static func sphere(radius: CGFloat, segments: Int = 24) -> SCNSphere {
        let geometry = SCNSphere(radius: radius)
        geometry.segmentCount = min(32, max(8, segments))
        geometry.isGeodesic = false
        return geometry
    }

    static func capsule(radius: CGFloat, height: CGFloat, segments: Int = 18) -> SCNCapsule {
        let geometry = SCNCapsule(capRadius: radius, height: height)
        geometry.radialSegmentCount = min(32, max(8, segments))
        geometry.heightSegmentCount = 1
        return geometry
    }

    static func cone(radius: CGFloat, height: CGFloat, segments: Int = 18) -> SCNCone {
        let geometry = SCNCone(topRadius: 0, bottomRadius: radius, height: height)
        geometry.radialSegmentCount = min(32, max(8, segments))
        geometry.heightSegmentCount = 1
        return geometry
    }

    static func cylinder(radius: CGFloat, height: CGFloat, segments: Int = 12) -> SCNCylinder {
        let geometry = SCNCylinder(radius: radius, height: height)
        geometry.radialSegmentCount = min(32, max(6, segments))
        geometry.heightSegmentCount = 1
        return geometry
    }

    static func torus(ringRadius: CGFloat, pipeRadius: CGFloat, segments: Int = 24) -> SCNTorus {
        let geometry = SCNTorus(ringRadius: ringRadius, pipeRadius: pipeRadius)
        geometry.ringSegmentCount = min(32, max(8, segments))
        geometry.pipeSegmentCount = 8
        return geometry
    }
}
