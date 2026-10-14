# 测试

## tests/e2e.php —— 端到端测试（推荐先跑这个）

真实打 WordPress 站点（默认 `http://127.0.0.1:8890`）的 HTTP 接口，覆盖：

- **三种访问地址**：`/api/...`、`/wp-json/couple/v1/api/...`、`/?couple_api=/api/...`
- **API.md 全部接口**：公开接口、账号、情侣关系、同步、聊天、表情包、AI、App 后台
- **边界与错误码**：409 / 403 / 404 / 401 / 400 / 429 的逐条断言
- **WordPress 特有**：激活建表、连续激活两次幂等、停用再启用数据还在、初始后台密码播种

### 用法

```bash
# 1) 把插件复制到 WordPress
#    <wordpress>/wp-content/plugins/couple-space/
# 2) 启用插件（后台点「启用」，或插件后台页面里启用）
# 3) 运行测试
php tests/e2e.php
php tests/e2e.php --url=http://127.0.0.1:8890 --wp=C:\path\to\wordpress --php=C:\path\to\php.exe
php tests/e2e.php --admin=你的后台密码
```

结果会打印到终端，并写入 `tests/report.txt`（含逐条 ✅/❌ 与最终通过数）。

> 注意：测试会**清空并重建**自建表（`wp_couple_*`）与插件选项，请只在测试库上运行。
> 它也会把 App 后台密码改成 `couplespacetest`（可用 `--admin=` 覆盖），并清空限流计数。

## tests/verify-zip.php —— 验证 zip 能被 WordPress 上传安装

检查打包结构（顶层目录必须是 `couple-space/`、路径必须是正斜杠、插件头可解析），
并用 WordPress 自己的 `unzip_file()` + `activate_plugin()` 真机安装一次。

```bash
# 需要 PHP 的 zip 扩展（本机默认没在 php.ini 里开，显式加载即可）
php -d extension=zip tests/verify-zip.php ../couple-space.zip
```

## 说明

- `tests/lib/harness.php` 是测试脚手架：HTTP 客户端、数据库辅助、断言与报告。
- 表情图片用代码手写的最小合法 PNG（含正确的 IHDR/IDAT/IEND 与 CRC），不依赖任何图片库。
- 测试脚手架直接读 `wp-config.php` 里的数据库常量来校验数据库状态。
