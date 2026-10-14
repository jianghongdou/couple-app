<?php
// phpcs:disable
require __DIR__ . '/harness.php';
$T = new E2E_Harness(
	'http://127.0.0.1:8890',
	'C:\\Users\\Tage-HongDou\\AppData\\Local\\wp-test\\wordpress',
	'C:\\Users\\Tage-HongDou\\AppData\\Local\\php83\\php.exe',
	'x'
);

echo "--- table schema ---\n";
foreach ( $T->pdo_public()->query( 'SHOW CREATE TABLE `' . $T->table( 'ai_messages' ) . '`' )->fetchAll( PDO::FETCH_NUM ) as $r ) {
	echo $r[1] . "\n";
}
echo "--- rows (latest 8) ---\n";
foreach ( $T->pdo_public()->query( 'SELECT id, partner_id, owner_id, sender, LEFT(body,12) b, client_id, created_at FROM `' . $T->table( 'ai_messages' ) . '` ORDER BY created_at DESC LIMIT 8' )->fetchAll( PDO::FETCH_ASSOC ) as $r ) {
	echo json_encode( $r, JSON_UNESCAPED_UNICODE ) . "\n";
}
echo "--- SHOW INDEX ---\n";
foreach ( $T->pdo_public()->query( 'SHOW INDEX FROM `' . $T->table( 'ai_messages' ) . '`' )->fetchAll( PDO::FETCH_ASSOC ) as $r ) {
	echo $r['Key_name'] . ' seq=' . $r['Seq_in_index'] . ' col=' . $r['Column_name'] . ' nonuniq=' . $r['Non_unique'] . ' null=' . var_export( $r['Null'], true ) . "\n";
}
