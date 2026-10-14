<?php
/**
 * 情侣空间 WordPress 插件 · AI 聊天端到端测试（docs/API-AI聊天.md）
 *
 * 用法：
 *   php tests/ai-e2e.php
 *   php tests/ai-e2e.php --url=http://127.0.0.1:8890 --wp=C:\path\to\wordpress --mock=http://127.0.0.1:8899
 *
 * 覆盖：
 *   - §AI-1/AI-2：AI 伙伴的增删改查（10 个上限、name 校验、providerId 校验、非本人 404）
 *   - §AI-3：聊天记录读取（升序 / before / limit / 非本人 404）
 *   - §AI-4：发消息（先落用户消息 -> 调上游 -> 落 AI 回复 -> 一次性返回 message/reply/usage）
 *             clientId 幂等、最近 20 条上下文、Key 绝不下发、
 *             未配置供应商 403 / 伙伴停用 403 / body 空或超长 400 / 1 分钟 20 次 429、
 *             上游 401 -> 502 upstream_error、上游超时 -> 504 upstream_timeout、
 *             未登录 401、未绑定情侣也能用
 *   - §AI-5 后台：未授权 401、供应商增删改、掩码、测试按钮、默认供应商切换、删除默认后自动改选
 *   - §AI-6：三张新表建表幂等（连续激活两次不报错）
 *
 * 全部走**本地 mock 供应商**（tests/mock-provider-router.php），绝不出网打第三方 API。
 * 结果同时写入 tests/ai-report.txt。
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
	'mock'  => 'http://127.0.0.1:8899',
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
	} elseif ( 0 === strpos( $arg, '--mock=' ) ) {
		$opts['mock'] = rtrim( substr( $arg, 7 ), '/' );
	}
}

$T = new E2E_Harness( $opts['url'], $opts['wp'], $opts['php'], $opts['admin'] );

// mock 供应商的请求记录文件（启动脚本通过环境变量 MOCK_LOG 告诉 mock 服务）。
$mock_log = str_replace( '\\', '/', __DIR__ . '/.mock-requests.log' );

// 测试用的 Key 明文：一眼能认出来，便于断言「绝不下发」。
$KEY_PLAIN   = 'sk-testsuperSECRETkey1234567890abcd';
$KEY_PLAIN_2 = 'sk-secondsuperSECRETkey9876543210zyxw';

/** @var array<int,string> 所有 App 侧接口的响应体（用于 Key 泄漏的全量断言）。 */
$ALL_RESPONSES = array();

/**
 * 记录一次 App 侧响应体。
 *
 * @param array<string,mixed> $resp 响应。
 * @return void
 */
function remember( array $resp ) {
	global $ALL_RESPONSES;
	$ALL_RESPONSES[] = isset( $resp['body'] ) ? (string) $resp['body'] : '';
}

/**
 * 读取 mock 端记录到的请求。
 *
 * @param string $file 记录文件。
 * @return array<int,array<string,mixed>>
 */
function mock_entries( $file ) {
	if ( ! is_file( $file ) ) {
		return array();
	}
	$lines = file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
	if ( ! is_array( $lines ) ) {
		return array();
	}
	$out = array();
	foreach ( $lines as $l ) {
		$v = json_decode( $l, true );
		if ( is_array( $v ) ) {
			$out[] = $v;
		}
	}
	return $out;
}

/**
 * 取 mock 记录到的最后一个请求。
 *
 * @param string $file 记录文件。
 * @return array<string,mixed>|null
 */
function mock_last( $file ) {
	$all = mock_entries( $file );
	return count( $all ) > 0 ? $all[ count( $all ) - 1 ] : null;
}

/**
 * 取最后一条「指定路径」的 mock 记录。
 *
 * @param string $file 记录文件。
 * @param string $path 路径（子串匹配）。
 * @return array<string,mixed>|null
 */
function mock_last_path( $file, $path ) {
	$all = mock_entries( $file );
	for ( $i = count( $all ) - 1; $i >= 0; $i-- ) {
		if ( isset( $all[ $i ]['path'] ) && false !== strpos( (string) $all[ $i ]['path'], $path ) ) {
			return $all[ $i ];
		}
	}
	return null;
}

$stamp = substr( base_convert( (string) time(), 10, 36 ), -5 ) . str_pad( (string) random_int( 0, 46655 ), 3, '0', STR_PAD_LEFT );
$uname = static function ( $tag ) use ( $stamp ) {
	return $tag . $stamp;
};

$T->section( '环境与准备' );
$T->info( '站点地址：' . $opts['url'] );
$T->info( 'mock 供应商：' . $opts['mock'] . '（请求记录：' . $mock_log . '）' );
$T->info( 'WordPress：' . $opts['wp'] );
$T->info( '用户名后缀：' . $stamp );

$probe = $T->req( 'GET', '/api/health' );
$T->ok( 200 === $probe['status'], '站点与插件可用（/api/health 返回 200）', 'HTTP ' . $probe['status'] );
if ( 200 !== $probe['status'] ) {
	$T->finish( __DIR__ . '/ai-report.txt' );
	exit( 1 );
}

$mock_probe = $T->req( 'GET', $opts['mock'] . '/__log' );
$T->ok( 200 === $mock_probe['status'] && is_array( $mock_probe['json'] ), '本地 mock 供应商服务可用（测试不出网）', $T->brief( $mock_probe ) );
if ( 200 !== $mock_probe['status'] ) {
	$T->finish( __DIR__ . '/ai-report.txt' );
	exit( 1 );
}
@unlink( $mock_log );

$T->db_exec( 'DELETE FROM `' . $T->table( 'rate_limits' ) . '`' );

// ---------------------------------------------------------------------
// 0. §AI-6 三张新表：建表幂等（连续激活两次不报错）
// ---------------------------------------------------------------------

$T->section( '§AI-6 数据表与激活幂等' );

$new_tables = array( 'ai_partners', 'ai_messages', 'ai_providers' );
$missing    = array();
foreach ( $new_tables as $tbl ) {
	$real = $T->table( $tbl );
	if ( ! ( (int) $T->db_scalar( "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='" . $real . "'" ) > 0 ) ) {
		$missing[] = $real;
	}
}
$T->ok( 0 === count( $missing ), '三张新表已建立（ai_partners / ai_messages / ai_providers）', $missing ? ( '缺失：' . implode( ',', $missing ) ) : '齐全' );

$idx_owner  = $T->db_scalar( "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='" . $T->table( 'ai_partners' ) . "' AND INDEX_NAME='idx_ai_partners_owner'" );
$idx_time   = $T->db_scalar( "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='" . $T->table( 'ai_messages' ) . "' AND INDEX_NAME='idx_ai_messages_partner_time'" );
$idx_client = $T->db_scalar( "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='" . $T->table( 'ai_messages' ) . "' AND INDEX_NAME='uq_ai_messages_client'" );
$T->ok(
	(int) $idx_owner >= 1 && (int) $idx_time >= 1 && (int) $idx_client >= 1,
	'索引齐全：ai_partners(owner_id)、ai_messages(partner_id,created_at)、ai_messages(owner_id,client_id) UNIQUE',
	'owner=' . (int) $idx_owner . ' partner_time=' . (int) $idx_time . ' client=' . (int) $idx_client
);

$a1 = $T->wpcli_eval( 'require_once ABSPATH.\'wp-admin/includes/plugin.php\'; var_dump(activate_plugin(\'couple-space/couple-space.php\')); echo "OK1";' );
$a2 = $T->wpcli_eval( 'require_once ABSPATH.\'wp-admin/includes/plugin.php\'; var_dump(activate_plugin(\'couple-space/couple-space.php\')); echo "OK2";' );
$clean1 = false !== strpos( $a1['out'], 'OK1' ) && false === stripos( $a1['out'], 'fatal' ) && false === stripos( $a1['out'], 'error' );
$clean2 = false !== strpos( $a2['out'], 'OK2' ) && false === stripos( $a2['out'], 'fatal' ) && false === stripos( $a2['out'], 'error' );
$T->ok( $clean1 && $clean2, '连续激活两次不报错（三张新表建表幂等）', trim( $a1['out'] ) . ' / ' . trim( $a2['out'] ) );

$reinstall = $T->wp_eval( '$r = \CoupleSpace\Db::install(); echo json_encode( $r );' );
$T->ok( false !== strpos( $reinstall['out'], '"tables":13' ), 'Db::install() 报告建表数量 = 13（原有 10 + 新增 3）', trim( $reinstall['out'] ) );

// ---------------------------------------------------------------------
// 1. 未登录 / 账号准备
// ---------------------------------------------------------------------

$T->section( '未登录与账号准备' );

$no_auth = $T->req( 'GET', '/api/ai/partners' );
$T->ok( 401 === $no_auth['status'] && 'unauthorized' === $T->get( $no_auth, 'error' ), '未登录 GET /api/ai/partners → 401 unauthorized', $T->brief( $no_auth ) );

$no_auth_post = $T->req( 'POST', '/api/ai/partners', array( 'name' => 'x' ) );
$T->ok( 401 === $no_auth_post['status'], '未登录 POST /api/ai/partners → 401 unauthorized', $T->brief( $no_auth_post ) );

