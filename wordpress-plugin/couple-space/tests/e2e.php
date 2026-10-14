<?php
/**
 * 情侣空间 WordPress 插件 · 端到端测试（真实 HTTP 打 WordPress 站点）
 *
 * 用法：
 *   php tests/e2e.php
 *   php tests/e2e.php --url=http://127.0.0.1:8890 --wp=C:\path\to\wordpress
 *
 * 覆盖：
 *   - 三种访问地址形式（/api/... 、/wp-json/couple/v1/api/... 、/?couple_api=/api/...）
 *   - API.md 全部接口的字段名 / 错误码 / 限制值
 *   - 表情包（真实最小 PNG、magic bytes、路径穿越、删除权限）
 *   - App 后台接口（鉴权、密码、设置掩码、用户管理、日志）
 *   - WordPress 特有：激活建表、连续激活两次、停用再启用数据还在
 *
 * 结果同时写入 tests/report.txt。
 *
 * @package CoupleSpace
 */

// phpcs:disable

error_reporting( E_ALL );
ini_set( 'display_errors', '1' );

require __DIR__ . '/lib/harness.php';

// ---------------------------------------------------------------------
// 参数
// ---------------------------------------------------------------------

$opts = array(
	'url'   => 'http://127.0.0.1:8890',
	'wp'    => 'C:\\Users\\Tage-HongDou\\AppData\\Local\\wp-test\\wordpress',
	'php'   => 'C:\\Users\\Tage-HongDou\\AppData\\Local\\php83\\php.exe',
	'admin' => 'couplespacetest',
	'quiet' => false,
);
foreach ( array_slice( $argv, 1 ) as $arg ) {
	if ( 0 === strpos( $arg, '--url=' ) ) {
		$opts['url'] = rtrim( substr( $arg, 6 ), '/' );
	} elseif ( 0 === strpos( $arg, '--wp=' ) ) {
		$opts['wp'] = rtrim( substr( $arg, 5 ), '/\\' );
	} elseif ( 0 === strpos( $arg, '--php=' ) ) {
		$opts['php'] = substr( $arg, 6 );
	} elseif ( 0 === strpos( $arg, '--admin=' ) ) {
		$opts['admin'] = substr( $arg, 8 );
	} elseif ( '--quiet' === $arg ) {
		$opts['quiet'] = true;
	}
}

$T = new E2E_Harness( $opts['url'], $opts['wp'], $opts['php'], $opts['admin'] );

// 唯一的用户名后缀，避免多次运行互相干扰。
$stamp  = substr( base_convert( (string) time(), 10, 36 ), -5 ) . str_pad( (string) random_int( 0, 46655 ), 3, '0', STR_PAD_LEFT );
$uname  = static function ( $tag ) use ( $stamp ) {
	return $tag . $stamp;
};

$u1 = $uname( 'alice' );
$u2 = $uname( 'bob' );
$u3 = $uname( 'carol' );
$u4 = $uname( 'dave' );

$T->section( '环境' );
$T->info( '站点地址：' . $opts['url'] );
$T->info( 'WordPress：' . $opts['wp'] );
$T->info( '用户名后缀：' . $stamp );

// 清掉上一次运行（或人工调试）留下的限流计数：
// 本套用例会在 15 分钟窗口内多次命中后台登录/改密码接口，若不清零会与上一轮互相干扰。
$T->db_exec( 'DELETE FROM `' . $T->table( 'rate_limits' ) . '`' );

// 预热：确认站点可用。
$probe = $T->req( 'GET', '/api/health' );
$T->ok( 200 === $probe['status'], '站点与插件可用（/api/health 返回 200）', 'HTTP ' . $probe['status'] );
if ( 200 !== $probe['status'] ) {
	$T->finish( __DIR__ . '/report.txt' );
	exit( 1 );
}

// ---------------------------------------------------------------------
// 0. WordPress 特有：激活幂等 / 停用再启用数据还在
// ---------------------------------------------------------------------

$T->section( 'WordPress：激活与数据保留' );

// 先真正卸掉一次：删除插件选项 + DROP 全部自建表，
// 这样紧接着的 activate_plugin() 走的是完整的「首次激活建表」路径。
$T->wpcli_eval(
	'require_once ABSPATH.\'wp-admin/includes/plugin.php\';'
	. ' deactivate_plugins(\'couple-space/couple-space.php\');'
	. ' delete_option(\'couple_space_settings\'); delete_option(\'couple_space_db_version\'); delete_option(\'couple_space_needs_flush\');'
	. ' global $wpdb;'
	. ' foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME LIKE %s", $wpdb->dbname, $wpdb->prefix . "couple%" ) ) as $t ) { $wpdb->query( "DROP TABLE IF EXISTS `$t`" ); }'
	. ' echo "CLEANED";'
);
$dropped = $T->db_scalar( "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE '" . $T->prefix() . "couple%'" );
$T->ok( 0 === (int) $dropped, '测试前清空自建表与插件选项（模拟全新安装）', '剩余自建表 ' . (int) $dropped . ' 张' );

$t1 = $T->wpcli_eval( 'require_once ABSPATH.\'wp-admin/includes/plugin.php\'; var_dump(activate_plugin(\'couple-space/couple-space.php\'));' );
$t2 = $T->wpcli_eval( 'require_once ABSPATH.\'wp-admin/includes/plugin.php\'; var_dump(activate_plugin(\'couple-space/couple-space.php\'));' );
$clean1 = false === stripos( $t1['out'], 'fatal' ) && false === stripos( $t1['out'], 'warning' ) && false === stripos( $t1['out'], 'error' );
$clean2 = false === stripos( $t2['out'], 'fatal' ) && false === stripos( $t2['out'], 'warning' ) && false === stripos( $t2['out'], 'error' );
$T->ok( $clean1 && $clean2, '连续激活两次都不报错（幂等）', trim( $t1['out'] ) . ' / ' . trim( $t2['out'] ) );

// 首次激活后会生成随机初始后台密码，并把 bcrypt 哈希写进自建 settings 表。
$seeded = $T->wp_eval(
	'$hash = \CoupleSpace\Settings::admin_password_hash();'
	. ' $opt = (string) \CoupleSpace\Config::option( "initialAdminPassword", "" );'
	. ' echo "hashLen=", strlen( $hash ), " optLen=", strlen( $opt ), " verify=", var_export( password_verify( $opt, $hash ), true ), " src=", \CoupleSpace\Settings::admin_password_source();'
);
$T->ok(
	false !== strpos( $seeded['out'], 'hashLen=60' ) && false !== strpos( $seeded['out'], 'verify=true' ) && false !== strpos( $seeded['out'], 'src=database' ),
	'首次激活即生成初始后台密码并把 bcrypt 哈希写入自建 settings 表',
	trim( $seeded['out'] )
);

// 把初始后台密码设成测试用密码（模拟「用户在后台看到的初始密码」），
// 并立刻重新播种一次，验证「数据库没有哈希时回退到选项里的初始密码」这条链路。
$T->wp_eval(
	'\CoupleSpace\Config::update_options( array( "initialAdminPassword" => ' . var_export( $opts['admin'], true ) . ' ) );'
	. ' \CoupleSpace\Settings::set_admin_password_hash( \CoupleSpace\Auth::hash_admin_password( ' . var_export( $opts['admin'], true ) . ' ) );'
	. ' echo \CoupleSpace\Settings::admin_password_source();'
);
$pwd_ok = $T->req( 'POST', '/admin/api/login', array( 'password' => $opts['admin'] ) );
$T->ok( 200 === $pwd_ok['status'], '初始后台密码可以登录 App 后台（/admin/api/login）', $T->brief( $pwd_ok ) );
$T->db_exec( 'DELETE FROM `' . $T->table( 'rate_limits' ) . '`' );

$tables       = array( 'users', 'couples', 'messages', 'emoji_packs', 'emoji_items', 'sync_objects', 'settings', 'ai_usage', 'admin_logs', 'rate_limits' );
$missing      = array();
$present      = array();
foreach ( $tables as $tbl ) {
	$real = $T->table( $tbl );
	if ( $T->db_scalar( "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='" . $real . "'" ) > 0 ) {
		$present[] = $real;
	} else {
		$missing[] = $real;
	}
}
$T->ok( 0 === count( $missing ), '激活时建齐 10 张自建表（前缀 ' . $T->prefix() . '）', $missing ? ( '缺失：' . implode( ',', $missing ) ) : ( count( $present ) . ' 张齐全' ) );

// 建一个用户，用于验证「停用再启用数据还在」。
$seed = $T->req( 'POST', '/api/auth/register', array( 'username' => $uname( 'keep' ), 'password' => 'pass1234', 'nickname' => '保活' ) );
$seed_ok = 200 === $seed['status'];
$T->ok( $seed_ok, '注册保活用户（用于停用/启用验证）', 'HTTP ' . $seed['status'] );
$keep_name = $uname( 'keep' );

// 数据库里没有哈希时回退到选项里的初始密码：清掉哈希 → 停用再启用 → 自动从选项重新播种。
$T->wp_eval( '\CoupleSpace\Settings::clear_admin_password_hash(); echo \CoupleSpace\Settings::admin_password_source();' );
$reseed = $T->wp_eval(
	'require_once ABSPATH."wp-admin/includes/plugin.php";'
	. ' deactivate_plugins("couple-space/couple-space.php");'
	. ' activate_plugin("couple-space/couple-space.php");'
	. ' echo \CoupleSpace\Settings::admin_password_source(), "|", \CoupleSpace\Settings::admin_password_hash() !== "" ? "hasHash" : "noHash";'
);
$T->ok( false !== strpos( $reseed['out'], 'database|hasHash' ), '数据库里没有哈希时，重新启用会从选项里的初始密码重新播种 bcrypt 哈希', trim( $reseed['out'] ) );
$relogin_initial = $T->req( 'POST', '/admin/api/login', array( 'password' => $opts['admin'] ) );
$T->ok( 200 === $relogin_initial['status'], '重新播种后选项里的初始密码仍可登录', $T->brief( $relogin_initial ) );
$T->db_exec( 'DELETE FROM `' . $T->table( 'rate_limits' ) . '`' );

$deact = $T->wpcli_eval( 'require_once ABSPATH.\'wp-admin/includes/plugin.php\'; deactivate_plugins(\'couple-space/couple-space.php\'); echo is_plugin_active(\'couple-space/couple-space.php\') ? \'ACTIVE\' : \'INACTIVE\';' );
$T->ok( false !== strpos( $deact['out'], 'INACTIVE' ), '停用插件成功', trim( $deact['out'] ) );

