<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Payment\PaymentGateway;
use App\Infrastructure\Payment\StripePaymentGateway;
use App\Services\AuthTokenVerifier;
use App\Services\Jwks\HttpJwksProvider;
use App\Services\Jwks\JwksProvider;
use App\Services\QueueTokenIssuer;
use App\Services\TicketCodeIssuer;
use Illuminate\Contracts\Redis\Factory as Redis;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;
use Stripe\StripeClient;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(StripeClient::class, fn (): StripeClient => new StripeClient((string) config('services.stripe.secret')));

        // Bind the domain port to its Stripe adapter (dependency inversion).
        $this->app->bind(PaymentGateway::class, StripePaymentGateway::class);

        $this->app->singleton(JwksProvider::class, fn (): JwksProvider => new HttpJwksProvider(
            url: (string) config('auth_token.jwks_url'),
            cacheTtlSeconds: (int) config('auth_token.jwks_cache_ttl_seconds'),
        ));

        $this->app->singleton(AuthTokenVerifier::class, fn ($app): AuthTokenVerifier => new AuthTokenVerifier(
            keys: $app->make(JwksProvider::class),
            issuer: (string) config('auth_token.issuer'),
            legacySecret: (string) config('auth_token.secret'),
            acceptHs256: (bool) config('auth_token.accept_hs256'),
        ));

        $this->app->singleton(TicketCodeIssuer::class, fn (): TicketCodeIssuer => new TicketCodeIssuer(
            secret: (string) config('ticket_code.secret'),
        ));

        $this->app->singleton(QueueTokenIssuer::class, fn ($app): QueueTokenIssuer => new QueueTokenIssuer(
            redis: $app->make(Redis::class),
            secret: (string) config('queue_gate.secret'),
            ttlSeconds: (int) config('queue_gate.ttl_seconds'),
            admissionCap: (int) config('queue_gate.admission_cap'),
        ));
    }

    public function boot(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());
    }
}
