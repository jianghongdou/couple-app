<?php
namespace App;

/**
 * 运行时信息（启动时间等）。
 *
 * PHP 没有 Node 那种 process.uptime()，这里把「服务启动时间」落在 data/.boot 里：
 *   - 文件不存在时创建
 *   - 代码（index.php / ws-server.php / Config.php）比该文件新，说明重新部署过，重置
 */
class Runtime
{
    private static $startedAt = null;

    private static function bootFile()
    {
        return Config::dataDir() . '/.boot';
    }

    public static function ensureBoot()
    {
        $file = self::bootFile();
        $stale = true;
        if (is_file($file)) {
            $bootMtime = (int) filemtime($file);
            $newest = 0;
            foreach ([Config::publicDir() . '/index.php', Config::root() . '/bin/ws-server.php', Config::root() . '/src/Config.php'] as $f) {
                if (is_file($f)) {
                    $newest = max($newest, (int) filemtime($f));
                }
            }
            $stale = $bootMtime < $newest;
        }
        if ($stale) {
            Config::ensureDirs();
            @file_put_contents($file, json_encode([
                'startedAt' => Time::nowMs(),
                'sapi' => PHP_SAPI,
                'pid' => function_exists('getmypid') ? getmypid() : 0,
                'version' => Config::APP_VERSION,
            ]));
        }
    }

    public static function startedAt()
    {
        if (self::$startedAt !== null) {
            return self::$startedAt;
        }
        $file = self::bootFile();
        $started = null;
        if (is_file($file)) {
            $raw = @file_get_contents($file);
            $json = $raw === false ? null : json_decode($raw, true);
            if (is_array($json) && isset($json['startedAt'])) {
                $started = (int) $json['startedAt'];
            }
        }
        if ($started === null) {
            $started = Time::nowMs();
        }
        self::$startedAt = $started;
        return $started;
    }

    public static function uptimeSeconds()
    {
        $diff = (int) floor((Time::nowMs() - self::startedAt()) / 1000);
        return $diff > 0 ? $diff : 0;
    }
}
