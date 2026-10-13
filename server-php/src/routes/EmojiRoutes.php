<?php
namespace App\routes;

use App\Auth;
use App\Config;
use App\Db;
use App\EmojiStore;
use App\Http;
use App\HttpException;
use App\Log;
use App\Settings;
use App\Time;
use App\util\Ids;
use App\util\Image;
use App\util\Validate;

/**
 * 表情包接口（API.md §6）
 *
 * 安全要点：
 *   1. 图片二进制存磁盘，文件名只由服务端生成的 id 决定，绝不使用用户传入的路径。
 *   2. blob 接口校验 id 格式 ^e_[A-Za-z0-9]+$（防路径穿越）。
 *   3. 上传时校验体积 + magic bytes，不信任客户端声明的 mime。
 */
class EmojiRoutes
{
    public static function register($r)
    {
        // GET /api/emoji/packs —— 同一情侣空间内双方都能看到对方的包
        $r->get('/api/emoji/packs', function () {
            $me = Auth::requireUser();
            $coupleId = Auth::requireCouple($me['user']);
            $packs = Db::listPacksForCouple($coupleId);
            Http::ok(['packs' => $packs]);
        });

        // POST /api/emoji/packs
        $r->post('/api/emoji/packs', function () {
            $me = Auth::requireUser();
            $coupleId = Auth::requireCouple($me['user']);
            $body = Http::jsonBody();
            $name = Validate::requireString(Http::field($body, 'name'), 'name', [
                'min' => 1, 'max' => 32, 'trim' => true,
            ]);

            // 每个情侣空间最多 N 个自建包（可按后台设置调整）
            $maxPacks = Settings::maxEmojiPacks();
            $used = Db::countOwnPacks($coupleId);
            if ($used >= $maxPacks) {
                throw new HttpException(409, 'conflict', '表情包数量已达上限（' . $maxPacks . ' 个）');
            }

            $row = Db::createPack([
                'name' => $name,
                'ownerId' => $me['user']['id'],
                'coupleId' => $coupleId,
                'isPublic' => false,
            ]);
            $pack = Db::getPackWithItems($row['id']);
            Log::info('创建表情包', ['packId' => $row['id'], 'userId' => $me['user']['id']]);
            Http::ok(['pack' => $pack]);
        });

        // DELETE /api/emoji/packs/:id —— 只能删自己创建的包（system 包不可删）
        $r->delete('/api/emoji/packs/:id', function ($params) {
            $me = Auth::requireUser();
            $coupleId = Auth::requireCouple($me['user']);
            $id = Validate::requireString($params['id'], 'id', ['min' => 1, 'max' => 64]);
            $pack = Db::getPackRow($id);

            if (!$pack || (empty($pack['is_public']) && $pack['couple_id'] !== $coupleId)) {
                throw new HttpException(404, 'not_found', '表情包不存在');
            }
            if (!empty($pack['is_system'])) {
                throw new HttpException(403, 'forbidden', '系统表情包不可删除');
            }
            if (!empty($pack['is_public'])) {
                throw new HttpException(403, 'forbidden', '公共表情包不可删除');
            }
            if ($pack['owner_id'] !== $me['user']['id']) {
                throw new HttpException(403, 'forbidden', '只能删除自己创建的表情包');
            }

            // 先取出条目 id，删库后清理磁盘文件
            $full = Db::getPackWithItems($id);
            $itemIds = [];
            if ($full) {
                foreach ($full['items'] as $it) {
                    $itemIds[] = $it['id'];
                }
            }
            Db::deletePack($id);
            foreach ($itemIds as $itemId) {
                // itemId 来自数据库且格式受约束，这里再校验一次以防脏数据
                if (!Ids::isValidEmojiId($itemId)) {
                    continue;
                }
                $path = EmojiStore::resolve(['id' => $itemId, 'owner_id' => $pack['owner_id'], 'pack_id' => $id]);
                if ($path !== null && is_file($path)) {
                    @unlink($path);
                }
            }

            Log::info('删除表情包', ['packId' => $id, 'userId' => $me['user']['id'], 'items' => count($itemIds)]);
            Http::ok(['ok' => true]);
        });

        // POST /api/emoji/items —— base64 上传
        $r->post('/api/emoji/items', function () {
            $me = Auth::requireUser();
            $coupleId = Auth::requireCouple($me['user']);
            $body = Http::jsonBody();

            $packId = Validate::requireString(Http::field($body, 'packId'), 'packId', ['min' => 1, 'max' => 64]);
            $declaredMime = Validate::validateMime(Http::field($body, 'mime'), Config::EMOJI_MIMES);
            $nameRaw = Http::field($body, 'name');
            $name = $nameRaw === null
                ? ''
                : Validate::requireString($nameRaw, 'name', ['min' => 0, 'max' => 32, 'trim' => true]);
            $dataBase64 = Validate::requireString(Http::field($body, 'dataBase64'), 'dataBase64', ['min' => 1]);

            // 归属校验：包必须属于本情侣空间
            $pack = Db::getPackRow($packId);
            if (!$pack || (empty($pack['is_public']) && $pack['couple_id'] !== $coupleId)) {
                throw new HttpException(404, 'not_found', '表情包不存在');
            }
            if (!empty($pack['is_public']) || !empty($pack['is_system'])) {
                throw new HttpException(403, 'forbidden', '公共/系统表情包不可上传');
            }

            // 每个包上限 200 张
            $maxItems = Config::limit('EMOJI_MAX_ITEMS_PER_PACK');
            if (Db::countItemsInPack($packId) >= $maxItems) {
                throw new HttpException(409, 'conflict', '该表情包已达上限（' . $maxItems . ' 张）');
            }

            // base64 长度预检，避免先解码出巨大缓冲
            $maxBase64 = (int) ceil(Config::limit('EMOJI_MAX_BYTES') / 3) * 4 + 16;
            if (strlen($dataBase64) > $maxBase64) {
                Validate::fail('图片体积超过上限（解码后最多 1MB）');
            }

            // 去掉可能存在的 data URL 前缀与空白字符
            $raw = trim($dataBase64);
            if (preg_match('#^data:[^;]+;base64,(.*)$#s', $raw, $m)) {
                $raw = $m[1];
            }
            $raw = preg_replace('/\s+/', '', $raw);
            if ($raw === null || !preg_match('#^[A-Za-z0-9+/]+={0,2}$#', $raw)) {
                Validate::fail('dataBase64 不是合法的 base64 数据');
            }

            $buf = base64_decode($raw, true);
            if ($buf === false) {
                Validate::fail('dataBase64 解码失败');
            }
            if ($buf === '') {
                Validate::fail('图片内容为空');
            }
            if (strlen($buf) > Config::limit('EMOJI_MAX_BYTES')) {
                Validate::fail('图片体积超过上限（解码后最多 1MB）');
            }

            // magic bytes 校验：不信任客户端声明的 mime
            $sniffed = Image::sniffMime($buf);
            if ($sniffed === null) {
                Validate::fail('文件内容不是受支持的图片格式（仅支持 JPEG / PNG / GIF / WEBP）');
            }
            if ($sniffed !== $declaredMime) {
                Validate::fail('声明的 mime（' . $declaredMime . '）与图片实际格式（' . $sniffed . '）不一致');
            }

            // 先落盘再写数据库；写库失败则回滚文件。
            // 文件名完全由服务端生成的 id 决定（{data_dir}/emoji/<id>.bin），不含用户输入
            $itemId = Ids::newEmojiId();
            $filePath = EmojiStore::localPath($itemId);
            Config::ensureDirs();
            if (@file_put_contents($filePath, $buf) === false) {
                throw new HttpException(500, 'server_error', '图片保存失败，请检查 data 目录权限');
            }

            try {
                Db::insertEmojiItem([
                    'id' => $itemId,
                    'packId' => $packId,
                    'ownerId' => $me['user']['id'],
                    'coupleId' => $coupleId,
                    'mime' => $sniffed,
                    'size' => strlen($buf),
                    'name' => $name,
                ]);
            } catch (\Exception $e) {
                @unlink($filePath);
                throw $e;
            }

            Log::info('上传表情', [
                'itemId' => $itemId,
                'packId' => $packId,
                'size' => strlen($buf),
                'mime' => $sniffed,
                'userId' => $me['user']['id'],
            ]);

            $found = Db::getEmojiItem($itemId);
            Http::ok(['item' => $found['item']]);
        });

        // DELETE /api/emoji/items/:id —— 删除单张表情（只能删自己上传的）
        $r->delete('/api/emoji/items/:id', function ($params) {
            $me = Auth::requireUser();
            $id = isset($params['id']) ? (string) $params['id'] : '';

            // 防路径穿越：严格校验 id 格式，绝不把用户输入拼进文件路径
            if (!Ids::isValidEmojiId($id)) {
                throw new HttpException(400, 'invalid_input', '表情 id 格式不正确（应形如 e_xxxxx）');
            }

            $found = Db::getEmojiItem($id);
            if (!$found) {
                throw new HttpException(404, 'not_found', '表情不存在');
            }
            $row = $found['row'];

            // 公共表情包里的表情不允许删除
            $pack = Db::getPackRow($row['pack_id']);
            if ($pack && !empty($pack['is_public'])) {
                throw new HttpException(403, 'forbidden', '公共表情包中的表情不可删除');
            }
            // 只能删除本人上传的表情
            if ($row['owner_id'] !== $me['user']['id']) {
                throw new HttpException(403, 'forbidden', '只能删除自己上传的表情');
            }

            // 先解析磁盘路径（必须在删库之前，之后就拿不到行了）
            $filePath = EmojiStore::resolve($row);
            Db::deleteEmojiItem($id);
            if ($filePath !== null && is_file($filePath)) {
                @unlink($filePath);
            }

            Log::info('删除表情', ['itemId' => $id, 'packId' => $row['pack_id'], 'userId' => $me['user']['id']]);
            Http::ok(['ok' => true]);
        });

        // GET /api/emoji/blob/:id —— 图片二进制
        $r->get('/api/emoji/blob/:id', function ($params) {
            // 鉴权：Authorization 头 或 ?token=（供 iOS AsyncImage 使用）
            $token = Http::extractToken();
            if ($token === null) {
                throw new HttpException(401, 'unauthorized', '缺少登录凭证');
            }
            $found = Auth::verifyUserToken($token);
            if (!$found) {
                throw new HttpException(401, 'unauthorized', '登录已过期，请重新登录');
            }
            if (!empty($found['row']['disabled'])) {
                throw new HttpException(403, 'forbidden', '账号已被禁用');
            }
            $user = $found['user'];

            $id = isset($params['id']) ? (string) $params['id'] : '';
            // 防路径穿越第一道关卡：严格校验 id 格式
            if (!Ids::isValidEmojiId($id)) {
                throw new HttpException(400, 'invalid_input', '表情 id 格式不正确');
            }

            $item = Db::getEmojiItem($id);
            if (!$item) {
                throw new HttpException(404, 'not_found', '表情不存在');
            }
            $row = $item['row'];
            $meta = $item['item'];

            // 只能访问同情侣空间或公共表情
            $pack = Db::getPackRow($row['pack_id']);
            $isPublic = $pack !== null && (int) $pack['is_public'] === 1;
            if (!$isPublic && (empty($row['couple_id']) || $row['couple_id'] !== $user['coupleId'])) {
                throw new HttpException(403, 'forbidden', '无权访问该表情');
            }

            $resolved = EmojiStore::resolve($row);
            if ($resolved === null) {
                Log::error('表情文件路径解析失败，已拒绝', ['id' => $id]);
                throw new HttpException(400, 'invalid_input', '表情 id 格式不正确');
            }
            if (!is_file($resolved)) {
                throw new HttpException(404, 'not_found', '表情文件不存在');
            }

            if (!headers_sent()) {
                http_response_code(200);
                header('Content-Type: ' . $meta['mime']);
                header('Content-Length: ' . (int) $meta['size']);
                // API.md §6 要求的长缓存
                header('Cache-Control: public, max-age=31536000, immutable');
                header('X-Content-Type-Options: nosniff');
            }
            readfile($resolved);
        });
    }
}
