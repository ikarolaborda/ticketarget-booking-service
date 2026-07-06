<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Jwks\JwksProvider;
use Illuminate\Support\Str;

/**
 * Mints RS256 platform JWTs the way the Users service does, and binds an
 * in-memory JWKS so verification is DB- and network-free. Tests authenticate
 * exactly like production now that the legacy HS256 path is retired.
 */
trait MintsRs256Tokens
{
    private const string TEST_KID = 'test-kid';

    private static ?string $rs256PrivatePem = null;

    protected function bindTestJwks(): void
    {
        $publicPem = $this->rs256PublicPem();

        $this->app->instance(JwksProvider::class, new class($publicPem, self::TEST_KID) implements JwksProvider
        {
            public function __construct(private string $publicPem, private string $kid) {}

            public function publicKeyPem(string $kid): ?string
            {
                return $kid === $this->kid ? $this->publicPem : null;
            }
        });
    }

    protected function rs256Token(
        ?string $sub = null,
        string $email = 'user@example.com',
        bool $isAdmin = false,
        int $expiresIn = 3600,
        ?string $kid = null,
        ?string $issuer = null,
    ): string {
        $header = $this->rs256b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => $kid ?? self::TEST_KID], JSON_THROW_ON_ERROR));
        $payload = $this->rs256b64(json_encode([
            'iss' => $issuer ?? (string) config('auth_token.issuer'),
            'sub' => $sub ?? (string) Str::uuid(),
            'email' => $email,
            'name' => 'Test User',
            'is_admin' => $isAdmin,
            'iat' => time(),
            'exp' => time() + $expiresIn,
        ], JSON_THROW_ON_ERROR));

        $signature = '';
        openssl_sign($header.'.'.$payload, $signature, self::rs256PrivateKeyPem(), OPENSSL_ALGO_SHA256);

        return $header.'.'.$payload.'.'.$this->rs256b64($signature);
    }

    private function rs256PublicPem(): string
    {
        $details = openssl_pkey_get_details(openssl_pkey_get_private(self::rs256PrivateKeyPem()));

        return (string) $details['key'];
    }

    private static function rs256PrivateKeyPem(): string
    {
        if (self::$rs256PrivatePem === null) {
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            $pem = '';
            openssl_pkey_export($key, $pem);
            self::$rs256PrivatePem = $pem;
        }

        return self::$rs256PrivatePem;
    }

    private function rs256b64(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
