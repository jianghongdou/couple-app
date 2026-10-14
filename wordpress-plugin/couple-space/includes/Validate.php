<?php
/**
 * 手写参数校验工具（不引第三方库）。
 *
 * 移植自 server-php/src/util/Validate.php，错误文案与错误码逐字保持一致：
 * 所有校验失败都抛 HttpException(400, invalid_input)。
 *
 * @package CoupleSpace
 */

namespace CoupleSpace;

defined( 'ABSPATH' ) || exit;

/**
 * 参数校验。
 */
class Validate {

	/**
	 * 抛出 400 invalid_input。
	 *
	 * @param string $message 提示。
	 * @throws HttpException 总是抛出。
	 */
	public static function fail( $message ) {
		throw new HttpException( 400, 'invalid_input', $message );
	}

	/**
	 * 抛出 404 not_found。
	 *
	 * @param string $message 提示。
	 * @throws HttpException 总是抛出。
	 */
	public static function fail_not_found( $message ) {
		throw new HttpException( 404, 'not_found', $message );
	}

	/**
	 * 抛出 403 forbidden。
	 *
	 * @param string $message 提示。
	 * @throws HttpException 总是抛出。
	 */
	public static function fail_forbidden( $message ) {
		throw new HttpException( 403, 'forbidden', $message );
	}

	/**
	 * 抛出 409 conflict。
	 *
	 * @param string $message 提示。
	 * @throws HttpException 总是抛出。
	 */
	public static function fail_conflict( $message ) {
		throw new HttpException( 409, 'conflict', $message );
	}

	/**
	 * 是否为 JSON 对象（stdClass；数组与 null 都不算）。
	 *
	 * @param mixed $v 值。
	 * @return bool
	 */
	public static function is_plain_object( $v ) {
		return $v instanceof \stdClass;
	}

	/**
	 * 按 Unicode 码点计算长度（emoji 算 1 个字符）。
	 *
	 * @param string $s 字符串。
	 * @return int
	 */
	public static function length( $s ) {
		return (int) mb_strlen( (string) $s, 'UTF-8' );
	}

	/**
	 * 必填字符串 + 长度/格式约束。
	 *
	 * @param mixed                $value 值。
	 * @param string               $field 字段名（用于提示）。
	 * @param array<string,mixed>  $opts  min / max / trim / pattern / patternHint。
	 * @return string
	 */
	public static function require_string( $value, $field, array $opts = array() ) {
		$min  = isset( $opts['min'] ) ? $opts['min'] : 1;
		$max  = isset( $opts['max'] ) ? $opts['max'] : null;
		$trim = ! empty( $opts['trim'] );
		if ( ! is_string( $value ) ) {
			self::fail( $field . ' 必须是字符串' );
		}
		$s   = $trim ? trim( $value ) : $value;
		$len = self::length( $s );
		if ( $len < $min ) {
			self::fail( $field . ' 长度不能少于 ' . $min . ' 个字符' );
		}
		if ( null !== $max && $len > $max ) {
			self::fail( $field . ' 长度不能超过 ' . $max . ' 个字符' );
		}
		if ( isset( $opts['pattern'] ) && ! preg_match( $opts['pattern'], $s ) ) {
			$hint = isset( $opts['patternHint'] ) ? '（' . $opts['patternHint'] . '）' : '';
			self::fail( $field . ' 格式不正确' . $hint );
		}
		return $s;
	}

	/**
	 * 可选字符串：null/缺失返回默认值。
	 *
	 * @param mixed               $value 值。
	 * @param string              $field 字段名。
	 * @param array<string,mixed> $opts  选项。
	 * @return string|null
	 */
	public static function optional_string( $value, $field, array $opts = array() ) {
		if ( null === $value ) {
			return isset( $opts['defaultValue'] ) ? $opts['defaultValue'] : null;
		}
		return self::require_string( $value, $field, $opts );
	}

	/**
	 * 校验并归一化「毫秒时间戳」整数。
	 *
	 * @param mixed               $value 值。
	 * @param string              $field 字段名。
	 * @param array<string,mixed> $opts  required / min / max / defaultValue。
	 * @return int|null
	 */
	public static function require_timestamp( $value, $field, array $opts = array() ) {
		$required = ! empty( $opts['required'] );
		$min      = isset( $opts['min'] ) ? $opts['min'] : 0;
		$max      = isset( $opts['max'] ) ? $opts['max'] : PHP_INT_MAX;
		$default  = isset( $opts['defaultValue'] ) ? $opts['defaultValue'] : null;
		if ( null === $value || '' === $value ) {
			if ( $required ) {
				self::fail( $field . ' 不能为空' );
			}
			return $default;
		}
		$n = self::to_number( $value );
		if ( null === $n || ! self::is_integer_number( $n ) ) {
			self::fail( $field . ' 必须是整数毫秒时间戳' );
		}
		$n = (int) $n;
		if ( $n < $min ) {
			self::fail( $field . ' 不能小于 ' . $min );
		}
		if ( $n > $max ) {
			self::fail( $field . ' 超出允许范围' );
		}
		return $n;
	}

