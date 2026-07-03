<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Verifies the stateless HS256 bearer tokens issued by the Users service.
 * Mirrors the issuer's strict contract: fixed algorithm (no negotiation),
 * required claims, constant-time signature comparison, hard expiry.
 */
final readonly class AuthTokenVerifier
{
    public function __construct(
        private string $secret,
        private string $issuer,
    ) {
    }

    /**
     * @return array{sub: string, email: string, name: string, is_admin: bool}|null claims, or null when invalid
     */
    public function verify(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        [$header, $payload, $signature] = $parts;

        $expected = $this->base64UrlEncode(hash_hmac('sha256', $header.'.'.$payload, $this->secret, true));
        if (! hash_equals($expected, $signature)) {
            return null;
        }

        $decodedHeader = json_decode($this->base64UrlDecode($header), true);
        if (! is_array($decodedHeader) || ($decodedHeader['alg'] ?? null) !== 'HS256') {
            return null;
        }

        $claims = json_decode($this->base64UrlDecode($payload), true);

        if (! is_array($claims)
            || ($claims['iss'] ?? null) !== $this->issuer
            || ! is_string($claims['sub'] ?? null)
            || preg_match('/^[0-9a-f-]{36}$/i', $claims['sub']) !== 1
            || ! is_string($claims['email'] ?? null)
            || ! is_string($claims['name'] ?? null)
            || ! is_int($claims['exp'] ?? null)
            || $claims['exp'] < time()
        ) {
            return null;
        }

        // Tokens minted before the admin rollout carry no is_admin claim;
        // they are plain customers, never admins.
        return [
            'sub' => $claims['sub'],
            'email' => $claims['email'],
            'name' => $claims['name'],
            'is_admin' => ($claims['is_admin'] ?? null) === true,
        ];
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
