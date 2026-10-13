# 💞 情侣空间 · iPhone App

一个只属于你们两个人的情侣 App。**15 项需求全部实现**，并且可以在 Windows 上完成开发 → 云端编译 → 自己签名安装到 iPhone 的全流程。

> 你只能在 Windows 上操作也没关系：编译交给 GitHub Actions 的免费 macOS 机器，产出「未签名 IPA」，再用你的个人 P12 证书签名安装。

---

## 快速开始（三步）

### 第 1 步：部署服务器（二选一，想聊天/双人同步就需要）

**方案 A：PHP 版（推荐，宝塔用户零依赖）** —— 不用装 Node、不用 npm，直接把 `server-php/` 丢进宝塔的 PHP 站点即可。
详见 **[docs/10-PHP服务端部署.md](docs/10-PHP服务端部署.md)**。

**方案 B：Node 版** —— 功能完全一样（同一套接口契约），适合有 Node 环境或想跑 Docker 的情况。
详见 **[docs/03-服务器部署.md](docs/03-服务器部署.md)**。

```bash
# Node 版快速体验
cd server
cp .env.example .env      # 修改 JWT_SECRET 和 ADMIN_PASSWORD
npm install
npm start                 # 默认 http://0.0.0.0:8787
```

> 两个版本**接口契约完全一致**（[docs/API.md](docs/API.md)），App 不需要任何改动，选哪个都行，别同时指向同一份数据。
> 没有服务器也能用：App 支持单机模式（记账、密码本、宠物、签到全部可用），只是两台手机不互通。

### 第 2 步：云端编译出 IPA

把本项目推送到你的 GitHub 仓库，然后在 **Actions → 构建 iOS IPA → Run workflow** 点一下。
几分钟后就能在 Artifacts 里下载：

- `CoupleApp-unsigned-ipa` —— **完整版**（含灵动岛小组件扩展）
- `CoupleAppLite-unsigned-ipa` —— **轻量版**（不含扩展，证书无法签名扩展时用）

详见 **[docs/02-云编译与安装.md](docs/02-云编译与安装.md)**。

### 第 3 步：用自己的 P12 签名安装

用 Sideloadly / ESign / 爱思助手 等工具，把 IPA + 你的 P12（+ 描述文件）签名安装到 iPhone。
不确定你的证书能不能装完整版？先看 **[docs/01-签名证书判断.md](docs/01-签名证书判断.md)**。

---

## 功能清单（对应 要求.txt）

| # | 需求 | 实现情况 |
| --- | --- | --- |
| 1 | iPhone 上运行的 App | 原生 SwiftUI，最低 iOS 16.1，底部 5 个标签页 |
| 2 | 个人 P12 签名 | 云编译未签名 IPA + 签名安装全流程文档 |
| 3 | 火花系统 | 火花值 / 等级 / 连续天数 / 30 天日历 / 里程碑 / 每日上限 |
| 4 | 抖音表情 + 自上传表情包 | 内置 24 款抖音风格表情 + Unicode 表情 + 相册上传自定义表情包 |
| 5 | 养宠物 | 饱食 / 心情 / 精力 / 经验 / 等级 / 金币 / 9 种形象 / 可换成自己拍的照片 |
| 6 | 签到系统 | 每日签到 + 心情 + 备注、连续奖励递增、双人日历、金币补签 |
| 7 | 愿望系统 | 愿望清单、共同攒金币、惊喜愿望、标记实现 |
| 8 | AI 记账（只存本地） | 一句话拆账（DeepSeek）+ 离线规则兜底，**账目只写本机** |
| 9 | 每日上班加班记录（工资） | 上下班打卡、加班时长、时薪 × 倍率、月度应发工资 |
| 10 | 苹果灵动岛 | Live Activity + 动态岛三种样式（天数 / 加班计时 / 火花），轻量版自动降级为卡片 |
| 11 | 聊天功能 | WebSocket 实时、表情/表情包/文字、已读、正在输入、断线重连、失败重发 |
| 12 | 密码管理 | AES-GCM 本地加密密码本 + Face ID 解锁 + 随机密码生成 |
| 13 | 登录系统 | 注册 / 登录 / JWT / 修改密码 / 邀请码绑定情侣 |
| 14 | 软件版本号 | 登录页、首页、我的页面都显示，支持服务端强制更新提示 |
| 15 | 可以走服务器 | 自带 Node + SQLite 服务端，含 WebSocket 与后台管理页面 |

