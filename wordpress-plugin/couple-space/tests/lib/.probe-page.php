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
	. '  $lvl = ob_get_level(); $buf = ""; while ( ob_get_level() > 0 ) { $buf = ob_get_clean() . $buf; }'
	. '  $fp = fopen( dirname( __DIR__ ) . "/.probe-page.json", "w" );'
	. '  fwrite( $fp, json_encode( array( "level_at_shutdown" => $lvl, "buf_len" => strlen( $buf ), "buf_head" => substr( $buf, 0, 60 ), "lasterr" => error_get_last() ? error_get_last()["message"] : "" ) ) );'
	. '  fclose( $fp ); } );'
	. 'echo "LEVEL_BEFORE=" . ob_get_level() . "|";'
	. 'ob_start();'
	. '$before = ob_get_level();'
	. 'try { \CoupleSpace\Admin::menu(); \CoupleSpace\AiAdmin::page_providers(); } catch ( \Throwable $e ) { echo "THROWN:" . $e->getMessage(); }'
	. '$mine = ob_get_contents();'
	. 'echo "|LEVEL_AFTER=" . ob_get_level() . " BEFORE=" . $before . " MINE_LEN=" . strlen( (string) $mine ) . " MINE_AT_LEVEL=" . ( ob_get_level() - 1 );';

$r = $T->wp_eval( $code );
echo 'EVAL OUT(head)=[' . substr( $r['out'], 0, 400 ) . "]\n";
echo 'RC=' . $r['code'] . "\n";
echo 'JSON=' . ( is_file( __DIR__ . '/.probe-page.json' ) ? (string) file_get_contents( __DIR__ . '/.probe-page.json' ) : '(missing)' ) . "\n";
@unlink( __DIR__ . '/.probe-page.json' );
