<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Queue\QueueAdmission;
use Illuminate\Contracts\Redis\Factory as Redis;

/**
 * Issues and validates stateless, HMAC-signed waiting-room tokens. Admission is
 * capped per event through a Redis counter, so bots cannot flood the purchase
 * path during an on-sale; the cap is keyed by event, never by geolocation.
 *
 * Token layout: base64url(payload_json) . base64url(hmac_sha256(payload_json)).
 */
final readonly class QueueTokenIssuer
{
    public function __construct(
        private Redis $redis,
        private string $secret,
        private int $ttlSeconds,
        private int $admissionCap,
    ) {
    }

    public function admit(string $userId, string $eventId): QueueAdmission
    {
        $counterKey = "queue:admitted:{$eventId}";
        $connection = $this->redis->connection('locks');

        $admitted = (int) $connection->incr($counterKey);
        if ($admitted === 1) {
            $connection->expire($counterKey, $this->ttlSeconds);
        }

        if ($admitted > $this->admissionCap) {
            $connection->decr($counterKey);

            return QueueAdmission::waiting();
        }

        $expiresAt = time() + $this->ttlSeconds;

        return new QueueAdmission(true, $this->sign($userId, $eventId, $expiresAt), $expiresAt);
    }

    public function isValid(string $token): bool
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return false;
        }

        [$encodedPayload, $signature] = $parts;
        $payloadJson = $this->base64UrlDecode($encodedPayload);

        $expected = $this->base64UrlEncode(hash_hmac('sha256', $payloadJson, $this->secret, true));
        if (! hash_equals($expected, $signature)) {
            return false;
        }

        $payload = json_decode($payloadJson, true);

        return is_array($payload) && ($payload['exp'] ?? 0) >= time();
    }

    private function sign(string $userId, string $eventId, int $expiresAt): string
    {
        $payloadJson = json_encode([
            'uid' => $userId,
            'eid' => $eventId,
            'exp' => $expiresAt,
            'nonce' => bin2hex(random_bytes(8)),
        ], JSON_THROW_ON_ERROR);

        $signature = hash_hmac('sha256', $payloadJson, $this->secret, true);

        return $this->base64UrlEncode($payloadJson).'.'.$this->base64UrlEncode($signature);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string
    {
        return base64_decode(strtr($value, '-_', '+/'), true) ?: '';
    }
}
