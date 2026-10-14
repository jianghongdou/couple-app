<?php
// phpcs:disable
require __DIR__ . '/harness.php';
$T = new E2E_Harness(
	'http://127.0.0.1:8890',
	'C:\\Users\\Tage-HongDou\\AppData\\Local\\wp-test\\wordpress',
	'C:\\Users\\Tage-HongDou\\AppData\\Local\\php83\\php.exe',
	'x'
);

$t = $T->table( 'ai_providers' );
$T->db_exec( "DELETE FROM `$t` WHERE name LIKE 'PROBE%'" );
$now = (int) round( microtime( true ) * 1000 );

// A、B 两个供应商：A 先建（默认），B 后建，然后把默认切到 B。
$T->db_exec(
	"INSERT INTO `$t` (id,name,type,base_url,api_key_enc,models_json,default_model,enabled,is_default,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)",
	array( 'prov_probeA', 'PROBE-A', 'openai', 'http://127.0.0.1:8899/v1', 'enc-x', '["m"]', 'm', 1, 1, $now, $now )
);
$T->db_exec(
	"INSERT INTO `$t` (id,name,type,base_url,api_key_enc,models_json,default_model,enabled,is_default,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)",
	array( 'prov_probeB', 'PROBE-B', 'anthropic', 'http://127.0.0.1:8899', 'enc-y', '["m"]', 'm', 1, 0, $now, $now )
);

echo "default before: " . var_export( $T->db_scalar( "SELECT id FROM `$t` WHERE is_default=1" ), true ) . "\n";
$T->db_exec( "UPDATE `$t` SET is_default=0" );
$T->db_exec( "UPDATE `$t` SET is_default=1 WHERE id='prov_probeB'" );
echo "default after switch: " . var_export( $T->db_scalar( "SELECT id FROM `$t` WHERE is_default=1" ), true ) . "\n";
echo 'all=' . json_encode( $T->pdo_public()->query( "SELECT id,name,is_default,enabled,created_at FROM `$t`" )->fetchAll( PDO::FETCH_ASSOC ) ) . "\n";

// 通过 Db 层删除 B（与 AiAdmin::handle_provider_delete 相同的调用）。
$out = $T->wp_eval( 'var_export( \CoupleSpace\Db::delete_ai_provider( "prov_probeB" ) );' );
echo 'Db::delete_ai_provider(B) => ' . trim( $out['out'] ) . "\n";
echo 'rows after delete=' . json_encode( $T->pdo_public()->query( "SELECT id,name,is_default FROM `$t`" )->fetchAll( PDO::FETCH_ASSOC ) ) . "\n";

$reselect = $T->wp_eval( 'echo \CoupleSpace\Db::reselect_default_ai_provider();' );
echo 'reselect => [' . trim( $reselect['out'] ) . "]\n";
echo 'rows after reselect=' . json_encode( $T->pdo_public()->query( "SELECT id,name,is_default FROM `$t`" )->fetchAll( PDO::FETCH_ASSOC ) ) . "\n";

$T->db_exec( "DELETE FROM `$t` WHERE name LIKE 'PROBE%'" );
