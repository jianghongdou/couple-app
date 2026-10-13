<?php
namespace App;

/**
 * 纯 PHP 的 WebSocket 服务（stream_socket_server + stream_select 事件循环）。
 *
 * 不依赖任何扩展/第三方库，手写握手与帧编解码，支持：
 *   - 鉴权：Authorization: Bearer <jwt> 头，或 ?token=<jwt>
 *   - 事件（API.md §8）：hello / message / read / typing / presence / sync / pong / error
 *   - 客户端事件：ping（保活）/ typing
 *   - 按情侣空间广播；同一用户允许多端在线（iPhone + iPad）
 *   - 心跳：服务端每 30 秒发 WS ping，剔除半开连接
 *
 * 跨进程事件来源：REST 进程把要广播的事件写入 ws_events 表（App\WsOutbox），
 * 本进程轮询该表并下发。
 */
class WsServer
{
    const GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';
    const HEARTBEAT_INTERVAL_MS = 30000;
    const STATUS_INTERVAL_MS = 10000;
    const PURGE_INTERVAL_MS = 60000;
    const COUPLE_REFRESH_MS = 5000;
    const MAX_FRAME_BYTES = 4194304;
    const MAX_HANDSHAKE_BYTES = 16384;

    /** @var resource */
    private $socket;
    private $host;
    private $port;
    private $path;

    /** @var array<int,array> 连接表：id => 连接信息 */
    private $clients = [];
    /** @var array<string,array<int,bool>> userId => [clientId => true] */
    private $userConns = [];
    private $nextId = 1;
    private $lastEventId = 0;
    private $running = true;
    private $lastHeartbeat = 0;
    private $lastStatus = 0;
    private $lastPurge = 0;

    public function __construct($host, $port, $path = '/ws')
    {
        $this->host = $host;
        $this->port = (int) $port;
        $this->path = $path;
    }

    /** 启动并进入事件循环（阻塞） */
    public function run()
    {
        $address = 'tcp://' . $this->host . ':' . $this->port;
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_server($address, $errno, $errstr);
        if ($socket === false) {
            $hint = ($errno === 98 || $errno === 10048)
                ? '（端口已被占用，请更换 WS_PORT 或结束占用进程）' : '';
            fwrite(STDERR, '❌ WebSocket 服务启动失败：' . $errstr . ' (' . $errno . ')' . $hint . "\n");
            exit(1);
        }
        stream_set_blocking($socket, false);
        $this->socket = $socket;

        $this->lastEventId = (int) Db::scalar('SELECT COALESCE(MAX(id), 0) FROM ws_events');

        Log::info('WebSocket 服务已启动', [
            'address' => $address,
            'path' => $this->path,
        ]);
        echo "✅ WebSocket 服务已启动： ws://" . $this->host . ':' . $this->port . $this->path . "?token=<jwt>\n";
        echo "   按 Ctrl+C 退出（生产环境请用 supervisor / 宝塔「进程守护」常驻）\n";

        while ($this->running) {
            $read = [$this->socket];
            $write = [];
            foreach ($this->clients as $id => $c) {
                $read[] = $c['sock'];
                if ($c['out'] !== '') {
                    $write[] = $c['sock'];
                }
            }
            $except = null;
            $n = @stream_select($read, $write, $except, 0, 250000);
            if ($n === false) {
                // 被信号打断等情况，继续下一轮
                usleep(20000);
            } elseif ($n > 0) {
                foreach ($read as $sock) {
                    if ($sock === $this->socket) {
                        $this->accept();
                        continue;
                    }
                    $id = $this->findClientBySocket($sock);
                    if ($id !== null) {
                        $this->onReadable($id);
                    }
                }
                foreach ($write as $sock) {
                    $id = $this->findClientBySocket($sock);
                    if ($id !== null) {
                        $this->flush($id);
                    }
                }
            }

            $this->pumpOutbox();
            $this->heartbeat();
            $this->refreshCouples();
            $this->publishStatus();
        }

        $this->shutdown();
    }

