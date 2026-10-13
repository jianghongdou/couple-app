<?php
namespace App;

/**
 * 数据库连接/建库失败（中文、可操作）。
 *
 * message 给用户看，永远不包含数据库密码。
 */
class DbConnectionError extends \RuntimeException
{
    /** @var string 服务端日志用的原始错误（不会直接回显给浏览器） */
    private $detail;

    public function __construct($message, $detail = '')
    {
        parent::__construct($message);
        $this->detail = (string) $detail;
    }

    public function detail()
    {
        return $this->detail;
    }
}
