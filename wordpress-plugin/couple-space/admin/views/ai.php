<?php
/**
 * AI 设置页视图。
 *
 * @package CoupleSpace
 * @var array<string,mixed> $settings 后台设置视图。
 */

defined( 'ABSPATH' ) || exit;
$s = $settings;
?>
<div class="wrap">
	<h1>情侣空间 · AI 设置</h1>
	<?php \CoupleSpace\Admin::render_notices(); ?>

	<div class="notice notice-info">
		<p>DeepSeek 的 API Key <strong>只存在服务端</strong>（AES-256-GCM 加密后落库），客户端永远拿不到。
			记账数据本身永远只存手机本地，服务端只做「文本 → 结构化 JSON」的转换，不落库明细。</p>
	</div>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'couple_space_settings' ); ?>
		<input type="hidden" name="action" value="couple_space_settings" />

		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row"><label for="deepseekApiKey">DeepSeek API Key</label></th>
					<td>
						<input type="password" class="regular-text" id="deepseekApiKey" name="deepseekApiKey" value="" autocomplete="off"
							placeholder="<?php echo $s['deepseekApiKeyConfigured'] ? '已配置，留空表示不修改' : 'sk-...'; ?>" />
						<p class="description">
							当前：<code><?php echo esc_html( $s['deepseekApiKeyMasked'] ? $s['deepseekApiKeyMasked'] : '未配置' ); ?></code>
							（只显示掩码，不会回显明文）
						</p>
						<label style="display:block;margin-top:6px">
							<input type="checkbox" name="clearDeepseekApiKey" value="1" />
							清空已保存的 Key
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="deepseekBaseUrl">API 基础地址</label></th>
					<td>
						<input type="text" class="regular-text" id="deepseekBaseUrl" name="deepseekBaseUrl"
							value="<?php echo esc_attr( (string) $s['deepseekBaseUrl'] ); ?>" placeholder="https://api.deepseek.com" />
						<p class="description">默认 <code>https://api.deepseek.com</code>，留空即用默认值。</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="deepseekModel">模型</label></th>
					<td>
						<input type="text" class="regular-text" id="deepseekModel" name="deepseekModel"
							value="<?php echo esc_attr( (string) $s['deepseekModel'] ); ?>" placeholder="deepseek-chat" />
					</td>
				</tr>
				<tr>
					<th scope="row">AI 功能开关</th>
					<td>
						<label>
							<input type="checkbox" name="aiEnabled" value="1" <?php checked( true, (bool) $s['aiEnabled'] ); ?> />
							开启 AI（记账解析 / 月度报告）
						</label>
						<p class="description">关闭后客户端调用 <code>/api/ai/*</code> 会收到 403 forbidden（AI 功能已关闭）。</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aiDailyLimit">每日调用上限</label></th>
					<td>
						<input type="number" min="0" step="1" id="aiDailyLimit" name="aiDailyLimit"
							value="<?php echo esc_attr( (string) (int) $s['aiDailyLimit'] ); ?>" class="small-text" />
						<p class="description">0 表示不限制。超出后客户端会收到 429 rate_limited。</p>
					</td>
				</tr>
			</tbody>
		</table>

		<?php submit_button( '保存 AI 设置' ); ?>
	</form>

	<hr />

	<h2>测试 DeepSeek 连接</h2>
	<p class="description">用当前保存的 Key 试跑一条记账文本，验证 Key / 网络是否正常。</p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'couple_space_ai_test' ); ?>
		<input type="hidden" name="action" value="couple_space_ai_test" />
		<p>
			<textarea name="text" rows="3" class="large-text" placeholder="今天午饭25，打车18块5，买菜一共83.6"></textarea>
		</p>
		<?php submit_button( '测试 AI', 'secondary', 'submit', false ); ?>
	</form>
</div>
