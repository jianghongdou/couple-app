<?php
namespace App\routes;

use App\Config;
use App\Db;
use App\Http;
use App\Settings;
use App\Time;

/**
 * 公开接口：健康检查与客户端元信息（API.md §1）
 */
class MetaRoutes
{
    public static function register($r)
    {
        // GET /api/health —— 响应 { ok, name, version, time }
        $r->get('/api/health', function () {
            Http::json([
                'ok' => true,
                'name' => Config::APP_NAME,
                'version' => Config::APP_VERSION,
                'time' => Time::nowMs(),
            ], 200);
        });

        // GET /api/meta —— 无需鉴权，客户端启动时拉取
        $r->get('/api/meta', function () {
            $s = Settings::readAll();
            $aiOn = $s['aiEnabled'] !== false;
            $aiConfigured = is_string($s['deepseekApiKey']) && $s['deepseekApiKey'] !== '';

            Http::json([
                'appName' => Config::DISPLAY_NAME,
                'serverVersion' => Config::APP_VERSION,
                'minAppBuild' => is_int($s['minAppBuild']) ? $s['minAppBuild'] : 1,
                'latestAppBuild' => is_int($s['latestAppBuild']) ? $s['latestAppBuild'] : 1,
                'updateURL' => is_string($s['updateURL']) ? $s['updateURL'] : '',
                'announcement' => is_string($s['announcement']) ? $s['announcement'] : '',
                'registerOpen' => $s['registerOpen'] !== false,
                'features' => [
                    'chat' => true,
                    'emoji' => true,
                    'ai' => $aiOn,
                    'sync' => true,
                ],
                'aiConfigured' => $aiConfigured,
                'serverTime' => Time::nowMs(),
            ], 200);
        });

        // GET /api/status —— 运维概览（公开只读，不含敏感信息）
        $r->get('/api/status', function () {
            $stats = Db::getStats();
            Http::json([
                'ok' => true,
                'version' => Config::APP_VERSION,
                'users' => $stats['users'],
                'couples' => $stats['couples'],
                'messages' => $stats['messages'],
                'online' => \App\WsHub::onlineCount(),
                'dbOk' => Db::pingDb(),
                'uptimeSeconds' => \App\WsHub::uptimeSeconds(),
                'serverTime' => Time::nowMs(),
            ], 200);
        });
    }
}
