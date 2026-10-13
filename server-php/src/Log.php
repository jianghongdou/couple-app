<?php
namespace App;

/**
 * 极简日志：写入 {data_dir}/logs/app.log，CLI 下同时输出到 stderr。
 * 级别：debug < info < warn < error
 */
class Log
{
    private static $levels = ['debug' => 10, 'info' => 20, 'warn' => 30, 'error' => 40];
    private static $threshold = 20;
    private static $ready = false;

    private static function boot()
    {
        if (self::$ready) {
            return;
        }
        self::$ready = true;
        $level = strtolower((string) Config::get('log_level', 'info'));
        self::$threshold = isset(self::$levels[$level]) ? self::$levels[$level] : 20;
    }

    public static function debug($message, array $context = [])
    {
        self::write('debug', $message, $context);
    }

    public static function info($message, array $context = [])
    {
        self::write('info', $message, $context);
    }

    public static function warn($message, array $context = [])
    {
        self::write('warn', $message, $context);
    }

    public static function error($message, array $context = [])
    {
        self::write('error', $message, $context);
    }

    private static function write($level, $message, array $context)
    {
        self::boot();
        if (self::$levels[$level] < self::$threshold) {
            return;
        }
        $time = date('Y-m-d H:i:s');
        $line = '[' . $time . '] ' . strtoupper($level) . ' ' . $message;
        if (count($context) > 0) {
            $json = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $line .= ' ' . ($json === false ? '{}' : $json);
        }
        $line .= "\n";

        $file = Config::logFile();
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        // 日志文件超过 8MB 时轮转一次，避免无限增长
        if (is_file($file) && filesize($file) > 8388608) {
            @rename($file, $file . '.' . date('Ymd-His') . '.bak');
        }
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);

        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, $line);
        }
    }
}
