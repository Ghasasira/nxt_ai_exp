<?php

use App\Http\Middleware\ApiAuthMiddleware;
use App\Http\Middleware\EnsureRoleMiddleware;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\IdempotencyMiddleware;
use App\Http\Middleware\RequestIdMiddleware;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(RequestIdMiddleware::class);

        $middleware->web(append: [
            HandleInertiaRequests::class,
        ]);

        // Allow /api/v1/* to authenticate via session cookie OR Bearer API key (SRS §5.2)
        $middleware->api(prepend: [
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
        ]);

        $middleware->alias([
            'api.auth' => ApiAuthMiddleware::class,
            'idempotent' => IdempotencyMiddleware::class,
            'role' => EnsureRoleMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
