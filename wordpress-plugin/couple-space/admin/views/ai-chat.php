<?php
/**
 * AI 聊天设置页视图（docs/API-AI聊天.md §AI-5 第 5/6 条）。
 *
 * @package CoupleSpace
 * @var array<string,mixed> $settings  后台设置视图。
 * @var array<int,mixed>    $providers 启用中的供应商。
 * @var array<string,int>   $stats     统计数字。
 */

defined( 'ABSPATH' ) || exit;

$s              = $settings;
$provider_list  = is_array( $providers ) ? $providers : array();
$current_pid    = isset( $s['aiProviderId'] ) ? (string) $s['aiProviderId'] : '';
$current_model  = isset( $s['aiProviderModel'] ) ? (string) $s['aiProviderModel'] : '';
$chat_on        = ! array_key_exists( 'aiChatEnabled', $s ) || false !== $s['aiChatEnabled'];

// 当前默认供应商的模型列表（用于默认模型下拉）。
$current_models = array();
$current_name   = '';
foreach ( $provider_list as $p ) {
	if ( (string) $p['id'] === $current_pid ) {
		$current_models = isset( $p['models'] ) && is_array( $p['models'] ) ? $p['models'] : array();
		$current_name   = (string) $p['name'];
	}
}
?>
<div class="wrap">
	<h1>情侣空间 · AI 聊天</h1>
	<?php \CoupleSpace\Admin::render_notices(); ?>

	<div class="notice notice-info">
		<p>AI 聊天让 App 用户可以创建「自定义聊天对象」（AI 伙伴），服务端用这里配置的供应商代发大模型。
			App 端只拿到「有哪些供应商、可选模型」，<strong>永远拿不到 Key</strong>。未绑定情侣的用户也能使用 AI 聊天。</p>
	</div>

	<h2>运行情况</h2>
	<table class="widefat striped" style="max-width:720px">
		<tbody>
			<tr><th style="width:40%">AI 伙伴数量</th><td><?php echo (int) $stats['partners']; ?> 个（每个用户最多 10 个）</td></tr>
			<tr><th>AI 聊天消息数</th><td><?php echo (int) $stats['messages']; ?> 条</td></tr>
			<tr><th>供应商</th><td><?php echo (int) $stats['providers']; ?> 个（启用 <?php echo (int) $stats['enabled']; ?> 个）</td></tr>
			<tr><th>今日 AI 调用</th><td><?php echo (int) \CoupleSpace\Db::count_ai_usage_today(); ?> 次</td></tr>
		</tbody>
	</table>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'couple_space_ai_chat' ); ?>
		<input type="hidden" name="action" value="couple_space_ai_chat" />

		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row">AI 聊天总开关</th>
					<td>
						<label>
							<input type="checkbox" name="aiChatEnabled" value="1" <?php checked( true, $chat_on ); ?> />
							开启 AI 聊天（/api/ai/partners 与 /api/ai/chat/*）
						</label>
						<p class="description">关闭后 App 端发消息会收到 <code>403 forbidden</code>（AI 聊天已关闭）；<code>/api/meta</code> 的 <code>features.aiChat</code> 变为 false。</p>
						<label style="display:block;margin-top:6px">
							<input type="checkbox" name="aiEnabled" value="1" <?php checked( true, (bool) $s['aiEnabled'] ); ?> />
							同时保持 AI 记账功能开启（关闭会影响 <code>/api/ai/parse-ledger</code> 等既有接口）
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aiDailyLimit">每用户每日上限</label></th>
					<td>
						<input type="number" min="0" step="1" class="small-text" id="aiDailyLimit" name="aiDailyLimit"
							value="<?php echo esc_attr( (string) (int) $s['aiDailyLimit'] ); ?>" />
						<p class="description">默认 200。统计「用户消息 + AI 回复」，超出后 App 端收到 <code>429 rate_limited</code>。0 表示不限制。</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aiProviderId">默认供应商</label></th>
					<td>
						<select id="aiProviderId" name="aiProviderId">
							<option value="">（自动：用后台设为「默认」的那个供应商）</option>
							<?php foreach ( $provider_list as $p ) : ?>
								<option value="<?php echo esc_attr( (string) $p['id'] ); ?>" <?php selected( $current_pid, (string) $p['id'] ); ?>>
									<?php echo esc_html( (string) $p['name'] . '（' . \CoupleSpace\AiAdmin::type_label( (string) $p['type'] ) . '）' ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description">只列出<strong>已启用</strong>的供应商。留空表示自动使用后台里设为「默认」的供应商。</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aiProviderModel">默认模型</label></th>
					<td>
						<input type="text" class="regular-text" id="aiProviderModel" name="aiProviderModel"
							value="<?php echo esc_attr( $current_model ); ?>" placeholder="deepseek-chat" list="ai-default-models" />
						<?php if ( count( $current_models ) > 0 ) : ?>
							<datalist id="ai-default-models">
								<?php foreach ( $current_models as $m ) : ?>
									<option value="<?php echo esc_attr( (string) $m ); ?>"></option>
								<?php endforeach; ?>
							</datalist>
						<?php endif; ?>
						<p class="description">
							留空表示用该供应商自己的默认模型。
							<?php if ( '' !== $current_name ) : ?>
								当前选择：<code><?php echo esc_html( $current_name ); ?></code>
							<?php endif; ?>
						</p>
					</td>
				</tr>
			</tbody>
		</table>

		<?php submit_button( '保存 AI 聊天设置' ); ?>
	</form>

	<h2>AI 伙伴与消息</h2>
	<p class="description">
		<code>/api/meta</code> 的 <code>features.aiChat</code> 表示聊天开关、<code>features.aiProviders</code> 表示启用供应商数量、
		<code>aiConfigured</code> 表示记账 AI 是否配了 Key。
		AI 伙伴由 App 用户自己创建（每人最多 10 个），可用的供应商与模型来自<a href="<?php echo esc_url( admin_url( 'admin.php?page=couple-space-ai-providers' ) ); ?>">AI 供应商</a>页。
	</p>
</div>
