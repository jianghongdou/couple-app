<?php
/**
 * 用户管理页视图。
 *
 * @package CoupleSpace
 * @var string              $query  搜索关键字。
 * @var int                 $page   当前页。
 * @var array<string,mixed> $result 列表结果。
 * @var int                 $total  总数。
 */

defined( 'ABSPATH' ) || exit;

$total_pages = (int) max( 1, ceil( $total / \CoupleSpace\Admin::PER_PAGE ) );
?>
<div class="wrap">
	<h1>情侣空间 · 用户管理</h1>
	<?php \CoupleSpace\Admin::render_notices(); ?>

	<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="margin:12px 0">
		<input type="hidden" name="page" value="<?php echo esc_attr( \CoupleSpace\Admin::SLUG . '-users' ); ?>" />
		<input type="search" name="q" value="<?php echo esc_attr( $query ); ?>" placeholder="用户名 / 昵称 / 用户 id" />
		<?php submit_button( '搜索', 'secondary', 'submit', false ); ?>
		<?php if ( '' !== $query ) : ?>
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . \CoupleSpace\Admin::SLUG . '-users' ) ); ?>">清空</a>
		<?php endif; ?>
		<span class="description" style="margin-left:8px">共 <?php echo (int) $total; ?> 个用户</span>
	</form>

	<table class="widefat striped">
		<thead>
			<tr>
				<th style="width:150px">用户 id</th>
				<th>用户名</th>
				<th>昵称</th>
				<th style="width:70px">头像</th>
				<th style="width:120px">情侣空间</th>
				<th style="width:80px">邀请码</th>
				<th style="width:80px">状态</th>
				<th style="width:150px">注册时间</th>
				<th style="width:150px">最后活跃</th>
				<th style="width:420px">操作</th>
			</tr>
		</thead>
		<tbody>
		<?php if ( empty( $result['users'] ) ) : ?>
			<tr><td colspan="10">没有匹配的用户。</td></tr>
		<?php else : ?>
			<?php foreach ( $result['users'] as $u ) : ?>
				<tr>
					<td><code><?php echo esc_html( $u['id'] ); ?></code></td>
					<td><strong><?php echo esc_html( $u['username'] ); ?></strong></td>
					<td><?php echo esc_html( $u['nickname'] ); ?></td>
					<td style="font-size:18px"><?php echo esc_html( $u['avatarEmoji'] ); ?></td>
					<td><?php echo $u['coupleId'] ? '<code>' . esc_html( $u['coupleId'] ) . '</code>' : '—'; ?></td>
					<td><?php echo $u['inviteCode'] ? '<code>' . esc_html( $u['inviteCode'] ) . '</code>' : '—'; ?></td>
					<td>
						<?php if ( ! empty( $u['disabled'] ) ) : ?>
							<span style="color:#dc3232">已禁用</span>
						<?php else : ?>
							<span style="color:#46b450">正常</span>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( \CoupleSpace\Admin::dt( $u['createdAt'] ) ); ?></td>
					<td><?php echo esc_html( \CoupleSpace\Admin::dt( $u['lastSeenAt'] ) ); ?></td>
					<td>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin:0 8px 4px 0">
							<?php wp_nonce_field( 'couple_space_user_action' ); ?>
							<input type="hidden" name="action" value="couple_space_user_action" />
							<input type="hidden" name="user_action" value="reset_password" />
							<input type="hidden" name="user_id" value="<?php echo esc_attr( $u['id'] ); ?>" />
							<input type="hidden" name="paged" value="<?php echo (int) $page; ?>" />
							<input type="hidden" name="q" value="<?php echo esc_attr( $query ); ?>" />
							<input type="text" name="newPassword" value="" placeholder="新密码" size="10" />
							<button type="submit" class="button button-small">重置密码</button>
						</form>

						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin:0 8px 4px 0">
							<?php wp_nonce_field( 'couple_space_user_action' ); ?>
							<input type="hidden" name="action" value="couple_space_user_action" />
							<input type="hidden" name="user_action" value="toggle_disabled" />
							<input type="hidden" name="user_id" value="<?php echo esc_attr( $u['id'] ); ?>" />
							<input type="hidden" name="paged" value="<?php echo (int) $page; ?>" />
							<input type="hidden" name="q" value="<?php echo esc_attr( $query ); ?>" />
							<input type="hidden" name="disabled" value="<?php echo empty( $u['disabled'] ) ? '1' : '0'; ?>" />
							<button type="submit" class="button button-small">
								<?php echo empty( $u['disabled'] ) ? '禁用' : '启用'; ?>
							</button>
						</form>

						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin:0"
							onsubmit="return confirm('确定删除用户 <?php echo esc_js( $u['username'] ); ?>？该操作不可恢复。');">
							<?php wp_nonce_field( 'couple_space_user_action' ); ?>
							<input type="hidden" name="action" value="couple_space_user_action" />
							<input type="hidden" name="user_action" value="delete" />
							<input type="hidden" name="user_id" value="<?php echo esc_attr( $u['id'] ); ?>" />
							<input type="hidden" name="paged" value="<?php echo (int) $page; ?>" />
							<input type="hidden" name="q" value="<?php echo esc_attr( $query ); ?>" />
							<button type="submit" class="button button-small button-link-delete">删除</button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
		<?php endif; ?>
		</tbody>
	</table>

	<?php if ( $total_pages > 1 ) : ?>
		<div class="tablenav"><div class="tablenav-pages">
			<?php
			echo wp_kses_post(
				paginate_links(
					array(
						'base'      => add_query_arg(
							array(
								'page'  => \CoupleSpace\Admin::SLUG . '-users',
								'q'     => rawurlencode( $query ),
								'paged' => '%#%',
							),
							admin_url( 'admin.php' )
						),
						'format'    => '',
						'current'   => $page,
						'total'     => $total_pages,
						'prev_text' => '‹',
						'next_text' => '›',
					)
				)
			);
			?>
		</div></div>
	<?php endif; ?>
</div>
