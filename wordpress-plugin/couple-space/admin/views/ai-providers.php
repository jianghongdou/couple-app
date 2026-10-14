<?php
/**
 * AI 供应商管理页视图（docs/API-AI聊天.md §AI-5）。
 *
 * @package CoupleSpace
 * @var array<int,mixed>       $providers 供应商列表。
 * @var array<string,mixed>|null $editing 正在编辑的供应商。
 * @var array<string,mixed>|null $default 当前默认供应商。
 */

defined( 'ABSPATH' ) || exit;

$editing = isset( $editing ) ? $editing : null;
$default = isset( $default ) ? $default : null;
$is_edit = is_array( $editing );

$field = static function ( $key, $fallback = '' ) use ( $editing ) {
	if ( is_array( $editing ) && isset( $editing[ $key ] ) ) {
		return $editing[ $key ];
	}
	return $fallback;
};

$models_text = '';
if ( $is_edit && isset( $editing['models'] ) && is_array( $editing['models'] ) ) {
	$models_text = implode( "\n", $editing['models'] );
}
?>
<div class="wrap">
	<h1>情侣空间 · AI 供应商</h1>
	<?php \CoupleSpace\Admin::render_notices(); ?>

	<div class="notice notice-info">
		<p>这里的供应商用于 <strong>AI 聊天</strong>（自定义聊天对象）。API Key 用 AES-256-GCM 加密后落库，
			页面与所有接口都<strong>只回掩码</strong>，客户端永远拿不到 Key。</p>
		<p>类型说明：<code>openai</code> = 任何 OpenAI 兼容服务（OpenAI、通义千问、Kimi、智谱、硅基流动、one-api、Ollama、LM Studio…）；
			<code>deepseek</code> 是 openai 的预设；<code>anthropic</code> 走 <code>/v1/messages</code>；
			<code>gemini</code> 走 <code>/v1beta/models/&lt;model&gt;:generateContent</code>。
			带版本号的 Base URL（如 <code>https://api.deepseek.com/v1</code>）与不带版本号的（如 <code>https://api.deepseek.com</code>）都可以填。</p>
	</div>

	<h2><?php echo $is_edit ? '编辑供应商' : '新增供应商'; ?></h2>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'couple_space_ai_provider' ); ?>
		<input type="hidden" name="action" value="couple_space_ai_provider" />
		<input type="hidden" name="providerId" value="<?php echo esc_attr( $is_edit ? (string) $editing['id'] : '' ); ?>" />

		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row"><label for="ai-provider-name">名称</label></th>
					<td>
						<input type="text" class="regular-text" id="ai-provider-name" name="name" required
							value="<?php echo esc_attr( (string) $field( 'name' ) ); ?>" placeholder="DeepSeek" />
						<p class="description">1-64 个字符，用于在 AI 伙伴里选择。</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="ai-provider-type">类型</label></th>
					<td>
						<?php $cur_type = \CoupleSpace\AiUpstream::normalize_type( (string) $field( 'type', 'openai' ) ); ?>
						<select id="ai-provider-type" name="type">
							<option value="openai" <?php selected( 'openai', $cur_type ); ?>>openai（OpenAI 兼容）</option>
							<option value="deepseek" <?php selected( 'deepseek', $cur_type ); ?>>deepseek（openai 预设）</option>
							<option value="anthropic" <?php selected( 'anthropic', $cur_type ); ?>>anthropic（/v1/messages）</option>
							<option value="gemini" <?php selected( 'gemini', $cur_type ); ?>>gemini（generateContent）</option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="ai-provider-base">Base URL</label></th>
					<td>
						<input type="text" class="regular-text" id="ai-provider-base" name="baseUrl"
							value="<?php echo esc_attr( (string) $field( 'baseUrl' ) ); ?>" placeholder="https://api.deepseek.com/v1" />
						<p class="description">留空即用该类型的默认地址（openai：https://api.openai.com，deepseek：https://api.deepseek.com，
							anthropic：https://api.anthropic.com，gemini：https://generativelanguage.googleapis.com）。</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="ai-provider-key">API Key</label></th>
					<td>
						<input type="password" class="regular-text" id="ai-provider-key" name="apiKey" value="" autocomplete="off"
							placeholder="<?php echo ( $is_edit && ! empty( $editing['apiKeyConfigured'] ) ) ? '已配置，留空表示不修改' : 'sk-...'; ?>" />
						<p class="description">
							当前：<code><?php echo esc_html( ( $is_edit && '' !== (string) $editing['apiKeyMasked'] ) ? (string) $editing['apiKeyMasked'] : '未配置' ); ?></code>
							（只显示掩码，不回显明文）
						</p>
						<label style="display:block;margin-top:6px">
							<input type="checkbox" name="clearApiKey" value="1" />
							清空已保存的 Key
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="ai-provider-models">模型列表</label></th>
					<td>
						<textarea id="ai-provider-models" name="models" rows="4" class="large-text code"
							placeholder="deepseek-chat&#10;deepseek-reasoner"><?php echo esc_textarea( $models_text ); ?></textarea>
						<p class="description">每行一个，或用逗号分隔（也支持中文逗号 / 顿号 / 分号）。</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="ai-provider-default-model">默认模型</label></th>
					<td>
						<input type="text" class="regular-text" id="ai-provider-default-model" name="defaultModel"
							value="<?php echo esc_attr( (string) $field( 'defaultModel' ) ); ?>" placeholder="deepseek-chat" />
						<p class="description">留空则用模型列表的第一个。AI 伙伴没指定模型时用这里的值。</p>
					</td>
				</tr>
				<tr>
					<th scope="row">状态</th>
					<td>
						<label style="display:block">
							<input type="checkbox" name="enabled" value="1" <?php checked( true, $is_edit ? ! empty( $editing['enabled'] ) : true ); ?> />
							启用（停用后 App 里既不能选它，也不会用它发消息）
						</label>
						<label style="display:block;margin-top:4px">
							<input type="checkbox" name="isDefault" value="1" <?php checked( true, $is_edit ? ! empty( $editing['isDefault'] ) : ( null === $default ) ); ?> />
							设为默认供应商
						</label>
					</td>
				</tr>
			</tbody>
		</table>

		<?php submit_button( $is_edit ? '保存供应商' : '添加供应商' ); ?>
		<?php if ( $is_edit ) : ?>
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=couple-space-ai-providers' ) ); ?>">取消编辑</a>
		<?php endif; ?>
	</form>

	<hr />

	<h2>已配置的供应商（<?php echo count( $providers ); ?> 个）</h2>
	<?php if ( 0 === count( $providers ) ) : ?>
		<p>还没有供应商。AI 伙伴在没有任何可用供应商时，App 端发消息会收到 <code>403 ai_not_configured</code>。</p>
	<?php else : ?>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th style="width:22%">名称</th>
					<th style="width:14%">类型</th>
					<th style="width:10%">模型数</th>
					<th style="width:12%">是否默认</th>
					<th style="width:10%">启用</th>
					<th style="width:20%">Key 掩码</th>
					<th style="width:12%">操作</th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $providers as $p ) : ?>
				<tr>
					<td>
						<strong><?php echo esc_html( (string) $p['name'] ); ?></strong>
						<div class="description"><?php echo esc_html( (string) $p['baseUrl'] ); ?></div>
					</td>
					<td><?php echo esc_html( \CoupleSpace\AiAdmin::type_label( (string) $p['type'] ) ); ?></td>
					<td><?php echo (int) count( $p['models'] ); ?></td>
					<td><?php echo ! empty( $p['isDefault'] ) ? '<strong>是</strong>' : '否'; ?></td>
					<td><?php echo ! empty( $p['enabled'] ) ? '✅ 启用' : '— 停用'; ?></td>
					<td><code><?php echo esc_html( '' !== (string) $p['apiKeyMasked'] ? (string) $p['apiKeyMasked'] : '未配置' ); ?></code></td>
					<td>
						<a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=couple-space-ai-providers&edit=' . rawurlencode( (string) $p['id'] ) ) ); ?>">编辑</a>

						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
							<?php wp_nonce_field( 'couple_space_ai_provider' ); ?>
							<input type="hidden" name="action" value="couple_space_ai_provider_test" />
							<input type="hidden" name="providerId" value="<?php echo esc_attr( (string) $p['id'] ); ?>" />
							<button type="submit" class="button button-small">测试</button>
						</form>

						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
							<?php wp_nonce_field( 'couple_space_ai_provider' ); ?>
							<input type="hidden" name="action" value="couple_space_ai_provider_default" />
							<input type="hidden" name="providerId" value="<?php echo esc_attr( (string) $p['id'] ); ?>" />
							<button type="submit" class="button button-small">
								<?php echo ! empty( $p['isDefault'] ) ? '取消默认' : '设为默认'; ?>
							</button>
						</form>

						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline"
							onsubmit="return confirm('确定删除该供应商？');">
							<?php wp_nonce_field( 'couple_space_ai_provider' ); ?>
							<input type="hidden" name="action" value="couple_space_ai_provider_delete" />
							<input type="hidden" name="providerId" value="<?php echo esc_attr( (string) $p['id'] ); ?>" />
							<button type="submit" class="button button-small button-link-delete">删除</button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<p class="description">
			「测试」会用该供应商发一句「你好」，结果与耗时显示在页面顶部的提示条里。
			当前默认供应商：
			<code><?php echo esc_html( null !== $default ? (string) $default['name'] : '无' ); ?></code>
		</p>
	<?php endif; ?>
</div>
