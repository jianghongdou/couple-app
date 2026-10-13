<?php
namespace App\routes;

use App\AiError;
use App\Auth;
use App\Config;
use App\Db;
use App\DeepSeek;
use App\Http;
use App\HttpException;
use App\Installer;
use App\Log;
use App\RateLimit;
use App\Runtime;
use App\Settings;
use App\Time;
use App\util\Validate;
use App\WsHub;

/**
 * 后台管理接口（API.md §9）
 *
 * 所有写操作都会记入 admin_logs。
 */
class AdminRoutes
{
    const BCRYPT_ROUNDS = 10;

    public static function register($r)
    {
        // ---------------------------------------------------------------
        // 登录（唯一不需要鉴权的后台接口）
        // ---------------------------------------------------------------
        $r->post('/admin/api/login', function () {
            // 后台登录限流：同一 IP 15 分钟最多 10 次
            RateLimit::enforce('adminlogin:' . Http::clientIp(), 10, 900000, '登录尝试过于频繁，请稍后再试');

            $body = Http::jsonBody();
            $password = Http::field($body, 'password');
            $valid = Auth::verifyAdminPassword($password);

            if (!$valid) {
                // 还没设过任何后台密码（数据库没有哈希、config.php 也没写）时，给一条能照着做的提示
                if (!Settings::adminPasswordInDatabase() && (string) Config::get('admin_password', '') === '') {
                    throw new HttpException(409, 'admin_password_unset',
                        '尚未设置后台密码。请在服务器执行：php bin/console.php set-admin-password 你的新密码');
                }
                Auth::logAdminAction('admin.login.failed', '密码错误');
                Log::warn('后台登录失败', ['ip' => Http::clientIp()]);
                throw new HttpException(401, 'unauthorized', '密码错误');
            }

            $token = Auth::signAdminToken();
            Auth::logAdminAction('admin.login.success', '登录成功');
            Log::info('后台登录成功', ['ip' => Http::clientIp()]);
            Http::ok(['token' => $token]);
        });

        // 以下全部需要后台 token
        $admin = function () {
            Auth::requireAdmin();
        };

        // GET /admin/api/settings
        $r->get('/admin/api/settings', function () use ($admin) {
            $admin();
            Http::ok(Settings::forAdmin());
        });

        // PUT /admin/api/settings
        // deepseekApiKey 传明文即覆盖，传空字符串表示清空
        $r->put('/admin/api/settings', function () use ($admin) {
            $admin();
            $body = Http::jsonBody();
            $fields = Http::fields($body);
            if (count($fields) === 0) {
                Validate::fail('请求体不能为空');
            }

            // 记录变更字段名（不记录 Key 明文）
            $changed = [];
            foreach (array_keys($fields) as $k) {
                if ($k !== 'deepseekApiKey') {
                    $changed[] = $k;
                }
            }
            $keyChanged = array_key_exists('deepseekApiKey', $fields);

            $settings = Settings::update($fields);

            $parts = [];
            if (count($changed) > 0) {
                $parts[] = '字段：' . implode('、', $changed);
            }
            if ($keyChanged) {
                $keyValue = $fields['deepseekApiKey'];
                $parts[] = (is_string($keyValue) && $keyValue !== '') ? 'DeepSeek Key：已更新' : 'DeepSeek Key：已清空';
            }
            Auth::logAdminAction('settings.update', count($parts) > 0 ? implode('；', $parts) : '无变更');
            Log::info('后台更新设置', ['fields' => implode(',', $changed), 'keyChanged' => $keyChanged]);

            Http::ok(['ok' => true, 'settings' => $settings]);
        });

        // POST /admin/api/admin-password —— 后台自助修改密码（API.md 之外的后台专用接口）
        //
        // 密码存 settings.adminPasswordHash（bcrypt），任何响应都不回显哈希/明文。
        // 改密码**不会**让已签发的 admin token 失效（避免用户把自己踢出去）。
        $r->post('/admin/api/admin-password', function () use ($admin) {
            $admin();
            // 防止用暴力方式猜测「当前密码」：同一 IP 15 分钟最多 10 次
            RateLimit::enforce('adminpwd:' . Http::clientIp(), 10, 900000, '密码修改尝试过于频繁，请稍后再试');

            $body = Http::jsonBody();
            $currentPassword = Validate::requireString(Http::field($body, 'currentPassword'), 'currentPassword', [
                'min' => 1, 'max' => Config::limit('PASSWORD_MAX'),
            ]);
            $newPassword = Validate::requireString(Http::field($body, 'newPassword'), 'newPassword', [
                'min' => Config::limit('PASSWORD_MIN'), 'max' => Config::limit('PASSWORD_MAX'),
            ]);

            // 当前密码不对 → 401（与登录失败同样的错误码风格），并记日志
            if (!Auth::verifyAdminPassword($currentPassword)) {
                Auth::logAdminAction('admin.password.change_failed', '当前密码错误');
                Log::warn('后台修改密码失败：当前密码错误', ['ip' => Http::clientIp()]);
                throw new HttpException(401, 'unauthorized', '当前密码错误');
            }

            if ($newPassword === $currentPassword) {
                Auth::logAdminAction('admin.password.change_failed', '新密码与当前密码相同');
                Validate::fail('新密码不能与当前密码相同');
            }

            Settings::setAdminPasswordHash(Auth::hashAdminPassword($newPassword));
            Auth::logAdminAction('admin.password.changed', '后台密码已修改（bcrypt 哈希存入数据库）');
            Log::info('后台密码已修改', ['ip' => Http::clientIp()]);

            Http::ok([
                'ok' => true,
                'message' => '后台密码已修改，当前登录仍然有效',
                'adminPasswordInDatabase' => true,
                'adminPasswordSource' => 'database',
            ]);
        });

        // POST /admin/api/ai/test —— 用当前（或临时传入的）Key 试跑一条文本
        $r->post('/admin/api/ai/test', function () use ($admin) {
            $admin();
            $body = Http::jsonBody();
            $textRaw = Http::field($body, 'text');
            $text = ($textRaw === null || trim((string) $textRaw) === '')
                ? '今天午饭25，打车18块5'
                : Validate::requireString($textRaw, 'text', ['min' => 1, 'max' => Config::limit('AI_TEXT_MAX'), 'trim' => true]);
            $today = date('Y-m-d');

            // 允许临时传入 Key / 模型用于「先测通再保存」的场景
            $overrideKeyRaw = Http::field($body, 'deepseekApiKey');
            $overrideModelRaw = Http::field($body, 'deepseekModel');
            $overrideBaseUrlRaw = Http::field($body, 'deepseekBaseUrl');
            $overrideKey = is_string($overrideKeyRaw) ? trim($overrideKeyRaw) : '';
            $overrideModel = is_string($overrideModelRaw) ? trim($overrideModelRaw) : '';
            $overrideBaseUrl = is_string($overrideBaseUrlRaw) ? trim($overrideBaseUrlRaw) : '';

            try {
                if ($overrideKey !== '' || $overrideModel !== '' || $overrideBaseUrl !== '') {
                    // 先保存当前「明文」配置，测试后原样恢复
                    $saved = Settings::readAll();
                    $patch = [];
                    if ($overrideKey !== '') {
                        $patch['deepseekApiKey'] = $overrideKey;
                    }
                    if ($overrideModel !== '') {
                        $patch['deepseekModel'] = $overrideModel;
                    }
                    if ($overrideBaseUrl !== '') {
                        $patch['deepseekBaseUrl'] = $overrideBaseUrl;
                    }
                    Settings::update($patch);

                    $result = null;
                    $failure = null;
                    try {
                        $result = DeepSeek::parseLedger(['text' => $text, 'currency' => 'CNY', 'today' => $today]);
                    } catch (\Exception $e) {
                        $failure = $e;
                    }

                    // 无论成功失败都恢复原配置（明文；空字符串表示清空）
                    Settings::update([
                        'deepseekApiKey' => is_string($saved['deepseekApiKey']) ? $saved['deepseekApiKey'] : '',
                        'deepseekModel' => (string) $saved['deepseekModel'],
                        'deepseekBaseUrl' => (string) $saved['deepseekBaseUrl'],
                    ]);

                    if ($failure !== null) {
                        if ($failure instanceof AiError) {
                            Auth::logAdminAction('ai.test.failed', $failure->getMessage());
                            throw new HttpException(
                                $failure->getStatus() ? $failure->getStatus() : 502,
                                $failure->getErrorCode(),
                                $failure->getMessage()
                            );
                        }
                        throw $failure;
                    }

                    Auth::logAdminAction('ai.test', '临时配置测试成功，识别 ' . count($result['items']) . ' 条');
                    Http::ok([
                        'ok' => true,
                        'usingTemporaryKey' => true,
                        'text' => $result['summary'],
                        'items' => $result['items'],
                        'total' => $result['total'],
                        'model' => $result['model'],
                    ]);
                    return;
                }

                if (!Settings::isAiConfigured()) {
                    throw new HttpException(403, 'forbidden', 'AI 未配置');
                }

                $result = DeepSeek::parseLedger(['text' => $text, 'currency' => 'CNY', 'today' => $today]);
                Auth::logAdminAction('ai.test', '测试成功，识别 ' . count($result['items']) . ' 条');
                Http::ok([
                    'ok' => true,
                    'usingTemporaryKey' => false,
                    'text' => $result['summary'],
                    'items' => $result['items'],
                    'total' => $result['total'],
                    'model' => $result['model'],
                ]);
            } catch (AiError $e) {
                Auth::logAdminAction('ai.test.failed', $e->getMessage());
                throw new HttpException($e->getStatus() ? $e->getStatus() : 502, $e->getErrorCode(), $e->getMessage());
            }
        });

        // GET /admin/api/stats —— 用户数/情侣数/消息数/今日 AI 调用/磁盘占用
        $r->get('/admin/api/stats', function () use ($admin) {
            $admin();
            $stats = Db::getStats();
            $disk = Db::getDiskUsage();
            Http::ok([
                'users' => $stats['users'],
                'couples' => $stats['couples'],
                'couplesTotal' => $stats['couplesTotal'],
                'messages' => $stats['messages'],
                'aiToday' => $stats['aiToday'],
                'aiTotal' => $stats['aiTotal'],
                'packs' => $stats['packs'],
                'items' => $stats['items'],
                'syncObjects' => $stats['syncObjects'],
                'disk' => [
                    'dbBytes' => $disk['dbBytes'],
                    'emojiBytes' => $disk['emojiBytes'],
                    'emojiFiles' => $disk['emojiFiles'],
                    'totalBytes' => $disk['totalBytes'],
                ],
                'uptimeSeconds' => Runtime::uptimeSeconds(),
                'online' => WsHub::onlineCount(),
            ]);
        });

        // GET /admin/api/users?query=&page=&pageSize=
        $r->get('/admin/api/users', function () use ($admin) {
            $admin();
            $queryRaw = Http::query('query');
            $query = is_string($queryRaw) ? mb_substr(trim($queryRaw), 0, 64, 'UTF-8') : '';
            $page = Validate::queryInt(Http::query('page'), 'page', ['defaultValue' => 1, 'min' => 1, 'max' => 100000]);
            $pageSize = Validate::queryInt(Http::query('pageSize'), 'pageSize', ['defaultValue' => 20, 'min' => 1, 'max' => 100]);

            Http::ok(Db::listUsers(['query' => $query, 'page' => $page, 'pageSize' => $pageSize]));
        });

        // POST /admin/api/users/:id/reset-password  { newPassword }
        $r->post('/admin/api/users/:id/reset-password', function ($params) use ($admin) {
            $admin();
            $id = Validate::requireString($params['id'], 'id', ['min' => 1, 'max' => 64]);
            $body = Http::jsonBody();
            $newPassword = Validate::requireString(Http::field($body, 'newPassword'), 'newPassword', [
                'min' => Config::limit('PASSWORD_MIN'), 'max' => Config::limit('PASSWORD_MAX'),
            ]);

            $found = Db::getUserById($id);
            if (!$found) {
                throw new HttpException(404, 'not_found', '用户不存在');
            }
            $hash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => self::BCRYPT_ROUNDS]);
            Db::updateUserPassword($id, $hash);

            Auth::logAdminAction('user.reset_password', '用户 ' . $found['user']['username'] . '（' . $id . '）');
            Log::info('后台重置用户密码', ['userId' => $id]);
            Http::ok(['ok' => true]);
        });

        // POST /admin/api/users/:id/toggle-disabled  { disabled }
        $r->post('/admin/api/users/:id/toggle-disabled', function ($params) use ($admin) {
            $admin();
            $id = Validate::requireString($params['id'], 'id', ['min' => 1, 'max' => 64]);
            $body = Http::jsonBody();
            $disabled = Validate::optionalBool(Http::field($body, 'disabled'), 'disabled', null);
            if ($disabled === null) {
                Validate::fail('disabled 必须是布尔值');
            }

            $found = Db::getUserById($id);
            if (!$found) {
                throw new HttpException(404, 'not_found', '用户不存在');
            }
            Db::setUserDisabled($id, $disabled);
            Auth::logAdminAction('user.toggle_disabled', '用户 ' . $found['user']['username'] . '（' . $id . '）-> '
                . ($disabled ? '禁用' : '启用'));
            Log::info('后台修改用户禁用状态', ['userId' => $id, 'disabled' => $disabled]);
            Http::ok(['ok' => true, 'user' => Db::getUserById($id)['user']]);
        });

        // DELETE /admin/api/users/:id
        $r->delete('/admin/api/users/:id', function ($params) use ($admin) {
            $admin();
            $id = Validate::requireString($params['id'], 'id', ['min' => 1, 'max' => 64]);
            $found = Db::getUserById($id);
            if (!$found) {
                throw new HttpException(404, 'not_found', '用户不存在');
            }
            Db::deleteUser($id);
            Auth::logAdminAction('user.delete', '用户 ' . $found['user']['username'] . '（' . $id . '）');
            Log::warn('后台删除用户', ['userId' => $id, 'username' => $found['user']['username']]);
            Http::ok(['ok' => true]);
        });

        // GET /admin/api/packs —— 表情包统计
        $r->get('/admin/api/packs', function () use ($admin) {
            $admin();
            $disk = Db::getDiskUsage();
            Http::ok([
                'totalPacks' => Db::countEmojiPacks(),
                'totalItems' => Db::countEmojiItems(),
                'emojiBytes' => $disk['emojiBytes'],
                'emojiFiles' => $disk['emojiFiles'],
                'maxPacksPerCouple' => Config::limit('EMOJI_MAX_PACKS'),
                'maxItemsPerPack' => Config::limit('EMOJI_MAX_ITEMS_PER_PACK'),
                'maxItemBytes' => Config::limit('EMOJI_MAX_BYTES'),
            ]);
        });

        // GET /admin/api/logs?limit=100
        $r->get('/admin/api/logs', function () use ($admin) {
            $admin();
            $limit = Validate::queryInt(Http::query('limit'), 'limit', ['defaultValue' => 100, 'min' => 1, 'max' => 500]);
            Http::ok(['logs' => Db::listAdminLogs($limit)]);
        });

        // GET /admin/api/system —— 附加系统信息（后台页面展示用）
        $r->get('/admin/api/system', function () use ($admin) {
            $admin();
            $dbVersion = Db::serverVersion();
            $dbEngine = Db::serverEngine();
            $dbOk = false;
            try {
                $dbOk = Db::pingDb();
            } catch (\Exception $e) {
                $dbOk = false;
            }
            Http::ok([
                'php' => PHP_VERSION,
                'platform' => PHP_OS . ' ' . php_uname('m'),
                'sapi' => PHP_SAPI,
                'pid' => function_exists('getmypid') ? getmypid() : 0,
                'uptimeSeconds' => Runtime::uptimeSeconds(),
                'memoryBytes' => memory_get_usage(true),
                'wsRunning' => WsHub::isRunning(),
                'wsOnline' => WsHub::onlineCount(),
                'env' => [
                    'port' => (int) Config::get('port', 8787),
                    'logLevel' => (string) Config::get('log_level', 'info'),
                    'trustProxy' => (bool) Config::get('trust_proxy', true),
                    'dataDir' => Config::dataDir(),
                    'wsPort' => (int) Config::get('ws_port', 8900),
                ],
                // 数据库（MySQL / MariaDB）信息
                'database' => [
                    'engine' => $dbEngine,
                    'version' => $dbVersion,
                    'host' => Config::dbHost(),
                    'port' => Config::dbPort(),
                    'name' => Config::dbName(),
                    'user' => Config::dbUser(),
                    'charset' => Config::dbCharset(),
                    'connected' => $dbOk,
                    'schemaVersion' => (int) Db::getSetting('schema_version'),
                ],
                'timezone' => date_default_timezone_get(),
                'jwtSecretConfigured' => (string) Config::get('jwt_secret', '') !== '',
                'adminPasswordConfigured' => Settings::adminPasswordInDatabase()
                    || (string) Config::get('admin_password', '') !== '',
                // 后台密码只暴露来源，绝不返回哈希/明文
                'adminPasswordInDatabase' => Settings::adminPasswordInDatabase(),
                'adminPasswordSource' => Settings::adminPasswordSource(),
                'installed' => Installer::isConfigured(),
                'configFile' => Config::configFile(),
            ]);
        });
    }
}
