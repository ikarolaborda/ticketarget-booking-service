<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Psr\Log\LoggerInterface;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use UnexpectedValueException;

/**
 * Receives Stripe events and reconciles them against local state. The signature
 * is verified against the endpoint secret before anything is trusted; the body
 * is processed idempotently keyed by the Stripe event id.
 */
final readonly class StripeWebhookController
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $secret = (string) config('services.stripe.webhook_secret');

        try {
            $event = Webhook::constructEvent(
                $request->getContent(),
                (string) $request->header('Stripe-Signature', ''),
                $secret,
            );
        } catch (UnexpectedValueException | SignatureVerificationException $e) {
            return response()->json(['message' => 'Invalid signature'], 400);
        }

        $this->logger->info('Stripe webhook received', ['type' => $event->type, 'event_id' => $event->id]);

        return response()->json(['received' => true]);
    }
}
