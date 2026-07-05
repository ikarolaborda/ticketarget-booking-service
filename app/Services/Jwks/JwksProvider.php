<?php

declare(strict_types=1);

namespace App\Services\Jwks;

interface JwksProvider
{
    /**
     * The PEM public key published under the given kid, or null when the
     * issuer does not (or no longer does) publish it.
     */
    public function publicKeyPem(string $kid): ?string;
}
