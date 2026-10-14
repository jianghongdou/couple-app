<?php
/**
 * 统计页视图。
 *
 * @package CoupleSpace
 * @var array<string,int> $stats 统计。
 * @var array<string,int> $disk  磁盘占用。
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap">
	<h1>情侣空间 · 统计</h1>
	<?php \CoupleSpace\Admin::render_notices(); ?>

	<h2 class="title">业务数据</h2>
	<table class="widefat striped" style="max-width:760px">
		<tbody>
			<tr><td style="width:260px">注册用户</td><td><?php echo (int) $stats['users']; ?></td></tr>
			<tr><td>已绑定情侣（未解绑）</td><td><?php echo (int) $stats['couples']; ?></td></tr>
			<tr><td>累计创建情侣空间</td><td><?php echo (int) $stats['couplesTotal']; ?></td></tr>
			<tr><td>聊天消息总数</td><td><?php echo (int) $stats['messages']; ?></td></tr>
			<tr><td>同步对象</td><td><?php echo (int) $stats['syncObjects']; ?></td></tr>
			<tr><td>表情包 / 表情</td><td><?php echo (int) $stats['packs']; ?> / <?php echo (int) $stats['items']; ?></td></tr>
			<tr><td>AI 调用（今日 / 累计）</td><td><?php echo (int) $stats['aiToday']; ?> / <?php echo (int) $stats['aiTotal']; ?></td></tr>
		</tbody>
	</table>

	<h2 class="title">磁盘占用</h2>
	<table class="widefat striped" style="max-width:760px">
		<tbody>
			<tr><td style="width:260px">数据库（数据 + 索引）</td><td><?php echo esc_html( \CoupleSpace\Admin::human_bytes( $disk['dbBytes'] ) ); ?></td></tr>
			<tr><td>表情图片文件</td><td><?php echo esc_html( \CoupleSpace\Admin::human_bytes( $disk['emojiBytes'] ) ); ?>（<?php echo (int) $disk['emojiFiles']; ?> 个文件）</td></tr>
			<tr><td>合计</td><td><strong><?php echo esc_html( \CoupleSpace\Admin::human_bytes( $disk['totalBytes'] ) ); ?></strong></td></tr>
		</tbody>
	</table>

	<p class="description" style="margin-top:16px">
		解绑的情侣空间数据会在 30 天后由 WP-Cron 自动清理（保留期内仍可查询）。
	</p>
</div>
