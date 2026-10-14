<?php
// phpcs:disable
require __DIR__ . '/harness.php';
$T = new E2E_Harness(
	'http://127.0.0.1:8890',
	'C:\\Users\\Tage-HongDou\\AppData\\Local\\wp-test\\wordpress',
	'C:\\Users\\Tage-HongDou\\AppData\\Local\\php83\\php.exe',
	'x'
);

$trace = str_replace( '\\', '/', __DIR__ . '/.probe-trace.txt' );
@unlink( __DIR__ . '/.probe-trace.txt' );

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
	. '  file_put_contents( ' . var_export( $trace, true ) . ', "SHUTDOWN out_len=" . strlen( $buf ) . " lasterr=" . ( $err ? $err["message"] : "-" ) . "\n", FILE_APPEND );'
	. '} );'
	. 'ob_start();'
	. 'file_put_contents( ' . var_export( $trace, true ) . ', "S1 level=" . ob_get_level() . "\n", FILE_APPEND );'
	. 'try { \CoupleSpace\Admin::menu(); } catch ( \Throwable $e ) { file_put_contents( ' . var_export( $trace, true ) . ', "MENU_THROWN " . get_class( $e ) . ":" . $e->getMessage() . "\n", FILE_APPEND ); }'
	. 'file_put_contents( ' . var_export( $trace, true ) . ', "S2 after menu level=" . ob_get_level() . " len=" . strlen( (string) ob_get_contents() ) . "\n", FILE_APPEND );'
	. 'try { \CoupleSpace\AiAdmin::page_providers(); } catch ( \Throwable $e ) { file_put_contents( ' . var_export( $trace, true ) . ', "PAGE_THROWN " . get_class( $e ) . ":" . $e->getMessage() . "\n", FILE_APPEND ); }'
	. 'file_put_contents( ' . var_export( $trace, true ) . ', "S3 after page level=" . ob_get_level() . " len=" . strlen( (string) ob_get_contents() ) . "\n", FILE_APPEND );';

$r = $T->wp_eval( $code );
echo 'RC=' . $r['code'] . "\n";
echo "TRACE:\n" . ( is_file( __DIR__ . '/.probe-trace.txt' ) ? (string) file_get_contents( __DIR__ . '/.probe-trace.txt' ) : '(missing)' ) . "\n";
echo 'OUTTAIL=[' . substr( $r['out'], -120 ) . "]\n";
