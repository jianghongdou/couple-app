# 09 · Linux / 宝塔面板部署（只讲服务器端）

> 本文只讲 **Linux 服务器**上的部署，跟本地 Windows 开发机器无关。
> 通用部署说明见 [03-服务器部署.md](03-服务器部署.md)；这篇是照着宝塔面板 + `/www/wwwroot/couple-app/server` 路径写的实操版。

---

## 先分清两边的职责（这是最容易踩坑的地方）

| | 本地电脑（Windows/macOS） | 服务器（Linux） |
| --- | --- | --- |
| 干什么 | 改代码、推 GitHub、下载 IPA | 只负责**跑服务** |
| 要不要装 Node | 随便（可选） | **必须装 Node 20+** |
| `node_modules` | 可以有（本机编译的） | **必须在服务器上重新装**，绝不能从本机拷 |
| `.env` | 不要提交到 git | 每台服务器自己填一份 |
| `data/` | 无意义 | 数据库 + 表情图片，**要备份** |

> 🚫 **铁律**：`node_modules` 不能跨平台复制。
> `better-sqlite3` 是原生模块，Windows 上的 `.node` 文件在 Linux 上一定加载失败：
> ```
> at bindings (.../bindings.js:112:48)
> at new Database (.../better-sqlite3/lib/database.js:48:64)
> { code: 'ERR_DLOPEN_FAILED' }
> ```
> 修复：`rm -rf node_modules && npm install`（在服务器上执行）。

---

## 一、环境检查

```bash
node -v        # 需要 v20 及以上（v22 / v24 都可以）
npm -v
```

没有 Node 20+ 的话（Ubuntu/Debian）：

```bash
curl -fsSL https://deb.nodesource.com/setup_20.x | bash -
apt install -y nodejs
```

CentOS / 宝塔用户建议直接在 **宝塔 → 软件商店 → Node.js 版本管理器** 里装一个 v20 或 v22，
然后在 **宝塔 → 网站 → Node 项目** 里指定用它。

---

## 二、把代码放到服务器（干净版）

目录按你的实际情况：`/www/wwwroot/couple-app/server`

### 方式 A：用更新包覆盖（推荐，桌面上的 `couple-server-update.zip`）

1. 宝塔 → 文件 → 进入 **`/www/wwwroot/couple-app/`**（注意：是**项目根目录**，不是 server 目录）
2. 上传 `couple-server-update.zip`（0.15 MB 左右，**不含 node_modules**）
3. 右键 → 解压
   - 包内已经带了一层 `server/`，所以文件会**自动落到 `/www/wwwroot/couple-app/server/`**，不会放错
   - ✅ 不会覆盖你的 `.env`（包里不含）
   - ✅ 不会动你的 `data/`（数据库和表情图片都在这里）

> ⚠️ 解压位置搞错会报 `Cannot find package 'express'`：
> 如果文件被解压到 `/www/wwwroot/couple-app/src/`（少了 `server/` 这一层），
> 那一层没有 `node_modules`，node 自然找不到依赖。
> 判断方法：`ls /www/wwwroot/couple-app/src` 应该**不存在**；正确的位置是 `/www/wwwroot/couple-app/server/src`。

### 方式 B：只删掉错误的依赖（最小改动）

如果暂时不想更新代码，只要在服务器上删掉从本机带来的 `node_modules` 即可：

```bash
cd /www/wwwroot/couple-app/server
rm -rf node_modules
```

### 方式 C：服务器上直接 git clone（最干净，但需要能访问 GitHub）

```bash
cd /www/wwwroot
git clone https://github.com/jianghongdou/couple-app.git couple-app-new
# 国内服务器访问 GitHub 可能需要先配代理，或改用 Gitee 镜像
```

---

## 三、安装依赖（一定在服务器上装）

```bash
cd /www/wwwroot/couple-app/server
npm install
```

