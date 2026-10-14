<?php
error_reporting( E_ALL );
ini_set( 'display_errors', '1' );
$wp = 'C:\\Users\\Tage-HongDou\\AppData\\Local\\wp-test\\wordpress';
echo "STEP1 before require\n";
require $wp . '/wp-load.php';
echo "STEP2 after require\n";
echo 'wp_rewrite type=' . gettype( $GLOBALS['wp_rewrite'] ) . "\n";
echo 'abspath=' . ABSPATH . "\n";
echo 'plugins=' . implode( ',', (array) get_option( 'active_plugins' ) ) . "\n";
