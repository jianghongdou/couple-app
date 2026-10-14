<?php
/**
 * 表情包接口（API.md §6）。
 *
 * 移植自 server-php/src/routes/EmojiRoutes.php。安全要点完全保留：
 *   1. 图片二进制存磁盘，文件名只由服务端生成的 id 决定，绝不使用用户传入的路径。
 *   2. blob 接口校验 id 格式 ^e_[A-Za-z0-9]+$（防路径穿越）。
 *   3. 上传时校验体积 + magic bytes，不信任客户端声明的 mime。
 *   4. 与 server-php 的差异仅在于存放目录：wp-content/uploads/couple-emoji/。
 *
 * @package CoupleSpace
 */

namespace CoupleSpace\Routes;

use CoupleSpace\Auth;
use CoupleSpace\Base;
use CoupleSpace\Config;
use CoupleSpace\Db;
use CoupleSpace\EmojiStore;
use CoupleSpace\Http;
use CoupleSpace\HttpException;
use CoupleSpace\Ids;
use CoupleSpace\Image;
use CoupleSpace\Log;
use CoupleSpace\Settings;
use CoupleSpace\Validate;

defined( 'ABSPATH' ) || exit;

/**
 * 表情包接口。
 */
class EmojiRoutes extends Base {

	/**
	 * 注册路由。
	 *
	 * @param object $r 路由器。
	 */
	public static function register( $r ) {
		// GET /api/emoji/packs —— 同一情侣空间内双方都能看到对方的包。
		$r->get(
			'/api/emoji/packs',
			static function () {
				$me        = Auth::require_user();
				$couple_id = Auth::require_couple( $me['user'] );
				$packs     = Db::list_packs_for_couple( $couple_id );
				return self::ok( array( 'packs' => $packs ) );
			}
		);

		// POST /api/emoji/packs。
		$r->post(
			'/api/emoji/packs',
			static function () {
				$me        = Auth::require_user();
				$couple_id = Auth::require_couple( $me['user'] );
				$body      = Http::json_body();
				$name      = Validate::require_string(
					Http::field( $body, 'name' ),
					'name',
					array(
						'min'  => 1,
						'max'  => 32,
						'trim' => true,
					)
				);

				// 每个情侣空间最多 N 个自建包（可按后台设置调整）。
				$max_packs = Settings::max_emoji_packs();
				$used      = Db::count_own_packs( $couple_id );
				if ( $used >= $max_packs ) {
					throw new HttpException( 409, 'conflict', '表情包数量已达上限（' . $max_packs . ' 个）' );
				}

				$row  = Db::create_pack(
					array(
						'name'     => $name,
						'ownerId'  => $me['user']['id'],
						'coupleId' => $couple_id,
						'isPublic' => false,
					)
				);
				$pack = Db::get_pack_with_items( $row['id'] );
				Log::info( '创建表情包', array( 'packId' => $row['id'], 'userId' => $me['user']['id'] ) );
				return self::ok( array( 'pack' => $pack ) );
			}
		);

		// DELETE /api/emoji/packs/:id —— 只能删自己创建的包（system 包不可删）。
		$r->delete(
			'/api/emoji/packs/:id',
			static function ( $params ) {
				$me        = Auth::require_user();
				$couple_id = Auth::require_couple( $me['user'] );
				$id        = Validate::require_string( $params['id'], 'id', array( 'min' => 1, 'max' => 64 ) );
				$pack      = Db::get_pack_row( $id );

				if ( ! $pack || ( empty( $pack['is_public'] ) && $pack['couple_id'] !== $couple_id ) ) {
					throw new HttpException( 404, 'not_found', '表情包不存在' );
				}
				if ( ! empty( $pack['is_system'] ) ) {
					throw new HttpException( 403, 'forbidden', '系统表情包不可删除' );
				}
				if ( ! empty( $pack['is_public'] ) ) {
					throw new HttpException( 403, 'forbidden', '公共表情包不可删除' );
				}
				if ( $pack['owner_id'] !== $me['user']['id'] ) {
					throw new HttpException( 403, 'forbidden', '只能删除自己创建的表情包' );
				}

				// 先取出条目行，删库后清理磁盘文件。
				$full     = Db::get_pack_with_items( $id );
				$item_rows = array();
				if ( $full ) {
					foreach ( $full['items'] as $it ) {
						$found = Db::get_emoji_item( $it['id'] );
						if ( $found ) {
							$item_rows[] = $found['row'];
						}
					}
				}
				Db::delete_pack( $id );
				foreach ( $item_rows as $row ) {
					EmojiStore::delete_file( $row );
				}

				Log::info(
					'删除表情包',
					array(
						'packId' => $id,
						'userId' => $me['user']['id'],
						'items'  => count( $item_rows ),
					)
				);
				return self::ok( array( 'ok' => true ) );
			}
		);

		// POST /api/emoji/items —— base64 上传。
		$r->post(
			'/api/emoji/items',
			static function () {
				$me        = Auth::require_user();
				$couple_id = Auth::require_couple( $me['user'] );
				$body      = Http::json_body();

				$pack_id      = Validate::require_string( Http::field( $body, 'packId' ), 'packId', array( 'min' => 1, 'max' => 64 ) );
				$declared     = Validate::validate_mime( Http::field( $body, 'mime' ), Config::EMOJI_MIMES );
				$name_raw     = Http::field( $body, 'name' );
				$name         = ( null === $name_raw )
					? ''
					: Validate::require_string(
						$name_raw,
						'name',
						array(
							'min'  => 0,
							'max'  => 32,
							'trim' => true,
						)
					);
				$data_base64  = Validate::require_string( Http::field( $body, 'dataBase64' ), 'dataBase64', array( 'min' => 1 ) );

				// 归属校验：包必须属于本情侣空间。
				$pack = Db::get_pack_row( $pack_id );
				if ( ! $pack || ( empty( $pack['is_public'] ) && $pack['couple_id'] !== $couple_id ) ) {
					throw new HttpException( 404, 'not_found', '表情包不存在' );
				}
				if ( ! empty( $pack['is_public'] ) || ! empty( $pack['is_system'] ) ) {
					throw new HttpException( 403, 'forbidden', '公共/系统表情包不可上传' );
				}

				// 每个包上限 200 张。
				$max_items = Config::limit( 'EMOJI_MAX_ITEMS_PER_PACK' );
				if ( Db::count_items_in_pack( $pack_id ) >= $max_items ) {
					throw new HttpException( 409, 'conflict', '该表情包已达上限（' . $max_items . ' 张）' );
				}

				// base64 长度预检，避免先解码出巨大缓冲。
				$max_base64 = (int) ceil( Config::limit( 'EMOJI_MAX_BYTES' ) / 3 ) * 4 + 16;
				if ( strlen( $data_base64 ) > $max_base64 ) {
					Validate::fail( '图片体积超过上限（解码后最多 1MB）' );
				}

				// 去掉可能存在的 data URL 前缀与空白字符。
				$raw = trim( $data_base64 );
				if ( preg_match( '#^data:[^;]+;base64,(.*)$#s', $raw, $m ) ) {
					$raw = $m[1];
				}
				$raw = preg_replace( '/\s+/', '', $raw );
				if ( null === $raw || ! preg_match( '#^[A-Za-z0-9+/]+={0,2}$#', $raw ) ) {
					Validate::fail( 'dataBase64 不是合法的 base64 数据' );
				}

				$buf = base64_decode( $raw, true );
				if ( false === $buf ) {
					Validate::fail( 'dataBase64 解码失败' );
				}
				if ( '' === $buf ) {
					Validate::fail( '图片内容为空' );
				}
				if ( strlen( $buf ) > Config::limit( 'EMOJI_MAX_BYTES' ) ) {
					Validate::fail( '图片体积超过上限（解码后最多 1MB）' );
				}

				// magic bytes 校验：不信任客户端声明的 mime。
				$sniffed = Image::sniff_mime( $buf );
				if ( null === $sniffed ) {
					Validate::fail( '文件内容不是受支持的图片格式（仅支持 JPEG / PNG / GIF / WEBP）' );
				}
				if ( $sniffed !== $declared ) {
					Validate::fail( '声明的 mime（' . $declared . '）与图片实际格式（' . $sniffed . '）不一致' );
				}

				// 先落盘再写数据库；写库失败则回滚文件。
				// 文件名完全由服务端生成的 id 决定（<uploads>/couple-emoji/<id>.bin），不含用户输入。
				$item_id   = Ids::new_emoji_id();
				$file_path = EmojiStore::local_path( $item_id );
				EmojiStore::ensure_dirs();
				if ( false === $file_path || false === @file_put_contents( $file_path, $buf ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					throw new HttpException( 500, 'server_error', '图片保存失败，请检查 uploads 目录权限' );
				}

				try {
					Db::insert_emoji_item(
						array(
							'id'       => $item_id,
							'packId'   => $pack_id,
							'ownerId'  => $me['user']['id'],
							'coupleId' => $couple_id,
							'mime'     => $sniffed,
							'size'     => strlen( $buf ),
							'name'     => $name,
						)
					);
				} catch ( \Throwable $e ) {
					if ( is_file( $file_path ) ) {
						wp_delete_file( $file_path );
					}
					throw $e;
				}

				Log::info(
					'上传表情',
					array(
						'itemId' => $item_id,
						'packId' => $pack_id,
						'size'   => strlen( $buf ),
						'mime'   => $sniffed,
						'userId' => $me['user']['id'],
					)
				);

				$found = Db::get_emoji_item( $item_id );
				return self::ok( array( 'item' => $found['item'] ) );
			}
		);

		// DELETE /api/emoji/items/:id —— 删除单张表情（只能删自己上传的）。
		$r->delete(
			'/api/emoji/items/:id',
			static function ( $params ) {
				$me = Auth::require_user();
				$id = isset( $params['id'] ) ? (string) $params['id'] : '';

				// 防路径穿越：严格校验 id 格式，绝不把用户输入拼进文件路径。
				if ( ! Ids::is_valid_emoji_id( $id ) ) {
					throw new HttpException( 400, 'invalid_input', '表情 id 格式不正确（应形如 e_xxxxx）' );
				}

				$found = Db::get_emoji_item( $id );
				if ( ! $found ) {
					throw new HttpException( 404, 'not_found', '表情不存在' );
				}
				$row = $found['row'];

				// 公共表情包里的表情不允许删除。
				$pack = Db::get_pack_row( $row['pack_id'] );
				if ( $pack && ! empty( $pack['is_public'] ) ) {
					throw new HttpException( 403, 'forbidden', '公共表情包中的表情不可删除' );
				}
				// 只能删除本人上传的表情。
				if ( $row['owner_id'] !== $me['user']['id'] ) {
					throw new HttpException( 403, 'forbidden', '只能删除自己上传的表情' );
				}

				Db::delete_emoji_item( $id );
				EmojiStore::delete_file( $row );

				Log::info(
					'删除表情',
					array(
						'itemId' => $id,
						'packId' => $row['pack_id'],
						'userId' => $me['user']['id'],
					)
				);
				return self::ok( array( 'ok' => true ) );
			}
		);

		// GET /api/emoji/blob/:id —— 图片二进制。
		$r->get(
			'/api/emoji/blob/:id',
			static function ( $params ) {
				// 鉴权：Authorization 头 或 ?token=（供 iOS AsyncImage 使用）。
				$token = Http::extract_token();
				if ( null === $token ) {
					throw new HttpException( 401, 'unauthorized', '缺少登录凭证' );
				}
				$found = Auth::verify_user_token( $token );
				if ( ! $found ) {
					throw new HttpException( 401, 'unauthorized', '登录已过期，请重新登录' );
				}
				if ( ! empty( $found['row']['disabled'] ) ) {
					throw new HttpException( 403, 'forbidden', '账号已被禁用' );
				}
				$user = $found['user'];

				$id = isset( $params['id'] ) ? (string) $params['id'] : '';
				// 防路径穿越第一道关卡：严格校验 id 格式。
				if ( ! Ids::is_valid_emoji_id( $id ) ) {
					throw new HttpException( 400, 'invalid_input', '表情 id 格式不正确' );
				}

				$item = Db::get_emoji_item( $id );
				if ( ! $item ) {
					throw new HttpException( 404, 'not_found', '表情不存在' );
				}
				$row  = $item['row'];
				$meta = $item['item'];

				// 只能访问同情侣空间或公共表情。
				$pack      = Db::get_pack_row( $row['pack_id'] );
				$is_public = ( null !== $pack && 1 === (int) $pack['is_public'] );
				if ( ! $is_public && ( empty( $row['couple_id'] ) || $row['couple_id'] !== $user['coupleId'] ) ) {
					throw new HttpException( 403, 'forbidden', '无权访问该表情' );
				}

				$resolved = EmojiStore::resolve( $row );
				if ( null === $resolved ) {
					Log::error( '表情文件路径解析失败，已拒绝', array( 'id' => $id ) );
					throw new HttpException( 400, 'invalid_input', '表情 id 格式不正确' );
				}
				if ( ! is_file( $resolved ) ) {
					throw new HttpException( 404, 'not_found', '表情文件不存在' );
				}

				$buf = @file_get_contents( $resolved ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				if ( false === $buf ) {
					throw new HttpException( 500, 'server_error', '表情文件读取失败' );
				}

				return self::raw(
					$buf,
					200,
					array(
						'Content-Type'   => (string) $meta['mime'],
						// API.md §6 要求的长缓存。
						'Cache-Control'  => 'public, max-age=31536000, immutable',
						'X-Content-Type-Options' => 'nosniff',
					)
				);
			}
		);
	}
}
