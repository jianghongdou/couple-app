# 10 · PHP 版服务端部署（宝塔，不装 Node）

> 这是 `server-php/` 的部署文档。**接口契约与 Node 版完全一致**（见 [API.md](API.md)），
> iOS App 不需要任何改动，只是换了个后端实现。
> Node 版仍在 `server/` 目录里保留，两者任选一个部署，**不要同时指向同一份数据**。

---

## 为什么用 PHP 版

| | Node 版 | PHP 版 |
| --- | --- | --- |
| 宝塔里要装什么 | Node.js + npm 依赖（含原生模块 better-sqlite3） | **什么都不用装**，宝塔的 PHP 站点直接跑 |
| 常见坑 | node_modules 跨平台、原生模块编译、npm 网络 | 基本没有 |
| 数据库 | SQLite 单文件 | SQLite 单文件（免装 MySQL） |
| 依赖管理 | npm（`server/` 里那套） | **不需要 Composer，也没有 vendor 目录** |
| 实时聊天 | 内置 | 附带一个常驻进程（`bin/ws-server.php`）；**不跑也能用**（App 自动改为 5 秒轮询） |
| 后台地址 | `/admin` | `/admin.php` |

---

## 一、前置检查

宝塔 → 软件商店 → 已安装的 PHP（建议 **8.1 / 8.2 / 8.3**）→ 设置 → 安装扩展，确认有：

| 扩展 | 必要性 |
| --- | --- |
| `pdo_sqlite` | **必需**（数据库） |
| `curl` | 建议（AI 记账调 DeepSeek） |
| `openssl` | 建议（HTTPS + 后台 Key 加密存储） |
| `mbstring` | 建议（中文处理） |
| `sockets` | 可选（只在用内置公共表情包/WS 时才可能用到） |

```bash
php -v
php -m | grep -E 'pdo_sqlite|curl|openssl|mbstring'
```

---

## 二、先在服务器上自测（强烈建议，2 分钟）

这一步能把「扩展缺失 / 目录权限 / 磁盘不可写」这类问题**在配 Nginx 之前**全部暴露出来。
上传代码后（见第三节）在服务器上执行：

```bash
cd /www/wwwroot/couple-php
php test/smoke.php
```

期望结尾输出：

```
========================================
  ✅ ALL TESTS PASSED（通过 387 项断言）
========================================
```

它会临时在 8899/8900 端口起两个本地服务、跑完整套接口（注册/绑定/同步/聊天/表情/后台/WebSocket），
结束后自动清理。**看到 387 项全绿，说明这台服务器的 PHP 环境没问题**，后面只剩配站点。

---

## 三、上传代码

目录建议：`/www/wwwroot/couple-php`

```bash
mkdir -p /www/wwwroot/couple-php
# 上传 server-php 里的全部文件到这里（宝塔上传 zip 后解压，或 rsync）
cd /www/wwwroot/couple-php
ls
# 期望看到：public/  src/  bin/  test/  config.example.php  README.md
```

⚠️ **不要上传 `data/`**（里面是跑起来之后生成的数据库与表情文件，属于服务器自己的数据）。

---

## 四、写配置

```bash
cd /www/wwwroot/couple-php
cp config.example.php config.php

openssl rand -hex 32        # 生成 JWT 密钥，复制输出

nano config.php
```

至少填这两项（不填会**拒绝启动**并打印中文清单）：

```php
'jwt_secret'      => '把上面生成的 64 位随机串粘这里',
'admin_password'  => '后台管理密码（自己定，建议 12 位以上）',
```

其它保持默认即可：

```php
'data_dir'         => __DIR__ . '/data',   // 数据库与表情文件
'timezone'         => 'Asia/Shanghai',
'deepseek_api_key' => '',                  // 留空，之后在后台页面填
'ws_port'          => 8900,                // WebSocket 端口（只用本机反代就行）
```

> `config.php` 已被 `.gitignore` 排除，不会进仓库；同名大写环境变量（如 `JWT_SECRET`）可以覆盖配置。

---

## 五、目录权限（宝塔必须做）

PHP 以 `www` 用户运行，必须能读写 `data/`：

```bash
cd /www/wwwroot/couple-php
mkdir -p data
chown -R www:www data
chmod -R 755 data
```

---

## 六、建站 + 伪静态

1. 宝塔 → **网站** → 添加站点（域名已解析到服务器；PHP 版本选 8.1+）
2. 站点 → 设置 → **网站目录** → 「运行目录」设为 **`/public`**
3. 站点 → 设置 → **伪静态** → 粘贴：

```nginx
location / {
    try_files $uri $uri/ /index.php$is_args$args;
}
```

4. 保存；需要 HTTPS 就在「SSL」里申请证书

> ✅ 验证：浏览器打开 `https://你的域名/api/health`，应返回 `{"ok":true,...}`
> ❌ 404 = 伪静态没生效 → 用下面第七节的兜底写法

---

## 七、App 里填什么地址

| 情况 | App 里填 |
| --- | --- |
| 已配好伪静态（推荐） | `https://你的域名` |
| 没配伪静态（兜底） | `https://你的域名/index.php` |

第二种利用了 PHP 的 PATH_INFO：请求会变成 `/index.php/api/auth/login`，**不需要任何 rewrite 规则**。
两种都验证过，注意末尾不要加 `/api`。

后台管理：`https://你的域名/admin.php`（密码 = `config.php` 里的 `admin_password`）

---

## 八、WebSocket（可选）

