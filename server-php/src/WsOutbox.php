<?php
namespace App;

/**
 * 实时推送事件队列（内部表 ws_events）。
 *
 * 为什么需要它：PHP-FPM 下每个 REST 请求都是独立进程，无法像 Node 版那样
 * 直接在进程内调用 WebSocket 广播。因此 REST 侧只把「要广播的事件」写入队列表，
 * 由常驻进程 bin/ws-server.php 轮询并经 WebSocket 下发给客户端。
 *
 * 事件格式与 API.md §8 完全一致（payload 就是客户端收到的那份 JSON）。
 */
class WsOutbox
{
    /**
     * 写入一条待广播事件
     * @param string|null $coupleId  目标情侣空间；为 null 时使用 targetUser
     * @param array       $payload   发给客户端的完整事件（含 type 字段）
     * @param string|null $exceptUser 排除某个用户（例如事件发起者）
     * @param string|null $targetUser 只发给某个用户
     */
    public static function enqueue($coupleId, array $payload, $exceptUser = null, $targetUser = null)
    {
        try {
            Db::run(
                'INSERT INTO ws_events (couple_id, target_user, except_user, payload, created_at)
                 VALUES (?, ?, ?, ?, ?)',
                [
                    $coupleId,
                    $targetUser,
                    $exceptUser,
                    json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    Time::nowMs(),
                ]
            );
        } catch (\Exception $e) {
            // 实时推送失败不能影响 REST 主流程
            Log::warn('写入实时推送事件失败', ['error' => $e->getMessage()]);
        }
    }

    /** 给某情侣空间广播（可排除发起者） */
    public static function toCouple($coupleId, array $payload, $exceptUser = null)
    {
        if (!$coupleId) {
            return;
        }
        self::enqueue($coupleId, $payload, $exceptUser, null);
    }

    /** 只发给某个用户 */
    public static function toUser($userId, array $payload)
    {
        self::enqueue(null, $payload, null, $userId);
    }

    /**
     * 读取 id 大于 $sinceId 的事件（最多 $limit 条）
     * @return array<int,array>
     */
    public static function pollAfter($sinceId, $limit = 200)
    {
        return Db::all(
            'SELECT * FROM ws_events WHERE id > ? ORDER BY id ASC LIMIT ?',
            [(int) $sinceId, (int) $limit]
        );
    }

    /** 删除已经广播完的事件（单调递增，直接按 id 范围删） */
    public static function deleteUpTo($id)
    {
        Db::run('DELETE FROM ws_events WHERE id <= ?', [(int) $id]);
    }

    /** 清理历史事件（WS 进程没在跑时避免无限堆积） */
    public static function purgeOlderThan($ms = 300000)
    {
        $cutoff = Time::nowMs() - $ms;
        $stmt = Db::run('DELETE FROM ws_events WHERE created_at < ?', [$cutoff]);
        return $stmt->rowCount();
    }
}
