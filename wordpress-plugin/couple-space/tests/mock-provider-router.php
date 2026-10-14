<?php
/**
 * AI 端到端测试用的「本地 mock 供应商」路由器（php -S 的内置路由）。
 *
 * 用法（由 tests/ai-e2e.php 自动启动）：
 *   php -S 127.0.0.1:<port> tests/mock-provider-router.php
 *
 * 环境变量：
 *   MOCK_LOG  —— 收到的请求记录（JSON Lines，每行一个请求；**不含 Key 明文**）
 *   MOCK_MODE —— normal（默认）| authfail | slow
 *
 * 端点：
 *   POST /v1/chat/completions            OpenAI 形状（含 usage）
 *   POST /v1/messages                    Anthropic 形状
 *   POST /v1beta/models/*:generateContent Gemini 形状
 *   任意路径 + ?mock=401                 返回 401（鉴权失败）
 *   任意路径 + ?mock=slow                先记录请求，再 sleep 35 秒（测 30 秒超时）
 *   任意路径 + ?mock=empty               返回空内容（测「（AI 没有返回内容）」）
 *   GET  /__log                          返回记录条数（探活用）
 *
 * @package CoupleSpace
 */

// phpcs:disable

$log_file = getenv( 'MOCK_LOG' );
if ( ! is_string( $log_file ) || '' === $log_file ) {
	$log_file = sys_get_temp_dir() . '/couple-ai-mock.log';
}

$path = parse_url( isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH );
$path = is_string( $path ) ? $path : '/';
$mock = isset( $_GET['mock'] ) ? (string) $_GET['mock'] : '';

// 也支持路径形式的 mock 开关（例如 baseUrl=http://127.0.0.1:8899/mock401）：
// 这样能顺带验证「Base URL 带查询串时路径拼接是否正确」由产品代码决定，而不是被测试迁就。
if ( '' === $mock && preg_match( '#/mock(401|slow|empty)(?:/|$)#', $path, $m ) ) {
	$mock = $m[1];
}

$raw = file_get_contents( 'php://input' );
if ( false === $raw ) {
	$raw = '';
}

// 探活端点：**不写请求记录**，否则「最后一次请求」会被探活本身污染。
if ( '/__log' === $path ) {
	header( 'Content-Type: application/json; charset=utf-8' );
	$lines = is_file( $log_file ) ? file( $log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) : array();
	// 同时回传**本进程实际使用的记录文件路径**：测试脚本据此读取请求记录，
	// 即使 mock 是被别人手工起起来的（没带 MOCK_LOG），也不会读错文件。
	echo json_encode(
		array(
			'ok'      => true,
			'count'   => is_array( $lines ) ? count( $lines ) : 0,
			'logFile' => str_replace( '\\', '/', (string) $log_file ),
			'pid'     => function_exists( 'getmypid' ) ? (int) getmypid() : 0,
		)
	);
	return;
}

// 记录请求（便于断言「上游收到了哪些 messages」）。
// 绝不记录 Authorization / x-api-key 的明文，只记录长度与哈希前缀。
$auth_len = 0;
$auth_sha = '';
foreach ( array( 'HTTP_AUTHORIZATION', 'HTTP_X_API_KEY' ) as $k ) {
	if ( isset( $_SERVER[ $k ] ) && '' !== (string) $_SERVER[ $k ] ) {
		$v        = (string) $_SERVER[ $k ];
		$auth_len = strlen( $v );
		$auth_sha = substr( hash( 'sha256', $v ), 0, 12 );
	}
}

$entry = array(
	't'        => microtime( true ),
	'method'   => isset( $_SERVER['REQUEST_METHOD'] ) ? (string) $_SERVER['REQUEST_METHOD'] : 'GET',
	'path'     => $path,
	'query'    => isset( $_SERVER['QUERY_STRING'] ) ? (string) $_SERVER['QUERY_STRING'] : '',
	'mock'     => $mock,
	'authLen'  => $auth_len,
	'authSha'  => $auth_sha,
	'body'     => json_decode( $raw, true ),
	'rawLen'   => strlen( $raw ),
);
// 先落盘再 sleep：超时用例里请求也要能被断言到。
file_put_contents( $log_file, json_encode( $entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n", FILE_APPEND | LOCK_EX );

/**
 * 输出 JSON。
 *
 * @param mixed $data   数据。
 * @param int   $status 状态码。
 */
function mock_json( $data, $status = 200 ) {
	http_response_code( $status );
	header( 'Content-Type: application/json; charset=utf-8' );
	echo json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
}

if ( '401' === $mock ) {
	mock_json(
		array(
			'error' => array(
				'message' => 'Incorrect API key provided: sk-****abcd.',
				'type'    => 'invalid_request_error',
				'code'    => 'invalid_api_key',
			),
		),
		401
	);
	return;
}

if ( 'slow' === $mock ) {
	// 上游 35 秒不响应：服务端 30 秒超时 -> 504 upstream_timeout。
	sleep( 35 );
	mock_json( array( 'choices' => array( array( 'message' => array( 'content' => '（迟到的回复）' ) ) ) ) );
	return;
}

if ( 'empty' === $mock ) {
	mock_json(
		array(
			'id'      => 'chatcmpl-mock-empty',
			'model'   => 'mock-model',
			'choices' => array(
				array(
					'index'         => 0,
					'message'       => array(
						'role'    => 'assistant',
						'content' => '',
					),
					'finish_reason' => 'stop',
				),
			),
			'usage'   => array(
				'prompt_tokens'     => 3,
				'completion_tokens' => 0,
			),
		)
	);
	return;
}

// Anthropic 形状。
if ( preg_match( '#/v1/messages$#', $path ) ) {
	$model = 'claude-mock';
	$body  = json_decode( $raw, true );
	if ( is_array( $body ) && isset( $body['model'] ) ) {
		$model = (string) $body['model'];
	}
	mock_json(
		array(
			'id'      => 'msg_mock',
			'type'    => 'message',
			'role'    => 'assistant',
			'model'   => $model,
			'content' => array(
				array(
					'type' => 'text',
					'text' => '你好呀～我是 mock（anthropic 形状）',
				),
			),
			'usage'   => array(
				'input_tokens'  => 50,
				'output_tokens' => 12,
			),
		)
	);
	return;
}

// Gemini 形状。
if ( false !== strpos( $path, ':generateContent' ) ) {
	mock_json(
		array(
			'candidates'    => array(
				array(
					'content'      => array(
						'parts' => array(
							array( 'text' => '你好呀～我是 mock（gemini 形状）' ),
						),
						'role'  => 'model',
					),
					'finishReason' => 'STOP',
				),
			),
			'usageMetadata' => array(
				'promptTokenCount'     => 40,
				'candidatesTokenCount' => 9,
			),
			'modelVersion'  => 'gemini-mock',
		)
	);
	return;
}

// 默认：OpenAI 形状。
$body  = json_decode( $raw, true );
$model = ( is_array( $body ) && isset( $body['model'] ) ) ? (string) $body['model'] : 'mock-model';
mock_json(
	array(
		'id'      => 'chatcmpl-mock',
		'object'  => 'chat.completion',
		'created' => time(),
		'model'   => $model,
		'choices' => array(
			array(
				'index'         => 0,
				'message'       => array(
					'role'    => 'assistant',
					'content' => '在的呀～我是 mock 供应商',
				),
				'finish_reason' => 'stop',
			),
		),
		'usage'   => array(
			'prompt_tokens'     => 42,
			'completion_tokens' => 18,
			'total_tokens'      => 60,
		),
	)
);
