<?php
namespace App\routes;

use App\Auth;
use App\Config;
use App\Db;
use App\Http;
use App\HttpException;
use App\Log;
use App\Time;
use App\util\Validate;
use App\WsOutbox;

/**
 * 情侣关系接口（API.md §3）
 */
class CoupleRoutes
{
    public static function register($r)
    {
        /**
         * 写入一条系统消息（API.md §5 kind: system）并实时推送。
         */
        $systemMessage = function ($coupleId, $senderId, $text) {
            try {
                $message = Db::insertMessage([
                    'clientId' => null,
                    'coupleId' => $coupleId,
                    'senderId' => $senderId,
                    'kind' => 'system',
                    'body' => $text,
                    'meta' => null,
                ]);
                WsOutbox::toCouple($coupleId, ['type' => 'message', 'message' => $message]);
                return $message;
            } catch (\Exception $e) {
                // 系统消息失败不应影响主流程
                Log::warn('写入系统消息失败', ['error' => $e->getMessage()]);
                return null;
            }
        };

        // POST /api/couple/create
        $r->post('/api/couple/create', function () use ($systemMessage) {
            $me = Auth::requireUser();
            $fresh = Db::getUserById($me['user']['id']);
            if (!$fresh) {
                throw new HttpException(404, 'not_found', '用户不存在');
            }
            if (!empty($fresh['user']['coupleId'])) {
                throw new HttpException(409, 'conflict', '你已经有情侣空间了');
            }

            $created = Db::createCouple(['anniversaryTitle' => '在一起']);
            Db::setUserCouple($me['user']['id'], $created['id']);
            $systemMessage($created['id'], $me['user']['id'], '情侣空间已创建，把邀请码发给 TA 吧');

            $couple = Db::buildCouple($created['id']);
            Log::info('创建情侣空间', ['coupleId' => $created['id'], 'userId' => $me['user']['id']]);
            Http::ok(['couple' => $couple]);
        });

        // POST /api/couple/join
        $r->post('/api/couple/join', function () use ($systemMessage) {
            $me = Auth::requireUser();
            $fresh = Db::getUserById($me['user']['id']);
            if (!$fresh) {
                throw new HttpException(404, 'not_found', '用户不存在');
            }
            if (!empty($fresh['user']['coupleId'])) {
                throw new HttpException(409, 'conflict', '你已经有情侣空间了');
            }

            $body = Http::jsonBody();
            $code = strtoupper(Validate::requireString(Http::field($body, 'inviteCode'), 'inviteCode', [
                'min' => 4, 'max' => 16, 'trim' => true,
            ]));

            $couple = Db::getCoupleByInviteCode($code);
            if (!$couple) {
                throw new HttpException(404, 'not_found', '邀请码无效');
            }
            if (Db::getCoupleMemberCount($couple['id']) >= Config::limit('COUPLE_MAX_MEMBERS')) {
                throw new HttpException(409, 'conflict', '该情侣空间已满');
            }

            Db::setUserCouple($me['user']['id'], $couple['id']);
            Log::info('加入情侣空间', ['coupleId' => $couple['id'], 'userId' => $me['user']['id']]);

            $nickname = $fresh['user']['nickname'] !== '' ? $fresh['user']['nickname'] : $fresh['user']['username'];
            $systemMessage($couple['id'], $me['user']['id'], $nickname . ' 加入了情侣空间，你们已绑定');

            $full = Db::buildCouple($couple['id']);
            WsOutbox::toCouple($couple['id'], ['type' => 'couple', 'couple' => $full]);
            Http::ok(['couple' => $full]);
        });

        // GET /api/couple —— members 含双方完整 user 对象
        $r->get('/api/couple', function () {
            $me = Auth::requireUser();
            $coupleId = Auth::requireCouple($me['user']);
            $couple = Db::buildCouple($coupleId);
            if (!$couple) {
                throw new HttpException(404, 'not_found', '情侣空间不存在');
            }
            Http::ok(['couple' => $couple]);
        });

        // PATCH /api/couple —— togetherDate / anniversaryTitle
        $r->patch('/api/couple', function () {
            $me = Auth::requireUser();
            $coupleId = Auth::requireCouple($me['user']);
            $body = Http::jsonBody();
            $patch = [];

            if (Http::has($body, 'togetherDate')) {
                $v = Http::field($body, 'togetherDate');
                // 允许显式传 null 表示清空
                $patch['togetherDate'] = $v === null
                    ? null
                    : Validate::requireTimestamp($v, 'togetherDate', ['required' => true]);
            }
            if (Http::has($body, 'anniversaryTitle')) {
                $patch['anniversaryTitle'] = Validate::optionalString(
                    Http::field($body, 'anniversaryTitle'),
                    'anniversaryTitle',
                    ['min' => 1, 'max' => 32, 'trim' => true]
                );
            }
            if (count($patch) === 0) {
                Validate::fail('请至少提供 togetherDate 或 anniversaryTitle');
            }

            $couple = Db::updateCouple($coupleId, $patch);
            if (!$couple) {
                throw new HttpException(404, 'not_found', '情侣空间不存在');
            }
            WsOutbox::toCouple($coupleId, ['type' => 'couple', 'couple' => $couple]);
            Http::ok(['couple' => $couple]);
        });

        // POST /api/couple/leave —— 解除绑定，双方 coupleId 置空（数据保留 30 天）
        $r->post('/api/couple/leave', function () {
            $me = Auth::requireUser();
            $coupleId = Auth::requireCouple($me['user']);
            $affected = Db::dissolveCouple($coupleId);
            Log::info('解除情侣绑定', ['coupleId' => $coupleId, 'userId' => $me['user']['id'], 'affected' => $affected]);

            // 通知双方（广播按旧 coupleId 查找连接，此时用户已不在该空间）
            WsOutbox::toCouple($coupleId, ['type' => 'couple', 'couple' => null]);
            Http::ok(['ok' => true]);
        });
    }
}
