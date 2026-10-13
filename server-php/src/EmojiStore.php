<?php
namespace App;

use App\util\Ids;

/**
 * 表情图片的磁盘存取。
 *
 * 文件路径完全由服务端决定，不含任何用户输入：
 *   - 公共内置表情（owner_id = 'system'）-> public/emoji/<包名>/<文件名>
 *   - 用户上传表情                        -> {data_dir}/emoji/<id>.bin
 */
class EmojiStore
{
    /**
     * 用户上传表情的本地路径（调用前必须已用 Ids::isValidEmojiId 校验 id）
     *
     * id 的字符集被限制为 ^e_[A-Za-z0-9]+$（不含 / \ . 与 %），因此拼出来的文件名
     * 不可能跳出表情目录；这里再做一次「解析后仍在目录内」的二次防御。
     *
     * @return string|null
     */
    public static function localPath($id)
    {
        if (!Ids::isValidEmojiId($id)) {
            return null;
        }
        $base = str_replace('\\', '/', rtrim(Config::emojiDir(), '/\\'));
        $path = $base . '/' . $id . '.bin';
        if (strpos($path, '..') !== false || strpos($path, $base . '/') !== 0) {
            return null;
        }
        return $path;
    }

    /**
     * 解析某个表情条目的磁盘路径（用户上传即使不存在也返回路径，由调用方决定 404）
     * @param array $itemRow emoji_items 行
     * @return string|null
     */
    public static function resolve(array $itemRow)
    {
        $id = isset($itemRow['id']) ? (string) $itemRow['id'] : '';
        if (!Ids::isValidEmojiId($id)) {
            return null;
        }
        $local = self::localPath($id);
        if ($local !== null && is_file($local)) {
            return $local;
        }
        // 公共内置表情：id 形如 e_public<packIdx><fileIdx>，扫描目录反查文件名
        if (isset($itemRow['owner_id']) && $itemRow['owner_id'] === 'system') {
            $public = PublicEmoji::resolvePath($id);
            if ($public !== null) {
                return $public;
            }
        }
        return $local;
    }
}
