# 情侣空间 · WordPress 插件

把「情侣空间」App 的服务端做成 WordPress 插件：**装 WordPress → 上传插件 zip → 启用**，
App 里填站点地址就能连上。接口与 `docs/API.md` 完全一致。

## 目录结构

```
couple-space/
├── couple-space.php          插件主文件（插件头、常量、自动加载、激活/停用钩子）
├── uninstall.php             卸载脚本（默认不删数据）
├── includes/                 全部 PHP 业务代码
│   ├── Plugin.php            引导 + 三种地址的请求识别
│   ├── Dispatcher.php        分发 + 统一错误处理
│   ├── Router.php            按路径段匹配的路由表
│   ├── RouteTable.php        路由登记入口
│   ├── Registrar.php         路由登记器（get/post/put/patch/delete）
│   ├── Routes/               各接口实现
│   │   ├── MetaRoutes.php    /api/health /api/meta /api/status
│   │   ├── AuthRoutes.php    /api/auth/*
│   │   ├── CoupleRoutes.php  /api/couple*
│   │   ├── SyncRoutes.php    /api/sync/*
│   │   ├── ChatRoutes.php    /api/chat/*
│   │   ├── EmojiRoutes.php   /api/emoji/*
│   │   ├── AiRoutes.php      /api/ai/*
│   │   └── AdminRoutes.php   /admin/api/*
│   ├── Db.php                wpdb 数据层（自建表加 $wpdb->prefix 前缀）
│   ├── Config.php            常量、限制值、WordPress 选项
│   ├── Settings.php          自建 settings 表 + AES-256-GCM Key + bcrypt 后台密码
│   ├── Auth.php              JWT 鉴权（用户 token / 后台 token）
│   ├── Jwt.php               手写 HS256 JWT
│   ├── Validate.php          参数校验（错误码与文案与 server-php 一致）
│   ├── Http.php              请求解析与响应输出
│   ├── HttpException.php     业务异常
│   ├── AiError.php           AI 异常
│   ├── DeepSeek.php          DeepSeek 代理（wp_remote_post）
│   ├── EmojiStore.php        表情磁盘存取（uploads/couple-emoji）
│   ├── PublicEmoji.php       内置公共表情包同步
│   ├── Image.php             magic bytes 嗅探
│   ├── Ids.php               id / 邀请码生成
│   ├── Time.php              毫秒时间戳
│   ├── RateLimit.php         数据库固定窗口限流
│   ├── Log.php               日志
│   ├── Activate.php          激活 / 停用
│   ├── Admin.php             WordPress 后台菜单与表单处理
│   ├── Cli.php               WP-CLI：wp couple-space ...
│   ├── WsOutbox.php          WebSocket 空实现（插件不做实时推送）
│   └── WsCompat.php          online/uptime 兼容层
├── admin/views/              WordPress 后台页面视图
├── public/emoji/             内置公共表情包目录
└── tests/                    端到端测试
```

## 部署（宝塔）

1. 装 WordPress（宝塔一键部署）。
2. 把 `couple-space.zip` 上传到「插件 → 安装插件 → 上传插件」，启用。
3. 打开「情侣空间 → 总览」，复制页面显示的 **App 服务器地址**（就是站点地址）。
4. 在 App 的「服务器地址」里填它，点「测试连接」。
5. 需要 AI 记账时，在「情侣空间 → AI 设置」里填 DeepSeek API Key。

> 数据库不用配：插件直接复用 `wp-config.php` 里的 `DB_NAME / DB_USER / DB_PASSWORD / DB_HOST`。

## 三种访问地址

| 形式 | 例子 | 说明 |
| --- | --- | --- |
| 伪静态 rewrite | `https://域名/api/health` | 推荐，App 里填 `https://域名` |
| REST 命名空间 | `https://域名/wp-json/couple/v1/api/health` | App 里填 `https://域名/wp-json/couple/v1` |
| query 兜底 | `https://域名/?couple_api=/api/health` | 固定链接没开时也能用 |

> **固定链接不用特意设置**：上面三种地址在「文章名」和「朴素」两种固定链接下都实测可用
> （启用插件时已注册 `^api/(.*)$` 规则并自动刷新一次 rewrite 规则）。
> 如果服务器上的 nginx/Apache 规则把 `^api/` 拦掉了，改用第二种地址即可，功能完全一样。
> 另外 `/index.php?rest_route=/couple/v1/api/health` 也能用（WordPress REST 的标准兜底形式）。

## 本地开发

```bash
# 用 PHP 内置服务器跑 WordPress（把插件放到 wp-content/plugins/couple-space）
php -S 127.0.0.1:8890 -t <wordpress目录> <wordpress目录>/router.php
# 跑端到端测试（结果写入 tests/report.txt）
php wp-content/plugins/couple-space/tests/e2e.php
```

## 测试

```bash
# 前提：本地 WordPress 跑在 127.0.0.1:8890，且插件已复制到 wp-content/plugins/couple-space
php tests/e2e.php
# 结果同时写入 tests/report.txt
```

## 已知差异 / 限制

- **不提供 WebSocket**：WordPress 无法常驻进程，聊天走 App 已有的 5 秒轮询。
- **App 后台页面（`/admin`）不提供**：改用 WordPress 原生后台菜单管理（App 后台接口 `/admin/api/*` 仍然完整保留）。
- 图片处理依赖 PHP 的 `openssl`（AES-256-GCM 加密 Key）与 `mbstring`。
