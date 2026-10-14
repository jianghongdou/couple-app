<?php
// phpcs:disable
require __DIR__ . '/tests/lib/harness.php';
$T = new E2E_Harness(
	'http://127.0.0.1:8890',
	'C:\\Users\\Tage-HongDou\\AppData\\Local\\wp-test\\wordpress',
	'C:\\Users\\Tage-HongDou\\AppData\\Local\\php83\\php.exe',
	'x'
);

$stamp = 'rprobe' . substr( (string) time(), -4 );
$T->db_exec( 'DELETE FROM `' . $T->table( 'rate_limits' ) . '`' );
$T->db_exec( 'DELETE FROM `' . $T->table( 'ai_providers' ) . '`' );
$reg = $T->req( 'POST', '/api/auth/register', array( 'username' => $stamp, 'password' => 'pass1234' ) );
$tok = (string) $T->get( $reg, 'token' );
$uid = (string) $T->get( $reg, 'user.id' );
$h   = array( 'Authorization' => 'Bearer ' . $tok );

$T->db_exec(
	'INSERT INTO `' . $T->table( 'ai_providers' ) . '` (id,name,type,base_url,api_key_enc,models_json,default_model,enabled,is_default,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
	array( 'prov_rl1', 'RL-Prov', 'openai', 'http://127.0.0.1:8899/v1', 'sk-plain-rl-key-1234567890', json_encode( array( 'mock-model-a' ) ), 'mock-model-a', 1, 1, time() * 1000, time() * 1000 )
);
$p   = $T->req( 'POST', '/api/ai/partners', array( 'name' => '限流探针', 'persona' => '温柔', 'providerId' => 'prov_rl1', 'model' => 'mock-model-a' ), $h );
$pid = (string) $T->get( $p, 'partner.id' );
echo "pid=$pid\n";

$T->db_exec( 'DELETE FROM `' . $T->table( 'rate_limits' ) . '` WHERE `key` LIKE ?', array( 'aichat:%' ) );
$statuses = array();
for ( $i = 1; $i <= 30; $i++ ) {
	$r          = $T->req( 'POST', '/api/ai/chat/messages', array( 'partnerId' => $pid, 'body' => '第' . $i . '条', 'clientId' => 'rc' . $i ), $h );
	$statuses[] = $r['status'];
	$rl         = $T->db_scalar( 'SELECT CONCAT(count,"/",prev_count,"/",window_start) FROM `' . $T->table( 'rate_limits' ) . "` WHERE `key` = 'aichat:" . $uid . "'" );
	printf( "i=%2d status=%d rl=%s\n", $i, $r['status'], (string) $rl );
}
echo 'statuses=' . implode( ',', $statuses ) . "\n";

$T->db_exec( 'DELETE FROM `' . $T->table( 'ai_messages' ) . '` WHERE owner_id = ?', array( $uid ) );
$T->db_exec( 'DELETE FROM `' . $T->table( 'ai_partners' ) . '` WHERE owner_id = ?', array( $uid ) );
$T->db_exec( 'DELETE FROM `' . $T->table( 'ai_providers' ) . '` WHERE id = ?', array( 'prov_rl1' ) );
$T->db_exec( 'DELETE FROM `' . $T->table( 'users' ) . '` WHERE id = ?', array( $uid ) );
$T->db_exec( 'DELETE FROM `' . $T->table( 'rate_limits' ) . '` WHERE `key` LIKE ?', array( 'aichat:%' ) );
