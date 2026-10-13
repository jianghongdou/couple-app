<?php
/**
 * 网页安装向导端到端测试（php test/install-test.php）
 *
 * 覆盖：
 *   1. 没有 config.php 时 GET /api/health、/admin.php 不再 500，而是渲染安装向导
 *   2. GET /install.php 返回 200 且含表单（含环境自检区块）
 *   3. POST /install.php（正确数据库信息 + 后台密码）→ 成功页 + 生成 config.php
 *      → /api/health 200 → /admin.php 可用该密码登录 → /admin/api/system 正常
 *   4. 已安装后再 GET/POST /install.php → 403 锁定
 *   5. 数据库密码错误 → 中文可读错误提示，且不生成 config.php
 *   6. CSRF：缺少 token 的 POST 被拒绝
 *   7. 幂等：连续调用初始化 3 次不报错
 *   8. 环境自检项包含 PHP 版本 / pdo_mysql / curl / openssl / mbstring / 目录可写 / 数据库连接
 *
 * 运行： php test/install-test.php
 * 会使用独立测试库（默认 couple_test_install）与独立配置目录（test/install-config/），
 * 结束时全部删除；结果追加写入 server-php/test-report.txt（与 smoke.php 的报告合并）。
 *
 * 环境变量：TEST_DB_HOST / TEST_DB_PORT / TEST_DB_USER / TEST_DB_PASS（默认见下）
 */

$ROOT = dirname(__DIR__);
$PORT = 8897;
$BASE = 'http://127.0.0.1:' . $PORT;
$CFG_DIR = $ROOT . '/test/install-config';
$CFG_FILE = $CFG_DIR . '/config.php';
$REPORT_FILE = $ROOT . '/test-report.txt';
$REST_LOG = $ROOT . '/test/install-server.log';

$DB_HOST = getenv('TEST_DB_HOST') ?: '127.0.0.1';
$DB_PORT = (int) (getenv('TEST_DB_PORT') ?: '3307');
$DB_USER = getenv('TEST_DB_USER') ?: 'couple';
$DB_PASS = getenv('TEST_DB_PASS') ?: 'couplepass123';
$DB_NAME = 'couple_test_install';
$DB_CHARSET = 'utf8mb4';

$ADMIN_PASSWORD = 'install123';
$SITE_URL = 'https://love.example.com';

$GLOBALS['passed'] = 0;
$GLOBALS['failed'] = 0;
$GLOBALS['failures'] = [];
$GLOBALS['report'] = [];

// ---------------------------------------------------------------------------
// 迷你测试框架（与 smoke.php 一致）
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

// ---------------------------------------------------------------------------
// HTTP 客户端（自行保存 Set-Cookie：安装向导用 session 保存 CSRF）
//
// 不用 CURLOPT_COOKIEJAR —— 某些 Windows / 受限环境下该选项不会落盘，
// 这里直接把服务端下发的 Cookie 记在内存里，下一次请求原样带回，行为更可控。
// ---------------------------------------------------------------------------
$GLOBALS['COOKIE_BOX'] = [];

function cookieHeader()
{
    $parts = [];
    foreach ($GLOBALS['COOKIE_BOX'] as $k => $v) {
        $parts[] = $k . '=' . $v;
    }
    return implode('; ', $parts);
}

function storeCookies(array $respHeaders)
{
    if (!isset($respHeaders['set-cookie'])) {
        return;
    }
    $raw = $respHeaders['set-cookie'];
    $cookies = is_array($raw) ? $raw : [$raw];
    foreach ($cookies as $line) {
        $pair = trim(explode(';', $line)[0]);
        $eq = strpos($pair, '=');
        if ($eq === false) {
            continue;
        }
        $name = trim(substr($pair, 0, $eq));
        $value = trim(substr($pair, $eq + 1));
        if ($name === '') {
            continue;
        }
        if ($value === '' || stripos($value, 'deleted') !== false) {
            unset($GLOBALS['COOKIE_BOX'][$name]);
        } else {
            $GLOBALS['COOKIE_BOX'][$name] = $value;
        }
    }
}

