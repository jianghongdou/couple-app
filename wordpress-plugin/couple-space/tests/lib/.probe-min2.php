<?php
error_reporting( E_ALL );
ini_set( 'display_errors', '1' );
$wp = 'C:\\Users\\Tage-HongDou\\AppData\\Local\\wp-test\\wordpress';
chdir( dirname( __DIR__, 2 ) );
register_shutdown_function(
	function () {
		$e = error_get_last();
		echo "\n[shutdown] lasterr=" . ( $e ? json_encode( array( $e['type'], $e['message'], basename( $e['file'] ) . ':' . $e['line'] ) ) : '(none)' ) . "\n";
	}
);
echo "STEP1 cwd=" . getcwd() . "\n";
require $wp . '/wp-load.php';
echo "STEP2 after require, wp_rewrite=" . gettype( $GLOBALS['wp_rewrite'] ) . "\n";
echo "active_plugins=" . implode( ',', (array) get_option( 'active_plugins' ) ) . "\n";
