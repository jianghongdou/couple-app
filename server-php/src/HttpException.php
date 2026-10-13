<?php
namespace App;

/**
 * 带 HTTP 状态的业务异常，由统一错误处理器映射为 API.md 规定的错误码。
 */
class HttpException extends \RuntimeException
{
    /** @var int */
    private $status;
    /** @var string */
    private $errorCode;

    public function __construct($status, $errorCode, $message)
    {
        parent::__construct($message);
        $this->status = (int) $status;
        $this->errorCode = (string) $errorCode;
    }

    public function getStatus()
    {
        return $this->status;
    }

    public function getErrorCode()
    {
        return $this->errorCode;
    }
}
