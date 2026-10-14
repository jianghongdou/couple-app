<?php
// phpcs:disable
// 复现「删除默认供应商后自动改选」用例的失败：只跑到该用例所需的步骤。
require __DIR__ . '/tests/lib/harness.php';
$T = new E2E_Harness(
	'http://127.0.0.1:8890',
	'C:\\Users\\Tage-HongDou\\AppData\\Local\\wp-test\\wordpress',
	'C:\\Users\\Tage-HongDou\\AppData\\Local\\php83\\php.exe',
	'x'
);

$dst = 'C:\\Users\\Tage-HongDou\\AppData\\Local\\wp-test\\wordpress\\wp-content\\plugins\\couple-space';
@unlink( $dst . '\\tests\\.dbg-del.txt' );

// 直接插入两个供应商（A 默认、B 非默认），再切默认到 B，然后删 B。
$t   = $T->table( 'ai_providers' );
$now = (int) round( microtime( true ) * 1000 );
$T->db_exec( "DELETE FROM `$t` WHERE name LIKE 'REPRO%'" );
$T->db_exec(
	"INSERT INTO `$t` (id,name,type,base_url,api_key_enc,models_json,default_model,enabled,is_default,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)",
	array( 'prov_repA', 'REPRO-A', 'openai', 'http://127.0.0.1:8899/v1', 'enc-a', '["m"]', 'm', 1, 1, $now, $now )
);
$T->db_exec(
	"INSERT INTO `$t` (id,name,type,base_url,api_key_enc,models_json,default_model,enabled,is_default,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)",
	array( 'prov_repB', 'REPRO-B', 'anthropic', 'http://127.0.0.1:8899', 'enc-b', '["m"]', 'm', 1, 0, $now, $now )
);

// 走 AiAdmin 的默认切换与删除（非 anon，走真实 wp-admin 权限 + nonce）。
$anon = __DIR__ . '/tests/.admin-anon.flag';
@unlink( $anon );

$setup = 'require_once ABSPATH . "wp-admin/includes/admin.php";'
	. 'require_once ABSPATH . "wp-admin/includes/plugin.php";'
	. 'add_filter( "wp_redirect", function ( $location, $status = 302 ) { $GLOBALS["__CS_LOC"] = (string) $location; throw new \RuntimeException( "__CS_REDIRECT__" ); }, 10, 2 );'
	. '  $uid = username_exists( "coupleadmin" );'
	. '  if ( ! $uid ) { $uid = wp_create_user( "coupleadmin", "coupleadminpass123", "coupleadmin@example.com" ); }'
	. '  $u = new WP_User( $uid ); $u->set_role( "administrator" ); wp_set_current_user( $uid );'
	. '$_GET = array( "page" => "couple-space-ai-providers" );';

/**
 * @param E2E_Harness $T   harness
 * @param string      $setup setup code
 * @param string      $hook hook
 * @param string      $id   provider id
 */
function call_hook( $T, $setup, $hook, $id ) {
	$post = array( 'providerId' => $id );
	$call = '$_POST = ' . var_export( $post, true ) . ';'
		. '$_POST["action"] = ' . var_export( $hook, true ) . ';'
		. '$_POST["_wpnonce"] = wp_create_nonce( "couple_space_ai_provider" );'
		. '$_REQUEST = array_merge( $_GET, $_POST );'
		. 'try {'
		. ( 'couple_space_ai_provider_default' === $hook ? '  \CoupleSpace\AiAdmin::handle_provider_default();' : '  \CoupleSpace\AiAdmin::handle_provider_delete();' )
		. '} catch ( \Throwable $e ) { echo "THROWN:" . $e->getMessage(); }'
		. 'echo "LOC=" . ( isset( $GLOBALS["__CS_LOC"] ) ? $GLOBALS["__CS_LOC"] : "(none)" );';
	return $T->wp_eval( $setup . $call );
}

$r1 = call_hook( $T, $setup, 'couple_space_ai_provider_default', 'prov_repB' );
echo 'set default => ' . trim( $r1['out'] ) . "\n";
echo 'db: ' . json_encode( $T->pdo_public()->query( "SELECT id,name,is_default FROM `$t` WHERE name LIKE 'REPRO%'" )->fetchAll( PDO::FETCH_ASSOC ) ) . "\n";

$r2 = call_hook( $T, $setup, 'couple_space_ai_provider_delete', 'prov_repB' );
echo 'delete => ' . trim( $r2['out'] ) . "\n";
echo 'db after delete: ' . json_encode( $T->pdo_public()->query( "SELECT id,name,is_default FROM `$t` WHERE name LIKE 'REPRO%'" )->fetchAll( PDO::FETCH_ASSOC ) ) . "\n";

$T->db_exec( "DELETE FROM `$t` WHERE name LIKE 'REPRO%'" );
echo "--- .dbg-del ---\n";
echo is_file( $dst . '\\tests\\.dbg-del.txt' ) ? (string) file_get_contents( $dst . '\\tests\\.dbg-del.txt' ) : "(missing)\n";
