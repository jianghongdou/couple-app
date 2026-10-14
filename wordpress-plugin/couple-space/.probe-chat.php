<?php
// phpcs:disable
require __DIR__ . '/tests/lib/harness.php';
$T = new E2E_Harness(
	'http://127.0.0.1:8890',
	'C:\\Users\\Tage-HongDou\\AppData\\Local\\wp-test\\wordpress',
	'C:\\Users\\Tage-HongDou\\AppData\\Local\\php83\\php.exe',
	'x'
);

$stamp = 'probe' . substr( (string) time(), -4 );
$T->db_exec( 'DELETE FROM `' . $T->table( 'rate_limits' ) . '`' );
$T->db_exec( 'DELETE FROM `' . $T->table( 'ai_providers' ) . '`' );
$reg = $T->req( 'POST', '/api/auth/register', array( 'username' => $stamp, 'password' => 'pass1234' ) );
$tok = (string) $T->get( $reg, 'token' );
$uid = (string) $T->get( $reg, 'user.id' );
$h   = array( 'Authorization' => 'Bearer ' . $tok );
echo "reg status={$reg['status']} uid=$uid\n";

// 直接落一个启用的供应商。
$T->db_exec(
	'INSERT INTO `' . $T->table( 'ai_providers' ) . '` (id,name,type,base_url,api_key_enc,models_json,default_model,enabled,is_default,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
	array( 'prov_probe1', 'Probe-Prov', 'openai', 'http://127.0.0.1:8899/v1', $T->wp_eval( 'echo \CoupleSpace\Settings::encrypt_secret( "sk-probeprobeprobeprobeprobe1234" );' )['out'], json_encode( array( 'mock-model-a' ) ), 'mock-model-a', 1, 1, time() * 1000, time() * 1000 )
);
$en = $T->db_scalar( 'SELECT COUNT(*) FROM `' . $T->table( 'ai_providers' ) . '` WHERE enabled=1' );
echo "enabled providers=$en\n";

$p = $T->req( 'POST', '/api/ai/partners', array( 'name' => '探针', 'persona' => '你是温柔的男友', 'providerId' => 'prov_probe1', 'model' => 'mock-model-a' ), $h );
$pid = (string) $T->get( $p, 'partner.id' );
echo "partner status={$p['status']} pid=$pid\n";

$m1 = $T->req( 'POST', '/api/ai/chat/messages', array( 'partnerId' => $pid, 'body' => '在吗', 'clientId' => 'pc1' ), $h );
echo "msg1 status={$m1['status']} reply=" . json_encode( $T->get( $m1, 'reply' ), JSON_UNESCAPED_UNICODE ) . "\n";
echo "msg1 body=" . substr( (string) $m1['body'], 0, 300 ) . "\n";

$m2 = $T->req( 'POST', '/api/ai/chat/messages', array( 'partnerId' => $pid, 'body' => '你好呀' ), $h );
echo "msg2 status={$m2['status']} reply=" . json_encode( $T->get( $m2, 'reply' ), JSON_UNESCAPED_UNICODE ) . "\n";
echo "msg2 body=" . substr( (string) $m2['body'], 0, 300 ) . "\n";

$rows = $T->db_scalar( 'SELECT COUNT(*) FROM `' . $T->table( 'ai_messages' ) . "` WHERE owner_id='$uid'" );
echo "rows=$rows\n";
foreach ( array( 'aichat:' . $uid ) as $k ) {
	$r = $T->db_scalar( 'SELECT CONCAT(`key`,"|",count,"|",prev_count,"|",window_start,"|",window_ms) FROM `' . $T->table( 'rate_limits' ) . "` WHERE `key`='$k'" );
	echo "rl[$k]=" . var_export( $r, true ) . "\n";
}

$list = $T->req( 'GET', '/api/ai/chat/messages?partnerId=' . $pid, null, $h );
echo "list count=" . count( (array) $T->get( $list, 'messages' ) ) . " hasMore=" . var_export( $T->get( $list, 'hasMore' ), true ) . "\n";

$msg2_id = (string) $T->get( $m2, 'message.id' );
$stored  = $T->db_scalar( 'SELECT client_id FROM `' . $T->table( 'ai_messages' ) . "` WHERE id = '" . $msg2_id . "'" );
echo "msg2 api clientId=" . var_export( $T->get( $m2, 'message.clientId' ), true ) . ' stored client_id=' . var_export( $stored, true ) . "\n";
$mapped = $T->wp_eval( '$r = \CoupleSpace\Db::get_ai_message( ' . var_export( $msg2_id, true ) . ', ' . var_export( $uid, true ) . ' ); echo json_encode( $r, JSON_UNESCAPED_UNICODE );' );
echo 'map_ai_message => ' . trim( $mapped['out'] ) . "\n";
foreach ( $T->pdo_public()->query( 'SELECT sender, client_id, body FROM `' . $T->table( 'ai_messages' ) . '`' )->fetchAll( PDO::FETCH_ASSOC ) as $r ) {
	echo 'row: ' . json_encode( $r, JSON_UNESCAPED_UNICODE ) . "\n";
}

$T->db_exec( 'DELETE FROM `' . $T->table( 'ai_messages' ) . '` WHERE owner_id = ?', array( $uid ) );
$T->db_exec( 'DELETE FROM `' . $T->table( 'ai_partners' ) . '` WHERE owner_id = ?', array( $uid ) );
$T->db_exec( 'DELETE FROM `' . $T->table( 'ai_providers' ) . '` WHERE id = ?', array( 'prov_probe1' ) );
$T->db_exec( 'DELETE FROM `' . $T->table( 'users' ) . '` WHERE id = ?', array( $uid ) );
