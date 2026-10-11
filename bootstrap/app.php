<?php

use App\Http\Middleware\EnsurePlatformAdminSessionVersion;
use App\Http\Middleware\EnsureSessionVersion;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Run revocation checks after session startup but before Sanctum caches a user.
        $middleware->prependToPriorityList(
            AuthenticatesRequests::class,
            EnsureSessionVersion::class,
        );
        $middleware->prependToPriorityList(
            AuthenticatesRequests::class,
            EnsurePlatformAdminSessionVersion::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