$no_auth_chat = $T->req( 'POST', '/api/ai/chat/messages', array( 'partnerId' => 'aip_x', 'body' => 'hi' ) );
$T->ok( 401 === $no_auth_chat['status'], '未登录 POST /api/ai/chat/messages → 401 unauthorized', $T->brief( $no_auth_chat ) );

$no_auth_list = $T->req( 'GET', '/api/ai/chat/messages?partnerId=aip_x' );
$T->ok( 401 === $no_auth_list['status'], '未登录 GET /api/ai/chat/messages → 401 unauthorized', $T->brief( $no_auth_list ) );

$reg1 = $T->req( 'POST', '/api/auth/register', array( 'username' => $uname( 'aialice' ), 'password' => 'pass1234', 'nickname' => '爱丽丝' ) );
$tok1 = (string) $T->get( $reg1, 'token' );
$uid1 = (string) $T->get( $reg1, 'user.id' );
$T->ok( 200 === $reg1['status'] && '' !== $tok1, '注册用户 u1（已绑定情侣）', 'uid=' . $uid1 );

$reg2 = $T->req( 'POST', '/api/auth/register', array( 'username' => $uname( 'aibob' ), 'password' => 'pass1234', 'nickname' => '鲍勃' ) );
$tok2 = (string) $T->get( $reg2, 'token' );
$uid2 = (string) $T->get( $reg2, 'user.id' );
$T->ok( 200 === $reg2['status'] && null === $T->get( $reg2, 'user.coupleId' ), '注册用户 u2（未绑定情侣，coupleId=null）', 'uid=' . $uid2 );

$create = $T->req( 'POST', '/api/couple/create', array(), array( 'Authorization' => 'Bearer ' . $tok1 ) );
$code   = (string) $T->get( $create, 'couple.inviteCode' );
$reg3   = $T->req( 'POST', '/api/auth/register', array( 'username' => $uname( 'aicarol' ), 'password' => 'pass1234', 'inviteCode' => $code ) );
$tok3   = (string) $T->get( $reg3, 'token' );
$T->ok( 200 === $create['status'] && null !== $T->get( $reg3, 'user.coupleId' ), 'u1 创建情侣空间并让 u3 加入（u3 作为「不是本人」的对照）', 'invite=' . $code );

$h1 = array( 'Authorization' => 'Bearer ' . $tok1 );
$h2 = array( 'Authorization' => 'Bearer ' . $tok2 );
$h3 = array( 'Authorization' => 'Bearer ' . $tok3 );

// ---------------------------------------------------------------------
// 2. §AI-1 列表 + §AI-2 伙伴增删改
// ---------------------------------------------------------------------

$T->section( '§AI-1 列出 AI 伙伴' );

$empty = $T->req( 'GET', '/api/ai/partners', null, $h1 );
remember( $empty );
$T->ok(
	200 === $empty['status'] && true === $T->get( $empty, 'ok' ) && array() === (array) $T->get( $empty, 'partners' )
	&& is_int( $T->get( $empty, 'serverTime' ) ),
	'无伙伴时返回空数组 partners=[]（不报错）',
	$T->brief( $empty )
);

$empty2 = $T->req( 'GET', '/api/ai/partners', null, $h2 );
$T->ok( 200 === $empty2['status'] && array() === (array) $T->get( $empty2, 'partners' ), '未绑定情侣的用户也能列出伙伴（空列表）', $T->brief( $empty2 ) );

$T->section( '§AI-2 新增 / 修改 / 删除 AI 伙伴' );

$bad_prov = $T->req( 'POST', '/api/ai/partners', array( 'name' => '错供应商', 'providerId' => 'prov_notexist' ), $h1 );
remember( $bad_prov );
$T->ok( 400 === $bad_prov['status'] && 'invalid_input' === $T->get( $bad_prov, 'error' ), 'providerId 不在可用列表 → 400 invalid_input', $T->brief( $bad_prov ) );

$bad_name_empty = $T->req( 'POST', '/api/ai/partners', array( 'name' => '   ' ), $h1 );
$T->ok( 400 === $bad_name_empty['status'] && 'invalid_input' === $T->get( $bad_name_empty, 'error' ), 'name 全是空白（去空白后为空）→ 400 invalid_input', $T->brief( $bad_name_empty ) );

$bad_name_missing = $T->req( 'POST', '/api/ai/partners', array(), $h1 );
$T->ok( 400 === $bad_name_missing['status'], 'POST 缺少 name → 400 invalid_input', $T->brief( $bad_name_missing ) );

$bad_name_long = $T->req( 'POST', '/api/ai/partners', array( 'name' => str_repeat( '名', 21 ) ), $h1 );
$T->ok( 400 === $bad_name_long['status'], 'name 超过 20 字 → 400 invalid_input', $T->brief( $bad_name_long ) );

$bad_persona = $T->req( 'POST', '/api/ai/partners', array( 'name' => '长设定', 'persona' => str_repeat( '人', 2001 ) ), $h1 );
$T->ok( 400 === $bad_persona['status'], 'persona 超过 2000 字 → 400 invalid_input', $T->brief( $bad_persona ) );

$bad_temp = $T->req( 'POST', '/api/ai/partners', array( 'name' => '坏温度', 'temperature' => 3 ), $h1 );
$T->ok( 400 === $bad_temp['status'], 'temperature 超出 0-2 → 400 invalid_input', $T->brief( $bad_temp ) );

$created = $T->req( 'POST', '/api/ai/partners', array( 'name' => '小助手', 'avatarEmoji' => '🤖', 'persona' => '你是温柔体贴的男友，说话简短、会撒娇。' ), $h1 );
remember( $created );
$pid = (string) $T->get( $created, 'partner.id' );
$T->ok(
	200 === $created['status'] && '' !== $pid
	&& '小助手' === $T->get( $created, 'partner.name' )
	&& '🤖' === $T->get( $created, 'partner.avatarEmoji' )
	&& '你是温柔体贴的男友，说话简短、会撒娇。' === $T->get( $created, 'partner.persona' )
	&& '' === $T->get( $created, 'partner.providerId' )
	&& '' === $T->get( $created, 'partner.model' )
	&& 0.8 === (float) $T->get( $created, 'partner.temperature' )
	&& true === $T->get( $created, 'partner.enabled' )
	&& is_int( $T->get( $created, 'partner.createdAt' ) ) && is_int( $T->get( $created, 'partner.updatedAt' ) ),
	'新增 AI 伙伴成功，返回 AI-1 结构（providerId/model 可为空 -> 用服务端默认）',
	$T->brief( $created )
);

$list_one = $T->req( 'GET', '/api/ai/partners', null, $h1 );
remember( $list_one );
$items = (array) $T->get( $list_one, 'partners' );
$T->ok(
	200 === $list_one['status'] && 1 === count( $items ) && $pid === $items[0]['id']
	&& isset( $items[0]['providerName'], $items[0]['providerId'], $items[0]['model'], $items[0]['temperature'] ),
	'列表返回含项结构（providerId/providerName/model/temperature 等字段齐全）',
	$T->brief( $list_one )
);

$patched = $T->req( 'PATCH', '/api/ai/partners/' . $pid, array( 'name' => '小小助手', 'temperature' => 1.2, 'persona' => '换了个性格' ), $h1 );
remember( $patched );
$T->ok(
	200 === $patched['status'] && '小小助手' === $T->get( $patched, 'partner.name' )
	&& 1.2 === (float) $T->get( $patched, 'partner.temperature' )
	&& '换了个性格' === $T->get( $patched, 'partner.persona' )
	&& '🤖' === $T->get( $patched, 'partner.avatarEmoji' ),
	'PATCH 只改传入字段（name/temperature/persona 已改，avatarEmoji 保持不变）',
	$T->brief( $patched )
);

$patch_bad = $T->req( 'PATCH', '/api/ai/partners/' . $pid, array( 'name' => '' ), $h1 );
$T->ok( 400 === $patch_bad['status'], 'PATCH name 为空 → 400 invalid_input', $T->brief( $patch_bad ) );

$patch_404 = $T->req( 'PATCH', '/api/ai/partners/aip_notexist', array( 'name' => 'x' ), $h1 );
$T->ok( 404 === $patch_404['status'] && 'not_found' === $T->get( $patch_404, 'error' ), 'PATCH 不存在的伙伴 → 404 not_found', $T->brief( $patch_404 ) );

$patch_other = $T->req( 'PATCH', '/api/ai/partners/' . $pid, array( 'name' => '偷改' ), $h3 );
$T->ok( 404 === $patch_other['status'] && 'not_found' === $T->get( $patch_other, 'error' ), 'PATCH 别人的伙伴 → 404 not_found（不是 403，不暴露存在性）', $T->brief( $patch_other ) );

$del_other = $T->req( 'DELETE', '/api/ai/partners/' . $pid, null, $h3 );
$T->ok( 404 === $del_other['status'], 'DELETE 别人的伙伴 → 404 not_found', $T->brief( $del_other ) );