- 项目自带 `server/.npmrc`，registry 已指向国内镜像 `registry.npmmirror.com`，**不需要开代理**
- 如果这一步 better-sqlite3 报错（下载预编译二进制失败）：

```bash
npm run fetch-sqlite        # 从镜像单独拉取本平台的二进制
# 还是没有就装编译工具从源码编译：
apt install -y python3 make g++ && npm install --build-from-source
```

---

## 四、配置 .env

```bash
cd /www/wwwroot/couple-app/server
cp .env.example .env

# 生成一个安全的 JWT_SECRET（Linux 命令）
openssl rand -hex 32

nano .env     # 或宝塔文件管理器里双击编辑
```

至少要填这两项（示例里的值是**故意留空**的，不填会拒绝启动）：

```ini
JWT_SECRET=把上面 openssl 生成的 64 位随机串粘这里
ADMIN_PASSWORD=给后台管理页面用的强密码
PORT=8787
DATA_DIR=./data
```

> 说明：`.env` 是按**文件所在目录**读取的，跟启动时的工作目录无关，所以宝塔面板里怎么启动都能读到。

---

## 五、体检 + 启动

```bash
npm run doctor     # 一键环境自检
npm start
```

`npm run doctor` 会逐项告诉你哪里有问题，正常输出长这样：

```
情侣空间服务端 · 环境自检
────────────────────────────────────────────────────
✅ Node 版本 v24.21.0
✅ 运行平台 linux / x64
✅ 原生模块文件平台正确（Linux，1.8 MB）
✅ better-sqlite3 可以正常使用（内存库试跑通过）
✅ JWT_SECRET 已配置
✅ ADMIN_PASSWORD 已配置
✅ 监听端口 8787
✅ 数据目录可写：/www/wwwroot/couple-app/server/data
────────────────────────────────────────────────────
✅ 没有阻塞问题，可以执行 npm start
```

看到控制台打印 `✅ 情侣空间服务端已启动` 就成了。前台测试用 `Ctrl+C` 可以停掉。

---

## 六、让它常驻运行（三选一）

### 方式 1：宝塔「Node 项目」（面板用户最省事）

宝塔面板 → 网站 → **Node 项目** → 添加：

| 字段 | 填什么 |
| --- | --- |
| 项目名称 | couple-server |
| 项目目录 | `/www/wwwroot/couple-app/server` |
| 启动文件 / 启动选项 | `src/index.js`（若面板要求命令，就填 `node src/index.js` 或 `npm start`） |
| Node 版本 | 20 或以上 |
| 运行用户 | `www`（推荐；用 root 也能跑） |
| 端口 | `8787` |

保存后启动。**如果用 www 用户启动，先执行一次权限修正**：

```bash
chown -R www:www /www/wwwroot/couple-app/server
chmod -R u+rwX /www/wwwroot/couple-app/server
```

### 方式 2：pm2（最通用）

```bash
cd /www/wwwroot/couple-app/server
npm install -g pm2
pm2 start ecosystem.config.cjs
pm2 save
pm2 startup          # 按提示执行它输出的那行命令，实现开机自启
pm2 logs couple-server
```

### 方式 3：systemd（系统级托管）

```bash
cat >/etc/systemd/system/couple-server.service <<'EOF'
[Unit]
Description=Couple App Server
After=network.target

[Service]
Type=simple
WorkingDirectory=/www/wwwroot/couple-app/server
ExecStart=/usr/bin/node src/index.js
Restart=always
RestartSec=3
User=www
Group=www

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
systemctl enable --now couple-server
systemctl status couple-server
journalctl -u couple-server -f
```

---

## 七、放行端口 / 配 HTTPS

### 只测试（最快）

宝塔 → 安全 → 放行端口 `8787`；如果云服务器还有安全组，也要放行 8787。
App 里服务器地址就填：`http://你的服务器IP:8787`

### 正式使用（推荐）

用 Nginx 反向代理 + SSL，只暴露 80/443：

