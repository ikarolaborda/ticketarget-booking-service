<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\QueueTokenIssuer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Defense-in-depth: even though Traefik already forward-auths /reserve, the
 * service re-checks the queue token so it stays safe if reached directly.
 */
final readonly class RequireQueueToken
{
    public function __construct(private QueueTokenIssuer $issuer)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) $request->headers->get('X-Queue-Token', '');

        if ($token === '' || ! $this->issuer->isValid($token)) {
            return response()->json(['message' => 'Valid queue token required'], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