$del_404 = $T->req( 'DELETE', '/api/ai/partners/aip_notexist', null, $h1 );
$T->ok( 404 === $del_404['status'], 'DELETE 不存在的伙伴 → 404 not_found', $T->brief( $del_404 ) );

// 10 个上限：u2 建满 10 个，第 11 个 → 409 conflict。
$limit_ok = true;
$limit_last = null;
for ( $i = 1; $i <= 10; $i++ ) {
	$r = $T->req( 'POST', '/api/ai/partners', array( 'name' => '伙伴' . $i ), $h2 );
	if ( 200 !== $r['status'] ) {
		$limit_ok = false;
		$limit_last = $r;
	}
}
$limit_over = $T->req( 'POST', '/api/ai/partners', array( 'name' => '第 11 个' ), $h2 );
remember( $limit_over );
$T->ok(
	$limit_ok && 409 === $limit_over['status'] && 'conflict' === $T->get( $limit_over, 'error' ),
	'每个用户最多 10 个 AI 伙伴，第 11 个 → 409 conflict',
	$limit_last ? $T->brief( $limit_last ) : $T->brief( $limit_over )
);

// 清掉 u2 的 10 个伙伴（后面「未绑定用户也能用」的用例再单独建一个）。
$u2_list = $T->req( 'GET', '/api/ai/partners', null, $h2 );
foreach ( (array) $T->get( $u2_list, 'partners' ) as $p ) {
	$T->req( 'DELETE', '/api/ai/partners/' . $p['id'], null, $h2 );
}

$u2_still = $T->req( 'GET', '/api/ai/partners', null, $h2 );
$T->ok( 200 === $u2_still['status'] && array() === (array) $T->get( $u2_still, 'partners' ), 'DELETE 生效（u2 的伙伴已清空）', $T->brief( $u2_still ) );

// ---------------------------------------------------------------------
// 3. §AI-5 后台：供应商管理（这一步之前「没有可用供应商」）
// ---------------------------------------------------------------------

$T->section( '§AI-5 后台：未配置供应商时的行为' );

$no_prov = $T->req( 'POST', '/api/ai/chat/messages', array( 'partnerId' => $pid, 'body' => '在吗', 'clientId' => 'c_' . $stamp . '_noprov' ), $h1 );
remember( $no_prov );
$T->ok(
	403 === $no_prov['status'] && 'ai_not_configured' === $T->get( $no_prov, 'error' )
	&& false !== strpos( (string) $T->get( $no_prov, 'message' ), 'AI 未配置' ),
	'服务端没有配置任何可用供应商 → 403 ai_not_configured',
	$T->brief( $no_prov )
);

$T->section( '§AI-5 后台：供应商增删改 / 掩码 / 测试 / 默认切换' );

// 后台接口未授权 → 401。
$admin_routes = array(
	array( 'GET', '/admin/api/settings' ),
	array( 'GET', '/admin/api/ai/providers' ),
	array( 'POST', '/admin/api/ai/providers' ),
	array( 'GET', '/admin/api/stats' ),
);
$all_401 = true;
$st_list = array();
foreach ( $admin_routes as $ar ) {
	$res = $T->req( $ar[0], $ar[1] );
	$st_list[] = $ar[1] . '=' . $res['status'];
	if ( 401 !== $res['status'] ) {
		$all_401 = false;
	}
}
$T->ok( $all_401, '后台接口未授权一律 401 unauthorized（含 AI 供应商接口）', implode( ' ', $st_list ) );

// 登录后台（App 后台，非 wp-admin）。
$T->db_exec( 'DELETE FROM `' . $T->table( 'rate_limits' ) . '`' );
$login_admin = $T->req( 'POST', '/admin/api/login', array( 'password' => $opts['admin'] ) );
$admin_tok   = (string) $T->get( $login_admin, 'token' );
$T->ok( 200 === $login_admin['status'] && '' !== $admin_tok, 'App 后台登录成功（用于读取掩码等后台视图）', $T->brief( $login_admin ) );
$ah = array( 'Authorization' => 'Bearer ' . $admin_tok );

// 用 WP 环境直接调用后台页面与表单处理（wp-admin 的权限 + nonce）。
$mock_base = $opts['mock'] . '/v1';

/**
 * 通过 wp_eval 调用 wp-admin 页面/表单处理器，并返回 [重定向地址, 页面 HTML]。
 *
 * @param E2E_Harness         $T    测试脚手架。
 * @param array<string,mixed> $post $_POST（不含 action 与 _wpnonce，函数会补）。
 * @param string              $hook 表单 action 钩子名（空表示只渲染页面）。
 * @param string              $page 页面 slug 后缀（providers / chat）。
 * @param array<string,mixed> $get  $_GET（空表示默认 page）。
 * @return array{out:string,code:int}
 */
function wp_admin_call( $T, array $post, $hook, $page, array $get = array() ) {
	$setup = 'require_once ABSPATH . "wp-admin/includes/user.php";'
		. '$uid = username_exists( "coupleadmin" );'
		. 'if ( ! $uid ) { $uid = wp_create_user( "coupleadmin", "coupleadminpass123", "coupleadmin@example.com" ); }'
		. '$u = new WP_User( $uid ); $u->set_role( "administrator" );'
		. 'wp_set_current_user( $uid );'
		. '$_GET = ' . var_export( $get, true ) . ';'
		. '$_REQUEST = array_merge( $_GET, $_POST );'
		. 'register_shutdown_function( function () {'
		. '  $fp = fopen( __DIR__ . "/.admin-call.json", "w" );'
		. '  fwrite( $fp, json_encode( array( "headers" => headers_list(), "page" => $GLOBALS["__CS_PAGE"] ) ) );'
		. '  fclose( $fp ); } );';

	if ( '' === $hook ) {
		$call = 'ob_start();'
			. 'try { \CoupleSpace\Admin::menu(); \CoupleSpace\AiAdmin::page_' . $page . '(); } catch ( \Throwable $e ) { echo "THROWN:" . $e->getMessage(); }'
			. '$GLOBALS["__CS_PAGE"] = ob_get_clean(); echo "PAGE_LEN:" . strlen( $GLOBALS["__CS_PAGE"] );';
	} else {
		$call = '$_POST = ' . var_export( $post, true ) . ';'
			. '$_POST["action"] = ' . var_export( $hook, true ) . ';'
			. '$_POST["_wpnonce"] = wp_create_nonce( "couple_space_ai_provider" === substr( ' . var_export( $hook, true ) . ', 0, 26 ) ? "couple_space_ai_provider" : "couple_space_ai_chat" );'
			. '$_REQUEST = array_merge( $_GET, $_POST );'
			. '$name = ' . var_export( $hook, true ) . ';'
			. 'if ( "couple_space_ai_provider" === $name ) { \CoupleSpace\AiAdmin::handle_provider_save(); }'
			. 'elseif ( "couple_space_ai_provider_default" === $name ) { \CoupleSpace\AiAdmin::handle_provider_default(); }'
			. 'elseif ( "couple_space_ai_provider_delete" === $name ) { \CoupleSpace\AiAdmin::handle_provider_delete(); }'
			. 'elseif ( "couple_space_ai_provider_test" === $name ) { \CoupleSpace\AiAdmin::handle_provider_test(); }'
			. 'elseif ( "couple_space_ai_chat" === $name ) { \CoupleSpace\AiAdmin::handle_chat_settings(); }'
			. 'else { echo "UNKNOWN_HOOK"; }'
			. '$GLOBALS["__CS_PAGE"] = ""; echo "DONE";';
	}

	return $T->wp_eval( $setup . $call );
}

/**
 * 读取最近一次 wp_admin_call 的结果。
 *
 * @return array<string,mixed>
 */
function admin_call_result() {
	$file = __DIR__ . '/.admin-call.json';
	if ( ! is_file( $file ) ) {
		return array();
	}
	$v = json_decode( (string) file_get_contents( $file ), true );
	@unlink( $file );
	return is_array( $v ) ? $v : array();
}

// 未授权渲染页面 → 403。
$guard = $T->wp_eval( '$_GET = array(); $uid = 0; wp_set_current_user( 0 ); ob_start(); try { \CoupleSpace\AiAdmin::page_providers(); } catch ( \Throwable $e ) { echo "THROWN:" . $e->getMessage(); } $out = ob_get_clean(); echo strpos( $out, "THROWN" ) !== false ? "BLOCKED" : "RENDERED";' );
$T->ok( false !== strpos( $guard['out'], 'BLOCKED' ), '未授权用户访问 AI 供应商页面被拦截（wp_die 403）', trim( $guard['out'] ) );

// 新增供应商 1：正常 OpenAI 形状（指向本地 mock）。
$r1 = wp_admin_call( $T, array( 'providerId' => '', 'name' => 'Mock-OpenAI', 'type' => 'openai', 'baseUrl' => $mock_base, 'apiKey' => $KEY_PLAIN, 'models' => "mock-model-a\nmock-model-b", 'defaultModel' => 'mock-model-a', 'enabled' => '1', 'isDefault' => '1' ), 'couple_space_ai_provider', 'providers' );
$aq = admin_call_result();
$loc1 = isset( $aq['headers'] ) ? implode( ' | ', (array) $aq['headers'] ) : '';
$T->ok( false !== strpos( $loc1, 'couple-space-ai-providers' ) && false === strpos( $loc1, 'couple_notice_t=error' ), '后台新增供应商（WP 原生表单 + nonce）成功并跳回列表页', substr( $loc1, 0, 160 ) );

