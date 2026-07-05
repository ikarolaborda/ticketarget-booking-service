<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Jwks\JwksProvider;
use Illuminate\Support\Str;

/**
 * Proves the RS256/JWKS verification path end to end through the admin guard,
 * and that legacy HS256 forgery dies once the migration flag is off — the
 * whole security point of moving off the shared symmetric secret.
 */
final class Rs256VerificationTest extends BookingTestCase
{
    private const string KID = 'test-kid';

    private static ?string $privatePem = null;

    protected function setUp(): void
    {
        parent::setUp();

        config(['auth_token.accept_hs256' => true]);

        $publicPem = $this->publicPem();

        $this->app->instance(JwksProvider::class, new class($publicPem, self::KID) implements JwksProvider
        {
            public function __construct(private string $publicPem, private string $kid) {}

            public function publicKeyPem(string $kid): ?string
            {
                return $kid === $this->kid ? $this->publicPem : null;
            }
        });
    }

    public function test_an_rs256_admin_token_is_accepted(): void
    {
        $this->getJson('/booking/admin/stats', ['Authorization' => 'Bearer '.$this->rs256Token(isAdmin: true)])
            ->assertOk();
    }

    public function test_a_non_admin_rs256_token_is_forbidden(): void
    {
        $this->getJson('/booking/admin/stats', ['Authorization' => 'Bearer '.$this->rs256Token(isAdmin: false)])
            ->assertStatus(403);
    }

    public function test_an_rs256_token_with_an_unknown_kid_is_rejected(): void
    {
        $token = $this->rs256Token(isAdmin: true, kid: 'rotated-away');

        $this->getJson('/booking/admin/stats', ['Authorization' => 'Bearer '.$token])
            ->assertStatus(401);
    }

    public function test_a_legacy_hs256_admin_token_is_accepted_only_while_the_flag_is_on(): void
    {
        $this->getJson('/booking/admin/stats', ['Authorization' => 'Bearer '.$this->hs256Token()])
            ->assertOk();
    }

    public function test_a_legacy_hs256_admin_token_is_rejected_once_the_flag_is_off(): void
    {
        config(['auth_token.accept_hs256' => false]);

        $this->getJson('/booking/admin/stats', ['Authorization' => 'Bearer '.$this->hs256Token()])
            ->assertStatus(401);
    }

    private function rs256Token(bool $isAdmin, ?string $kid = null): string
    {
        $header = $this->b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => $kid ?? self::KID], JSON_THROW_ON_ERROR));
        $payload = $this->b64(json_encode($this->claims($isAdmin), JSON_THROW_ON_ERROR));

        $signature = '';
        openssl_sign($header.'.'.$payload, $signature, self::privateKeyPem(), OPENSSL_ALGO_SHA256);

        return $header.'.'.$payload.'.'.$this->b64($signature);
    }

    private function hs256Token(): string
    {
        $header = $this->b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $payload = $this->b64(json_encode($this->claims(true), JSON_THROW_ON_ERROR));
        $signature = $this->b64(hash_hmac('sha256', $header.'.'.$payload, (string) config('auth_token.secret'), true));

        return $header.'.'.$payload.'.'.$signature;
    }

    /** @return array<string, mixed> */
    private function claims(bool $isAdmin): array
    {
        return [
            'iss' => config('auth_token.issuer'),
            'sub' => (string) Str::uuid(),
            'email' => 'admin@example.com',
            'name' => 'Admin',
            'is_admin' => $isAdmin,
            'iat' => time(),
            'exp' => time() + 3600,
        ];
    }

    private function publicPem(): string
    {
        $details = openssl_pkey_get_details(openssl_pkey_get_private(self::privateKeyPem()));

        return (string) $details['key'];
    }

    private static function privateKeyPem(): string
    {
        if (self::$privatePem === null) {
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            $pem = '';
            openssl_pkey_export($key, $pem);
            self::$privatePem = $pem;
        }

        return self::$privatePem;
    }

    private function b64(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