    /** 请求停止（收到信号时） */
    public function stop()
    {
        $this->running = false;
    }

    // ------------------------------------------------------------------
    // 连接管理
    // ------------------------------------------------------------------

    private function accept()
    {
        $sock = @stream_socket_accept($this->socket, 0);
        if ($sock === false) {
            return;
        }
        stream_set_blocking($sock, false);
        $id = $this->nextId++;
        $this->clients[$id] = [
            'sock' => $sock,
            'buf' => '',
            'out' => '',
            'handshaked' => false,
            'user' => null,
            'row' => null,
            'coupleId' => null,
            'isAlive' => true,
            'fragOpcode' => null,
            'fragPayload' => '',
            'refreshedAt' => Time::nowMs(),
            'closing' => false,
        ];
        Log::debug('WebSocket 新连接', ['id' => $id, 'total' => count($this->clients)]);
    }

    private function findClientBySocket($sock)
    {
        foreach ($this->clients as $id => $c) {
            if ($c['sock'] === $sock) {
                return $id;
            }
        }
        return null;
    }

    private function onReadable($id)
    {
        if (!isset($this->clients[$id])) {
            return;
        }
        $sock = $this->clients[$id]['sock'];
        $data = @fread($sock, 8192);
        if ($data === false || $data === '') {
            if (feof($sock)) {
                $this->closeClient($id, 1006, false);
            }
            return;
        }
        $this->clients[$id]['buf'] .= $data;
        if (!$this->clients[$id]['handshaked']) {
            $this->tryHandshake($id);
            return;
        }
        $this->parseFrames($id);
    }

    // ------------------------------------------------------------------
    // 握手
    // ------------------------------------------------------------------

