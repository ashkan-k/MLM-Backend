<?php

use App\Http\Middleware\AuthenticateApiToken;
use App\Http\Middleware\EnsureActiveRole;
use App\Http\Middleware\EnsureSuperuser;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->use([HandleCors::class]);
        $middleware->alias([
            'auth.api' => AuthenticateApiToken::class,
            'active.role' => EnsureActiveRole::class,
            'superuser' => EnsureSuperuser::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (RuntimeException $e, $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
        });
    })->create();
