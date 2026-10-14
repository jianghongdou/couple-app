<?php
/**
 * 应用设置页视图。
 *
 * @package CoupleSpace
 * @var array<string,mixed> $settings 后台设置视图。
 */

defined( 'ABSPATH' ) || exit;

$s = $settings;

?>
<div class="wrap">
	<h1>情侣空间 · 应用设置</h1>
	<?php \CoupleSpace\Admin::render_notices(); ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'couple_space_app_settings' ); ?>
		<input type="hidden" name="action" value="couple_space_app_settings" />

		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row">开放注册</th>
					<td>
						<label>
							<input type="checkbox" name="registerOpen" value="1" <?php checked( true, (bool) $s['registerOpen'] ); ?> />
							允许 App 用户自助注册
						</label>
						<p class="description">关闭后 <code>POST /api/auth/register</code> 返回 403 forbidden。</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="announcement">公告</label></th>
					<td>
						<textarea id="announcement" name="announcement" rows="4" class="large-text"><?php echo esc_textarea( (string) $s['announcement'] ); ?></textarea>
						<p class="description">客户端启动时通过 <code>/api/meta</code> 拉取并展示。</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="minAppBuild">最低版本号 minAppBuild</label></th>
					<td>
						<input type="number" min="0" step="1" class="small-text" id="minAppBuild" name="minAppBuild"
							value="<?php echo esc_attr( (string) (int) $s['minAppBuild'] ); ?>" />
						<p class="description">低于该版本号的 App 会被提示必须升级。</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="latestAppBuild">最新版本号 latestAppBuild</label></th>
					<td>
						<input type="number" min="0" step="1" class="small-text" id="latestAppBuild" name="latestAppBuild"
							value="<?php echo esc_attr( (string) (int) $s['latestAppBuild'] ); ?>" />
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="updateURL">更新地址 updateURL</label></th>
					<td>
						<input type="url" class="regular-text" id="updateURL" name="updateURL"
							value="<?php echo esc_attr( (string) $s['updateURL'] ); ?>" placeholder="https://example.com/download" />
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="maxEmojiPacks">表情包上限（每个情侣空间）</label></th>
					<td>
						<input type="number" min="1" step="1" class="small-text" id="maxEmojiPacks" name="maxEmojiPacks"
							value="<?php echo esc_attr( (string) (int) $s['maxEmojiPacks'] ); ?>" />
						<p class="description">默认 30。超出后创建表情包返回 409 conflict。</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="maxMessagesPerDay">每日消息上限（每人）</label></th>
					<td>
						<input type="number" min="0" step="1" class="small-text" id="maxMessagesPerDay" name="maxMessagesPerDay"
							value="<?php echo esc_attr( (string) (int) $s['maxMessagesPerDay'] ); ?>" />
						<p class="description">默认 3000。0 表示不限制。</p>
					</td>
				</tr>
				<tr>
					<th scope="row">信任代理头</th>
					<td>
						<label>
							<input type="checkbox" name="trustProxy" value="1" <?php checked( true, (bool) \CoupleSpace\Config::option( 'trustProxy', true ) ); ?> />
							信任 <code>X-Forwarded-For</code> / <code>X-Real-IP</code>
						</label>
						<p class="description">宝塔 + Nginx 反代场景建议保持开启（限流与日志里记录真实 IP）；直连时关闭更安全。</p>
					</td>
				</tr>
			</tbody>
		</table>

		<?php submit_button( '保存应用设置' ); ?>
	</form>
</div>