function req($method, $path, $opts = [])
{
    global $BASE;
    $url = (strpos($path, 'http') === 0) ? $path : $BASE . $path;
    $headers = [];
    $ch = curl_init($url);
    $post = null;
    if (isset($opts['form'])) {
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $post = http_build_query($opts['form']);
    } elseif (array_key_exists('body', $opts) && $opts['body'] !== null) {
        $headers[] = 'Content-Type: application/json';
        $post = json_encode($opts['body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    if (!empty($opts['token'])) {
        $headers[] = 'Authorization: Bearer ' . $opts['token'];
    }
    if (!empty($opts['accept'])) {
        $headers[] = 'Accept: ' . $opts['accept'];
    }
    $cookie = cookieHeader();
    if ($cookie !== '') {
        $headers[] = 'Cookie: ' . $cookie;
    }

    $respHeaders = [];
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$respHeaders) {
            $p = strpos($line, ':');
            if ($p !== false) {
                $name = strtolower(trim(substr($line, 0, $p)));
                $value = trim(substr($line, $p + 1));
                // Set-Cookie 可能出现多次，累积成数组
                if ($name === 'set-cookie' && isset($respHeaders[$name])) {
                    $respHeaders[$name] = (array) $respHeaders[$name];
                    $respHeaders[$name][] = $value;
                } else {
                    $respHeaders[$name] = $value;
                }
            }
            return strlen($line);
        },
    ]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    }
    $text = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($text === false) {
        $text = '';
        $status = -1;
    }
    storeCookies($respHeaders);
    return [
        'status' => $status,
        'text' => $text,
        'headers' => $respHeaders,
        'json' => (isset($respHeaders['content-type']) && strpos($respHeaders['content-type'], 'application/json') !== false)
            ? json_decode($text, true) : null,
    ];
}

/** 从安装表单里取出 CSRF token */
function extractCsrf($html)
{
    if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $m)) {
        return $m[1];
    }
    return null;
}

// ---------------------------------------------------------------------------
// 进程 / 文件辅助
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
    return is_resource($proc) ? $proc : null;
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

function dbConnect($withDb = false)
{
    global $DB_HOST, $DB_PORT, $DB_NAME, $DB_USER, $DB_PASS, $DB_CHARSET;
    $dsn = 'mysql:host=' . $DB_HOST . ';port=' . $DB_PORT . ($withDb ? ';dbname=' . $DB_NAME : '') . ';charset=' . $DB_CHARSET;
    return new PDO($dsn, $DB_USER, $DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}

/**
 * 在独立 PHP 进程里跑一次完整启动（Bootstrap::boot → 建表 → 迁移 → 默认设置 → 公共表情同步），
 * 用于验证「连续启动 N 次」的幂等性。
 *
 * @return array{ok:bool,error:string,output:string}
 */
function runInitInChildProcess($configFile, $round = 0)
{
    global $ROOT, $DB_HOST, $DB_PORT, $DB_NAME, $DB_USER, $DB_PASS;
    $code = <<<'PHP'
require $argv[1] . '/src/Bootstrap.php';
App\Bootstrap::registerAutoloader();
App\Bootstrap::boot();
$stats = App\Db::getStats();
echo "INIT_OK tables=" . count(App\Db::tableColumns('users')) . " schema=" . json_encode(App\Db::getSetting('schema_version'));
PHP;
    $tmpScript = $ROOT . '/test/.init-round' . (int) $round . '.php';
    @file_put_contents($tmpScript, "<?php\n" . $code . "\n");
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tmpScript) . ' ' . escapeshellarg($ROOT) . ' 2>&1';
    $env = childEnv([
        'COUPLE_CONFIG_FILE' => $configFile,
        'DB_HOST' => $DB_HOST, 'DB_PORT' => (string) $DB_PORT,
        'DB_NAME' => $DB_NAME, 'DB_USER' => $DB_USER, 'DB_PASS' => $DB_PASS,
        'DATA_DIR' => './data-install-test',
        'COUPLE_SKIP_PUBLIC_EMOJI' => '1',
    ]);
    $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $pipes = [];
    $p = proc_open($cmd, $desc, $pipes, $ROOT, $env);
    $output = '';
    if (is_resource($p)) {
        $output = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($p);
    }
    @unlink($tmpScript);
    return [
        'ok' => strpos($output, 'INIT_OK') !== false,
        'error' => strpos($output, 'INIT_OK') !== false ? '' : trim(substr($output, 0, 400)),
        'output' => $output,
    ];
}

