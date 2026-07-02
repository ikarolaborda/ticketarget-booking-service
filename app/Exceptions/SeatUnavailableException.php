<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class SeatUnavailableException extends RuntimeException
{
    public function __construct(string $message = 'One or more seats are no longer available')
    {
        parent::__construct($message);
    }
}
