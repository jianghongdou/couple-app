import Foundation
import SwiftUI      // ColorScheme（Pet3DBrightness 初始化用）
import UIKit
import SceneKit

// MARK: - Q 版 3D 宠物的造型 / 情绪 / 工具

/// 物种造型风格。
/// 9 种宠物共用同一套「三头身」骨架，只替换耳朵 / 尾巴 / 嘴鼻 / 附加部件的形态与配色。
/// 注意：全部是纯代码参数，不依赖任何图片或模型资源。
struct PetSpeciesStyle: Equatable {
    /// 耳朵形状
    enum EarStyle {
        case pointy     // 三角尖耳（奶猫）
        case floppy     // 下垂圆耳（柴犬）
        case long       // 长兔耳（兔子）
        case round      // 小圆耳（小熊 / 熊猫）
        case tiny       // 几乎没有耳朵（企鹅 / 小恐龙）
        case tuft       // 尖耳 + 独角（独角兽）
    }

    /// 尾巴形状
    enum TailStyle {
        case curved     // 细长上翘的弯尾巴
        case fluffy     // 蓬松大尾巴（狐狸）
        case puff       // 短圆团子尾巴（兔子）
        case stubby     // 小短尾（小熊 / 熊猫 / 企鹅）
    }

    /// 附加部件
    enum Feature {
        case none
        case horn       // 独角（独角兽）
        case patches    // 黑眼圈（熊猫）
        case belly      // 白肚皮（企鹅）
        case spikes     // 背鳍小尖刺（小恐龙）
        case whiskers   // 胡须（奶猫）
        case antlers    // 小鹿角（小恐龙 / 独角兽备用）
    }

    var earStyle: EarStyle = .pointy
    var tailStyle: TailStyle = .curved
    var feature: Feature = .none
    /// 身体主色（Q 版马卡龙色系）
    var bodyColor: UIColor = UIColor(red: 1.00, green: 0.78, blue: 0.52, alpha: 1)
    /// 嘴鼻周围颜色
    var muzzleColor: UIColor = UIColor(red: 1.00, green: 0.93, blue: 0.90, alpha: 1)
    /// 鼻子颜色
    var noseColor: UIColor = UIColor(red: 1.00, green: 0.45, blue: 0.58, alpha: 1)
    /// 耳内 / 肚皮 / 附加件颜色
    var accentColor: UIColor = UIColor(red: 1.00, green: 0.62, blue: 0.72, alpha: 1)
    /// 眼睛大小倍数
    var eyeScale: CGFloat = 1
    /// 是否长呆毛
    var hasAhoge: Bool = true

    /// key 与 PetState.species 一一对应；未知（含 custom）都退回奶猫造型
    static func make(for species: String) -> PetSpeciesStyle {
        switch species {
        case "dog":     return dog
        case "bunny":   return bunny
        case "bear":    return bear
        case "penguin": return penguin
        case "fox":     return fox
        case "panda":   return panda
        case "dino":    return dino
        case "unicorn": return unicorn
        default:        return cat
        }
    }

    // MARK: 各物种配色（沿用 AppTheme 的粉 / 紫 / 薄荷 / 橙 / 黄）

    static let cat: PetSpeciesStyle = {
        var s = PetSpeciesStyle()
        s.earStyle = .pointy
        s.tailStyle = .curved
        s.feature = .whiskers
        s.bodyColor = UIColor(red: 1.00, green: 0.78, blue: 0.52, alpha: 1)   // 奶橘
        s.accentColor = UIColor(red: 1.00, green: 0.62, blue: 0.72, alpha: 1)
        return s
    }()

    static let dog: PetSpeciesStyle = {
        var s = PetSpeciesStyle()
        s.earStyle = .floppy
        s.tailStyle = .curved
        s.bodyColor = UIColor(red: 1.00, green: 0.71, blue: 0.44, alpha: 1)   // 柴犬橘
        s.accentColor = UIColor(red: 1.00, green: 0.93, blue: 0.85, alpha: 1)
        return s
    }()

    static let bunny: PetSpeciesStyle = {
        var s = PetSpeciesStyle()
        s.earStyle = .long
        s.tailStyle = .puff
        s.eyeScale = 1.05
        s.bodyColor = UIColor(red: 1.00, green: 0.96, blue: 0.97, alpha: 1)   // 奶白
        s.accentColor = UIColor(red: 1.00, green: 0.62, blue: 0.72, alpha: 1)
        return s
    }()

