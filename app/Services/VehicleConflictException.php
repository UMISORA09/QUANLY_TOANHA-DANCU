<?php

namespace App\Services;

use Exception;

class VehicleConflictException extends Exception
{
    public function __construct(string $message = 'Dữ liệu phương tiện bị xung đột hoặc trùng lặp.', int $code = 409)
    {
        parent::__construct($message, $code);
    }

    public function getStatusCode(): int
    {
        return 409;
    }
}
