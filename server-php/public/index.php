<?php
/**
 * 情侣空间服务端（PHP 版）· REST 入口
 *
 * 同时支持两种访问形式：
 *   伪静态：   GET /api/health                    （nginx 把 /api/* rewrite 到这里）
 *   兜底形式： GET /index.php/api/health          （未配置伪静态时也能用）
 *   后台页面： GET /admin 或 /admin.php
 *   实时推送： ws://域名/ws?token=...（由 bin/ws-server.php 常驻进程提供）
 *
 * PHP 内置服务器启动方式：
 *   php -S 127.0.0.1:8787 -t public
 */

require __DIR__ . '/../src/Bootstrap.php';

App\Bootstrap::run();
