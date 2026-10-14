<?php
// 临时调试脚本：追踪 handle_provider_save 的重定向与输出。
error_reporting( E_ALL );
ini_set( 'display_errors', '1' );

$wp = 'C:\\Users\\Tage-HongDou\\AppData\\Local\\wp-test\\wordpress';
chdir( dirname( __DIR__, 2 ) ); // 与测试脚手架一致：CWD 必须在插件目录（wp-load.php 靠它找 wp-config.php）。
require $wp . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

if ( ! function_exists( 'wp_safe_redirect' ) ) {
	function wp_safe_redirect( $location, $status = 302 ) {
		$GLOBALS['__CS_LOC'] = (string) $location;
		throw new \RuntimeException( '__CS_REDIRECT__' );
	}
}

$uid = username_exists( 'coupleadmin' );
if ( ! $uid ) {
	$uid = wp_create_user( 'coupleadmin', 'coupleadminpass123', 'coupleadmin@example.com' );
}
$u = new WP_User( $uid );
$u->set_role( 'administrator' );
wp_set_current_user( $uid );

add_filter(
	'wp_die_handler',
	function () {
		return function ( $message, $title = '', $args = array() ) {
			$GLOBALS['__CS_DIE'] = 'code=' . ( is_array( $args ) && isset( $args['response'] ) ? (int) $args['response'] : 500 ) . ' msg=' . wp_strip_all_tags( (string) $message );
			throw new \RuntimeException( '__CS_DIE__' );
		};
	}
);

register_shutdown_function(
	function () {
		$err = error_get_last();
		echo "\n[shutdown] loc=" . ( isset( $GLOBALS['__CS_LOC'] ) ? $GLOBALS['__CS_LOC'] : '(none)' )
			. ' die=' . ( isset( $GLOBALS['__CS_DIE'] ) ? $GLOBALS['__CS_DIE'] : '(none)' )
			. ' lasterr=' . ( $err ? json_encode( array( $err['type'], $err['message'], basename( $err['file'] ) . ':' . $err['line'] ) ) : '(none)' ) . "\n";
	}
);

$_GET     = array( 'page' => 'couple-space-ai-providers' );
$_POST    = array(
	'providerId'   => '',
	'name'         => 'Probe-P1',
	'type'         => 'openai',
	'baseUrl'      => 'http://127.0.0.1:8899/v1',
	'apiKey'       => 'sk-testsuperSECRETkey1234567890abcd',
	'models'       => "mock-model-a\nmock-model-b",
	'defaultModel' => 'mock-model-a',
	'enabled'      => '1',
	'isDefault'    => '1',
	'action'       => 'couple_space_ai_provider',
	'_wpnonce'     => wp_create_nonce( 'couple_space_ai_provider' ),
);
$_REQUEST = array_merge( $_GET, $_POST );

echo "uid=" . get_current_user_id() . " can=" . var_export( current_user_can( 'manage_options' ), true ) . "\n";
echo "referer_check=";
try {
	check_admin_referer( 'couple_space_ai_provider' );
	echo "OK\n";
} catch ( \Throwable $e ) {
	echo 'THROWN ' . get_class( $e ) . ': ' . $e->getMessage() . "\n";
}

echo "calling handle_provider_save...\n";
try {
	\CoupleSpace\AiAdmin::handle_provider_save();
	echo "\nreturned normally\n";
} catch ( \Throwable $e ) {
	echo "\nTHROWN " . get_class( $e ) . ': ' . $e->getMessage() . "\n";
}
echo "ob_level=" . ob_get_level() . "\n";
