<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Jwks\JwksProvider;

/**
 * Verifies the stateless bearer tokens issued by the Users service. RS256 —
 * with the key selected by kid from the issuer's published JWKS — is the
 * primary contract; legacy HS256 is honoured only while the server-side
 * accept_hs256 migration flag stays on. The algorithm read from the strictly
 * parsed header only ever selects between these two server-configured paths,
 * so "none" or any other downgrade is impossible, and a token can never talk
 * the verifier into an algorithm the server did not enable.
 */
final readonly class AuthTokenVerifier
{
    public function __construct(
        private JwksProvider $keys,
        private string $issuer,
        private string $legacySecret,
        private bool $acceptHs256,
    ) {}

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

        $decodedHeader = json_decode($this->base64UrlDecode($header), true);
        if (! is_array($decodedHeader)) {
            return null;
        }

        if (! $this->signatureValid($decodedHeader, $header.'.'.$payload, $signature)) {
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

    /** @param array<mixed> $decodedHeader */
    private function signatureValid(array $decodedHeader, string $signingInput, string $signature): bool
    {
        $algorithm = $decodedHeader['alg'] ?? null;

        if ($algorithm === 'RS256') {
            $kid = $decodedHeader['kid'] ?? null;

            if (! is_string($kid) || $kid === '') {
                return false;
            }

            $pem = $this->keys->publicKeyPem($kid);

            if ($pem === null) {
                return false;
            }

            $raw = $this->base64UrlDecode($signature);

            return $raw !== '' && openssl_verify($signingInput, $raw, $pem, OPENSSL_ALGO_SHA256) === 1;
        }

        if ($algorithm === 'HS256' && $this->acceptHs256 && $this->legacySecret !== '') {
            $expected = $this->base64UrlEncode(hash_hmac('sha256', $signingInput, $this->legacySecret, true));

            return hash_equals($expected, $signature);
        }

        return false;
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
