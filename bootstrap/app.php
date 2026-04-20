<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Http\Middleware\AuthenticateToken;
use App\Http\Middleware\EnsureMinaScope;
use App\Http\Middleware\WebAuthenticate;
use App\Shared\Support\ApiResponse;
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
        $middleware->alias([
            'auth.token' => AuthenticateToken::class,
            'mina.scope' => EnsureMinaScope::class,
            'web.auth' => WebAuthenticate::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (ValidationException $e, Request $request) {
            if (!$request->is('api/*')) {
                return null;
            }

            return ApiResponse::error(
                message: 'Error de validacion',
                code: 'VALIDATION_ERROR',
                detail: $e->errors(),
                status: 422,
            );
        });
    })->create();
