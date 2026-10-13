<?php
namespace App;

use App\util\Image;

/**
 * 内置公共表情包（API.md §6：isPublic=true 为内置公共表情包）。
 *
 * 目录约定（服务端预置，随仓库一起发布）：
 *   public/emoji/<表情包名>/<文件名>.{jpg,jpeg,png,gif,webp}
 *
 * 每次「目录内容有变化」时重新扫描并覆盖同名包的内容，保证与磁盘一致；
 * 用户自建包（p_ 前缀但不含 p_public_）完全不受影响。
 */
class PublicEmoji
{
    /** 公共表情包固定 id 前缀，便于与用户自建包区分 */
    const PACK_PREFIX = 'p_public_';

    /**
     * 扫描目录并按需同步（目录内容指纹未变化时直接跳过，避免每个请求都写库）
     * @return array{packs:int,items:int,skipped:bool}
     */
    public static function syncIfChanged()
    {
        $fingerprint = self::fingerprint();
        $row = Db::one('SELECT value FROM settings WHERE key = ?', ['publicEmojiFingerprint']);
        $current = $row ? (string) Db::parseJson($row['value']) : '';
        if ($current === $fingerprint) {
            return ['packs' => 0, 'items' => 0, 'skipped' => true];
        }
        $result = self::sync();
        Db::setSetting('publicEmojiFingerprint', $fingerprint);
        return $result + ['skipped' => false];
    }

    /** 目录内容指纹（包名 + 文件名 + 文件大小） */
    public static function fingerprint()
    {
        $dir = Config::publicEmojiDir();
        if (!is_dir($dir)) {
            return 'empty';
        }
        $parts = [];
        foreach (self::listDirs() as $packName) {
            $parts[] = $packName . ':';
            foreach (self::listFiles($dir . '/' . $packName) as $fileName) {
                $size = @filesize($dir . '/' . $packName . '/' . $fileName);
                $parts[] = $fileName . '=' . ($size === false ? 0 : $size);
            }
        }
        return md5(implode('|', $parts));
    }

    /**
     * 从磁盘目录同步内置公共表情包
     * @return array{packs:int,items:int}
     */
    public static function sync()
    {
        $dir = Config::publicEmojiDir();
        $result = ['packs' => 0, 'items' => 0];
        if (!is_dir($dir)) {
            return $result;
        }
        $packs = self::listDirs();
        if (count($packs) === 0) {
            return $result;
        }

        $now = Time::nowMs();
        $packIdx = 0;
        foreach ($packs as $packName) {
            $packIdx++;
            $packDir = $dir . '/' . $packName;
            $packId = self::PACK_PREFIX . preg_replace('/[^A-Za-z0-9_-]/', '_', $packName);

            $files = self::listFiles($packDir);
            if (count($files) === 0) {
                continue;
            }

            Db::run(
                'INSERT INTO emoji_packs (id, name, owner_id, couple_id, is_public, is_system, created_at, updated_at)
                 VALUES (?, ?, \'system\', \'\', 1, 1, ?, ?)
                 ON CONFLICT(id) DO UPDATE SET name = excluded.name, updated_at = excluded.updated_at',
                [$packId, $packName, $now, $now]
            );
            // 重建该包内容，保证与磁盘一致
            Db::run('DELETE FROM emoji_items WHERE pack_id = ?', [$packId]);

            $fileIdx = 0;
            $added = 0;
            foreach ($files as $fileName) {
                $fileIdx++;
                $path = $packDir . '/' . $fileName;
                $buf = @file_get_contents($path);
                if ($buf === false || $buf === '') {
                    continue;
                }
                if (strlen($buf) > Config::limit('EMOJI_MAX_BYTES')) {
                    continue;
                }
                // 同样按 magic bytes 校验，非法文件直接跳过
                $mime = Image::sniffMime($buf);
                if ($mime === null || !in_array($mime, Config::EMOJI_MIMES, true)) {
                    Log::warn('公共表情文件格式不受支持，已跳过', ['file' => $path]);
                    continue;
                }
                $baseName = preg_replace('/\.[^.]+$/', '', $fileName);
                Db::run(
                    'INSERT OR REPLACE INTO emoji_items
                       (id, pack_id, owner_id, couple_id, mime, size, name, created_at, updated_at)
                     VALUES (?, ?, \'system\', \'\', ?, ?, ?, ?, ?)',
                    ['e_public' . $packIdx . $fileIdx, $packId, $mime, strlen($buf), $baseName, $now, $now]
                );
                $added++;
            }
            if ($added > 0) {
                $result['packs']++;
                $result['items'] += $added;
            }
        }
        return $result;
    }

    /**
     * 解析公共内置表情的磁盘路径。
     * id 形如 e_public<packIdx><fileIdx>，按与 sync() 完全相同的排序定位。
     * @param string $id
     * @return string|null
     */
    public static function resolvePath($id)
    {
        if (!preg_match('/^e_public(\d+)(\d+)$/', $id, $m)) {
            return null;
        }
        $packIdx = (int) $m[1];
        $fileIdx = (int) $m[2];
        $dir = Config::publicEmojiDir();
        $packs = self::listDirs();
        if (!isset($packs[$packIdx - 1])) {
            return null;
        }
        $packDir = $dir . '/' . $packs[$packIdx - 1];
        $files = self::listFiles($packDir);
        if (!isset($files[$fileIdx - 1])) {
            return null;
        }
        $resolved = realpath($packDir . '/' . $files[$fileIdx - 1]);
        $base = realpath($dir);
        // 二次防御：确保仍在公共表情目录内
        if ($resolved === false || $base === false || strpos($resolved, $base . DIRECTORY_SEPARATOR) !== 0) {
            return null;
        }
        return $resolved;
    }

    /** @return string[] 子目录名（按名称排序，保证 id 映射稳定可复现） */
    private static function listDirs()
    {
        $dir = Config::publicEmojiDir();
        if (!is_dir($dir)) {
            return [];
        }
        $names = @scandir($dir);
        if (!is_array($names)) {
            return [];
        }
        $out = [];
        foreach ($names as $n) {
            if ($n === '.' || $n === '..') {
                continue;
            }
            if (is_dir($dir . '/' . $n)) {
                $out[] = $n;
            }
        }
        sort($out, SORT_STRING);
        return $out;
    }

    /** @return string[] 图片文件名（按名称排序） */
    private static function listFiles($packDir)
    {
        if (!is_dir($packDir)) {
            return [];
        }
        $names = @scandir($packDir);
        if (!is_array($names)) {
            return [];
        }
        $out = [];
        foreach ($names as $n) {
            if ($n === '.' || $n === '..') {
                continue;
            }
            if (!is_file($packDir . '/' . $n)) {
                continue;
            }
            if (preg_match('/\.(jpe?g|png|gif|webp)$/i', $n)) {
                $out[] = $n;
            }
        }
        sort($out, SORT_STRING);
        return $out;
    }
}
