<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Issues and verifies the signed codes embedded in ticket QR images. Format is
 * versioned (`v1.`) so the scheme can evolve without breaking printed tickets.
 * The HMAC uses a purpose prefix for domain separation from auth/queue tokens.
 */
final readonly class TicketCodeIssuer
{
    public function __construct(private string $secret) {}

    public function issue(string $bookingId): string
    {
        return 'v1.'.$this->base64UrlEncode($bookingId).'.'.$this->sign($bookingId);
    }

    /**
     * @return string|null the booking id, or null for ANY invalid input
     */
    public function verify(string $code): ?string
    {
        $parts = explode('.', $code);

        if (count($parts) !== 3 || $parts[0] !== 'v1') {
            return null;
        }

        $bookingId = $this->base64UrlDecode($parts[1]);

        if ($bookingId === '' || ! hash_equals($this->sign($bookingId), $parts[2])) {
            return null;
        }

        return $bookingId;
    }

    private function sign(string $bookingId): string
    {
        return $this->base64UrlEncode(hash_hmac('sha256', 'ticket:'.$bookingId, $this->secret, true));
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
