<?php

declare(strict_types=1);

namespace App\Domain\Queue;

final readonly class QueueAdmission
{
    public function __construct(
        public bool $admitted,
        public ?string $token,
        public int $expiresAt,
    ) {
    }

    public static function waiting(): self
    {
        return new self(false, null, 0);
    }
}
