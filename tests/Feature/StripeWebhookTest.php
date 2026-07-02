<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class StripeWebhookTest extends TestCase
{
    private const string SECRET = 'whsec_test_secret';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.stripe.webhook_secret' => self::SECRET]);
    }

    public function test_it_accepts_a_genuinely_signed_event(): void
    {
        $payload = json_encode([
            'id' => 'evt_test_1',
            'object' => 'event',
            'type' => 'payment_intent.succeeded',
            'data' => ['object' => ['id' => 'pi_test']],
        ], JSON_THROW_ON_ERROR);

        $this->postRaw($payload, $this->sign($payload))
            ->assertOk()
            ->assertJson(['received' => true]);
    }

    public function test_it_rejects_a_tampered_payload(): void
    {
        $payload = json_encode(['id' => 'evt_test_2', 'object' => 'event', 'type' => 'x', 'data' => []], JSON_THROW_ON_ERROR);
        $signature = $this->sign($payload);

        $this->postRaw($payload.'tampered', $signature)->assertStatus(400);
    }

    public function test_it_rejects_a_missing_or_bogus_signature(): void
    {
        $payload = '{"id":"evt_test_3","object":"event"}';

        $this->postRaw($payload, '')->assertStatus(400);
        $this->postRaw($payload, 't=1,v1=deadbeef')->assertStatus(400);
    }

    /**
     * Signs exactly the way Stripe does (t=<ts>,v1=HMAC_SHA256(secret, "<ts>.<payload>"))
     * and verifies through the REAL SDK verifier, so the scheme itself is under test.
     */
    private function sign(string $payload): string
    {
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, self::SECRET);

        return 't='.$timestamp.',v1='.$signature;
    }

    private function postRaw(string $payload, string $signature)
    {
        return $this->call('POST', '/booking/webhook', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ], $payload);
    }
}
