import Foundation
import UIKit
import SceneKit

/// Q 版宠物的粒子特效工厂。
/// 全部由代码生成（`SCNParticleSystem`），不使用任何图片资源：
/// - 爱心：开心时从身体里冒出来
/// - Zzz：睡觉时头顶飘字
/// - 星星：点一下 / 吃东西时的闪烁反馈
enum Pet3DParticleFactory {

    // MARK: - 爱心（开心）

    /// 冒爱心：一次性爆一小簇
    static func makeHeartBurst() -> SCNParticleSystem {
        let system = SCNParticleSystem()
        system.loops = false
        system.birthRate = 62
        system.emissionDuration = 0.22
        system.warmupDuration = 0
        system.particleLifeSpan = 1.25
        system.particleLifeSpanVariation = 0.45
        system.particleVelocity = 0.55
        system.particleVelocityVariation = 0.32
        system.emittingDirection = Pet3DMath.vec(0, 1, 0)
        system.spreadingAngle = 32
        system.acceleration = Pet3DMath.vec(0, 1.15, 0)
        system.particleSize = 0.115
        system.particleSizeVariation = 0.05
        system.particleAngularVelocity = 26
        system.particleAngularVelocityVariation = 66
        system.particleColor = UIColor(red: 1.00, green: 0.36, blue: 0.54, alpha: 1)
        // 颜色抖动：随机偏紫 / 偏浅，看起来更活泼
        system.particleColorVariation = Pet3DMath.vec4(0.09, 0.09, 0.09, 0.12)
        system.blendMode = .alpha
        system.isAffectedByGravity = false
        system.isAffectedByPhysicsFields = false
        system.particleImage = heartImage()
        system.birthLocation = .surface
        system.birthDirection = .surfaceNormal
        return system
    }

    // MARK: - Zzz（睡觉）

    /// 头顶飘 Zzz：一直循环，睡觉时挂上、醒了移除
    static func makeSleepZ() -> SCNParticleSystem {
        let system = SCNParticleSystem()
        system.loops = true
        system.birthRate = 3.4
        system.emissionDuration = 1
        system.particleLifeSpan = 2.6
        system.particleLifeSpanVariation = 0.6
        system.particleVelocity = 0.42
        system.particleVelocityVariation = 0.12
        system.emittingDirection = Pet3DMath.vec(0.16, 1, 0)
        system.spreadingAngle = 12
        system.acceleration = Pet3DMath.vec(0.10, 0.26, 0)
        system.particleSize = 0.24
        system.particleSizeVariation = 0.06
        system.particleColor = UIColor(red: 0.55, green: 0.44, blue: 0.95, alpha: 1)
        system.particleColorVariation = Pet3DMath.vec4(0.06, 0.06, 0.10, 0)
        // 逐渐淡出，像真的睡着一样
        system.blendMode = .alpha
        system.isAffectedByGravity = false
        system.particleImage = zImage()
        system.birthLocation = .sphere
        system.birthDirection = .constant
        return system
    }

    // MARK: - 星星（点击 / 吃东西）

    /// 小星星爆一下：点宠物、或者开饭时用
    static func makeSparkleBurst() -> SCNParticleSystem {
        let system = SCNParticleSystem()
        system.loops = false
        system.birthRate = 120
        system.emissionDuration = 0.10
        system.particleLifeSpan = 0.7
        system.particleLifeSpanVariation = 0.28
        system.particleVelocity = 1.15
        system.particleVelocityVariation = 0.55
        system.emittingDirection = Pet3DMath.vec(0, 1, 0)
        system.spreadingAngle = 78
        system.acceleration = Pet3DMath.vec(0, -0.7, 0)
        system.particleSize = 0.085
        system.particleSizeVariation = 0.035
        system.particleColor = UIColor(red: 1.00, green: 0.83, blue: 0.28, alpha: 1)
        system.particleColorVariation = Pet3DMath.vec4(0.08, 0.12, 0.10, 0.08)
        system.blendMode = .additive
        system.isAffectedByGravity = false
        system.particleImage = starImage()
        system.birthLocation = .surface
        system.birthDirection = .surfaceNormal
        return system
    }

    // MARK: - 挂载 helper

