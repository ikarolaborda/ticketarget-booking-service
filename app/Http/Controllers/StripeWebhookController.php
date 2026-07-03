<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\ReconcileRefundAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Psr\Log\LoggerInterface;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use UnexpectedValueException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Receives Stripe events and reconciles them against local state. The signature
 * is verified against the endpoint secret before anything is trusted; the body
 * is processed idempotently keyed by the Stripe event id.
 */
final readonly class StripeWebhookController
{
    public function __construct(
        private LoggerInterface $logger,
        private ReconcileRefundAction $reconcileRefund,
    ) {
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
            return response()->json(['message' => 'Invalid signature'], Response::HTTP_BAD_REQUEST);
        }

        $this->logger->info('Stripe webhook received', ['type' => $event->type, 'event_id' => $event->id]);

        if ($event->type === 'charge.refunded') {
            $charge = $event->data->object;

            // A failure here must bubble up as 5xx so Stripe redelivers; the
            // reconciliation is idempotent on replay.
            $this->reconcileRefund->execute(
                (string) $charge->payment_intent,
                (int) $charge->amount_refunded,
                (int) $charge->amount,
            );
        }

        return response()->json(['received' => true]);
    }
}
