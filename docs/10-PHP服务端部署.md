# 10 · PHP 版服务端部署（宝塔 + MySQL，不装 Node）

> 这是 `server-php/` 的部署文档。**接口契约与 Node 版完全一致**（见 [API.md](API.md)），
> iOS App 不需要任何改动。
> Node 版仍在 `server/` 目录里保留，两者任选其一部署，**不要同时指向同一份数据**。

**PHP 版的特点**：纯 PHP（无 Composer、无 vendor、无 npm），用**宝塔面板自带的 MySQL**，
部署全程**不需要改代码** —— 数据库信息和管理员密码都在**网页安装向导**里填。

| | Node 版 | PHP 版 |
| --- | --- | --- |
| 宝塔要装什么 | Node.js + npm 依赖（含原生模块 better-sqlite3） | 只要 PHP（宝塔自带） |
| 数据库 | SQLite 单文件 | **MySQL**（宝塔面板里建） |
| 配置方式 | 手改 `.env` | **网页安装向导**填表 |
| 依赖管理 | npm | 无（不需要 Composer） |
| 实时聊天 | 内置 | 附带常驻进程；**不跑也能用**（App 自动 5 秒轮询） |
| 后台地址 | `/admin` | `/admin.php` |

---

## 一、宝塔准备（3 分钟）

### 1. 确认 PHP 版本与扩展

宝塔 → 软件商店 → 已安装的 PHP（建议 **8.1 / 8.2 / 8.3**）→ 设置 → 安装扩展：

| 扩展 | 必要性 |
| --- | --- |
| `pdo_mysql` | **必需**（连 MySQL） |
| `curl` | 建议（AI 记账调 DeepSeek） |
| `openssl` | 建议（HTTPS + 后台 Key 加密存储） |
| `mbstring` | 建议（中文处理） |

> 安装向导页面会在最上方**自动列出**这些扩展的检测结果（✅/❌），缺哪个一目了然。

### 2. 在宝塔里建一个数据库

宝塔 → **数据库** → 添加数据库：

| 字段 | 填什么 |
| --- | --- |
| 数据库名 | 例如 `couple` |
| 用户名 | 例如 `couple` |
| 密码 | 点「随机」生成，**复制下来**（安装向导要用） |
| 权限 | 本地服务器（默认） |

添加后记下这四项：**库名、用户名、密码、主机**（一般是 `127.0.0.1`，端口 `3306`）。

> 也可以在安装向导里勾选「库不存在时自动创建」让程序建库 —— 但用宝塔面板建更直观，
> 而且面板会帮你管理备份。

---

## 二、上传代码

目录建议就放在站点根目录（例如站点根是 `/home/www/nb.wwoyun.cn`，代码放 `couple-php/`）：

```bash
mkdir -p /home/www/nb.wwoyun.cn/couple-php
# 上传 couple-php.zip 到这里并解压（包内自带 couple-php/ 一层）
cd /home/www/nb.wwoyun.cn/couple-php
ls     # 应看到 public/ src/ bin/ test/ config.example.php README.md
```

**不要上传** `data/`（运行后自动生成，存表情文件和日志）。

权限（PHP 以 www 运行，需要能写 data 目录、并在安装时写 config.php）：

```bash
cd /home/www/nb.wwoyun.cn/couple-php
mkdir -p data
chown -R www:www .
chmod -R u+rwX .
```

---

## 三、建站（让浏览器能打开）

1. 宝塔 → **网站** → 添加站点（域名解析到服务器；PHP 版本选 8.1+）
2. 站点 → 设置 → **网站目录** → 「运行目录」设为 **`/public`**
   - ⚠️ 如果代码放在站点根的子目录里（站点根 `/home/www/nb.wwoyun.cn`、代码在 `couple-php/`），要填 **`/couple-php/public`**
   - 宝塔这个字段是**相对站点根目录**的
3. 站点 → 设置 → **伪静态** → 粘贴：

```nginx
location / {
    try_files $uri $uri/ /index.php$is_args$args;
}
```