    static let bear: PetSpeciesStyle = {
        var s = PetSpeciesStyle()
        s.earStyle = .round
        s.tailStyle = .stubby
        s.hasAhoge = false
        s.eyeScale = 0.95
        s.bodyColor = UIColor(red: 0.87, green: 0.68, blue: 0.50, alpha: 1)   // 焦糖棕
        s.accentColor = UIColor(red: 0.97, green: 0.85, blue: 0.72, alpha: 1)
        return s
    }()

    static let penguin: PetSpeciesStyle = {
        var s = PetSpeciesStyle()
        s.earStyle = .tiny
        s.tailStyle = .stubby
        s.feature = .belly
        s.hasAhoge = false
        s.bodyColor = UIColor(red: 0.23, green: 0.26, blue: 0.38, alpha: 1)   // 深夜蓝
        s.muzzleColor = UIColor(red: 1.00, green: 0.62, blue: 0.72, alpha: 1)
        s.accentColor = UIColor(red: 1.00, green: 0.82, blue: 0.72, alpha: 1)
        s.noseColor = UIColor(red: 1.00, green: 0.72, blue: 0.34, alpha: 1)   // 橙色小嘴
        return s
    }()

    static let fox: PetSpeciesStyle = {
        var s = PetSpeciesStyle()
        s.earStyle = .pointy
        s.tailStyle = .fluffy
        s.feature = .whiskers
        s.bodyColor = UIColor(red: 1.00, green: 0.60, blue: 0.35, alpha: 1)   // 橘红
        s.accentColor = UIColor(red: 1.00, green: 0.95, blue: 0.90, alpha: 1)
        return s
    }()

    static let panda: PetSpeciesStyle = {
        var s = PetSpeciesStyle()
        s.earStyle = .round
        s.tailStyle = .stubby
        s.feature = .patches
        s.bodyColor = UIColor(red: 1.00, green: 0.97, blue: 0.98, alpha: 1)   // 奶白
        s.accentColor = UIColor(red: 0.22, green: 0.22, blue: 0.27, alpha: 1)
        return s
    }()

    static let dino: PetSpeciesStyle = {
        var s = PetSpeciesStyle()
        s.earStyle = .tiny
        s.tailStyle = .curved
        s.feature = .spikes
        s.hasAhoge = false
        s.bodyColor = UIColor(red: 0.24, green: 0.85, blue: 0.68, alpha: 1)   // 薄荷绿
        s.accentColor = UIColor(red: 1.00, green: 0.83, blue: 0.35, alpha: 1)
        return s
    }()

    static let unicorn: PetSpeciesStyle = {
        var s = PetSpeciesStyle()
        s.earStyle = .tuft
        s.tailStyle = .fluffy
        s.feature = .horn
        s.bodyColor = UIColor(red: 0.69, green: 0.53, blue: 1.00, alpha: 1)   // 梦幻紫
        s.accentColor = UIColor(red: 1.00, green: 0.62, blue: 0.72, alpha: 1)
        return s
    }()
}

// MARK: - 成长阶段（等级 → 体型比例 / 颜色深浅）

struct PetGrowthStage: Equatable {
    var title: String
    /// 整体缩放
    var sizeScale: CGFloat
    /// 脑袋占身高的比例：越小越「幼态」，越大越「成体」
    var headToBody: CGFloat
    /// 颜色深浅：0 = 原色，1 = 最浅
    var colorLighten: CGFloat

    static func stage(forLevel level: Int) -> PetGrowthStage {
        switch level {
        case ..<4:
            return PetGrowthStage(title: "幼年", sizeScale: 0.88, headToBody: 0.62, colorLighten: 0.00)
        case 4..<10:
            return PetGrowthStage(title: "成长", sizeScale: 0.95, headToBody: 0.60, colorLighten: 0.06)
        case 10..<20:
            return PetGrowthStage(title: "成年", sizeScale: 1.00, headToBody: 0.57, colorLighten: 0.12)
        default:
            return PetGrowthStage(title: "完全体", sizeScale: 1.07, headToBody: 0.54, colorLighten: 0.20)
        }
    }
}

// MARK: - 心情（心情值 / 饱食度 / 精力 → 表情与动画频率）

enum Pet3DMood {
    case sleepy     // 精力低 / 夜间 → 睡觉
    case hungry     // 饱食度低 → 委屈嘴
    case unhappy    // 心情低 → 生气嘴 + 眯眼
    case happy      // 心情高 → 大笑
    case normal

    /// 是否闭眼睡觉
    var isSleeping: Bool { self == .sleepy }

