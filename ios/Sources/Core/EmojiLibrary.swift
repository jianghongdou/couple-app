import Foundation

/// 内置"抖音风格"表情包（用 emoji + 文案 + 配色绘制，不依赖任何外部素材，避免版权问题）
struct BuiltinSticker: Identifiable, Equatable {
    let id: String
    let emoji: String
    let text: String
    let colorHex: UInt32
}

enum BuiltinStickers {
    static let all: [BuiltinSticker] = [
        BuiltinSticker(id: "dz_like", emoji: "👍", text: "点赞", colorHex: 0xFF5C8A),
        BuiltinSticker(id: "dz_love", emoji: "❤️", text: "比心", colorHex: 0xFF3B6B),
        BuiltinSticker(id: "dz_doge", emoji: "🐶", text: "狗头", colorHex: 0xFFA94D),
        BuiltinSticker(id: "dz_laugh", emoji: "😂", text: "笑不活了", colorHex: 0xFFD43B),
        BuiltinSticker(id: "dz_cry", emoji: "😭", text: "破防了", colorHex: 0x4F9DFF),
        BuiltinSticker(id: "dz_666", emoji: "🤙", text: "666", colorHex: 0x9B6BFF),
        BuiltinSticker(id: "dz_flower", emoji: "🌸", text: "送花", colorHex: 0xFF8FAE),
        BuiltinSticker(id: "dz_money", emoji: "💰", text: "打钱", colorHex: 0xFFC93C),
        BuiltinSticker(id: "dz_fight", emoji: "💪", text: "加油鸭", colorHex: 0x2FD6A5),
        BuiltinSticker(id: "dz_eat", emoji: "🍜", text: "干饭", colorHex: 0xFF9F43),
        BuiltinSticker(id: "dz_sleep", emoji: "😴", text: "困了", colorHex: 0x748FFC),
        BuiltinSticker(id: "dz_hug", emoji: "🤗", text: "抱抱", colorHex: 0xFF7BA3),
        BuiltinSticker(id: "dz_kiss", emoji: "😘", text: "亲亲", colorHex: 0xFF5C8A),
        BuiltinSticker(id: "dz_angry", emoji: "😤", text: "生气了", colorHex: 0xFF6B6B),
        BuiltinSticker(id: "dz_shy", emoji: "🙈", text: "害羞", colorHex: 0xF783AC),
        BuiltinSticker(id: "dz_rocket", emoji: "🚀", text: "冲鸭", colorHex: 0x4F9DFF),
        BuiltinSticker(id: "dz_cake", emoji: "🎂", text: "生日快乐", colorHex: 0xFF8FAE),
        BuiltinSticker(id: "dz_gift", emoji: "🎁", text: "惊喜", colorHex: 0x9B6BFF),
        BuiltinSticker(id: "dz_moon", emoji: "🌙", text: "晚安", colorHex: 0x5C7CFA),
        BuiltinSticker(id: "dz_sun", emoji: "☀️", text: "早安", colorHex: 0xFFA94D),
        BuiltinSticker(id: "dz_ok", emoji: "👌", text: "收到", colorHex: 0x2FD6A5),
        BuiltinSticker(id: "dz_no", emoji: "🙅", text: "不行", colorHex: 0xFF6B6B),
        BuiltinSticker(id: "dz_work", emoji: "💼", text: "上班了", colorHex: 0x868E96),
        BuiltinSticker(id: "dz_ot", emoji: "🌚", text: "加班中", colorHex: 0x495057)
    ]

    static func sticker(_ id: String) -> BuiltinSticker? {
        all.first { $0.id == id }
    }
}

/// 一组 Unicode 表情
struct EmojiGroup: Identifiable, Equatable {
    var title: String
    var emojis: [String]

    var id: String { title }
}

/// Unicode 表情分组（选择器用）
enum EmojiLibrary {
    static let groups: [EmojiGroup] = [
        EmojiGroup(title: "热门", emojis: ["😂", "🥰", "😍", "🤣", "😭", "😘", "🙈", "😤", "🥺", "😴", "🤗", "😎", "🤔", "😳", "🥳", "😇", "😏", "😜", "🤪", "😌", "😔", "😢", "😡", "🥱", "🤤", "😷", "🤒", "🥴", "😵", "🤯", "😱", "😨"]),
        EmojiGroup(title: "爱心", emojis: ["❤️", "🧡", "💛", "💚", "💙", "💜", "🖤", "🤍", "💖", "💗", "💓", "💞", "💕", "💘", "💝", "❣️", "💔", "❤️‍🔥", "💋", "🌹", "💐", "🌷", "🌸", "💍", "💌"]),
        EmojiGroup(title: "手势", emojis: ["👍", "👎", "👌", "✌️", "🤞", "🤟", "🤘", "🤙", "👈", "👉", "👆", "👇", "✋", "🤚", "👋", "🙌", "👏", "🙏", "🤝", "💪", "🫶", "🤗", "🫰", "✊", "👊"]),
        EmojiGroup(title: "动物", emojis: ["🐱", "🐶", "🐰", "🐻", "🐼", "🐨", "🦊", "🐯", "🦁", "🐮", "🐷", "🐸", "🐵", "🐔", "🐧", "🐦", "🦄", "🐝", "🦋", "🐢", "🐙", "🦖", "🐳", "🦕", "🐿️"]),
        EmojiGroup(title: "食物", emojis: ["🍜", "🍚", "🍰", "🎂", "🍦", "🍫", "🍬", "🍭", "🍎", "🍓", "🍉", "🍇", "🍑", "🥑", "🍕", "🍔", "🍟", "🌮", "🍣", "🍤", "🥟", "🍲", "☕️", "🧋", "🍺"]),
        EmojiGroup(title: "日常", emojis: ["🌙", "☀️", "⭐️", "🌈", "☁️", "❄️", "🔥", "✨", "💫", "🎉", "🎁", "🎈", "🏆", "💰", "💳", "📱", "💻", "🎮", "🎵", "🎬", "📚", "✏️", "🚗", "✈️", "🏠"]),
        EmojiGroup(title: "符号", emojis: ["✅", "❌", "❗️", "❓", "💯", "🔔", "🔒", "🔑", "⏰", "📅", "📍", "🔗", "⬆️", "⬇️", "🆗", "🆘", "♻️", "⚡️", "💤", "🩹", "🎯", "🧸"])
    ]

    static let avatars: [String] = [
        "🐻", "🐱", "🐶", "🐰", "🐼", "🦊", "🐯", "🦁", "🐨", "🐷", "🐸", "🐵",
        "🐧", "🐦", "🦄", "🐝", "🦋", "🐢", "🐙", "🐳", "🌟", "🌙", "☀️", "🌈",
        "🍓", "🍑", "🍰", "🧋", "🌸", "🌻", "💖", "❤️‍🔥", "😎", "🥰", "🤓", "🐣"
    ]
}
