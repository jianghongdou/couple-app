<?php
namespace App;

/**
 * HTTP 请求解析与响应输出。
 *
 * 路由路径解析同时支持两种访问形式：
 *   伪静态：   /api/auth/login
 *   兜底形式： /index.php/api/auth/login
 * 优先使用「原始 REQUEST_URI」而不是 PATH_INFO，因为内置服务器/nginx 会把
 * 路径里的 ..%2F 归一化掉；用原始 URI 才能把「非法的表情 id」原样交给业务层
 * 校验并返回 400，而不是被服务器提前处理。
 */
class Http
{
    /** @var \stdClass|null */
    private static $bodyCache = null;
    private static $bodyParsed = false;
    /** @var array<int,string>|null */
    private static $segmentsCache = null;

    // ------------------------------------------------------------------
    // 请求
    // ------------------------------------------------------------------

    public static function method()
    {
        $m = isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';
        return $m;
    }

    /** 原始请求路径（不含查询串） */
    public static function rawPath()
    {
        $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/';
        $pos = strpos($uri, '?');
        if ($pos !== false) {
            $uri = substr($uri, 0, $pos);
        }
        if ($uri === '') {
            $uri = '/';
        }
        // 去掉可能存在的绝对地址前缀（部分代理会写成 http://host/path）
        if (preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*://[^/]*(/.*)$#', $uri, $m)) {
            $uri = $m[1];
        }
        return $uri;
    }

    /**
     * 路由段数组（每段已 rawurldecode）。
     * @return array<int,string>
     */
    public static function segments()
    {
        if (self::$segmentsCache !== null) {
            return self::$segmentsCache;
        }
        $path = self::rawPath();

        // 1) 后台接口：/admin/api/... （必须先于 /api/ 判断，避免被 /api/ 误截）
        // 2) 业务接口：/api/...
        if (preg_match('#(/admin/api(?:/.*)?)$#', $path, $m)) {
            $route = $m[1];
        } elseif (preg_match('#(/api(?:/.*)?)$#', $path, $m)) {
            $route = $m[1];
        } else {
            $route = $path;
            // 去掉脚本名（/index.php、/sub/index.php 之类）
            if (!preg_match('#^/(api|admin)#', $route)) {
                $stripped = preg_replace('#^.*\.php#', '', $route);
                $route = ($stripped === null || $stripped === '') ? '/' : $stripped;
            }
        }

        $parts = explode('/', trim($route, '/'));
        $out = [];
        foreach ($parts as $p) {
            if ($p === '') {
                continue;
            }
            $out[] = rawurldecode($p);
        }
        self::$segmentsCache = $out;
        return $out;
    }

    /** 归一化后的路由路径，仅用于日志与 404 文案 */
    public static function routePath()
    {
        $segs = self::segments();
        return count($segs) === 0 ? '/' : '/' . implode('/', $segs);
    }

    /** 查询参数（字符串或 null） */
    public static function query($name, $default = null)
    {
        if (!isset($_GET[$name])) {
            return $default;
        }
        $v = $_GET[$name];
        if (is_array($v)) {
            return $default;
        }
        return $v;
    }