    private function tryHandshake($id)
    {
        $buf = $this->clients[$id]['buf'];
        if (strlen($buf) > self::MAX_HANDSHAKE_BYTES) {
            $this->rawWrite($id, "HTTP/1.1 431 Request Header Fields Too Large\r\n\r\n");
            $this->closeClient($id, 1002, false);
            return;
        }
        $pos = strpos($buf, "\r\n\r\n");
        if ($pos === false) {
            return; // 还没收全
        }
        $head = substr($buf, 0, $pos);
        $rest = substr($buf, $pos + 4);
        $this->clients[$id]['buf'] = $rest;

        $lines = explode("\r\n", $head);
        $requestLine = array_shift($lines);
        $parts = explode(' ', (string) $requestLine);
        $target = isset($parts[1]) ? $parts[1] : '';
        $headers = [];
        foreach ($lines as $line) {
            $p = strpos($line, ':');
            if ($p === false) {
                continue;
            }
            $headers[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
        }

        $path = $target;
        $query = '';
        $qpos = strpos($target, '?');
        if ($qpos !== false) {
            $path = substr($target, 0, $qpos);
            $query = substr($target, $qpos + 1);
        }

        if ($path !== $this->path && rtrim($path, '/') !== rtrim($this->path, '/')) {
            $this->rawWrite($id, "HTTP/1.1 404 Not Found\r\nConnection: close\r\n\r\n");
            $this->closeClient($id, 1002, false);
            return;
        }

        $upgrade = isset($headers['upgrade']) ? strtolower($headers['upgrade']) : '';
        $key = isset($headers['sec-websocket-key']) ? $headers['sec-websocket-key'] : '';
        if (strpos($upgrade, 'websocket') === false || $key === '') {
            $this->rawWrite($id, "HTTP/1.1 400 Bad Request\r\nConnection: close\r\n\r\n");
            $this->closeClient($id, 1002, false);
            return;
        }

        // 鉴权：Authorization 头 或 ?token=
        $token = null;
        if (isset($headers['authorization']) && stripos($headers['authorization'], 'Bearer ') === 0) {
            $token = trim(substr($headers['authorization'], 7));
        }
        if (($token === null || $token === '') && $query !== '') {
            $params = [];
            parse_str($query, $params);
            if (isset($params['token']) && is_string($params['token']) && $params['token'] !== '') {
                $token = $params['token'];
            }
        }
        if ($token === null || $token === '') {
            $this->rawWrite($id, "HTTP/1.1 401 Unauthorized\r\nConnection: close\r\n\r\n");
            $this->closeClient($id, 1002, false);
            return;
        }
        $found = Auth::verifyUserToken($token);
        if (!$found || !empty($found['row']['disabled'])) {
            $this->rawWrite($id, "HTTP/1.1 401 Unauthorized\r\nConnection: close\r\n\r\n");
            $this->closeClient($id, 1002, false);
            return;
        }

        $accept = base64_encode(sha1($key . self::GUID, true));
        $this->rawWrite($id,
            "HTTP/1.1 101 Switching Protocols\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . 'Sec-WebSocket-Accept: ' . $accept . "\r\n\r\n"
        );

        $this->clients[$id]['handshaked'] = true;
        $this->clients[$id]['user'] = $found['user'];
        $this->clients[$id]['row'] = $found['row'];
        $this->clients[$id]['coupleId'] = $found['user']['coupleId'];
        $this->register($id);

        Db::touchUserLastSeen($found['user']['id']);
        Log::info('WebSocket 已连接', ['userId' => $found['user']['id'], 'online' => $this->onlineCount()]);

        // 握手事件：hello（API.md §8）
        $this->sendJson($id, [
            'type' => 'hello',
            'user' => $found['user'],
            'coupleId' => $found['user']['coupleId'],
            'serverTime' => Time::nowMs(),
        ]);
        // 连接建立时把在线状态同步给本端
        if (!empty($found['user']['coupleId'])) {
            $this->sendJson($id, [
                'type' => 'presence',
                'userId' => $found['user']['id'],
                'online' => true,
                'lastSeen' => Time::nowMs(),
            ]);
        }

        // 处理握手时同包到达的第一批帧
        if ($this->clients[$id]['buf'] !== '') {
            $this->parseFrames($id);
        }
    }

    private function register($id)
    {
        $userId = $this->clients[$id]['user']['id'];
        $wasOffline = !isset($this->userConns[$userId]) || count($this->userConns[$userId]) === 0;
        if (!isset($this->userConns[$userId])) {
            $this->userConns[$userId] = [];
        }
        $this->userConns[$userId][$id] = true;
        if ($wasOffline) {
            $this->announcePresence($id, true);
        }
    }

    private function unregister($id)
    {
        if (!isset($this->clients[$id])) {
            return;
        }
        $userId = $this->clients[$id]['user'] ? $this->clients[$id]['user']['id'] : null;
        if ($userId === null || !isset($this->userConns[$userId])) {
            return;
        }
        unset($this->userConns[$userId][$id]);
        if (count($this->userConns[$userId]) === 0) {
            unset($this->userConns[$userId]);
            $this->announcePresence($id, false);
        }
    }

    private function announcePresence($id, $online)
    {
        $c = isset($this->clients[$id]) ? $this->clients[$id] : null;
        if ($c === null || empty($c['coupleId']) || empty($c['user'])) {
            return;
        }
        $this->broadcastToCouple($c['coupleId'], [
            'type' => 'presence',
            'userId' => $c['user']['id'],
            'online' => $online,
            'lastSeen' => Time::nowMs(),
        ], $c['user']['id']);
    }

    // ------------------------------------------------------------------
    // 帧编解码
    // ------------------------------------------------------------------

    private function parseFrames($id)
    {
        while (isset($this->clients[$id])) {
            $buf = $this->clients[$id]['buf'];
            $len = strlen($buf);
            if ($len < 2) {
                return;
            }
            $b0 = ord($buf[0]);
            $b1 = ord($buf[1]);
            $fin = ($b0 & 0x80) !== 0;
            $opcode = $b0 & 0x0F;
            $masked = ($b1 & 0x80) !== 0;
            $payloadLen = $b1 & 0x7F;
            $offset = 2;

            if ($payloadLen === 126) {
                if ($len < 4) {
                    return;
                }
                $unpacked = unpack('n', substr($buf, 2, 2));
                $payloadLen = $unpacked[1];
                $offset = 4;
            } elseif ($payloadLen === 127) {
                if ($len < 10) {
                    return;
                }
                $unpacked = unpack('J', substr($buf, 2, 8));
                $payloadLen = $unpacked[1];
                $offset = 10;
            }

            if ($payloadLen > self::MAX_FRAME_BYTES) {
                Log::warn('WebSocket 帧过大，已断开', ['id' => $id, 'len' => $payloadLen]);
                $this->closeClient($id, 1009, true);
                return;
            }

            $maskKey = '';
            if ($masked) {
                if ($len < $offset + 4) {
                    return;
                }
                $maskKey = substr($buf, $offset, 4);
                $offset += 4;
            }
            if ($len < $offset + $payloadLen) {
                return; // 帧还没收全
            }

            $payload = substr($buf, $offset, $payloadLen);
            $this->clients[$id]['buf'] = substr($buf, $offset + $payloadLen);
            if ($masked) {
                $payload = self::applyMask($payload, $maskKey);
            }

            // 控制帧
            if ($opcode === 0x8) { // close
                $this->closeClient($id, 1000, true);
                return;
            }
            if ($opcode === 0x9) { // ping
                $this->clients[$id]['isAlive'] = true;
                $this->sendFrame($id, 0xA, $payload);
                continue;
            }
            if ($opcode === 0xA) { // pong
                $this->clients[$id]['isAlive'] = true;
                continue;
            }

            // 数据帧
            if ($opcode === 0x0) { // continuation
                if ($this->clients[$id]['fragOpcode'] === null) {
                    continue;
                }
                $this->clients[$id]['fragPayload'] .= $payload;
                if ($fin) {
                    $full = $this->clients[$id]['fragPayload'];
                    $this->clients[$id]['fragOpcode'] = null;
                    $this->clients[$id]['fragPayload'] = '';
                    $this->handleDataFrame($id, $full);
                }
                continue;
            }
            if ($opcode === 0x1 || $opcode === 0x2) {
                if (!$fin) {
                    $this->clients[$id]['fragOpcode'] = $opcode;
                    $this->clients[$id]['fragPayload'] = $payload;
                    continue;
                }
                $this->handleDataFrame($id, $payload);
                continue;
            }
            // 其它保留 opcode：忽略
        }
    }

    private static function applyMask($payload, $maskKey)
    {
        $out = '';
        $len = strlen($payload);
        for ($i = 0; $i < $len; $i++) {
            $out .= chr(ord($payload[$i]) ^ ord($maskKey[$i % 4]));
        }
        return $out;
    }

    /** 组装并排队一个 WS 帧（服务端 -> 客户端，不掩码） */
    private function sendFrame($id, $opcode, $payload = '')
    {
        if (!isset($this->clients[$id])) {
            return false;
        }
        $len = strlen($payload);
        $header = chr(0x80 | ($opcode & 0x0F));
        if ($len < 126) {
            $header .= chr($len);
        } elseif ($len <= 0xFFFF) {
            $header .= chr(126) . pack('n', $len);
        } else {
            $header .= chr(127) . pack('J', $len);
        }
        $this->clients[$id]['out'] .= $header . $payload;
        $this->flush($id);
        return true;
    }

    private function sendJson($id, array $payload)
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return false;
        }
        return $this->sendFrame($id, 0x1, $json);
    }

    /** 立刻把待发缓冲写出去（非阻塞，写不完留到下次 select 可写时） */
    private function flush($id)
    {
        if (!isset($this->clients[$id]) || $this->clients[$id]['out'] === '') {
            return;
        }
        $sock = $this->clients[$id]['sock'];
        $written = @fwrite($sock, $this->clients[$id]['out']);
        if ($written === false) {
            $this->closeClient($id, 1011, false);
            return;
        }
        if ($written > 0) {
            $this->clients[$id]['out'] = substr($this->clients[$id]['out'], $written);
        }
    }

    /** 握手阶段的原始 HTTP 响应（不经过帧编码） */
    private function rawWrite($id, $text)
    {
        if (!isset($this->clients[$id])) {
            return;
        }
        @fwrite($this->clients[$id]['sock'], $text);
    }

    // ------------------------------------------------------------------
    // 业务事件
    // ------------------------------------------------------------------

    private function handleDataFrame($id, $payload)
    {
        if (!isset($this->clients[$id])) {
            return;
        }
        $msg = json_decode($payload);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->sendJson($id, ['type' => 'error', 'message' => '消息格式必须是 JSON']);
            return;
        }
        if (!($msg instanceof \stdClass) || !isset($msg->type) || !is_string($msg->type)) {
            $this->sendJson($id, ['type' => 'error', 'message' => '缺少 type 字段']);
            return;
        }

        $user = $this->clients[$id]['user'];
        switch ($msg->type) {
            case 'ping':
                // 客户端每 25 秒 ping 一次保活（API.md §8）
                if ($user) {
                    Db::touchUserLastSeen($user['id']);
                }
                $this->sendJson($id, ['type' => 'pong', 'serverTime' => Time::nowMs()]);
                break;

            case 'typing':
                if (!empty($this->clients[$id]['coupleId']) && $user) {
                    $this->broadcastToCouple($this->clients[$id]['coupleId'], [
                        'type' => 'typing',
                        'userId' => $user['id'],
                        'at' => Time::nowMs(),
                    ], $user['id']);
                }
                break;

            default:
                $this->sendJson($id, ['type' => 'error', 'message' => '不支持的事件类型：' . $msg->type]);
        }
    }

    /** 给某个情侣空间的所有在线成员广播（可排除某个用户） */
    public function broadcastToCouple($coupleId, array $payload, $exceptUserId = null)
    {
        if (!$coupleId) {
            return 0;
        }
        $n = 0;
        foreach ($this->clients as $id => $c) {
            if (empty($c['handshaked']) || empty($c['user'])) {
                continue;
            }
            if ($c['coupleId'] !== $coupleId) {
                continue;
            }
            if ($exceptUserId !== null && $c['user']['id'] === $exceptUserId) {
                continue;
            }
            if ($this->sendJson($id, $payload)) {
                $n++;
            }
        }
        return $n;
    }

    /** 给指定用户的所有连接发送 */
    public function sendToUser($userId, array $payload)
    {
        if (!isset($this->userConns[$userId])) {
            return 0;
        }
        $n = 0;
        foreach (array_keys($this->userConns[$userId]) as $id) {
            if ($this->sendJson($id, $payload)) {
                $n++;
            }
        }
        return $n;
    }

    /** 在线用户数 */
    public function onlineCount()
    {
        return count($this->userConns);
    }

    /** 连接数（含多端） */
    public function connectionCount()
    {
        $n = 0;
        foreach ($this->clients as $c) {
            if (!empty($c['handshaked'])) {
                $n++;
            }
        }
        return $n;
    }

    /**
     * 轮询 ws_events 表，把 REST 进程写入的事件广播出去
     */
    private function pumpOutbox()
    {
        try {
            $rows = WsOutbox::pollAfter($this->lastEventId, 200);
        } catch (\Exception $e) {
            Log::warn('读取实时推送事件失败', ['error' => $e->getMessage()]);
            return;
        }
        if (count($rows) === 0) {
            return;
        }
        foreach ($rows as $row) {
            $this->lastEventId = max($this->lastEventId, (int) $row['id']);
            $payload = json_decode($row['payload'], true);
            if (!is_array($payload)) {
                continue;
            }
            if (!empty($row['target_user'])) {
                $this->sendToUser($row['target_user'], $payload);
                continue;
            }
            $except = empty($row['except_user']) ? null : $row['except_user'];
            $this->broadcastToCouple($row['couple_id'], $payload, $except);
        }
        try {
            WsOutbox::deleteUpTo($this->lastEventId);
        } catch (\Exception $e) {
            Log::warn('清理实时推送事件失败', ['error' => $e->getMessage()]);
        }
    }

    /** 心跳：服务端每 30 秒 ping 一次，未回 pong 的连接直接断开 */
    private function heartbeat()
    {
        $now = Time::nowMs();
        if ($now - $this->lastHeartbeat < self::HEARTBEAT_INTERVAL_MS) {
            return;
        }
        $this->lastHeartbeat = $now;
        foreach ($this->clients as $id => $c) {
            if (empty($c['handshaked'])) {
                continue;
            }
            if ($c['isAlive'] === false) {
                $this->closeClient($id, 1001, true);
                continue;
            }
            $this->clients[$id]['isAlive'] = false;
            $this->sendFrame($id, 0x9, '');
        }
    }

    /**
     * 定期从数据库刷新连接的 coupleId（用户刚加入/解绑情侣空间后，
     * 已建立的连接也要能收到对应的广播）
     */
    private function refreshCouples()
    {
        $now = Time::nowMs();
        foreach ($this->clients as $id => $c) {
            if (empty($c['handshaked']) || empty($c['user'])) {
                continue;
            }
            if ($now - $c['refreshedAt'] < self::COUPLE_REFRESH_MS) {
                continue;
            }
            $this->clients[$id]['refreshedAt'] = $now;
            try {
                $row = Db::one('SELECT couple_id, disabled FROM users WHERE id = ?', [$c['user']['id']]);
            } catch (\Exception $e) {
                continue;
            }
            if (!$row) {
                $this->closeClient($id, 1000, true);
                continue;
            }
            if (!empty($row['disabled'])) {
                $this->sendJson($id, ['type' => 'error', 'message' => '账号已被禁用']);
                $this->closeClient($id, 1000, true);
                continue;
            }
            $coupleId = $row['couple_id'] !== null ? (string) $row['couple_id'] : null;
            if ($coupleId !== $this->clients[$id]['coupleId']) {
                $this->clients[$id]['coupleId'] = $coupleId;
                // 同步给客户端，便于它刷新界面
                $this->sendJson($id, [
                    'type' => 'couple',
                    'couple' => $coupleId ? Db::buildCouple($coupleId) : null,
                ]);
            }
        }
    }

    private function publishStatus()
    {
        $now = Time::nowMs();
        if ($now - $this->lastStatus < self::STATUS_INTERVAL_MS) {
            return;
        }
        $this->lastStatus = $now;
        WsHub::publish($this->onlineCount(), $this->connectionCount(), [
            'host' => $this->host,
            'path' => $this->path,
        ]);
        // 顺手清理过期事件（WS 进程没跑时 REST 写入的事件也不会无限堆积）
        if ($now - $this->lastPurge > self::PURGE_INTERVAL_MS) {
            $this->lastPurge = $now;
            try {
                WsOutbox::purgeOlderThan(300000);
                RateLimit::purge();
            } catch (\Exception $e) {
                Log::warn('清理过期数据失败', ['error' => $e->getMessage()]);
            }
        }
    }

    // ------------------------------------------------------------------
    // 关闭
    // ------------------------------------------------------------------

    private function closeClient($id, $code = 1000, $sendClose = true)
    {
        if (!isset($this->clients[$id])) {
            return;
        }
        if ($sendClose && !empty($this->clients[$id]['handshaked'])) {
            $this->sendFrame($id, 0x8, pack('n', $code));
        }
        $this->unregister($id);
        $sock = $this->clients[$id]['sock'];
        @fclose($sock);
        $userId = $this->clients[$id]['user'] ? $this->clients[$id]['user']['id'] : null;
        unset($this->clients[$id]);
        if ($userId !== null) {
            Log::info('WebSocket 已断开', ['userId' => $userId, 'online' => $this->onlineCount()]);
        }
    }

    private function shutdown()
    {
        foreach (array_keys($this->clients) as $id) {
            $this->closeClient($id, 1001, true);
        }
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }
        WsHub::publish(0, 0, ['stopped' => true]);
        Log::info('WebSocket 服务已退出');
    }
}
