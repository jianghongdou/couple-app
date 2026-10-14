<?php
/**
 * 卸载插件。
 *
 * 默认**只清理选项与定时任务，不删数据**（避免误删聊天记录 / 表情）。
 * 想连自建表一起删，请在 wp-config.php 里加：
 *     define( 'COUPLE_SPACE_REMOVE_DATA_ON_UNINSTALL', true );
 * 之后再卸载插件。
 *
 * @package CoupleSpace
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// 定时任务与选项一定清理。
wp_clear_scheduled_hook( 'couple_space_daily_cleanup' );
delete_option( 'couple_space_settings' );
delete_option( 'couple_space_db_version' );
delete_option( 'couple_space_needs_flush' );

if ( ! defined( 'COUPLE_SPACE_REMOVE_DATA_ON_UNINSTALL' ) || ! COUPLE_SPACE_REMOVE_DATA_ON_UNINSTALL ) {
	return;
}

// 明确要求删数据时才 DROP 自建表。
$suffixes = array(
	'users',
	'couples',
	'messages',
	'emoji_packs',
	'emoji_items',
	'sync_objects',
	'settings',
	'ai_usage',
	'admin_logs',
	'rate_limits',
	'ai_partners',
	'ai_messages',
	'ai_providers',
);
foreach ( $suffixes as $suffix ) {
	$table = $wpdb->prefix . 'couple_' . $suffix;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
	$wpdb->query( 'DROP TABLE IF EXISTS `' . $table . '`' );
}

// 表情目录也一并删除。
$uploads = wp_get_upload_dir();
$dir     = rtrim( str_replace( '\\', '/', $uploads['basedir'] ), '/' ) . '/couple-emoji';
if ( is_dir( $dir ) ) {
	$items = glob( $dir . '/*' );
	if ( is_array( $items ) ) {
		foreach ( $items as $item ) {
			if ( is_file( $item ) ) {
				wp_delete_file( $item );
			}
		}
	}
}
