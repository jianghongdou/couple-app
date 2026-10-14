<?php
/**
 * 内置公共表情包（API.md §6：isPublic=true 为内置公共表情包）。
 *
 * 移植自 server-php/src/PublicEmoji.php。目录约定（随插件一起发布）：
 *   <插件目录>/public/emoji/<表情包名>/<文件名>.{jpg,jpeg,png,gif,webp}
 *
 * 每次「目录内容有变化」时重新扫描并覆盖同名包的内容，保证与磁盘一致；
 * 用户自建包（p_ 前缀但不含 p_public_）完全不受影响。
 *
 * @package CoupleSpace
 */

namespace CoupleSpace;

defined( 'ABSPATH' ) || exit;

/**
 * 公共表情同步。
 */
class PublicEmoji {

	/** 公共表情包固定 id 前缀，便于与用户自建包区分。 */
	const PACK_PREFIX = 'p_public_';

	/** 目录指纹的 settings 键。 */
	const FINGERPRINT_KEY = 'publicEmojiFingerprint';

	/**
	 * 扫描目录并按需同步（目录内容指纹未变化时直接跳过，避免每个请求都写库）。
	 *
	 * @return array{packs:int,items:int,skipped:bool}
	 */
	public static function sync_if_changed() {
		$fingerprint = self::fingerprint();
		$current     = (string) Db::get_setting( self::FINGERPRINT_KEY );
		if ( $current === $fingerprint ) {
			return array(
				'packs'   => 0,
				'items'   => 0,
				'skipped' => true,
			);
		}
		$result = self::sync();
		Db::set_setting( self::FINGERPRINT_KEY, $fingerprint );
		$result['skipped'] = false;
		return $result;
	}

	/**
	 * 目录内容指纹（包名 + 文件名 + 文件大小）。
	 *
	 * @return string
	 */
	public static function fingerprint() {
		$dir = Config::public_emoji_dir();
		if ( ! is_dir( $dir ) ) {
			return 'empty';
		}
		$parts = array();
		foreach ( self::list_dirs() as $pack_name ) {
			$parts[] = $pack_name . ':';
			foreach ( self::list_files( $dir . '/' . $pack_name ) as $file_name ) {
				$size    = @filesize( $dir . '/' . $pack_name . '/' . $file_name );
				$parts[] = $file_name . '=' . ( false === $size ? 0 : $size );
			}
		}
		return md5( implode( '|', $parts ) );
	}

	/**
	 * 从磁盘目录同步内置公共表情包。
	 *
	 * @return array{packs:int,items:int}
	 */
	public static function sync() {
		$dir    = Config::public_emoji_dir();
		$result = array(
			'packs' => 0,
			'items' => 0,
		);
		if ( ! is_dir( $dir ) ) {
			return $result;
		}
		$packs = self::list_dirs();
		if ( 0 === count( $packs ) ) {
			return $result;
		}

		$now      = Time::now_ms();
		$pack_idx = 0;
		foreach ( $packs as $pack_name ) {
			$pack_idx++;
			$pack_dir = $dir . '/' . $pack_name;
			$pack_id  = self::PACK_PREFIX . preg_replace( '/[^A-Za-z0-9_-]/', '_', $pack_name );

			$files = self::list_files( $pack_dir );
			if ( 0 === count( $files ) ) {
				continue;
			}

			// 已存在则保持原样（不能写 ON DUPLICATE KEY UPDATE name=VALUES(name)：
			// 后台改过的包名会被磁盘目录名冲掉），这里用 INSERT IGNORE 保持幂等。
			Db::run(
				'INSERT IGNORE INTO `' . Db::table( 'emoji_packs' ) . '` (id, name, owner_id, couple_id, is_public, is_system, created_at, updated_at)
				 VALUES (%s, %s, %s, %s, 1, 1, %d, %d)',
				array( $pack_id, $pack_name, 'system', '', $now, $now )
			);
			// 重建该包内容，保证与磁盘一致。
			Db::run( 'DELETE FROM `' . Db::table( 'emoji_items' ) . '` WHERE pack_id = %s', array( $pack_id ) );

			$file_idx = 0;
			$added    = 0;
			foreach ( $files as $file_name ) {
				$file_idx++;
				$path = $pack_dir . '/' . $file_name;
				$buf  = @file_get_contents( $path );
				if ( false === $buf || '' === $buf ) {
					continue;
				}
				if ( strlen( $buf ) > Config::limit( 'EMOJI_MAX_BYTES' ) ) {
					continue;
				}
				// 同样按 magic bytes 校验，非法文件直接跳过。
				$mime = Image::sniff_mime( $buf );
				if ( null === $mime || ! in_array( $mime, Config::EMOJI_MIMES, true ) ) {
					Log::warn( '公共表情文件格式不受支持，已跳过', array( 'file' => $path ) );
					continue;
				}
				$base_name = preg_replace( '/\.[^.]+$/', '', $file_name );
				// MySQL 通用写法：REPLACE INTO。
				Db::run(
					'REPLACE INTO `' . Db::table( 'emoji_items' ) . '`
					   (id, pack_id, owner_id, couple_id, mime, size, name, created_at, updated_at)
					 VALUES (%s, %s, %s, %s, %s, %d, %s, %d, %d)',
					array( 'e_public' . $pack_idx . $file_idx, $pack_id, 'system', '', $mime, strlen( $buf ), $base_name, $now, $now )
				);
				$added++;
			}
			if ( $added > 0 ) {
				$result['packs']++;
				$result['items'] += $added;
			}
		}
		return $result;
	}

