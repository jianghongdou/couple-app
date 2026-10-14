<?php
/**
 * WP-CLI 命令：wp couple-space <子命令>
 *
 * 目前提供：
 *   wp couple-space set-admin-password <密码>   设置 App 后台（/admin/api/login）的密码
 *   wp couple-space reset-admin-password        删除数据库里的哈希，回退到选项里的初始密码
 *   wp couple-space status                      查看插件/数据库/表状态
 *   wp couple-space app-url                     打印 App 里应该填的服务器地址
 *   wp couple-space rebuild-tables              重新建表与迁移（幂等）
 *
 * 没装 WP-CLI 时，WordPress 后台「情侣空间 → 服务器信息」页面同样可以改后台密码。
 *
 * @package CoupleSpace
 */

namespace CoupleSpace;

defined( 'ABSPATH' ) || exit;

/**
 * WP-CLI 命令。
 */
class Cli {

	/** 注册命令。 */
	public static function boot() {
		if ( ! class_exists( '\WP_CLI' ) ) {
			return;
		}
		\WP_CLI::add_command( 'couple-space', __CLASS__ );
	}

	/**
	 * 设置 App 后台（/admin/api/login）密码。
	 *
	 * ## OPTIONS
	 *
	 * <password>
	 * : 新的后台密码，6-64 位。
	 *
	 * ## EXAMPLES
	 *
	 *     wp couple-space set-admin-password my-secret-pass
	 *
	 * @param array<int,string>   $args       位置参数。
	 * @param array<string,mixed> $assoc_args 关联参数。
	 */
	public function set_admin_password( $args, $assoc_args = array() ) {
		unset( $assoc_args );
		$password = isset( $args[0] ) ? (string) $args[0] : '';
		$min      = Config::limit( 'PASSWORD_MIN' );
		$max      = Config::limit( 'PASSWORD_MAX' );
		if ( strlen( $password ) < $min || strlen( $password ) > $max ) {
			\WP_CLI::error( '密码长度必须是 ' . $min . '-' . $max . ' 位。' );
		}

		Db::install();
		Settings::set_admin_password_hash( Auth::hash_admin_password( $password ) );
		Auth::log_admin_action( 'cli.set_admin_password', '通过 WP-CLI 设置 App 后台密码', 'cli' );
		\WP_CLI::success( 'App 后台密码已更新（bcrypt 哈希已写入自建 settings 表）。' );
	}

	/**
	 * 删除数据库里的后台密码哈希，回退到 WordPress 选项里的初始密码。
	 *
	 * ## EXAMPLES
	 *
	 *     wp couple-space reset-admin-password
	 *
	 * @param array<int,string>   $args       位置参数。
	 * @param array<string,mixed> $assoc_args 关联参数。
	 */
	public function reset_admin_password( $args, $assoc_args = array() ) {
		unset( $args, $assoc_args );
		Settings::clear_admin_password_hash();
		Config::ensure_options();
		Auth::log_admin_action( 'cli.reset_admin_password', '通过 WP-CLI 清除后台密码哈希', 'cli' );
		$initial = (string) Config::option( 'initialAdminPassword', '' );
		\WP_CLI::success( '已清除数据库里的后台密码哈希，现在使用选项里的初始密码：' . $initial );
	}

	/**
	 * 查看状态。
	 *
	 * ## EXAMPLES
	 *
	 *     wp couple-space status
	 *
	 * @param array<int,string>   $args       位置参数。
	 * @param array<string,mixed> $assoc_args 关联参数。
	 */
	public function status( $args, $assoc_args = array() ) {
		unset( $args, $assoc_args );
		$rows = array();
		foreach ( Db::TABLES as $name ) {
			$real   = Db::table( $name );
			$exists = Db::table_exists( $real );
			$rows[] = array(
				'table'  => $real,
				'exists' => $exists ? 'yes' : 'NO',
				'rows'   => $exists ? (int) Db::scalar( 'SELECT COUNT(*) FROM `' . $real . '`' ) : 0,
			);
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'table', 'exists', 'rows' ) );

		$stats = Db::get_stats();
		\WP_CLI::log( '用户 ' . $stats['users'] . ' / 情侣 ' . $stats['couples'] . ' / 消息 ' . $stats['messages'] . ' / 表情 ' . $stats['items'] );
		\WP_CLI::log( 'App 后台密码来源：' . Settings::admin_password_source() );
		\WP_CLI::log( 'App 服务器地址：' . Plugin::app_server_urls()['primary'] );
	}

	/**
	 * 打印 App 里应该填的服务器地址。
	 *
	 * ## EXAMPLES
	 *
	 *     wp couple-space app-url
	 *
	 * @param array<int,string>   $args       位置参数。
	 * @param array<string,mixed> $assoc_args 关联参数。
	 */
	public function app_url( $args, $assoc_args = array() ) {
		unset( $args, $assoc_args );
		$urls = Plugin::app_server_urls();
		\WP_CLI::log( '推荐：' . $urls['primary'] );
		\WP_CLI::log( '备用一：' . $urls['alternatives'][0] );
		\WP_CLI::log( '备用二：' . $urls['alternatives'][1] );
	}

	/**
	 * 重新建表与迁移（幂等，安全可重复执行）。
	 *
	 * ## EXAMPLES
	 *
	 *     wp couple-space rebuild-tables
	 *
	 * @param array<int,string>   $args       位置参数。
	 * @param array<string,mixed> $assoc_args 关联参数。
	 */
	public function rebuild_tables( $args, $assoc_args = array() ) {
		unset( $args, $assoc_args );
		$result = Db::install();
		Config::ensure_dirs();
		update_option( Plugin::DB_VERSION_OPTION, COUPLE_SPACE_VERSION, false );
		\WP_CLI::success(
			'建表/迁移完成：新增列 ' . (int) $result['addedColumns'] . ' 个、新增索引 ' . (int) $result['addedIndexes'] . ' 个。'
		);
	}
}
