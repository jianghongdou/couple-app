<?php
/**
 * 命令行工具（宝塔/SSH 里使用）
 *
 *   php bin/console.php check                 配置自检（不启动服务）
 *   php bin/console.php gen-secret            生成随机密钥
 *   php bin/console.php init-db               建表 + 写入默认设置
 *   php bin/console.php sync-public-emoji     同步 public/emoji 下的内置公共表情包
 *   php bin/console.php purge                 清理过期数据（解绑 30 天以上 + 限流记录）
 *   php bin/console.php serve [--port=8787]   用 PHP 内置服务器跑 REST（仅供本地调试）
 *   php bin/console.php stats                 打印统计信息
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
    // 先做一次配置校验，缺 jwt_secret / admin_password 时立刻报错退出
    App\Config::assertConfig();
    $opts = App\util\Cli::options(array_slice($argvAll, 2));
    $port = isset($opts['port']) && $opts['port'] !== true ? (int) $opts['port'] : (int) App\Config::get('port', 8787);
    $host = isset($opts['host']) && $opts['host'] !== true ? $opts['host'] : '127.0.0.1';
    $docroot = App\Config::publicDir();
    $php = PHP_BINARY;
    echo "启动 PHP 内置服务器： http://{$host}:{$port}\n";
    echo "（本地调试用；生产环境请用 nginx + PHP-FPM）\n";
    passthru(escapeshellarg($php) . ' -S ' . escapeshellarg($host . ':' . $port) . ' -t ' . escapeshellarg($docroot), $code);
    exit((int) $code);
}

if ($command === 'help' || $command === '--help' || $command === '-h') {
    echo "情侣空间服务端（PHP 版）命令行工具\n\n";
    echo "  php bin/console.php check                 配置自检（不启动服务）\n";
    echo "  php bin/console.php gen-secret            生成随机密钥\n";
    echo "  php bin/console.php init-db               建表 + 写入默认设置\n";
    echo "  php bin/console.php sync-public-emoji     同步内置公共表情包\n";
    echo "  php bin/console.php purge                 清理过期数据\n";
    echo "  php bin/console.php stats                 打印统计信息\n";
    echo "  php bin/console.php serve [--port=8787]   本地用内置服务器跑 REST\n";
    exit(0);
}

// 其余命令都需要完整的启动流程（配置校验 + 建库 + 时区）
App\Bootstrap::boot();

if ($command === 'check') {
    echo "✅ 配置检查通过\n";
    echo "   数据目录： " . App\Config::dataDir() . "\n";
    echo "   数据库：   " . App\Config::dbFile() . "\n";
    echo "   表情目录： " . App\Config::emojiDir() . "\n";
    echo "   时区：     " . date_default_timezone_get() . "\n";
    echo "   后台密码： 已配置（长度 " . strlen((string) App\Config::get('admin_password')) . "）\n";
    echo "   JWT 密钥： 已配置（长度 " . strlen((string) App\Config::get('jwt_secret')) . "）\n";
    echo "   数据库可读： " . (App\Db::pingDb() ? '是' : '否') . "\n";
    echo "   DeepSeek Key： " . (App\Settings::isAiConfigured() ? '已配置' : '未配置') . "\n";
    echo "   WebSocket： " . (App\WsHub::isRunning() ? ('运行中（在线 ' . App\WsHub::onlineCount() . '）') : '未检测到运行中的进程') . "\n";
    exit(0);
}

if ($command === 'init-db') {
    App\Db::init();
    echo "✅ 数据库已初始化： " . App\Config::dbFile() . "\n";
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
    echo "磁盘占用：   " . number_format($disk['totalBytes'] / 1024, 1) . " KB（数据库 "
        . number_format($disk['dbBytes'] / 1024, 1) . " KB · 表情 "
        . number_format($disk['emojiBytes'] / 1024, 1) . " KB / " . $disk['emojiFiles'] . " 个文件）\n";
    exit(0);
}

fwrite(STDERR, "未知命令： {$command}\n执行 php bin/console.php help 查看用法。\n");
exit(1);
