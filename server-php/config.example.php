<?php
/**
 * 情侣空间服务端（PHP 版）· 配置模板（MySQL / MariaDB 版）
 *
 * ⚠️ 正常流程不需要手动复制这个文件！
 *    部署后浏览器打开  你的站点地址/install.php  ，在网页安装向导里填一次
 *    数据库信息 + 后台密码，程序会自动建库、建表并生成 config.php。
 *
 * 什么时候才需要手动用本文件？
 *   - 服务器不允许 PHP 写文件（代码目录不可写），安装向导会在页面上给出
 *     「可复制的配置内容」，你也可以照抄本模板手动创建 config.php；
 *   - 你想完全跳过网页向导，直接手工写配置。
 *
 * config.php 已被 .gitignore 忽略（里面有密码与密钥，不要提交、不要公开），
 * 建议权限 0640、属主与 PHP 运行用户一致（宝塔默认是 www）。
 *
 * 也可以完全不写 config.php，改用同名的大写环境变量（方便宝塔/命令行部署）：
 *   JWT_SECRET、ADMIN_PASSWORD、ADMIN_JWT_SECRET、DEEPSEEK_API_KEY、DATA_DIR、
 *   PUBLIC_BASE_URL、TIMEZONE、PORT、TRUST_PROXY、LOG_LEVEL、WS_HOST、WS_PORT、
 *   DB_HOST、DB_PORT、DB_NAME、DB_USER、DB_PASS、DB_CHARSET
 * 环境变量的优先级高于本文件。
 */

return [
    // ── 数据库（MySQL / MariaDB，必填）────────────────────────────────────────
    // 宝塔面板「数据库」页面添加数据库后，把下面 5 项照抄过来即可。
    'db_host' => '127.0.0.1',        // 数据库与网站在同一台机器就填 127.0.0.1
    'db_port' => 3306,               // 宝塔里可能不是 3306，以面板显示为准
    'db_name' => 'couple',           // 数据库名
    'db_user' => 'couple',           // 数据库用户名
    'db_pass' => '请替换为数据库密码', // 数据库密码
    'db_charset' => 'utf8mb4',       // 固定 utf8mb4（支持 emoji）

    // ── 必填 ────────────────────────────────────────────────────────────────
    // 用户 token 的签名密钥（HS256）。要求 ≥32 位随机串。
    // 安装向导会自动生成；手工填写时用： php -r "echo bin2hex(random_bytes(32));"
    'jwt_secret' => '请替换为至少32位的随机字符串请替换为至少32位',

    // 后台管理登录密码（≥6 位）。登录地址： /admin.php
    'admin_password' => '请替换为后台管理密码',

    // ── 可选 ────────────────────────────────────────────────────────────────
    // 后台 token 的签名密钥；留空则回退到 jwt_secret。
    // 单独设置可以让「用户 token」与「后台 token」使用不同密钥，更安全。
    'admin_jwt_secret' => '',

    // DeepSeek API Key。可留空 —— 留空时登录后台在「AI 设置」里填写即可。
    // 这里填了会作为「数据库里还没有 Key 时」的初始值写入（加密存储）。
    'deepseek_api_key' => '',

    // 表情文件、日志、运行时状态文件的存放目录（数据库本身在 MySQL 里）。
    // 相对路径基于 server-php/ 解析；宝塔部署时建议写成站外目录，
    // 例如 '/www/wwwroot/couple-data'，并确保 PHP 运行用户（www）有读写权限。
    'data_dir' => './data',

    // 生成表情绝对地址用的前缀，例如 'https://your-domain.com'。
    // 留空时 /api/meta 与表情 url 返回相对地址（iOS 客户端可直接使用）。
    'public_base_url' => '',

    // 时区（影响每日限额、日志时间、/api/ai 的默认日期）
    'timezone' => 'Asia/Shanghai',

    // REST 服务监听端口（仅 bin/console.php serve 使用；宝塔/PHP-FPM 忽略）
    'port' => 8787,

    // 是否信任反向代理的 X-Forwarded-For（用于限流与日志里的真实 IP）
    'trust_proxy' => true,

    // 日志级别：debug / info / warn / error
    'log_level' => 'info',

    // WebSocket 常驻进程监听地址与端口
    'ws_host' => '0.0.0.0',
    'ws_port' => 8900,

    // 数据库走 TLS 时的 CA 证书路径（一般留空；宝塔本机连接不需要）
    'db_ssl_ca' => '',
];
