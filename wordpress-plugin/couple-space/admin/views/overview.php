<?php
/**
 * 总览页视图。
 *
 * @package CoupleSpace
 * @var array<string,mixed> $urls          App 服务器地址建议。
 * @var bool                $db_ok         数据库连通。
 * @var string              $password_src  App 后台密码来源。
 * @var bool                $in_database   后台密码是否已在数据库。
 * @var string              $initial_pwd   选项里的初始密码（明文，供用户查看）。
 * @var array<string,int>   $stats         统计。
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap">
	<h1>情侣空间 · 总览</h1>

	<div class="notice notice-info">
		<p><strong>App 里「服务器地址」请填这一个：</strong>
			<code class="couple-url"><?php echo esc_html( $urls['primary'] ); ?></code>
			<button type="button" class="button button-small" data-couple-copy="<?php echo esc_attr( $urls['primary'] ); ?>">复制</button>
		</p>
		<p>App 会自己拼接 <code>/api/health</code>、<code>/api/auth/login</code> 等路径，<strong>不要</strong>在地址后面手动加 <code>/api</code>。</p>
		<p>如果第一种地址在你的服务器上被其它规则占用，可以改成下面两种之一（功能完全一样）：</p>
		<ul style="list-style:disc;margin-left:22px">
			<?php foreach ( $urls['alternatives'] as $alt ) : ?>
				<li><code><?php echo esc_html( $alt ); ?></code>
					<button type="button" class="button button-small" data-couple-copy="<?php echo esc_attr( rtrim( $alt, '=' ) ); ?>">复制</button>
				</li>
			<?php endforeach; ?>
		</ul>
		<p class="description"><?php echo esc_html( $urls['note'] ); ?></p>
	</div>

	<div class="notice notice-warning">
		<p><strong>关于实时推送（WebSocket）：</strong>WordPress 是「请求-响应」模型，无法常驻 WebSocket 进程，因此本插件<strong>不提供 WebSocket</strong>。
			聊天消息、已读、同步都通过 App 已有的 <strong>5 秒轮询</strong>兜底，功能不受影响，只是对方发来的消息最多晚 5 秒出现。</p>
	</div>

	<h2 class="title">服务状态</h2>
	<table class="widefat striped" style="max-width:900px">
		<tbody>
			<tr><td style="width:220px">插件版本</td><td><?php echo esc_html( COUPLE_SPACE_VERSION ); ?></td></tr>
			<tr><td>数据库</td><td><?php echo $db_ok ? '<span style="color:#46b450">已连接</span>' : '<span style="color:#dc3232">连接失败</span>'; ?></td></tr>
			<tr><td>站点地址</td><td><code><?php echo esc_html( home_url() ); ?></code></td></tr>
			<tr><td>接口可用性</td><td>
				<a href="<?php echo esc_url( home_url( '/api/health' ) ); ?>" target="_blank" rel="noopener">/api/health</a> ·
				<a href="<?php echo esc_url( home_url( '/api/meta' ) ); ?>" target="_blank" rel="noopener">/api/meta</a> ·
				<a href="<?php echo esc_url( home_url( '/wp-json/couple/v1/api/health' ) ); ?>" target="_blank" rel="noopener">/wp-json/couple/v1/api/health</a>
			</td></tr>
			<tr><td>App 后台密码来源</td><td>
				<?php if ( 'database' === $password_src ) : ?>
					<strong>数据库</strong>（bcrypt 哈希，已通过插件后台或 WP-CLI 设置过）
				<?php else : ?>
					<strong>WordPress 选项里的初始密码</strong>：<code><?php echo esc_html( $initial_pwd ); ?></code>
					（第一次用它登录 App 后台后会自动转成数据库哈希，可在「服务器信息」页修改）
				<?php endif; ?>
			</td></tr>
		</tbody>
	</table>

	<h2 class="title">数据概览</h2>
	<table class="widefat striped" style="max-width:900px">
		<tbody>
			<tr><td style="width:220px">注册用户</td><td><?php echo (int) $stats['users']; ?></td></tr>
			<tr><td>已绑定情侣</td><td><?php echo (int) $stats['couples']; ?>（累计创建 <?php echo (int) $stats['couplesTotal']; ?>）</td></tr>
			<tr><td>聊天消息</td><td><?php echo (int) $stats['messages']; ?></td></tr>
			<tr><td>表情包 / 表情</td><td><?php echo (int) $stats['packs']; ?> / <?php echo (int) $stats['items']; ?></td></tr>
			<tr><td>同步对象</td><td><?php echo (int) $stats['syncObjects']; ?></td></tr>
			<tr><td>今日 AI 调用</td><td><?php echo (int) $stats['aiToday']; ?>（累计 <?php echo (int) $stats['aiTotal']; ?>）</td></tr>
		</tbody>
	</table>

	<h2 class="title">下一步</h2>
	<ol style="line-height:1.9">
		<li>在 <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . \CoupleSpace\Admin::SLUG . '-ai' ) ); ?>">AI 设置</a> 里填写 DeepSeek API Key（记账 / 月度报告要用）。</li>
		<li>在 <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . \CoupleSpace\Admin::SLUG . '-app' ) ); ?>">应用设置</a> 里决定是否开放注册、填公告与版本号。</li>
		<li>打开 App，在「服务器地址」里填上面的地址，点「测试连接」。</li>
	</ol>
</div>

<script>
( function () {
	document.querySelectorAll( '[data-couple-copy]' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			var text = btn.getAttribute( 'data-couple-copy' ) || '';
			var done = function () {
				var old = btn.textContent;
				btn.textContent = '已复制';
				setTimeout( function () { btn.textContent = old; }, 1200 );
			};
			if ( navigator.clipboard && navigator.clipboard.writeText ) {
				navigator.clipboard.writeText( text ).then( done );
			} else {
				var ta = document.createElement( 'textarea' );
				ta.value = text;
				document.body.appendChild( ta );
				ta.select();
				try { document.execCommand( 'copy' ); done(); } catch ( e ) {}
				document.body.removeChild( ta );
			}
		} );
	} );
}() );
</script>
