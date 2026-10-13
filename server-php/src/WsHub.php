<?php
namespace App;

/**
 * WebSocket 在线状态（跨进程只读视图）。
 *
 * REST 进程与 WS 常驻进程是两个进程，在线数只能靠状态文件传递：
 *   bin/ws-server.php 每 10 秒把 { online, users, pid, at } 写到 data/ws-status.json
 *   REST/后台读取该文件，超过 60 秒未更新则视为「WS 未运行」，返回 0。
 */
class WsHub
{
    const STALE_MS = 60000;

    private static function statusFile()
    {
        return Config::dataDir() . '/ws-status.json';
    }

    /** WS 进程写入自己的状态 */
    public static function publish($online, $users = 0, $extra = [])
    {
        $data = array_merge([
            'online' => (int) $online,
            'users' => (int) $users,
            'pid' => function_exists('getmypid') ? getmypid() : 0,
            'at' => Time::nowMs(),
            'port' => (int) Config::get('ws_port', 8900),
        ], $extra);
        @file_put_contents(self::statusFile(), json_encode($data));
    }

    /** 读取状态文件（可能为 null） */
    public static function status()
    {
        $file = self::statusFile();
        if (!is_file($file)) {
            return null;
        }
        $raw = @file_get_contents($file);
        if ($raw === false) {
            return null;
        }
        $json = json_decode($raw, true);
        if (!is_array($json) || !isset($json['at'])) {
            return null;
        }
        if (Time::nowMs() - (int) $json['at'] > self::STALE_MS) {
            return null;
        }
        return $json;
    }

    /** 在线设备数（WS 未运行时为 0） */
    public static function onlineCount()
    {
        $s = self::status();
        return $s === null ? 0 : (int) $s['online'];
    }

    /** 服务运行时长（秒） */
    public static function uptimeSeconds()
    {
        return Runtime::uptimeSeconds();
    }

    /** WS 是否在运行 */
    public static function isRunning()
    {
        return self::status() !== null;
    }
}