	/**
	 * 可选布尔值。
	 *
	 * @param mixed  $value   值。
	 * @param string $field   字段名。
	 * @param mixed  $default 默认值。
	 * @return bool|null
	 */
	public static function optional_bool( $value, $field, $default = null ) {
		if ( null === $value ) {
			return $default;
		}
		if ( ! is_bool( $value ) ) {
			self::fail( $field . ' 必须是布尔值' );
		}
		return $value;
	}

	/**
	 * 必填整数。
	 *
	 * @param mixed               $value 值。
	 * @param string              $field 字段名。
	 * @param array<string,mixed> $opts  选项。
	 * @return int
	 */
	public static function require_int( $value, $field, array $opts = array() ) {
		$min = isset( $opts['min'] ) ? $opts['min'] : PHP_INT_MIN;
		$max = isset( $opts['max'] ) ? $opts['max'] : PHP_INT_MAX;
		if ( array_key_exists( 'defaultValue', $opts ) ) {
			$default = $opts['defaultValue'];
		} else {
			$default = null;
		}
		if ( null === $value || '' === $value ) {
			if ( null !== $default ) {
				return $default;
			}
			self::fail( $field . ' 不能为空' );
		}
		$n = self::to_number( $value );
		if ( null === $n || ! self::is_integer_number( $n ) ) {
			self::fail( $field . ' 必须是整数' );
		}
		$n = (int) $n;
		if ( $n < $min || $n > $max ) {
			self::fail( $field . ' 必须在 ' . $min . ' 到 ' . $max . ' 之间' );
		}
		return $n;
	}

	/**
	 * 查询参数形式的整数。
	 *
	 * @param mixed               $value 值。
	 * @param string              $field 字段名。
	 * @param array<string,mixed> $opts  选项。
	 * @return int|null
	 */
	public static function query_int( $value, $field, array $opts = array() ) {
		if ( null === $value || '' === $value ) {
			return isset( $opts['defaultValue'] ) ? $opts['defaultValue'] : null;
		}
		if ( is_array( $value ) ) {
			self::fail( $field . ' 必须是整数' );
		}
		$n = self::to_number( $value );
		if ( null === $n || ! self::is_integer_number( $n ) ) {
			self::fail( $field . ' 必须是整数' );
		}
		$n   = (int) $n;
		$min = isset( $opts['min'] ) ? $opts['min'] : PHP_INT_MIN;
		$max = isset( $opts['max'] ) ? $opts['max'] : PHP_INT_MAX;
		if ( $n < $min ) {
			self::fail( $field . ' 不能小于 ' . $min );
		}
		if ( $n > $max ) {
			self::fail( $field . ' 不能大于 ' . $max );
		}
		return $n;
	}