// ===========================================================================
// 主流程
// ===========================================================================
out('========================================');
out('  情侣 App 服务端 · PHP 版安装向导测试');
out('========================================');
out('PHP ' . PHP_VERSION . ' · ' . PHP_OS);
out('测试端口 ' . $PORT . ' · 测试库 ' . $DB_NAME . ' · 配置目录 test/install-config');
out('数据库 ' . $DB_HOST . ':' . $DB_PORT . '（用户 ' . $DB_USER . '）');

// 从零开始
$dropped = false;
try {
    $tmp = dbConnect(false);
    $tmp->exec('DROP DATABASE IF EXISTS `' . $DB_NAME . '`');
    $dropped = true;
} catch (PDOException $e) {
    out('❌ 无法连接 MySQL：' . $e->getMessage());
    out('   请确认数据库服务已启动（' . $DB_HOST . ':' . $DB_PORT . '）。');
    @file_put_contents($REPORT_FILE, implode("\n", $GLOBALS['report']) . "\n");
    exit(1);
}
removeDir($CFG_DIR);
@mkdir($CFG_DIR, 0775, true);
@unlink($REST_LOG);
out('测试库已删除（将从零安装）： ' . $DB_NAME);

$env = childEnv([
    // COUPLE_CONFIG_FILE 指向独立目录：不碰仓库根目录的 config.php
    'COUPLE_CONFIG_FILE' => $CFG_FILE,
    // 安装向导的默认值（模拟用户在表单里填写；测试里用同值覆盖来验证不同场景）
    'COUPLE_INSTALL_DEFAULTS' => json_encode([
        'db_host' => $DB_HOST,
        'db_port' => (string) $DB_PORT,
        'db_name' => $DB_NAME,
        'db_user' => $DB_USER,
        'db_pass' => $DB_PASS,
    ], JSON_UNESCAPED_UNICODE),
    // 注意：这里**故意不设** DB_HOST/DB_PORT/DB_NAME/DB_USER/DB_PASS 环境变量。
    // 那些变量会被 Config 当作环境覆盖，从而把「安装向导写完 config.php 后
    // 必须重载配置」这类 bug 掩盖掉（曾经真的漏了一个：Db::init() 读到启动时
    // 缓存的空 db_user，报「MySQL 用户名不能为空」）。数据库信息只允许来自表单提交。
    'DATA_DIR' => './data-install-test',
    'PORT' => (string) $PORT,
    'TIMEZONE' => 'Asia/Shanghai',
    'LOG_LEVEL' => 'info',
    'TRUST_PROXY' => '0',
    'JWT_SECRET' => '',
    'ADMIN_PASSWORD' => '',
]);

$proc = spawnProcess(
    [PHP_BINARY, '-S', '127.0.0.1:' . $PORT, '-t', $ROOT . '/public'],
    $env,
    $REST_LOG,
    $ROOT
);
if ($proc === null) {
    out('❌ 无法启动测试服务进程');
    @file_put_contents($REPORT_FILE, implode("\n", $GLOBALS['report']) . "\n");
    exit(1);
}
if (!waitForTcp($PORT, 20)) {
    out('❌ 等待服务启动超时');
    out(tailFile($REST_LOG, 30));
    stopProcess($proc);
    @file_put_contents($REPORT_FILE, implode("\n", $GLOBALS['report']) . "\n");
    exit(1);
}
out('测试服务已启动（' . $BASE . '），开始断言…');

