<?php
/**
 * 端到端冒烟测试（php test/smoke.php）
 *
 * 做法：
 *   1. 先连 MySQL/MariaDB：DROP + CREATE 测试库（couple_test），保证从零开始
 *   2. 用 proc_open 启动真实服务：php -S 127.0.0.1:8899 -t public
 *      （环境变量注入随机 JWT_SECRET / ADMIN_PASSWORD / DATA_DIR=./data-test 与
 *        DB_HOST/DB_PORT/DB_NAME/DB_USER/DB_PASS，
 *        并设置 COUPLE_CONFIG_FILE=none 保证不受本机 config.php 影响）
 *   3. 逐条断言 docs/API.md 定义的关键接口（对齐 Node 版 server/test/smoke.mjs）
 *   4. 再启动 bin/ws-server.php（端口 8900），用本文件里手写的极简 WebSocket 客户端
 *      断言 hello / message 实时推送 / ping-pong / presence / typing
 *   5. 全部输出同时写入 test-report.txt；结束时 DROP 测试库、清理临时文件
 *
 * 运行： php test/smoke.php
 * 数据库连接可用环境变量覆盖（默认下面的值）：
 *   TEST_DB_HOST / TEST_DB_PORT / TEST_DB_NAME / TEST_DB_USER / TEST_DB_PASS
 */

// ---------------------------------------------------------------------------
// 测试配置
// ---------------------------------------------------------------------------
$ROOT = dirname(__DIR__);
$PORT = 8899;
$WS_PORT = 8900;
$BASE = 'http://127.0.0.1:' . $PORT;
$ADMIN_PASSWORD = 'test123';
$JWT_SECRET = bin2hex(random_bytes(48));
$ADMIN_JWT_SECRET = bin2hex(random_bytes(48));
$DATA_DIR = './data-test';
$DATA_DIR_ABS = $ROOT . '/data-test';
$PUBLIC_PACK_DIR = $ROOT . '/public/emoji/公共表情';
$REPORT_FILE = $ROOT . '/test-report.txt';
$REST_LOG = $ROOT . '/test-server.log';
$WS_LOG = $ROOT . '/test-ws-server.log';

// MySQL / MariaDB（宝塔环境的等价物）。测试库会在每次运行前重建。
function envOr($key, $default)
{
    $v = getenv($key);
    return ($v === false || $v === '') ? $default : $v;
}
$DB_HOST = envOr('TEST_DB_HOST', '127.0.0.1');
$DB_PORT = (int) envOr('TEST_DB_PORT', '3307');
$DB_NAME = envOr('TEST_DB_NAME', 'couple_test');
$DB_USER = envOr('TEST_DB_USER', 'couple');
$DB_PASS = envOr('TEST_DB_PASS', 'couplepass123');
$DB_CHARSET = 'utf8mb4';

$GLOBALS['passed'] = 0;
$GLOBALS['failed'] = 0;
$GLOBALS['failures'] = [];
$GLOBALS['report'] = [];

// ---------------------------------------------------------------------------
// 迷你测试框架
// ---------------------------------------------------------------------------
function out($line = '')
{
    echo $line . "\n";
    $GLOBALS['report'][] = $line;
}

function ok($cond, $label, $extra = '')
{
    if ($cond) {
        $GLOBALS['passed']++;
        out('  ✅ ' . $label);
    } else {
        $GLOBALS['failed']++;
        $GLOBALS['failures'][] = $label . ($extra !== '' ? '  →  ' . $extra : '');
        out('  ❌ ' . $label . ($extra !== '' ? "\n       " . $extra : ''));
    }
    return (bool) $cond;
}

function section($title)
{
    out('');
    out($title);
}

/** 按 a.b.c 取嵌套值（json 解析为关联数组） */
function dot($data, $path, $default = null)
{
    $cur = $data;
    foreach (explode('.', $path) as $key) {
        if (is_array($cur) && array_key_exists($key, $cur)) {
            $cur = $cur[$key];
            continue;
        }
        return $default;
    }
    return $cur;
}

// ---------------------------------------------------------------------------
// HTTP 客户端（cURL）
// ---------------------------------------------------------------------------
/**
 * @return array{status:int,json:mixed,text:string,headers:array,buf:string}
 */
