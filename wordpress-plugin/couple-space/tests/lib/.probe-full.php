<?php
// phpcs:disable
require __DIR__ . '/harness.php';
$T = new E2E_Harness(
	'http://127.0.0.1:8890',
	'C:\\Users\\Tage-HongDou\\AppData\\Local\\wp-test\\wordpress',
	'C:\\Users\\Tage-HongDou\\AppData\\Local\\php83\\php.exe',
	'x'
);

$code = 'require_once ABSPATH . "wp-admin/includes/admin.php";'
	. 'require_once ABSPATH . "wp-admin/includes/plugin.php";'
	. '  $uid = username_exists( "coupleadmin" );'
	. '  if ( ! $uid ) { $uid = wp_create_user( "coupleadmin", "coupleadminpass123", "coupleadmin@example.com" ); }'
	. '  $u = new WP_User( $uid ); $u->set_role( "administrator" );'
	. '  wp_set_current_user( $uid );'
	. '$_GET = array( "page" => "couple-space-ai-providers" );'
	. 'add_filter( "wp_redirect", function ( $location ) { $GLOBALS["__CS_LOC"] = (string) $location; return $location; } );'
	. 'add_filter( "wp_die_handler", function () { return function ( $message, $title = "", $args = array() ) {'
	. '  $plain = is_wp_error( $message ) ? $message->get_error_message() : (string) $message;'
	. '  $code  = ( is_array( $args ) && isset( $args["response"] ) ) ? (int) $args["response"] : 500;'
	. '  $GLOBALS["__CS_DIE"] = "code=" . $code . " title=" . $title . " msg=" . wp_strip_all_tags( $plain );'
	. '  throw new \RuntimeException( "__CS_DIE__" );'
	. '}; } );'
	. 'register_shutdown_function( function () {'
	. '  $buf = ""; while ( ob_get_level() > 0 ) { $buf = ob_get_clean() . $buf; }'
	. '  $fp = fopen( dirname( __DIR__ ) . "/.probe-full.json", "w" );'
	. '  fwrite( $fp, json_encode( array( "out_len" => strlen( $buf ), "out_head" => substr( $buf, 0, 50 ), "die" => isset( $GLOBALS["__CS_DIE"] ) ? $GLOBALS["__CS_DIE"] : "", "loc" => isset( $GLOBALS["__CS_LOC"] ) ? $GLOBALS["__CS_LOC"] : "" ) ) );'
	. '  fclose( $fp ); } );'
	. 'ob_start();'
	. 'try { \CoupleSpace\Admin::menu(); \CoupleSpace\AiAdmin::page_providers(); } catch ( \Throwable $e ) { echo "THROWN:" . $e->getMessage(); }'
	. 'echo "NO_EXIT:" . strlen( (string) ob_get_contents() );';

$r = $T->wp_eval( $code );
echo 'RC=' . $r['code'] . "\n";
echo 'JSON=' . ( is_file( __DIR__ . '/.probe-full.json' ) ? (string) file_get_contents( __DIR__ . '/.probe-full.json' ) : '(missing)' ) . "\n";
@unlink( __DIR__ . '/.probe-full.json' );