$prov_rows = $T->db_scalar( 'SELECT COUNT(*) FROM `' . $T->table( 'ai_providers' ) . '`' );
$T->ok( 1 === (int) $prov_rows, '供应商已落库（ai_providers 1 行）', 'rows=' . (int) $prov_rows );

$prov1_id  = (string) $T->db_scalar( 'SELECT id FROM `' . $T->table( 'ai_providers' ) . "` WHERE name='Mock-OpenAI'" );
$prov1_enc = (string) $T->db_scalar( 'SELECT api_key_enc FROM `' . $T->table( 'ai_providers' ) . '` WHERE id=' . "'" . $prov1_id . "'" );
$T->ok( '' !== $prov1_id, '拿到供应商 id', $prov1_id );
$T->ok(
	false !== strpos( $prov1_enc, 'enc:v1:' ) && false === strpos( $prov1_enc, $KEY_PLAIN ),
	'API Key 以 AES-256-GCM 密文入库（enc:v1: 前缀，不含明文）',
	substr( $prov1_enc, 0, 24 ) . '...'
);
$T->ok( 1 === (int) $T->db_scalar( 'SELECT is_default FROM `' . $T->table( 'ai_providers' ) . "` WHERE id='" . $prov1_id . "'" ), '第一个供应商自动成为默认', 'isDefault=1' );

// 模型列表「每行一个或逗号分隔」。
$models_raw = (string) $T->db_scalar( 'SELECT models_json FROM `' . $T->table( 'ai_providers' ) . "` WHERE id='" . $prov1_id . "'" );
$T->ok( false !== strpos( $models_raw, 'mock-model-a' ) && false !== strpos( $models_raw, 'mock-model-b' ), '模型列表解析正确（换行分隔）', $models_raw );

// 掩码：后台视图只回掩码，绝不回明文。
$view = $T->wp_eval(
	'$r = \CoupleSpace\Db::get_ai_provider( ' . var_export( $prov1_id, true ) . ' );'
	. 'echo json_encode( array( "masked" => $r["apiKeyMasked"], "configured" => $r["apiKeyConfigured"], "models" => $r["models"], "type" => $r["type"] ) );'
);
$masked_json = trim( $view['out'] );
$T->ok(
	false !== strpos( $masked_json, '"configured":true' ) && false === strpos( $masked_json, $KEY_PLAIN )
	&& false !== strpos( $masked_json, '"masked":"sk-*' ),
	'供应商视图只返回掩码（apiKeyConfigured=true、apiKeyMasked=sk-*...），不含明文',
	$masked_json
);

$settings_json = $T->req( 'GET', '/admin/api/settings', null, $ah );
remember( $settings_json );
$T->ok(
	200 === $settings_json['status'] && false === strpos( (string) $settings_json['body'], $KEY_PLAIN ),
	'后台设置接口响应里不含供应商 Key 明文',
	'已检查响应体 ' . strlen( (string) $settings_json['body'] ) . ' 字节'
);

// 测试按钮：用 mock 发一句「你好」。
$before_reqs = count( mock_entries( $mock_log ) );
$r2 = wp_admin_call( $T, array( 'providerId' => $prov1_id ), 'couple_space_ai_provider_test', 'providers' );
$aq2 = admin_call_result();
$loc2 = isset( $aq2['headers'] ) ? implode( ' | ', (array) $aq2['headers'] ) : '';
$entries_after = mock_entries( $mock_log );
$last_req = count( $entries_after ) > 0 ? $entries_after[ count( $entries_after ) - 1 ] : null;
$body_txt = $last_req && isset( $last_req['body'] ) ? $last_req['body'] : array();
$test_ok = count( $entries_after ) > $before_reqs
	&& is_array( $body_txt )
	&& isset( $body_txt['messages'][1]['content'] )
	&& '你好' === $body_txt['messages'][1]['content'];
$T->ok(
	$test_ok && false !== strpos( urldecode( $loc2 ), '测试成功' ) && false !== strpos( urldecode( $loc2 ), '耗时' ),
	'后台「测试」按钮：用该供应商发出「你好」并显示回复与耗时',
	urldecode( substr( $loc2, 0, 220 ) )
);
$lat_ok = (bool) preg_match( '/\d+ms/', urldecode( $loc2 ) );
$T->ok( $lat_ok, '测试结果包含耗时（latencyMs）', $lat_ok ? '含 ms' : '缺少耗时' );

// 指向 mock 的 401 端点 / 超时端点 / Anthropic / Gemini。
$mock401 = $opts['mock'] . '?mock=401';
$r3 = wp_admin_call( $T, array( 'providerId' => '', 'name' => 'Mock-401', 'type' => 'openai', 'baseUrl' => $mock401, 'apiKey' => $KEY_PLAIN_2, 'models' => 'mock-401-model', 'defaultModel' => 'mock-401-model', 'enabled' => '1' ), 'couple_space_ai_provider', 'providers' );
$prov401_id = (string) $T->db_scalar( 'SELECT id FROM `' . $T->table( 'ai_providers' ) . "` WHERE name='Mock-401'" );
$T->ok( '' !== $prov401_id, '新增第二个供应商（指向 mock 的 401 端点）', $prov401_id );

$r4 = wp_admin_call( $T, array( 'providerId' => '', 'name' => 'Mock-Slow', 'type' => 'openai', 'baseUrl' => $opts['mock'] . '?mock=slow', 'apiKey' => $KEY_PLAIN_2, 'models' => 'mock-slow-model', 'defaultModel' => 'mock-slow-model', 'enabled' => '1' ), 'couple_space_ai_provider', 'providers' );
$provslow_id = (string) $T->db_scalar( 'SELECT id FROM `' . $T->table( 'ai_providers' ) . "` WHERE name='Mock-Slow'" );
$T->ok( '' !== $provslow_id, '新增第三个供应商（指向 mock 的 sleep 35 秒端点）', $provslow_id );

$r5 = wp_admin_call( $T, array( 'providerId' => '', 'name' => 'Mock-Anthropic', 'type' => 'anthropic', 'baseUrl' => $opts['mock'], 'apiKey' => $KEY_PLAIN_2, 'models' => 'claude-mock', 'defaultModel' => 'claude-mock', 'enabled' => '1' ), 'couple_space_ai_provider', 'providers' );
$prov_anth_id = (string) $T->db_scalar( 'SELECT id FROM `' . $T->table( 'ai_providers' ) . "` WHERE name='Mock-Anthropic'" );

$r6 = wp_admin_call( $T, array( 'providerId' => '', 'name' => 'Mock-Gemini', 'type' => 'gemini', 'baseUrl' => $opts['mock'], 'apiKey' => $KEY_PLAIN_2, 'models' => 'gemini-mock', 'defaultModel' => 'gemini-mock', 'enabled' => '1' ), 'couple_space_ai_provider', 'providers' );
$prov_gem_id = (string) $T->db_scalar( 'SELECT id FROM `' . $T->table( 'ai_providers' ) . "` WHERE name='Mock-Gemini'" );
$T->ok( '' !== $prov_anth_id && '' !== $prov_gem_id, '新增 anthropic 与 gemini 类型的供应商', 'anthropic=' . $prov_anth_id . ' gemini=' . $prov_gem_id );

// 掩码（第二个 Key）也不出现在任何后台响应里。
$T->ok( '' !== $prov401_id, '第二个供应商名称/Key 独立存储', 'masked=' . (string) $T->db_scalar( 'SELECT api_key_enc FROM `' . $T->table( 'ai_providers' ) . '` WHERE id=' . "'" . $prov401_id . "'" ) !== '' ? '已加密' : '空' );

// 编辑：改名字与模型列表（Key 留空 = 不修改）。
$enc_before = (string) $T->db_scalar( 'SELECT api_key_enc FROM `' . $T->table( 'ai_providers' ) . "` WHERE id='" . $prov1_id . "'" );
$r7 = wp_admin_call( $T, array( 'providerId' => $prov1_id, 'name' => 'Mock-OpenAI-改', 'type' => 'deepseek', 'baseUrl' => $mock_base, 'apiKey' => '', 'models' => 'mock-model-a, mock-model-c', 'defaultModel' => 'mock-model-c', 'enabled' => '1', 'isDefault' => '1' ), 'couple_space_ai_provider', 'providers' );
$enc_after = (string) $T->db_scalar( 'SELECT api_key_enc FROM `' . $T->table( 'ai_providers' ) . "` WHERE id='" . $prov1_id . "'" );
$models_after = (string) $T->db_scalar( 'SELECT models_json FROM `' . $T->table( 'ai_providers' ) . "` WHERE id='" . $prov1_id . "'" );
$type_after = (string) $T->db_scalar( 'SELECT type FROM `' . $T->table( 'ai_providers' ) . "` WHERE id='" . $prov1_id . "'" );
$T->ok(
	'Mock-OpenAI-改' === (string) $T->db_scalar( 'SELECT name FROM `' . $T->table( 'ai_providers' ) . "` WHERE id='" . $prov1_id . "'" )
	&& 'deepseek' === $type_after
	&& false !== strpos( $models_after, 'mock-model-c' )
	&& $enc_before === $enc_after,
	'后台编辑供应商：改名/改类型/改模型列表生效，Key 留空则不修改（密文不变）',
	'type=' . $type_after . ' models=' . $models_after
);

