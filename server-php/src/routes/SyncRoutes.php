<?php
namespace App\routes;

use App\Auth;
use App\Config;
use App\Db;
use App\Http;
use App\Log;
use App\Time;
use App\util\Validate;
use App\WsOutbox;

/**
 * 通用数据同步接口（API.md §4）
 *
 * 重要：记账（ledger）与密码本（vault）不参与同步，只存手机本地。
 * 服务端明确拒绝这两个 kind，并返回可读的中文原因。
 */
class SyncRoutes
{
    public static function register($r)
    {
        // POST /api/sync/push
        $r->post('/api/sync/push', function () {
            $me = Auth::requireUser();
            $coupleId = Auth::requireCouple($me['user']);
            $body = Http::jsonBody();
            $objects = Http::field($body, 'objects');

            if (!is_array($objects)) {
                Validate::fail('objects 必须是数组');
            }
            if (count($objects) === 0) {
                Validate::fail('objects 不能为空数组');
            }
            if (count($objects) > Config::limit('SYNC_MAX_OBJECTS')) {
                Validate::fail('单次最多推送 ' . Config::limit('SYNC_MAX_OBJECTS') . ' 个对象，当前 ' . count($objects) . ' 个');
            }

            $validated = [];
            foreach ($objects as $i => $o) {
                $validated[] = self::validateSyncObject($o, $i);
            }

            $result = Db::pushSyncObjects([
                'coupleId' => $coupleId,
                'userId' => $me['user']['id'],
                'objects' => $validated,
            ]);

            // 通过 WS 给伴侣广播 {type:"sync"}（API.md §8）
            if (count($result['applied']) > 0) {
                $kinds = [];
                foreach ($validated as $v) {
                    if (in_array($v['id'], $result['applied'], true) && !in_array($v['kind'], $kinds, true)) {
                        $kinds[] = $v['kind'];
                    }
                }
                WsOutbox::toCouple($coupleId, [
                    'type' => 'sync',
                    'kinds' => $kinds,
                    'fromUserId' => $me['user']['id'],
                ], $me['user']['id']);
            }

            Log::info('同步推送完成', [
                'userId' => $me['user']['id'],
                'coupleId' => $coupleId,
                'applied' => count($result['applied']),
                'conflicts' => count($result['conflicts']),
            ]);

            Http::ok(['applied' => $result['applied'], 'conflicts' => $result['conflicts']]);
        });

        // GET /api/sync/pull?since=<ms>&kinds=spark,pet&limit=500
        $r->get('/api/sync/pull', function () {
            $me = Auth::requireUser();
            $coupleId = Auth::requireCouple($me['user']);

            $since = Validate::queryInt(Http::query('since'), 'since', ['defaultValue' => 0, 'min' => 0]);

            // kinds 可选，逗号分隔，缺省为全部白名单
            $kinds = Config::SYNC_KINDS;
            $rawKinds = Http::query('kinds');
            if (is_string($rawKinds) && trim($rawKinds) !== '') {
                $requested = [];
                foreach (explode(',', $rawKinds) as $s) {
                    $s = trim($s);
                    if ($s !== '') {
                        $requested[] = $s;
                    }
                }
                if (count($requested) === 0) {
                    Validate::fail('kinds 参数不能为空');
                }
                $rejected = [];
                foreach ($requested as $k) {
                    if (in_array($k, Config::SYNC_REJECTED_KINDS, true)) {
                        $rejected[] = $k;
                    }
                }
                if (count($rejected) > 0) {
                    Validate::fail('kind "' . implode('、', $rejected)
                        . '" 不参与服务端同步：记账数据与密码本只保存在手机本地，服务端不接收。');
                }
                $invalid = [];
                foreach ($requested as $k) {
                    if (!in_array($k, Config::SYNC_KINDS, true)) {
                        $invalid[] = $k;
                    }
                }
                if (count($invalid) > 0) {
                    Validate::fail('kinds 包含不支持的取值：' . implode('、', $invalid)
                        . '，仅支持：' . implode('、', Config::SYNC_KINDS));
                }
                $kinds = array_values(array_unique($requested));
            }

            // limit 默认 500，上限 1000
            $limit = Validate::queryInt(Http::query('limit'), 'limit', [
                'defaultValue' => Config::limit('SYNC_PULL_DEFAULT'),
                'min' => 1,
                'max' => Config::limit('SYNC_PULL_MAX'),
            ]);

            $result = Db::pullSyncObjects([
                'coupleId' => $coupleId,
                'since' => $since,
                'kinds' => $kinds,
                'limit' => $limit,
            ]);

            // 客户端应使用服务端返回的 serverTime 作为下次 since
            Http::ok(['objects' => $result['objects'], 'hasMore' => $result['hasMore']]);
        });
    }

    /**
     * 校验单个同步对象
     * @param mixed $raw
     * @param int $index
     * @return array
     */
    private static function validateSyncObject($raw, $index)
    {
        $at = 'objects[' . $index . ']';
        if (!Validate::isPlainObject($raw)) {
            Validate::fail($at . ' 必须是一个 JSON 对象');
        }

        $kind = Validate::requireString(Http::field($raw, 'kind'), $at . '.kind', [
            'min' => 1, 'max' => 32, 'trim' => true,
        ]);
        if (!in_array($kind, Config::SYNC_KINDS, true)) {
            if (in_array($kind, Config::SYNC_REJECTED_KINDS, true)) {
                // 这两类数据只存手机本地（API.md §4）
                Validate::fail('kind "' . $kind . '" 不参与服务端同步：记账数据与密码本只保存在手机本地，服务端不接收、不存储。');
            }
            Validate::fail($at . '.kind 不在允许列表中，仅支持：' . implode('、', Config::SYNC_KINDS));
        }

        $id = Validate::requireString(Http::field($raw, 'id'), $at . '.id', ['min' => 1, 'max' => 128]);
        $updatedAt = Validate::requireTimestamp(Http::field($raw, 'updatedAt'), $at . '.updatedAt', ['required' => true]);
        $deleted = Validate::optionalBool(Http::field($raw, 'deleted'), $at . '.deleted', false);
        $payload = Validate::requireJsonObject(Http::field($raw, 'payload'), $at . '.payload', [
            'allowNull' => true, 'defaultValue' => null,
        ]);

        return [
            'kind' => $kind,
            'id' => $id,
            'updatedAt' => $updatedAt,
            'deleted' => $deleted,
            'payload' => $payload,
        ];
    }
}
