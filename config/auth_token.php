<?php

declare(strict_types=1);

return [
    // Legacy symmetric secret. Consulted only while accept_hs256 stays on
    // (RS256 migration overlap) and still used by the ticket-code issuer.
    'secret' => env('AUTH_JWT_SECRET', ''),

    'ttl_seconds' => (int) env('AUTH_JWT_TTL', 86400),

    'issuer' => env('AUTH_JWT_ISSUER', 'ticketarget-users'),

    // RS256 verification: the Users-service JWKS endpoint and how long its
    // key set is cached before a refetch.
    'jwks_url' => env('AUTH_JWKS_URL', 'http://users-service:8000/auth/.well-known/jwks.json'),

    'jwks_cache_ttl_seconds' => (int) env('AUTH_JWKS_CACHE_TTL', 3600),

    // Accept legacy HS256 bearers during the RS256 migration window.
    'accept_hs256' => (bool) env('AUTH_JWT_ACCEPT_HS256', true),
];
