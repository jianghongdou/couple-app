<?php
/**
 * 验证 zip 可以被 WordPress 后台「上传插件」流程安装。
 *
 * 用法： php tests/verify-zip.php [zip路径]
 */

$zip_path = isset( $argv[1] ) ? $argv[1] : dirname( __DIR__, 2 ) . '/couple-space.zip';
$wp_root  = isset( $argv[2] ) ? $argv[2] : 'C:/Users/Tage-HongDou/AppData/Local/wp-test/wordpress';

if ( ! is_file( $zip_path ) ) {
	fwrite( STDERR, "找不到 zip：{$zip_path}\n" );
	exit( 1 );
}

require $wp_root . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
require_once ABSPATH . 'wp-admin/includes/misc.php';

$fail = 0;
$ok   = static function ( $cond, $label, $detail = '' ) use ( &$fail ) {
	if ( ! $cond ) {
		$fail++;
	}
	echo ( $cond ? '✅ ' : '❌ ' ), $label, ( '' !== $detail ? "  [{$detail}]" : '' ), "\n";
};

// 1) zip 结构检查
$zip = new ZipArchive();
$ok( true === $zip->open( $zip_path ), 'zip 可以打开', $zip_path );
$names = array();
for ( $i = 0; $i < $zip->numFiles; $i++ ) {
	$names[] = $zip->getNameIndex( $i );
}
$zip->close();

$tops = array();
foreach ( $names as $n ) {
	$tops[ explode( '/', $n )[0] ] = true;
}
$ok( array( 'couple-space' ) === array_keys( $tops ), '顶层目录只有 couple-space/', implode( ',', array_keys( $tops ) ) );
$ok( in_array( 'couple-space/couple-space.php', $names, true ), '顶层直接是 couple-space.php（没有多套一层）' );
$ok( in_array( 'couple-space/includes/Db.php', $names, true ), '包含 includes/ 目录' );
$ok( in_array( 'couple-space/admin/views/overview.php', $names, true ), '包含 admin/ 目录' );
$ok( in_array( 'couple-space/tests/e2e.php', $names, true ), '包含 tests/ 目录' );
$bad_sep = array_filter( $names, static function ( $n ) { return false !== strpos( $n, '\\' ); } );
$ok( 0 === count( $bad_sep ), '所有 zip 条目使用正斜杠路径分隔符（Windows 打包常见坑）', count( $bad_sep ) . ' 条异常' );

// 2) 插件头
$header = get_file_data(
	'phar://' . str_replace( '\\', '/', $zip_path ) . '/couple-space/couple-space.php',
	array(
		'Name'        => 'Plugin Name',
		'Version'     => 'Version',
		'RequiresPHP' => 'Requires PHP',
	)
);
$ok( '情侣空间（Couple Space）' === $header['Name'], '插件头 Plugin Name 可被 WordPress 解析', (string) $header['Name'] );
$ok( '' !== $header['Version'], '插件头 Version 可被解析', (string) $header['Version'] );

// 3) 真机安装：把 zip 解到 plugins 目录（等价于 WP 上传插件的解压步骤）
$target = WP_PLUGIN_DIR . '/couple-space';
$backup = WP_PLUGIN_DIR . '/_couple-space-backup';
$had    = is_dir( $target );

if ( $had ) {
	deactivate_plugins( 'couple-space/couple-space.php' );
	rename( $target, $backup );
}

// WordPress 的 unzip_file() 依赖 WP_Filesystem（后台安装插件时由页面前置初始化）。
if ( ! function_exists( 'WP_Filesystem' ) ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
}
$fs_ready = WP_Filesystem();
$ok( true === $fs_ready, 'WP_Filesystem 初始化成功（unzip_file 的前置条件）', $fs_ready ? 'direct' : 'failed' );

$unzip = unzip_file( $zip_path, WP_PLUGIN_DIR );
$ok( true === $unzip || null === $unzip, 'unzip_file() 解压成功（WordPress 安装插件用的同一个函数）', is_wp_error( $unzip ) ? $unzip->get_error_message() : 'ok' );
$ok( is_file( $target . '/couple-space.php' ), '解压后 couple-space/couple-space.php 存在' );

$activate = activate_plugin( 'couple-space/couple-space.php' );
$ok( ! is_wp_error( $activate ) && is_plugin_active( 'couple-space/couple-space.php' ), '安装后可以启用', is_wp_error( $activate ) ? $activate->get_error_message() : 'active' );

// 4) 启用后接口立刻可用
$health = wp_remote_get( home_url( '/api/health' ), array( 'timeout' => 20 ) );
$code   = is_wp_error( $health ) ? 0 : (int) wp_remote_retrieve_response_code( $health );
$ok( 200 === $code, '启用后 /api/health 返回 200', 'HTTP ' . $code );

// 5) 还原备份（保持测试环境与打包前一致）
if ( $had && is_dir( $backup ) ) {
	deactivate_plugins( 'couple-space/couple-space.php' );
	// 用备份覆盖解压出来的目录，保证带 report.txt 的测试环境不丢。
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $backup, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::SELF_FIRST );
	foreach ( $it as $f ) {
		$rel  = substr( $f->getPathname(), strlen( $backup ) + 1 );
		$dest = $target . '/' . $rel;
		if ( $f->isDir() ) {
			if ( ! is_dir( $dest ) ) {
				mkdir( $dest, 0777, true );
			}
			continue;
		}
		copy( $f->getPathname(), $dest );
	}
	$rit = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $backup, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $rit as $f ) {
		$f->isDir() ? rmdir( $f->getPathname() ) : unlink( $f->getPathname() );
	}
	rmdir( $backup );
	activate_plugin( 'couple-space/couple-space.php' );
}

echo "\n失败：{$fail}\n";
exit( $fail > 0 ? 1 : 0 );
