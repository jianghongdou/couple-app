<?php
/**
 * App 后台密码管理页视图。
 *
 * @package CoupleSpace
 * @var string $password_src App 后台密码来源（database / option）。
 * @var bool   $in_database  哈希是否已在数据库。
 * @var string $initial_pwd  选项里的初始密码（明文）。
 * @var string $jwt_source   用户 token 密钥来源。
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap">
	<h1>情侣空间 · App 后台密码</h1>
	<?php \CoupleSpace\Admin::render_notices(); ?>

	<div class="notice notice-warning inline" style="margin:8px 0">
		<p>App 后台登录（<code>POST /admin/api/login</code>）用的是<strong>本插件自己的 JWT 体系</strong>，
			与 WordPress 用户完全无关。这里的密码只影响 <code>/admin/api/*</code> 这批接口。</p>
	</div>

	<table class="widefat striped" style="max-width:900px">
		<tbody>
			<tr><td style="width:260px">当前密码来源</td><td>
				<?php if ( 'database' === $password_src ) : ?>
					<strong>数据库</strong>（bcrypt 哈希存于自建 settings 表，任何接口都不返回哈希）
				<?php else : ?>
					<strong>WordPress 选项里的初始密码</strong>：<code><?php echo esc_html( $initial_pwd ); ?></code>
				<?php endif; ?>
			</td></tr>
			<tr><td>哈希是否已入库</td><td><?php echo $in_database ? '是' : '否（第一次用它登录成功后会自动写入）'; ?></td></tr>
			<tr><td>用户 token 密钥来源</td><td>
				<?php if ( 'option' === $jwt_source ) : ?>
					插件选项里的独立密钥
				<?php elseif ( 'wp-config' === $jwt_source ) : ?>
					由 WordPress <code>wp-config.php</code> 的 <code>AUTH_KEY</code> 派生（默认，装完即用）
				<?php else : ?>
					<code>AUTH_KEY</code> 异常，已用站点地址派生（建议重新生成）
				<?php endif; ?>
			</td></tr>
		</tbody>
	</table>

	<h2 class="title">修改密码</h2>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'couple_space_admin_password' ); ?>
		<input type="hidden" name="action" value="couple_space_admin_password" />
		<input type="hidden" name="mode" value="change" />
		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row"><label for="currentPassword">当前密码</label></th>
					<td><input type="password" class="regular-text" id="currentPassword" name="currentPassword" value="" autocomplete="off" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="newPassword">新密码</label></th>
					<td><input type="password" class="regular-text" id="newPassword" name="newPassword" value="" autocomplete="off" />
						<p class="description">6-64 位。</p></td>
				</tr>
				<tr>
					<th scope="row"><label for="newPassword2">重复新密码</label></th>
					<td><input type="password" class="regular-text" id="newPassword2" name="newPassword2" value="" autocomplete="off" /></td>
				</tr>
			</tbody>
		</table>
		<?php submit_button( '修改 App 后台密码', 'primary', 'submit', false ); ?>
	</form>

	<h2 class="title">忘记密码？直接重置</h2>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'couple_space_admin_password' ); ?>
		<input type="hidden" name="action" value="couple_space_admin_password" />
		<input type="hidden" name="mode" value="reset" />
		<p>
			<input type="password" name="newPassword" value="" placeholder="新密码" autocomplete="off" />
			<input type="password" name="newPassword2" value="" placeholder="重复新密码" autocomplete="off" />
			<?php submit_button( '直接重置', 'secondary', 'submit', false, array( 'onclick' => "return confirm('确定直接重置 App 后台密码？');" ) ); ?>
		</p>
		<p class="description">不需要知道旧密码（你已经是 WordPress 管理员）。等价于 <code>wp couple-space set-admin-password 新密码</code>。</p>
	</form>

	<h2 class="title">高级：密钥与初始密码</h2>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'couple_space_secret' ); ?>
		<input type="hidden" name="action" value="couple_space_secret" />
		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row">重新生成用户 token 密钥</th>
					<td>
						<label><input type="checkbox" name="regenerateJwtSecret" value="1" />
							生成一个随机密钥并保存到插件选项</label>
						<p class="description" style="color:#dc3232">注意：换密钥会让<strong>所有 App 用户立刻掉线</strong>（需要重新登录）。</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="initialAdminPassword">初始后台密码（选项）</label></th>
					<td>
						<input type="text" class="regular-text" id="initialAdminPassword" name="initialAdminPassword" value=""
							placeholder="<?php echo esc_attr( $initial_pwd ); ?>" autocomplete="off" />
						<p class="description">当数据库里还没有密码哈希时用它登录；留空表示不修改。</p>
					</td>
				</tr>
			</tbody>
		</table>
		<?php submit_button( '保存', 'secondary' ); ?>
	</form>
</div>
