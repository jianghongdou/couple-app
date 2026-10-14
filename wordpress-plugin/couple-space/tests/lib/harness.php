<?php
/**
 * 端到端测试脚手架：HTTP 客户端 + WordPress/数据库辅助 + 断言统计。
 *
 * @package CoupleSpace
 */

// phpcs:disable

/**
 * 测试脚手架。
 */
class E2E_Harness {

	/** @var string */
	private $base;

	/** @var string */
	private $wp_dir;

	/** @var string */
	private $php_bin;

	/** @var string */
	private $admin_password;

	/** @var PDO|null */
	private $pdo = null;

	/** @var string */
	private $prefix = 'wp_';

	/** @var array<int,array{ok:bool,label:string,detail:string}> */
	private $results = array();

	/** @var int */
	private $failed = 0;

	/** @var int */
	private $passed = 0;

	/** @var array<int,string> */
	private $lines = array();

	/** @var string */
	private $last_raw_body = '';

	/** @var array<string,string> */
	private $last_content_type = array();

	/** @var float */
	private $started;

	/**
	 * @param string $base          站点地址。
	 * @param string $wp_dir        WordPress 目录。
	 * @param string $php_bin       PHP 可执行文件。
	 * @param string $admin_password 测试用后台密码。
	 */
	public function __construct( $base, $wp_dir, $php_bin, $admin_password ) {
		$this->base           = rtrim( $base, '/' );
		$this->wp_dir         = $wp_dir;
		$this->php_bin        = $php_bin;
		$this->admin_password = $admin_password;
		$this->started        = microtime( true );
		$this->load_wp_config();
	}

	// ------------------------------------------------------------------
	// WordPress / 数据库
	// ------------------------------------------------------------------

	/** 从 wp-config.php 里读数据库常量。 */
	private function load_wp_config() {
		$file = $this->wp_dir . '/wp-config.php';
		if ( ! is_file( $file ) ) {
			return;
		}
		$src = (string) file_get_contents( $file );
		$get = static function ( $name ) use ( $src ) {
			if ( preg_match( "/define\\(\\s*'" . $name . "'\\s*,\\s*'([^']*)'\\s*\\)/", $src, $m ) ) {
				return $m[1];
			}
			return '';
		};
		if ( preg_match( '/\$table_prefix\s*=\s*\'([^\']*)\'/', $src, $m ) ) {
			$this->prefix = $m[1];
		}

		$host = $get( 'DB_HOST' );
		$port = 3306;
		if ( false !== strpos( $host, ':' ) ) {
			list( $host, $port ) = explode( ':', $host, 2 );
		}
		$dsn = 'mysql:host=' . $host . ';port=' . (int) $port . ';dbname=' . $get( 'DB_NAME' ) . ';charset=utf8mb4';
		try {
			$this->pdo = new PDO( $dsn, $get( 'DB_USER' ), $get( 'DB_PASSWORD' ), array( PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION ) );
		} catch ( \Throwable $e ) {
			$this->pdo = null;
		}
	}

	/** @return string 表前缀。 */
	public function prefix() {
		return $this->prefix;
	}

	/** @return PDO|null 暴露 PDO（供调试脚本使用）。 */
	public function pdo_public() {
		return $this->pdo;
	}

	/**
	 * 自建表真实表名。
	 *
	 * @param string $name 逻辑表名。
	 * @return string
	 */
	public function table( $name ) {
		return $this->prefix . 'couple_' . $name;
	}

	/**
	 * 执行 SQL 并返回第一列。
	 *
	 * @param string $sql SQL。
	 * @return mixed
	 */
	public function db_scalar( $sql ) {
		if ( ! $this->pdo ) {
			return null;
		}
		$stmt = $this->pdo->query( $sql );
		if ( false === $stmt ) {
			return null;
		}
		$v = $stmt->fetchColumn();
		return false === $v ? null : $v;
	}

	/**
	 * 执行写 SQL。
	 *
	 * @param string             $sql    SQL。
	 * @param array<int,mixed>   $params 参数。
	 * @return int
	 */
	public function db_exec( $sql, array $params = array() ) {
		if ( ! $this->pdo ) {
			return 0;
		}
		$stmt = $this->pdo->prepare( $sql );
		$stmt->execute( $params );
		return $stmt->rowCount();
	}

	/**
	 * 在 WordPress 环境里执行一段 PHP（独立进程，避免污染本进程）。
	 *
	 * @param string $code PHP 代码（不要带 <?php）。
	 * @return array{out:string,code:int}
	 */
	public function wp_eval( $code ) {
		$file = __DIR__ . '/.wp-eval-' . getmypid() . '.php';
		$body = "<?php\nrequire '" . str_replace( "'", "\\'", $this->wp_dir ) . "/wp-load.php';\n" . $code . "\n";
		file_put_contents( $file, $body );
		$cmd = escapeshellarg( $this->php_bin ) . ' ' . escapeshellarg( $file ) . ' 2>&1';
		$out = array();
		$rc  = 0;
		exec( $cmd, $out, $rc );
		@unlink( $file );
		return array(
			'out'  => implode( "\n", $out ),
			'code' => $rc,
		);
	}

