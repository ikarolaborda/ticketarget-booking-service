<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Payment\PaymentGateway;
use App\Infrastructure\Payment\StripePaymentGateway;
use App\Services\AuthTokenVerifier;
use App\Services\QueueTokenIssuer;
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

        $this->app->singleton(AuthTokenVerifier::class, fn (): AuthTokenVerifier => new AuthTokenVerifier(
            secret: (string) config('auth_token.secret'),
            issuer: (string) config('auth_token.issuer'),
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