4. 需要 HTTPS 就在「SSL」里申请证书

---

## 四、运行网页安装向导（关键一步）

浏览器打开你的域名，例如：

```
https://nb.wwoyun.cn/install.php
```

（如果直接打开 `https://nb.wwoyun.cn/`，未安装时会**自动跳到**安装向导。）

页面分两块：

**① 环境自检**（自动显示，装之前先看这栏）

```
PHP 版本 8.1+          ✅
pdo_mysql 扩展         ✅
curl / openssl / mbstring  ✅
代码目录可写           ✅   ← 若 ❌：chown -R www:www /home/www/nb.wwoyun.cn/couple-php
```

**② 填写信息**

| 字段 | 填什么 |
| --- | --- |
| 数据库主机 | `127.0.0.1`（宝塔默认） |
| 端口 | `3306` |
| 数据库名 | 你在宝塔建的那个（如 `couple`） |
| 数据库用户名 / 密码 | 宝塔建库时的那一组 |
| ☑ 库不存在时自动创建 | 建议勾上（需要账号有建库权限） |
| 后台管理员密码 + 确认 | **自己定**（≥6 位，登录 `/admin.php` 用） |
| 站点地址 | 可留空，或填 `https://你的域名` |

点「开始安装」→ 它会：连数据库 → 建表 → 写入默认设置 → 生成 `config.php`（含自动生成的
`jwt_secret`，你不用管）→ 显示成功页。

> 安装完成后 `install.php` 会**自动锁定**（再次访问返回 403「已安装」），这是一道安全防线。

---

## 五、登录后台，填 AI Key