**不跑也能用**：App 在 WebSocket 连不上时会自动降级为「每 5 秒轮询」，聊天页顶部显示「轮询刷新中（5 秒）」。
想实时（对方一发就到、能看到「正在输入」）就启动常驻进程：

```bash
cd /www/wwwroot/couple-php
# 前台试跑（只监听本机，由 Nginx 反代 /ws 过来）
php bin/ws-server.php --host=127.0.0.1 --port=8900 --path=/ws
```

常驻方式（二选一）：

**A. 宝塔「进程守护管理器」**（面板用户最省事）
- 软件商店装「进程守护管理器」→ 添加进程
- 名称 `couple-ws`；启动用户 `www`；运行目录 `/www/wwwroot/couple-php`
- 启动命令：`php bin/ws-server.php --host=127.0.0.1 --port=8900 --path=/ws`

**B. systemd**

```bash
cat >/etc/systemd/system/couple-ws.service <<'EOF'
[Unit]
Description=Couple App WebSocket
After=network.target

[Service]
Type=simple
WorkingDirectory=/www/wwwroot/couple-php
ExecStart=/usr/bin/php bin/ws-server.php --host=127.0.0.1 --port=8900 --path=/ws
Restart=always
RestartSec=3
User=www
Group=www

[Install]
WantedBy=multi-user.target
EOF
systemctl daemon-reload && systemctl enable --now couple-ws
```

然后在**站点配置**里把 `/ws` 反代过去（这样 App 不用改任何设置）：

站点 → 设置 → 配置文件，在 `location /` **之前**插入：

```nginx
location /ws {
    proxy_pass http://127.0.0.1:8900;
    proxy_http_version 1.1;
    proxy_set_header Upgrade    $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host       $host;
    proxy_read_timeout 3600s;
}
```

保存并 reload Nginx。之后 App 连的就是 `wss://你的域名/ws` ✅

---

## 九、部署后的验证清单

```bash
curl https://你的域名/api/health
```

- [ ] `/api/health` 返回 `ok: true`
- [ ] `/admin.php` 能打开后台登录页，用 `admin_password` 能登录
- [ ] 后台填 DeepSeek API Key → 点「测试」能返回账目
- [ ] App 里填服务器地址 → 「测试连接」成功
- [ ] 两台手机注册 → 建情侣空间 / 输邀请码绑定 → 互发消息能收到
      （没开 WebSocket 时约 5 秒内出现；开了则秒到，聊天页顶部显示「已连接/在线」）
- [ ] 手机设置 → 通用 → 关于本机 里的 App 版本是 **v1.0.0 (1)**（＝仓库 tag v1.0.3 那次构建）

---

## 十、常用命令

```bash
cd /www/wwwroot/couple-php

php bin/console.php check            # 环境自检（扩展、目录、配置）
php bin/console.php gen-secret       # 直接生成一个 jwt_secret
php bin/console.php init-db          # 只建库建表，不启动服务
php bin/console.php stats            # 用户数/情侣数/消息数/表情数/磁盘占用
php bin/console.php sync-public-emoji# 手动同步内置公共表情包
php bin/console.php purge            # 清理超过保留期的已解绑数据
php bin/console.php serve            # 本地快速试跑（等价 php -S）
php test/smoke.php                   # 端到端测试（387 项断言）
```

内置公共表情包：把图片放进 `public/emoji/<包名>/` 即可（子目录名 = 包名，文件名 = 表情名），
支持 jpg/png/gif/webp，按**文件头 magic bytes** 校验，启动时自动同步。

---

## 十一、排错对照

| 现象 | 原因与处理 |
| --- | --- |
| `/api/health` 返回 404 | 伪静态没配 → 按第六节加规则；或 App 地址改成 `https://域名/index.php` |
| 打开是下载文件 / 显示源码 | 站点没选 PHP 版本 → 站点设置里指定 PHP 8.1+ |
| 500 错误 | 看宝物「网站 → 日志」与 `data/logs/`；多半是没建 `config.php` 或缺 `jwt_secret` |
| `SQLSTATE[HY000] unable to open database file` | `data/` 不可写 → `chown -R www:www data && chmod -R 755 data` |
| `could not find driver` | PHP 缺 `pdo_sqlite` → 宝塔 PHP 设置里安装扩展 |
| 启动提示「缺少 jwt_secret / admin_password」 | 按提示填 `config.php`（这是故意设计的，防止用示例值上线） |
| 上传表情失败 | `data/` 不可写；或超过体积上限（单张 1MB / 30 包 / 每包 200 张） |
| AI 报 403「AI 未配置」 | 后台没填 DeepSeek Key |
| 聊天要等 5 秒才出现 | 正常，说明没跑 WebSocket（第八节可开） |
| WebSocket 连不上 | `/ws` 反代没加或没 reload；守护进程没起来（`ps aux \| grep ws-server`） |
| 换了 `jwt_secret` 之后 AI 报错 | 后台保存的 DeepSeek Key 是用它派生的密钥加密的 → 重新在后台填一次 Key |
| 想换回 Node 版 | 站点改成反向代理到 Node 端口即可，两版接口一致，App 不用改 |

---

## 十二、备份与迁移

所有数据都在 `data/`（SQLite 数据库 + 用户上传的表情）：

```bash
cd /www/wwwroot/couple-php
tar czf ~/couple-php-backup-$(date +%F).tar.gz data
```

迁移到新服务器：传 `couple-php`（**不含 data**）+ `config.php` + `data/`，然后重新执行第五节的权限命令即可。