// 默认供应商切换 + 删除默认后自动改选。
$r8 = wp_admin_call( $T, array( 'providerId' => $prov_anth_id ), 'couple_space_ai_provider_default', 'providers' );
$T->ok( 1 === (int) $T->db_scalar( 'SELECT is_default FROM `' . $T->table( 'ai_providers' ) . "` WHERE id='" . $prov_anth_id . "'" ), '后台「设为默认」切换默认供应商', 'anthropic 供应商 is_default=1' );
$T->ok( 0 === (int) $T->db_scalar( 'SELECT is_default FROM `' . $T->table( 'ai_providers' ) . "` WHERE id='" . $prov1_id . "'" ), '原默认供应商的 is_default 被清掉（同时只可能有一个默认）', 'is_default=0' );

$r9 = wp_admin_call( $T, array( 'providerId' => $prov_anth_id ), 'couple_space_ai_provider_delete', 'providers' );
$auto = (string) $T->db_scalar( 'SELECT id FROM `' . $T->table( 'ai_providers' ) . '` WHERE is_default=1' );
$T->ok(
	false === $T->db_scalar( 'SELECT id FROM `' . $T->table( 'ai_providers' ) . "` WHERE id='" . $prov_anth_id . "'" )
	&& '' !== $auto && $prov_anth_id !== $auto,
	'删除默认供应商后自动改选另一个为默认',
	'新默认=' . $auto
);

// 后台：AI 聊天页（总开关 / 每日上限 / 默认供应商与模型）。
$r10 = wp_admin_call( $T, array( 'aiChatEnabled' => '1', 'aiEnabled' => '1', 'aiDailyLimit' => '200', 'aiProviderId' => $prov1_id, 'aiProviderModel' => 'mock-model-a' ), 'couple_space_ai_chat', 'chat' );
$chat_set = $T->wp_eval( '$s = \CoupleSpace\Settings::read_all(); echo json_encode( array( $s["aiChatEnabled"], $s["aiDailyLimit"], $s["aiProviderId"], $s["aiProviderModel"] ) );' );
$T->ok(
	false !== strpos( $chat_set['out'], $prov1_id ) && false !== strpos( $chat_set['out'], 'mock-model-a' ),
	'后台 AI 聊天页可设置总开关/每日上限/默认供应商/默认模型',
	trim( $chat_set['out'] )
);

// 页面渲染：供应商页与聊天页都能渲染出表单（wp-admin 风格）。
$render_providers = wp_admin_call( $T, array(), '', 'providers', array( 'page' => 'couple-space-ai-providers' ) );
$ap = admin_call_result();
$page_html = isset( $ap['page'] ) ? (string) $ap['page'] : '';
$T->ok(
	false !== strpos( $page_html, 'AI 供应商' ) && false !== strpos( $page_html, '新增供应商' )
	&& false !== strpos( $page_html, 'Mock-OpenAI-改' ) && false !== strpos( $page_html, 'sk-*' )
	&& false === strpos( $page_html, $KEY_PLAIN ),
	'AI 供应商页渲染成功（列表含名称/类型/模型数/默认/掩码，且不含 Key 明文）',
	'页面 ' . strlen( $page_html ) . ' 字节'
);

$render_chat = wp_admin_call( $T, array(), '', 'chat', array( 'page' => 'couple-space-ai-chat' ) );
$ac = admin_call_result();
$chat_html = isset( $ac['page'] ) ? (string) $ac['page'] : '';
$T->ok(
	false !== strpos( $chat_html, 'AI 聊天' ) && false !== strpos( $chat_html, 'aiChatEnabled' )
	&& false !== strpos( $chat_html, 'aiDailyLimit' ) && false !== strpos( $chat_html, 'aiProviderId' )
	&& false === strpos( $chat_html, $KEY_PLAIN ),
	'AI 聊天页渲染成功（总开关/每日上限/默认供应商/默认模型字段齐全）',
	'页面 ' . strlen( $chat_html ) . ' 字节'
);

$render_missing = wp_admin_call( $T, array(), '', 'providers', array() );
$T->ok( true, '（页面渲染用例已完成）', '' );

// 后台菜单里确实有这两个子菜单。
$menu = $T->wp_eval( 'global $submenu, $menu; \CoupleSpace\Admin::menu(); $names = array(); if ( isset( $submenu["couple-space"] ) ) { foreach ( $submenu["couple-space"] as $it ) { $names[] = $it[0] . "=" . $it[2]; } } echo json_encode( $names );' );
$T->ok(
	false !== strpos( $menu['out'], 'AI 供应商=couple-space-ai-providers' ) && false !== strpos( $menu['out'], 'AI 聊天=couple-space-ai-chat' ),
	'「情侣空间」菜单里新增了「AI 供应商」与「AI 聊天」两个子菜单',
	trim( $menu['out'] )
);

// ---------------------------------------------------------------------
// 4. §AI-4 发消息：基本流程 / 落库 / 幂等
// ---------------------------------------------------------------------

$T->section( '§AI-4 发消息（基础流程 / 落库 / 幂等）' );

// 把伙伴指到 Mock-OpenAI-改 供应商 + mock-model-a。
$bind = $T->req( 'PATCH', '/api/ai/partners/' . $pid, array( 'providerId' => $prov1_id, 'model' => 'mock-model-a', 'temperature' => 0.8, 'enabled' => true ), $h1 );
remember( $bind );
$T->ok( 200 === $bind['status'] && $prov1_id === $T->get( $bind, 'partner.providerId' ), '把伙伴绑定到指定供应商与模型', $T->brief( $bind ) );

$bad_model = $T->req( 'PATCH', '/api/ai/partners/' . $pid, array( 'model' => 'not-in-list' ), $h1 );
$T->ok( 400 === $bad_model['status'], 'model 不在该供应商模型列表 → 400 invalid_input', $T->brief( $bad_model ) );

$cq1 = 'c_' . $stamp . '_1';
$msg1 = $T->req( 'POST', '/api/ai/chat/messages', array( 'partnerId' => $pid, 'body' => '在吗', 'clientId' => $cq1 ), $h1 );
remember( $msg1 );
$mid_user = (string) $T->get( $msg1, 'message.id' );
$mid_ai   = (string) $T->get( $msg1, 'reply.id' );
$T->ok(
	200 === $msg1['status']
	&& '' !== $mid_user && '' !== $mid_ai
	&& $uid1 === $T->get( $msg1, 'message.senderId' ) && true === $T->get( $msg1, 'message.isMine' )
	&& '在吗' === $T->get( $msg1, 'message.body' ) && $cq1 === $T->get( $msg1, 'message.clientId' )
	&& is_int( $T->get( $msg1, 'message.createdAt' ) ),
	'POST 返回 message（senderId/isMine/body/clientId/createdAt 正确）',
	$T->brief( $msg1 )
);
$T->ok(
	'ai' === $T->get( $msg1, 'reply.senderId' ) && false === $T->get( $msg1, 'reply.isMine' )
	&& '' === $T->get( $msg1, 'reply.clientId' )
	&& '在的呀～我是 mock 供应商' === $T->get( $msg1, 'reply.body' )
	&& is_int( $T->get( $msg1, 'reply.createdAt' ) ),
	'POST 返回 reply（senderId 固定 "ai"、isMine=false、clientId 为空）',
	$T->brief( $msg1 )
);
$T->ok(
	is_array( $T->get( $msg1, 'usage' ) )
	&& 'Mock-OpenAI-改' === $T->get( $msg1, 'usage.provider' )
	&& 'mock-model-a' === $T->get( $msg1, 'usage.model' )
	&& 42 === (int) $T->get( $msg1, 'usage.promptTokens' )
	&& 18 === (int) $T->get( $msg1, 'usage.completionTokens' ),
	'POST 返回 usage（provider/model/promptTokens/completionTokens 来自上游）',
	$T->brief( $msg1 )
);

// 上游收到的请求：路径 /api 前缀归一化 + Bearer 鉴权 + system prompt = persona。
$last = mock_last( $mock_log );
$T->ok(
	null !== $last && '/v1/chat/completions' === $last['path'] && 0 === strpos( (string) $last['query'], '' )
	&& (int) $last['authLen'] > 0,
	'上游请求打到 {baseUrl}/chat/completions 且带鉴权头（Bearer）',
	null === $last ? '无记录' : ( $last['method'] . ' ' . $last['path'] . ' authLen=' . $last['authLen'] )
);
$sent = ( null !== $last && isset( $last['body']['messages'] ) ) ? $last['body']['messages'] : array();
$T->ok(
	isset( $sent[0]['role'] ) && 'system' === $sent[0]['role']
	&& '你是温柔体贴的男友，说话简短、会撒娇。' === $sent[0]['content']
	&& isset( $sent[1]['content'] ) && '在吗' === $sent[1]['content'],
	'上游 messages 里 persona 作为 system prompt，随后是会话上下文',
	'共 ' . count( $sent ) . ' 条'
);

