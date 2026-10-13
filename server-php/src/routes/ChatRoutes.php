<?php
namespace App\routes;

use App\Auth;
use App\Config;
use App\Db;
use App\Http;
use App\HttpException;
use App\Log;
use App\Settings;
use App\Time;
use App\util\Validate;
use App\WsOutbox;

/**
 * 聊天接口（API.md §5）
 */
class ChatRoutes
{
    public static function register($r)
    {
        // GET /api/chat/messages?before=<ms>&limit=50
        $r->get('/api/chat/messages', function () {
            $me = Auth::requireUser();
            $coupleId = Auth::requireCouple($me['user']);

            $before = Validate::queryInt(Http::query('before'), 'before', ['min' => 0]);
            $limit = Validate::queryInt(Http::query('limit'), 'limit', [
                'defaultValue' => Config::limit('CHAT_PAGE_DEFAULT'),
                'min' => 1,
                'max' => 200,
            ]);

            $result = Db::listMessages([
                'coupleId' => $coupleId,
                'before' => $before,
                'limit' => $limit,
            ]);
            // 返回按 createdAt 升序（API.md §5）
            Http::ok(['messages' => $result['messages'], 'hasMore' => $result['hasMore']]);
        });

        // POST /api/chat/messages
        $r->post('/api/chat/messages', function () {
            $me = Auth::requireUser();
            $coupleId = Auth::requireCouple($me['user']);
            $body = Http::jsonBody();

            // clientId 幂等：重复提交返回已存在的那条消息
            $clientId = Validate::requireString(Http::field($body, 'clientId'), 'clientId', ['min' => 1, 'max' => 128]);
            $existing = Db::findMessageByClientId($coupleId, $clientId);
            if ($existing) {
                Http::ok(['message' => $existing]);
                return;
            }

            $kind = Validate::requireString(Http::field($body, 'kind'), 'kind', ['min' => 1, 'max' => 16, 'trim' => true]);
            if (!in_array($kind, Config::MESSAGE_KINDS, true)) {
                Validate::fail('kind 不在允许列表中，仅支持：' . implode('、', Config::MESSAGE_KINDS));
            }
            if (!in_array($kind, Config::CLIENT_MESSAGE_KINDS, true)) {
                Validate::fail('system 类型消息由服务端生成，客户端不能直接发送');
            }

            // body 长度上限 2000 字符（API.md §5）
            $messageBody = '';
            $rawBody = Http::field($body, 'body');
            if ($rawBody !== null) {
                if (!is_string($rawBody)) {
                    Validate::fail('body 必须是字符串');
                }
                $messageBody = $rawBody;
                if (Validate::length($messageBody) > Config::limit('CHAT_BODY_MAX')) {
                    Validate::fail('body 长度不能超过 ' . Config::limit('CHAT_BODY_MAX') . ' 个字符');
                }
            }
            if ($kind === 'text' && trim($messageBody) === '') {
                Validate::fail('text 类型消息的 body 不能为空');
            }
            if ($kind === 'emoji' || $kind === 'image') {
                if (trim($messageBody) === '') {
                    Validate::fail($kind . ' 类型消息的 body 不能为空');
                }
                if (!preg_match('/^(item|unicode):/', $messageBody)) {
                    Validate::fail($kind . ' 类型消息的 body 必须是 "item:<表情id>" 或 "unicode:<emoji>" 格式');
                }
            }

            $meta = Validate::requireJsonObject(Http::field($body, 'meta'), 'meta', [
                'allowNull' => true, 'defaultValue' => null,
            ]);

            // 每日消息总数限制（settings.maxMessagesPerDay）
            $maxPerDay = Settings::maxMessagesPerDay();
            if ($maxPerDay > 0) {
                $used = Db::countMessagesTodayByUser($me['user']['id']);
                if ($used >= $maxPerDay) {
                    throw new HttpException(429, 'rate_limited', '今日消息条数已达上限（' . $maxPerDay . ' 条）');
                }
            }

            try {
                $message = Db::insertMessage([
                    'clientId' => $clientId,
                    'coupleId' => $coupleId,
                    'senderId' => $me['user']['id'],
                    'kind' => $kind,
                    'body' => $messageBody,
                    'meta' => $meta,
                ]);
            } catch (\PDOException $e) {
                // 并发下同一 clientId 同时插入：唯一索引冲突 -> 返回已存在的那条（仍然幂等）
                if (strpos($e->getMessage(), 'UNIQUE') !== false || strpos($e->getMessage(), 'constraint') !== false) {
                    $found = Db::findMessageByClientId($coupleId, $clientId);
                    if ($found) {
                        Http::ok(['message' => $found]);
                        return;
                    }
                }
                throw $e;
            }

            Db::touchCouple($coupleId);
            // 实时推送给伴侣（也回推给自己，保证多端一致）
            WsOutbox::toCouple($coupleId, ['type' => 'message', 'message' => $message]);

            Log::info('发送消息', [
                'messageId' => $message['id'],
                'coupleId' => $coupleId,
                'senderId' => $me['user']['id'],
                'kind' => $kind,
            ]);
            Http::ok(['message' => $message]);
        });

        // POST /api/chat/read —— 把 createdAt <= upTo 且对方发来的消息标记为已读
        $r->post('/api/chat/read', function () {
            $me = Auth::requireUser();
            $coupleId = Auth::requireCouple($me['user']);
            $body = Http::jsonBody();
            $upTo = Validate::requireTimestamp(Http::field($body, 'upTo'), 'upTo', ['required' => true]);
            $count = Db::markMessagesRead($coupleId, $me['user']['id'], $upTo);

            WsOutbox::toCouple($coupleId, [
                'type' => 'read',
                'userId' => $me['user']['id'],
                'upTo' => $upTo,
            ], $me['user']['id']);

            Http::ok(['ok' => true, 'count' => $count]);
        });

        // DELETE /api/chat/messages/:id —— 撤回自己发的消息（24 小时内）
        $r->delete('/api/chat/messages/:id', function ($params) {
            $me = Auth::requireUser();
            $coupleId = Auth::requireCouple($me['user']);
            $id = Validate::requireString($params['id'], 'id', ['min' => 1, 'max' => 128]);
            $found = Db::getMessage($id);

            if (!$found || $found['row']['couple_id'] !== $coupleId) {
                throw new HttpException(404, 'not_found', '消息不存在');
            }
            if ($found['row']['sender_id'] !== $me['user']['id']) {
                throw new HttpException(403, 'forbidden', '只能撤回自己发送的消息');
            }
            if (!empty($found['row']['deleted_at'])) {
                // 已撤回，幂等返回成功
                Http::ok(['ok' => true]);
                return;
            }
            if (Time::nowMs() - (int) $found['row']['created_at'] > Config::limit('CHAT_RECALL_WINDOW_MS')) {
                throw new HttpException(403, 'forbidden', '超过 24 小时的消息不能撤回');
            }

            Db::recallMessage($id);
            WsOutbox::toCouple($coupleId, [
                'type' => 'message_deleted',
                'messageId' => $id,
                'userId' => $me['user']['id'],
            ]);

            Log::info('撤回消息', ['messageId' => $id, 'userId' => $me['user']['id']]);
            Http::ok(['ok' => true]);
        });
    }
}
