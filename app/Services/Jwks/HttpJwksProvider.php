<?php

declare(strict_types=1);

namespace App\Services\Jwks;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Fetches the users-service key set over the internal network and caches the
 * kid => PEM map. Failure semantics are deliberately conservative: an expired
 * cache keeps serving when a refresh fails (auth must survive transient
 * issuer outages), an unknown kid forces at most one refetch per throttle
 * window (so forged kids cannot stampede the issuer), and with no cache and
 * no reachable issuer RS256 verification fails closed.
 */
final readonly class HttpJwksProvider implements JwksProvider
{
    private const string CACHE_KEY = 'auth.jwks.keys';

    private const string REFRESH_KEY = 'auth.jwks.last_forced_refresh';

    private const int FORCED_REFRESH_THROTTLE_SECONDS = 30;

    public function __construct(
        private string $url,
        private int $cacheTtlSeconds,
    ) {}

    public function publicKeyPem(string $kid): ?string
    {
        $keys = $this->cachedKeys();

        if (isset($keys[$kid])) {
            return $keys[$kid];
        }

        // Unknown kid: the issuer may have rotated since the last fetch.
        if (! $this->mayForceRefresh()) {
            return null;
        }

        $fresh = $this->fetch();

        if ($fresh === null) {
            return null;
        }

        $this->remember($fresh);

        return $fresh[$kid] ?? null;
    }

    /** @return array<string, string> */
    private function cachedKeys(): array
    {
        $cached = Cache::get(self::CACHE_KEY);
        $usable = is_array($cached) && is_array($cached['keys'] ?? null) && is_int($cached['fetched_at'] ?? null);

        if ($usable && time() - $cached['fetched_at'] <= $this->cacheTtlSeconds) {
            return $cached['keys'];
        }

        $fresh = $this->fetch();

        if ($fresh !== null) {
            $this->remember($fresh);

            return $fresh;
        }

        if ($usable) {
            Log::warning('JWKS refresh failed; serving stale keys', ['url' => $this->url]);

            return $cached['keys'];
        }

        return [];
    }

    /** @return array<string, string>|null kid => PEM, or null when the endpoint is unreachable or malformed */
    private function fetch(): ?array
    {
        try {
            $response = Http::timeout(3)->get($this->url);
        } catch (\Throwable $e) {
            Log::warning('JWKS fetch failed', ['url' => $this->url, 'error' => $e->getMessage()]);

            return null;
        }

        if (! $response->ok()) {
            Log::warning('JWKS fetch returned an error status', ['url' => $this->url, 'status' => $response->status()]);

            return null;
        }

        $body = $response->json();

        if (! is_array($body) || ! is_array($body['keys'] ?? null)) {
            return null;
        }

        $keys = [];

        foreach ($body['keys'] as $jwk) {
            if (! is_array($jwk) || ! is_string($jwk['kid'] ?? null) || $jwk['kid'] === '') {
                continue;
            }

            $pem = JwkConverter::rsaPemFromJwk($jwk);

            if ($pem !== null) {
                $keys[$jwk['kid']] = $pem;
            }
        }

        return $keys;
    }

    /** @param array<string, string> $keys */
    private function remember(array $keys): void
    {
        Cache::forever(self::CACHE_KEY, ['fetched_at' => time(), 'keys' => $keys]);
    }

    private function mayForceRefresh(): bool
    {
        $last = (int) Cache::get(self::REFRESH_KEY, 0);

        if (time() - $last < self::FORCED_REFRESH_THROTTLE_SECONDS) {
            return false;
        }

        Cache::forever(self::REFRESH_KEY, time());

        return true;
    }
}
