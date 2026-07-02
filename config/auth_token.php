<?php

declare(strict_types=1);

return [
    // Symmetric secret shared with every service that verifies bearer tokens.
    'secret' => env('AUTH_JWT_SECRET', ''),

    'ttl_seconds' => (int) env('AUTH_JWT_TTL', 86400),

    'issuer' => env('AUTH_JWT_ISSUER', 'ticketarget-users'),
];
