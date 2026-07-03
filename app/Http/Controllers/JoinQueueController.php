<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\JoinQueueRequest;
use App\Services\QueueTokenIssuer;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final readonly class JoinQueueController
{
    public function __construct(private QueueTokenIssuer $issuer) {}

    public function __invoke(JoinQueueRequest $request): JsonResponse
    {
        $admission = $this->issuer->admit(
            $request->validated('user_id'),
            $request->validated('event_id'),
        );

        if (! $admission->admitted) {
            return response()->json(['status' => 'waiting'], Response::HTTP_SERVICE_UNAVAILABLE)
                ->header('Retry-After', '10');
        }

        return response()->json([
            'status' => 'active',
            'queue_token' => $admission->token,
            'expires_at' => $admission->expiresAt,
        ]);
    }
}
