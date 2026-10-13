<?php
/**
 * 命令行工具（宝塔/SSH 里使用）
 *
 *   php bin/console.php check                 配置自检 + 数据库连接/版本（不启动服务）
 *   php bin/console.php gen-secret            生成随机密钥
 *   php bin/console.php init-db               建表 + 写入默认设置（幂等，可重复执行）
 *   php bin/console.php sync-public-emoji     同步 public/emoji 下的内置公共表情包
 *   php bin/console.php purge                 清理过期数据（解绑 30 天以上 + 限流记录）
 *   php bin/console.php serve [--port=8787]   用 PHP 内置服务器跑 REST（仅供本地调试）
 *   php bin/console.php stats                 打印统计信息
 *
 * 数据库是 MySQL / MariaDB：首次部署请先在浏览器打开安装向导（/install.php）生成 config.php。
 */

require __DIR__ . '/../src/Bootstrap.php';

// 只注册自动加载器（不连数据库），下面几个命令不需要数据库
App\Bootstrap::registerAutoloader();

$argvAll = isset($argv) ? $argv : [];
$command = isset($argvAll[1]) ? $argvAll[1] : 'help';

if ($command === 'gen-secret') {
    echo App\util\Ids::randomSecret(32) . "\n";
    exit(0);
}

if ($command === 'serve') {
    // 先做一次配置校验，缺数据库配置时提示去浏览器安装向导
    try {
        App\Config::assertConfig();
    } catch (App\InstallRequired $e) {
        fwrite(STDERR, "❌ 尚未完成安装：请先在浏览器打开安装向导\n"
            . "   站点地址 + /install.php   （例如 http://127.0.0.1:8787/install.php）\n"
            . "   缺少： " . $e->getMessage() . "\n");
        exit(1);
    }
    $opts = App\util\Cli::options(array_slice($argvAll, 2));
    $port = isset($opts['port']) && $opts['port'] !== true ? (int) $opts['port'] : (int) App\Config::get('port', 8787);
    $host = isset($opts['host']) && $opts['host'] !== true ? $opts['host'] : '127.0.0.1';
    $docroot = App\Config::publicDir();
    $php = PHP_BINARY;
    echo "启动 PHP 内置服务器： http://{$host}:{$port}\n";
    echo "安装向导（数据库配置）： http://{$host}:{$port}/install.php\n";
    echo "（本地调试用；生产环境请用 nginx + PHP-FPM）\n";
    passthru(escapeshellarg($php) . ' -S ' . escapeshellarg($host . ':' . $port) . ' -t ' . escapeshellarg($docroot), $code);
    exit((int) $code);
}

if ($command === 'help' || $command === '--help' || $command === '-h') {
    echo "情侣空间服务端（PHP 版 · MySQL）命令行工具\n\n";
    echo "  php bin/console.php check                 配置自检 + 数据库连接/版本\n";
    echo "  php bin/console.php gen-secret            生成随机密钥\n";
    echo "  php bin/console.php init-db               建表 + 写入默认设置\n";
    echo "  php bin/console.php sync-public-emoji     同步内置公共表情包\n";
    echo "  php bin/console.php purge                 清理过期数据\n";
    echo "  php bin/console.php stats                 打印统计信息\n";
    echo "  php bin/console.php serve [--port=8787]   本地用内置服务器跑 REST\n";
    echo "\n首次部署：浏览器打开 站点地址/install.php 填数据库信息（自动生成 config.php）\n";
    exit(0);
}

// 其余命令都需要完整的启动流程（配置校验 + 建库 + 时区）
try {
    App\Bootstrap::boot();
} catch (App\InstallRequired $e) {
    fwrite(STDERR, "❌ 尚未完成安装：请先在浏览器打开安装向导进行配置。\n"
        . "   站点地址 + /install.php\n"
        . "   缺少： " . $e->getMessage() . "\n"
        . "   也可以复制 config.example.php 为 config.php 手动填写数据库信息。\n");
    exit(1);
} catch (App\DbConnectionError $e) {
    fwrite(STDERR, "❌ 数据库连接失败： " . $e->getMessage() . "\n"
        . "   当前配置： " . App\Config::dbLabel() . "\n"
        . "   请检查宝塔面板里的数据库信息，或修改 config.php 后重试。\n");
    exit(1);
}

