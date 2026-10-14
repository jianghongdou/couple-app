<?php
/**
 * 服务器信息页视图。
 *
 * @package CoupleSpace
 * @var array<int,array<string,mixed>> $tables       自建表状态。
 * @var bool                           $db_ok        数据库连通。
 * @var array<string,mixed>            $emoji        表情目录状态。
 * @var array<string,mixed>            $urls         App 服务器地址建议。
 * @var string                         $password_src App 后台密码来源。
 * @var bool                           $in_database  后台密码是否已在数据库。
 * @var string                         $initial_pwd  初始密码（明文）。
 * @var string                         $jwt_source   JWT 密钥来源。
 * @var int                            $schema       结构版本。
 * @var bool                           $tables_ok    核心表是否齐全。
 */

defined( 'ABSPATH' ) || exit;

use CoupleSpace\Admin;
use CoupleSpace\Config;
use CoupleSpace\Db;
?>
<div class="wrap">
	<h1>情侣空间 · 服务器信息</h1>
	<?php Admin::render_notices(); ?>

	<h2 class="title">App 服务器地址（该填哪个）</h2>
	<div class="notice notice-info" style="margin:8px 0">
		<p><strong>推荐：</strong><code><?php echo esc_html( $urls['primary'] ); ?></code>
			<button type="button" class="button button-small" data-couple-copy="<?php echo esc_attr( $urls['primary'] ); ?>">复制</button>
			（App 自己会加 <code>/api/...</code>，不要再手动拼 <code>/api</code>）</p>
		<p><strong>备用一：</strong><code><?php echo esc_html( $urls['alternatives'][0] ); ?></code></p>
		<p><strong>备用二：</strong><code><?php echo esc_html( $urls['alternatives'][1] ); ?></code>（固定链接没开时也能用）</p>
	</div>

	<h2 class="title">数据库</h2>
	<table class="widefat striped" style="max-width:900px">
		<tbody>
			<tr><td style="width:260px">连接</td><td><?php echo $db_ok ? '<span style="color:#46b450">正常</span>' : '<span style="color:#dc3232">失败</span>'; ?></td></tr>
			<tr><td>数据库类型 / 版本</td><td><?php echo esc_html( Db::server_engine() . ' ' . Db::server_version() ); ?></td></tr>
			<tr><td>主机 / 库名</td><td><code><?php echo esc_html( ( defined( 'DB_HOST' ) ? DB_HOST : '' ) . ' / ' . ( defined( 'DB_NAME' ) ? DB_NAME : '' ) ); ?></code></td></tr>
			<tr><td>用户名</td><td><code><?php echo esc_html( defined( 'DB_USER' ) ? DB_USER : '' ); ?></code></td></tr>
			<tr><td>字符集</td><td><?php echo esc_html( Config::db_charset() ); ?></td></tr>
			<tr><td>连接来源</td><td><strong>自动取自 WordPress 的 <code>wp-config.php</code></strong>（DB_NAME / DB_USER / DB_PASSWORD / DB_HOST），本插件无需再配置数据库。</td></tr>
			<tr><td>结构版本</td><td><?php echo (int) $schema; ?>（插件版本 <?php echo esc_html( COUPLE_SPACE_VERSION ); ?>）</td></tr>
			<tr><td>核心表</td><td><?php echo $tables_ok ? '<span style="color:#46b450">齐全</span>' : '<span style="color:#dc3232">缺失（请停用后重新启用插件）</span>'; ?></td></tr>
		</tbody>
	</table>

	<h3>自建表状态</h3>
	<table class="widefat striped" style="max-width:900px">
		<thead><tr><th>表名</th><th style="width:110px">是否存在</th><th style="width:100px">列数</th><th style="width:120px">行数</th></tr></thead>
		<tbody>
			<?php foreach ( $tables as $t ) : ?>
				<tr>
					<td><code><?php echo esc_html( $t['name'] ); ?></code></td>
					<td><?php echo $t['exists'] ? '<span style="color:#46b450">是</span>' : '<span style="color:#dc3232">否</span>'; ?></td>
					<td><?php echo (int) $t['columns']; ?></td>
					<td><?php echo (int) $t['rows']; ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<h2 class="title">表情图片目录</h2>
	<table class="widefat striped" style="max-width:900px">
		<tbody>
			<tr><td style="width:260px">目录</td><td><code><?php echo esc_html( $emoji['dir'] ); ?></code></td></tr>
			<tr><td>存在 / 可写</td><td>
				<?php echo $emoji['exists'] ? '<span style="color:#46b450">存在</span>' : '<span style="color:#dc3232">不存在</span>'; ?>
				/
				<?php echo $emoji['writable'] ? '<span style="color:#46b450">可写</span>' : '<span style="color:#dc3232">不可写</span>'; ?>
			</td></tr>
			<tr><td>文件数 / 占用</td><td><?php echo (int) $emoji['files']; ?> 个 / <?php echo esc_html( Admin::human_bytes( $emoji['bytes'] ) ); ?></td></tr>
		</tbody>
	</table>


	<h2 class="title">App 后台密码</h2>
	<p>App 后台（<code>/admin/api/login</code>）的密码管理、用户 token 密钥与初始密码，已移到
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . \CoupleSpace\Admin::SLUG . '-security' ) ); ?>">情侣空间 → App 后台密码</a> 页面。</p>

	<h2 class="title">运行环境</h2>
	<table class="widefat striped" style="max-width:900px">
		<tbody>
			<tr><td style="width:260px">WordPress</td><td><?php echo esc_html( get_bloginfo( 'version' ) ); ?></td></tr>
			<tr><td>PHP</td><td><?php echo esc_html( PHP_VERSION ); ?>（<?php echo esc_html( PHP_SAPI ); ?>）</td></tr>
			<tr><td>时区</td><td><?php echo esc_html( wp_timezone_string() ); ?></td></tr>
			<tr><td>WebSocket</td><td><span style="color:#dc3232">不支持</span> —— WordPress 无法常驻进程，聊天走 App 的 5 秒轮询兜底。</td></tr>
		</tbody>
	</table>
</div>

<script>
( function () {
	document.querySelectorAll( '[data-couple-copy]' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			var text = btn.getAttribute( 'data-couple-copy' ) || '';
			if ( navigator.clipboard && navigator.clipboard.writeText ) {
				navigator.clipboard.writeText( text );
				var old = btn.textContent;
				btn.textContent = '已复制';
				setTimeout( function () { btn.textContent = old; }, 1200 );
			}
		} );
	} );
}() );
</script>
