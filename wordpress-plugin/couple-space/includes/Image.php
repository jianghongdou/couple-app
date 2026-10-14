<?php
/**
 * 图片二进制处理：magic bytes 识别 + 扩展名映射。
 * 绝不信任客户端声明的 mime。
 *
 * 移植自 server-php/src/util/Image.php。
 *
 * @package CoupleSpace
 */

namespace CoupleSpace;

defined( 'ABSPATH' ) || exit;

/**
 * 图片嗅探。
 */
class Image {

	/**
	 * 通过 magic bytes 判断真实图片类型。
	 *
	 * @param string $buf 二进制内容。
	 * @return string|null 匹配到的 mime；无法识别返回 null。
	 */
	public static function sniff_mime( $buf ) {
		if ( ! is_string( $buf ) || strlen( $buf ) < 12 ) {
			return null;
		}
		// JPEG: FF D8 FF。
		if ( 0xFF === ord( $buf[0] ) && 0xD8 === ord( $buf[1] ) && 0xFF === ord( $buf[2] ) ) {
			return 'image/jpeg';
		}
		// PNG: 89 50 4E 47 0D 0A 1A 0A。
		if ( 0x89 === ord( $buf[0] ) && 0x50 === ord( $buf[1] ) && 0x4E === ord( $buf[2] ) && 0x47 === ord( $buf[3] )
			&& 0x0D === ord( $buf[4] ) && 0x0A === ord( $buf[5] ) && 0x1A === ord( $buf[6] ) && 0x0A === ord( $buf[7] ) ) {
			return 'image/png';
		}
		// GIF: "GIF87a" / "GIF89a"。
		$head6 = substr( $buf, 0, 6 );
		if ( 'GIF87a' === $head6 || 'GIF89a' === $head6 ) {
			return 'image/gif';
		}
		// WEBP: "RIFF" .... "WEBP"。
		if ( 'RIFF' === substr( $buf, 0, 4 ) && 'WEBP' === substr( $buf, 8, 4 ) ) {
			return 'image/webp';
		}
		return null;
	}

	/**
	 * 按 mime 给出建议扩展名（仅用于内置公共表情目录，不参与用户上传）。
	 *
	 * @param string $mime mime。
	 * @return string
	 */
	public static function extension_for( $mime ) {
		$map = array(
			'image/jpeg' => 'jpg',
			'image/png'  => 'png',
			'image/gif'  => 'gif',
			'image/webp' => 'webp',
		);
		return isset( $map[ $mime ] ) ? $map[ $mime ] : 'bin';
	}
}