1. 宝塔 → 网站 → 添加站点（域名解析到服务器）
2. 站点设置 → **反向代理** → 目标 URL `http://127.0.0.1:8787`
3. 反向代理配置里确认有 WebSocket 两行（**聊天必须**）：

```nginx
proxy_set_header Upgrade    $http_upgrade;
proxy_set_header Connection "upgrade";
proxy_read_timeout 3600s;
```

4. 站点设置 → **SSL** → Let's Encrypt 一键申请
5. 放行 80 / 443，**不要再对外暴露 8787**
6. App 里服务器地址填：`https://你的域名`

---

## 八、部署完成后的验证清单

```bash
curl http://127.0.0.1:8787/api/health      # 应返回 {"ok":true,...}
```

- [ ] `http://你的地址:8787/api/health` 返回 ok
- [ ] `http://你的地址:8787/admin` 能打开后台登录页
- [ ] 用 `.env` 里的 `ADMIN_PASSWORD` 能登录后台
- [ ] 后台 → 填 DeepSeek API Key → 点「测试」返回账目
- [ ] 手机浏览器也能打开 `/api/health`（说明外网可达）
- [ ] App 里填服务器地址 → 「测试连接」成功
- [ ] 两台手机分别注册 → 一台建情侣空间 → 另一台用邀请码绑定 → 互发消息能秒收

---

## 九、以后更新服务器代码的正确姿势

```bash
cd /www/wwwroot/couple-app/server

# 1) 备份（数据库 + 表情图片都在 data/）
tar czf ~/couple-backup-$(date +%F).tar.gz data

# 2) 上传新代码（只传 src/ scripts/ package.json public/ 等，绝不含 node_modules）
#    宝塔：把 couple-server-update.zip 上传到 /www/wwwroot/couple-app/ 后解压
#          （包内自带 server/ 一层，会自动落到 server/ 下）
#    命令行：rsync -av --exclude node_modules --exclude data --exclude .env ./server/ root@服务器:/www/wwwroot/couple-app/server/

# 3) 依赖有变化时才需要重装
npm install

# 4) 重启
pm2 restart couple-server      # 或 systemctl restart couple-server / 宝塔里点重启
```

---

## 十、Linux 常见报错对照

| 报错 | 原因 | 处理 |
| --- | --- | --- |
| `ERR_DLOPEN_FAILED` / `not a valid Win32 application` / `invalid ELF header` | 把本机 node_modules 传上来了 | `rm -rf node_modules && npm install` |
| `❌ 启动失败：环境配置不完整` / 提示缺少 `JWT_SECRET` | `.env` 没建或没填 | `cp .env.example .env` 后填 JWT_SECRET / ADMIN_PASSWORD |
| `端口 8787 已被占用` | 端口冲突 | `ss -lntp \| grep 8787` 找到进程，或改 `.env` 里的 PORT |
| `EACCES: permission denied` 写 `data/` | 运行用户对目录没权限 | `chown -R www:www /www/wwwroot/couple-app/server` |
| `Cannot find module 'express'` / `Cannot find package 'express' imported from ...` | 依赖没装，或**启动目录不对**（解压少了 `server/` 这层，或在错的目录里跑） | 先确认 `ls 启动文件所在的上一级` 里有 `node_modules` 与 `package.json`；在该目录执行 `npm install`。正确目录是 `/www/wwwroot/couple-app/server` |
| 宝塔 Node 项目启动报错但手工 `npm start` 正常 | 面板里的「项目目录」填错了 | 项目目录填 `/www/wwwroot/couple-app/server`，启动文件 `src/index.js` |
| WebSocket 连不上（聊天发不出） | Nginx 反代缺 Upgrade 头 | 按第七节补两行并重载 Nginx |
| App「测试连接」失败 | 端口没放行 / 云安全组 / 地址写错 | 先用 `curl` 从服务器本身测，再从外网测；地址不要带 `/api` |
| 后台打不开 | 路径要写 `/admin` | `http://地址:8787/admin` |
