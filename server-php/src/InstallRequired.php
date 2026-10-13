<?php
namespace App;

/**
 * 「尚未完成安装」信号。
 *
 * 当 server-php/config.php 不存在、或必需配置项（jwt_secret / admin_password /
 * db_*）缺失时抛出，由入口文件引导用户打开网页安装向导（public/install.php），
 * 而不是直接 Fatal error。
 */
class InstallRequired extends \RuntimeException
{
}
