<?php
/**
 * 操作日志页视图。
 *
 * @package CoupleSpace
 * @var array<int,array<string,mixed>> $logs 日志。
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap">
	<h1>情侣空间 · 操作日志</h1>
	<?php \CoupleSpace\Admin::render_notices(); ?>

	<p class="description">最近 200 条操作日志（App 后台的登录、改设置、用户管理，以及 WordPress 后台里本插件的管理动作）。</p>

	<table class="widefat striped">
		<thead>
			<tr>
				<th style="width:170px">时间</th>
				<th style="width:230px">动作</th>
				<th style="width:140px">IP</th>
				<th>详情</th>
			</tr>
		</thead>
		<tbody>
		<?php if ( empty( $logs ) ) : ?>
			<tr><td colspan="4">暂无日志。</td></tr>
		<?php else : ?>
			<?php foreach ( $logs as $log ) : ?>
				<tr>
					<td><?php echo esc_html( \CoupleSpace\Admin::dt( $log['createdAt'] ) ); ?></td>
					<td><code><?php echo esc_html( $log['action'] ); ?></code></td>
					<td><?php echo esc_html( (string) $log['ip'] ); ?></td>
					<td><?php echo esc_html( (string) $log['detail'] ); ?></td>
				</tr>
			<?php endforeach; ?>
		<?php endif; ?>
		</tbody>
	</table>
</div>