	/**
	 * 解析公共内置表情的磁盘路径。
	 * id 形如 e_public<packIdx><fileIdx>，按与 sync() 完全相同的排序定位。
	 *
	 * @param string $id 表情 id。
	 * @return string|null
	 */
	public static function resolve_path( $id ) {
		if ( ! preg_match( '/^e_public(\d+)(\d+)$/', (string) $id, $m ) ) {
			return null;
		}
		$pack_idx = (int) $m[1];
		$file_idx = (int) $m[2];
		$dir      = Config::public_emoji_dir();
		$packs    = self::list_dirs();
		if ( ! isset( $packs[ $pack_idx - 1 ] ) ) {
			return null;
		}
		$pack_dir = $dir . '/' . $packs[ $pack_idx - 1 ];
		$files    = self::list_files( $pack_dir );
		if ( ! isset( $files[ $file_idx - 1 ] ) ) {
			return null;
		}
		$resolved = realpath( $pack_dir . '/' . $files[ $file_idx - 1 ] );
		$base     = realpath( $dir );
		// 二次防御：确保仍在公共表情目录内。
		if ( false === $resolved || false === $base || 0 !== strpos( $resolved, $base . DIRECTORY_SEPARATOR ) ) {
			return null;
		}
		return $resolved;
	}

	/**
	 * @return array<int,string> 子目录名（按名称排序，保证 id 映射稳定可复现）。
	 */
	private static function list_dirs() {
		$dir = Config::public_emoji_dir();
		if ( ! is_dir( $dir ) ) {
			return array();
		}
		$names = @scandir( $dir );
		if ( ! is_array( $names ) ) {
			return array();
		}
		$out = array();
		foreach ( $names as $n ) {
			if ( '.' === $n || '..' === $n ) {
				continue;
			}
			if ( is_dir( $dir . '/' . $n ) ) {
				$out[] = $n;
			}
		}
		sort( $out, SORT_STRING );
		return $out;
	}

	/**
	 * @param string $pack_dir 包目录。
	 * @return array<int,string> 图片文件名（按名称排序）。
	 */
	private static function list_files( $pack_dir ) {
		if ( ! is_dir( $pack_dir ) ) {
			return array();
		}
		$names = @scandir( $pack_dir );
		if ( ! is_array( $names ) ) {
			return array();
		}
		$out = array();
		foreach ( $names as $n ) {
			if ( '.' === $n || '..' === $n ) {
				continue;
			}
			if ( ! is_file( $pack_dir . '/' . $n ) ) {
				continue;
			}
			if ( preg_match( '/\.(jpe?g|png|gif|webp)$/i', $n ) ) {
				$out[] = $n;
			}
		}
		sort( $out, SORT_STRING );
		return $out;
	}
}
