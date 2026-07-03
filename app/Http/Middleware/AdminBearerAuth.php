<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\AuthTokenVerifier;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the admin analytics surface. Booking-service has no Sanctum tokens,
 * so this accepts exactly one credential: a platform JWT carrying is_admin.
 */
final readonly class AdminBearerAuth
{
    public function __construct(private AuthTokenVerifier $tokens) {}

    public function handle(Request $request, Closure $next): Response
    {
        $bearer = (string) $request->bearerToken();

        if ($bearer === '') {
            return response()->json(['message' => 'Unauthenticated.'], Response::HTTP_UNAUTHORIZED);
        }

        $claims = $this->tokens->verify($bearer);

        if ($claims === null) {
            return response()->json(['message' => 'Unauthenticated.'], Response::HTTP_UNAUTHORIZED);
        }

        if ($claims['is_admin'] !== true) {
            return response()->json(['message' => 'Forbidden.'], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