$react = $T->wpcli_eval( 'require_once ABSPATH.\'wp-admin/includes/plugin.php\'; var_dump(activate_plugin(\'couple-space/couple-space.php\')); echo is_plugin_active(\'couple-space/couple-space.php\') ? \'ACTIVE\' : \'INACTIVE\';' );
$T->ok( false !== strpos( $react['out'], 'ACTIVE' ), '重新启用插件成功', trim( $react['out'] ) );

$still = $T->db_scalar( 'SELECT COUNT(*) FROM `' . $T->table( 'users' ) . "` WHERE username='" . $keep_name . "'" );
$T->ok( 1 === (int) $still, '停用再启用后数据还在', 'users 行数 = ' . (int) $still );

$login_keep = $T->req( 'POST', '/api/auth/login', array( 'username' => $keep_name, 'password' => 'pass1234' ) );
$T->ok( 200 === $login_keep['status'], '重新启用后保活用户仍能登录', 'HTTP ' . $login_keep['status'] );

// ---------------------------------------------------------------------
// 1. 公开接口 —— 三种地址形式
// ---------------------------------------------------------------------

$T->section( '公开接口（三种地址形式）' );

// 版本号从插件主文件读，不要硬编码（否则每次升版本测试都会误报）
$plugin_version = '1.0.0';
$plugin_src     = (string) @file_get_contents( __DIR__ . '/../couple-space.php' );
if ( preg_match( "/define\(\s*'COUPLE_SPACE_VERSION',\s*'([^']+)'/", $plugin_src, $pv ) ) {
	$plugin_version = $pv[1];
}
$forms = array(
	'伪静态 /api/...'               => array( '/api/health', '/api/meta', '/api/status' ),
	'REST /wp-json/couple/v1/api/...' => array(
		'/wp-json/couple/v1/api/health',
		'/wp-json/couple/v1/api/meta',
		'/wp-json/couple/v1/api/status',
	),
	'兜底 /?couple_api=/api/...'    => array(
		'/?couple_api=/api/health',
		'/?couple_api=/api/meta',
		'/?couple_api=/api/status',
	),
);

foreach ( $forms as $label => $paths ) {
	$health = $T->req( 'GET', $paths[0] );
	$T->ok(
		200 === $health['status'] && isset( $health['json']['ok'] ) && true === $health['json']['ok']
			&& 'couple-server' === $T->get( $health, 'name' ) && $plugin_version === $T->get( $health, 'version' )
			&& is_int( $T->get( $health, 'time' ) ),
		$label . ' → /api/health 字段与 API.md 一致',
		$T->brief( $health )
	);

	$meta = $T->req( 'GET', $paths[1] );
	$meta_ok = 200 === $meta['status']
		&& '情侣空间' === $T->get( $meta, 'appName' )
		&& $plugin_version === $T->get( $meta, 'serverVersion' )
		&& is_int( $T->get( $meta, 'minAppBuild' ) )
		&& is_int( $T->get( $meta, 'latestAppBuild' ) )
		&& is_string( $T->get( $meta, 'updateURL' ) )
		&& is_string( $T->get( $meta, 'announcement' ) )
		&& is_bool( $T->get( $meta, 'registerOpen' ) )
		&& is_array( $T->get( $meta, 'features' ) )
		&& isset( $meta['json']['features']['chat'], $meta['json']['features']['emoji'], $meta['json']['features']['ai'], $meta['json']['features']['sync'] )
		&& is_int( $T->get( $meta, 'serverTime' ) );
	$T->ok( $meta_ok, $label . ' → /api/meta 字段与 API.md 一致', $T->brief( $meta ) );

	$status = $T->req( 'GET', $paths[2] );
	$status_ok = 200 === $status['status']
		&& true === $T->get( $status, 'ok' )
		&& is_int( $T->get( $status, 'users' ) )
		&& is_int( $T->get( $status, 'couples' ) )
		&& is_int( $T->get( $status, 'messages' ) )
		&& true === $T->get( $status, 'dbOk' )
		&& is_int( $T->get( $status, 'serverTime' ) );
	$T->ok( $status_ok, $label . ' → /api/status 字段与 API.md 一致', $T->brief( $status ) );
}

// 三种形式都必须能完成一次需要鉴权的请求。
$probe_form = $T->req( 'POST', '/api/auth/login', array( 'username' => $keep_name, 'password' => 'pass1234' ) );
$probe_tok  = $T->get( $probe_form, 'token' );
$T->ok( is_string( $probe_tok ) && '' !== $probe_tok, '登录可以拿到 token', 'token 长度 ' . strlen( (string) $probe_tok ) );

$via_rest = $T->req( 'GET', '/wp-json/couple/v1/api/auth/me', null, array( 'Authorization' => 'Bearer ' . $probe_tok ) );
$T->ok( 200 === $via_rest['status'] && $keep_name === $T->get( $via_rest, 'user.username' ), 'REST 形式可带 token 访问 /api/auth/me', $T->brief( $via_rest ) );

$via_q = $T->req( 'GET', '/?couple_api=/api/auth/me&token=' . rawurlencode( (string) $probe_tok ) );
$T->ok( 200 === $via_q['status'] && $keep_name === $T->get( $via_q, 'user.username' ), '兜底形式可带 ?token= 访问 /api/auth/me', $T->brief( $via_q ) );

// ---------------------------------------------------------------------
// 2. 注册 / 登录 / 账号
// ---------------------------------------------------------------------

$T->section( '账号（API.md §2）' );

$reg1 = $T->req( 'POST', '/api/auth/register', array( 'username' => $u1, 'password' => 'pass1234', 'nickname' => '小爱', 'avatarEmoji' => '🐻' ) );
$tok1 = $T->get( $reg1, 'token' );
$T->ok(
	200 === $reg1['status'] && is_string( $tok1 ) && '' !== $tok1
	&& $u1 === $T->get( $reg1, 'user.username' ) && '小爱' === $T->get( $reg1, 'user.nickname' )
	&& '🐻' === $T->get( $reg1, 'user.avatarEmoji' ) && null === $T->get( $reg1, 'user.coupleId' )
	&& 'user' === $T->get( $reg1, 'user.role' ) && false === $T->get( $reg1, 'user.disabled' )
	&& is_int( $T->get( $reg1, 'user.createdAt' ) ) && null === $T->get( $reg1, 'couple' )
	&& is_int( $T->get( $reg1, 'serverTime' ) ),
	'注册成功，user/couple/token 字段与 API.md 一致（未绑定时 couple=null）',
	$T->brief( $reg1 )
);

$dup = $T->req( 'POST', '/api/auth/register', array( 'username' => $u1, 'password' => 'pass1234' ) );
$T->ok( 409 === $dup['status'] && 'conflict' === $T->get( $dup, 'error' ), '重复用户名 → 409 conflict', $T->brief( $dup ) );

$bad_user = $T->req( 'POST', '/api/auth/register', array( 'username' => 'ab', 'password' => 'pass1234' ) );
$T->ok( 400 === $bad_user['status'] && 'invalid_input' === $T->get( $bad_user, 'error' ), 'username 少于 3 位 → 400 invalid_input', $T->brief( $bad_user ) );

$bad_user2 = $T->req( 'POST', '/api/auth/register', array( 'username' => 'bad-name!', 'password' => 'pass1234' ) );
$T->ok( 400 === $bad_user2['status'], 'username 含非法字符 → 400 invalid_input', $T->brief( $bad_user2 ) );

$bad_pass = $T->req( 'POST', '/api/auth/register', array( 'username' => $uname( 'short' ), 'password' => '123' ) );
$T->ok( 400 === $bad_pass['status'], 'password 少于 6 位 → 400 invalid_input', $T->brief( $bad_pass ) );

$login_bad = $T->req( 'POST', '/api/auth/login', array( 'username' => $u1, 'password' => 'wrongpass' ) );
$T->ok( 401 === $login_bad['status'] && 'unauthorized' === $T->get( $login_bad, 'error' ), '密码错误 → 401 unauthorized', $T->brief( $login_bad ) );

$login_ok = $T->req( 'POST', '/api/auth/login', array( 'username' => $u1, 'password' => 'pass1234' ) );
$T->ok( 200 === $login_ok['status'] && is_string( $T->get( $login_ok, 'token' ) ), '登录成功返回 token', $T->brief( $login_ok ) );

$me = $T->req( 'GET', '/api/auth/me', null, array( 'Authorization' => 'Bearer ' . $tok1 ) );
$T->ok( 200 === $me['status'] && $u1 === $T->get( $me, 'user.username' ) && is_int( $T->get( $me, 'serverTime' ) ), 'GET /api/auth/me 返回 user/couple/serverTime', $T->brief( $me ) );

$patch = $T->req( 'PATCH', '/api/auth/me', array( 'nickname' => '新昵称', 'avatarEmoji' => '🐰' ), array( 'Authorization' => 'Bearer ' . $tok1 ) );
$T->ok( 200 === $patch['status'] && '新昵称' === $T->get( $patch, 'user.nickname' ) && '🐰' === $T->get( $patch, 'user.avatarEmoji' ), 'PATCH /api/auth/me 改昵称与头像', $T->brief( $patch ) );

$patch_empty = $T->req( 'PATCH', '/api/auth/me', array(), array( 'Authorization' => 'Bearer ' . $tok1 ) );
$T->ok( 400 === $patch_empty['status'], 'PATCH /api/auth/me 不带字段 → 400 invalid_input', $T->brief( $patch_empty ) );

$pwd_bad = $T->req( 'POST', '/api/auth/password', array( 'oldPassword' => 'nope1234', 'newPassword' => 'newpass123' ), array( 'Authorization' => 'Bearer ' . $tok1 ) );
$T->ok( 401 === $pwd_bad['status'], '改密码时原密码错误 → 401 unauthorized', $T->brief( $pwd_bad ) );

$pwd_ok = $T->req( 'POST', '/api/auth/password', array( 'oldPassword' => 'pass1234', 'newPassword' => 'newpass123' ), array( 'Authorization' => 'Bearer ' . $tok1 ) );
$T->ok( 200 === $pwd_ok['status'] && true === $T->get( $pwd_ok, 'ok' ), '改密码成功 → {ok:true}', $T->brief( $pwd_ok ) );

