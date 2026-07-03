<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\AuthTokenVerifier;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guests pass through untouched, but a PRESENT bearer token must be valid:
 * rejecting bad tokens (instead of silently downgrading to guest) keeps the
 * identity on a booking unambiguous.
 */
final readonly class OptionalBearerAuth
{
    public function __construct(private AuthTokenVerifier $tokens)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) $request->bearerToken();

        if ($token === '') {
            return $next($request);
        }

        $claims = $this->tokens->verify($token);

        if ($claims === null) {
            return response()->json(['message' => 'Invalid or expired token.'], Response::HTTP_UNAUTHORIZED);
        }

        $request->attributes->set('auth_user_id', $claims['sub']);
        $request->attributes->set('auth_email', mb_strtolower(trim($claims['email'])));

        return $next($request);
    }
}