    /** 请求头（大小写不敏感） */
    public static function header($name, $default = null)
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (isset($_SERVER[$key])) {
            return $_SERVER[$key];
        }
        // Content-Type / Content-Length 不走 HTTP_ 前缀
        $alt = strtoupper(str_replace('-', '_', $name));
        if (isset($_SERVER[$alt])) {
            return $_SERVER[$alt];
        }
        return $default;
    }

    public static function rawBody()
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        $cached = (string) file_get_contents('php://input');
        return $cached;
    }

    /**
     * 解析 JSON 请求体。
     * 与 Node 版 express.json 行为一致：
     *   - 空请求体 / 非 JSON Content-Type -> 空对象
     *   - JSON 语法错误 -> 400 invalid_input
     *   - 顶层可以是对象、数组或标量（业务层按需校验）
     *
     * @return mixed
     */
    public static function jsonBody()
    {
        if (self::$bodyParsed) {
            return self::$bodyCache === null ? new \stdClass() : self::$bodyCache;
        }
        self::$bodyParsed = true;

        $len = (int) self::header('Content-Length', 0);
        if ($len > Config::limit('BODY_MAX_BYTES')) {
            throw new HttpException(413, 'invalid_input', '请求体过大（上限 4MB）');
        }

        $raw = self::rawBody();
        if ($raw === '') {
            self::$bodyCache = new \stdClass();
            return self::$bodyCache;
        }

        $ctype = strtolower((string) self::header('Content-Type', ''));
        if (strpos($ctype, 'application/json') === false && strpos($ctype, 'text/json') === false) {
            // 与 Node 版一致：非 JSON 请求体不解析
            self::$bodyCache = new \stdClass();
            return self::$bodyCache;
        }
        if (strlen($raw) > Config::limit('BODY_MAX_BYTES')) {
            throw new HttpException(413, 'invalid_input', '请求体过大（上限 4MB）');
        }

        $decoded = json_decode($raw);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new HttpException(400, 'invalid_input', '请求体不是合法的 JSON');
        }
        self::$bodyCache = $decoded;
        return self::$bodyCache;
    }

    /** 读取对象字段；不存在时返回默认值（数组与标量请求体一律视为没有该字段） */
    public static function field($obj, $name, $default = null)
    {
        if ($obj instanceof \stdClass && property_exists($obj, $name)) {
            return $obj->{$name};
        }
        if (is_array($obj) && array_key_exists($name, $obj)) {
            return $obj[$name];
        }
        return $default;
    }

    /** 字段是否存在（区分「不存在」与「显式 null」） */
    public static function has($obj, $name)
    {
        if ($obj instanceof \stdClass) {
            return property_exists($obj, $name);
        }
        return is_array($obj) && array_key_exists($name, $obj);
    }

    /**
     * 取出请求体的全部顶层字段（用于「请求体不能为空」这类判断）
     * @return array<string,mixed>
     */
    public static function fields($obj)
    {
        if ($obj instanceof \stdClass) {
            return get_object_vars($obj);
        }
        if (is_array($obj)) {
            return $obj;
        }
        return [];
    }

    /** 客户端 IP（TRUST_PROXY 打开时读 X-Forwarded-For 的第一段） */
    public static function clientIp()
    {
        if (Config::get('trust_proxy', true)) {
            $xff = self::header('X-Forwarded-For');
            if (is_string($xff) && $xff !== '') {
                $parts = explode(',', $xff);
                $first = trim($parts[0]);
                if ($first !== '') {
                    return $first;
                }
            }
            $real = self::header('X-Real-IP');
            if (is_string($real) && $real !== '') {
                return $real;
            }
        }
        return isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown';
    }

    /** 从 Authorization 头或 ?token= 中取 token（后台 token 只认请求头） */
    public static function bearerToken()
    {
        $header = (string) self::header('Authorization', '');
        if (stripos($header, 'Bearer ') === 0) {
            $t = trim(substr($header, 7));
            if ($t !== '') {
                return $t;
            }
        }
        return null;
    }

    public static function extractToken()
    {
        $t = self::bearerToken();
        if ($t !== null) {
            return $t;
        }
        $q = self::query('token');
        if (is_string($q) && $q !== '') {
            return $q;
        }
        return null;
    }

    // ------------------------------------------------------------------
    // 响应
    // ------------------------------------------------------------------

    public static function securityHeaders()
    {
        if (headers_sent()) {
            return;
        }
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
    }

    /**
     * 输出 JSON。
     * @param mixed $data
     */
    public static function json($data, $status = 200)
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            $path = self::rawPath();
            if (strpos($path, '/api/') !== false || strpos($path, '/admin/api/') !== false
                || preg_match('#/api$#', $path) || preg_match('#/admin/api$#', $path)) {
                header('Cache-Control: no-store');
            }
            self::securityHeaders();
        }
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        $out = json_encode($data, $flags);
        if ($out === false) {
            // 兜底：极端情况下（例如非法 UTF-8）也要返回结构化错误
            http_response_code(500);
            $out = '{"error":"server_error","message":"响应序列化失败"}';
        }
        echo $out;
        return $out;
    }

    /** 统一的成功响应：所有接口都带 serverTime（毫秒） */
    public static function ok(array $payload)
    {
        $payload['serverTime'] = Time::nowMs();
        return self::json($payload, 200);
    }

    /** 统一的错误响应 */
    public static function error($status, $errorCode, $message)
    {
        return self::json([
            'error' => $errorCode,
            'message' => $message,
            'serverTime' => Time::nowMs(),
        ], $status);
    }
}