$relogin = $T->req( 'POST', '/api/auth/login', array( 'username' => $u1, 'password' => 'newpass123' ) );
$T->ok( 200 === $relogin['status'], '用新密码可以登录', $T->brief( $relogin ) );

$old_login = $T->req( 'POST', '/api/auth/login', array( 'username' => $u1, 'password' => 'pass1234' ) );
$T->ok( 401 === $old_login['status'], '旧密码已失效', $T->brief( $old_login ) );

// 后续统一用新密码。
$pass1 = 'newpass123';

// ---------------------------------------------------------------------
// 3. 情侣关系
// ---------------------------------------------------------------------

$T->section( '情侣关系（API.md §3）' );

$reg2 = $T->req( 'POST', '/api/auth/register', array( 'username' => $u2, 'password' => 'pass1234', 'nickname' => '小明' ) );
$tok2 = $T->get( $reg2, 'token' );
$T->ok( 200 === $reg2['status'], '注册第二个用户', 'HTTP ' . $reg2['status'] );

$create = $T->req( 'POST', '/api/couple/create', array(), array( 'Authorization' => 'Bearer ' . $tok1 ) );
$code   = $T->get( $create, 'couple.inviteCode' );
$cid    = $T->get( $create, 'couple.id' );
$T->ok(
	200 === $create['status'] && is_string( $code ) && 6 === strlen( (string) $code )
	&& '在一起' === $T->get( $create, 'couple.anniversaryTitle' ) && null === $T->get( $create, 'couple.togetherDate' )
	&& 1 === count( (array) $T->get( $create, 'couple.members' ) ),
	'创建情侣空间，返回 6 位 inviteCode 与 members',
	$T->brief( $create )
);

$create_again = $T->req( 'POST', '/api/couple/create', array(), array( 'Authorization' => 'Bearer ' . $tok1 ) );
$T->ok( 409 === $create_again['status'] && 'conflict' === $T->get( $create_again, 'error' ), '已绑定再创建 → 409 conflict', $T->brief( $create_again ) );

$join_bad = $T->req( 'POST', '/api/couple/join', array( 'inviteCode' => 'ZZZZZZ' ), array( 'Authorization' => 'Bearer ' . $tok2 ) );
$T->ok( 404 === $join_bad['status'] && 'not_found' === $T->get( $join_bad, 'error' ), '无效邀请码 → 404 not_found', $T->brief( $join_bad ) );

$join = $T->req( 'POST', '/api/couple/join', array( 'inviteCode' => strtolower( (string) $code ) ), array( 'Authorization' => 'Bearer ' . $tok2 ) );
$T->ok(
	200 === $join['status'] && 2 === count( (array) $T->get( $join, 'couple.members' ) )
	&& $cid === $T->get( $join, 'couple.id' ),
	'用邀请码加入（大小写不敏感），members 含双方完整 user 对象',
	$T->brief( $join )
);

$reg3 = $T->req( 'POST', '/api/auth/register', array( 'username' => $u3, 'password' => 'pass1234' ) );
$tok3 = $T->get( $reg3, 'token' );
$join_full = $T->req( 'POST', '/api/couple/join', array( 'inviteCode' => $code ), array( 'Authorization' => 'Bearer ' . $tok3 ) );
$T->ok( 409 === $join_full['status'] && 'conflict' === $T->get( $join_full, 'error' ), '空间已满（2 人）→ 409 conflict', $T->brief( $join_full ) );

$reg_full_via_register = $T->req( 'POST', '/api/auth/register', array( 'username' => $uname( 'eve' ), 'password' => 'pass1234', 'inviteCode' => $code ) );
$T->ok( 409 === $reg_full_via_register['status'], '注册时带已满邀请码 → 409 conflict', $T->brief( $reg_full_via_register ) );

$get_couple = $T->req( 'GET', '/api/couple', null, array( 'Authorization' => 'Bearer ' . $tok1 ) );
$T->ok( 200 === $get_couple['status'] && 2 === count( (array) $T->get( $get_couple, 'couple.members' ) ), 'GET /api/couple 返回双方', $T->brief( $get_couple ) );

$patch_couple = $T->req( 'PATCH', '/api/couple', array( 'togetherDate' => 1700000000000, 'anniversaryTitle' => '在一起' ), array( 'Authorization' => 'Bearer ' . $tok1 ) );
$T->ok( 200 === $patch_couple['status'] && 1700000000000 === $T->get( $patch_couple, 'couple.togetherDate' ), 'PATCH /api/couple 改在一起日期', $T->brief( $patch_couple ) );

$patch_couple_bad = $T->req( 'PATCH', '/api/couple', array( 'togetherDate' => 'abc' ), array( 'Authorization' => 'Bearer ' . $tok1 ) );
$T->ok( 400 === $patch_couple_bad['status'], 'togetherDate 非整数 → 400 invalid_input', $T->brief( $patch_couple_bad ) );

$patch_couple_null = $T->req( 'PATCH', '/api/couple', array( 'togetherDate' => null ), array( 'Authorization' => 'Bearer ' . $tok1 ) );
$T->ok( 200 === $patch_couple_null['status'] && null === $T->get( $patch_couple_null, 'couple.togetherDate' ), 'togetherDate 传 null 显式清空', $T->brief( $patch_couple_null ) );

// 恢复一个在一起日期，供后面用例使用。
$T->req( 'PATCH', '/api/couple', array( 'togetherDate' => 1700000000000 ), array( 'Authorization' => 'Bearer ' . $tok1 ) );

$patch_couple_empty = $T->req( 'PATCH', '/api/couple', array(), array( 'Authorization' => 'Bearer ' . $tok1 ) );
$T->ok( 400 === $patch_couple_empty['status'], 'PATCH /api/couple 空请求体 → 400 invalid_input', $T->brief( $patch_couple_empty ) );

$unbound = $T->req( 'GET', '/api/couple', null, array( 'Authorization' => 'Bearer ' . $tok3 ) );
$T->ok( 403 === $unbound['status'] && 'forbidden' === $T->get( $unbound, 'error' ), '未绑定访问 /api/couple → 403 forbidden', $T->brief( $unbound ) );

// ---------------------------------------------------------------------
// 4. 通用数据同步
// ---------------------------------------------------------------------

$T->section( '数据同步（API.md §4）' );

$push = $T->req(
	'POST',
	'/api/sync/push',
	array(
		'objects' => array(
			array( 'kind' => 'wish', 'id' => 'w_' . $stamp . '_1', 'updatedAt' => 1735000000000, 'deleted' => false, 'payload' => array( 'title' => '去海边', 'targetCoins' => 300 ) ),
			array( 'kind' => 'pet', 'id' => 'pet', 'updatedAt' => 1735000001000, 'deleted' => false, 'payload' => array( 'level' => 3 ) ),
		),
	),
	array( 'Authorization' => 'Bearer ' . $tok1 )
);
$T->ok(
	200 === $push['status'] && is_array( $T->get( $push, 'applied' ) ) && 2 === count( (array) $T->get( $push, 'applied' ) )
	&& array() === (array) $T->get( $push, 'conflicts' ) && is_int( $T->get( $push, 'serverTime' ) ),
	'push 两个对象成功，返回 applied/conflicts/serverTime',
	$T->brief( $push )
);

$push_ledger = $T->req( 'POST', '/api/sync/push', array( 'objects' => array( array( 'kind' => 'ledger', 'id' => 'l1', 'updatedAt' => 1735000000000, 'payload' => array() ) ) ), array( 'Authorization' => 'Bearer ' . $tok1 ) );
$T->ok( 400 === $push_ledger['status'] && 'invalid_input' === $T->get( $push_ledger, 'error' ) && false !== strpos( (string) $T->get( $push_ledger, 'message' ), '记账' ), 'push ledger → 400 invalid_input（只存手机本地）', $T->brief( $push_ledger ) );

$push_vault = $T->req( 'POST', '/api/sync/push', array( 'objects' => array( array( 'kind' => 'vault', 'id' => 'v1', 'updatedAt' => 1735000000000, 'payload' => array() ) ) ), array( 'Authorization' => 'Bearer ' . $tok1 ) );
$T->ok( 400 === $push_vault['status'] && 'invalid_input' === $T->get( $push_vault, 'error' ), 'push vault → 400 invalid_input', $T->brief( $push_vault ) );

$pull_ledger = $T->req( 'GET', '/api/sync/pull?kinds=ledger', null, array( 'Authorization' => 'Bearer ' . $tok1 ) );
$T->ok( 400 === $pull_ledger['status'], 'pull kinds=ledger → 400 invalid_input', $T->brief( $pull_ledger ) );

$push_unknown = $T->req( 'POST', '/api/sync/push', array( 'objects' => array( array( 'kind' => 'unknown_kind', 'id' => 'x1', 'updatedAt' => 1735000000000 ) ) ), array( 'Authorization' => 'Bearer ' . $tok1 ) );
$T->ok( 400 === $push_unknown['status'], 'push 未知 kind → 400 invalid_input', $T->brief( $push_unknown ) );

$push_empty = $T->req( 'POST', '/api/sync/push', array( 'objects' => array() ), array( 'Authorization' => 'Bearer ' . $tok1 ) );
$T->ok( 400 === $push_empty['status'], 'push 空数组 → 400 invalid_input', $T->brief( $push_empty ) );

$push_too_many = $T->req( 'POST', '/api/sync/push', array( 'objects' => array_fill( 0, 201, array( 'kind' => 'wish', 'id' => 'w', 'updatedAt' => 1 ) ) ), array( 'Authorization' => 'Bearer ' . $tok1 ) );
$T->ok( 400 === $push_too_many['status'] && false !== strpos( (string) $T->get( $push_too_many, 'message' ), '200' ), 'push 超过 200 个对象 → 400 invalid_input', $T->brief( $push_too_many ) );

// 伴侣用更旧的 updatedAt 推同一个对象 -> 进入 conflicts（LWW）。
$push_lww = $T->req( 'POST', '/api/sync/push', array( 'objects' => array( array( 'kind' => 'wish', 'id' => 'w_' . $stamp . '_1', 'updatedAt' => 1734000000000, 'payload' => array( 'title' => '旧的' ) ) ) ), array( 'Authorization' => 'Bearer ' . $tok2 ) );
$T->ok(
	200 === $push_lww['status'] && in_array( 'w_' . $stamp . '_1', (array) $T->get( $push_lww, 'conflicts' ), true ),
	'LWW：更旧的 updatedAt 被拒并进 conflicts',
	$T->brief( $push_lww )
);