if ($command === 'check') {
    $version = App\Db::serverVersion();
    $engine = App\Db::serverEngine();
    $configured = App\Installer::isConfigured();
    echo "✅ 配置检查通过\n";
    echo "   安装状态： 已完成（config.php + 数据表齐备）\n";
    echo "   数据目录： " . App\Config::dataDir() . "\n";
    echo "   数据库：   " . ($engine !== '' ? $engine . ' ' : '') . $version . "\n";
    echo "   连接地址： " . App\Config::dbLabel() . "（用户 " . App\Config::dbUser() . "）\n";
    echo "   连接状态： 正常（SELECT 1 通过）\n";
    echo "   表情目录： " . App\Config::emojiDir() . "\n";
    echo "   时区：     " . date_default_timezone_get() . "\n";
    echo "   后台密码： 已配置（长度 " . strlen((string) App\Config::get('admin_password')) . "）\n";
    echo "   JWT 密钥： 已配置（长度 " . strlen((string) App\Config::get('jwt_secret')) . "）\n";
    echo "   数据库可读： " . (App\Db::pingDb() ? '是' : '否') . "\n";
    echo "   DeepSeek Key： " . (App\Settings::isAiConfigured() ? '已配置' : '未配置') . "\n";
    echo "   WebSocket： " . (App\WsHub::isRunning() ? ('运行中（在线 ' . App\WsHub::onlineCount() . '）') : '未检测到运行中的进程') . "\n";
    if (!$configured) {
        echo "   ⚠️ 数据表不完整，请运行： php bin/console.php init-db\n";
    }
    exit(0);
}

if ($command === 'init-db') {
    App\Db::init();
    echo "✅ 数据库已初始化（幂等，可重复执行）\n";
    echo "   " . App\Db::serverEngine() . ' ' . App\Db::serverVersion() . ' · ' . App\Config::dbLabel() . "\n";
    echo "   表结构版本：" . App\Config::SCHEMA_VERSION . "\n";
    exit(0);
}

if ($command === 'sync-public-emoji') {
    $result = App\PublicEmoji::sync();
    App\Db::setSetting('publicEmojiFingerprint', App\PublicEmoji::fingerprint());
    echo "✅ 公共表情包同步完成： 包 " . $result['packs'] . " 个 · 表情 " . $result['items'] . " 张\n";
    echo "   目录： " . App\Config::publicEmojiDir() . "\n";
    exit(0);
}

if ($command === 'purge') {
    $couples = App\Db::purgeDissolvedCouples(App\Config::limit('COUPLE_RETENTION_DAYS'));
    $limits = App\RateLimit::purge();
    echo "✅ 清理完成：过期情侣空间 " . $couples . " 个 · 限流记录 " . $limits . " 条\n";
    exit(0);
}

if ($command === 'stats') {
    $stats = App\Db::getStats();
    $disk = App\Db::getDiskUsage();
    echo "用户数：     " . $stats['users'] . "\n";
    echo "情侣数：     " . $stats['couples'] . "（累计 " . $stats['couplesTotal'] . "）\n";
    echo "消息数：     " . $stats['messages'] . "\n";
    echo "同步对象：   " . $stats['syncObjects'] . "\n";
    echo "表情包/表情：" . $stats['packs'] . " / " . $stats['items'] . "\n";
    echo "今日 AI 调用：" . $stats['aiToday'] . "（累计 " . $stats['aiTotal'] . "）\n";
    echo "占用：       " . number_format($disk['totalBytes'] / 1024, 1) . " KB（数据库 "
        . number_format($disk['dbBytes'] / 1024, 1) . " KB · 表情 "
        . number_format($disk['emojiBytes'] / 1024, 1) . " KB / " . $disk['emojiFiles'] . " 个文件）\n";
    exit(0);
}

fwrite(STDERR, "未知命令： {$command}\n执行 php bin/console.php help 查看用法。\n");
exit(1);
