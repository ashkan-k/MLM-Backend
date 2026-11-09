<?php

use App\Http\Middleware\AuthenticateApiToken;
use App\Http\Middleware\EnsureActiveRole;
use App\Http\Middleware\EnsureCourseManager;
use App\Http\Middleware\EnsureSuperuser;
use App\Services\Audit\AuditService;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

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
            'course.manager' => EnsureCourseManager::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (ValidationException $e, Request $request) {
            if (! ($request->expectsJson() || $request->is('api/*'))) {
                return null;
            }

            $errors = $e->errors();
            $first = collect($errors)->flatten()->first();

            return response()->json([
                'message' => $first ?: 'اطلاعات ارسالی نامعتبر است.',
                'errors' => $errors,
            ], $e->status);
        });

        $exceptions->render(function (RuntimeException $e, $request) {
            if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
                return null;
            }
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
        });

        $exceptions->report(function (\Throwable $e) {
            try {
                $request = request();
                if (! $request || ! ($request->is('api/*') || $request->expectsJson())) {
                    return;
                }
                if ($e instanceof ValidationException) {
                    return;
                }
                app(AuditService::class)->record($request->user(), 'api.error', 'system', null, [
                    'path' => '/'.$request->path(),
                    'method' => $request->method(),
                    'exception' => class_basename($e),
                    'message' => mb_substr($e->getMessage(), 0, 500),
                ]);
            } catch (\Throwable) {
                // ignore audit failures while reporting
            }
        });
    })->create();
