<?php
namespace App;

/**
 * 极简路由器：按「路径段」匹配，支持 :name 占位符。
 *
 * 之所以按段匹配（而不是拼成一整条正则），是为了让 /api/emoji/items/e_..%2F..%2Fdb
 * 这样的请求把「解码后的整段」原样交给业务层，由业务层用 ^e_[A-Za-z0-9]+$ 校验并
 * 返回 400 invalid_input —— 也就是 Node 版的行为。
 */
class Router
{
    /** @var array<int,array{method:string,segs:array<int,string>,handler:callable,pattern:string}> */
    private $routes = [];

    public function add($method, $pattern, callable $handler)
    {
        $segs = [];
        foreach (explode('/', $pattern) as $s) {
            if ($s !== '') {
                $segs[] = $s;
            }
        }
        $this->routes[] = [
            'method' => strtoupper($method),
            'segs' => $segs,
            'handler' => $handler,
            'pattern' => $pattern,
        ];
        return $this;
    }

    public function get($pattern, callable $handler)
    {
        return $this->add('GET', $pattern, $handler);
    }

    public function post($pattern, callable $handler)
    {
        return $this->add('POST', $pattern, $handler);
    }

    public function put($pattern, callable $handler)
    {
        return $this->add('PUT', $pattern, $handler);
    }

    public function patch($pattern, callable $handler)
    {
        return $this->add('PATCH', $pattern, $handler);
    }

    public function delete($pattern, callable $handler)
    {
        return $this->add('DELETE', $pattern, $handler);
    }

    /**
     * 执行分发
     * @return bool 是否命中路由
     */
    public function dispatch()
    {
        $method = Http::method();
        if ($method === 'HEAD') {
            $method = 'GET';
        }
        $segments = Http::segments();

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }
            $params = $this->matchSegments($route['segs'], $segments);
            if ($params === null) {
                continue;
            }
            $handler = $route['handler'];
            $handler($params);
            return true;
        }
        return false;
    }

    /**
     * @return array<string,string>|null 匹配成功返回参数表，失败返回 null
     */
    private function matchSegments(array $pattern, array $segments)
    {
        if (count($pattern) !== count($segments)) {
            return null;
        }
        $params = [];
        foreach ($pattern as $i => $p) {
            $value = $segments[$i];
            if (strlen($p) > 0 && $p[0] === ':') {
                $params[substr($p, 1)] = $value;
                continue;
            }
            if ($p !== $value) {
                return null;
            }
        }
        return $params;
    }
}
