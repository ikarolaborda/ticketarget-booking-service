<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class RefundNotAllowedException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status,
    ) {
        parent::__construct($message);
    }
}
