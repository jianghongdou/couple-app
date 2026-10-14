<?php
/**
 * 手写 JWT（HS256）：base64url + hash_hmac + hash_equals 校验。
 *
 * 直接移植 server-php/src/Jwt.php，不依赖任何第三方库，兼容 PHP 8.1。
 * 用户 token 与后台 token 使用不同密钥、不同 typ，互不通用。
 *
 * @package CoupleSpace
 */

namespace CoupleSpace;

defined( 'ABSPATH' ) || exit;

/**
 * 极简 JWT 实现。
 */
class Jwt {

	/**
	 * base64url 编码。
	 *
	 * @param string $data 原始字节。
	 * @return string
	 */
	public static function base64_url_encode( $data ) {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	/**
	 * base64url 解码。
	 *
	 * @param string $data 编码后的字符串。
	 * @return string|null
	 */
	public static function base64_url_decode( $data ) {
		$s   = strtr( (string) $data, '-_', '+/' );
		$pad = strlen( $s ) % 4;
		if ( $pad > 0 ) {
			$s .= str_repeat( '=', 4 - $pad );
		}
		$out = base64_decode( $s, true );
		return ( false === $out ) ? null : $out;
	}

	/**
	 * 签发 token。
	 *
	 * @param array    $payload    载荷。
	 * @param string   $secret     密钥。
	 * @param int|null $ttl_seconds 有效期（秒）；null 表示不带 exp。
	 * @return string
	 */
	public static function sign( array $payload, $secret, $ttl_seconds = null ) {
		$now = time();
		if ( ! isset( $payload['iat'] ) ) {
			$payload['iat'] = $now;
		}
		if ( null !== $ttl_seconds ) {
			$payload['exp'] = $now + (int) $ttl_seconds;
		}
		$header = array(
			'alg' => 'HS256',
			'typ' => 'JWT',
		);
		$h = self::base64_url_encode( (string) wp_json_encode( $header, JSON_UNESCAPED_SLASHES ) );
		$p = self::base64_url_encode( (string) wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
		$signing_input = $h . '.' . $p;
		$sig           = self::base64_url_encode( hash_hmac( 'sha256', $signing_input, (string) $secret, true ) );
		return $signing_input . '.' . $sig;
	}

	/**
	 * 校验 token，返回载荷数组；失败返回 null。
	 *
	 * @param string $token  token。
	 * @param string $secret 密钥。
	 * @return array<string,mixed>|null
	 */
	public static function verify( $token, $secret ) {
		if ( ! is_string( $token ) || '' === $token ) {
			return null;
		}
		$parts = explode( '.', $token );
		if ( 3 !== count( $parts ) ) {
			return null;
		}
		list( $h, $p, $sig ) = $parts;
		$expected = self::base64_url_encode( hash_hmac( 'sha256', $h . '.' . $p, (string) $secret, true ) );
		if ( ! hash_equals( $expected, $sig ) ) {
			return null;
		}
		$payload_raw = self::base64_url_decode( $p );
		if ( null === $payload_raw ) {
			return null;
		}
		$payload = json_decode( $payload_raw, true );
		if ( ! is_array( $payload ) ) {
			return null;
		}
		// 头部算法必须是 HS256（拒绝 alg=none 之类的降级攻击）。
		$header_raw = self::base64_url_decode( $h );
		$header     = ( null === $header_raw ) ? null : json_decode( $header_raw, true );
		if ( ! is_array( $header ) || ! isset( $header['alg'] ) || 'HS256' !== strtoupper( (string) $header['alg'] ) ) {
			return null;
		}
		if ( isset( $payload['exp'] ) && time() >= (int) $payload['exp'] ) {
			return null;
		}
		return $payload;
	}
}
