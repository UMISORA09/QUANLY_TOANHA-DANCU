<?php

namespace App\Services;

use Exception;

class AccessCardConflictException extends Exception
{
    public function __construct(string $message = 'Mã thẻ UID hoặc số thẻ đã tồn tại trong hệ thống.', int $code = 409)
    {
        parent::__construct($message, $code);
    }

    public function getStatusCode(): int
    {
        return 409;
    }
}
