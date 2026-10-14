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
	. 'register_shutdown_function( function () {'
	. '  $buf = ""; while ( ob_get_level() > 0 ) { $buf = ob_get_clean() . $buf; }'
	. '  $err = error_get_last();'
	. '  $fp = fopen( dirname( __DIR__ ) . "/.probe-f2.json", "w" );'
	. '  fwrite( $fp, json_encode( array( "out_len" => strlen( $buf ), "lasterr" => $err ? $err["message"] : "" ), JSON_UNESCAPED_UNICODE ) );'
	. '  fclose( $fp ); } );'
	. 'add_filter( "wp_redirect", function ( $location ) { $GLOBALS["__CS_LOC"] = (string) $location; return $location; } );'
	. 'ob_start();'
	. 'try { \CoupleSpace\Admin::menu(); \CoupleSpace\AiAdmin::page_providers(); } catch ( \Throwable $e ) { echo "THROWN:" . $e->getMessage(); }'
	. 'echo "NO_EXIT:" . strlen( (string) ob_get_contents() );';

$r = $T->wp_eval( $code );
echo 'RC=' . $r['code'] . "\n";
echo 'JSON=' . ( is_file( __DIR__ . '/.probe-f2.json' ) ? (string) file_get_contents( __DIR__ . '/.probe-f2.json' ) : '(missing)' ) . "\n";
@unlink( __DIR__ . '/.probe-f2.json' );
