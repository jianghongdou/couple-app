import Foundation
import Combine

/// AI 记账服务。
/// DeepSeek 的 Key 只存在服务器后台，App 不保存任何 Key。
/// **账目本身只写入本机数据库，服务器不接收账单明细。**
final class LedgerAIService: ObservableObject {
    static let shared = LedgerAIService()

    @Published var isParsing = false
    @Published var errorText = ""
    @Published var status: AIStatus?
    @Published var lastEngine = ""

    private init() {}

    func loadStatus() async {
        do {
            let value = try await APIClient.shared.fetchAIStatus()
            await MainActor.run { self.status = value }
        } catch {
            await MainActor.run { self.status = nil }
        }
    }

    /// 解析一句话账单。优先用服务端 DeepSeek，失败时自动退回本地规则解析。
    func parse(_ text: String) async -> ParsedLedgerResult {
        let trimmed = text.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !trimmed.isEmpty else {
            return ParsedLedgerResult()
        }

        await MainActor.run { self.isParsing = true; self.errorText = "" }

        var result = ParsedLedgerResult()
        var remoteError = ""

        if !DeviceConfig.shared.token.isEmpty {
            do {
                result = try await APIClient.shared.parseLedger(text: trimmed, today: DateUtils.dateKey())
                result.model = result.model.isEmpty ? "deepseek" : result.model
                await MainActor.run { self.lastEngine = "DeepSeek 智能识别" }
            } catch let error as APIError {
                remoteError = error.message
            } catch {
                remoteError = "AI 解析失败"
            }
        } else {
            remoteError = "未登录，无法使用 AI"
        }

        if result.items.isEmpty {
            // 本地规则兜底
            let local = LocalLedgerParser.parse(trimmed)
            if !local.isEmpty {
                result.items = local
                result.total = local.reduce(0) { $0 + ($1.isExpense ? $1.amount : -$1.amount) }
                result.summary = "本地规则识别出 \(local.count) 笔"
                result.model = "local"
                await MainActor.run { self.lastEngine = "本地规则识别（离线兜底）" }
            } else {
                result.errorText = remoteError.isEmpty ? "没能识别出金额，换个说法试试" : remoteError
            }
        }

        // ⚠️ 必须先把值取成 let：@Sendable 闭包（MainActor.run）里不能引用可变局部变量
        let errorSnapshot = result.errorText
        await MainActor.run {
            self.isParsing = false
            if !errorSnapshot.isEmpty { self.errorText = errorSnapshot }
        }
        return result
    }

    /// AI 月度总结（只上传汇总数字，不上传明细）
    func monthlyReport(month: String, summary: LedgerSummary) async -> String {
        do {
            return try await APIClient.shared.monthlyReport(
                month: month,
                summary: summary,
                days: DateUtils.datesInMonth().count
            )
        } catch let error as APIError {
            return "生成失败：" + error.message
        } catch {
            return "生成失败，请检查服务器连接"
        }
    }
}

/// 纯本地的规则解析：不联网也能记账
enum LocalLedgerParser {

    private static let rules: [(keys: [String], category: String, kind: String)] = [
        (["工资", "薪水", "发薪", "月薪", "奖金", "提成"], "工资", "income"),
        (["报销", "退款", "收到", "转入", "利息", "理财"], "理财", "income"),
        (["红包", "压岁钱"], "红包", "income"),
        (["饭", "午饭", "晚饭", "早饭", "早餐", "午餐", "晚餐", "外卖", "奶茶", "咖啡", "吃", "餐", "买菜", "水果", "零食", "火锅", "烧烤"], "餐饮", "expense"),
        (["打车", "地铁", "公交", "加油", "停车", "高铁", "飞机", "机票", "油费", "过路费", "滴滴"], "交通", "expense"),
        (["买", "购物", "淘宝", "京东", "拼多多", "快递", "衣服", "鞋", "化妆品", "日用品"], "购物", "expense"),
        (["电影", "游戏", "娱乐", "KTV", "演唱会", "会员", "视频", "桌游"], "娱乐", "expense"),
        (["房租", "水电", "物业", "燃气", "宽带", "话费", "电费", "水费"], "居住", "expense"),
        (["药", "医院", "看病", "体检", "挂号", "牙"], "医疗", "expense"),
        (["书", "课程", "培训", "学习", "考试", "文具"], "学习", "expense"),
        (["礼物", "份子", "请客", "随礼", "送"], "人情", "expense"),
        (["旅游", "酒店", "门票", "民宿", "景点", "度假"], "旅行", "expense"),
        (["猫", "狗", "宠物", "猫粮", "狗粮", "猫砂", "疫苗"], "宠物", "expense")
    ]

    private static let numberRegex = try? NSRegularExpression(pattern: "([0-9]+(\\.[0-9]{1,2})?)", options: [])

    /// 从一句话里抽出若干笔账
    static func parse(_ text: String) -> [ParsedLedgerItem] {
        var cleaned = text
        cleaned = cleaned.replacingOccurrences(of: "块半", with: ".5")
        cleaned = cleaned.replacingOccurrences(of: "块", with: ".")
        cleaned = cleaned.replacingOccurrences(of: "元", with: " ")
        cleaned = cleaned.replacingOccurrences(of: "钱", with: " ")

        // 注意：不能用 "." 当分隔符，否则 "18.5" 会被拆成两笔
        let separators = CharacterSet(charactersIn: "，,、；;。！!？?\n\t ")
        let segments = cleaned.components(separatedBy: separators)
            .map { $0.trimmingCharacters(in: .whitespaces) }
            .filter { !$0.isEmpty }

        var items: [ParsedLedgerItem] = []
        for segment in segments {
            guard let regex = numberRegex else { continue }
            let range = NSRange(segment.startIndex..<segment.endIndex, in: segment)
            let matches = regex.matches(in: segment, options: [], range: range)
            guard !matches.isEmpty else { continue }

            let (category, kind) = categoryFor(segment)
            let note = segment.trimmingCharacters(in: .whitespaces)

            for match in matches {
                guard let valueRange = Range(match.range, in: segment) else { continue }
                let valueText = String(segment[valueRange])
                guard let amount = Double(valueText), amount > 0 else { continue }
                var item = ParsedLedgerItem()
                item.amount = amount
                item.kind = kind
                item.category = category
                item.note = note
                item.date = DateUtils.dateKey()
                items.append(item)
            }
        }

        if items.isEmpty, let regex = numberRegex {
            let range = NSRange(cleaned.startIndex..<cleaned.endIndex, in: cleaned)
            let matches = regex.matches(in: cleaned, options: [], range: range)
            for match in matches {
                guard let valueRange = Range(match.range, in: cleaned),
                      let amount = Double(String(cleaned[valueRange])), amount > 0 else { continue }
                var item = ParsedLedgerItem()
                item.amount = amount
                item.kind = "expense"
                item.category = "其它"
                item.note = cleaned.trimmingCharacters(in: .whitespaces)
                item.date = DateUtils.dateKey()
                items.append(item)
            }
        }

        return Array(items.prefix(30))
    }

    private static func categoryFor(_ text: String) -> (String, String) {
        for rule in rules {
            for key in rule.keys where text.contains(key) {
                return (rule.category, rule.kind)
            }
        }
        return ("其它", "expense")
    }
}