	/**
	 * 校验日期 YYYY-MM-DD。
	 *
	 * @param mixed  $value 值。
	 * @param string $field 字段名。
	 * @return string
	 */
	public static function require_date_string( $value, $field ) {
		if ( ! is_string( $value ) ) {
			self::fail( $field . ' 必须是 YYYY-MM-DD 字符串' );
		}
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			self::fail( $field . ' 格式必须是 YYYY-MM-DD' );
		}
		list( $y, $m, $d ) = array_map( 'intval', explode( '-', $value ) );
		if ( ! checkdate( $m, $d, $y ) ) {
			self::fail( $field . ' 不是有效日期' );
		}
		return $value;
	}

	/**
	 * 校验月份 YYYY-MM。
	 *
	 * @param mixed  $value 值。
	 * @param string $field 字段名。
	 * @return string
	 */
	public static function require_month_string( $value, $field ) {
		if ( ! is_string( $value ) ) {
			self::fail( $field . ' 必须是 YYYY-MM 字符串' );
		}
		if ( ! preg_match( '/^\d{4}-\d{2}$/', $value ) ) {
			self::fail( $field . ' 格式必须是 YYYY-MM' );
		}
		$m = (int) substr( $value, 5, 2 );
		if ( $m < 1 || $m > 12 ) {
			self::fail( $field . ' 月份必须在 01-12 之间' );
		}
		return $value;
	}

	/**
	 * 校验 JSON 对象（用于 payload / meta），带体积保护。
	 *
	 * @param mixed               $value 值。
	 * @param string              $field 字段名。
	 * @param array<string,mixed> $opts  allowNull / defaultValue。
	 * @return \stdClass|null
	 */
	public static function require_json_object( $value, $field, array $opts = array() ) {
		$allow_null = ! array_key_exists( 'allowNull', $opts ) || $opts['allowNull'];
		$default    = array_key_exists( 'defaultValue', $opts ) ? $opts['defaultValue'] : null;
		if ( null === $value ) {
			if ( $allow_null ) {
				return $default;
			}
			self::fail( $field . ' 不能为空' );
		}
		if ( ! self::is_plain_object( $value ) ) {
			self::fail( $field . ' 必须是 JSON 对象' );
		}
		$serialized = wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( false === $serialized ) {
			self::fail( $field . ' 无法序列化为 JSON' );
		}
		if ( strlen( $serialized ) > Config::limit( 'JSON_FIELD_MAX_BYTES' ) ) {
			self::fail( $field . ' 数据过大（上限 256KB）' );
		}
		return $value;
	}

	/**
	 * 昵称：1-32 字符。
	 *
	 * @param mixed  $value 值。
	 * @param string $field 字段名。
	 * @return string
	 */
	public static function validate_nickname( $value, $field = 'nickname' ) {
		return self::require_string(
			$value,
			$field,
			array(
				'min'  => 1,
				'max'  => 32,
				'trim' => true,
			)
		);
	}

	/**
	 * 头像 emoji：可选，单个 emoji。
	 *
	 * @param mixed $value 值。
	 * @return string|null
	 */
	public static function validate_avatar_emoji( $value ) {
		if ( null === $value || '' === $value ) {
			return null;
		}
		if ( ! is_string( $value ) ) {
			self::fail( 'avatarEmoji 必须是字符串' );
		}
		$chars = preg_split( '//u', $value, -1, PREG_SPLIT_NO_EMPTY );
		$count = is_array( $chars ) ? count( $chars ) : 0;
		if ( 0 === $count || $count > 4 ) {
			self::fail( 'avatarEmoji 必须是单个 emoji' );
		}
		if ( strlen( $value ) > 16 ) {
			self::fail( 'avatarEmoji 过长' );
		}
		return $value;
	}

	/**
	 * username：3-32 位，只允许 a-zA-Z0-9_。
	 *
	 * @param mixed $value 值。
	 * @return string
	 */
	public static function validate_username( $value ) {
		return self::require_string(
			$value,
			'username',
			array(
				'min'         => 3,
				'max'         => 32,
				'pattern'     => '/^[a-zA-Z0-9_]+$/',
				'patternHint' => '只允许字母、数字和下划线',
			)
		);
	}

	/**
	 * password：6-64 位。
	 *
	 * @param mixed  $value 值。
	 * @param string $field 字段名。
	 * @return string
	 */
	public static function validate_password( $value, $field = 'password' ) {
		return self::require_string(
			$value,
			$field,
			array(
				'min' => Config::limit( 'PASSWORD_MIN' ),
				'max' => Config::limit( 'PASSWORD_MAX' ),
			)
		);
	}

	/**
	 * 校验表情 mime 是否在白名单内。
	 *
	 * @param mixed         $mime      值。
	 * @param array<string> $whitelist 白名单。
	 * @return string
	 */
	public static function validate_mime( $mime, array $whitelist ) {
		if ( ! is_string( $mime ) ) {
			self::fail( 'mime 必须是字符串' );
		}
		if ( ! in_array( $mime, $whitelist, true ) ) {
			self::fail( 'mime 不在白名单内，仅支持：' . implode( '、', $whitelist ) );
		}
		return $mime;
	}

	// ------------------------------------------------------------------
	// 内部工具
	// ------------------------------------------------------------------

	/**
	 * 转数字（接受 int/float/数字字符串）。
	 *
	 * @param mixed $v 值。
	 * @return int|float|null
	 */
	private static function to_number( $v ) {
		if ( is_int( $v ) || is_float( $v ) ) {
			return $v;
		}
		if ( is_string( $v ) ) {
			$s = trim( $v );
			if ( '' === $s || ! preg_match( '/^[+-]?(\d+(\.\d+)?|\.\d+)([eE][+-]?\d+)?$/', $s ) ) {
				return null;
			}
			return $s + 0;
		}
		return null;
	}

	/**
	 * @param int|float $n 数字。
	 * @return bool
	 */
	private static function is_integer_number( $n ) {
		if ( is_int( $n ) ) {
			return true;
		}
		return is_float( $n ) && floor( $n ) === $n && is_finite( $n );
	}
}
