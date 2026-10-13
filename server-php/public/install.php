<?php
/**
 * 情侣空间服务端（PHP 版）· 网页安装向导
 *
 * 打开方式：浏览器访问  你的站点地址/install.php
 *
 * 作用：
 *   1. 安装前展示「环境自检」（PHP 版本、pdo_mysql、curl/openssl/mbstring、目录可写、数据库连接）
 *   2. 填数据库信息 + 后台密码 -> 自动建库、建表、初始化默认设置
 *   3. 自动生成 config.php（含随机 jwt_secret），全程不用改代码
 *
 * 安全：
 *   - 已安装（config.php 存在且数据库可用）后再次访问一律 403
 *   - 表单带 CSRF token；错误提示不回显任何密码
 *
 * 本文件既可以被 public/index.php 路由到（/install、/install.php），
 * 也可以被直接访问（大多数宝塔/nginx 站点是这种情况）。
 */

require_once __DIR__ . '/../src/Bootstrap.php';

App\Bootstrap::registerAutoloader();
App\Installer::handle();
