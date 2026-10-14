<?php
// phpcs:disable
// 调试：复刻 tests/ai-e2e.php 的 wp_admin_call 采集链路，定位 __CS_LOC 为何为空。
require __DIR__ . '/harness.php';

$T = new E2E_Harness(
	'http://127.0.0.1:8890',
	'C:\\Users\\Tage-HongDou\\AppData\\Local\\wp-test\\wordpress',
	'C:\\Users\\Tage-HongDou\\AppData\\Local\\php83\\php.exe',
	'x'
);

$setup = 'require_once ABSPATH . "wp-admin/includes/admin.php";'
	. 'require_once ABSPATH . "wp-admin/includes/plugin.php";'
	. 'echo "FUNC_EXISTS=" . var_export( function_exists( "wp_safe_redirect" ), true ) . "|";'
	. 'if ( ! function_exists( "wp_safe_redirect" ) ) {'
	. '  function wp_safe_redirect( $location, $status = 302 ) {'
	. '    $GLOBALS["__CS_LOC"] = (string) $location;'
	. '    throw new \RuntimeException( "__CS_REDIRECT__" );'
	. '  }'
	. '}'
	. '  $uid = username_exists( "coupleadmin" );'
	. '  if ( ! $uid ) { $uid = wp_create_user( "coupleadmin", "coupleadminpass123", "coupleadmin@example.com" ); }'
	. '  $u = new WP_User( $uid ); $u->set_role( "administrator" );'
	. '  wp_set_current_user( $uid );'
	. '$_GET = array( "page" => "couple-space-ai-providers" );'
	. 'register_shutdown_function( function () {'
	. '  $buf = ""; while ( ob_get_level() > 0 ) { $buf = ob_get_clean() . $buf; }'
	. '  $fp = fopen( dirname( __DIR__ ) . "/.admin-call.json", "w" );'
	. '  fwrite( $fp, json_encode( array( "headers" => headers_list(), "out" => $buf, "die" => isset( $GLOBALS["__CS_DIE"] ) ? $GLOBALS["__CS_DIE"] : "", "loc" => isset( $GLOBALS["__CS_LOC"] ) ? $GLOBALS["__CS_LOC"] : "", "trace" => isset( $GLOBALS["__CS_TRACE"] ) ? $GLOBALS["__CS_TRACE"] : "", "extra" => isset( $GLOBALS["__CS_EXTRA"] ) ? $GLOBALS["__CS_EXTRA"] : "" ) ) );'
	. '  fclose( $fp ); } );';

$post = array(
	'providerId'   => '',
	'name'         => 'Probe-P1',
	'type'         => 'openai',
	'baseUrl'      => 'http://127.0.0.1:8899/v1',
	'apiKey'       => 'sk-testsuperSECRETkey1234567890abcd',
	'models'       => "mock-model-a\nmock-model-b",
	'defaultModel' => 'mock-model-a',
	'enabled'      => '1',
	'isDefault'    => '1',
);

$call = '$_POST = ' . var_export( $post, true ) . ';'
	. '$_POST["action"] = "couple_space_ai_provider";'
	. '$_POST["_wpnonce"] = wp_create_nonce( "couple_space_ai_provider" );'
	. '$_REQUEST = array_merge( $_GET, $_POST );'
	. 'try {'
	. '  \CoupleSpace\AiAdmin::handle_provider_save();'
	. '  $GLOBALS["__CS_EXTRA"] = "returned-normally";'
	. '} catch ( \RuntimeException $e ) {'
	. '  $GLOBALS["__CS_EXTRA"] = "caught:" . $e->getMessage() . " loc=" . ( isset( $GLOBALS["__CS_LOC"] ) ? $GLOBALS["__CS_LOC"] : "(unset)" );'
	. '} catch ( \Throwable $e ) {'
	. '  $GLOBALS["__CS_EXTRA"] = "caught-other:" . get_class( $e ) . ":" . $e->getMessage();'
	. '}'
	. 'echo "NO_EXIT:" . strlen( (string) ob_get_contents() ) . " obj=" . ob_get_level();';

$r = $T->wp_eval( $setup . $call );
echo "EVAL OUT=[" . $r['out'] . "] RC=" . $r['code'] . "\n";
echo "JSON=" . ( is_file( __DIR__ . '/.admin-call.json' ) ? (string) file_get_contents( __DIR__ . '/.admin-call.json' ) : '(missing)' ) . "\n";
@unlink( __DIR__ . '/.admin-call.json' );