1. 打开 `https://你的域名/admin.php`，用你刚设的管理员密码登录
2. 「AI 设置」里粘贴 DeepSeek API Key（[申请地址](https://platform.deepseek.com)）→ 选模型 `deepseek-chat` → 保存
3. 点「测试」，输入 `午饭25，打车18.5`，能返回两条账目就成功了
4. 顺手在「应用设置」里**关闭开放注册**（两个人用，避免陌生人占用）
5. 「服务器信息」区块能看到：数据库版本、连接状态、数据目录、JWT 密钥状态、WebSocket 是否在跑

---

## 六、App 里填什么地址

| 情况 | App 里填 |
| --- | --- |
| 已配好伪静态（推荐） | `https://你的域名` |
| 没配伪静态（兜底） | `https://你的域名/index.php` |

两种都验证过；注意末尾不要加 `/api`。两台手机都要填**同一个地址**。

---

## 七、WebSocket（可选）

**不跑也能用**：App 连不上 WebSocket 时会自动降级为「每 5 秒轮询」，聊天页顶部显示「轮询刷新中（5 秒）」。
想实时（对方一发就到、能看到「正在输入」）就启动常驻进程：

```bash
cd /home/www/nb.wwoyun.cn/couple-php
php bin/ws-server.php --host=127.0.0.1 --port=8900 --path=/ws      # 前台试跑
```

常驻方式（二选一）：

**A. 宝塔「进程守护管理器」**（面板用户最省事）
- 软件商店安装「进程守护管理器」→ 添加进程
- 名称 `couple-ws`；启动用户 `www`；运行目录 `/home/www/nb.wwoyun.cn/couple-php`
- 启动命令：`php bin/ws-server.php --host=127.0.0.1 --port=8900 --path=/ws`

**B. systemd**

```bash
cat >/etc/systemd/system/couple-ws.service <<'EOF'
[Unit]
Description=Couple App WebSocket
After=network.target

[Service]
Type=simple
WorkingDirectory=/home/www/nb.wwoyun.cn/couple-php
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

再在**站点配置**里把 `/ws` 反代到它（这样 App 不用改任何设置）：

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

## 八、验证清单

- [ ] `https://你的域名/api/health` 返回 `{"ok":true,...}`
- [ ] `/admin.php` 能登录，且「服务器信息」显示数据库版本正常
- [ ] 后台填好 DeepSeek Key 后「测试」能拆出账目
- [ ] App 填服务器地址 → 「测试连接」成功
- [ ] 两台手机注册 → 一台建情侣空间 → 另一台输邀请码绑定 → 互发消息能收到
- [ ] 未开 WebSocket 时约 5 秒收到；开了则秒到

---

## 九、安全加固（建议做）

1. **确认 `install.php` 已锁定**：再访问一次应返回 403「已安装」
2. **别把 `config.php` 暴露出去**：它在代码目录里（不在 `public/` 下），Nginx 只暴露 `public/`，正常访问不到 ✅
3. 后台「关闭开放注册」
4. 后台密码用强密码（≥12 位）
5. `DATA_DIR` 可放到站点外更安全，例如 `config.php` 里改成 `'/www/wwwroot/couple-data'`，然后
   `mkdir -p /www/wwwroot/couple-data && chown -R www:www /www/wwwroot/couple-data`
6. 定期备份（见第十一节）

---

## 十、排错对照

| 现象 | 原因与处理 |
| --- | --- |
| 打开网站自动跳到安装向导 | 正常，说明还没安装（或数据库连不上） |
| 安装页显示 `pdo_mysql` ❌ | 宝塔 → PHP 设置 → 安装扩展 `pdo_mysql`，然后重启 PHP |
| 安装页显示「代码目录不可写」 | `chown -R www:www /home/www/nb.wwoyun.cn/couple-php` |
| 安装时报数据库连接失败 | 核对宝塔「数据库」里那四项；主机一般 `127.0.0.1`；密码区分大小写 |
| 安装报「库不存在且无法创建」 | 不勾「自动创建」，改成先在宝塔面板里建好库 |
| `/api/health` 返回 404 | 伪静态没配 → 按第三节加规则；或 App 地址改成 `https://域名/index.php` |
| 打开是下载文件 / 显示源码 | 站点没选 PHP 版本 → 站点设置里指定 8.1+ |
| 500 错误 | 看宝塔「网站 → 日志」与 `data/logs/` |
| 上传表情失败 | `data/` 不可写 → `chown -R www:www data`；或超过体积上限 |
| AI 报 403「AI 未配置」 | 后台没填 DeepSeek Key |
| 聊天要等 5 秒 | 正常，说明没跑 WebSocket（第七节可开） |
| WebSocket 连不上 | `/ws` 反代没加或没 reload；守护进程没起来（`ps aux \| grep ws-server`） |
| 安装向导被锁但想重装 | 删除 `config.php` 后重新访问 `/install.php`（数据不会自动删，需自行处理） |

---

## 十一、备份与迁移

**两个东西要备份**：MySQL 数据库 + 表情文件目录。

```bash
# 1) 数据库（用宝塔面板的备份功能更省事：数据库 → 备份）
mysqldump -h127.0.0.1 -ucouple -p'你的库密码' couple > ~/couple-db-$(date +%F).sql

# 2) 表情文件与日志
cd /home/www/nb.wwoyun.cn/couple-php
tar czf ~/couple-files-$(date +%F).tar.gz data
```

迁移到新服务器：建库 → 导入 sql → 传代码（不含 data）→ 恢复 `data/` 与 `config.php` → 重新执行权限命令。

---

## 十二、常用命令

```bash
cd /home/www/nb.wwoyun.cn/couple-php

php bin/console.php check             # 环境自检（含数据库版本）
php bin/console.php gen-secret        # 生成 jwt_secret
php bin/console.php init-db           # 只建表，不启动服务（幂等）
php bin/console.php stats             # 用户数/情侣数/消息数/表情数
php bin/console.php sync-public-emoji # 同步内置公共表情包
php bin/console.php purge             # 清理超过保留期的已解绑数据
php bin/console.php serve             # 本地快速试跑（php -S）
php test/smoke.php                    # 端到端测试（需要一份可用的测试库配置）
```

内置公共表情包：把图片放进 `public/emoji/<包名>/`（子目录名=包名，文件名=表情名），
支持 jpg/png/gif/webp，按**文件头 magic bytes** 校验，启动时自动同步。