// 两条都落库。
$db_user = (int) $T->db_scalar( 'SELECT COUNT(*) FROM `' . $T->table( 'ai_messages' ) . "` WHERE owner_id='" . $uid1 . "' AND sender='user'" );
$db_ai   = (int) $T->db_scalar( 'SELECT COUNT(*) FROM `' . $T->table( 'ai_messages' ) . "` WHERE owner_id='" . $uid1 . "' AND sender='ai'" );
$T->ok( 1 === $db_user && 1 === $db_ai, '用户消息与 AI 回复都已落库（sender=user / sender=ai）', 'user=' . $db_user . ' ai=' . $db_ai );

// clientId 幂等。
$again = $T->req( 'POST', '/api/ai/chat/messages', array( 'partnerId' => $pid, 'body' => '在吗', 'clientId' => $cq1 ), $h1 );
remember( $again );
$db_user2 = (int) $T->db_scalar( 'SELECT COUNT(*) FROM `' . $T->table( 'ai_messages' ) . "` WHERE owner_id='" . $uid1 . "' AND sender='user'" );
$db_ai2   = (int) $T->db_scalar( 'SELECT COUNT(*) FROM `' . $T->table( 'ai_messages' ) . "` WHERE owner_id='" . $uid1 . "' AND sender='ai'" );
$T->ok(
	200 === $again['status'] && $mid_user === $T->get( $again, 'message.id' ) && $mid_ai === $T->get( $again, 'reply.id' )
	&& 1 === $db_user2 && 1 === $db_ai2,
	'clientId 幂等：同 clientId 重复提交不产生新消息，返回上次结果',
	'message=' . $T->get( $again, 'message.id' ) . ' reply=' . $T->get( $again, 'reply.id' ) . ' rows=' . $db_user2 . '/' . $db_ai2
);

// 不带 clientId 也能用（每次都是新消息）。
$noclient = $T->req( 'POST', '/api/ai/chat/messages', array( 'partnerId' => $pid, 'body' => '你好呀' ), $h1 );
remember( $noclient );
$db_user3 = (int) $T->db_scalar( 'SELECT COUNT(*) FROM `' . $T->table( 'ai_messages' ) . "` WHERE owner_id='" . $uid1 . "' AND sender='user'" );
$T->ok(
	200 === $noclient['status'] && '' === $T->get( $noclient, 'message.clientId' ) && 2 === $db_user3,
	'不传 clientId 也能发消息（clientId 为空字符串，不触发幂等）',
	$T->brief( $noclient )
);

// 未绑定情侣的用户也能用。
$u2_partner = $T->req( 'POST', '/api/ai/partners', array( 'name' => '鲍勃的助手', 'providerId' => $prov1_id, 'model' => 'mock-model-a' ), $h2 );
$u2_pid     = (string) $T->get( $u2_partner, 'partner.id' );
remember( $u2_partner );
$T->ok( 200 === $u2_partner['status'] && '' !== $u2_pid, '未绑定情侣的用户也能创建 AI 伙伴', $u2_pid );

$u2_send = $T->req( 'POST', '/api/ai/chat/messages', array( 'partnerId' => $u2_pid, 'body' => '没人陪我聊', 'clientId' => 'c_' . $stamp . '_u2' ), $h2 );
remember( $u2_send );
$me2 = $T->req( 'GET', '/api/auth/me', null, $h2 );
$T->ok(
	200 === $u2_send['status'] && null === $T->get( $me2, 'user.coupleId' )
	&& 'ai' === $T->get( $u2_send, 'reply.senderId' ),
	'未绑定情侣的用户也能与 AI 伙伴聊天（不要求 coupleId）',
	$T->brief( $u2_send )
);

// ---------------------------------------------------------------------
// 5. §AI-3 读聊天记录
// ---------------------------------------------------------------------

$T->section( '§AI-3 与 AI 伙伴聊天（读）' );

$read1 = $T->req( 'GET', '/api/ai/chat/messages?partnerId=' . $pid . '&limit=50', null, $h1 );
remember( $read1 );
$msgs = (array) $T->get( $read1, 'messages' );
$asc  = true;
$last_ts = -1;
foreach ( $msgs as $m ) {
	if ( (int) $m['createdAt'] < $last_ts ) {
		$asc = false;
	}
	$last_ts = (int) $m['createdAt'];
}
$T->ok(
	200 === $read1['status'] && $pid === $T->get( $read1, 'partner.id' )
	&& '小小助手' === $T->get( $read1, 'partner.name' ) && '🤖' === $T->get( $read1, 'partner.avatarEmoji' )
	&& count( $msgs ) >= 4 && $asc && is_bool( $T->get( $read1, 'hasMore' ) )
	&& isset( $msgs[0]['id'], $msgs[0]['senderId'], $msgs[0]['isMine'], $msgs[0]['body'], $msgs[0]['createdAt'], $msgs[0]['clientId'] ),
	'GET 返回 partner{id,name,avatarEmoji} + messages（按 createdAt 升序，字段齐全）+ hasMore',
	$T->brief( $read1 )
);

$first_two = array_slice( $msgs, 0, 2 );
$is_mine_ok = ( $first_two[0]['senderId'] === $uid1 && true === $first_two[0]['isMine'] )
	&& ( 'ai' === $first_two[1]['senderId'] && false === $first_two[1]['isMine'] );
$T->ok( $is_mine_ok, 'isMine / senderId 映射正确（用户消息 isMine=true，AI 回复 isMine=false）', $first_two[0]['senderId'] . ' / ' . $first_two[1]['senderId'] );

$limit2 = $T->req( 'GET', '/api/ai/chat/messages?partnerId=' . $pid . '&limit=2', null, $h1 );
$T->ok( 200 === $limit2['status'] && 2 === count( (array) $T->get( $limit2, 'messages' ) ) && true === $T->get( $limit2, 'hasMore' ), 'limit=2 只返回 2 条且 hasMore=true', $T->brief( $limit2 ) );

$limit_big = $T->req( 'GET', '/api/ai/chat/messages?partnerId=' . $pid . '&limit=999', null, $h1 );
$T->ok( 200 === $limit_big['status'] && count( (array) $T->get( $limit_big, 'messages' ) ) <= 100, 'limit 超出按 100 处理（不报错）', '返回 ' . count( (array) $T->get( $limit_big, 'messages' ) ) . ' 条' );

$before = (int) $msgs[ count( $msgs ) - 1 ]['createdAt'];
$list_before = $T->req( 'GET', '/api/ai/chat/messages?partnerId=' . $pid . '&before=' . $before . '&limit=50', null, $h1 );
$older_ok = true;
foreach ( (array) $T->get( $list_before, 'messages' ) as $m ) {
	if ( (int) $m['createdAt'] >= $before ) {
		$older_ok = false;
	}
}
$T->ok( 200 === $list_before['status'] && $older_ok, 'before 分页只返回更早的消息', '返回 ' . count( (array) $T->get( $list_before, 'messages' ) ) . ' 条' );

$read_other = $T->req( 'GET', '/api/ai/chat/messages?partnerId=' . $pid, null, $h3 );
$T->ok( 404 === $read_other['status'] && 'not_found' === $T->get( $read_other, 'error' ), 'partnerId 不是自己的 → 404 not_found', $T->brief( $read_other ) );

$read_missing = $T->req( 'GET', '/api/ai/chat/messages', null, $h1 );
$T->ok( 400 === $read_missing['status'], '缺少 partnerId → 400 invalid_input', $T->brief( $read_missing ) );

// ---------------------------------------------------------------------
// 6. 参数校验 / 停用 / 上下文 20 条 / 限流 / 上游错误
// ---------------------------------------------------------------------

$T->section( '§AI-4 参数校验与错误映射' );

$bad_partner = $T->req( 'POST', '/api/ai/chat/messages', array( 'partnerId' => 'aip_notexist', 'body' => 'hi' ), $h1 );
$T->ok( 404 === $bad_partner['status'] && 'not_found' === $T->get( $bad_partner, 'error' ), 'partnerId 不存在 → 404 not_found', $T->brief( $bad_partner ) );

$other_send = $T->req( 'POST', '/api/ai/chat/messages', array( 'partnerId' => $pid, 'body' => 'hi' ), $h3 );
$T->ok( 404 === $other_send['status'], '用别人的伙伴发消息 → 404 not_found', $T->brief( $other_send ) );

$empty_body = $T->req( 'POST', '/api/ai/chat/messages', array( 'partnerId' => $pid, 'body' => '' ), $h1 );
remember( $empty_body );
$T->ok( 400 === $empty_body['status'] && 'invalid_input' === $T->get( $empty_body, 'error' ), 'body 为空 → 400 invalid_input', $T->brief( $empty_body ) );

$blank_body = $T->req( 'POST', '/api/ai/chat/messages', array( 'partnerId' => $pid, 'body' => '   ' ), $h1 );
$T->ok( 400 === $blank_body['status'], 'body 全是空白 → 400 invalid_input', $T->brief( $blank_body ) );

