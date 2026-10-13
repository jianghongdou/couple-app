<?php
/**
 * 情侣空间服务端（PHP 版）· 配置模板
 *
 * 使用方法：把本文件复制为 config.php，然后按注释填写。
 * config.php 已被 .gitignore 忽略，不会进版本库（里面有密钥）。
 *
 * 也可以完全不写 config.php，改用同名的大写环境变量（方便宝塔/命令行部署）：
 *   JWT_SECRET、ADMIN_PASSWORD、ADMIN_JWT_SECRET、DEEPSEEK_API_KEY、
 *   DATA_DIR、PUBLIC_BASE_URL、TIMEZONE、PORT、TRUST_PROXY、LOG_LEVEL、
 *   WS_HOST、WS_PORT
 * 环境变量的优先级高于本文件。
 */

return [
    // ── 必填 ────────────────────────────────────────────────────────────────
    // 用户 token 的签名密钥（HS256）。要求 ≥32 位随机串。
    // 生成方式： php -r "echo bin2hex(random_bytes(32));"
    'jwt_secret' => '请替换为至少32位的随机字符串请替换为至少32位',

    // 后台管理登录密码（≥6 位）。登录地址： /admin
    'admin_password' => '请替换为后台管理密码',

    // ── 可选 ────────────────────────────────────────────────────────────────
    // 后台 token 的签名密钥；留空则回退到 jwt_secret。
    // 单独设置可以让「用户 token」与「后台 token」使用不同密钥，更安全。
    'admin_jwt_secret' => '',

    // DeepSeek API Key。可留空 —— 留空时登录后台在「AI 设置」里填写即可。
    // 这里填了会作为「数据库里还没有 Key 时」的初始值写入（加密存储）。
    'deepseek_api_key' => '',

    // 数据库与表情文件的存放目录。相对路径基于 server-php/ 解析。
    // 宝塔部署时建议写成站外目录，例如 '/www/wwwroot/couple-data'，
    // 并确保 PHP 运行用户（www）对该目录有读写权限。
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
];