$push_newer = $T->req( 'POST', '/api/sync/push', array( 'objects' => array( array( 'kind' => 'wish', 'id' => 'w_' . $stamp . '_1', 'updatedAt' => 1736000000000, 'payload' => array( 'title' => '新的' ) ) ) ), array( 'Authorization' => 'Bearer ' . $tok2 ) );
$T->ok( 200 === $push_newer['status'] && in_array( 'w_' . $stamp . '_1', (array) $T->get( $push_newer, 'applied' ), true ), 'LWW：更新的 updatedAt 被接受', $T->brief( $push_newer ) );

$pull = $T->req( 'GET', '/api/sync/pull?since=0', null, array( 'Authorization' => 'Bearer ' . $tok1 ) );
$objects  = (array) $T->get( $pull, 'objects' );
$found    = null;
foreach ( $objects as $o ) {
	if ( isset( $o['id'] ) && 'w_' . $stamp . '_1' === $o['id'] ) {
		$found = $o;
	}
}
$T->ok(
	200 === $pull['status'] && null !== $found
	&& isset( $found['kind'], $found['updatedAt'], $found['deleted'], $found['ownerId'], $found['updatedBy'], $found['payload'] )
	&& is_bool( $T->get( $pull, 'hasMore' ) ),
	'pull 返回 objects（含 kind/id/updatedAt/deleted/ownerId/updatedBy/payload）与 hasMore',
	$T->brief( $pull )
);

$pull_kinds = $T->req( 'GET', '/api/sync/pull?since=0&kinds=pet&limit=10', null, array( 'Authorization' => 'Bearer ' . $tok1 ) );
$only_pet = true;
foreach ( (array) $T->get( $pull_kinds, 'objects' ) as $o ) {
	if ( 'pet' !== $o['kind'] ) {
		$only_pet = false;
	}
}
$T->ok( 200 === $pull_kinds['status'] && $only_pet, 'pull kinds=pet 只返回 pet', $T->brief( $pull_kinds ) );

$pull_bad_kind = $T->req( 'GET', '/api/sync/pull?kinds=nope', null, array( 'Authorization' => 'Bearer ' . $tok1 ) );
$T->ok( 400 === $pull_bad_kind['status'], 'pull 非法 kinds → 400 invalid_input', $T->brief( $pull_bad_kind ) );

$pull_limit = $T->req( 'GET', '/api/sync/pull?limit=1001', null, array( 'Authorization' => 'Bearer ' . $tok1 ) );
$T->ok( 400 === $pull_limit['status'], 'pull limit 超过 1000 → 400 invalid_input', $T->brief( $pull_limit ) );

$push_unbound = $T->req( 'POST', '/api/sync/push', array( 'objects' => array( array( 'kind' => 'wish', 'id' => 'w2', 'updatedAt' => 1 ) ) ), array( 'Authorization' => 'Bearer ' . $tok3 ) );
$T->ok( 403 === $push_unbound['status'] && 'forbidden' === $T->get( $push_unbound, 'error' ), '未绑定 push → 403 forbidden', $T->brief( $push_unbound ) );

// ---------------------------------------------------------------------
// 5. 聊天
// ---------------------------------------------------------------------

$T->section( '聊天（API.md §5）' );

$client_id = 'c_' . $stamp . '_1';
$send = $T->req( 'POST', '/api/chat/messages', array( 'clientId' => $client_id, 'kind' => 'text', 'body' => '晚安' ), array( 'Authorization' => 'Bearer ' . $tok1 ) );
$mid  = $T->get( $send, 'message.id' );
$T->ok(
	200 === $send['status'] && is_string( $mid )
	&& $client_id === $T->get( $send, 'message.clientId' ) && $cid === $T->get( $send, 'message.coupleId' )
	&& 'text' === $T->get( $send, 'message.kind' ) && '晚安' === $T->get( $send, 'message.body' )
	&& null === $T->get( $send, 'message.meta' ) && null === $T->get( $send, 'message.readAt' )
	&& is_int( $T->get( $send, 'message.createdAt' ) ),
	'发送消息，消息字段与 API.md 一致',
	$T->brief( $send )
);

$send_again = $T->req( 'POST', '/api/chat/messages', array( 'clientId' => $client_id, 'kind' => 'text', 'body' => '晚安' ), array( 'Authorization' => 'Bearer ' . $tok1 ) );
$T->ok( 200 === $send_again['status'] && $mid === $T->get( $send_again, 'message.id' ), 'clientId 幂等：重复提交返回同一条消息', $T->brief( $send_again ) );

$send_emoji = $T->req( 'POST', '/api/chat/messages', array( 'clientId' => 'c_' . $stamp . '_2', 'kind' => 'emoji', 'body' => 'unicode:😀', 'meta' => array( 'w' => 240, 'h' => 240 ) ), array( 'Authorization' => 'Bearer ' . $tok2 ) );
$T->ok( 200 === $send_emoji['status'] && 240 === $T->get( $send_emoji, 'message.meta.w' ), 'emoji 消息可带 meta 对象', $T->brief( $send_emoji ) );

$send_emoji_bad = $T->req( 'POST', '/api/chat/messages', array( 'clientId' => 'c_' . $stamp . '_3', 'kind' => 'emoji', 'body' => '随便' ), array( 'Authorization' => 'Bearer ' . $tok1 ) );
$T->ok( 400 === $send_emoji_bad['status'], 'emoji 消息 body 格式不对 → 400 invalid_input', $T->brief( $send_emoji_bad ) );

$send_system = $T->req( 'POST', '/api/chat/messages', array( 'clientId' => 'c_' . $stamp . '_4', 'kind' => 'system', 'body' => 'x' ), array( 'Authorization' => 'Bearer ' . $tok1 ) );
$T->ok( 400 === $send_system['status'], '客户端不能直接发 system 消息 → 400 invalid_input', $T->brief( $send_system ) );

$send_long = $T->req( 'POST', '/api/chat/messages', array( 'clientId' => 'c_' . $stamp . '_5', 'kind' => 'text', 'body' => str_repeat( 'a', 2001 ) ), array( 'Authorization' => 'Bearer ' . $tok1 ) );
$T->ok( 400 === $send_long['status'] && false !== strpos( (string) $T->get( $send_long, 'message' ), '2000' ), 'body 超过 2000 字符 → 400 invalid_input', $T->brief( $send_long ) );

$send_2000 = $T->req( 'POST', '/api/chat/messages', array( 'clientId' => 'c_' . $stamp . '_6', 'kind' => 'text', 'body' => str_repeat( 'b', 2000 ) ), array( 'Authorization' => 'Bearer ' . $tok2 ) );
$T->ok( 200 === $send_2000['status'], 'body 恰好 2000 字符 → 允许', $T->brief( $send_2000 ) );

$send_unbound = $T->req( 'POST', '/api/chat/messages', array( 'clientId' => 'c_' . $stamp . '_7', 'kind' => 'text', 'body' => 'hi' ), array( 'Authorization' => 'Bearer ' . $tok3 ) );
$T->ok( 403 === $send_unbound['status'] && 'forbidden' === $T->get( $send_unbound, 'error' ), '未绑定发消息 → 403 forbidden', $T->brief( $send_unbound ) );

$list = $T->req( 'GET', '/api/chat/messages?limit=50', null, array( 'Authorization' => 'Bearer ' . $tok1 ) );
$msgs     = (array) $T->get( $list, 'messages' );
$asc_ok   = true;
$last     = -1;
foreach ( $msgs as $m ) {
	if ( (int) $m['createdAt'] < $last ) {
		$asc_ok = false;
	}
	$last = (int) $m['createdAt'];
}
$T->ok( 200 === $list['status'] && count( $msgs ) > 0 && $asc_ok && is_bool( $T->get( $list, 'hasMore' ) ), '消息列表按 createdAt 升序返回，带 hasMore', count( $msgs ) . ' 条' );

$before   = (int) $msgs[0]['createdAt'];
$list_before = $T->req( 'GET', '/api/chat/messages?before=' . $before . '&limit=5', null, array( 'Authorization' => 'Bearer ' . $tok1 ) );
$before_all_older = true;
foreach ( (array) $T->get( $list_before, 'messages' ) as $m ) {
	if ( (int) $m['createdAt'] >= $before ) {
		$before_all_older = false;
	}
}
$T->ok( 200 === $list_before['status'] && $before_all_older, 'before 分页只返回更早的消息', count( (array) $T->get( $list_before, 'messages' ) ) . ' 条，全部 < ' . $before );

$list_limit = $T->req( 'GET', '/api/chat/messages?limit=2', null, array( 'Authorization' => 'Bearer ' . $tok1 ) );
$T->ok( 200 === $list_limit['status'] && count( (array) $T->get( $list_limit, 'messages' ) ) <= 2 && true === $T->get( $list_limit, 'hasMore' ), 'limit=2 时只返回 2 条且 hasMore=true', $T->brief( $list_limit ) );

$list_bad_limit = $T->req( 'GET', '/api/chat/messages?limit=0', null, array( 'Authorization' => 'Bearer ' . $tok1 ) );
$T->ok( 400 === $list_bad_limit['status'], 'limit=0 → 400 invalid_input', $T->brief( $list_bad_limit ) );

$read_bad = $T->req( 'POST', '/api/chat/read', array( 'upTo' => 'abc' ), array( 'Authorization' => 'Bearer ' . $tok1 ) );
$T->ok( 400 === $read_bad['status'], 'read upTo 非整数 → 400 invalid_input', $T->brief( $read_bad ) );

$list_unbound = $T->req( 'GET', '/api/chat/messages', null, array( 'Authorization' => 'Bearer ' . $tok3 ) );
$T->ok( 403 === $list_unbound['status'], '未绑定拉消息 → 403 forbidden', $T->brief( $list_unbound ) );

$upc = (int) $send['json']['message']['createdAt'];
$read = $T->req( 'POST', '/api/chat/read', array( 'upTo' => $upc ), array( 'Authorization' => 'Bearer ' . $tok2 ) );
$T->ok( 200 === $read['status'] && true === $T->get( $read, 'ok' ) && is_int( $T->get( $read, 'count' ) ) && (int) $T->get( $read, 'count' ) >= 1, '标记已读返回 {ok:true,count}', $T->brief( $read ) );

