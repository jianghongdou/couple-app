<?php
/**
 * 表情图片的磁盘存取。
 *
 * 移植自 server-php/src/EmojiStore.php。差异：图片存放目录改成
 * wp-content/uploads/couple-emoji/（WordPress 约定的上传目录），
 * 通过本插件自己的 API 输出，不直接暴露文件 URL。
 *
 * 文件路径完全由服务端决定，不含任何用户输入：
 *   - 用户上传表情 -> {uploads}/couple-emoji/<id>.bin
 *   - 内置公共表情 -> 插件目录 public/emoji/<包名>/<文件名>
 *
 * @package CoupleSpace
 */

namespace CoupleSpace;

defined( 'ABSPATH' ) || exit;

/**
 * 表情磁盘存取。
 */
class EmojiStore {

	/** 上传目录下的子目录名。 */
	const DIR_NAME = 'couple-emoji';

	/** @var string|null 目录缓存。 */
	private static $base_dir = null;

	/**
	 * 表情图片根目录（wp-content/uploads/couple-emoji）。
	 *
	 * @return string
	 */
	public static function base_dir() {
		if ( null !== self::$base_dir ) {
			return self::$base_dir;
		}
		/**
		 * 允许站点把表情目录放到站外（例如宝塔里单独挂一块盘）。
		 *
		 * @param string $dir 目录绝对路径。
		 */
		$custom = apply_filters( 'couple_space_emoji_dir', '' );
		if ( is_string( $custom ) && '' !== $custom ) {
			self::$base_dir = rtrim( str_replace( '\\', '/', $custom ), '/' );
			return self::$base_dir;
		}
		// 直接算路径，不调用 wp_upload_dir()：后者会触发 uploads 目录检查，
		// 在极端环境（uploads 不可写）下返回 error，反而让表情功能整体不可用。
		$uploads        = wp_get_upload_dir();
		self::$base_dir = rtrim( str_replace( '\\', '/', $uploads['basedir'] ), '/' ) . '/' . self::DIR_NAME;
		return self::$base_dir;
	}

	/** 确保目录存在。 */
	public static function ensure_dirs() {
		$dir = self::base_dir();
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
	}

	/**
	 * 用户上传表情的本地路径（调用前必须已用 Ids::is_valid_emoji_id 校验 id）。
	 *
	 * id 的字符集被限制为 ^e_[A-Za-z0-9]+$（不含 / \ . 与 %），因此拼出来的文件名
	 * 不可能跳出表情目录；这里再做一次「解析后仍在目录内」的二次防御。
	 *
	 * @param string $id 表情 id。
	 * @return string|null
	 */
	public static function local_path( $id ) {
		if ( ! Ids::is_valid_emoji_id( $id ) ) {
			return null;
		}
		$base = rtrim( str_replace( '\\', '/', self::base_dir() ), '/\\' );
		$path = $base . '/' . $id . '.bin';
		if ( false !== strpos( $path, '..' ) || 0 !== strpos( $path, $base . '/' ) ) {
			return null;
		}
		return $path;
	}

	/**
	 * 解析某个表情条目的磁盘路径（用户上传即使不存在也返回路径，由调用方决定 404）。
	 *
	 * @param array<string,mixed> $item_row emoji_items 行。
	 * @return string|null
	 */
	public static function resolve( array $item_row ) {
		$id = isset( $item_row['id'] ) ? (string) $item_row['id'] : '';
		if ( ! Ids::is_valid_emoji_id( $id ) ) {
			return null;
		}
		$local = self::local_path( $id );
		if ( null !== $local && is_file( $local ) ) {
			return $local;
		}
		// 内置公共表情：id 形如 e_public<packIdx><fileIdx>，扫描目录反查文件名。
		if ( isset( $item_row['owner_id'] ) && 'system' === $item_row['owner_id'] ) {
			$public = PublicEmoji::resolve_path( $id );
			if ( null !== $public ) {
				return $public;
			}
		}
		return $local;
	}

	/**
	 * 删除某个表情的磁盘文件（不存在也算成功）。
	 *
	 * @param array<string,mixed> $item_row emoji_items 行。
	 */
	public static function delete_file( array $item_row ) {
		$path = self::resolve( $item_row );
		if ( null !== $path && is_file( $path ) ) {
			wp_delete_file( $path );
		}
	}

	/** @return array{dir:string,writable:bool,exists:bool,files:int,bytes:int} 目录状态（后台展示用）。 */
	public static function status() {
		$dir     = self::base_dir();
		$files   = 0;
		$bytes   = 0;
		$exists  = is_dir( $dir );
		$names   = $exists ? @scandir( $dir ) : false;
		if ( is_array( $names ) ) {
			foreach ( $names as $name ) {
				if ( '.' === $name || '..' === $name || 'logs' === $name ) {
					continue;
				}
				$p = $dir . '/' . $name;
				if ( is_file( $p ) ) {
					$files++;
					$bytes += (int) filesize( $p );
				}
			}
		}
		return array(
			'dir'      => $dir,
			'exists'   => $exists,
			'writable' => $exists && wp_is_writable( $dir ),
			'files'    => $files,
			'bytes'    => $bytes,
		);
	}
}