function req($method, $path, $opts = [])
{
    global $BASE;
    $url = (strpos($path, 'http') === 0) ? $path : $BASE . $path;
    $headers = [];
    if (array_key_exists('body', $opts) && $opts['body'] !== null) {
        $headers[] = 'Content-Type: application/json';
    }
    if (!empty($opts['token'])) {
        $headers[] = 'Authorization: Bearer ' . $opts['token'];
    }
    if (!empty($opts['rawBody'])) {
        $headers[] = 'Content-Type: application/json';
    }

    $respHeaders = [];
    $ch = curl_init($url);
    $body = null;
    if (array_key_exists('body', $opts) && $opts['body'] !== null) {
        $body = json_encode($opts['body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } elseif (!empty($opts['rawBody'])) {
        $body = $opts['rawBody'];
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$respHeaders) {
            $p = strpos($line, ':');
            if ($p !== false) {
                $respHeaders[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
            }
            return strlen($line);
        },
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $text = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($text === false) {
        $text = '';
        $status = -1;
    }
    $json = null;
    if (isset($respHeaders['content-type']) && strpos($respHeaders['content-type'], 'application/json') !== false) {
        $json = json_decode($text, true);
    }
    return [
        'status' => $status,
        'json' => $json,
        'text' => $text,
        'headers' => $respHeaders,
        'buf' => $text,
        'curlError' => $err,
    ];
}

// ---------------------------------------------------------------------------
// 生成真实的最小 PNG（8x8，只用 PHP 内置 gzcompress，不依赖外部图片文件）
// ---------------------------------------------------------------------------
function pngChunk($type, $data)
{
    return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
}

function makePng($size = 8)
{
    $sig = "\x89PNG\r\n\x1a\n";
    // IHDR：宽、高、位深 8、颜色类型 6（RGBA）、压缩/滤波/隔行均为 0
    $ihdr = pack('N', $size) . pack('N', $size) . chr(8) . chr(6) . chr(0) . chr(0) . chr(0);
    // 原始像素：每行 1 字节滤波类型 + size 个 RGBA 像素
    $raw = '';
    for ($y = 0; $y < $size; $y++) {
        $raw .= chr(0);
        for ($x = 0; $x < $size; $x++) {
            $raw .= chr(($x * 32) & 0xff) . chr(($y * 32) & 0xff) . chr(0x99) . chr(0xff);
        }
    }
    return $sig . pngChunk('IHDR', $ihdr) . pngChunk('IDAT', gzcompress($raw, 9)) . pngChunk('IEND', '');
}

function isPng($buf)
{
    return strlen($buf) > 8
        && substr($buf, 0, 4) === "\x89PNG"
        && substr($buf, 12, 4) === 'IHDR'
        && substr($buf, -8, 4) === 'IEND';
}

// ---------------------------------------------------------------------------
// 极简 WebSocket 客户端（手写握手 + 帧编解码，用于测试 bin/ws-server.php）
// ---------------------------------------------------------------------------
class WsClient
{
    public $sock;
    private $buf = '';
    public $events = [];
    public $error = null;

    public static function connect($port, $token = null, $headerToken = null)
    {
        $client = new self();
        $errno = 0;
        $errstr = '';
        $sock = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $errstr, 5);
        if ($sock === false) {
            $client->error = 'TCP 连接失败：' . $errstr;
            return $client;
        }
        stream_set_timeout($sock, 5);
        $client->sock = $sock;

        $key = base64_encode(random_bytes(16));
        $target = '/ws';
        if ($token !== null) {
            $target .= '?token=' . rawurlencode($token);
        }
        $req = 'GET ' . $target . " HTTP/1.1\r\n"
            . 'Host: 127.0.0.1:' . $port . "\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . 'Sec-WebSocket-Key: ' . $key . "\r\n"
            . "Sec-WebSocket-Version: 13\r\n";
        if ($headerToken !== null) {
            $req .= 'Authorization: Bearer ' . $headerToken . "\r\n";
        }
        $req .= "\r\n";
        fwrite($sock, $req);

        $head = '';
        $deadline = microtime(true) + 5;
        while (strpos($head, "\r\n\r\n") === false && microtime(true) < $deadline) {
            $chunk = fread($sock, 1024);
            if ($chunk === false || $chunk === '') {
                if (feof($sock)) {
                    break;
                }
                usleep(10000);
                continue;
            }
            $head .= $chunk;
        }
        $pos = strpos($head, "\r\n\r\n");
        if ($pos === false) {
            $client->error = '握手无响应';
            return $client;
        }
        $client->statusLine = substr($head, 0, strpos($head, "\r\n"));
        $client->statusCode = (int) substr($client->statusLine, 9, 3);
        $client->buf = substr($head, $pos + 4);
        if ($client->statusCode !== 101) {
            $client->error = '握手失败：' . $client->statusLine;
            return $client;
        }
        // 校验 Sec-WebSocket-Accept
        if (preg_match('/sec-websocket-accept:\s*(\S+)/i', $head, $m)) {
            $expect = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
            if (trim($m[1]) !== $expect) {
                $client->error = 'Sec-WebSocket-Accept 校验失败';
            }
        } else {
            $client->error = '缺少 Sec-WebSocket-Accept';
        }
        return $client;
    }

    public $statusLine = '';
    public $statusCode = 0;

    /** 发送一帧（客户端 -> 服务端，必须掩码） */
    public function sendFrame($opcode, $payload = '')
    {
        $len = strlen($payload);
        $header = chr(0x80 | ($opcode & 0x0F));
        if ($len < 126) {
            $header .= chr(0x80 | $len);
        } elseif ($len <= 0xFFFF) {
            $header .= chr(0x80 | 126) . pack('n', $len);
        } else {
            $header .= chr(0x80 | 127) . pack('J', $len);
        }
        $mask = random_bytes(4);
        $masked = '';
        for ($i = 0; $i < $len; $i++) {
            $masked .= chr(ord($payload[$i]) ^ ord($mask[$i % 4]));
        }
        @fwrite($this->sock, $header . $mask . $masked);
    }

    public function sendJson($data)
    {
        $this->sendFrame(0x1, json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    /** 读取一帧；超时返回 null */
    public function readFrame($timeoutMs = 2000)
    {
        $deadline = microtime(true) + $timeoutMs / 1000;
        while (true) {
            $frame = $this->tryParse();
            if ($frame !== null) {
                return $frame;
            }
            $left = $deadline - microtime(true);
            if ($left <= 0) {
                return null;
            }
            $read = [$this->sock];
            $write = null;
            $except = null;
            $sec = (int) floor($left);
            $usec = (int) (($left - $sec) * 1000000);
            $n = @stream_select($read, $write, $except, $sec, $usec);
            if ($n === false || $n === 0) {
                continue;
            }
            $chunk = @fread($this->sock, 8192);
            if ($chunk === false || $chunk === '') {
                if (feof($this->sock)) {
                    return null;
                }
                usleep(5000);
                continue;
            }
            $this->buf .= $chunk;
        }
    }

    private function tryParse()
    {
        $buf = $this->buf;
        $len = strlen($buf);
        if ($len < 2) {
            return null;
        }
        $b0 = ord($buf[0]);
        $b1 = ord($buf[1]);
        $opcode = $b0 & 0x0F;
        $masked = ($b1 & 0x80) !== 0; // 服务端 -> 客户端不应掩码
        $payloadLen = $b1 & 0x7F;
        $offset = 2;
        if ($payloadLen === 126) {
            if ($len < 4) {
                return null;
            }
            $u = unpack('n', substr($buf, 2, 2));
            $payloadLen = $u[1];
            $offset = 4;
        } elseif ($payloadLen === 127) {
            if ($len < 10) {
                return null;
            }
            $u = unpack('J', substr($buf, 2, 8));
            $payloadLen = $u[1];
            $offset = 10;
        }
        if ($masked) {
            $offset += 4;
        }
        if ($len < $offset + $payloadLen) {
            return null;
        }
        $payload = substr($buf, $offset, $payloadLen);
        $this->buf = substr($buf, $offset + $payloadLen);
        return ['opcode' => $opcode, 'payload' => $payload, 'masked' => $masked];
    }

    /** 读取下一个 JSON 事件（自动回复服务端 ping） */
    public function nextJson($timeoutMs = 2000)
    {
        $deadline = microtime(true) + $timeoutMs / 1000;
        while (true) {
            $left = (int) round(($deadline - microtime(true)) * 1000);
            if ($left <= 0) {
                return null;
            }
            $frame = $this->readFrame($left);
            if ($frame === null) {
                return null;
            }
            if ($frame['opcode'] === 0x9) { // 服务端 ping
                $this->sendFrame(0xA, $frame['payload']);
                continue;
            }
            if ($frame['opcode'] === 0x8) { // close
                return null;
            }
            if ($frame['opcode'] !== 0x1 && $frame['opcode'] !== 0x2) {
                continue;
            }
            $json = json_decode($frame['payload'], true);
            if (is_array($json)) {
                $this->events[] = $json;
                return $json;
            }
        }
    }

    /**
     * 等待匹配的事件（先查已收到的）
     * @param callable $match
     */
    public function waitFor($match, $timeoutMs = 4000)
    {
        foreach ($this->events as $e) {
            if ($match($e)) {
                return $e;
            }
        }
        $deadline = microtime(true) + $timeoutMs / 1000;
        while (microtime(true) < $deadline) {
            $left = (int) round(($deadline - microtime(true)) * 1000);
            if ($left <= 0) {
                break;
            }
            $event = $this->nextJson($left);
            if ($event === null) {
                break;
            }
            if ($match($event)) {
                return $event;
            }
        }
        return null;
    }

    public function close()
    {
        if (is_resource($this->sock)) {
            $this->sendFrame(0x8, pack('n', 1000));
            @fclose($this->sock);
        }
    }
}

// ---------------------------------------------------------------------------
// 数据库准备 / 清理（MySQL / MariaDB）
// ---------------------------------------------------------------------------
function testDbConnect($withDb = false)
{
    global $DB_HOST, $DB_PORT, $DB_NAME, $DB_USER, $DB_PASS, $DB_CHARSET;
    $dsn = 'mysql:host=' . $DB_HOST . ';port=' . $DB_PORT . ($withDb ? ';dbname=' . $DB_NAME : '') . ';charset=' . $DB_CHARSET;
    return new PDO($dsn, $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

/** 从零开始：DROP + CREATE 测试库 */
function recreateTestDatabase()
{
    global $DB_NAME;
    $pdo = testDbConnect(false);
    $pdo->exec('DROP DATABASE IF EXISTS `' . $DB_NAME . '`');
    $pdo->exec('CREATE DATABASE `' . $DB_NAME . '` CHARACTER SET utf8mb4');
    $pdo->exec('USE `' . $DB_NAME . '`');
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    return count($tables) === 0;
}

/** 删除测试库（测试结束清理） */
function dropTestDatabase()
{
    global $DB_NAME;
    try {
        $pdo = testDbConnect(false);
        $pdo->exec('DROP DATABASE IF EXISTS `' . $DB_NAME . '`');
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

// ---------------------------------------------------------------------------
// 进程管理（stdio 重定向到文件，避免管道在受限环境下不可用）
// ---------------------------------------------------------------------------
function childEnv(array $overrides)
{
    $env = getenv();
    if (!is_array($env)) {
        $env = [];
    }
    foreach ($overrides as $k => $v) {
        $env[$k] = (string) $v;
    }
    return $env;
}

function spawnProcess(array $cmd, array $env, $logFile, $workdir)
{
    $devNull = (stripos(PHP_OS, 'WIN') === 0) ? 'NUL' : '/dev/null';
    @file_put_contents($logFile, '');
    $desc = [
        0 => ['file', $devNull, 'r'],
        1 => ['file', $logFile, 'a'],
        2 => ['file', $logFile, 'a'],
    ];
    $pipes = [];
    $proc = proc_open($cmd, $desc, $pipes, $workdir, $env);
    if (!is_resource($proc)) {
        return null;
    }
    return $proc;
}

function stopProcess($proc)
{
    if (!is_resource($proc)) {
        return;
    }
    $status = proc_get_status($proc);
    if ($status['running']) {
        proc_terminate($proc);
        usleep(400000);
        $status = proc_get_status($proc);
        if ($status['running']) {
            if (stripos(PHP_OS, 'WIN') === 0) {
                @exec('taskkill /F /T /PID ' . (int) $status['pid'] . ' 2>&1');
            } else {
                proc_terminate($proc, 9);
            }
            usleep(200000);
        }
    }
    @proc_close($proc);
}

function waitForHttp($url, $timeoutSeconds = 30)
{
    $deadline = microtime(true) + $timeoutSeconds;
    while (microtime(true) < $deadline) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($status === 200 && $body !== false) {
            return true;
        }
        usleep(200000);
    }
    return false;
}

function waitForTcp($port, $timeoutSeconds = 20)
{
    $deadline = microtime(true) + $timeoutSeconds;
    while (microtime(true) < $deadline) {
        $sock = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $errstr, 1);
        if ($sock !== false) {
            @fclose($sock);
            return true;
        }
        usleep(200000);
    }
    return false;
}

function tailFile($file, $lines = 25)
{
    if (!is_file($file)) {
        return '';
    }
    $content = (string) @file_get_contents($file);
    if ($content === '') {
        return '';
    }
    $all = explode("\n", rtrim($content, "\n"));
    return implode("\n", array_slice($all, -$lines));
}

function removeDir($dir)
{
    if (!is_dir($dir)) {
        return;
    }
    $items = @scandir($dir);
    if (!is_array($items)) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . '/' . $item;
        if (is_dir($path)) {
            removeDir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

// ---------------------------------------------------------------------------
// 主流程
// ---------------------------------------------------------------------------
out('========================================');
out('  情侣 App 服务端 · PHP 版端到端冒烟测试');
out('========================================');
out('PHP ' . PHP_VERSION . ' · ' . PHP_OS . ' · ' . php_uname('m'));
out('测试端口 ' . $PORT . '（REST） · ' . $WS_PORT . '（WebSocket） · 数据目录 ' . $DATA_DIR);
out('数据库 ' . $DB_HOST . ':' . $DB_PORT . ' / ' . $DB_NAME . '（' . $DB_USER . '）');

// 从干净状态开始
removeDir($DATA_DIR_ABS);
@unlink($REST_LOG);
@unlink($WS_LOG);
try {
    $fresh = recreateTestDatabase();
    out('测试数据库已重建： ' . $DB_NAME . '（空库：' . ($fresh ? '是' : '否') . '）');
} catch (PDOException $e) {
    out('❌ 无法重建测试数据库 ' . $DB_NAME . '：' . $e->getMessage());
    out('   请确认 MySQL/MariaDB 正在运行，且 ' . $DB_USER . '@' . $DB_HOST . ':' . $DB_PORT . ' 可用。');
    @file_put_contents($REPORT_FILE, implode("\n", $GLOBALS['report']) . "\n");
    exit(1);
}

$envOverrides = [
    'JWT_SECRET' => $JWT_SECRET,
    'ADMIN_PASSWORD' => $ADMIN_PASSWORD,
    'ADMIN_JWT_SECRET' => $ADMIN_JWT_SECRET,
    'DATA_DIR' => $DATA_DIR,
    'PORT' => (string) $PORT,
    'WS_PORT' => (string) $WS_PORT,
    'WS_HOST' => '127.0.0.1',
    'TRUST_PROXY' => '0',
    'PUBLIC_BASE_URL' => '',
    'LOG_LEVEL' => 'info',
    'TIMEZONE' => 'Asia/Shanghai',
    'DEEPSEEK_API_KEY' => '',
    'COUPLE_CONFIG_FILE' => 'none',
    'DB_HOST' => $DB_HOST,
    'DB_PORT' => (string) $DB_PORT,
    'DB_NAME' => $DB_NAME,
    'DB_USER' => $DB_USER,
    'DB_PASS' => $DB_PASS,
    'DB_CHARSET' => $DB_CHARSET,
];
$env = childEnv($envOverrides);

$restProc = spawnProcess(
    [PHP_BINARY, '-S', '127.0.0.1:' . $PORT, '-t', $ROOT . '/public'],
    $env,
    $REST_LOG,
    $ROOT
);
if ($restProc === null) {
    out('❌ 无法启动 REST 服务进程');
    @file_put_contents($REPORT_FILE, implode("\n", $GLOBALS['report']) . "\n");
    exit(1);
}
if (!waitForHttp($BASE . '/api/health', 30)) {
    out('❌ 等待 REST 服务启动超时');
    out(tailFile($REST_LOG, 30));
    stopProcess($restProc);
    @file_put_contents($REPORT_FILE, implode("\n", $GLOBALS['report']) . "\n");
    exit(1);
}
out('REST 服务已启动，开始断言…');

$wsProc = null;
$wsClients = [];
$exitCode = 1;

// 异常退出（超时/致命错误）也要尽量清掉测试库，避免留下脏数据
register_shutdown_function(function () {
    global $DB_NAME;
    try {
        $pdo = testDbConnect(false);
        $pdo->exec('DROP DATABASE IF EXISTS `' . $DB_NAME . '`');
    } catch (PDOException $e) {
        // 数据库已不可用，忽略
    }
});

try {
    // =====================================================================
    section('1. 公开接口 /api/health、/api/meta');
    // =====================================================================
    {
        $r = req('GET', '/api/health');
        ok($r['status'] === 200, 'GET /api/health 返回 200', '实际 ' . $r['status']);
        ok(dot($r['json'], 'ok') === true, 'health.ok 为 true');
        ok(dot($r['json'], 'name') === 'couple-server', 'health.name 为 "couple-server"', (string) dot($r['json'], 'name'));
        ok(dot($r['json'], 'version') === '1.0.0', 'health.version 为 "1.0.0"', (string) dot($r['json'], 'version'));
        ok(is_int(dot($r['json'], 'time')), 'health.time 是毫秒时间戳');

        $m = req('GET', '/api/meta');
        ok($m['status'] === 200, 'GET /api/meta 返回 200', '实际 ' . $m['status']);
        ok(dot($m['json'], 'appName') === '情侣空间', 'meta.appName 为 "情侣空间"', (string) dot($m['json'], 'appName'));
        ok(dot($m['json'], 'registerOpen') === true, 'meta.registerOpen 默认 true');
        ok(is_int(dot($m['json'], 'minAppBuild')), 'meta.minAppBuild 是数字');
        ok(is_int(dot($m['json'], 'latestAppBuild')), 'meta.latestAppBuild 是数字');
        ok(is_string(dot($m['json'], 'announcement')), 'meta.announcement 是字符串');
        ok(is_string(dot($m['json'], 'updateURL')), 'meta.updateURL 是字符串');
        ok(dot($m['json'], 'features.chat') === true, 'meta.features.chat 为 true');
        ok(dot($m['json'], 'features.emoji') === true, 'meta.features.emoji 为 true');
        ok(dot($m['json'], 'features.ai') === true, 'meta.features.ai 为 true');
        ok(dot($m['json'], 'features.sync') === true, 'meta.features.sync 为 true');
        ok(is_int(dot($m['json'], 'serverTime')), 'meta.serverTime 是数字');

        $s = req('GET', '/api/status');
        ok($s['status'] === 200 && dot($s['json'], 'dbOk') === true, 'GET /api/status 返回 200 且数据库正常');

        // 无伪静态兜底：/index.php/api/... 必须与伪静态 /api/... 等价
        $fallbackHealth = req('GET', '/index.php/api/health');
        ok($fallbackHealth['status'] === 200 && dot($fallbackHealth['json'], 'ok') === true,
            '兜底形式 /index.php/api/health 返回 200', '实际 ' . $fallbackHealth['status']);
        $fallbackMeta = req('GET', '/index.php/api/meta');
        ok($fallbackMeta['status'] === 200 && dot($fallbackMeta['json'], 'appName') === '情侣空间',
            '兜底形式 /index.php/api/meta 返回 200', '实际 ' . $fallbackMeta['status']);
        $fallback404 = req('GET', '/index.php/api/definitely-not-exist');
        ok($fallback404['status'] === 404 && dot($fallback404['json'], 'error') === 'not_found',
            '兜底形式下的未知路径返回 404 not_found', '实际 ' . $fallback404['status']);
        $rootPage = req('GET', '/');
        ok($rootPage['status'] === 200 && strpos($rootPage['buf'], '情侣空间') !== false,
            'GET / 返回中文说明页', '实际 ' . $rootPage['status']);
    }

    // =====================================================================
    section('2. 注册两个用户 → 建情侣空间 → 邀请码加入');
    // =====================================================================
    $userA = null;
    $userB = null;
    $tokenA = null;
    $tokenB = null;
    $coupleId = null;
    $inviteCode = null;
    {
        $suffix = bin2hex(random_bytes(3));
        $rA = req('POST', '/api/auth/register', ['body' => [
            'username' => 'alice_' . $suffix, 'password' => 'pass1234',
            'nickname' => '小爱', 'avatarEmoji' => '🐻',
        ]]);
        ok($rA['status'] === 200, '用户 A 注册返回 200', '实际 ' . $rA['status'] . ' ' . $rA['text']);
        ok(is_string(dot($rA['json'], 'token')) && strlen((string) dot($rA['json'], 'token')) > 20, '注册响应含 token');
        ok(dot($rA['json'], 'user.username') === 'alice_' . $suffix, '注册响应 user.username 正确');
        ok(dot($rA['json'], 'user.nickname') === '小爱', '注册响应 user.nickname 正确');
        ok(dot($rA['json'], 'user.avatarEmoji') === '🐻', '注册响应 user.avatarEmoji 正确');
        ok(dot($rA['json'], 'user.coupleId') === null, '未绑定时 user.coupleId 为 null');
        ok(dot($rA['json'], 'couple') === null, '未绑定时 couple 为 null');
        ok(dot($rA['json'], 'user.role') === 'user', 'user.role 为 "user"');
        ok(dot($rA['json'], 'user.disabled') === false, 'user.disabled 为 false');
        ok(is_int(dot($rA['json'], 'user.createdAt')), 'user.createdAt 是数字');
        ok(strpos((string) dot($rA['json'], 'user.id'), 'u_') === 0, 'user.id 使用 u_ 前缀', (string) dot($rA['json'], 'user.id'));
        ok(is_int(dot($rA['json'], 'serverTime')), '注册响应含 serverTime');
        $userA = dot($rA['json'], 'user');
        $tokenA = dot($rA['json'], 'token');

        $rB = req('POST', '/api/auth/register', ['body' => [
            'username' => 'bob_' . $suffix, 'password' => 'pass1234',
            'nickname' => '小博', 'avatarEmoji' => '🐰',
        ]]);
        ok($rB['status'] === 200, '用户 B 注册返回 200', '实际 ' . $rB['status']);
        $userB = dot($rB['json'], 'user');
        $tokenB = dot($rB['json'], 'token');

        // 重复用户名 -> 409 conflict
        $dup = req('POST', '/api/auth/register', ['body' => ['username' => 'alice_' . $suffix, 'password' => 'pass1234']]);
        ok($dup['status'] === 409 && dot($dup['json'], 'error') === 'conflict', '重复用户名注册返回 409 conflict',
            '实际 ' . $dup['status'] . ' ' . (string) dot($dup['json'], 'error'));

        // 非法用户名 -> 400
        $bad = req('POST', '/api/auth/register', ['body' => ['username' => 'ab', 'password' => 'pass1234']]);
        ok($bad['status'] === 400 && dot($bad['json'], 'error') === 'invalid_input', '过短用户名返回 400 invalid_input',
            '实际 ' . $bad['status']);

        // 密码过短 -> 400
        $badPwd = req('POST', '/api/auth/register', ['body' => ['username' => 'shortpwd_' . $suffix, 'password' => '123']]);
        ok($badPwd['status'] === 400, '过短密码返回 400', '实际 ' . $badPwd['status']);

        // A 创建情侣空间
        $rc = req('POST', '/api/couple/create', ['token' => $tokenA]);
        ok($rc['status'] === 200, 'A 创建情侣空间返回 200', '实际 ' . $rc['status'] . ' ' . $rc['text']);
        $coupleId = dot($rc['json'], 'couple.id');
        $inviteCode = dot($rc['json'], 'couple.inviteCode');
        ok(is_string($coupleId) && strpos($coupleId, 'c_') === 0, 'couple.id 使用 c_ 前缀', (string) $coupleId);
        ok(is_string($inviteCode) && strlen($inviteCode) === 6, 'inviteCode 为 6 位', (string) $inviteCode);
        ok(dot($rc['json'], 'couple.anniversaryTitle') === '在一起', 'anniversaryTitle 默认 "在一起"');
        ok(dot($rc['json'], 'couple.togetherDate') === null, 'togetherDate 默认 null');
        ok(is_array(dot($rc['json'], 'couple.members')) && count(dot($rc['json'], 'couple.members')) === 1,
            '创建后 members 含 1 人');
        ok(is_int(dot($rc['json'], 'couple.createdAt')) && is_int(dot($rc['json'], 'couple.updatedAt')),
            'couple 含 createdAt / updatedAt 数字');

        // 已有空间再创建 -> 409
        $again = req('POST', '/api/couple/create', ['token' => $tokenA]);
        ok($again['status'] === 409 && dot($again['json'], 'error') === 'conflict', '已有空间再次创建返回 409 conflict',
            '实际 ' . $again['status']);

        // 无效邀请码 -> 404
        $badCode = req('POST', '/api/couple/join', ['token' => $tokenB, 'body' => ['inviteCode' => 'ZZZZZZ']]);
        ok($badCode['status'] === 404 && dot($badCode['json'], 'error') === 'not_found', '无效邀请码返回 404 not_found',
            '实际 ' . $badCode['status']);

        // B 用邀请码加入
        $rj = req('POST', '/api/couple/join', ['token' => $tokenB, 'body' => ['inviteCode' => $inviteCode]]);
        ok($rj['status'] === 200, 'B 用邀请码加入返回 200', '实际 ' . $rj['status'] . ' ' . $rj['text']);
        ok(dot($rj['json'], 'couple.id') === $coupleId, 'B 加入的是同一个情侣空间');

        // GET /api/couple 应能看到 2 个成员
        $rget = req('GET', '/api/couple', ['token' => $tokenB]);
        ok($rget['status'] === 200, 'GET /api/couple 返回 200');
        ok(count((array) dot($rget['json'], 'couple.members', [])) === 2, 'GET /api/couple 能看到 2 个成员',
            '实际 ' . count((array) dot($rget['json'], 'couple.members', [])));
        $ids = [];
        foreach ((array) dot($rget['json'], 'couple.members', []) as $m) {
            $ids[] = dot($m, 'id');
        }
        sort($ids);
        ok(count($ids) === 2 && in_array($userA['id'], $ids, true) && in_array($userB['id'], $ids, true),
            'members 含双方完整 user 对象');
        $memberOk = true;
        foreach ((array) dot($rget['json'], 'couple.members', []) as $m) {
            if (!array_key_exists('username', $m) || !array_key_exists('nickname', $m) || !array_key_exists('coupleId', $m)) {
                $memberOk = false;
            }
        }
        ok($memberOk, 'members 中每个 user 含 username/nickname/coupleId');

        // 空间已满 -> 第三个用户加入应 409
        $rC = req('POST', '/api/auth/register', ['body' => ['username' => 'carol_' . $suffix, 'password' => 'pass1234']]);
        $full = req('POST', '/api/couple/join', ['token' => dot($rC['json'], 'token'), 'body' => ['inviteCode' => $inviteCode]]);
        ok($full['status'] === 409 && dot($full['json'], 'error') === 'conflict', '空间已满时加入返回 409 conflict',
            '实际 ' . $full['status']);

        // 已绑定用户再加入 -> 409
        $rejoin = req('POST', '/api/couple/join', ['token' => $tokenB, 'body' => ['inviteCode' => $inviteCode]]);
        ok($rejoin['status'] === 409, '已绑定用户再次加入返回 409', '实际 ' . $rejoin['status']);

        // 未绑定用户 GET /api/couple -> 403
        $nobody = req('GET', '/api/couple', ['token' => dot($rC['json'], 'token')]);
        ok($nobody['status'] === 403 && dot($nobody['json'], 'error') === 'forbidden',
            '未绑定用户 GET /api/couple 返回 403 forbidden', '实际 ' . $nobody['status']);
    }

    // =====================================================================
    section('3. 同步接口 /api/sync/push 与 /api/sync/pull');
    // =====================================================================
    {
        $now = (int) round(microtime(true) * 1000);
        $wishId = 'w_' . $now;
        $push = req('POST', '/api/sync/push', ['token' => $tokenA, 'body' => ['objects' => [[
            'kind' => 'wish', 'id' => $wishId, 'updatedAt' => $now, 'deleted' => false,
            'payload' => ['title' => '去海边', 'targetCoins' => 300],
        ]]]]);
        ok($push['status'] === 200, 'A 推送 wish 返回 200', '实际 ' . $push['status'] . ' ' . $push['text']);
        ok(is_array(dot($push['json'], 'applied')) && in_array($wishId, (array) dot($push['json'], 'applied'), true),
            'applied 含刚推送的对象 id');
        ok(dot($push['json'], 'conflicts') === [], 'conflicts 为空');

        $pull = req('GET', '/api/sync/pull?since=0', ['token' => $tokenB]);
        ok($pull['status'] === 200, 'B 拉取同步数据返回 200');
        $got = null;
        foreach ((array) dot($pull['json'], 'objects', []) as $o) {
            if (dot($o, 'id') === $wishId) {
                $got = $o;
            }
        }
        ok($got !== null, 'B 拉到了 A 推送的 wish');
        ok(dot($got, 'kind') === 'wish', '拉到的对象 kind 为 wish');
        ok(dot($got, 'payload.title') === '去海边' && dot($got, 'payload.targetCoins') === 300, 'payload 原样保留');
        ok(dot($got, 'ownerId') === $userA['id'], 'ownerId 为 A 的 id');
        ok(dot($got, 'updatedBy') === $userA['id'], 'updatedBy 为 A 的 id');
        ok(dot($got, 'deleted') === false, 'deleted 为 false');
        ok(is_bool(dot($pull['json'], 'hasMore')), '响应含 hasMore 布尔值');
        ok(is_int(dot($pull['json'], 'serverTime')), '响应含 serverTime');

        // ledger / vault 必须被拒绝（400 invalid_input）
        $ledger = req('POST', '/api/sync/push', ['token' => $tokenA, 'body' => ['objects' => [[
            'kind' => 'ledger', 'id' => 'l_1', 'updatedAt' => $now, 'payload' => ['amount' => 10],
        ]]]]);
        ok($ledger['status'] === 400, '推送 kind=ledger 被拒绝（400）', '实际 ' . $ledger['status']);
        ok(dot($ledger['json'], 'error') === 'invalid_input', 'ledger 拒绝时 error 为 invalid_input');
        ok(mb_strpos((string) dot($ledger['json'], 'message'), '本地') !== false,
            'ledger 拒绝消息说明了「只存手机本地」', (string) dot($ledger['json'], 'message'));

        $vault = req('POST', '/api/sync/push', ['token' => $tokenA, 'body' => ['objects' => [[
            'kind' => 'vault', 'id' => 'v_1', 'updatedAt' => $now, 'payload' => ['secret' => 'x'],
        ]]]]);
        ok($vault['status'] === 400 && dot($vault['json'], 'error') === 'invalid_input', '推送 kind=vault 被拒绝（400）',
            '实际 ' . $vault['status']);

        $other = req('POST', '/api/sync/push', ['token' => $tokenA, 'body' => ['objects' => [[
            'kind' => 'unknown_kind', 'id' => 'x_1', 'updatedAt' => $now,
        ]]]]);
        ok($other['status'] === 400, '推送非白名单 kind 被拒绝（400）', '实际 ' . $other['status']);

        // 超过 200 个对象 -> 400
        $bulk = [];
        for ($i = 0; $i < 201; $i++) {
            $bulk[] = ['kind' => 'wish', 'id' => 'w_bulk_' . $i, 'updatedAt' => $now, 'payload' => null];
        }
        $tooMany = req('POST', '/api/sync/push', ['token' => $tokenA, 'body' => ['objects' => $bulk]]);
        ok($tooMany['status'] === 400, '单次推送 201 个对象被拒绝（400）', '实际 ' . $tooMany['status']);

        // objects 为空数组 / 非数组
        $empty = req('POST', '/api/sync/push', ['token' => $tokenA, 'body' => ['objects' => []]]);
        ok($empty['status'] === 400, 'objects 为空数组被拒绝（400）', '实际 ' . $empty['status']);
        $notArray = req('POST', '/api/sync/push', ['token' => $tokenA, 'body' => ['objects' => 'nope']]);
        ok($notArray['status'] === 400, 'objects 非数组被拒绝（400）', '实际 ' . $notArray['status']);

        // LWW：用更旧的时间戳推送同一对象 -> 冲突
        $lwwOld = req('POST', '/api/sync/push', ['token' => $tokenB, 'body' => ['objects' => [[
            'kind' => 'wish', 'id' => $wishId, 'updatedAt' => $now - 60000, 'payload' => ['title' => '旧数据'],
        ]]]]);
        ok($lwwOld['status'] === 200, 'LWW 旧数据推送请求本身返回 200');
        ok(in_array($wishId, (array) dot($lwwOld['json'], 'conflicts', []), true),
            'LWW：更旧的 updatedAt 被列为 conflict', $lwwOld['text']);

        // LWW：用更新的时间戳推送同一对象 -> 接受
        $lwwNew = req('POST', '/api/sync/push', ['token' => $tokenB, 'body' => ['objects' => [[
            'kind' => 'wish', 'id' => $wishId, 'updatedAt' => $now + 1000, 'payload' => ['title' => '新数据'],
        ]]]]);
        ok(in_array($wishId, (array) dot($lwwNew['json'], 'applied', []), true), 'LWW：更新的 updatedAt 被接受');

        // 墓碑删除
        $del = req('POST', '/api/sync/push', ['token' => $tokenA, 'body' => ['objects' => [[
            'kind' => 'pet', 'id' => 'pet', 'updatedAt' => $now + 2000, 'deleted' => true, 'payload' => null,
        ]]]]);
        ok(in_array('pet', (array) dot($del['json'], 'applied', []), true), 'deleted=true 的墓碑对象被接受');
        $pullDel = req('GET', '/api/sync/pull?since=0&kinds=pet', ['token' => $tokenB]);
        $petObj = null;
        foreach ((array) dot($pullDel['json'], 'objects', []) as $o) {
            if (dot($o, 'id') === 'pet') {
                $petObj = $o;
            }
        }
        ok(dot($petObj, 'deleted') === true, '拉到的墓碑对象 deleted 为 true');

        // kinds 过滤
        $filtered = req('GET', '/api/sync/pull?since=0&kinds=pet', ['token' => $tokenB]);
        ok($filtered['status'] === 200, 'kinds=pet 过滤拉取返回 200');
        $onlyPet = true;
        foreach ((array) dot($filtered['json'], 'objects', []) as $o) {
            if (dot($o, 'kind') !== 'pet') {
                $onlyPet = false;
            }
        }
        ok($onlyPet, 'kinds 过滤生效（只返回 pet）');

        // kinds=ledger -> 400
        $kindsLedger = req('GET', '/api/sync/pull?since=0&kinds=ledger', ['token' => $tokenB]);
        ok($kindsLedger['status'] === 400, 'kinds=ledger 被拒绝（400）', '实际 ' . $kindsLedger['status']);
        $kindsBad = req('GET', '/api/sync/pull?since=0&kinds=foo', ['token' => $tokenB]);
        ok($kindsBad['status'] === 400, 'kinds 含未知取值被拒绝（400）', '实际 ' . $kindsBad['status']);

        // limit 上限 1000
        $overLimit = req('GET', '/api/sync/pull?since=0&limit=5000', ['token' => $tokenB]);
        ok($overLimit['status'] === 400, 'limit 超过 1000 被拒绝（400）', '实际 ' . $overLimit['status']);
        $limitOk = req('GET', '/api/sync/pull?since=0&limit=1000', ['token' => $tokenB]);
        ok($limitOk['status'] === 200, 'limit=1000 被接受');

        // 未绑定用户不能读写同步数据
        $solo = req('POST', '/api/auth/register', ['body' => ['username' => 'solo_' . bin2hex(random_bytes(3)), 'password' => 'pass1234']]);
        $soloPush = req('POST', '/api/sync/push', ['token' => dot($solo['json'], 'token'), 'body' => ['objects' => [[
            'kind' => 'wish', 'id' => 'w_solo', 'updatedAt' => $now, 'payload' => null,
        ]]]]);
        ok($soloPush['status'] === 403 && dot($soloPush['json'], 'error') === 'forbidden',
            '未绑定情侣的用户推送同步数据返回 403 forbidden', '实际 ' . $soloPush['status']);
        $soloPull = req('GET', '/api/sync/pull?since=0', ['token' => dot($solo['json'], 'token')]);
        ok($soloPull['status'] === 403, '未绑定情侣的用户拉取同步数据返回 403', '实际 ' . $soloPull['status']);
    }

    // =====================================================================
    section('4. 聊天 /api/chat/*');
    // =====================================================================
    $msgId = null;
    $clientId = 'c_' . (int) round(microtime(true) * 1000);
    {
        $send = req('POST', '/api/chat/messages', ['token' => $tokenA, 'body' => [
            'clientId' => $clientId, 'kind' => 'text', 'body' => '晚安', 'meta' => null,
        ]]);
        ok($send['status'] === 200, 'A 发送消息返回 200', '实际 ' . $send['status'] . ' ' . $send['text']);
        $msg = dot($send['json'], 'message');
        $msgId = dot($msg, 'id');
        ok(is_string($msgId) && strpos($msgId, 'm_') === 0, 'message.id 使用 m_ 前缀', (string) $msgId);
        ok(dot($msg, 'clientId') === $clientId, 'message.clientId 原样返回');
        ok(dot($msg, 'coupleId') === $coupleId, 'message.coupleId 正确');
        ok(dot($msg, 'senderId') === $userA['id'], 'message.senderId 为 A');
        ok(dot($msg, 'kind') === 'text', 'message.kind 为 text');
        ok(dot($msg, 'body') === '晚安', 'message.body 为 "晚安"');
        ok(dot($msg, 'meta') === null, 'message.meta 为 null');
        ok(is_int(dot($msg, 'createdAt')), 'message.createdAt 是数字');
        ok(dot($msg, 'readAt') === null, 'message.readAt 初始为 null');

        // clientId 幂等
        $resend = req('POST', '/api/chat/messages', ['token' => $tokenA, 'body' => [
            'clientId' => $clientId, 'kind' => 'text', 'body' => '晚安', 'meta' => null,
        ]]);
        ok($resend['status'] === 200, '重发同一 clientId 返回 200');
        ok(dot($resend['json'], 'message.id') === $msgId, '重发返回的是同一条消息（幂等）',
            (string) dot($resend['json'], 'message.id') . ' vs ' . $msgId);

        $list = req('GET', '/api/chat/messages?limit=50', ['token' => $tokenB]);
        ok($list['status'] === 200, 'B 拉取消息返回 200');
        $found = 0;
        foreach ((array) dot($list['json'], 'messages', []) as $m) {
            if (dot($m, 'clientId') === $clientId) {
                $found++;
            }
        }
        ok($found === 1, 'clientId 幂等：列表中只有一条该消息', '实际 ' . $found . ' 条');
        $hasMsg = false;
        foreach ((array) dot($list['json'], 'messages', []) as $m) {
            if (dot($m, 'id') === $msgId) {
                $hasMsg = true;
            }
        }
        ok($hasMsg, 'B 能拉到 A 发的消息');
        ok(is_bool(dot($list['json'], 'hasMore')), '消息列表含 hasMore');

        // 升序校验
        $times = [];
        foreach ((array) dot($list['json'], 'messages', []) as $m) {
            $times[] = dot($m, 'createdAt');
        }
        $sorted = $times;
        sort($sorted);
        ok($times === $sorted, '消息按 createdAt 升序返回', json_encode($times));

        // body 超长 -> 400
        $tooLong = req('POST', '/api/chat/messages', ['token' => $tokenA, 'body' => [
            'clientId' => 'c_long_' . microtime(true), 'kind' => 'text', 'body' => str_repeat('x', 2001),
        ]]);
        ok($tooLong['status'] === 400, 'body 超过 2000 字符被拒绝（400）', '实际 ' . $tooLong['status']);

        // kind 非法 / system 不可发 / text 空内容
        $badKind = req('POST', '/api/chat/messages', ['token' => $tokenA, 'body' => [
            'clientId' => 'c_badkind_' . microtime(true), 'kind' => 'video', 'body' => 'x',
        ]]);
        ok($badKind['status'] === 400, '非法 kind 被拒绝（400）', '实际 ' . $badKind['status']);
        $systemKind = req('POST', '/api/chat/messages', ['token' => $tokenA, 'body' => [
            'clientId' => 'c_sys_' . microtime(true), 'kind' => 'system', 'body' => 'x',
        ]]);
        ok($systemKind['status'] === 400, '客户端发送 system 消息被拒绝（400）', '实际 ' . $systemKind['status']);
        $emptyText = req('POST', '/api/chat/messages', ['token' => $tokenA, 'body' => [
            'clientId' => 'c_empty_' . microtime(true), 'kind' => 'text', 'body' => '   ',
        ]]);
        ok($emptyText['status'] === 400, 'text 类型空 body 被拒绝（400）', '实际 ' . $emptyText['status']);
        $badEmojiBody = req('POST', '/api/chat/messages', ['token' => $tokenA, 'body' => [
            'clientId' => 'c_eb_' . microtime(true), 'kind' => 'emoji', 'body' => '不是 item: 前缀',
        ]]);
        ok($badEmojiBody['status'] === 400, 'emoji 类型 body 格式错误被拒绝（400）', '实际 ' . $badEmojiBody['status']);

        // 未绑定用户发消息 -> 403
        $solo = req('POST', '/api/auth/register', ['body' => ['username' => 'solo2_' . bin2hex(random_bytes(3)), 'password' => 'pass1234']]);
        $soloMsg = req('POST', '/api/chat/messages', ['token' => dot($solo['json'], 'token'), 'body' => [
            'clientId' => 'c_solo', 'kind' => 'text', 'body' => 'hi',
        ]]);
        ok($soloMsg['status'] === 403 && dot($soloMsg['json'], 'error') === 'forbidden',
            '未绑定情侣的用户发消息返回 403 forbidden', '实际 ' . $soloMsg['status']);
        $soloRead = req('POST', '/api/chat/read', ['token' => dot($solo['json'], 'token'), 'body' => ['upTo' => (int) round(microtime(true) * 1000)]]);
        ok($soloRead['status'] === 403, '未绑定情侣的用户上报已读返回 403', '实际 ' . $soloRead['status']);

        // 已读回执
        $readAt = (int) round(microtime(true) * 1000) + 5000;
        $read = req('POST', '/api/chat/read', ['token' => $tokenB, 'body' => ['upTo' => $readAt]]);
        ok($read['status'] === 200, 'POST /api/chat/read 返回 200', '实际 ' . $read['status']);
        ok(dot($read['json'], 'ok') === true, 'read 响应 ok 为 true');
        ok(is_int(dot($read['json'], 'count')) && dot($read['json'], 'count') > 0,
            'read 响应 count 为正数（标记了对方消息）', (string) dot($read['json'], 'count'));

        $list2 = req('GET', '/api/chat/messages?limit=50', ['token' => $tokenA]);
        $readMarked = null;
        foreach ((array) dot($list2['json'], 'messages', []) as $m) {
            if (dot($m, 'id') === $msgId) {
                $readMarked = dot($m, 'readAt');
            }
        }
        ok(is_int($readMarked), '消息的 readAt 已被标记', var_export($readMarked, true));

        // upTo 缺失 -> 400
        $noUpTo = req('POST', '/api/chat/read', ['token' => $tokenA, 'body' => []]);
        ok($noUpTo['status'] === 400, '缺少 upTo 返回 400', '实际 ' . $noUpTo['status']);

        // before 分页
        $beforeRes = req('GET', '/api/chat/messages?before=' . ((int) round(microtime(true) * 1000) + 10000) . '&limit=1', ['token' => $tokenA]);
        ok($beforeRes['status'] === 200 && is_bool(dot($beforeRes['json'], 'hasMore')),
            'before+limit 分页正常返回', '实际 ' . $beforeRes['status']);

        // 撤回消息
        $recall = req('DELETE', '/api/chat/messages/' . $msgId, ['token' => $tokenA]);
        ok($recall['status'] === 200 && dot($recall['json'], 'ok') === true, 'A 撤回自己的消息返回 200',
            '实际 ' . $recall['status'] . ' ' . $recall['text']);
        $otherRecall = req('DELETE', '/api/chat/messages/' . $msgId, ['token' => $tokenB]);
        ok($otherRecall['status'] === 403, 'B 撤回 A 的消息被拒绝（403）', '实际 ' . $otherRecall['status']);
        $reCall = req('DELETE', '/api/chat/messages/' . $msgId, ['token' => $tokenA]);
        ok($reCall['status'] === 200, '重复撤回幂等返回 200', '实际 ' . $reCall['status']);
        $noMsg = req('DELETE', '/api/chat/messages/m_notexist', ['token' => $tokenA]);
        ok($noMsg['status'] === 404, '撤回不存在的消息返回 404', '实际 ' . $noMsg['status']);
    }

    // =====================================================================
    section('5. WebSocket 实时推送（bin/ws-server.php + 手写 WS 客户端）');
    // =====================================================================
    {
        $wsProc = spawnProcess([PHP_BINARY, $ROOT . '/bin/ws-server.php'], $env, $WS_LOG, $ROOT);
        ok($wsProc !== null, 'WebSocket 常驻进程已启动');
        ok(waitForTcp($WS_PORT, 20), 'WebSocket 服务监听 ' . $WS_PORT . ' 端口成功');

        // 先连接 B
        $clientB = WsClient::connect($WS_PORT, $tokenB);
        $wsClients[] = $clientB;
        ok($clientB->statusCode === 101 && $clientB->error === null,
            'B 用 ?token= 完成 WebSocket 握手（101）', (string) $clientB->error);

        $helloB = $clientB->waitFor(function ($e) {
            return dot($e, 'type') === 'hello';
        }, 5000);
        ok($helloB !== null && dot($helloB, 'type') === 'hello', 'B 收到 hello 事件');
        ok(dot($helloB, 'user.id') === $userB['id'], 'hello.user 是 B 本人', (string) dot($helloB, 'user.id'));
        ok(dot($helloB, 'coupleId') === $coupleId, 'hello.coupleId 正确');
        ok(is_int(dot($helloB, 'serverTime')), 'hello.serverTime 是数字');

        // 再连接 A（用 Authorization 头鉴权），B 应收到 A 的 presence
        $clientA = WsClient::connect($WS_PORT, null, $tokenA);
        $wsClients[] = $clientA;
        ok($clientA->statusCode === 101 && $clientA->error === null,
            'A 用 Authorization 头完成 WebSocket 握手（101）', (string) $clientA->error);
        $helloA = $clientA->waitFor(function ($e) {
            return dot($e, 'type') === 'hello';
        }, 5000);
        ok($helloA !== null && dot($helloA, 'user.id') === $userA['id'], 'A 收到 hello 且 user 是 A');

        $presence = $clientB->waitFor(function ($e) {
            return dot($e, 'type') === 'presence' && dot($e, 'userId') !== null;
        }, 5000);
        ok($presence !== null, 'A 上线后 B 收到 presence 事件');
        ok(dot($presence, 'online') === true, 'presence.online 为 true');
        ok(is_int(dot($presence, 'lastSeen')), 'presence.lastSeen 是数字');

        // A 通过 REST 发消息 -> B 应立即通过 WS 收到 {type:"message"}
        $wsClientId = 'c_ws_' . (int) round(microtime(true) * 1000);
        $send = req('POST', '/api/chat/messages', ['token' => $tokenA, 'body' => [
            'clientId' => $wsClientId, 'kind' => 'text', 'body' => 'WS 实时测试',
        ]]);
        ok($send['status'] === 200, 'A 通过 REST 发送消息返回 200', '实际 ' . $send['status']);

        $pushed = $clientB->waitFor(function ($e) use ($wsClientId) {
            return dot($e, 'type') === 'message' && dot($e, 'message.clientId') === $wsClientId;
        }, 6000);
        ok($pushed !== null, 'B 在数秒内通过 WebSocket 收到 {type:"message"}');
        ok(dot($pushed, 'message.body') === 'WS 实时测试', 'WS 推送的 message.body 正确',
            (string) dot($pushed, 'message.body'));
        ok(dot($pushed, 'message.senderId') === $userA['id'], 'WS 推送的 message.senderId 为 A');

        // ping -> pong
        $clientB->sendJson(['type' => 'ping']);
        $pong = $clientB->waitFor(function ($e) {
            return dot($e, 'type') === 'pong';
        }, 4000);
        ok($pong !== null, 'WebSocket ping 收到 pong 响应');
        ok(is_int(dot($pong, 'serverTime')), 'pong 含 serverTime');

        // typing 事件
        $clientB->sendJson(['type' => 'typing']);
        $typing = $clientA->waitFor(function ($e) {
            return dot($e, 'type') === 'typing';
        }, 4000);
        ok($typing !== null, 'B 的 typing 事件被推送给 A');
        ok(dot($typing, 'userId') === $userB['id'], 'typing.userId 为 B');
        ok(is_int(dot($typing, 'at')), 'typing.at 是数字');

        // 未知事件类型 -> error
        $clientB->sendJson(['type' => 'not_supported']);
        $errEvent = $clientB->waitFor(function ($e) {
            return dot($e, 'type') === 'error';
        }, 4000);
        ok($errEvent !== null && is_string(dot($errEvent, 'message')), '不支持的事件类型返回 error 事件');

        // 非法 JSON -> error
        $clientB->sendFrame(0x1, 'not json at all');
        $errJson = $clientB->waitFor(function ($e) {
            return dot($e, 'type') === 'error' && mb_strpos((string) dot($e, 'message'), 'JSON') !== false;
        }, 4000);
        ok($errJson !== null, '非 JSON 消息返回 error 事件');

        // 已读回执事件：A 上报已读 -> B 收到 read
        $read = req('POST', '/api/chat/read', ['token' => $tokenA, 'body' => ['upTo' => (int) round(microtime(true) * 1000)]]);
        ok($read['status'] === 200, 'A 上报已读返回 200');
        $readEvent = $clientB->waitFor(function ($e) {
            return dot($e, 'type') === 'read';
        }, 6000);
        ok($readEvent !== null, '对方上报已读后 B 收到 {type:"read"}');
        ok(dot($readEvent, 'userId') === $userA['id'] && is_int(dot($readEvent, 'upTo')), 'read 事件含 userId / upTo');

        // 同步事件：B 推送同步对象 -> A 收到 {type:"sync"}
        $nowMs = (int) round(microtime(true) * 1000);
        $pushSync = req('POST', '/api/sync/push', ['token' => $tokenB, 'body' => ['objects' => [[
            'kind' => 'spark', 'id' => 's_ws', 'updatedAt' => $nowMs, 'payload' => ['level' => 1],
        ]]]]);
        ok($pushSync['status'] === 200, 'B 推送同步对象返回 200');
        $syncEvent = $clientA->waitFor(function ($e) {
            return dot($e, 'type') === 'sync';
        }, 6000);
        ok($syncEvent !== null, 'A 收到 {type:"sync"} 事件');
        ok(dot($syncEvent, 'fromUserId') === $userB['id'], 'sync.fromUserId 为 B');
        ok(is_array(dot($syncEvent, 'kinds')) && in_array('spark', (array) dot($syncEvent, 'kinds'), true),
            'sync.kinds 含 spark');

        // 无 token / 错误 token -> 401
        $noToken = WsClient::connect($WS_PORT, null, null);
        ok($noToken->statusCode === 401, '无 token 的 WebSocket 握手返回 401', (string) $noToken->statusCode);
        if (is_resource($noToken->sock)) {
            @fclose($noToken->sock);
        }
        $badToken = WsClient::connect($WS_PORT, 'not-a-jwt', null);
        ok($badToken->statusCode === 401, '非法 token 的 WebSocket 握手返回 401', (string) $badToken->statusCode);
        if (is_resource($badToken->sock)) {
            @fclose($badToken->sock);
        }
    }

    // =====================================================================
    section('6. 表情包（含真实 PNG 上传与 magic bytes 校验）');
    // =====================================================================
    $packId = null;
    $emojiId = null;
    {
        $png = makePng(8);
        ok(isPng($png), '本地生成的 8x8 PNG 结构合法', 'size=' . strlen($png) . 'B');
        ok(strlen($png) > 60, 'PNG 体积合理', strlen($png) . 'B');
        $pngB64 = base64_encode($png);

        $create = req('POST', '/api/emoji/packs', ['token' => $tokenA, 'body' => ['name' => '我的表情']]);
        ok($create['status'] === 200, '创建表情包返回 200', '实际 ' . $create['status'] . ' ' . $create['text']);
        $packId = dot($create['json'], 'pack.id');
        ok(is_string($packId) && strpos($packId, 'p_') === 0, 'pack.id 使用 p_ 前缀', (string) $packId);
        ok(dot($create['json'], 'pack.name') === '我的表情', 'pack.name 正确');
        ok(dot($create['json'], 'pack.ownerId') === $userA['id'], 'pack.ownerId 为 A');
        ok(dot($create['json'], 'pack.isPublic') === false, 'pack.isPublic 为 false');
        ok(dot($create['json'], 'pack.items') === [], '新包 items 为空');

        $nameTooLong = req('POST', '/api/emoji/packs', ['token' => $tokenA, 'body' => ['name' => str_repeat('长', 33)]]);
        ok($nameTooLong['status'] === 400, '表情包名超过 32 字被拒绝（400）', '实际 ' . $nameTooLong['status']);
        $noName = req('POST', '/api/emoji/packs', ['token' => $tokenA, 'body' => []]);
        ok($noName['status'] === 400, '缺少 name 被拒绝（400）', '实际 ' . $noName['status']);

        // 上传真实 PNG（base64）
        $upload = req('POST', '/api/emoji/items', ['token' => $tokenA, 'body' => [
            'packId' => $packId, 'mime' => 'image/png', 'name' => '大笑', 'dataBase64' => $pngB64,
        ]]);
        ok($upload['status'] === 200, '上传 PNG 表情返回 200', '实际 ' . $upload['status'] . ' ' . $upload['text']);
        $item = dot($upload['json'], 'item');
        $emojiId = dot($item, 'id');
        ok(is_string($emojiId) && strpos($emojiId, 'e_') === 0, 'item.id 使用 e_ 前缀', (string) $emojiId);
        ok(preg_match('/^e_[A-Za-z0-9]+$/', (string) $emojiId) === 1, 'item.id 满足 ^e_[A-Za-z0-9]+$ 格式', (string) $emojiId);
        ok(dot($item, 'mime') === 'image/png', 'item.mime 为 image/png', (string) dot($item, 'mime'));
        ok(dot($item, 'size') === strlen($png), 'item.size 与实际字节数一致',
            (string) dot($item, 'size') . ' vs ' . strlen($png));
        ok(dot($item, 'name') === '大笑', 'item.name 正确');
        ok(dot($item, 'packId') === $packId, 'item.packId 正确');
        ok(dot($item, 'ownerId') === $userA['id'], 'item.ownerId 为 A');
        ok(dot($item, 'url') === '/api/emoji/blob/' . $emojiId, 'item.url 形如 /api/emoji/blob/e_xxx',
            (string) dot($item, 'url'));

        // 列表能看到
        $packs = req('GET', '/api/emoji/packs', ['token' => $tokenB]);
        ok($packs['status'] === 200, 'B 拉取表情包列表返回 200');
        $pack = null;
        foreach ((array) dot($packs['json'], 'packs', []) as $p) {
            if (dot($p, 'id') === $packId) {
                $pack = $p;
            }
        }
        ok($pack !== null, 'B 能看到 A 创建的表情包（同情侣空间可见）');
        $itemVisible = false;
        foreach ((array) dot($pack, 'items', []) as $it) {
            if (dot($it, 'id') === $emojiId) {
                $itemVisible = true;
            }
        }
        ok($itemVisible, '列表中含刚上传的表情');

        // -----------------------------------------------------------------
        // 6.2 内置公共表情包（isPublic / isSystem）
        //     真实写一个目录到 public/emoji/ 下，验证自动同步、公共可读、不可删改
        // -----------------------------------------------------------------
        @mkdir($PUBLIC_PACK_DIR, 0775, true);
        file_put_contents($PUBLIC_PACK_DIR . '/大笑.png', $png);
        $outsider = req('POST', '/api/auth/register', ['body' => [
            'username' => 'outsider_' . bin2hex(random_bytes(3)), 'password' => 'pass1234',
        ]]);
        $outsiderToken = dot($outsider['json'], 'token');

        $packsPublic = req('GET', '/api/emoji/packs', ['token' => $tokenB]);
        $pubPack = null;
        foreach ((array) dot($packsPublic['json'], 'packs', []) as $p) {
            if (dot($p, 'isPublic') === true) {
                $pubPack = $p;
            }
        }
        ok($pubPack !== null, '内置公共表情包出现在 GET /api/emoji/packs（isPublic=true）');
        ok(dot($pubPack, 'name') === '公共表情', '公共表情包名取自目录名', (string) dot($pubPack, 'name'));
        ok(strpos((string) dot($pubPack, 'id'), 'p_public_') === 0, '公共表情包 id 使用 p_public_ 前缀',
            (string) dot($pubPack, 'id'));
        ok(count((array) dot($pubPack, 'items', [])) >= 1, '公共表情包已同步到图片条目',
            (string) count((array) dot($pubPack, 'items', [])));
        $pubItemId = dot($pubPack, 'items.0.id');
        ok(preg_match('/^e_[A-Za-z0-9]+$/', (string) $pubItemId) === 1,
            '公共表情 id 满足 ^e_[A-Za-z0-9]+$', (string) $pubItemId);
        ok(dot($pubPack, 'items.0.mime') === 'image/png', '公共表情 mime 按 magic bytes 识别为 image/png',
            (string) dot($pubPack, 'items.0.mime'));

        $pubBlob = req('GET', '/api/emoji/blob/' . $pubItemId . '?token=' . rawurlencode($outsiderToken));
        ok($pubBlob['status'] === 200, '未绑定情侣的已登录用户也能访问公共表情（200）', '实际 ' . $pubBlob['status']);
        ok($pubBlob['buf'] === $png, '公共表情返回的内容与磁盘文件一致');
        ok(dot($pubBlob['headers'], 'cache-control') === 'public, max-age=31536000, immutable',
            '公共表情同样带长缓存头');

        $delPubItem = req('DELETE', '/api/emoji/items/' . $pubItemId, ['token' => $tokenA]);
        ok($delPubItem['status'] === 403, '删除公共表情包中的表情返回 403', '实际 ' . $delPubItem['status']);
        $delPubPack = req('DELETE', '/api/emoji/packs/' . dot($pubPack, 'id'), ['token' => $tokenA]);
        ok($delPubPack['status'] === 403, '删除公共/系统表情包返回 403', '实际 ' . $delPubPack['status']);
        $uploadPub = req('POST', '/api/emoji/items', ['token' => $tokenA, 'body' => [
            'packId' => dot($pubPack, 'id'), 'mime' => 'image/png', 'dataBase64' => $pngB64,
        ]]);
        ok($uploadPub['status'] === 403, '往公共表情包里上传返回 403', '实际 ' . $uploadPub['status']);

        // blob 二进制：?token= 方式
        $blob = req('GET', '/api/emoji/blob/' . $emojiId . '?token=' . rawurlencode($tokenB));
        ok($blob['status'] === 200, 'GET /api/emoji/blob/:id?token= 返回 200', '实际 ' . $blob['status']);
        ok(strpos((string) dot($blob['headers'], 'content-type'), 'image/png') !== false,
            'blob Content-Type 为 image/png', (string) dot($blob['headers'], 'content-type'));
        ok(dot($blob['headers'], 'cache-control') === 'public, max-age=31536000, immutable',
            'blob Cache-Control 符合 API.md 要求', (string) dot($blob['headers'], 'cache-control'));
        ok(dot($blob['headers'], 'x-content-type-options') === 'nosniff', 'blob 带 X-Content-Type-Options: nosniff');
        ok(strlen($blob['buf']) === strlen($png), 'blob 返回的字节数与上传一致',
            strlen($blob['buf']) . ' vs ' . strlen($png));
        ok($blob['buf'] === $png, 'blob 返回的内容与上传的 PNG 完全一致');
        ok(isPng($blob['buf']), 'blob 内容是合法 PNG');

        // blob：Authorization 头方式
        $blob2 = req('GET', '/api/emoji/blob/' . $emojiId, ['token' => $tokenB]);
        ok($blob2['status'] === 200, 'blob 支持 Authorization 头鉴权', '实际 ' . $blob2['status']);
        // 无凭证 -> 401
        $blobNoAuth = req('GET', '/api/emoji/blob/' . $emojiId);
        ok($blobNoAuth['status'] === 401, 'blob 无凭证返回 401', '实际 ' . $blobNoAuth['status']);
        ok(dot($blobNoAuth['json'], 'error') === 'unauthorized', 'blob 401 错误码为 unauthorized');
        // 非法 token -> 401
        $blobBadToken = req('GET', '/api/emoji/blob/' . $emojiId . '?token=bad.token.here');
        ok($blobBadToken['status'] === 401, 'blob 非法 token 返回 401', '实际 ' . $blobBadToken['status']);

        // 伪造 mime 的非图片数据必须被拒绝
        $fake = req('POST', '/api/emoji/items', ['token' => $tokenA, 'body' => [
            'packId' => $packId, 'mime' => 'image/png', 'name' => '假图',
            'dataBase64' => base64_encode('这不是图片，只是普通文本内容。'),
        ]]);
        ok($fake['status'] === 400 && dot($fake['json'], 'error') === 'invalid_input',
            '伪造 mime 的非图片数据被拒绝（400 invalid_input）',
            '实际 ' . $fake['status'] . ' ' . $fake['text']);

        // HTML 伪装成 PNG
        $fakeHtml = req('POST', '/api/emoji/items', ['token' => $tokenA, 'body' => [
            'packId' => $packId, 'mime' => 'image/png',
            'dataBase64' => base64_encode('<html><script>alert(1)</script></html>'),
        ]]);
        ok($fakeHtml['status'] === 400, 'HTML 内容伪装 PNG 被拒绝（400）', '实际 ' . $fakeHtml['status']);

        // 声明 mime 与实际不符
        $mismatch = req('POST', '/api/emoji/items', ['token' => $tokenA, 'body' => [
            'packId' => $packId, 'mime' => 'image/jpeg', 'dataBase64' => $pngB64,
        ]]);
        ok($mismatch['status'] === 400, '声明 mime 与实际格式不符被拒绝（400）', '实际 ' . $mismatch['status']);

        // 非白名单 mime
        $badMime = req('POST', '/api/emoji/items', ['token' => $tokenA, 'body' => [
            'packId' => $packId, 'mime' => 'image/svg+xml', 'dataBase64' => $pngB64,
        ]]);
        ok($badMime['status'] === 400, '非白名单 mime 被拒绝（400）', '实际 ' . $badMime['status']);

        // 非法 base64
        $badB64 = req('POST', '/api/emoji/items', ['token' => $tokenA, 'body' => [
            'packId' => $packId, 'mime' => 'image/png', 'dataBase64' => '!!!not base64!!!',
        ]]);
        ok($badB64['status'] === 400, '非法 base64 被拒绝（400）', '实际 ' . $badB64['status']);

        // 超出体积（解码后 1MB 上限）
        $huge = str_repeat('A', (int) ceil(1048576 / 3) * 4 + 64);
        $tooBig = req('POST', '/api/emoji/items', ['token' => $tokenA, 'body' => [
            'packId' => $packId, 'mime' => 'image/png', 'dataBase64' => $huge,
        ]]);
        ok($tooBig['status'] === 400, '超出 1MB 上限被拒绝（400）', '实际 ' . $tooBig['status']);

        // 包不存在 / 别人的包
        $noPack = req('POST', '/api/emoji/items', ['token' => $tokenA, 'body' => [
            'packId' => 'p_notexist', 'mime' => 'image/png', 'dataBase64' => $pngB64,
        ]]);
        ok($noPack['status'] === 404, '往不存在的包里上传返回 404', '实际 ' . $noPack['status']);

        // 路径穿越尝试
        $trav1 = req('GET', '/api/emoji/blob/..%2F..%2Fpackage.json?token=' . rawurlencode($tokenB));
        ok(in_array($trav1['status'], [400, 404], true), '路径穿越尝试被拒绝（400/404）', '实际 ' . $trav1['status']);
        $trav2 = req('GET', '/api/emoji/blob/e_..%2F..%2Fdb?token=' . rawurlencode($tokenB));
        ok(in_array($trav2['status'], [400, 404], true), '带 e_ 前缀的穿越尝试被拒绝（400/404）', '实际 ' . $trav2['status']);
        $trav3 = req('DELETE', '/api/emoji/items/..%2F..%2Fetc%2Fpasswd', ['token' => $tokenA]);
        ok(in_array($trav3['status'], [400, 404], true), '删除接口的穿越尝试被拒绝（400/404）', '实际 ' . $trav3['status']);

        // -----------------------------------------------------------------
        // 6.1 删除单张表情 DELETE /api/emoji/items/:id
        // -----------------------------------------------------------------
        $upload2 = req('POST', '/api/emoji/items', ['token' => $tokenA, 'body' => [
            'packId' => $packId, 'mime' => 'image/png', 'name' => '待删除', 'dataBase64' => $pngB64,
        ]]);
        ok($upload2['status'] === 200, '为删除用例再上传一张表情成功', '实际 ' . $upload2['status']);
        $itemToDelete = dot($upload2['json'], 'item.id');
        ok(is_string($itemToDelete) && preg_match('/^e_[A-Za-z0-9]+$/', $itemToDelete) === 1,
            '待删除表情 id 格式合法', (string) $itemToDelete);

        $beforeBlob = req('GET', '/api/emoji/blob/' . $itemToDelete . '?token=' . rawurlencode($tokenA));
        ok($beforeBlob['status'] === 200, '删除前 blob 可正常下载（200）', '实际 ' . $beforeBlob['status']);

        // 未鉴权 -> 401
        $delNoAuth = req('DELETE', '/api/emoji/items/' . $itemToDelete);
        ok($delNoAuth['status'] === 401, '未鉴权删除表情返回 401', '实际 ' . $delNoAuth['status']);

        // B 删除 A 上传的表情 -> 403
        $delByOther = req('DELETE', '/api/emoji/items/' . $itemToDelete, ['token' => $tokenB]);
        ok($delByOther['status'] === 403 && dot($delByOther['json'], 'error') === 'forbidden',
            'B 删除 A 上传的表情返回 403 forbidden', '实际 ' . $delByOther['status']);

        // 不存在的 id -> 404
        $delMissing = req('DELETE', '/api/emoji/items/e_zzzzzzzzzzzz', ['token' => $tokenA]);
        ok($delMissing['status'] === 404 && dot($delMissing['json'], 'error') === 'not_found',
            '删除不存在的 id 返回 404 not_found', '实际 ' . $delMissing['status']);

        // 非法 id -> 400 invalid_input（不得用于拼路径）
        foreach (['notanid', 'e_', 'p_abc', 'E_ABC', 'e_abc%20def'] as $badId) {
            $r = req('DELETE', '/api/emoji/items/' . $badId, ['token' => $tokenA]);
            ok($r['status'] === 400 && dot($r['json'], 'error') === 'invalid_input',
                '非法 id "' . rawurldecode($badId) . '" 返回 400 invalid_input',
                '实际 ' . $r['status'] . ' ' . (string) dot($r['json'], 'error'));
        }

        // A 删除自己上传的表情 -> 200
        $delOwn = req('DELETE', '/api/emoji/items/' . $itemToDelete, ['token' => $tokenA]);
        ok($delOwn['status'] === 200, 'A 删除自己上传的表情返回 200', '实际 ' . $delOwn['status'] . ' ' . $delOwn['text']);
        ok(dot($delOwn['json'], 'ok') === true, '删除响应 ok 为 true');
        ok(is_int(dot($delOwn['json'], 'serverTime')), '删除响应含 serverTime');

        // 删除后：列表里没有、blob 404、再删 404
        $afterList = req('GET', '/api/emoji/packs', ['token' => $tokenA]);
        $afterPack = null;
        foreach ((array) dot($afterList['json'], 'packs', []) as $p) {
            if (dot($p, 'id') === $packId) {
                $afterPack = $p;
            }
        }
        $stillThere = false;
        $keptOther = false;
        foreach ((array) dot($afterPack, 'items', []) as $it) {
            if (dot($it, 'id') === $itemToDelete) {
                $stillThere = true;
            }
            if (dot($it, 'id') === $emojiId) {
                $keptOther = true;
            }
        }
        ok(!$stillThere, '删除后该表情不再出现在 GET /api/emoji/packs 列表里');
        ok($keptOther, '同一包内其它表情不受影响');
        $afterBlob = req('GET', '/api/emoji/blob/' . $itemToDelete . '?token=' . rawurlencode($tokenA));
        ok($afterBlob['status'] === 404, '删除后 GET /api/emoji/blob/:id 返回 404', '实际 ' . $afterBlob['status']);
        $delAgain = req('DELETE', '/api/emoji/items/' . $itemToDelete, ['token' => $tokenA]);
        ok($delAgain['status'] === 404, '已删除的 id 再删一次返回 404', '实际 ' . $delAgain['status']);

        // 磁盘文件已删除
        $emojiFile = $DATA_DIR_ABS . '/emoji/' . $itemToDelete . '.bin';
        ok(!is_file($emojiFile), '表情图片文件已从磁盘删除');
        ok(is_file($DATA_DIR_ABS . '/emoji/' . $emojiId . '.bin'), '同包其它表情文件仍在磁盘上');

        // 删除自己的包
        $delPack = req('DELETE', '/api/emoji/packs/' . $packId, ['token' => $tokenA]);
        ok($delPack['status'] === 200 && dot($delPack['json'], 'ok') === true, '删除自己的表情包返回 200',
            '实际 ' . $delPack['status']);
        $afterPackBlob = req('GET', '/api/emoji/blob/' . $emojiId . '?token=' . rawurlencode($tokenA));
        ok($afterPackBlob['status'] === 404, '删除包后其表情文件不可访问（404）', '实际 ' . $afterPackBlob['status']);
        $delPackAgain = req('DELETE', '/api/emoji/packs/' . $packId, ['token' => $tokenA]);
        ok($delPackAgain['status'] === 404, '删除不存在的包返回 404', '实际 ' . $delPackAgain['status']);
        // B 删除 A 的包 -> 403（A 已无包，用新包验证归属）
        $pack2 = req('POST', '/api/emoji/packs', ['token' => $tokenA, 'body' => ['name' => 'A 的包']]);
        $pack2Id = dot($pack2['json'], 'pack.id');
        $delPackOther = req('DELETE', '/api/emoji/packs/' . $pack2Id, ['token' => $tokenB]);
        ok($delPackOther['status'] === 403, 'B 删除 A 创建的表情包返回 403', '实际 ' . $delPackOther['status']);
        $delPackOwner = req('DELETE', '/api/emoji/packs/' . $pack2Id, ['token' => $tokenA]);
        ok($delPackOwner['status'] === 200, 'A 删除自己的表情包成功', '实际 ' . $delPackOwner['status']);
    }

    // =====================================================================
    section('7. 后台管理 /admin/api/*');
    // =====================================================================
    $adminToken = null;
    $secretKey = 'sk-test-1234567890abcdefghijklmn';
    {
        // 错误密码登录失败
        $bad = req('POST', '/admin/api/login', ['body' => ['password' => 'wrong-password']]);
        ok($bad['status'] === 401 && dot($bad['json'], 'error') === 'unauthorized',
            '错误密码登录失败（401 unauthorized）', '实际 ' . $bad['status']);

        $empty = req('POST', '/admin/api/login', ['body' => []]);
        ok($empty['status'] === 401, '空密码登录失败（401）', '实际 ' . $empty['status']);

        // 正确密码登录成功
        $good = req('POST', '/admin/api/login', ['body' => ['password' => $ADMIN_PASSWORD]]);
        ok($good['status'] === 200, '正确密码登录成功（200）', '实际 ' . $good['status'] . ' ' . $good['text']);
        ok(is_string(dot($good['json'], 'token')) && strlen((string) dot($good['json'], 'token')) > 20,
            '登录响应含 admin token');
        $adminToken = dot($good['json'], 'token');

        // 兜底形式（无伪静态）下的后台接口
        $fallbackLogin = req('POST', '/index.php/admin/api/login', ['body' => ['password' => $ADMIN_PASSWORD]]);
        ok($fallbackLogin['status'] === 200, '兜底形式 /index.php/admin/api/login 也能登录',
            '实际 ' . $fallbackLogin['status']);
        $fallbackSettings = req('GET', '/index.php/admin/api/settings', ['token' => $adminToken]);
        ok($fallbackSettings['status'] === 200, '兜底形式读取后台设置返回 200', '实际 ' . $fallbackSettings['status']);

        // 未鉴权 / 用户 token 访问后台 -> 401
        $noAuth = req('GET', '/admin/api/settings');
        ok($noAuth['status'] === 401, '未鉴权访问 /admin/api/settings 返回 401', '实际 ' . $noAuth['status']);
        $userTokenTry = req('GET', '/admin/api/settings', ['token' => $tokenA]);
        ok($userTokenTry['status'] === 401, '用户 token 无法访问后台接口（不同 secret）', '实际 ' . $userTokenTry['status']);

        // 读取设置
        $s0 = req('GET', '/admin/api/settings', ['token' => $adminToken]);
        ok($s0['status'] === 200, 'GET /admin/api/settings 返回 200');
        ok(is_string(dot($s0['json'], 'deepseekApiKeyMasked')), 'settings 含 deepseekApiKeyMasked 字段',
            (string) dot($s0['json'], 'deepseekApiKeyMasked'));
        ok(!array_key_exists('deepseekApiKey', (array) $s0['json']), 'settings 不返回 deepseekApiKey 明文字段');
        ok(dot($s0['json'], 'deepseekBaseUrl') === 'https://api.deepseek.com', '默认 deepseekBaseUrl 正确',
            (string) dot($s0['json'], 'deepseekBaseUrl'));
        ok(dot($s0['json'], 'deepseekModel') === 'deepseek-chat', '默认 deepseekModel 为 deepseek-chat');
        ok(dot($s0['json'], 'aiEnabled') === true, '默认 aiEnabled 为 true');
        ok(dot($s0['json'], 'aiDailyLimit') === 200, '默认 aiDailyLimit 为 200', (string) dot($s0['json'], 'aiDailyLimit'));
        ok(dot($s0['json'], 'registerOpen') === true, '默认 registerOpen 为 true');
        ok(dot($s0['json'], 'maxEmojiPacks') === 30, '默认 maxEmojiPacks 为 30', (string) dot($s0['json'], 'maxEmojiPacks'));
        ok(dot($s0['json'], 'maxMessagesPerDay') === 3000, '默认 maxMessagesPerDay 为 3000');
        ok(dot($s0['json'], 'minAppBuild') === 1 && dot($s0['json'], 'latestAppBuild') === 1, '默认版本号为 1');
        ok(dot($s0['json'], 'announcement') === '' && dot($s0['json'], 'updateURL') === '', '默认公告/更新地址为空');

        // 写入 DeepSeek Key
        $put = req('PUT', '/admin/api/settings', ['token' => $adminToken, 'body' => [
            'deepseekApiKey' => $secretKey, 'deepseekModel' => 'deepseek-reasoner', 'aiDailyLimit' => 123,
        ]]);
        ok($put['status'] === 200 && dot($put['json'], 'ok') === true, 'PUT /admin/api/settings 返回 200',
            '实际 ' . $put['status']);

        $s1 = req('GET', '/admin/api/settings', ['token' => $adminToken]);
        $masked = (string) dot($s1['json'], 'deepseekApiKeyMasked');
        ok(strlen($masked) > 0, '写入后 deepseekApiKeyMasked 非空');
        ok(strpos($masked, $secretKey) === false, '掩码中不含明文 Key', $masked);
        ok(strpos($masked, '*') !== false, '掩码中含星号');
        ok(strpos($masked, 'sk-') === 0, '掩码保留 sk- 前缀', $masked);
        ok(substr($masked, -4) === substr($secretKey, -4), '掩码保留末 4 位', $masked);
        ok(strpos($s1['text'], $secretKey) === false, '整个 settings 响应体中不含明文 Key');
        ok(dot($s1['json'], 'deepseekModel') === 'deepseek-reasoner', 'deepseekModel 已更新');
        ok(dot($s1['json'], 'aiDailyLimit') === 123, 'aiDailyLimit 已更新');
        ok(dot($s1['json'], 'deepseekApiKeyConfigured') === true, 'settings 标记 Key 已配置');

        // 数据库里也不应是明文（直接查 MySQL 里的原始字节）
        $dbRows = [];
        try {
            $probe = testDbConnect(true);
            $dbRows = $probe->query('SELECT `key`, `value` FROM settings')->fetchAll();
            $probe = null;
        } catch (PDOException $e) {
            $dbRows = [];
        }
        $allBytes = '';
        foreach ($dbRows as $row) {
            $allBytes .= (string) $row['key'] . '=' . (string) $row['value'] . "\n";
        }
        ok($allBytes !== '' && strpos($allBytes, $secretKey) === false,
            '数据库中不存在明文 Key（已加密存储）');

        // 非法字段类型被忽略（不脏写）
        $badPut = req('PUT', '/admin/api/settings', ['token' => $adminToken, 'body' => ['aiDailyLimit' => 'abc']]);
        ok($badPut['status'] === 200 && dot($badPut['json'], 'settings.aiDailyLimit') === 123,
            '非法类型字段被忽略（aiDailyLimit 保持 123）', (string) dot($badPut['json'], 'settings.aiDailyLimit'));
        $emptyPut = req('PUT', '/admin/api/settings', ['token' => $adminToken, 'body' => []]);
        ok($emptyPut['status'] === 400, 'PUT 空请求体返回 400', '实际 ' . $emptyPut['status']);

        // 应用设置：公告 / 版本号 / 开关
        $appPut = req('PUT', '/admin/api/settings', ['token' => $adminToken, 'body' => [
            'announcement' => '欢迎使用情侣空间', 'minAppBuild' => 1, 'latestAppBuild' => 3,
            'updateURL' => 'https://example.com/download', 'maxEmojiPacks' => 30, 'maxMessagesPerDay' => 3000,
        ]]);
        ok($appPut['status'] === 200, '保存应用设置返回 200');
        $meta = req('GET', '/api/meta');
        ok(dot($meta['json'], 'announcement') === '欢迎使用情侣空间', '公告已通过 /api/meta 下发',
            (string) dot($meta['json'], 'announcement'));
        ok(dot($meta['json'], 'latestAppBuild') === 3, 'latestAppBuild 已下发');
        ok(dot($meta['json'], 'updateURL') === 'https://example.com/download', 'updateURL 已下发');

        // 注册开关
        $closeReg = req('PUT', '/admin/api/settings', ['token' => $adminToken, 'body' => ['registerOpen' => false]]);
        ok($closeReg['status'] === 200, '关闭注册返回 200');
        $metaClosed = req('GET', '/api/meta');
        ok(dot($metaClosed['json'], 'registerOpen') === false, 'meta.registerOpen 变为 false');
        $regClosed = req('POST', '/api/auth/register', ['body' => ['username' => 'closed_' . bin2hex(random_bytes(3)), 'password' => 'pass1234']]);
        ok($regClosed['status'] === 403 && dot($regClosed['json'], 'error') === 'forbidden',
            '关闭注册后注册返回 403 forbidden', '实际 ' . $regClosed['status']);
        $reopen = req('PUT', '/admin/api/settings', ['token' => $adminToken, 'body' => ['registerOpen' => true]]);
        ok($reopen['status'] === 200, '重新开启注册返回 200');

        // 统计
        $stats = req('GET', '/admin/api/stats', ['token' => $adminToken]);
        ok($stats['status'] === 200, 'GET /admin/api/stats 返回 200');
        ok(is_int(dot($stats['json'], 'users')), 'stats.users 是数字', (string) dot($stats['json'], 'users'));
        ok(is_int(dot($stats['json'], 'couples')), 'stats.couples 是数字');
        ok(is_int(dot($stats['json'], 'messages')), 'stats.messages 是数字');
        ok(is_int(dot($stats['json'], 'aiToday')), 'stats.aiToday 是数字');
        ok(is_int(dot($stats['json'], 'aiTotal')), 'stats.aiTotal 是数字');
        ok(is_int(dot($stats['json'], 'packs')) && is_int(dot($stats['json'], 'items')), 'stats.packs/items 是数字');
        ok(is_int(dot($stats['json'], 'syncObjects')), 'stats.syncObjects 是数字');
        ok(is_int(dot($stats['json'], 'disk.totalBytes')), 'stats.disk.totalBytes 是数字');
        ok(dot($stats['json'], 'users') >= 3, 'stats.users 统计到已注册用户', (string) dot($stats['json'], 'users'));

        // 用户列表
        $users = req('GET', '/admin/api/users?page=1', ['token' => $adminToken]);
        ok($users['status'] === 200, 'GET /admin/api/users 返回 200');
        ok(is_array(dot($users['json'], 'users')) && count((array) dot($users['json'], 'users')) > 0, '用户列表非空');
        ok(is_int(dot($users['json'], 'total')), '用户列表含 total');
        $listedA = null;
        foreach ((array) dot($users['json'], 'users', []) as $u) {
            if (dot($u, 'id') === $userA['id']) {
                $listedA = $u;
            }
        }
        ok($listedA !== null, '用户列表含用户 A');
        ok(!array_key_exists('password_hash', (array) $listedA), '用户列表不含密码哈希');
        ok(array_key_exists('inviteCode', (array) $listedA), '用户列表含 inviteCode 字段');

        // 搜索
        $search = req('GET', '/admin/api/users?query=' . rawurlencode($userA['username']), ['token' => $adminToken]);
        ok($search['status'] === 200, '用户搜索返回 200');
        $foundA = false;
        foreach ((array) dot($search['json'], 'users', []) as $u) {
            if (dot($u, 'id') === $userA['id']) {
                $foundA = true;
            }
        }
        ok($foundA, '搜索结果含目标用户');

        // 重置密码
        $reset = req('POST', '/admin/api/users/' . $userA['id'] . '/reset-password', ['token' => $adminToken, 'body' => [
            'newPassword' => 'newpass456',
        ]]);
        ok($reset['status'] === 200 && dot($reset['json'], 'ok') === true, '重置用户密码返回 200', '实际 ' . $reset['status']);
        $resetShort = req('POST', '/admin/api/users/' . $userA['id'] . '/reset-password', ['token' => $adminToken, 'body' => [
            'newPassword' => '123',
        ]]);
        ok($resetShort['status'] === 400, '重置为过短密码返回 400', '实际 ' . $resetShort['status']);
        $resetMissing = req('POST', '/admin/api/users/u_notexist/reset-password', ['token' => $adminToken, 'body' => [
            'newPassword' => 'newpass456',
        ]]);
        ok($resetMissing['status'] === 404, '重置不存在用户的密码返回 404', '实际 ' . $resetMissing['status']);

        $loginNew = req('POST', '/api/auth/login', ['body' => ['username' => $userA['username'], 'password' => 'newpass456']]);
        ok($loginNew['status'] === 200, '重置后可用新密码登录', '实际 ' . $loginNew['status']);
        $tokenA = dot($loginNew['json'], 'token');
        $loginOld = req('POST', '/api/auth/login', ['body' => ['username' => $userA['username'], 'password' => 'pass1234']]);
        ok($loginOld['status'] === 401, '重置后旧密码无法登录（401）', '实际 ' . $loginOld['status']);

        // 禁用 / 启用
        $disable = req('POST', '/admin/api/users/' . $userB['id'] . '/toggle-disabled', ['token' => $adminToken, 'body' => ['disabled' => true]]);
        ok($disable['status'] === 200, '禁用用户返回 200', '实际 ' . $disable['status']);
        ok(dot($disable['json'], 'user.disabled') === true, '禁用响应里的 user.disabled 为 true');
        $disabledLogin = req('POST', '/api/auth/login', ['body' => ['username' => $userB['username'], 'password' => 'pass1234']]);
        ok($disabledLogin['status'] === 403 && dot($disabledLogin['json'], 'error') === 'forbidden',
            '被禁用用户登录返回 403 forbidden', '实际 ' . $disabledLogin['status']);
        $disabledApi = req('GET', '/api/auth/me', ['token' => $tokenB]);
        ok($disabledApi['status'] === 403, '被禁用用户的 token 访问接口返回 403', '实际 ' . $disabledApi['status']);
        $badToggle = req('POST', '/admin/api/users/' . $userB['id'] . '/toggle-disabled', ['token' => $adminToken, 'body' => ['disabled' => 'yes']]);
        ok($badToggle['status'] === 400, 'disabled 非布尔值返回 400', '实际 ' . $badToggle['status']);
        $enable = req('POST', '/admin/api/users/' . $userB['id'] . '/toggle-disabled', ['token' => $adminToken, 'body' => ['disabled' => false]]);
        ok($enable['status'] === 200, '启用用户返回 200');
        $reLoginB = req('POST', '/api/auth/login', ['body' => ['username' => $userB['username'], 'password' => 'pass1234']]);
        ok($reLoginB['status'] === 200, '启用后可正常登录', '实际 ' . $reLoginB['status']);
        $tokenB = dot($reLoginB['json'], 'token');

        // 表情包统计
        $packs = req('GET', '/admin/api/packs', ['token' => $adminToken]);
        ok($packs['status'] === 200, 'GET /admin/api/packs 返回 200');
        ok(is_int(dot($packs['json'], 'totalPacks')) && is_int(dot($packs['json'], 'totalItems')), 'packs 统计是数字');
        ok(is_int(dot($packs['json'], 'maxPacksPerCouple')) && is_int(dot($packs['json'], 'maxItemBytes')),
            'packs 含限制说明字段');

        // 操作日志
        $logs = req('GET', '/admin/api/logs?limit=100', ['token' => $adminToken]);
        ok($logs['status'] === 200, 'GET /admin/api/logs 返回 200');
        ok(is_array(dot($logs['json'], 'logs')) && count((array) dot($logs['json'], 'logs')) > 0, '操作日志非空');
        $actions = [];
        foreach ((array) dot($logs['json'], 'logs', []) as $l) {
            $actions[] = dot($l, 'action');
        }
        ok(in_array('admin.login.success', $actions, true), '日志记录了后台登录成功');
        ok(in_array('admin.login.failed', $actions, true), '日志记录了后台登录失败');
        ok(in_array('settings.update', $actions, true), '日志记录了设置修改');
        ok(in_array('user.reset_password', $actions, true), '日志记录了重置密码');
        ok(in_array('user.toggle_disabled', $actions, true), '日志记录了禁用/启用');
        $firstLog = dot($logs['json'], 'logs.0');
        ok(is_int(dot($firstLog, 'createdAt')) && array_key_exists('detail', (array) $firstLog),
            '日志条目含 createdAt / detail');

        // 系统信息
        $sys = req('GET', '/admin/api/system', ['token' => $adminToken]);
        ok($sys['status'] === 200 && is_string(dot($sys['json'], 'php')), 'GET /admin/api/system 返回 PHP 版本');
        ok(dot($sys['json'], 'wsRunning') === true, '/admin/api/system 报告 WebSocket 正在运行');
        // 服务器信息区块：数据库版本 / 连接状态 / 数据目录 / 时区 / JWT 密钥
        ok(is_string(dot($sys['json'], 'database.version')) && dot($sys['json'], 'database.version') !== '',
            '/admin/api/system 返回数据库版本', (string) dot($sys['json'], 'database.version'));
        ok(in_array(dot($sys['json'], 'database.engine'), ['MySQL', 'MariaDB'], true),
            '数据库类型为 MySQL 或 MariaDB', (string) dot($sys['json'], 'database.engine'));
        ok(dot($sys['json'], 'database.connected') === true, '数据库连接状态为「正常」');
        ok(dot($sys['json'], 'database.charset') === 'utf8mb4', '数据库字符集为 utf8mb4',
            (string) dot($sys['json'], 'database.charset'));
        ok((int) dot($sys['json'], 'database.port') === $DB_PORT, '/admin/api/system 返回数据库端口',
            (string) dot($sys['json'], 'database.port'));
        ok(dot($sys['json'], 'database.name') === $DB_NAME, '/admin/api/system 返回数据库名',
            (string) dot($sys['json'], 'database.name'));
        ok(is_int(dot($sys['json'], 'database.schemaVersion')), '系统信息含表结构版本');
        ok(dot($sys['json'], 'jwtSecretConfigured') === true, '系统信息显示 JWT 密钥已配置');
        ok(is_string(dot($sys['json'], 'timezone')) && dot($sys['json'], 'timezone') !== '', '系统信息含时区');
        ok(is_string(dot($sys['json'], 'env.dataDir')) && dot($sys['json'], 'env.dataDir') !== '', '系统信息含数据目录');

        // 删除用户
        $victim = req('POST', '/api/auth/register', ['body' => ['username' => 'todelete_' . bin2hex(random_bytes(3)), 'password' => 'pass1234']]);
        $delUser = req('DELETE', '/admin/api/users/' . dot($victim['json'], 'user.id'), ['token' => $adminToken]);
        ok($delUser['status'] === 200 && dot($delUser['json'], 'ok') === true, '删除用户返回 200', '实际 ' . $delUser['status']);
        $checkDeleted = req('GET', '/admin/api/users?query=' . rawurlencode((string) dot($victim['json'], 'user.username')), ['token' => $adminToken]);
        ok(count((array) dot($checkDeleted['json'], 'users', [])) === 0, '删除后用户列表中不再出现');
        $delMissing = req('DELETE', '/admin/api/users/u_notexist', ['token' => $adminToken]);
        ok($delMissing['status'] === 404, '删除不存在的用户返回 404', '实际 ' . $delMissing['status']);

        // 后台页面可访问
        $adminPage = req('GET', '/admin');
        ok($adminPage['status'] === 200, 'GET /admin 返回 200', '实际 ' . $adminPage['status']);
        ok(strpos((string) dot($adminPage['headers'], 'content-type'), 'text/html') !== false, 'GET /admin 返回 HTML');
        ok(mb_strpos($adminPage['buf'], '情侣空间后台') !== false, '后台页面含中文标题');
        ok(mb_strpos($adminPage['buf'], 'admin/api/login') !== false, '后台页面含登录接口调用');
        $adminPhp = req('GET', '/admin.php');
        ok($adminPhp['status'] === 200, 'GET /admin.php 也能直接访问', '实际 ' . $adminPhp['status']);
    }

    // =====================================================================
    section('8. AI 接口（未配置 Key 时的受控行为）');
    // =====================================================================
    {
        $status = req('GET', '/api/ai/status', ['token' => $tokenA]);
        ok($status['status'] === 200, 'GET /api/ai/status 返回 200', '实际 ' . $status['status']);
        ok(is_bool(dot($status['json'], 'enabled')), 'ai/status.enabled 是布尔值');
        ok(dot($status['json'], 'configured') === true, 'ai/status.configured 为 true（已写入 Key）');
        ok(is_string(dot($status['json'], 'model')), 'ai/status.model 是字符串', (string) dot($status['json'], 'model'));
        ok(is_int(dot($status['json'], 'dailyLimit')), 'ai/status.dailyLimit 是数字');
        ok(is_int(dot($status['json'], 'usedToday')), 'ai/status.usedToday 是数字');

        $noAuth = req('GET', '/api/ai/status');
        ok($noAuth['status'] === 401, 'AI 接口未鉴权返回 401', '实际 ' . $noAuth['status']);

        // 清空 Key -> parse-ledger 必须是受控 403（不是 500）
        $clear = req('PUT', '/admin/api/settings', ['token' => $adminToken, 'body' => ['deepseekApiKey' => '']]);
        ok($clear['status'] === 200, '清空 deepseekApiKey 返回 200');
        ok(dot($clear['json'], 'settings.deepseekApiKeyMasked') === '', '清空后掩码为空字符串',
            (string) dot($clear['json'], 'settings.deepseekApiKeyMasked'));

        $parse = req('POST', '/api/ai/parse-ledger', ['token' => $tokenA, 'body' => [
            'text' => '今天午饭25', 'currency' => 'CNY', 'today' => '2025-06-01',
        ]]);
        ok($parse['status'] === 403, '未配置 Key 时 /api/ai/parse-ledger 返回受控 403（不是 500）',
            '实际 ' . $parse['status'] . ' ' . $parse['text']);
        ok(dot($parse['json'], 'error') === 'forbidden', '未配置 Key 的 error 为 forbidden');
        ok(dot($parse['json'], 'message') === 'AI 未配置', '未配置 Key 的 message 为「AI 未配置」',
            (string) dot($parse['json'], 'message'));
        ok(is_int(dot($parse['json'], 'serverTime')), 'AI 错误响应含 serverTime');

        $status2 = req('GET', '/api/ai/status', ['token' => $tokenA]);
        ok(dot($status2['json'], 'configured') === false, '清空后 configured 变为 false');

        // AI 开关关闭 -> 403 forbidden（AI 功能已关闭）
        req('PUT', '/admin/api/settings', ['token' => $adminToken, 'body' => ['deepseekApiKey' => $secretKey, 'aiEnabled' => false]]);
        $off = req('POST', '/api/ai/parse-ledger', ['token' => $tokenA, 'body' => ['text' => '午饭25']]);
        ok($off['status'] === 403, 'AI 开关关闭时返回 403', '实际 ' . $off['status']);
        $metaOff = req('GET', '/api/meta');
        ok(dot($metaOff['json'], 'features.ai') === false, 'meta.features.ai 反映后台开关');
        req('PUT', '/admin/api/settings', ['token' => $adminToken, 'body' => ['aiEnabled' => true]]);

        // 参数校验：text 为空 -> 400
        $emptyText = req('POST', '/api/ai/parse-ledger', ['token' => $tokenA, 'body' => ['text' => '']]);
        ok($emptyText['status'] === 400, 'text 为空返回 400', '实际 ' . $emptyText['status']);
        $badDate = req('POST', '/api/ai/parse-ledger', ['token' => $tokenA, 'body' => ['text' => '午饭25', 'today' => '2025/06/01']]);
        ok($badDate['status'] === 400, 'today 格式错误返回 400', '实际 ' . $badDate['status']);
        $badMonth = req('POST', '/api/ai/monthly-report', ['token' => $tokenA, 'body' => [
            'month' => '2025-13', 'totals' => ['expense' => 1], 'days' => 30,
        ]]);
        ok($badMonth['status'] === 400, 'month 非法返回 400', '实际 ' . $badMonth['status']);
        $noTotals = req('POST', '/api/ai/monthly-report', ['token' => $tokenA, 'body' => ['month' => '2025-06']]);
        ok($noTotals['status'] === 400, 'monthly-report 缺少 totals 返回 400', '实际 ' . $noTotals['status']);
        $badDays = req('POST', '/api/ai/monthly-report', ['token' => $tokenA, 'body' => [
            'month' => '2025-06', 'totals' => ['expense' => 1], 'days' => 0,
        ]]);
        ok($badDays['status'] === 400, 'days=0 返回 400', '实际 ' . $badDays['status']);
        $badByCat = req('POST', '/api/ai/monthly-report', ['token' => $tokenA, 'body' => [
            'month' => '2025-06', 'totals' => ['expense' => 1], 'byCategory' => 'nope', 'days' => 30,
        ]]);
        ok($badByCat['status'] === 400, 'byCategory 非数组返回 400', '实际 ' . $badByCat['status']);

        // 后台 AI 测试接口：
        //   1) 未配置 Key 时返回 403「AI 未配置」
        //   2) 传入临时 Key（base URL 指向不可达地址，避免真实联网）时必须返回受控错误，
        //      且测试结束后原配置要原样恢复
        req('PUT', '/admin/api/settings', ['token' => $adminToken, 'body' => ['deepseekApiKey' => '']]);
        $aiTestUnconfigured = req('POST', '/admin/api/ai/test', ['token' => $adminToken, 'body' => []]);
        ok($aiTestUnconfigured['status'] === 403, '未配置 Key 时后台 AI 测试返回 403',
            '实际 ' . $aiTestUnconfigured['status']);
        ok(dot($aiTestUnconfigured['json'], 'message') === 'AI 未配置', '后台 AI 测试 403 的 message 为「AI 未配置」');

        req('PUT', '/admin/api/settings', ['token' => $adminToken, 'body' => ['deepseekApiKey' => $secretKey]]);
        $aiTest = req('POST', '/admin/api/ai/test', ['token' => $adminToken, 'body' => [
            'deepseekApiKey' => 'sk-temporary-key-for-test',
            'deepseekBaseUrl' => 'http://127.0.0.1:9',
        ]]);
        ok(in_array($aiTest['status'], [403, 429, 502, 504], true),
            '后台 AI 测试（临时 Key 不可达）返回受控错误（非 500）',
            '实际 ' . $aiTest['status'] . ' ' . $aiTest['text']);
        $sAfter = req('GET', '/admin/api/settings', ['token' => $adminToken]);
        ok(dot($sAfter['json'], 'deepseekBaseUrl') === 'https://api.deepseek.com',
            'AI 测试后 baseUrl 已恢复', (string) dot($sAfter['json'], 'deepseekBaseUrl'));
        ok(substr((string) dot($sAfter['json'], 'deepseekApiKeyMasked'), -4) === substr($secretKey, -4),
            'AI 测试后原 Key 已恢复（掩码末 4 位一致）', (string) dot($sAfter['json'], 'deepseekApiKeyMasked'));
        $logsAfterTest = req('GET', '/admin/api/logs?limit=20', ['token' => $adminToken]);
        $testActions = [];
        foreach ((array) dot($logsAfterTest['json'], 'logs', []) as $l) {
            $testActions[] = dot($l, 'action');
        }
        ok(count(array_intersect($testActions, ['ai.test', 'ai.test.failed'])) > 0, '日志记录了 AI 测试动作',
            json_encode($testActions));

        // 最后清空 Key，保持「未配置」状态供报告说明
        req('PUT', '/admin/api/settings', ['token' => $adminToken, 'body' => ['deepseekApiKey' => '']]);
        $finalStatus = req('GET', '/api/ai/status', ['token' => $tokenA]);
        ok(dot($finalStatus['json'], 'configured') === false, '清空 Key 后 configured 为 false');
    }

    // =====================================================================
    section('9. 通用约定校验（错误码 / serverTime / 账号 / 解绑）');
    // =====================================================================
    {
        // 404 结构
        $nf = req('GET', '/api/not-exist-at-all');
        ok($nf['status'] === 404, '未知接口返回 404', '实际 ' . $nf['status']);
        ok(dot($nf['json'], 'error') === 'not_found', '404 响应 error 为 not_found', (string) dot($nf['json'], 'error'));
        ok(is_string(dot($nf['json'], 'message')), '404 响应含 message');
        ok(is_int(dot($nf['json'], 'serverTime')), '404 响应含 serverTime');
        $nfAdmin = req('GET', '/admin/api/not-exist');
        ok($nfAdmin['status'] === 401, '未鉴权访问未知后台接口返回 401', '实际 ' . $nfAdmin['status']);

        // 非法 JSON
        $badJson = req('POST', '/api/auth/login', ['rawBody' => '{not json']);
        ok($badJson['status'] === 400, '非法 JSON 请求体返回 400', '实际 ' . $badJson['status']);
        ok(dot($badJson['json'], 'error') === 'invalid_input', '非法 JSON 的 error 为 invalid_input');

        // 无 token
        $noToken = req('GET', '/api/auth/me');
        ok($noToken['status'] === 401 && dot($noToken['json'], 'error') === 'unauthorized',
            '无 token 访问 /api/auth/me 返回 401 unauthorized', '实际 ' . $noToken['status']);
        $badToken = req('GET', '/api/auth/me', ['token' => 'invalid.token.value']);
        ok($badToken['status'] === 401, '非法 token 返回 401', '实际 ' . $badToken['status']);

        // 登录
        $login = req('POST', '/api/auth/login', ['body' => ['username' => $userA['username'], 'password' => 'wrong']]);
        ok($login['status'] === 401 && dot($login['json'], 'error') === 'unauthorized', '密码错误返回 401 unauthorized',
            '实际 ' . $login['status']);
        $noUser = req('POST', '/api/auth/login', ['body' => ['username' => 'nobody_here', 'password' => 'whatever']]);
        ok($noUser['status'] === 401, '用户名不存在返回 401', '实际 ' . $noUser['status']);

        // PATCH /api/auth/me
        $patch = req('PATCH', '/api/auth/me', ['token' => $tokenA, 'body' => ['nickname' => '新昵称']]);
        ok($patch['status'] === 200, 'PATCH /api/auth/me 返回 200', '实际 ' . $patch['status']);
        ok(dot($patch['json'], 'user.nickname') === '新昵称', '昵称已更新', (string) dot($patch['json'], 'user.nickname'));
        $patchEmoji = req('PATCH', '/api/auth/me', ['token' => $tokenA, 'body' => ['avatarEmoji' => '🐰']]);
        ok(dot($patchEmoji['json'], 'user.avatarEmoji') === '🐰', '头像 emoji 已更新');
        $patchEmpty = req('PATCH', '/api/auth/me', ['token' => $tokenA, 'body' => []]);
        ok($patchEmpty['status'] === 400, 'PATCH me 无字段返回 400', '实际 ' . $patchEmpty['status']);

        // POST /api/auth/password
        $pwd = req('POST', '/api/auth/password', ['token' => $tokenA, 'body' => [
            'oldPassword' => 'newpass456', 'newPassword' => 'finalpass789',
        ]]);
        ok($pwd['status'] === 200 && dot($pwd['json'], 'ok') === true, 'POST /api/auth/password 返回 200',
            '实际 ' . $pwd['status'] . ' ' . $pwd['text']);
        $wrongOld = req('POST', '/api/auth/password', ['token' => $tokenA, 'body' => [
            'oldPassword' => 'wrongwrong', 'newPassword' => 'whatever123',
        ]]);
        ok($wrongOld['status'] === 401, '原密码错误返回 401', '实际 ' . $wrongOld['status']);
        $loginFinal = req('POST', '/api/auth/login', ['body' => ['username' => $userA['username'], 'password' => 'finalpass789']]);
        ok($loginFinal['status'] === 200, '修改后的新密码可登录', '实际 ' . $loginFinal['status']);
        $tokenA = dot($loginFinal['json'], 'token');

        // PATCH /api/couple
        $cpatch = req('PATCH', '/api/couple', ['token' => $tokenA, 'body' => [
            'togetherDate' => 1700000000000, 'anniversaryTitle' => '在一起的日子',
        ]]);
        ok($cpatch['status'] === 200, 'PATCH /api/couple 返回 200', '实际 ' . $cpatch['status']);
        ok(dot($cpatch['json'], 'couple.togetherDate') === 1700000000000, 'togetherDate 已更新');
        ok(dot($cpatch['json'], 'couple.anniversaryTitle') === '在一起的日子', 'anniversaryTitle 已更新');
        $cpatchEmpty = req('PATCH', '/api/couple', ['token' => $tokenA, 'body' => []]);
        ok($cpatchEmpty['status'] === 400, 'PATCH couple 无字段返回 400', '实际 ' . $cpatchEmpty['status']);
        $cpatchNull = req('PATCH', '/api/couple', ['token' => $tokenA, 'body' => ['togetherDate' => null]]);
        ok($cpatchNull['status'] === 200 && dot($cpatchNull['json'], 'couple.togetherDate') === null,
            'togetherDate 可显式清空为 null');

        // 所有响应都带 serverTime
        $me = req('GET', '/api/auth/me', ['token' => $tokenA]);
        ok(is_int(dot($me['json'], 'serverTime')), 'GET /api/auth/me 响应含 serverTime');
        ok(dot($me['json'], 'user.id') === $userA['id'], 'auth/me 返回正确的用户');
        ok(dot($me['json'], 'couple.id') === $coupleId, 'auth/me 返回情侣空间');

        // 解绑
        $leave = req('POST', '/api/couple/leave', ['token' => $tokenA]);
        ok($leave['status'] === 200 && dot($leave['json'], 'ok') === true, 'POST /api/couple/leave 返回 200',
            '实际 ' . $leave['status']);
        $afterLeave = req('GET', '/api/auth/me', ['token' => $tokenA]);
        ok(dot($afterLeave['json'], 'user.coupleId') === null, '解绑后 coupleId 置空');
        ok(dot($afterLeave['json'], 'couple') === null, '解绑后 couple 为 null');
        $leaveAgain = req('POST', '/api/couple/leave', ['token' => $tokenA]);
        ok($leaveAgain['status'] === 403, '未绑定用户调用 leave 返回 403', '实际 ' . $leaveAgain['status']);

        // 解绑后原邀请码失效
        $joinDead = req('POST', '/api/couple/join', ['token' => $tokenA, 'body' => ['inviteCode' => $inviteCode]]);
        ok($joinDead['status'] === 404, '解绑后原邀请码失效（404）', '实际 ' . $joinDead['status']);
    }
} catch (\Throwable $e) {
    $GLOBALS['failed']++;
    $GLOBALS['failures'][] = '测试过程中抛出异常：' . $e->getMessage();
    out('');
    out('❌ 测试异常：' . $e->getMessage());
    out($e->getFile() . ':' . $e->getLine());
}

// ---------------------------------------------------------------------------
// 清理
// ---------------------------------------------------------------------------
out('');
out('----------------------------------------');
out('清理：关闭 WebSocket 连接、停止服务进程、删除测试数据目录与测试数据库');

foreach ($wsClients as $c) {
    $c->close();
}
if ($wsProc !== null) {
    stopProcess($wsProc);
}
stopProcess($restProc);
$dropped = dropTestDatabase();
removeDir($DATA_DIR_ABS);
// 清掉测试期间写进 public/emoji/ 的临时公共表情包（只删这一个子目录）
removeDir($PUBLIC_PACK_DIR);
@unlink($ROOT . '/data-test-png.txt');
out('数据目录已清理：' . $DATA_DIR);
out('测试数据库已删除： ' . ($dropped ? $DB_NAME : ($DB_NAME . '（删除失败，请手动清理）')));

$passed = $GLOBALS['passed'];
$failed = $GLOBALS['failed'];
out('');
out('========================================');
if ($failed === 0) {
    out('  ✅ ALL TESTS PASSED（通过 ' . $passed . ' 项断言）');
    $exitCode = 0;
} else {
    out('  ❌ 失败 ' . $failed . ' 项 / 通过 ' . $passed . ' 项');
    out('----------------------------------------');
    foreach ($GLOBALS['failures'] as $f) {
        out('  · ' . $f);
    }
    $restTail = tailFile($REST_LOG, 25);
    if (trim($restTail) !== '') {
        out('----------------------------------------');
        out('REST 服务端日志（尾部）：');
        out($restTail);
    }
    $wsTail = tailFile($WS_LOG, 20);
    if (trim($wsTail) !== '') {
        out('----------------------------------------');
        out('WebSocket 服务端日志（尾部）：');
        out($wsTail);
    }
    $exitCode = 1;
}
out('========================================');
out('');

// 把「网页安装向导测试」的报告也合并进 test-report.txt（如果之前跑过 test/install-test.php）
$mergedReport = $ROOT . '/test-report.txt';
if (is_file($mergedReport)) {
    $prev = (string) @file_get_contents($mergedReport);
    $marker = '  附：网页安装向导测试';
    $pos = strpos($prev, $marker);
    if ($pos !== false) {
        $cut = strrpos(substr($prev, 0, $pos), '========================================');
        if ($cut !== false) {
            $installPart = rtrim(substr($prev, $cut));
            $GLOBALS['report'][] = '';
            $GLOBALS['report'][] = $installPart;
        }
    }
}

@file_put_contents($REPORT_FILE, implode("\n", $GLOBALS['report']) . "\n");
@unlink($REST_LOG);
@unlink($WS_LOG);

exit($exitCode);