    /// 在节点上播一次「爆发型」特效。
    /// 每次都换一个新的 system，避免重复 addParticleSystem 累积、也避免旧系统继续发射。
    static func burst(_ make: () -> SCNParticleSystem, on node: SCNNode, tag: String) {
        for system in node.particleSystems ?? [] where system.name == tag {
            node.removeParticleSystem(system)
        }
        let system = make()
        system.name = tag
        node.addParticleSystem(system)
    }

    /// 持续型特效（Zzz）：挂上就一直循环
    static func attachLoop(_ system: SCNParticleSystem, on node: SCNNode, tag: String) {
        for existing in node.particleSystems ?? [] where existing.name == tag {
            return
        }
        system.name = tag
        system.loops = true
        node.addParticleSystem(system)
    }

    /// 移除某个持续型特效
    static func stopLoop(on node: SCNNode, tag: String) {
        for system in node.particleSystems ?? [] where system.name == tag {
            node.removeParticleSystem(system)
        }
    }

    // MARK: - 用 Core Graphics 画粒子贴图（不是资源文件）

    /// 爱心贴图
    private static func heartImage() -> UIImage {
        let size = CGSize(width: 64, height: 64)
        let renderer = UIGraphicsImageRenderer(size: size)
        return renderer.image { context in
            let ctx = context.cgContext
            let path = UIBezierPath()
            // 两个圆弧 + 下方尖角拼成一颗圆润的爱心
            path.move(to: CGPoint(x: 32, y: 56))
            path.addCurve(
                to: CGPoint(x: 6, y: 24),
                controlPoint1: CGPoint(x: 20, y: 46),
                controlPoint2: CGPoint(x: 6, y: 36)
            )
            path.addCurve(
                to: CGPoint(x: 32, y: 18),
                controlPoint1: CGPoint(x: 6, y: 10),
                controlPoint2: CGPoint(x: 24, y: 8)
            )
            path.addCurve(
                to: CGPoint(x: 58, y: 24),
                controlPoint1: CGPoint(x: 40, y: 8),
                controlPoint2: CGPoint(x: 58, y: 10)
            )
            path.addCurve(
                to: CGPoint(x: 32, y: 56),
                controlPoint1: CGPoint(x: 58, y: 36),
                controlPoint2: CGPoint(x: 44, y: 46)
            )
            path.close()
            ctx.setFillColor(UIColor.white.cgColor)
            ctx.addPath(path.cgPath)
            ctx.fillPath()
        }
    }

    /// 字母 Z 贴图（Zzz 的每一颗粒子）
    private static func zImage() -> UIImage {
        let size = CGSize(width: 64, height: 64)
        let renderer = UIGraphicsImageRenderer(size: size)
        return renderer.image { _ in
            let paragraph = NSMutableParagraphStyle()
            paragraph.alignment = .center
            let attributes: [NSAttributedString.Key: Any] = [
                .font: UIFont.systemFont(ofSize: 44, weight: .heavy),
                .foregroundColor: UIColor.white,
                .paragraphStyle: paragraph
            ]
            let text = NSAttributedString(string: "Z", attributes: attributes)
            let textSize = text.size()
            text.draw(at: CGPoint(x: (size.width - textSize.width) / 2,
                                  y: (size.height - textSize.height) / 2))
        }
    }

    /// 四角星贴图
    private static func starImage() -> UIImage {
        let size = CGSize(width: 64, height: 64)
        let renderer = UIGraphicsImageRenderer(size: size)
        return renderer.image { context in
            let ctx = context.cgContext
            let center = CGPoint(x: 32, y: 32)
            let outer: CGFloat = 29
            let inner: CGFloat = 7
            let path = UIBezierPath()
            for index in 0..<8 {
                let radius = index % 2 == 0 ? outer : inner
                let angle = CGFloat(index) * .pi / 4 - .pi / 2
                let point = CGPoint(
                    x: center.x + cos(angle) * radius,
                    y: center.y + sin(angle) * radius
                )
                if index == 0 {
                    path.move(to: point)
                } else {
                    path.addLine(to: point)
                }
            }
            path.close()
            ctx.setFillColor(UIColor.white.cgColor)
            ctx.addPath(path.cgPath)
            ctx.fillPath()
        }
    }
}
