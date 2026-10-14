<?php
// phpcs:disable
chdir( dirname( __DIR__ ) );
require 'C:\\Users\\Tage-HongDou\\AppData\\Local\\wp-test\\wordpress\\wp-load.php';
use CoupleSpace\AiUpstream;

$sample = array(
	'id'      => 'chatcmpl-mock',
	'object'  => 'chat.completion',
	'created' => time(),
	'model'   => 'mock-model-a',
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
	),
);

$parsed = AiUpstream::parse_response( 'openai', $sample );
echo 'parse_response=' . json_encode( $parsed, JSON_UNESCAPED_UNICODE ) . "\n";
echo 'trim_test=[' . trim( '在的呀～我是 mock 供应商' ) . "]\n";

// 直接用该供应商跑一次 chat()。
$provider = array(
	'name'    => 'Probe',
	'type'    => 'openai',
	'baseUrl' => 'http://127.0.0.1:8899/v1',
	'apiKey'  => 'sk-probeprobeprobeprobeprobe1234',
);
$res = AiUpstream::chat(
	$provider,
	array(
		array( 'role' => 'system', 'content' => '你是温柔的男友' ),
		array( 'role' => 'user', 'content' => '在吗' ),
	),
	array( 'model' => 'mock-model-a', 'temperature' => 0.8 )
);
echo 'chat_result=' . json_encode( $res, JSON_UNESCAPED_UNICODE ) . "\n";