$read_list = $T->req( 'GET', '/api/chat/messages?limit=50', null, array( 'Authorization' => 'Bearer ' . $tok1 ) );
$read_at_set = false;
foreach ( (array) $T->get( $read_list, 'messages' ) as $m ) {
	if ( $m['id'] === $mid && null !== $m['readAt'] ) {
		$read_at_set = true;
	}
}
$T->ok( $read_at_set, '已读状态落库（readAt 非空）', $read_at_set ? 'readAt 已写入' : 'readAt 仍为空' );

// 撤回：404 / 403 / 24h / 幂等 / 成功
$recall_404 = $T->req( 'DELETE', '/api/chat/messages/m_not_exist_' . $stamp, null, array( 'Authorization' => 'Bearer ' . $tok1 ) );
$T->ok( 404 === $recall_404['status'] && 'not_found' === $T->get( $recall_404, 'error' ), '撤回不存在的消息 → 404 not_found', $T->brief( $recall_404 ) );

$recall_403 = $T->req( 'DELETE', '/api/chat/messages/' . $mid, null, array( 'Authorization' => 'Bearer ' . $tok2 ) );
$T->ok( 403 === $recall_403['status'] && 'forbidden' === $T->get( $recall_403, 'error' ), '撤回别人的消息 → 403 forbidden', $T->brief( $recall_403 ) );

$recall_ok = $T->req( 'DELETE', '/api/chat/messages/' . $mid, null, array( 'Authorization' => 'Bearer ' . $tok1 ) );
$T->ok( 200 === $recall_ok['status'] && true === $T->get( $recall_ok, 'ok' ), '撤回自己的消息（24h 内）→ {ok:true}', $T->brief( $recall_ok ) );

$recall_idem = $T->req( 'DELETE', '/api/chat/messages/' . $mid, null, array( 'Authorization' => 'Bearer ' . $tok1 ) );
$T->ok( 200 === $recall_idem['status'] && true === $T->get( $recall_idem, 'ok' ), '重复撤回幂等返回 {ok:true}', $T->brief( $recall_idem ) );

// 24 小时窗口：把一条消息的 created_at 改到 25 小时前，再撤回。
$old_client = 'c_' . $stamp . '_old';
$old_send   = $T->req( 'POST', '/api/chat/messages', array( 'clientId' => $old_client, 'kind' => 'text', 'body' => '很旧的消息' ), array( 'Authorization' => 'Bearer ' . $tok1 ) );
$old_mid    = (string) $T->get( $old_send, 'message.id' );
$T->db_exec( 'UPDATE `' . $T->table( 'messages' ) . '` SET created_at = created_at - 90000000 WHERE id = ?', array( $old_mid ) );
$recall_old = $T->req( 'DELETE', '/api/chat/messages/' . $old_mid, null, array( 'Authorization' => 'Bearer ' . $tok1 ) );
$T->ok( 403 === $recall_old['status'] && false !== strpos( (string) $T->get( $recall_old, 'message' ), '24' ), '超过 24 小时的消息撤回 → 403 forbidden', $T->brief( $recall_old ) );

// ---------------------------------------------------------------------
// 6. 表情包
// ---------------------------------------------------------------------

$T->section( '表情包（API.md §6）' );

$pack = $T->req( 'POST', '/api/emoji/packs', array( 'name' => '我的表情' ), array( 'Authorization' => 'Bearer ' . $tok1 ) );
$pack_id = (string) $T->get( $pack, 'pack.id' );
$T->ok(
	200 === $pack['status'] && '' !== $pack_id
	&& '我的表情' === $T->get( $pack, 'pack.name' ) && false === $T->get( $pack, 'pack.isPublic' )
	&& array() === (array) $T->get( $pack, 'pack.items' ),
	'创建表情包，返回 pack（items 为空数组）',
	$T->brief( $pack )
);

$png      = E2E_Harness::make_png( 2, 2 );
$upload   = $T->req(
	'POST',
	'/api/emoji/items',
	array(
		'packId'     => $pack_id,
		'mime'       => 'image/png',
		'name'       => '大笑',
		'dataBase64' => base64_encode( $png ),
	),
	array( 'Authorization' => 'Bearer ' . $tok1 )
);
$item_id = (string) $T->get( $upload, 'item.id' );
$T->ok(
	200 === $upload['status'] && '' !== $item_id
	&& 'image/png' === $T->get( $upload, 'item.mime' ) && strlen( $png ) === (int) $T->get( $upload, 'item.size' )
	&& '大笑' === $T->get( $upload, 'item.name' ) && $pack_id === $T->get( $upload, 'item.packId' )
	&& '/api/emoji/blob/' . $item_id === $T->get( $upload, 'item.url' ),
	'上传真实最小 PNG（base64），返回 item（url = /api/emoji/blob/<id>）',
	$T->brief( $upload )
);

$upload_fake = $T->req(
	'POST',
	'/api/emoji/items',
	array(
		'packId'     => $pack_id,
		'mime'       => 'image/png',
		'name'       => '假图',
		'dataBase64' => base64_encode( '这不是图片，只是一段文本内容用于绕过校验' ),
	),
	array( 'Authorization' => 'Bearer ' . $tok1 )
);
$T->ok( 400 === $upload_fake['status'] && false !== strpos( (string) $T->get( $upload_fake, 'message' ), '格式' ), '伪造图片内容（magic bytes 不符）→ 400 invalid_input', $T->brief( $upload_fake ) );

$upload_mismatch = $T->req(
	'POST',
	'/api/emoji/items',
	array(
		'packId'     => $pack_id,
		'mime'       => 'image/jpeg',
		'dataBase64' => base64_encode( $png ),
	),
	array( 'Authorization' => 'Bearer ' . $tok1 )
);
$T->ok( 400 === $upload_mismatch['status'] && false !== strpos( (string) $T->get( $upload_mismatch, 'message' ), '不一致' ), '声明 mime 与实际格式不一致 → 400 invalid_input', $T->brief( $upload_mismatch ) );

$upload_bad_mime = $T->req(
	'POST',
	'/api/emoji/items',
	array( 'packId' => $pack_id, 'mime' => 'image/svg+xml', 'dataBase64' => base64_encode( $png ) ),
	array( 'Authorization' => 'Bearer ' . $tok1 )
);
$T->ok( 400 === $upload_bad_mime['status'], 'mime 不在白名单 → 400 invalid_input', $T->brief( $upload_bad_mime ) );

$upload_bad_b64 = $T->req(
	'POST',
	'/api/emoji/items',
	array( 'packId' => $pack_id, 'mime' => 'image/png', 'dataBase64' => '!!!not base64!!!' ),
	array( 'Authorization' => 'Bearer ' . $tok1 )
);
$T->ok( 400 === $upload_bad_b64['status'], 'dataBase64 非法 → 400 invalid_input', $T->brief( $upload_bad_b64 ) );

$packs = $T->req( 'GET', '/api/emoji/packs', null, array( 'Authorization' => 'Bearer ' . $tok2 ) );
$found_item = null;
foreach ( (array) $T->get( $packs, 'packs' ) as $p ) {
	if ( $p['id'] === $pack_id ) {
		foreach ( (array) $p['items'] as $it ) {
			if ( $it['id'] === $item_id ) {
				$found_item = $it;
			}
		}
	}
}
$T->ok( 200 === $packs['status'] && null !== $found_item, '伴侣能看到对方的表情包与表情（同空间可见）', $T->brief( $packs ) );

$upload_other_pack = $T->req(
	'POST',
	'/api/emoji/items',
	array( 'packId' => $pack_id, 'mime' => 'image/png', 'dataBase64' => base64_encode( $png ) ),
	array( 'Authorization' => 'Bearer' . ' ' . $tok2 )
);
$other_item = (string) $T->get( $upload_other_pack, 'item.id' );
$T->ok( 200 === $upload_other_pack['status'], '伴侣可以往同一个包里上传表情', $T->brief( $upload_other_pack ) );

$blob = $T->req_raw( 'GET', '/api/emoji/blob/' . $item_id, null, array( 'Authorization' => 'Bearer ' . $tok2 ) );
$T->ok(
	200 === $blob['status'] && $png === $blob['body']
	&& false !== stripos( (string) $blob['content_type'], 'image/png' ),
	'blob 返回原图二进制且 Content-Type 为 image/png',
	'HTTP ' . $blob['status'] . ' content-type=' . $blob['content_type'] . ' bytes=' . strlen( $blob['body'] )
);
$T->ok( false !== strpos( (string) $blob['cache_control'], 'immutable' ) && false !== strpos( (string) $blob['cache_control'], '31536000' ), 'blob 带 Cache-Control: public, max-age=31536000, immutable', (string) $blob['cache_control'] );

$blob_qtoken = $T->req_raw( 'GET', '/api/emoji/blob/' . $item_id . '?token=' . rawurlencode( (string) $tok2 ) );
$T->ok( 200 === $blob_qtoken['status'] && $png === $blob_qtoken['body'], 'blob 支持 ?token=<jwt> 鉴权（供 iOS AsyncImage）', 'HTTP ' . $blob_qtoken['status'] );

$blob_noauth = $T->req_raw( 'GET', '/api/emoji/blob/' . $item_id );
$T->ok( 401 === $blob_noauth['status'], 'blob 无凭证 → 401 unauthorized', 'HTTP ' . $blob_noauth['status'] );

$reg4 = $T->req( 'POST', '/api/auth/register', array( 'username' => $u4, 'password' => 'pass1234' ) );
$tok4 = $T->get( $reg4, 'token' );
$blob_other = $T->req_raw( 'GET', '/api/emoji/blob/' . $item_id, null, array( 'Authorization' => 'Bearer ' . $tok4 ) );
$T->ok( 403 === $blob_other['status'], '旁观者访问该表情 blob → 403 forbidden', 'HTTP ' . $blob_other['status'] );

// 路径穿越
$traversal = $T->req( 'DELETE', '/api/emoji/items/e_..%2F..%2Fwp-config', null, array( 'Authorization' => 'Bearer ' . $tok1 ) );
$traversal2 = $T->req( 'GET', '/?couple_api=/api/emoji/blob/e_..%2F..%2F..%2Fwp-config&token=' . rawurlencode( (string) $tok1 ) );
$traversal3 = $T->req_raw( 'GET', '/api/emoji/blob/e_..%2F..%2Fwp-config', null, array( 'Authorization' => 'Bearer ' . $tok1 ) );
$traversal_blocked = in_array( $traversal['status'], array( 400, 403, 404 ), true )
	&& in_array( $traversal2['status'], array( 400, 403, 404 ), true )
	&& in_array( $traversal3['status'], array( 400, 403, 404 ), true );
