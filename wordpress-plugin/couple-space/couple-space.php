<?php
/**
 * Plugin Name:       情侣空间（Couple Space）
 * Plugin URI:        https://example.com/couple-space
 * Description:       把「情侣空间」App 的服务端做成 WordPress 插件：装好后上传启用即可，App 里填域名就能连上。接口与 docs/API.md 完全一致（注册登录、情侣绑定、数据同步、聊天、表情包、DeepSeek AI 代理、后台管理）。
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Author:            情侣空间
 * Author URI:        https://example.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       couple-space
 * Domain Path:       /languages
 *
 * 设计说明（与 server-php 的关系）：
 *   - 业务逻辑移植自 server-php/（已在那边通过 397 项端到端测试），接口契约见 docs/API.md。
 *   - 数据库连接直接复用 WordPress 的 DB_NAME / DB_USER / DB_PASSWORD / DB_HOST 常量，
 *     自建表统一加 $wpdb->prefix 前缀（如 wp_couple_users），与 WP 自带表共存。
 *   - 不再需要 config.php：配置存在 WordPress 选项里，DeepSeek Key 沿用
 *     server-php 的 AES-256-GCM 加密存储 + 掩码返回。
 *   - 不提供 WebSocket（WP 不适合常驻进程），聊天走 App 的 5 秒轮询兜底。
 *
 * @package CoupleSpace
 */

defined( 'ABSPATH' ) || exit;

/** 插件版本（与 /api/health 的 version 字段一致）。 */
define( 'COUPLE_SPACE_VERSION', '1.0.0' );

/** 服务标识（/api/health 的 name 字段）。 */
define( 'COUPLE_SPACE_SERVER_NAME', 'couple-server' );

/** 客户端展示名（/api/meta 的 appName）。 */
define( 'COUPLE_SPACE_DISPLAY_NAME', '情侣空间' );

/** 插件主文件。 */
define( 'COUPLE_SPACE_FILE', __FILE__ );

/** 插件目录（带结尾斜杠）。 */
define( 'COUPLE_SPACE_DIR', plugin_dir_path( __FILE__ ) );

/** 插件目录 URL（带结尾斜杠）。 */
define( 'COUPLE_SPACE_URL', plugin_dir_url( __FILE__ ) );

/** REST 命名空间：/wp-json/couple/v1/api/... */
define( 'COUPLE_SPACE_REST_NAMESPACE', 'couple/v1' );

/** WordPress 选项名（插件的全部可配置项都在这一个数组里）。 */
define( 'COUPLE_SPACE_OPTION', 'couple_space_settings' );

/** 数据库表名后缀前缀（最终表名为 $wpdb->prefix . 'couple_users'）。 */
define( 'COUPLE_SPACE_TABLE_PREFIX', 'couple_' );

/**
 * PSR-4 风格的极简自动加载器（仅处理 CoupleSpace\ 前缀，不引第三方库）。
 */
spl_autoload_register(
	static function ( $class ) {
		$prefix = 'CoupleSpace\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}
		$relative = str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) );
		$file     = COUPLE_SPACE_DIR . 'includes/' . $relative . '.php';
		if ( is_file( $file ) ) {
			require $file;
		}
	}
);

// 激活 / 停用钩子必须在主文件里注册（激活时插件尚未正常加载完成）。
register_activation_hook( __FILE__, array( 'CoupleSpace\\Activate', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'CoupleSpace\\Activate', 'deactivate' ) );

// 引导插件（在 plugins_loaded 之后初始化，确保 $wpdb 与 WP 函数都已就绪）。
add_action( 'plugins_loaded', array( 'CoupleSpace\\Plugin', 'boot' ), 5 );
