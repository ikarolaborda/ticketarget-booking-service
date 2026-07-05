<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Jwks\HttpJwksProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Fail-closed and stale-on-error semantics are the security crux of RS256
 * verification: an unreachable issuer must never let an attacker's kid through,
 * but a transient outage must not knock out auth for keys already known.
 */
final class HttpJwksProviderTest extends TestCase
{
    private const string URL = 'http://users-service:8000/auth/.well-known/jwks.json';

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);
        Cache::flush();
    }

    public function test_it_returns_the_pem_for_a_published_kid(): void
    {
        [$jwk, $expectedPem] = $this->jwkAndPem('k1');
        Http::fake([self::URL => Http::response(['keys' => [$jwk]])]);

        $pem = $this->provider()->publicKeyPem('k1');

        $this->assertNotNull($pem);
        $this->assertSame($this->normalize($expectedPem), $this->normalize($pem));
    }

    public function test_it_fails_closed_when_the_issuer_is_unreachable_and_nothing_is_cached(): void
    {
        Http::fake([self::URL => Http::response(null, 500)]);

        $this->assertNull($this->provider()->publicKeyPem('k1'));
    }

    public function test_it_serves_a_cached_key_when_a_refresh_fails(): void
    {
        [$jwk] = $this->jwkAndPem('k1');
        Http::fake([self::URL => Http::response(['keys' => [$jwk]])]);

        // Warm the cache, then treat it as already expired (ttl -1) so the next
        // lookup is forced to attempt a refresh.
        $this->provider(cacheTtl: -1)->publicKeyPem('k1');

        // Issuer now down: the failed refresh must fall back to the cached k1
        // (stale-on-error) rather than dropping the key.
        Http::fake([self::URL => Http::response(null, 503)]);

        $this->assertNotNull($this->provider(cacheTtl: -1)->publicKeyPem('k1'));
    }

    public function test_an_unknown_kid_is_rejected_even_with_a_warm_cache_and_a_down_issuer(): void
    {
        [$jwk] = $this->jwkAndPem('k1');
        Http::fake([self::URL => Http::response(['keys' => [$jwk]])]);
        $this->provider()->publicKeyPem('k1');

        // A forged/rotated kid must not be satisfied by any cached key, and the
        // one throttled refetch also fails — so it is rejected, not confused.
        Http::fake([self::URL => Http::response(null, 500)]);

        $this->assertNull($this->provider()->publicKeyPem('attacker-kid'));
    }

    private function provider(int $cacheTtl = 3600): HttpJwksProvider
    {
        return new HttpJwksProvider(self::URL, $cacheTtl);
    }

    /**
     * @return array{0: array{kty: string, use: string, alg: string, kid: string, n: string, e: string}, 1: string}
     */
    private function jwkAndPem(string $kid): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $details = openssl_pkey_get_details($key);
        $encode = static fn (string $v): string => rtrim(strtr(base64_encode($v), '+/', '-_'), '=');

        return [[
            'kty' => 'RSA',
            'use' => 'sig',
            'alg' => 'RS256',
            'kid' => $kid,
            'n' => $encode(ltrim($details['rsa']['n'], "\x00")),
            'e' => $encode(ltrim($details['rsa']['e'], "\x00")),
        ], (string) $details['key']];
    }

    private function normalize(string $pem): string
    {
        return trim(str_replace("\r\n", "\n", $pem));
    }
}
