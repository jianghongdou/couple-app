<?php
/**
 * WebSocket 常驻进程（纯 PHP 实现，无需任何扩展）
 *
 * 启动：
 *   php bin/ws-server.php
 *   php bin/ws-server.php --port=8900 --host=0.0.0.0
 * 环境变量： WS_HOST / WS_PORT（优先级高于 config.php）
 *
 * 生产环境请用 supervisor / systemd / 宝塔「进程守护管理器」保持常驻，
 * 并把 nginx 的 /ws 反向代理到 127.0.0.1:<WS_PORT>。
 */

require __DIR__ . '/../src/Bootstrap.php';

App\Bootstrap::registerAutoloader();

// 命令行参数（--port=8900 --host=0.0.0.0 --path=/ws）
$opts = App\util\Cli::options(array_slice(isset($argv) ? $argv : [], 1));
$host = isset($opts['host']) && $opts['host'] !== true ? $opts['host'] : App\Config::get('ws_host', '0.0.0.0');
$port = isset($opts['port']) && $opts['port'] !== true ? (int) $opts['port'] : (int) App\Config::get('ws_port', 8900);
$path = isset($opts['path']) && $opts['path'] !== true ? $opts['path'] : '/ws';

// 配置校验 + 建库（缺 jwt_secret / admin_password 时中文提示并退出）
App\Bootstrap::boot();

$server = new App\WsServer($host, $port, $path);

// 优雅退出（Windows 的 PHP 没有 pcntl，用进程管理器结束即可）
if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGINT, function () use ($server) {
        fwrite(STDOUT, "\n收到 SIGINT，正在退出…\n");
        $server->stop();
    });
    pcntl_signal(SIGTERM, function () use ($server) {
        fwrite(STDOUT, "\n收到 SIGTERM，正在退出…\n");
        $server->stop();
    });
}

$server->run();