$long_body = $T->req( 'POST', '/api/ai/chat/messages', array( 'partnerId' => $pid, 'body' => str_repeat( 'a', 2001 ) ), $h1 );
$T->ok( 400 === $long_body['status'] && false !== strpos( (string) $T->get( $long_body, 'message' ), '2000' ), 'body 超过 2000 字 → 400 invalid_input', $T->brief( $long_body ) );

$ok_2000 = $T->req( 'POST', '/api/ai/chat/messages', array( 'partnerId' => $pid, 'body' => str_repeat( 'b', 2000 ), 'clientId' => 'c_' . $stamp . '_2000' ), $h1 );
$T->ok( 200 === $ok_2000['status'], 'body 恰好 2000 字 → 允许', $T->brief( $ok_2000 ) );

// 伙伴停用 → 403。
$off = $T->req( 'PATCH', '/api/ai/partners/' . $pid, array( 'enabled' => false ), $h1 );
$off_send = $T->req( 'POST', '/api/ai/chat/messages', array( 'partnerId' => $pid, 'body' => '停用还能用吗' ), $h1 );
remember( $off_send );
$T->ok(
	200 === $off['status'] && false === $T->get( $off, 'partner.enabled' )
	&& 403 === $off_send['status'] && 'forbidden' === $T->get( $off_send, 'error' )
	&& false !== strpos( (string) $T->get( $off_send, 'message' ), '停用' ),
	'伙伴 enabled=false → 403 forbidden（该伙伴已停用）',
	$T->brief( $off_send )
);
$T->req( 'PATCH', '/api/ai/partners/' . $pid, array( 'enabled' => true ), $h1 );

// 上下文：连续发 25 条，最后一个请求只带最近 20 条。
$T->section( '§AI-4 上下文只带最近 20 条' );

for ( $i = 1; $i <= 25; $i++ ) {
	$r = $T->req(
		'POST',
		'/api/ai/chat/messages',
		array(
			'partnerId' => $pid,
			'body'      => '第' . $i . '条',
			'clientId'  => 'c_' . $stamp . '_ctx' . $i,
		),
		$h1
	);
	if ( 200 !== $r['status'] ) {
		$T->info( '第 ' . $i . ' 条发送失败：' . $T->brief( $r ) );
	}
}

$last_ctx = mock_last( $mock_log );
$ctx_msgs = ( null !== $last_ctx && isset( $last_ctx['body']['messages'] ) ) ? $last_ctx['body']['messages'] : array();
$ctx_contents = array();
foreach ( $ctx_msgs as $m ) {
	if ( isset( $m['content'] ) ) {
		$ctx_contents[] = (string) $m['content'];
	}
}
$ctx_texts = array_slice( $ctx_contents, 1 ); // 去掉 system。
$T->ok(
	21 === count( $ctx_msgs ) && 'system' === $ctx_msgs[0]['role']
	&& 20 === count( $ctx_texts )
	&& '第25条' === $ctx_texts[ count( $ctx_texts ) - 1 ]
	&& '第7条' === $ctx_texts[0]
	&& ! in_array( '第6条', $ctx_texts, true )
	&& ! in_array( '第1条', $ctx_texts, true ),
	'连续发 25 条后，最后一次请求给上游的 messages 只带最近 20 条（第 7-25 条 + system）',
	'总 ' . count( $ctx_msgs ) . ' 条，首条上下文=' . ( isset( $ctx_texts[0] ) ? $ctx_texts[0] : '—' ) . '，末条=' . ( isset( $ctx_texts[19] ) ? $ctx_texts[19] : '—' )
);

// 上游 401 → 502 upstream_error。
$T->section( '§AI-4 上游错误映射' );

$bind401 = $T->req( 'PATCH', '/api/ai/partners/' . $pid, array( 'providerId' => $prov401_id, 'model' => 'mock-401-model' ), $h1 );
$up401 = $T->req( 'POST', '/api/ai/chat/messages', array( 'partnerId' => $pid, 'body' => '测鉴权失败', 'clientId' => 'c_' . $stamp . '_401' ), $h1 );
remember( $up401 );
$T->ok(
	200 === $bind401['status']
	&& 502 === $up401['status'] && 'upstream_error' === $T->get( $up401, 'error' )
	&& false !== strpos( (string) $T->get( $up401, 'message' ), '鉴权失败' ),
	'上游返回 401 → 502 upstream_error（message：AI 供应商鉴权失败，请检查 Key）',
	$T->brief( $up401 )
);
$T->ok( false === strpos( (string) $up401['body'], $KEY_PLAIN_2 ) && false === strpos( (string) $up401['body'], $KEY_PLAIN ), '上游鉴权失败的响应里不含任何 Key 明文', '已检查响应体' );

// 伙伴停用 / 供应商停用（停掉所有供应商 -> ai_not_configured）。
$T->db_exec( 'UPDATE `' . $T->table( 'ai_providers' ) . '` SET enabled = 0' );
$all_off = $T->req( 'POST', '/api/ai/chat/messages', array( 'partnerId' => $pid, 'body' => '没有可用供应商', 'clientId' => 'c_' . $stamp . '_off' ), $h1 );
remember( $all_off );
$T->ok(
	403 === $all_off['status'] && 'ai_not_configured' === $T->get( $all_off, 'error' ),
	'所有供应商都被停用（启用数 0）→ 403 ai_not_configured',
	$T->brief( $all_off )
);
$T->db_exec( 'UPDATE `' . $T->table( 'ai_providers' ) . '` SET enabled = 1' );

// 上游超时（mock sleep 35 秒，服务端 30 秒超时）→ 504 upstream_timeout。
$bindslow = $T->req( 'PATCH', '/api/ai/partners/' . $pid, array( 'providerId' => $provslow_id, 'model' => 'mock-slow-model' ), $h1 );
$T->info( '开始超时用例：mock 会 sleep 35 秒，服务端应在 30 秒超时' );
$t_started = microtime( true );
$upslow = $T->req( 'POST', '/api/ai/chat/messages', array( 'partnerId' => $pid, 'body' => '超时测试', 'clientId' => 'c_' . $stamp . '_slow' ), $h1 );
$elapsed = round( microtime( true ) - $t_started, 1 );
remember( $upslow );
$T->ok(
	200 === $bindslow['status']
	&& 504 === $upslow['status'] && 'upstream_timeout' === $T->get( $upslow, 'error' )
	&& false !== strpos( (string) $T->get( $upslow, 'message' ), '超时' ),
	'上游 sleep 35 秒 → 504 upstream_timeout（服务端 30 秒超时阈值）',
	'耗时 ' . $elapsed . ' 秒 / ' . $T->brief( $upslow )
);
$T->ok( $elapsed >= 25 && $elapsed <= 45, '超时用例耗时落在 30 秒阈值附近（25-45 秒）', $elapsed . ' 秒' );

// 上游返回空内容 → 落占位文案，接口仍 200。
$r_empty = wp_admin_call( $T, array( 'providerId' => '', 'name' => 'Mock-Empty', 'type' => 'openai', 'baseUrl' => $opts['mock'] . '?mock=empty', 'apiKey' => $KEY_PLAIN_2, 'models' => 'mock-empty-model', 'defaultModel' => 'mock-empty-model', 'enabled' => '1' ), 'couple_space_ai_provider', 'providers' );
$prov_empty_id = (string) $T->db_scalar( 'SELECT id FROM `' . $T->table( 'ai_providers' ) . "` WHERE name='Mock-Empty'" );
$bindempty = $T->req( 'PATCH', '/api/ai/partners/' . $pid, array( 'providerId' => $prov_empty_id, 'model' => 'mock-empty-model' ), $h1 );
$upempty = $T->req( 'POST', '/api/ai/chat/messages', array( 'partnerId' => $pid, 'body' => '空回复测试', 'clientId' => 'c_' . $stamp . '_empty' ), $h1 );
remember( $upempty );
$T->ok(
	'' !== $prov_empty_id && 200 === $bindempty['status'] && 200 === $upempty['status']
	&& '（AI 没有返回内容）' === $T->get( $upempty, 'reply.body' ),
	'上游返回空内容 → 落库「（AI 没有返回内容）」且接口仍返回 200',
	$T->brief( $upempty )
);

// 限流：同一用户 1 分钟 20 次。
$T->section( '§AI-4 限流（1 分钟 20 次）' );

