<?php

namespace App\Services;

class AmenityConflictException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Dữ liệu đã được thay đổi bởi người dùng khác.');
    }
}