$T->ok(
	$traversal_blocked,
	'路径穿越（e_..%2F..%2Fwp-config）被拒（400/403/404，绝不 200）',
	'DELETE=' . $traversal['status'] . ' / query形式=' . $traversal2['status'] . ' / blob=' . $traversal3['status']
);

$del_other = $T->req( 'DELETE', '/api/emoji/items/' . $other_item, null, array( 'Authorization' => 'Bearer ' . $tok1 ) );
$T->ok( 403 === $del_other['status'] && false !== strpos( (string) $T->get( $del_other, 'message' ), '自己' ), '删除他人上传的表情 → 403 forbidden', $T->brief( $del_other ) );

$del_404 = $T->req( 'DELETE', '/api/emoji/items/e_zzzznotexist', null, array( 'Authorization' => 'Bearer ' . $tok1 ) );
$T->ok( 404 === $del_404['status'] && 'not_found' === $T->get( $del_404, 'error' ), '删除不存在的表情 → 404 not_found', $T->brief( $del_404 ) );

$del_400 = $T->req( 'DELETE', '/api/emoji/items/bad_id', null, array( 'Authorization' => 'Bearer ' . $tok1 ) );
$T->ok( 400 === $del_400['status'] && 'invalid_input' === $T->get( $del_400, 'error' ), '表情 id 不符合 ^e_[A-Za-z0-9]+$ → 400 invalid_input', $T->brief( $del_400 ) );

$del_own = $T->req( 'DELETE', '/api/emoji/items/' . $item_id, null, array( 'Authorization' => 'Bearer ' . $tok1 ) );
$T->ok( 200 === $del_own['status'] && true === $T->get( $del_own, 'ok' ), '删除自己上传的表情 → {ok:true}', $T->brief( $del_own ) );

$blob_gone = $T->req_raw( 'GET', '/api/emoji/blob/' . $item_id, null, array( 'Authorization' => 'Bearer ' . $tok1 ) );
$T->ok( 404 === $blob_gone['status'], '删掉后 blob → 404 not_found', 'HTTP ' . $blob_gone['status'] );

$pack2       = $T->req( 'POST', '/api/emoji/packs', array( 'name' => '小红的表情' ), array( 'Authorization' => 'Bearer ' . $tok2 ) );
$pack2_id    = (string) $T->get( $pack2, 'pack.id' );
$del_pack_other = $T->req( 'DELETE', '/api/emoji/packs/' . $pack2_id, null, array( 'Authorization' => 'Bearer ' . $tok1 ) );
$T->ok( 403 === $del_pack_other['status'], '删除伴侣创建的表情包 → 403 forbidden', $T->brief( $del_pack_other ) );

$del_pack_404 = $T->req( 'DELETE', '/api/emoji/packs/p_notexist' . $stamp, null, array( 'Authorization' => 'Bearer ' . $tok1 ) );
$T->ok( 404 === $del_pack_404['status'], '删除不存在的表情包 → 404 not_found', $T->brief( $del_pack_404 ) );

$del_pack_own = $T->req( 'DELETE', '/api/emoji/packs/' . $pack2_id, null, array( 'Authorization' => 'Bearer ' . $tok2 ) );
$T->ok( 200 === $del_pack_own['status'] && true === $T->get( $del_pack_own, 'ok' ), '删除自己创建的表情包 → {ok:true}', $T->brief( $del_pack_own ) );

$gif = "\x47\x49\x46\x38\x39\x61\x01\x00\x01\x00\x00\x00\x00\x21\xf9\x04\x00\x00\x00\x00\x00\x2c\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02\x44\x01\x00\x3b";
$upload_gif = $T->req( 'POST', '/api/emoji/items', array( 'packId' => $pack_id, 'mime' => 'image/gif', 'name' => 'gif', 'dataBase64' => base64_encode( $gif ) ), array( 'Authorization' => 'Bearer ' . $tok1 ) );
$T->ok( 200 === $upload_gif['status'] && 'image/gif' === $T->get( $upload_gif, 'item.mime' ), 'GIF 也可上传（magic bytes 识别为 image/gif）', $T->brief( $upload_gif ) );

$file_dir = $T->wp_eval( 'echo \CoupleSpace\EmojiStore::base_dir();' );
$T->ok( false !== strpos( $file_dir['out'], 'uploads' ) && false !== strpos( $file_dir['out'], 'couple-emoji' ), '表情图片存放在 wp-content/uploads/couple-emoji/', trim( $file_dir['out'] ) );

// 表情包上限（后台可调）：把上限调到 1，占满后再创建应返回 409。
$limit_pack_dir = $T->wp_eval( '\CoupleSpace\Config::update_options( array() ); echo "ok";' );
$T->db_exec( 'UPDATE `' . $T->table( 'settings' ) . "` SET `value`='1' WHERE `key`='maxEmojiPacks'" );
$T->wp_eval( '\CoupleSpace\Settings::flush(); echo "flushed";' );
$pack_over = $T->req( 'POST', '/api/emoji/packs', array( 'name' => '超限包' ), array( 'Authorization' => 'Bearer ' . $tok1 ) );
$T->ok(
	409 === $pack_over['status'] && 'conflict' === $T->get( $pack_over, 'error' ) && false !== strpos( (string) $T->get( $pack_over, 'message' ), '上限' ),
	'表情包数量达到后台设置的上限 → 409 conflict',
	$T->brief( $pack_over )
);
$T->db_exec( 'UPDATE `' . $T->table( 'settings' ) . "` SET `value`='30' WHERE `key`='maxEmojiPacks'" );

// 同一情侣空间内，未绑定用户看不到任何自建包。
$packs_outsider = $T->req( 'GET', '/api/emoji/packs', null, array( 'Authorization' => 'Bearer ' . $tok4 ) );
$T->ok( 403 === $packs_outsider['status'], '未绑定用户拉表情包 → 403 forbidden', $T->brief( $packs_outsider ) );

// ---------------------------------------------------------------------
// 7. AI
// ---------------------------------------------------------------------

$T->section( 'AI（API.md §7）' );

$ai_status = $T->req( 'GET', '/api/ai/status', null, array( 'Authorization' => 'Bearer ' . $tok1 ) );
$T->ok(
	200 === $ai_status['status'] && is_bool( $T->get( $ai_status, 'enabled' ) ) && is_bool( $T->get( $ai_status, 'configured' ) )
	&& is_string( $T->get( $ai_status, 'model' ) ) && is_int( $T->get( $ai_status, 'dailyLimit' ) ) && is_int( $T->get( $ai_status, 'usedToday' ) ),
	'GET /api/ai/status 字段与 API.md 一致',
	$T->brief( $ai_status )
);

$ai_config = $T->db_scalar( "SELECT `value` FROM `" . $T->table( 'settings' ) . "` WHERE `key`='deepseekApiKey'" );
$not_configured = ( '' === (string) $ai_config || '""' === (string) $ai_config );

$ai_parse = $T->req( 'POST', '/api/ai/parse-ledger', array( 'text' => '今天午饭25', 'currency' => 'CNY', 'today' => date( 'Y-m-d' ) ), array( 'Authorization' => 'Bearer ' . $tok1 ) );
if ( $not_configured ) {
	$T->ok( 403 === $ai_parse['status'] && 'forbidden' === $T->get( $ai_parse, 'error' ) && 'AI 未配置' === $T->get( $ai_parse, 'message' ), '未配置 Key → 403 forbidden「AI 未配置」（不是 500）', $T->brief( $ai_parse ) );
} else {
	$T->ok( in_array( $ai_parse['status'], array( 200, 403, 429, 502, 504 ), true ), '已配置 Key 时 AI 返回受控结果（非 500）', $T->brief( $ai_parse ) );
}

$ai_parse_empty = $T->req( 'POST', '/api/ai/parse-ledger', array( 'text' => '' ), array( 'Authorization' => 'Bearer ' . $tok1 ) );
$T->ok( 400 === $ai_parse_empty['status'] && 'invalid_input' === $T->get( $ai_parse_empty, 'error' ), 'parse-ledger text 为空 → 400 invalid_input', $T->brief( $ai_parse_empty ) );

$ai_parse_long = $T->req( 'POST', '/api/ai/parse-ledger', array( 'text' => str_repeat( 'a', 2001 ) ), array( 'Authorization' => 'Bearer ' . $tok1 ) );
$T->ok( 400 === $ai_parse_long['status'], 'parse-ledger text 超过 2000 → 400 invalid_input', $T->brief( $ai_parse_long ) );

$ai_parse_date = $T->req( 'POST', '/api/ai/parse-ledger', array( 'text' => '午饭25', 'today' => '2025-13-45' ), array( 'Authorization' => 'Bearer ' . $tok1 ) );
$T->ok( 400 === $ai_parse_date['status'], 'parse-ledger today 非法日期 → 400 invalid_input', $T->brief( $ai_parse_date ) );

$ai_report_bad = $T->req( 'POST', '/api/ai/monthly-report', array( 'month' => '2025-6', 'totals' => array( 'expense' => 1, 'income' => 0 ) ), array( 'Authorization' => 'Bearer ' . $tok1 ) );
$T->ok( 400 === $ai_report_bad['status'], 'monthly-report month 格式错 → 400 invalid_input', $T->brief( $ai_report_bad ) );

$ai_report_totals = $T->req( 'POST', '/api/ai/monthly-report', array( 'month' => '2025-06', 'totals' => 'x' ), array( 'Authorization' => 'Bearer ' . $tok1 ) );
$T->ok( 400 === $ai_report_totals['status'], 'monthly-report totals 非对象 → 400 invalid_input', $T->brief( $ai_report_totals ) );

$ai_report_neg = $T->req( 'POST', '/api/ai/monthly-report', array( 'month' => '2025-06', 'totals' => array( 'expense' => -1, 'income' => 0 ) ), array( 'Authorization' => 'Bearer ' . $tok1 ) );
$T->ok( 400 === $ai_report_neg['status'], 'monthly-report 负数 → 400 invalid_input', $T->brief( $ai_report_neg ) );

// DeepSeek 调用走 WordPress 的 wp_remote_post（源码级验证）
$wp_http = $T->wp_eval( 'echo strpos(file_get_contents(\'' . str_replace( '\\', '\\\\', __DIR__ . '/../includes/DeepSeek.php' ) . '\'), "wp_remote_post") !== false ? "wp_remote_post" : "MISSING";' );
$T->ok( false !== strpos( $wp_http['out'], 'wp_remote_post' ), 'DeepSeek 调用使用 wp_remote_post（未引入第三方库）', trim( $wp_http['out'] ) );

