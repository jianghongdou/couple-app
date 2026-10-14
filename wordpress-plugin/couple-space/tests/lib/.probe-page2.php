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
	. 'wp_observe( "after-setup level=" . ob_get_level() );'
	. 'ob_start();'
	. 'try { \CoupleSpace\Admin::menu(); \CoupleSpace\AiAdmin::page_providers(); } catch ( \Throwable $e ) { wp_observe( "THROWN:" . get_class( $e ) . ":" . $e->getMessage() ); }'
	. '$mine = ob_get_contents();'
	. 'wp_observe( "captured_len=" . strlen( (string) $mine ) . " level=" . ob_get_level() );'
	. 'wp_observe( "DONE" );';

// 用报错日志做观察窗口：E_WARNING 会被 WP 写进 debug.log。
$code = str_replace( 'wp_observe(', 'trigger_error(', $code );
$r     = $T->wp_eval( $code );
echo 'RC=' . $r['code'] . ' OUTTAIL=[' . substr( $r['out'], -200 ) . "]\n";
