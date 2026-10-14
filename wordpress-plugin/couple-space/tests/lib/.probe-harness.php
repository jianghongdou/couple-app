<?php
require __DIR__ . '/harness.php';
$T = new E2E_Harness(
	'http://127.0.0.1:8890',
	'C:\\Users\\Tage-HongDou\\AppData\\Local\\wp-test\\wordpress',
	'C:\\Users\\Tage-HongDou\\AppData\\Local\\php83\\php.exe',
	'x'
);
$r = $T->wp_eval( 'echo "WR=" . gettype($GLOBALS["wp_rewrite"]) . " DBG=" . var_export(WP_DEBUG,true) . " ABSPATH=" . ABSPATH;' );
echo "OUT=[" . $r['out'] . "] RC=" . $r['code'] . "\n";