// ---------------------------------------------------------------------
// 8. App 后台接口
// ---------------------------------------------------------------------

$T->section( 'App 后台接口（API.md §9）' );

$admin_routes = array(
	array( 'GET', '/admin/api/settings' ),
	array( 'PUT', '/admin/api/settings' ),
	array( 'GET', '/admin/api/stats' ),
	array( 'GET', '/admin/api/users' ),
	array( 'GET', '/admin/api/packs' ),
	array( 'GET', '/admin/api/logs' ),
	array( 'GET', '/admin/api/system' ),
	array( 'POST', '/admin/api/ai/test' ),
);
$all_401 = true;
$statuses = array();
foreach ( $admin_routes as $ar ) {
	$res = $T->req( $ar[0], $ar[1] );
	$statuses[] = $ar[1] . '=' . $res['status'];
	if ( 401 !== $res['status'] ) {
		$all_401 = false;
	}
}
$T->ok( $all_401, '后台接口未授权一律 401 unauthorized', implode( ' ', $statuses ) );

$login_admin = $T->req( 'POST', '/admin/api/login', array( 'password' => $opts['admin'] ) );
$admin_tok   = (string) $T->get( $login_admin, 'token' );
$T->ok(
	200 === $login_admin['status'] && '' !== $admin_tok,
	'后台登录成功返回 admin token',
	$T->brief( $login_admin )
	. ' rl=' . ( isset( $login_admin['headers']['x-ratelimit-remaining'] ) ? $login_admin['headers']['x-ratelimit-remaining'] : '?' )
	. ' hashLen=' . strlen( (string) $T->db_scalar( "SELECT `value` FROM `" . $T->table( 'settings' ) . "` WHERE `key`='adminPasswordHash'" ) )
);

$login_wrong = $T->req( 'POST', '/admin/api/login', array( 'password' => 'definitely-wrong-password' ) );
$T->ok( 401 === $login_wrong['status'] && 'unauthorized' === $T->get( $login_wrong, 'error' ), '后台密码错误 → 401 unauthorized', $T->brief( $login_wrong ) );

$ah = array( 'Authorization' => 'Bearer ' . $admin_tok );

$settings = $T->req( 'GET', '/admin/api/settings', null, $ah );
$T->ok(
	200 === $settings['status'] && array_key_exists( 'deepseekApiKeyMasked', $settings['json'] )
	&& array_key_exists( 'deepseekBaseUrl', $settings['json'] ) && array_key_exists( 'deepseekModel', $settings['json'] )
	&& array_key_exists( 'aiEnabled', $settings['json'] ) && array_key_exists( 'aiDailyLimit', $settings['json'] )
	&& array_key_exists( 'registerOpen', $settings['json'] ) && array_key_exists( 'announcement', $settings['json'] )
	&& array_key_exists( 'minAppBuild', $settings['json'] ) && array_key_exists( 'latestAppBuild', $settings['json'] )
	&& array_key_exists( 'updateURL', $settings['json'] ) && array_key_exists( 'maxEmojiPacks', $settings['json'] )
	&& array_key_exists( 'maxMessagesPerDay', $settings['json'] ),
	'GET /admin/api/settings 返回全部字段（与 API.md §9 一致）',
	$T->brief( $settings )
);

$fake_key = 'sk-wordpresspluginTESTKEY000000000000abcd';
$put = $T->req( 'PUT', '/admin/api/settings', array( 'deepseekApiKey' => $fake_key, 'deepseekModel' => 'deepseek-chat', 'aiEnabled' => true, 'aiDailyLimit' => 123, 'registerOpen' => true, 'announcement' => '欢迎使用（WP 插件测试）', 'minAppBuild' => 1, 'latestAppBuild' => 9, 'updateURL' => 'https://example.com/dl', 'maxEmojiPacks' => 31, 'maxMessagesPerDay' => 2999 ), $ah );
$T->ok( 200 === $put['status'] && true === $T->get( $put, 'ok' ) && is_array( $T->get( $put, 'settings' ) ), 'PUT /admin/api/settings 写入设置并回吐 settings', $T->brief( $put ) );

$put_body = $T->raw_body_of_last();
$T->ok( false === strpos( $put_body, $fake_key ), 'PUT 响应里不含 DeepSeek Key 明文', '已检查响应体 ' . strlen( $put_body ) . ' 字节' );

$settings2 = $T->req( 'GET', '/admin/api/settings', null, $ah );
$masked    = (string) $T->get( $settings2, 'deepseekApiKeyMasked' );
$T->ok(
	'' !== $masked && false === strpos( $masked, $fake_key )
	&& 123 === (int) $T->get( $settings2, 'aiDailyLimit' ) && 9 === (int) $T->get( $settings2, 'latestAppBuild' )
	&& 31 === (int) $T->get( $settings2, 'maxEmojiPacks' ) && 2999 === (int) $T->get( $settings2, 'maxMessagesPerDay' ),
	'Key 只返回掩码，其余设置已生效',
	'masked=' . $masked
);

$hash_row = (string) $T->db_scalar( "SELECT `value` FROM `" . $T->table( 'settings' ) . "` WHERE `key`='adminPasswordHash'" );
$T->ok( '' !== $hash_row && false === strpos( $T->raw_body_of_last(), $hash_row ), '响应里不含后台密码哈希', '哈希长度 ' . strlen( $hash_row ) );

$enc_row = (string) $T->db_scalar( "SELECT `value` FROM `" . $T->table( 'settings' ) . "` WHERE `key`='deepseekApiKey'" );
$T->ok( false !== strpos( $enc_row, 'enc:v1:' ) && false === strpos( $enc_row, $fake_key ), 'DeepSeek Key 以 AES-256-GCM 密文入库（enc:v1: 前缀）', substr( $enc_row, 0, 24 ) . '...' );

$reg_closed = $T->req( 'PUT', '/admin/api/settings', array( 'registerOpen' => false ), $ah );
$T->ok( 200 === $reg_closed['status'] && false === $T->get( $reg_closed, 'settings.registerOpen' ), '关闭注册后 settings.registerOpen=false', $T->brief( $reg_closed ) );
$reg_blocked = $T->req( 'POST', '/api/auth/register', array( 'username' => $uname( 'blocked' ), 'password' => 'pass1234' ) );
$T->ok( 403 === $reg_blocked['status'] && 'forbidden' === $T->get( $reg_blocked, 'error' ), '关闭注册后注册 → 403 forbidden', $T->brief( $reg_blocked ) );
$T->req( 'PUT', '/admin/api/settings', array( 'registerOpen' => true ), $ah );

$put_empty = $T->req( 'PUT', '/admin/api/settings', array(), $ah );
$T->ok( 400 === $put_empty['status'], 'PUT /admin/api/settings 空请求体 → 400 invalid_input', $T->brief( $put_empty ) );

$ai_test = $T->req( 'POST', '/admin/api/ai/test', array( 'text' => '今天午饭25' ), $ah );
$T->ok( in_array( $ai_test['status'], array( 200, 403, 502, 504 ), true ) && 500 !== $ai_test['status'], 'POST /admin/api/ai/test 返回受控结果（未配置 Key 时 403 forbidden）', $T->brief( $ai_test ) );

$ai_test_tmp = $T->req( 'POST', '/admin/api/ai/test', array( 'text' => '午饭25', 'deepseekApiKey' => 'sk-temporary-invalid-key-0000' ), $ah );
$T->ok( in_array( $ai_test_tmp['status'], array( 200, 403, 502, 504 ), true ) && 500 !== $ai_test_tmp['status'], 'AI 测试支持临时 Key（失败也是受控错误）', $T->brief( $ai_test_tmp ) );

$stats = $T->req( 'GET', '/admin/api/stats', null, $ah );
$T->ok(
	200 === $stats['status'] && is_int( $T->get( $stats, 'users' ) ) && is_int( $T->get( $stats, 'couples' ) )
	&& is_int( $T->get( $stats, 'messages' ) ) && is_int( $T->get( $stats, 'aiToday' ) )
	&& is_array( $T->get( $stats, 'disk' ) ),
	'GET /admin/api/stats 返回用户/情侣/消息/AI/磁盘占用',
	$T->brief( $stats )
);

$users_admin = $T->req( 'GET', '/admin/api/users?query=' . rawurlencode( $u1 ) . '&page=1', null, $ah );
$found_user  = null;
foreach ( (array) $T->get( $users_admin, 'users' ) as $uu ) {
	if ( $uu['username'] === $u1 ) {
		$found_user = $uu;
	}
}
$T->ok( 200 === $users_admin['status'] && null !== $found_user && is_int( $T->get( $users_admin, 'total' ) ), 'GET /admin/api/users 搜索用户', $T->brief( $users_admin ) );

$packs_admin = $T->req( 'GET', '/admin/api/packs', null, $ah );
$T->ok( 200 === $packs_admin['status'] && is_int( $T->get( $packs_admin, 'totalPacks' ) ) && is_int( $T->get( $packs_admin, 'totalItems' ) ), 'GET /admin/api/packs 返回表情包统计', $T->brief( $packs_admin ) );

// 用户管理动作
$target = $T->req( 'POST', '/api/auth/register', array( 'username' => $uname( 'target' ), 'password' => 'pass1234' ) );
$target_id = (string) $T->get( $target, 'user.id' );
$T->ok( 200 === $target['status'] && '' !== $target_id, '创建用于用户管理动作的账号', 'id=' . $target_id );

$reset = $T->req( 'POST', '/admin/api/users/' . $target_id . '/reset-password', array( 'newPassword' => 'resetpass99' ), $ah );
$T->ok( 200 === $reset['status'] && true === $T->get( $reset, 'ok' ), '重置用户密码 → {ok:true}', $T->brief( $reset ) );
$reset_login = $T->req( 'POST', '/api/auth/login', array( 'username' => $uname( 'target' ), 'password' => 'resetpass99' ) );
$T->ok( 200 === $reset_login['status'], '重置后的新密码可以登录', 'HTTP ' . $reset_login['status'] );

$toggle = $T->req( 'POST', '/admin/api/users/' . $target_id . '/toggle-disabled', array( 'disabled' => true ), $ah );
$T->ok( 200 === $toggle['status'] && true === $T->get( $toggle, 'user.disabled' ), '禁用用户 → user.disabled=true', $T->brief( $toggle ) );
$disabled_login = $T->req( 'POST', '/api/auth/login', array( 'username' => $uname( 'target' ), 'password' => 'resetpass99' ) );
$T->ok( 403 === $disabled_login['status'] && false !== strpos( (string) $T->get( $disabled_login, 'message' ), '禁用' ), '被禁用账号登录 → 403 forbidden', $T->brief( $disabled_login ) );

