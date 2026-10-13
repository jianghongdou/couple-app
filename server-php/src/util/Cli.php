<?php
namespace App\util;

/**
 * 命令行参数解析（不依赖 getopt，因为它遇到第一个位置参数就停止解析）。
 *
 * 支持三种写法：
 *   --port=8900
 *   --port 8900
 *   --verbose          （无值时为 true）
 */
class Cli
{
    /**
     * @param array<int,string> $args 命令名之后的参数
     * @return array<string,mixed>
     */
    public static function options(array $args)
    {
        $out = [];
        $n = count($args);
        for ($i = 0; $i < $n; $i++) {
            $a = $args[$i];
            if (!is_string($a) || strpos($a, '--') !== 0) {
                continue;
            }
            $body = substr($a, 2);
            $eq = strpos($body, '=');
            if ($eq !== false) {
                $out[substr($body, 0, $eq)] = substr($body, $eq + 1);
                continue;
            }
            $next = isset($args[$i + 1]) ? $args[$i + 1] : null;
            if ($next !== null && (strlen($next) === 0 || $next[0] !== '-')) {
                $out[$body] = $next;
                $i++;
                continue;
            }
            $out[$body] = true;
        }
        return $out;
    }
}
