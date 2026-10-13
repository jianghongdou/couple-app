<?php
namespace App;

/**
 * AI 调用异常（携带 HTTP 状态与错误码）
 */
class AiError extends \RuntimeException
{
    /** @var string */
    private $errorCode;
    /** @var int */
    private $status;

    public function __construct($message, $errorCode = 'server_error', $status = 500)
    {
        parent::__construct($message);
        $this->errorCode = $errorCode;
        $this->status = (int) $status;
    }

    public function getErrorCode()
    {
        return $this->errorCode;
    }

    public function getStatus()
    {
        return $this->status;
    }
}
