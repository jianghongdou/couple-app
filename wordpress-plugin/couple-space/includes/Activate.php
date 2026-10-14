<?php
/**
 * 插件激活 / 停用。
 *
 * 激活时：
 *   1. 建表 + 迁移（幂等：重复激活不报错、不丢数据）
 *   2. 写默认设置到自建 settings 表
 *   3. 首次启用时生成一个随机初始后台密码，写进选项（用户可在后台查看/修改）
 *   4. 准备表情目录
 *   5. 标记需要刷新 rewrite 规则（让 /api/... 立即可用）
 *
 * @package CoupleSpace
 */

namespace CoupleSpace;

defined( 'ABSPATH' ) || exit;

/**
 * 激活器。
 */
class Activate {

	/**
	 * 激活钩子。
	 *
	 * 必须是**幂等**的：WordPress 允许反复启用/停用，重复执行不能报错、不能丢数据。
	 */
	public static function activate() {
		// 建表（CREATE TABLE IF NOT EXISTS + information_schema 补列补索引）。
		try {
			Db::install();
		} catch ( \Throwable $e ) {
			Log::error( '插件激活：建表失败', array( 'error' => $e->getMessage() ) );
			// 不让激活直接失败：Dispatcher 在首次请求时还会再尝试一次建表。
		}

		// 选项默认值（含随机初始后台密码）。
		Config::ensure_options();

		// 自建 settings 表里补齐默认值（已有值不动）。
		try {
			Settings::seed_from_options();
		} catch ( \Throwable $e ) {
			Log::error( '插件激活：写入默认设置失败', array( 'error' => $e->getMessage() ) );
		}

		// 首次启用（或数据库里的哈希被清掉、表被重建）时，
		// 把选项里的初始后台密码写成 bcrypt 哈希存入自建 settings 表。
		// 之后后台登录就用这个哈希；改密码也只是覆盖它。
		try {
			Settings::ensure_admin_password_seeded();
		} catch ( \Throwable $e ) {
			Log::error( '插件激活：写入初始后台密码失败', array( 'error' => $e->getMessage() ) );
		}

		// 表情目录与日志目录。
		Config::ensure_dirs();

		// 内置公共表情包（public/emoji 下若有内容）。
		try {
			PublicEmoji::sync_if_changed();
		} catch ( \Throwable $e ) {
			Log::warn( '插件激活：同步公共表情失败', array( 'error' => $e->getMessage() ) );
		}

		// 记录结构版本，避免每次请求都跑迁移。
		update_option( Plugin::DB_VERSION_OPTION, COUPLE_SPACE_VERSION, false );

		// 让 /api/(.*)$ 规则立刻生效（下次请求时刷新，避免激活期间直接 flush 出问题）。
		Plugin::mark_rewrite_dirty();

		// 每日维护任务。
		if ( ! wp_next_scheduled( 'couple_space_daily_cleanup' ) ) {
			wp_schedule_event( time() + 3600, 'daily', 'couple_space_daily_cleanup' );
		}

		Log::info( '情侣空间插件已激活', array( 'version' => COUPLE_SPACE_VERSION ) );
	}

	/**
	 * 停用钩子。
	 *
	 * 只清理定时任务与 rewrite 规则；**不删任何数据**（重新启用后数据仍在）。
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'couple_space_daily_cleanup' );
		flush_rewrite_rules( false );
		Log::info( '情侣空间插件已停用（数据保留）' );
	}
}
