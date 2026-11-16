<?php

namespace App\Services;

use Symfony\Component\HttpKernel\Exception\HttpException;

class TicketConflictException extends HttpException
{
    public function __construct(string $message = 'Dữ liệu yêu cầu sự cố bị xung đột hoặc đã được cập nhật bởi phiên khác.', int $code = 409)
    {
        parent::__construct($code, $message);
    }

    public function getStatusCode(): int
    {
        return 409;
    }
}
