<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\QueueTokenIssuer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Traefik forward-auth target. Traefik replays the original request's headers
 * here; a valid X-Queue-Token returns 2xx (request proceeds to /reserve),
 * anything else returns 403 (Traefik rejects without ever hitting the service).
 */
final readonly class VerifyQueueController
{
    public function __construct(private QueueTokenIssuer $issuer)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $token = (string) $request->headers->get('X-Queue-Token', '');

        if ($token === '' || ! $this->issuer->isValid($token)) {
            return response()->json(['message' => 'Queue token missing or invalid'], Response::HTTP_FORBIDDEN);
        }

        return response()->json(['status' => 'ok'])->header('X-Queue-Token', $token);
    }
}
