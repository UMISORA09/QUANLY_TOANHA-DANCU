<?php

namespace App\Services;

use Exception;

class AccessCardNotFoundException extends Exception
{
    public function __construct(string $message = 'Thẻ RFID không tồn tại trong hệ thống.', int $code = 404)
    {
        parent::__construct($message, $code);
    }

    public function getStatusCode(): int
    {
        return 404;
    }
}