    /// 眼睛大小倍数
    var eyeScale: CGFloat {
        switch self {
        case .sleepy:  return 0.80
        case .hungry:  return 1.10
        case .unhappy: return 0.85
        case .happy:   return 1.00
        case .normal:  return 1.00
        }
    }

    /// 待机动作速度（越大越快）
    var animSpeed: Double {
        switch self {
        case .sleepy:  return 0.55
        case .hungry:  return 0.78
        case .unhappy: return 0.70
        case .happy:   return 1.45
        case .normal:  return 1.00
        }
    }

    /// 两次眨眼之间的随机间隔上限（秒）
    var blinkDelay: Double {
        switch self {
        case .sleepy:  return 10.0
        case .hungry:  return 6.5
        case .unhappy: return 7.5
        case .happy:   return 3.4
        case .normal:  return 4.8
        }
    }

    /// 心情文案（嘴型 / 眼神提示，仅在气泡里出现）
    var reactionLine: String {
        switch self {
        case .sleepy:  return "呼…呼…（睡着了）"
        case .hungry:  return "肚子好饿呀～"
        case .unhappy: return "哼！（奶凶一下）"
        case .happy:   return "超开心！再蹭一下～"
        case .normal:  return "蹭蹭你～"
        }
    }

    /// 根据宠物数据推导心情（夜间 22:00-06:00 且精力偏低时想睡觉）
    static func derive(from pet: PetState) -> Pet3DMood {
        let hour = Calendar.current.component(.hour, from: Date())
        let isNight = hour >= 22 || hour < 6
        if pet.energy < 20 { return .sleepy }
        if isNight && pet.energy < 55 { return .sleepy }
        if pet.hunger < 25 { return .hungry }
        if pet.mood < 30 { return .unhappy }
        if pet.mood >= 82 { return .happy }
        return .normal
    }
}

// MARK: - 主题明暗（3D 场景的灯光 / 地面跟着 colorScheme 走）

enum Pet3DBrightness {
    case light
    case dark

    init(_ scheme: ColorScheme) {
        self = scheme == .dark ? .dark : .light
    }

    /// 主光强度
    var keyIntensity: CGFloat { self == .dark ? 820 : 1080 }
    /// 环境光强度（深色下压暗，避免过曝）
    var ambientIntensity: CGFloat { self == .dark ? 300 : 430 }
    /// 补光强度
    var fillIntensity: CGFloat { self == .dark ? 340 : 430 }
    /// 地面圆盘颜色
    var groundColor: UIColor {
        self == .dark
            ? UIColor(red: 0.20, green: 0.16, blue: 0.24, alpha: 1)
            : UIColor(red: 1.00, green: 0.97, blue: 0.98, alpha: 1)
    }
    /// 地面粉色氛围光晕
    var groundTint: UIColor {
        self == .dark
            ? UIColor(red: 0.42, green: 0.32, blue: 0.52, alpha: 1)
            : UIColor(red: 1.00, green: 0.83, blue: 0.89, alpha: 1)
    }
    /// 阴影颜色（软阴影）
    var shadowColor: UIColor {
        self == .dark
            ? UIColor(red: 0.02, green: 0.01, blue: 0.04, alpha: 0.55)
            : UIColor(red: 0.42, green: 0.32, blue: 0.44, alpha: 0.32)
    }
}

// MARK: - 部件名（构建后靠名字取节点，避免到处传引用）

enum Pet3DPart {
    static let pet = "pet3d.pet"          // 外层（手势旋转）
    static let spin = "pet3d.spin"        // 转圈动画
    static let base = "pet3d.base"        // 浮动 / 蹭人
    static let body = "pet3d.body"        // 呼吸（缩放）
    static let squash = "pet3d.squash"    // 点一下时的形变抖动
    static let head = "pet3d.head"        // 低头 / 点头
    static let eyes = "pet3d.eyes"        // 眨眼（整体压扁）
    static let mouth = "pet3d.mouth"      // 张嘴 / 咀嚼
    static let smile = "pet3d.mouth.smile"
    static let neutral = "pet3d.mouth.neutral"
    static let open = "pet3d.mouth.open"
    static let frown = "pet3d.mouth.frown"
    static let tail = "pet3d.tail"
}

// MARK: - 小工具

enum Pet3DMath {
    /// 角度 → 弧度。
    /// 返回 CGFloat：视图层与 SCNAction 的角度参数在 64 位设备上都是 CGFloat，
    /// 需要写进 SCNVector3（Float）时统一走 `vec(_:_:_:)` 转换。
    static func rad(_ degrees: CGFloat) -> CGFloat {
        degrees * .pi / 180
    }