$exitCode = 1;
try {
    // =====================================================================
    section('1. 未安装时：不再 500，而是引导到安装向导');
    // =====================================================================
    {
        ok(!is_file($CFG_FILE), '前置条件：config.php 不存在');

        $health = req('GET', '/api/health', ['accept' => 'text/html']);
        ok($health['status'] === 503, 'GET /api/health 未安装时返回 503（不是 500）', '实际 ' . $health['status']);
        ok(strpos($health['text'], '安装向导') !== false, '/api/health 渲染安装向导页面');
        ok(strpos($health['text'], '尚未完成安装') !== false, '/api/health 页面含「尚未完成安装」提示');
        ok(strpos($health['text'], '<form') !== false, '/api/health 页面上就有安装表单');

        $healthJson = req('GET', '/api/health');
        ok($healthJson['status'] === 503, '非 HTML 请求（iOS 客户端）返回 503', '实际 ' . $healthJson['status']);
        ok(is_array($healthJson['json']) && $healthJson['json']['error'] === 'db_unavailable',
            'API 请求返回结构化 JSON 错误 db_unavailable',
            json_encode($healthJson['json']));
        ok(is_int($healthJson['json']['serverTime']) || isset($healthJson['json']['serverTime']),
            'JSON 错误响应仍带 serverTime');

        $admin = req('GET', '/admin.php', ['accept' => 'text/html']);
        ok($admin['status'] === 503, 'GET /admin.php 未安装时返回 503（不是 500）', '实际 ' . $admin['status']);
        ok(strpos($admin['text'], '安装向导') !== false, '/admin.php 渲染安装向导页面');

        $meta = req('GET', '/api/meta', ['accept' => 'text/html']);
        ok($meta['status'] === 503 && strpos($meta['text'], '安装向导') !== false,
            '/api/meta 同样引导到安装向导', '实际 ' . $meta['status']);
    }

    // =====================================================================
    section('2. GET /install.php 渲染表单与环境自检');
    // =====================================================================
    $csrf = null;
    {
        $page = req('GET', '/install.php', ['accept' => 'text/html']);
        ok($page['status'] === 200, 'GET /install.php 返回 200', '实际 ' . $page['status']);
        ok(stripos((string) $page['headers']['content-type'], 'text/html') === 0, 'Content-Type 为 text/html');
        ok(strpos($page['text'], '<form') !== false, '页面含安装表单');
        ok(strpos($page['text'], 'name="db_host"') !== false, '表单含数据库主机字段');
        ok(strpos($page['text'], 'name="db_port"') !== false, '表单含数据库端口字段');
        ok(strpos($page['text'], 'name="db_name"') !== false, '表单含数据库名字段');
        ok(strpos($page['text'], 'name="db_user"') !== false, '表单含数据库用户名字段');
        ok(strpos($page['text'], 'name="db_pass"') !== false, '表单含数据库密码字段');
        ok(strpos($page['text'], 'name="auto_create"') !== false, '表单含「自动建库」勾选项');
        ok(strpos($page['text'], 'name="admin_password"') !== false, '表单含后台密码字段');
        ok(strpos($page['text'], 'name="admin_password2"') !== false, '表单含后台确认密码字段');
        ok(strpos($page['text'], 'name="public_base_url"') !== false, '表单含站点地址字段（可选）');
        ok(strpos($page['text'], 'viewport') !== false, '页面含 viewport（手机可看）');

        // 环境自检区块
        ok(strpos($page['text'], '环境自检') !== false, '页面含「环境自检」区块');
        ok(strpos($page['text'], 'PHP 版本') !== false, '自检项含 PHP 版本');
        ok(strpos($page['text'], PHP_VERSION) !== false, '自检项显示真实 PHP 版本 ' . PHP_VERSION);
        ok(strpos($page['text'], 'pdo_mysql') !== false, '自检项含 pdo_mysql');
        ok(strpos($page['text'], 'curl') !== false, '自检项含 curl');
        ok(strpos($page['text'], 'openssl') !== false, '自检项含 openssl');
        ok(strpos($page['text'], 'mbstring') !== false, '自检项含 mbstring');
        ok(strpos($page['text'], '代码目录可写') !== false, '自检项含「代码目录可写」');
        ok(strpos($page['text'], '数据库连接') !== false, '自检项含数据库连接测试');
        ok(strpos($page['text'], 'chown -R www:www') === false || strpos($page['text'], '不可写') !== false,
            '目录不可写时才提示 chown 命令');

        // jwt_secret 由程序生成，不需要用户填写
        ok(strpos($page['text'], 'name="jwt_secret"') === false, '表单不要求填写 jwt_secret（自动生成）');
        ok(strpos($page['text'], $DB_PASS) === false, '页面不回显数据库密码');

        $csrf = extractCsrf($page['text']);
        ok(is_string($csrf) && strlen($csrf) >= 32, '表单含 CSRF token', (string) $csrf);
    }

    // =====================================================================
    section('3. 错误场景：CSRF 缺失 / 数据库密码错误（都不生成 config.php）');
    // =====================================================================
    {
        $noCsrf = req('POST', '/install.php', ['form' => [
            'db_host' => $DB_HOST,
            'db_port' => (string) $DB_PORT,
            'db_name' => $DB_NAME,
            'db_user' => $DB_USER,
            'db_pass' => $DB_PASS,
            'admin_password' => $ADMIN_PASSWORD,
            'admin_password2' => $ADMIN_PASSWORD,
        ]]);
        ok($noCsrf['status'] === 200, '缺少 CSRF token 的 POST 被拒（返回表单页）', '实际 ' . $noCsrf['status']);
        ok(strpos($noCsrf['text'], 'CSRF') !== false, '缺少 CSRF 时给出中文提示');
        ok(!is_file($CFG_FILE), '缺少 CSRF 时不会生成 config.php');

        $wrongPass = req('POST', '/install.php', ['form' => [
            'csrf_token' => $csrf,
            'db_host' => $DB_HOST,
            'db_port' => (string) $DB_PORT,
            'db_name' => $DB_NAME,
            'db_user' => $DB_USER,
            'db_pass' => 'definitely-wrong-password',
            'auto_create' => '1',
            'admin_password' => $ADMIN_PASSWORD,
            'admin_password2' => $ADMIN_PASSWORD,
        ]]);
        ok($wrongPass['status'] === 200, '数据库密码错误时返回表单页（不 500）', '实际 ' . $wrongPass['status']);
        ok(strpos($wrongPass['text'], '数据库账号或密码错误') !== false,
            '错误提示为可读中文（账号或密码错误）');
        ok(strpos($wrongPass['text'], 'definitely-wrong-password') === false,
            '错误提示不回显填写的数据库密码');
        ok(!is_file($CFG_FILE), '数据库密码错误时不生成 config.php');
        ok(strpos($wrongPass['text'], 'name="csrf_token"') !== false, '失败后表单仍带 CSRF token');

        // 密码不一致
        $mismatch = req('POST', '/install.php', ['form' => [
            'csrf_token' => $csrf,
            'db_host' => $DB_HOST,
            'db_port' => (string) $DB_PORT,
            'db_name' => $DB_NAME,
            'db_user' => $DB_USER,
            'db_pass' => $DB_PASS,
            'admin_password' => 'abcdef',
            'admin_password2' => 'abcdefg',
        ]]);
        ok(strpos($mismatch['text'], '两次输入的后台管理密码不一致') !== false, '两次密码不一致时中文报错');
        ok(!is_file($CFG_FILE), '两次密码不一致时不生成 config.php');

        // 后台密码过短
        $shortPwd = req('POST', '/install.php', ['form' => [
            'csrf_token' => $csrf,
            'db_host' => $DB_HOST,
            'db_port' => (string) $DB_PORT,
            'db_name' => $DB_NAME,
            'db_user' => $DB_USER,
            'db_pass' => $DB_PASS,
            'admin_password' => '123',
            'admin_password2' => '123',
        ]]);
        ok(strpos($shortPwd['text'], '至少 6 位') !== false, '后台密码过短时中文报错（至少 6 位）');
        ok(!is_file($CFG_FILE), '后台密码过短时不生成 config.php');
    }

    // =====================================================================
    section('4. 正式安装：自动建库 + 建表 + 生成 config.php');
    // =====================================================================
    $createdConfig = false;
    {
        $probe = new PDO('mysql:host=' . $DB_HOST . ';port=' . $DB_PORT, $DB_USER, $DB_PASS);
        $exists = $probe->query("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '" . $DB_NAME . "'")->fetchColumn();
        ok($exists === false, '安装前：数据库 ' . $DB_NAME . ' 不存在');
        $probe = null;

        $install = req('POST', '/install.php', ['form' => [
            'csrf_token' => $csrf,
            'db_host' => $DB_HOST,
            'db_port' => (string) $DB_PORT,
            'db_name' => $DB_NAME,
            'db_user' => $DB_USER,
            'db_pass' => $DB_PASS,
            'auto_create' => '1',
            'admin_password' => $ADMIN_PASSWORD,
            'admin_password2' => $ADMIN_PASSWORD,
            'public_base_url' => $SITE_URL,
            'timezone' => 'Asia/Shanghai',
        ]]);
        ok($install['status'] === 200, 'POST /install.php 返回 200', '实际 ' . $install['status']);
        ok(strpos($install['text'], '安装完成') !== false, '成功页显示「安装完成」');
        ok(strpos($install['text'], '/admin.php') !== false, '成功页给出后台地址 /admin.php');
        ok(strpos($install['text'], '/api/health') !== false, '成功页给出 /api/health 自检链接');
        ok(strpos($install['text'], 'install.php') !== false, '成功页提示下一步（install.php 可删除/忽略）');
        ok(strpos($install['text'], 'utf8mb4') !== false, '成功页提示已按 utf8mb4 建库');

        $createdConfig = is_file($CFG_FILE);
        ok($createdConfig, 'config.php 已生成', $CFG_FILE);

        // 自动建库 + 建表
        $check = dbConnect(true);
        $tables = $check->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        sort($tables);
        ok(in_array('users', $tables, true) && in_array('couples', $tables, true)
            && in_array('messages', $tables, true) && in_array('settings', $tables, true),
            '数据库与核心表已创建（' . count($tables) . ' 张表）', implode(',', $tables));
        ok(count($tables) === 11, '建表数量完整（users/couples/messages/emoji_packs/emoji_items/'
            . 'sync_objects/settings/ai_usage/admin_logs/rate_limits/ws_events = 11 张）', count($tables) . ' 张');
        $settingsCount = (int) $check->query('SELECT COUNT(*) FROM settings')->fetchColumn();
        ok($settingsCount > 0, '默认 settings 已写入（' . $settingsCount . ' 项）');
        $schemaVersion = $check->query("SELECT value FROM settings WHERE `key` = 'schema_version'")->fetchColumn();
        ok((int) $schemaVersion >= 1, '已写入 schema_version', (string) $schemaVersion);

        // config.php 内容
        $src = (string) file_get_contents($CFG_FILE);
        ok(strpos($src, "'db_host'") !== false && strpos($src, "'db_name'") !== false, 'config.php 含 db_* 配置');
        ok(strpos($src, "=> '" . $DB_NAME . "'") !== false, 'config.php 的 db_name 正确');
        ok(strpos($src, "'jwt_secret'") !== false, 'config.php 含 jwt_secret');
        if (preg_match("/'jwt_secret'\s*=>\s*'([0-9a-f]+)'/", $src, $m)) {
            ok(strlen($m[1]) >= 32, 'jwt_secret 为自动生成的随机串（长度 ' . strlen($m[1]) . '）');
        } else {
            ok(false, 'jwt_secret 为自动生成的随机串');
        }
        if (preg_match("/'admin_password'\s*=>\s*'([^']+)'/", $src, $m)) {
            ok($m[1] === $ADMIN_PASSWORD, 'config.php 的后台密码与填写的一致');
        } else {
            ok(false, 'config.php 的后台密码与填写的一致');
        }
        ok(strpos($src, "public_base_url' => '" . $SITE_URL . "'") !== false,
            'config.php 写入站点地址（用于表情绝对地址）');
        ok(strpos($src, "'timezone'") !== false && strpos($src, "'ws_port'") !== false,
            'config.php 含 timezone / ws_port 等运行参数');
        ok(strpos($src, 'install') === false || strpos($src, '<?php') === 0, 'config.php 是合法 PHP 文件');

        $loaded = @include $CFG_FILE;
        ok(is_array($loaded) && $loaded['db_name'] === $DB_NAME && $loaded['db_host'] === $DB_HOST,
            'config.php 可被 PHP 正常载入且内容正确');

        // 文件权限（Windows 下 chmod 语义不同，只做尽力而为的提示）
        if (stripos(PHP_OS, 'WIN') !== 0) {
            $perms = substr(sprintf('%o', fileperms($CFG_FILE)), -4);
            ok($perms === '0640' || $perms === '0644', 'config.php 权限为 ' . $perms . '（尽量 0640）', $perms);
        } else {
            ok(true, 'config.php 权限检查跳过（Windows）');
        }
    }

    // =====================================================================
    section('5. 安装后：API 与后台可用');
    // =====================================================================
    $adminToken = null;
    {
        $health = req('GET', '/api/health');
        ok($health['status'] === 200, '安装后 GET /api/health 返回 200', '实际 ' . $health['status']);
        ok(is_array($health['json']) && $health['json']['ok'] === true, 'health.ok 为 true');
        ok($health['json']['name'] === 'couple-server' && $health['json']['version'] === '1.0.0',
            'health 契约字段不变（name/version）');

        $meta = req('GET', '/api/meta');
        ok($meta['status'] === 200 && $meta['json']['appName'] === '情侣空间', '安装后 /api/meta 正常');

        $status = req('GET', '/api/status');
        ok($status['status'] === 200 && $status['json']['dbOk'] === true, '安装后 /api/status 数据库正常');

        $adminPage = req('GET', '/admin.php');
        ok($adminPage['status'] === 200 && strpos($adminPage['text'], '情侣空间') !== false,
            'GET /admin.php 返回后台页面', '实际 ' . $adminPage['status']);

        $badLogin = req('POST', '/admin/api/login', ['body' => ['password' => 'wrong-password']]);
        ok($badLogin['status'] === 401, '错误密码登录后台返回 401', '实际 ' . $badLogin['status']);

        $login = req('POST', '/admin/api/login', ['body' => ['password' => $ADMIN_PASSWORD]]);
        ok($login['status'] === 200, '用安装时设置的后台密码登录成功', '实际 ' . $login['status']);
        $adminToken = isset($login['json']['token']) ? $login['json']['token'] : null;
        ok(is_string($adminToken) && strlen($adminToken) > 20, '登录返回后台 token');

        $sys = req('GET', '/admin/api/system', ['token' => $adminToken]);
        ok($sys['status'] === 200, 'GET /admin/api/system 返回 200', '实际 ' . $sys['status']);
        ok(isset($sys['json']['database']['version']) && $sys['json']['database']['version'] !== '',
            '系统信息含数据库版本', json_encode(isset($sys['json']['database']) ? $sys['json']['database'] : null));
        ok($sys['json']['database']['connected'] === true, '系统信息显示数据库连接正常');
        ok($sys['json']['database']['name'] === $DB_NAME, '系统信息显示正确的数据库名');
        ok($sys['json']['jwtSecretConfigured'] === true, '系统信息显示 JWT 密钥已配置');
        ok(isset($sys['json']['env']['dataDir']) && $sys['json']['env']['dataDir'] !== '', '系统信息含数据目录');
        ok(isset($sys['json']['timezone']) && $sys['json']['timezone'] !== '', '系统信息含时区');
    }

    // =====================================================================
    section('6. 已安装锁定：再访问安装向导一律 403');
    // =====================================================================
    {
        $get = req('GET', '/install.php', ['accept' => 'text/html']);
        ok($get['status'] === 403, '已安装后 GET /install.php 返回 403', '实际 ' . $get['status']);
        ok(strpos($get['text'], '已安装') !== false && strpos($get['text'], '删除') !== false,
            '403 页面提示「已安装。如需重装请先删除 config.php」');
        ok(strpos($get['text'], 'name="db_pass"') === false, '403 页面不再输出安装表单');

        $postAgain = req('POST', '/install.php', ['form' => [
            'csrf_token' => $csrf,
            'db_host' => '127.0.0.1',
            'db_port' => '3306',
            'db_name' => 'hacked',
            'db_user' => 'root',
            'db_pass' => 'rootpass123',
            'auto_create' => '1',
            'admin_password' => 'hacked123',
            'admin_password2' => 'hacked123',
        ]]);
        ok($postAgain['status'] === 403, '已安装后 POST /install.php 也返回 403', '实际 ' . $postAgain['status']);
        ok(strpos($postAgain['text'], '已安装') !== false, 'POST 被拒时同样给出中文提示');

        // 确认没有被改写
        $check = dbConnect(true);
        $still = $check->query("SELECT value FROM settings WHERE `key` = 'schema_version'")->fetchColumn();
        ok((int) $still >= 1, '已有数据库未被重装请求破坏');
        $src = (string) file_get_contents($CFG_FILE);
        ok(strpos($src, "=> '" . $DB_NAME . "'") !== false, 'config.php 未被重装请求覆盖');
    }

    // =====================================================================
    section('7. 幂等：连续初始化 3 次不报错、不丢表、不产生脏数据');
    // =====================================================================
    {
        $okAll = true;
        $detail = '';
        $runs = [];
        for ($i = 1; $i <= 3; $i++) {
            $r = runInitInChildProcess($CFG_FILE, $i);
            $runs[] = 'run' . $i . '=' . ($r['ok'] ? 'ok' : 'FAIL');
            if (!$r['ok']) {
                $okAll = false;
                $detail = '第 ' . $i . ' 次：' . $r['error'];
                break;
            }
        }
        ok($okAll, '连续调用启动/初始化 3 次均成功（幂等）', $detail . ' ' . implode(' ', $runs));
        $check = dbConnect(true);
        $tables = $check->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        ok(count($tables) === 11, '幂等执行后表数量不变（' . count($tables) . ' 张）');
        $usersCount = (int) $check->query('SELECT COUNT(*) FROM users')->fetchColumn();
        ok($usersCount === 0, '幂等执行不产生脏数据（users 仍为空）');
        $settingsCount = (int) $check->query('SELECT COUNT(*) FROM settings')->fetchColumn();
        ok($settingsCount > 0, '幂等执行后默认设置仍在（' . $settingsCount . ' 项）');
    }

    // =====================================================================
    section('8. 命令行自检（php bin/console.php check）');
    // =====================================================================
    {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($ROOT . '/bin/console.php') . ' check 2>&1';
        $env2 = childEnv([
            'COUPLE_CONFIG_FILE' => $CFG_FILE,
            'DB_HOST' => $DB_HOST, 'DB_PORT' => (string) $DB_PORT,
            'DB_NAME' => $DB_NAME, 'DB_USER' => $DB_USER, 'DB_PASS' => $DB_PASS,
            'DATA_DIR' => './data-install-test',
        ]);
        $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $pipes = [];
        $p = proc_open($cmd, $desc, $pipes, $ROOT, $env2);
        $outText = '';
        if (is_resource($p)) {
            $outText = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
            fclose($pipes[0]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($p);
        }
        ok(strpos($outText, '配置检查通过') !== false, 'console.php check 输出「配置检查通过」', $outText);
        ok(strpos($outText, 'MariaDB') !== false || strpos($outText, 'MySQL') !== false,
            'console.php check 显示数据库类型');
        ok(strpos($outText, '10.11') !== false || preg_match('/\d+\.\d+\.\d+/', $outText) === 1,
            'console.php check 显示数据库版本', $outText);
        ok(strpos($outText, '连接状态') !== false, 'console.php check 显示连接状态');
    }
} catch (\Throwable $e) {
    $GLOBALS['failed']++;
    $GLOBALS['failures'][] = '测试过程中抛出异常：' . $e->getMessage();
    out('');
    out('❌ 测试异常：' . $e->getMessage());
    out($e->getFile() . ':' . $e->getLine());
}

// ===========================================================================
// 清理
// ===========================================================================
out('');
out('----------------------------------------');
out('清理：停止服务、删除测试库与测试配置目录');

stopProcess($proc);

$dropped = false;
try {
    $tmp = dbConnect(false);
    $tmp->exec('DROP DATABASE IF EXISTS `' . $DB_NAME . '`');
    $dropped = true;
} catch (PDOException $e) {
    $dropped = false;
}
removeDir($CFG_DIR);
removeDir($ROOT . '/data-install-test');
@unlink($REST_LOG);

out('测试库已删除： ' . ($dropped ? $DB_NAME : $DB_NAME . '（删除失败，请手动清理）'));
out('测试配置目录已删除： test/install-config');

$passed = $GLOBALS['passed'];
$failed = $GLOBALS['failed'];
out('');
out('========================================');
if ($failed === 0) {
    out('  ✅ ALL INSTALL TESTS PASSED（通过 ' . $passed . ' 项断言）');
    $exitCode = 0;
} else {
    out('  ❌ 失败 ' . $failed . ' 项 / 通过 ' . $passed . ' 项');
    out('----------------------------------------');
    foreach ($GLOBALS['failures'] as $f) {
        out('  · ' . $f);
    }
    $tail = tailFile($REST_LOG, 30);
    if (trim($tail) !== '') {
        out('----------------------------------------');
        out('服务端日志（尾部）：');
        out($tail);
    }
    $exitCode = 1;
}
out('========================================');
out('');

// 报告追加写入 test-report.txt（smoke.php 先跑则完整覆盖，本项目 389+ 断言的两份证据都保留）
// 写法：先删掉上一个「安装向导报告」区块，再追加本次结果，避免重复堆积。
function writeInstallReport($file, array $lines)
{
    $sep = "========================================\n  附：网页安装向导测试（php test/install-test.php）\n========================================\n";
    $marker = '  附：网页安装向导测试';
    $text = is_file($file) ? (string) @file_get_contents($file) : '';
    if ($text !== '') {
        $pos = strpos($text, $marker);
        if ($pos !== false) {
            $cut = strrpos(substr($text, 0, $pos), '========================================');
            if ($cut !== false) {
                $text = rtrim(substr($text, 0, $cut)) . "\n";
            }
        }
    }
    $text .= "\n" . $sep . implode("\n", $lines) . "\n";
    @file_put_contents($file, $text);
}

writeInstallReport($REPORT_FILE, $GLOBALS['report']);
exit($exitCode);
