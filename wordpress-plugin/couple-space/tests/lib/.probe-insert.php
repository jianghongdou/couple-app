<?php
// phpcs:disable
chdir( dirname( __DIR__ ) );
require 'C:\\Users\\Tage-HongDou\\AppData\\Local\\wp-test\\wordpress\\wp-load.php';

use CoupleSpace\Db;

register_shutdown_function(
	function () {
		$e = error_get_last();
		echo "[shutdown] " . ( $e ? $e['message'] . ' @ ' . basename( $e['file'] ) . ':' . $e['line'] : 'ok' ) . "\n";
	}
);

// 干净的两条消息：一条空 clientId、一条非空，验证 insert_ai_message 的返回值。
Db::run( 'DELETE FROM `' . Db::table( 'ai_messages' ) . "` WHERE owner_id = 'probe_owner'" );

$r1 = Db::insert_ai_message(
	array(
		'partnerId' => 'probe_partner',
		'ownerId'   => 'probe_owner',
		'sender'    => 'ai',
		'body'      => '空 clientId 的回复',
		'clientId'  => '',
	)
);
echo 'empty-clientId insert => ' . var_export( $r1, true ) . "\n";

$r2 = Db::insert_ai_message(
	array(
		'partnerId' => 'probe_partner',
		'ownerId'   => 'probe_owner',
		'sender'    => 'ai',
		'body'      => '带 clientId 的回复',
		'clientId'  => 'c:abc',
	)
);
echo 'with-clientId insert => ' . var_export( $r2, true ) . "\n";

$rows = Db::all( 'SELECT id, sender, client_id, body FROM `' . Db::table( 'ai_messages' ) . '` WHERE owner_id = %s', array( 'probe_owner' ) );
foreach ( $rows as $row ) {
	echo 'row: ' . json_encode( $row, JSON_UNESCAPED_UNICODE ) . "\n";
}
echo 'raw rows=' . count( $rows ) . "\n";
echo 'last_error=' . var_export( Db::last_error(), true ) . "\n";

Db::run( 'DELETE FROM `' . Db::table( 'ai_messages' ) . "` WHERE owner_id = 'probe_owner'" );