---

## 目录结构

```
iphone情侣软件/
├─ ios/                         # iOS 工程（用 XcodeGen 生成 .xcodeproj）
│  ├─ project.yml               # 工程定义（两个 App 目标 + 一个扩展）
│  ├─ Support/                  # Info.plist
│  └─ Sources/
│     ├─ App/                   # 入口、根视图、主标签页
│     ├─ Core/                  # 主题、存储、加密、网络、同步、组件
│     ├─ Models/                # 数据模型
│     ├─ Services/              # 会话、聊天、表情包、AI 记账
│     ├─ Features/              # 各功能页面
│     └─ Resources/             # 资源目录（App 图标等）
├─ server/                      # Node 服务端（Express + SQLite + ws + 后台管理）
├─ server-php/                  # PHP 服务端（与 Node 版同一套接口契约，宝塔零依赖）
├─ scripts/build-ipa.sh         # 云端构建脚本
├─ tools/make-icon.mjs          # 图标生成脚本（纯 Node）
├─ .github/workflows/           # GitHub Actions 云编译流水线
└─ docs/                        # 中文文档
```

---

## 文档索引

| 文档 | 内容 |
| --- | --- |
| [docs/01-签名证书判断.md](docs/01-签名证书判断.md) | 判断你的 P12 能不能签名「完整版」、能不能用灵动岛 |
| [docs/02-云编译与安装.md](docs/02-云编译与安装.md) | 从推送代码到装进 iPhone 的完整步骤 |
| [docs/03-服务器部署.md](docs/03-服务器部署.md) | Node 版服务端部署（直跑 / Docker / systemd / Nginx / HTTPS） |
| [docs/10-PHP服务端部署.md](docs/10-PHP服务端部署.md) | **PHP 版服务端部署（宝塔，不装 Node）** |
| [docs/09-宝塔Linux部署.md](docs/09-宝塔Linux部署.md) | **只讲 Linux / 宝塔面板部署（含常驻运行、权限、排错表）** |
| [docs/04-后台管理.md](docs/04-后台管理.md) | 后台页面：填 DeepSeek Key、开关、统计 |
| [docs/05-功能与需求对照.md](docs/05-功能与需求对照.md) | 每条需求在代码里的位置与注意事项 |
| [docs/06-常见问题.md](docs/06-常见问题.md) | 装不上 / 连不上 / 同步失败 / AI 不可用 |
| [docs/07-编译失败怎么反馈.md](docs/07-编译失败怎么反馈.md) | 把报错快速反馈给 AI 的方法 |
| [docs/API.md](docs/API.md) | 前后端接口契约（改接口先改这里） |
| [docs/08-验收清单.md](docs/08-验收清单.md) | **从零到能用的逐条打勾清单（推荐先看这个）** |
| [server/README.md](server/README.md) | 服务端详细说明（接口、数据库、测试、运维） |

---

## 技术说明

- **iOS**：SwiftUI，零第三方依赖，最低 iOS 16.1；本地数据用 JSON 文件 + Keychain + AES-GCM；实时通信 URLSessionWebSocketTask。
- **服务端**：两套实现，接口契约相同 —— Node 20+（Express 4 / better-sqlite3 / ws）或 PHP 8.1+（纯 PHP + PDO SQLite，无需 Composer）。
- **构建**：XcodeGen 生成工程（不再手写 pbxproj），GitHub Actions 在 macOS 上编译未签名 IPA。
- **隐私**：记账与密码本永远只存在手机上；服务器只保存聊天、火花、宠物、签到、愿望、加班记录。

## 免责声明

- 本项目仅供个人自用。内置表情为「emoji + 配色 + 文案」自行绘制，不包含任何第三方受版权保护的素材。
- 使用共享/购买的证书签名存在被吊销的风险，请自行评估。
