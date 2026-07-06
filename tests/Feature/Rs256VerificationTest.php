<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Str;

/**
 * Proves the RS256/JWKS verification path end to end through the admin guard,
 * and that legacy HS256 is honoured only while the migration flag is on — the
 * whole security point of retiring the shared symmetric secret.
 */
final class Rs256VerificationTest extends BookingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Baseline for the flag-on cases; the flag-off test overrides this.
        config(['auth_token.accept_hs256' => true]);
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

    private function hs256Token(): string
    {
        $b64 = static fn (string $v): string => rtrim(strtr(base64_encode($v), '+/', '-_'), '=');

        $header = $b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $payload = $b64(json_encode([
            'iss' => config('auth_token.issuer'),
            'sub' => (string) Str::uuid(),
            'email' => 'admin@example.com',
            'name' => 'Admin',
            'is_admin' => true,
            'iat' => time(),
            'exp' => time() + 3600,
        ], JSON_THROW_ON_ERROR));
        $signature = $b64(hash_hmac('sha256', $header.'.'.$payload, (string) config('auth_token.secret'), true));

        return $header.'.'.$payload.'.'.$signature;
    }
}
