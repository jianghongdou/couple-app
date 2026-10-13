<?php
namespace App\util;

/**
 * 图片二进制处理：magic bytes 识别 + 分类目录映射。
 * 绝不信任客户端声明的 mime。
 */
class Image
{
    /**
     * 通过 magic bytes 判断真实图片类型
     * @param string $buf
     * @return string|null 匹配到的 mime，无法识别返回 null
     */
    public static function sniffMime($buf)
    {
        if (!is_string($buf) || strlen($buf) < 12) {
            return null;
        }
        // JPEG: FF D8 FF
        if (ord($buf[0]) === 0xFF && ord($buf[1]) === 0xD8 && ord($buf[2]) === 0xFF) {
            return 'image/jpeg';
        }
        // PNG: 89 50 4E 47 0D 0A 1A 0A
        if (ord($buf[0]) === 0x89 && ord($buf[1]) === 0x50 && ord($buf[2]) === 0x4E && ord($buf[3]) === 0x47
            && ord($buf[4]) === 0x0D && ord($buf[5]) === 0x0A && ord($buf[6]) === 0x1A && ord($buf[7]) === 0x0A) {
            return 'image/png';
        }
        // GIF: "GIF87a" / "GIF89a"
        $head6 = substr($buf, 0, 6);
        if ($head6 === 'GIF87a' || $head6 === 'GIF89a') {
            return 'image/gif';
        }
        // WEBP: "RIFF" .... "WEBP"
        if (substr($buf, 0, 4) === 'RIFF' && substr($buf, 8, 4) === 'WEBP') {
            return 'image/webp';
        }
        return null;
    }

    /** 按 mime 给出建议扩展名（仅用于公共表情目录里的文件名生成，不参与用户上传） */
    public static function extensionFor($mime)
    {
        $map = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
        ];
        return isset($map[$mime]) ? $map[$mime] : 'bin';
    }
}