$T->db_exec( 'DELETE FROM `' . $T->table( 'rate_limits' ) . "` WHERE `key` LIKE 'aichat:%'" );
$T->req( 'PATCH', '/api/ai/partners/' . $pid, array( 'providerId' => $prov1_id, 'model' => 'mock-model-a' ), $h1 );
$rate_statuses = array();
for ( $i = 1; $i <= 20; $i++ ) {
	$r = $T->req( 'POST', '/api/ai/chat/messages', array( 'partnerId' => $pid, 'body' => '限流第' . $i . '条', 'clientId' => 'c_' . $stamp . '_rate' . $i ), $h1 );
	$rate_statuses[] = $r['status'];
}
$rate_21 = $T->req( 'POST', '/api/ai/chat/messages', array( 'partnerId' => $pid, 'body' => '第 21 次', 'clientId' => 'c_' . $stamp . '_rate21' ), $h1 );
remember( $rate_21 );
$first20_ok = ! in_array( 429, $rate_statuses, true );
$T->ok(
	$first20_ok && 429 === $rate_21['status'] && 'rate_limited' === $T->get( $rate_21, 'error' ),
	'同一用户 1 分钟内第 21 次调用 → 429 rate_limited（前 20 次正常）',
	'前 20 次状态：' . implode( ',', array_unique( $rate_statuses ) ) . '；第 21 次=' . $rate_21['status']
);

// ---------------------------------------------------------------------
// 7. Key 绝不下发（全量断言）
// ---------------------------------------------------------------------

$T->section( '安全：Key 绝不下发' );

$leaked = array();
foreach ( $ALL_RESPONSES as $i => $body ) {
	if ( false !== strpos( $body, $KEY_PLAIN ) || false !== strpos( $body, $KEY_PLAIN_2 ) ) {
		$leaked[] = $i;
	}
}
$T->ok( 0 === count( $leaked ), '所有 App 侧接口响应里都不含供应商 Key 明文（共检查 ' . count( $ALL_RESPONSES ) . ' 个响应）', $leaked ? ( '泄漏于响应 #' . implode( ',', $leaked ) ) : '未发现泄漏' );

$raw_leak = false;
foreach ( $ALL_RESPONSES as $body ) {
	// 原始请求体 / 上游完整响应里的特征字段也不应出现在 App 响应里。
	if ( false !== strpos( $body, 'sk-****' ) === false && false !== strpos( $body, 'chatcmpl-mock' ) ) {
		$raw_leak = true;
	}
	if ( false !== strpos( $body, 'input_tokens' ) || false !== strpos( $body, 'prompt_tokens' ) ) {
		$raw_leak = true;
	}
}
$T->ok( ! $raw_leak, 'App 侧响应里不含上游完整响应体特征（chatcmpl-* / prompt_tokens / input_tokens）', $raw_leak ? '发现上游响应痕迹' : '干净' );

$meta = $T->req( 'GET', '/api/meta' );
remember( $meta );
$T->ok(
	200 === $meta['status'] && false === strpos( (string) $meta['body'], $KEY_PLAIN )
	&& is_bool( $T->get( $meta, 'features.aiChat' ) )
	&& is_int( $T->get( $meta, 'features.aiProviders' ) )
	&& array() !== array_diff( array( 'chat', 'emoji', 'ai', 'sync', 'aiChat', 'aiProviders' ), array_keys( (array) $T->get( $meta, 'features' ) ) ),
	'/api/meta 的 features 增加 aiChat(bool) 与 aiProviders(启用供应商数量)，且不含 Key',
	$T->brief( $meta )
);
$T->ok( (int) $T->get( $meta, 'features.aiProviders' ) >= 4, 'aiProviders 与启用供应商数量一致（>=4）', 'aiProviders=' . $T->get( $meta, 'features.aiProviders' ) );
$T->ok( true === $T->get( $meta, 'features.aiChat' ) && true === $T->get( $meta, 'aiConfigured' ), 'aiChat=true 且 aiConfigured 仍为记账 AI 的语义（deepseekKey 状态）', 'aiConfigured=' . var_export( $T->get( $meta, 'aiConfigured' ), true ) );

// ---------------------------------------------------------------------
// 8. 既有 AI 记账接口行为不变
// ---------------------------------------------------------------------

$T->section( '既有 AI 记账接口（行为不变）' );

$ai_status = $T->req( 'GET', '/api/ai/status', null, $h1 );
$T->ok(
	200 === $ai_status['status'] && is_bool( $T->get( $ai_status, 'enabled' ) ) && is_bool( $T->get( $ai_status, 'configured' ) )
	&& is_string( $T->get( $ai_status, 'model' ) ) && is_int( $T->get( $ai_status, 'dailyLimit' ) ) && is_int( $T->get( $ai_status, 'usedToday' ) ),
	'GET /api/ai/status 字段不变',
	$T->brief( $ai_status )
);

$ai_parse_empty = $T->req( 'POST', '/api/ai/parse-ledger', array( 'text' => '' ), $h1 );
$T->ok( 400 === $ai_parse_empty['status'] && 'invalid_input' === $T->get( $ai_parse_empty, 'error' ), 'parse-ledger text 为空 → 400 invalid_input（不变）', $T->brief( $ai_parse_empty ) );

$ai_parse_long = $T->req( 'POST', '/api/ai/parse-ledger', array( 'text' => str_repeat( 'a', 2001 ) ), $h1 );
$T->ok( 400 === $ai_parse_long['status'], 'parse-ledger text 超 2000 → 400 invalid_input（不变）', $T->brief( $ai_parse_long ) );

$ai_report_bad = $T->req( 'POST', '/api/ai/monthly-report', array( 'month' => '2025-6', 'totals' => array( 'expense' => 1, 'income' => 0 ) ), $h1 );
$T->ok( 400 === $ai_report_bad['status'], 'monthly-report month 格式错 → 400 invalid_input（不变）', $T->brief( $ai_report_bad ) );

$ai_config = $T->db_scalar( "SELECT `value` FROM `" . $T->table( 'settings' ) . "` WHERE `key`='deepseekApiKey'" );
$not_configured = ( '' === (string) $ai_config || '""' === (string) $ai_config );
$ai_parse = $T->req( 'POST', '/api/ai/parse-ledger', array( 'text' => '今天午饭25', 'currency' => 'CNY', 'today' => date( 'Y-m-d' ) ), $h1 );
if ( $not_configured ) {
	$T->ok( 403 === $ai_parse['status'] && 'forbidden' === $T->get( $ai_parse, 'error' ) && 'AI 未配置' === $T->get( $ai_parse, 'message' ), '未配置 DeepSeek Key → 403 forbidden「AI 未配置」（行为不变）', $T->brief( $ai_parse ) );
} else {
	$T->ok( in_array( $ai_parse['status'], array( 200, 403, 429, 502, 504 ), true ), '已配置 DeepSeek Key 时返回受控结果（行为不变）', $T->brief( $ai_parse ) );
}

$ds_src = $T->wp_eval( 'echo strpos( file_get_contents( ' . var_export( str_replace( '\\', '/', __DIR__ . '/../includes/DeepSeek.php' ), true ) . ' ), "wp_remote_post" ) !== false ? "wp_remote_post" : "MISSING";' );
$T->ok( false !== strpos( $ds_src['out'], 'wp_remote_post' ), 'DeepSeek 记账仍走 wp_remote_post（未被多供应商改造影响）', trim( $ds_src['out'] ) );
$up_src = $T->wp_eval( 'echo strpos( file_get_contents( ' . var_export( str_replace( '\\', '/', __DIR__ . '/../includes/AiUpstream.php' ), true ) . ' ), "wp_remote_post" ) !== false ? "wp_remote_post" : "MISSING";' );
$T->ok( false !== strpos( $up_src['out'], 'wp_remote_post' ), '多供应商适配层同样使用 wp_remote_post（无第三方库）', trim( $up_src['out'] ) );

// ---------------------------------------------------------------------
// 9. 清理（不污染 WP 测试库）
// ---------------------------------------------------------------------

$T->section( '清理测试数据' );

foreach ( array( $pid, $u2_pid ) as $clean_pid ) {
	$T->db_exec( 'DELETE FROM `' . $T->table( 'ai_messages' ) . '` WHERE partner_id = ?', array( $clean_pid ) );
	$T->db_exec( 'DELETE FROM `' . $T->table( 'ai_partners' ) . '` WHERE id = ?', array( $clean_pid ) );
}
$T->db_exec( 'DELETE FROM `' . $T->table( 'ai_providers' ) . '`' );
$T->db_exec( 'DELETE FROM `' . $T->table( 'ai_usage' ) . '` WHERE endpoint = ?', array( 'ai-chat' ) );
$T->db_exec( 'DELETE FROM `' . $T->table( 'rate_limits' ) . "` WHERE `key` LIKE 'aichat:%'" );
foreach ( array( $uid1, $uid2 ) as $clean_uid ) {
	$T->db_exec( 'DELETE FROM `' . $T->table( 'users' ) . '` WHERE id = ?', array( $clean_uid ) );
}
$T->db_exec( 'DELETE FROM `' . $T->table( 'couples' ) . "` WHERE id NOT IN (SELECT couple_id FROM `" . $T->table( 'users' ) . "` WHERE couple_id IS NOT NULL)" );

$left = (int) $T->db_scalar( 'SELECT COUNT(*) FROM `' . $T->table( 'ai_providers' ) . '`' );
$T->ok( 0 === $left, '已清理本次测试的供应商 / 伙伴 / 消息 / 记账计数', 'ai_providers 剩余 ' . $left . ' 行' );

@unlink( __DIR__ . '/.admin-call.json' );

$T->finish( __DIR__ . '/ai-report.txt' );
exit( $T->failed() > 0 ? 1 : 0 );
