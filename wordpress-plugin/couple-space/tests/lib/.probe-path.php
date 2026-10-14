<?php
require 'C:\\Users\\Tage-HongDou\\AppData\\Local\\wp-test\\wordpress\\wp-load.php';
$p = 'C:/Users/Tage-HongDou/AppData/Local/wp-test/wordpress/wp-content/plugins/couple-space/includes/Routes/../../tests/.dbg-path.txt';
var_dump( $p, realpath( dirname( $p ) ) );
var_dump( file_put_contents( $p, 'hello' . PHP_EOL, FILE_APPEND ) );
