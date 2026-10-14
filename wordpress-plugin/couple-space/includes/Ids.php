<?php
/**
 * id 生成：形如 u_xxx / c_xxx / m_xxx / p_xxx / e_xxx。
 *
 * 直接移植 server-php/src/util/Ids.php：base36 毫秒时间戳 + 进程内自增计数(3 位) + 随机后缀(4 位)。
 * 字符集固定 [0-9a-z]，满足表情 id 正则 ^e_[A-Za-z0-9]+$。
 *
 * @package CoupleSpace
 */

namespace CoupleSpace;

defined( 'ABSPATH' ) || exit;

/**
 * id 生成与校验。
 */
class Ids {

	/** base36 字母表。 */
	const ALPHABET = '0123456789abcdefghijklmnopqrstuvwxyz';

	/** @var int 进程内自增计数（36^3 = 46656） */
	private static $counter = -1;

	/**
	 * 生成带前缀的 id。
	 *
	 * @param string $prefix 前缀（u/c/m/p/e）。
	 * @return string
	 */
	public static function make_id( $prefix ) {
		if ( self::$counter < 0 ) {
			self::$counter = random_int( 0, 46655 );
		}
		self::$counter = ( self::$counter + 1 ) % 46656;
		$ts  = self::base36( (int) floor( microtime( true ) * 1000 ) );
		$seq = str_pad( self::base36( self::$counter ), 3, '0', STR_PAD_LEFT );
		return $prefix . '_' . $ts . $seq . self::random_suffix( 4 );
	}

	/** @return string */
	public static function new_user_id() {
		return self::make_id( 'u' );
	}

	/** @return string */
	public static function new_couple_id() {
		return self::make_id( 'c' );
	}

	/** @return string */
	public static function new_message_id() {
		return self::make_id( 'm' );
	}

	/** @return string */
	public static function new_pack_id() {
		return self::make_id( 'p' );
	}

	/** @return string */
	public static function new_emoji_id() {
		return self::make_id( 'e' );
	}

	/** @return string */
	public static function new_log_id() {
		return 'l_' . self::base36( (int) floor( microtime( true ) * 1000 ) ) . self::random_suffix( 6 );
	}

	/** @return string */
	public static function new_ai_usage_id() {
		return 'a_' . self::base36( (int) floor( microtime( true ) * 1000 ) ) . self::random_suffix( 6 );
	}

	/**
	 * 校验表情 id 格式（防路径穿越的第一道关卡）。
	 *
	 * @param mixed $id 待校验的 id。
	 * @return bool
	 */
	public static function is_valid_emoji_id( $id ) {
		return is_string( $id ) && strlen( $id ) <= 64 && 1 === preg_match( '/^e_[A-Za-z0-9]+$/', $id );
	}

	/**
	 * 生成 6 位邀请码（大写字母 + 数字，排除易混淆字符 0/O/1/I）。
	 *
	 * @return string
	 */
	public static function new_invite_code() {
		$chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
		$out   = '';
		$max   = strlen( $chars ) - 1;
		for ( $i = 0; $i < 6; $i++ ) {
			$out .= $chars[ random_int( 0, $max ) ];
		}
		return $out;
	}

	/**
	 * 生成随机密钥（WP-CLI 命令用）。
	 *
	 * @param int $bytes 字节数。
	 * @return string
	 */
	public static function random_secret( $bytes = 32 ) {
		return bin2hex( random_bytes( $bytes ) );
	}

	/**
	 * @param int $len 长度。
	 * @return string
	 */
	private static function random_suffix( $len ) {
		$out = '';
		$max = strlen( self::ALPHABET ) - 1;
		for ( $i = 0; $i < $len; $i++ ) {
			$out .= self::ALPHABET[ random_int( 0, $max ) ];
		}
		return $out;
	}

	/**
	 * @param int $n 正整数。
	 * @return string
	 */
	private static function base36( $n ) {
		if ( $n <= 0 ) {
			return '0';
		}
		$out = '';
		while ( $n > 0 ) {
			$out = self::ALPHABET[ $n % 36 ] . $out;
			$n   = intdiv( $n, 36 );
		}
		return $out;
	}
}