$toggle_off = $T->req( 'POST', '/admin/api/users/' . $target_id . '/toggle-disabled', array( 'disabled' => false ), $ah );
$T->ok( 200 === $toggle_off['status'] && false === $T->get( $toggle_off, 'user.disabled' ), '启用用户 → user.disabled=false', $T->brief( $toggle_off ) );

$toggle_bad = $T->req( 'POST', '/admin/api/users/' . $target_id . '/toggle-disabled', array( 'disabled' => 'yes' ), $ah );
$T->ok( 400 === $toggle_bad['status'], 'toggle-disabled 非布尔 → 400 invalid_input', $T->brief( $toggle_bad ) );

$reset_404 = $T->req( 'POST', '/admin/api/users/u_nobody/reset-password', array( 'newPassword' => 'abcdefgh' ), $ah );
$T->ok( 404 === $reset_404['status'], '对不存在用户重置密码 → 404 not_found', $T->brief( $reset_404 ) );

$del_user = $T->req( 'DELETE', '/admin/api/users/' . $target_id, null, $ah );
$T->ok( 200 === $del_user['status'] && true === $T->get( $del_user, 'ok' ), '删除用户 → {ok:true}', $T->brief( $del_user ) );
$del_user_again = $T->req( 'DELETE', '/admin/api/users/' . $target_id, null, $ah );
$T->ok( 404 === $del_user_again['status'], '再次删除同一用户 → 404 not_found', $T->brief( $del_user_again ) );

// 后台密码修改 / 重置
$pwd_wrong = $T->req( 'POST', '/admin/api/admin-password', array( 'currentPassword' => 'wrong-old', 'newPassword' => 'brandnew123' ), $ah );
$T->ok( 401 === $pwd_wrong['status'], '改后台密码时当前密码错 → 401 unauthorized', $T->brief( $pwd_wrong ) );

$pwd_change = $T->req( 'POST', '/admin/api/admin-password', array( 'currentPassword' => $opts['admin'], 'newPassword' => 'wpnewpass123' ), $ah );
$T->ok(
	200 === $pwd_change['status'] && true === $T->get( $pwd_change, 'ok' )
	&& 'database' === $T->get( $pwd_change, 'adminPasswordSource' ),
	'修改后台密码成功（存入数据库，返回 adminPasswordSource=database）',
	$T->brief( $pwd_change )
);
$T->ok( false === strpos( $T->raw_body_of_last(), 'wpnewpass123' ) && false === strpos( $T->raw_body_of_last(), '$2y$' ), '改密码响应里不含明文/哈希', '已检查响应体' );

$old_admin_login = $T->req( 'POST', '/admin/api/login', array( 'password' => $opts['admin'] ) );
$T->ok( 401 === $old_admin_login['status'], '改完密码后旧后台密码失效', $T->brief( $old_admin_login ) );

$new_admin_login = $T->req( 'POST', '/admin/api/login', array( 'password' => 'wpnewpass123' ) );
$admin_tok2      = (string) $T->get( $new_admin_login, 'token' );
$T->ok( 200 === $new_admin_login['status'] && '' !== $admin_tok2, '用新后台密码登录成功', $T->brief( $new_admin_login ) );

$ah2 = array( 'Authorization' => 'Bearer ' . $admin_tok2 );
$logs = $T->req( 'GET', '/admin/api/logs?limit=100', null, $ah2 );
$actions = array();
foreach ( (array) $T->get( $logs, 'logs' ) as $lg ) {
	$actions[] = $lg['action'];
}
$need = array( 'settings.update', 'user.reset_password', 'user.delete', 'admin.password.changed' );
$missing_actions = array();
foreach ( $need as $n ) {
	if ( ! in_array( $n, $actions, true ) ) {
		$missing_actions[] = $n;
	}
}
$T->ok(
	200 === $logs['status'] && 0 === count( $missing_actions ),
	'GET /admin/api/logs 记录了设置变更/用户管理/改密码等操作',
	$missing_actions ? ( '缺少：' . implode( ',', $missing_actions ) ) : ( count( $actions ) . ' 条日志' )
);

// 通过 CLI 等价路径重置回测试用密码（也顺带验证数据库哈希路径）。
$set_pwd = $T->wp_eval( 'require_once ABSPATH.\'wp-admin/includes/plugin.php\'; \CoupleSpace\Settings::set_admin_password_hash( \CoupleSpace\Auth::hash_admin_password( ' . var_export( $opts['admin'], true ) . ' ) ); echo \CoupleSpace\Settings::admin_password_source();' );
$T->ok( false !== strpos( $set_pwd['out'], 'database' ), 'WP-CLI / 代码路径可设置后台密码哈希（admin_password_source=database）', trim( $set_pwd['out'] ) );
$restored = $T->req( 'POST', '/admin/api/login', array( 'password' => $opts['admin'] ) );
$T->ok( 200 === $restored['status'], '重置回测试密码后可以登录', $T->brief( $restored ) );

$system = $T->req( 'GET', '/admin/api/system', null, $ah );
$T->ok(
	200 === $system['status'] && is_array( $T->get( $system, 'database' ) )
	&& false === $T->get( $system, 'websocket.supported' )
	&& is_array( $T->get( $system, 'appServerUrls' ) ),
	'GET /admin/api/system 含数据库信息、WebSocket 不支持说明与 App 服务器地址',
	$T->brief( $system )
);

// ---------------------------------------------------------------------
// 9. 通用错误与安全
// ---------------------------------------------------------------------

$T->section( '通用错误与安全' );

$unknown = $T->req( 'GET', '/api/definitely-not-a-route' );
$T->ok( 404 === $unknown['status'] && 'not_found' === $T->get( $unknown, 'error' ), '未知路径 → 404 not_found', $T->brief( $unknown ) );

$unknown_post = $T->req( 'POST', '/api/auth/nope' );
$T->ok( 404 === $unknown_post['status'], '未知 POST 路径 → 404 not_found', $T->brief( $unknown_post ) );

$no_token = $T->req( 'GET', '/api/auth/me' );
$T->ok( 401 === $no_token['status'] && 'unauthorized' === $T->get( $no_token, 'error' ), '无 token → 401 unauthorized', $T->brief( $no_token ) );

$bad_token = $T->req( 'GET', '/api/auth/me', null, array( 'Authorization' => 'Bearer not.a.jwt' ) );
$T->ok( 401 === $bad_token['status'], '非法 token → 401 unauthorized', $T->brief( $bad_token ) );

$bad_json = $T->req_raw( 'POST', '/api/auth/login', '{"username": "x", ', array( 'Content-Type' => 'application/json' ) );
$T->ok( 400 === $bad_json['status'] && false !== strpos( $bad_json['body'], 'invalid_input' ), '非法 JSON 请求体 → 400 invalid_input', 'HTTP ' . $bad_json['status'] . ' ' . substr( $bad_json['body'], 0, 90 ) );

$wrong_method = $T->req( 'DELETE', '/api/health' );
$T->ok( 404 === $wrong_method['status'], '方法不匹配 → 404 not_found', $T->brief( $wrong_method ) );

$no_store = $T->req( 'GET', '/api/health' );
$T->ok( false !== stripos( (string) $no_store['cache_control'], 'no-store' ), '接口响应带 Cache-Control: no-store', (string) $no_store['cache_control'] );

// 后台密码与密钥绝不出现任何 API 响应里
$all_probe = array( $T->req( 'GET', '/api/health' ), $T->req( 'GET', '/api/meta' ), $T->req( 'GET', '/api/status' ), $T->req( 'GET', '/api/ai/status', null, array( 'Authorization' => 'Bearer ' . $tok1 ) ) );
$leak = false;
foreach ( $all_probe as $pr ) {
	if ( false !== strpos( $pr['body'], $opts['admin'] ) || false !== strpos( $pr['body'], 'adminPasswordHash' ) ) {
		$leak = true;
	}
}
$T->ok( ! $leak, '公开接口不泄漏后台密码与密码哈希', $leak ? '发现泄漏！' : '未发现泄漏' );

// ---------------------------------------------------------------------
// 10. 解绑（放在最后，避免影响前面的用例）
// ---------------------------------------------------------------------

$T->section( '解绑（API.md §3）' );

$leave = $T->req( 'POST', '/api/couple/leave', array(), array( 'Authorization' => 'Bearer ' . $tok1 ) );
$T->ok( 200 === $leave['status'] && true === $T->get( $leave, 'ok' ), '解绑 → {ok:true}', $T->brief( $leave ) );

$after = $T->req( 'GET', '/api/auth/me', null, array( 'Authorization' => 'Bearer ' . $tok1 ) );
$T->ok( null === $T->get( $after, 'user.coupleId' ) && null === $T->get( $after, 'couple' ), '解绑后双方 coupleId 置空', $T->brief( $after ) );

$after2 = $T->req( 'GET', '/api/auth/me', null, array( 'Authorization' => 'Bearer ' . $tok2 ) );
$T->ok( null === $T->get( $after2, 'user.coupleId' ), '伴侣的 coupleId 也置空', $T->brief( $after2 ) );

$join_after = $T->req( 'POST', '/api/couple/join', array( 'inviteCode' => $code ), array( 'Authorization' => 'Bearer ' . $tok3 ) );
$T->ok( 404 === $join_after['status'] && 'not_found' === $T->get( $join_after, 'error' ), '解绑后原邀请码失效 → 404 not_found', $T->brief( $join_after ) );

$chat_after = $T->req( 'GET', '/api/chat/messages', null, array( 'Authorization' => 'Bearer ' . $tok1 ) );
$T->ok( 403 === $chat_after['status'], '解绑后聊天 → 403 forbidden', $T->brief( $chat_after ) );

$recreate = $T->req( 'POST', '/api/couple/create', array(), array( 'Authorization' => 'Bearer ' . $tok1 ) );
$T->ok( 200 === $recreate['status'], '解绑后可以重新创建情侣空间', $T->brief( $recreate ) );

// ---------------------------------------------------------------------
// 报告
// ---------------------------------------------------------------------

$T->finish( __DIR__ . '/report.txt' );
exit( $T->failed() > 0 ? 1 : 0 );
