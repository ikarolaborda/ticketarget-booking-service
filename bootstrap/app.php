<?php

declare(strict_types=1);

use App\Http\Middleware\AssignRequestId;
use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withProviders([AppServiceProvider::class])
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        apiPrefix: '',
        commands: __DIR__.'/../routes/console.php',
        health: '/health',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [AssignRequestId::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