	/**
	 * 与 wp_eval 相同（语义化命名：跑一段 WP-CLI 等价逻辑）。
	 *
	 * @param string $code PHP 代码。
	 * @return array{out:string,code:int}
	 */
	public function wpcli_eval( $code ) {
		return $this->wp_eval( $code );
	}

	// ------------------------------------------------------------------
	// HTTP
	// ------------------------------------------------------------------

	/**
	 * 发起一次请求。
	 *
	 * @param string                   $method  HTTP 方法。
	 * @param string                   $path    路径。
	 * @param array<string,mixed>|null $body    请求体（数组会 JSON 编码）。
	 * @param array<string,string>     $headers 额外请求头。
	 * @return array{status:int,headers:array<string,string>,body:string,json:mixed,content_type:string,cache_control:string,raw:array}
	 */
	public function req( $method, $path, $body = null, array $headers = array() ) {
		$json = null;
		if ( null !== $body ) {
			$json = json_encode( $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}
		return $this->req_raw( $method, $path, $json, $headers, null !== $body );
	}

	/**
	 * 发起一次请求并返回原始响应。
	 *
	 * @param string               $method       HTTP 方法。
	 * @param string               $path         路径。
	 * @param string|null          $raw_body     原始请求体。
	 * @param array<string,string> $headers      请求头。
	 * @param bool                 $json_content 是否设置 Content-Type: application/json。
	 * @return array{status:int,headers:array<string,string>,body:string,json:mixed,content_type:string,cache_control:string}
	 */
	public function req_raw( $method, $path, $raw_body = null, array $headers = array(), $json_content = false ) {
		$url = $this->base . $path;
		$ch  = curl_init( $url );
		$hdr = array();
		$ctype = 'application/json';
		foreach ( $headers as $k => $v ) {
			$hdr[] = $k . ': ' . $v;
			if ( 0 === strcasecmp( $k, 'content-type' ) ) {
				$ctype = $v;
			}
		}
		if ( $json_content && ! isset( $headers['Content-Type'] ) ) {
			$hdr[] = 'Content-Type: application/json';
			$ctype = 'application/json';
		}
		$hdr[] = 'Accept: application/json';

		curl_setopt_array(
			$ch,
			array(
				CURLOPT_CUSTOMREQUEST  => strtoupper( $method ),
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_HEADER         => true,
				CURLOPT_FOLLOWLOCATION => false,
				CURLOPT_CONNECTTIMEOUT => 10,
				CURLOPT_TIMEOUT        => 60,
				CURLOPT_HTTPHEADER     => $hdr,
			)
		);
		if ( null !== $raw_body ) {
			curl_setopt( $ch, CURLOPT_POSTFIELDS, $raw_body );
		}
		$response = curl_exec( $ch );
		$err      = curl_error( $ch );
		$status   = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
		$hsize    = (int) curl_getinfo( $ch, CURLINFO_HEADER_SIZE );
		curl_close( $ch );

		if ( false === $response ) {
			return array(
				'status'        => 0,
				'headers'       => array(),
				'body'          => 'curl error: ' . $err,
				'json'          => null,
				'content_type'  => '',
				'cache_control' => '',
			);
		}

		$raw_headers = substr( $response, 0, $hsize );
		$resp_body   = substr( $response, $hsize );
		$parsed      = array();
		foreach ( explode( "\r\n", $raw_headers ) as $line ) {
			$pos = strpos( $line, ':' );
			if ( false === $pos ) {
				continue;
			}
			$k = strtolower( trim( substr( $line, 0, $pos ) ) );
			$v = trim( substr( $line, $pos + 1 ) );
			// 重定向/100-continue 会产生多段响应头，保留最后一段。
			$parsed[ $k ] = $v;
		}

		$this->last_raw_body = $resp_body;
		if ( isset( $parsed['content-type'] ) ) {
			$this->last_content_type[ $path ] = $parsed['content-type'];
		}

		return array(
			'status'        => $status,
			'headers'       => $parsed,
			'body'          => $resp_body,
			'json'          => json_decode( $resp_body, true ),
			'content_type'  => isset( $parsed['content-type'] ) ? $parsed['content-type'] : '',
			'cache_control' => isset( $parsed['cache-control'] ) ? $parsed['cache-control'] : '',
		);
	}

	/** @return string 最近一次响应的原始 body。 */
	public function raw_body_of_last() {
		return $this->last_raw_body;
	}

	/**
	 * 从响应里按点号路径取字段（如 user.coupleId）。
	 *
	 * @param array<string,mixed> $resp 响应。
	 * @param string              $path 路径。
	 * @param mixed               $default 默认值。
	 * @return mixed
	 */
	public function get( $resp, $path, $default = null ) {
		$cur = isset( $resp['json'] ) ? $resp['json'] : null;
		foreach ( explode( '.', $path ) as $seg ) {
			if ( is_array( $cur ) && array_key_exists( $seg, $cur ) ) {
				$cur = $cur[ $seg ];
				continue;
			}
			return $default;
		}
		return $cur;
	}

	/**
	 * 响应摘要（用于报告）。
	 *
	 * @param array<string,mixed> $resp 响应。
	 * @return string
	 */
	public function brief( $resp ) {
		$body = isset( $resp['body'] ) ? (string) $resp['body'] : '';
		if ( strlen( $body ) > 180 ) {
			$body = substr( $body, 0, 180 ) . '…';
		}
		return 'HTTP ' . $resp['status'] . ' ' . $body;
	}

	// ------------------------------------------------------------------
	// 断言与报告
	// ------------------------------------------------------------------

	/**
	 * 输出一个小节标题。
	 *
	 * @param string $title 标题。
	 */
	public function section( $title ) {
		$this->lines[] = '';
		$this->lines[] = '── ' . $title . ' ' . str_repeat( '─', max( 4, 60 - mb_strlen( $title, 'UTF-8' ) ) );
		echo "\n\033[1m── {$title} ──\033[0m\n";
	}

	/**
	 * 输出一条信息。
	 *
	 * @param string $text 文本。
	 */
	public function info( $text ) {
		$this->lines[] = '   · ' . $text;
		echo "   · {$text}\n";
	}

	/**
	 * 一条断言。
	 *
	 * @param bool   $cond   是否通过。
	 * @param string $label  用例名。
	 * @param string $detail 细节。
	 */
	public function ok( $cond, $label, $detail = '' ) {
		$cond = (bool) $cond;
		if ( $cond ) {
			$this->passed++;
		} else {
			$this->failed++;
		}
		$mark          = $cond ? '✅' : '❌';
		$this->lines[] = $mark . ' ' . $label . ( '' !== $detail ? ( "\n      " . str_replace( "\n", "\n      ", $detail ) ) : '' );
		echo $mark . ' ' . $label . ( '' !== $detail ? ( '  [' . str_replace( "\n", ' ', $detail ) . ']' ) : '' ) . "\n";
	}

	/** @return int 失败数。 */
	public function failed() {
		return $this->failed;
	}

	/** @return int 通过数。 */
	public function passed() {
		return $this->passed;
	}

	/**
	 * 写出报告。
	 *
	 * @param string $report_file 报告文件路径。
	 */
	public function finish( $report_file ) {
		$total   = $this->passed + $this->failed;
		$elapsed = round( microtime( true ) - $this->started, 2 );
		$head    = array(
			'情侣空间 WordPress 插件 · 端到端测试报告',
			'生成时间：' . date( 'Y-m-d H:i:s' ),
			'站点地址：' . $this->base,
			'PHP：' . PHP_VERSION,
			'耗时：' . $elapsed . ' 秒',
			'',
			'═══════════════════════════════════════════════════════════════',
		);
		$foot = array(
			'═══════════════════════════════════════════════════════════════',
			'',
			'通过：' . $this->passed . ' 项',
			'失败：' . $this->failed . ' 项',
			'合计：' . $total . ' 项',
			'结果：' . ( 0 === $this->failed ? '✅ 全部通过' : '❌ 存在失败用例' ),
			'',
		);
		file_put_contents( $report_file, implode( "\n", array_merge( $head, $this->lines, $foot ) ) );

		echo "\n" . str_repeat( '=', 62 ) . "\n";
		echo '通过：' . $this->passed . " 项\n";
		echo '失败：' . $this->failed . " 项\n";
		echo '合计：' . $total . " 项\n";
		echo '报告：' . $report_file . "\n";
		if ( 0 === $this->failed ) {
			echo "结果：✅ 全部通过\n";
		} else {
			echo "结果：❌ 存在失败用例\n";
		}
	}

	// ------------------------------------------------------------------
	// 工具
	// ------------------------------------------------------------------

	/**
	 * 手写一个最小的合法 PNG（带正确的 IHDR/IDAT/IEND 与 CRC）。
	 *
	 * @param int $w 宽。
	 * @param int $h 高。
	 * @return string 二进制 PNG 内容。
	 */
	public static function make_png( $w = 2, $h = 2 ) {
		$raw = '';
		for ( $y = 0; $y < $h; $y++ ) {
			$raw .= "\x00"; // filter type 0
			for ( $x = 0; $x < $w; $x++ ) {
				$raw .= chr( 255 ) . chr( 120 ) . chr( 160 ); // RGB 粉色
			}
		}
		$ihdr = pack( 'NNCCCCC', $w, $h, 8, 2, 0, 0, 0 );
		return "\x89PNG\r\n\x1a\n"
			. self::png_chunk( 'IHDR', $ihdr )
			. self::png_chunk( 'IDAT', gzcompress( $raw, 9 ) )
			. self::png_chunk( 'IEND', '' );
	}

	/**
	 * @param string $type 分块类型。
	 * @param string $data 数据。
	 * @return string
	 */
	private static function png_chunk( $type, $data ) {
		$body = $type . $data;
		return pack( 'N', strlen( $data ) ) . $body . pack( 'N', crc32( $body ) );
	}
}