    /// 组装 SCNVector3：SceneKit 的三个分量是 Float，这里统一收口转换，
    /// 避免 `Cannot convert value of type 'CGFloat' to expected argument type 'Float'`。
    static func vec(_ x: CGFloat, _ y: CGFloat, _ z: CGFloat) -> SCNVector3 {
        SCNVector3(Float(x), Float(y), Float(z))
    }

    /// 组装 SCNVector4（粒子颜色抖动等）
    static func vec4(_ x: CGFloat, _ y: CGFloat, _ z: CGFloat, _ w: CGFloat) -> SCNVector4 {
        SCNVector4(Float(x), Float(y), Float(z), Float(w))
    }
    /// 组装位移矩阵（节点 pivot）
    static func translation(_ t: CGFloat) -> SCNMatrix4 {
        SCNMatrix4MakeTranslation(Float(t), Float(t), Float(t))
    }

    static func translation(_ x: CGFloat, _ y: CGFloat, _ z: CGFloat) -> SCNMatrix4 {
        SCNMatrix4MakeTranslation(Float(x), Float(y), Float(z))
    }
}

// MARK: - 颜色与材质

enum Pet3DMaterials {
    /// 统一的卡通材质：物体本身偏「软」，靠高光 + 环境光出体积感
    static func make(color: UIColor, specular: CGFloat = 0.45, roughness: CGFloat = 0.52) -> SCNMaterial {
        let material = SCNMaterial()
        material.lightingModel = .physicallyBased
        material.diffuse.contents = color
        material.specular.contents = UIColor.white
        material.specular.intensity = specular
        material.roughness.contents = roughness
        material.metalness.contents = 0.0
        // 一点点自发光，深色模式下不至于糊成一团
        material.emission.contents = color.withAlphaComponent(0.06)
        material.isDoubleSided = false
        return material
    }

    /// 半透明扁球腮红
    static func blush() -> SCNMaterial {
        let material = SCNMaterial()
        material.lightingModel = .lambert
        material.diffuse.contents = UIColor(red: 1.00, green: 0.50, blue: 0.62, alpha: 1)
        material.transparency = 0.55
        material.isDoubleSided = false
        return material
    }

    /// 眼睛高光（不受光照影响，永远是白亮点）
    static func highlight() -> SCNMaterial {
        let material = SCNMaterial()
        material.lightingModel = .constant
        material.diffuse.contents = UIColor.white
        material.emission.contents = UIColor.white
        return material
    }

    /// 默认（空）材质：用于「只投影不显示」的节点
    static func transparent() -> SCNMaterial {
        let material = SCNMaterial()
        material.lightingModel = .constant
        material.diffuse.contents = UIColor.clear
        material.transparency = 0.0
        // 不写颜色缓冲：节点完全隐形，但仍然参与深度与渲染
        material.colorBufferWriteMask = []
        return material
    }
}

enum Pet3DColor {
    /// 朝白色混合（越浅越「奶」）
    static func lighten(_ color: UIColor, amount: CGFloat) -> UIColor {
        mixing(color, with: .white, amount: amount)
    }

    /// 朝黑色混合
    static func darken(_ color: UIColor, amount: CGFloat) -> UIColor {
        mixing(color, with: .black, amount: amount)
    }

    static func mixing(_ color: UIColor, with other: UIColor, amount: CGFloat) -> UIColor {
        let ratio = min(1, max(0, amount))
        var r1: CGFloat = 0, g1: CGFloat = 0, b1: CGFloat = 0, a1: CGFloat = 0
        var r2: CGFloat = 0, g2: CGFloat = 0, b2: CGFloat = 0, a2: CGFloat = 0
        color.getRed(&r1, green: &g1, blue: &b1, alpha: &a1)
        other.getRed(&r2, green: &g2, blue: &b2, alpha: &a2)
        return UIColor(
            red: r1 + (r2 - r1) * ratio,
            green: g1 + (g2 - g1) * ratio,
            blue: b1 + (b2 - b1) * ratio,
            alpha: a1 + (a2 - a1) * ratio
        )
    }

    static func scaled(_ color: UIColor, by factor: CGFloat) -> UIColor {
        var r: CGFloat = 0, g: CGFloat = 0, b: CGFloat = 0, a: CGFloat = 0
        color.getRed(&r, green: &g, blue: &b, alpha: &a)
        return UIColor(
            red: min(1, r * factor),
            green: min(1, g * factor),
            blue: min(1, b * factor),
            alpha: a
        )
    }
}
