<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Http\Middleware\AuthenticateToken;
use App\Http\Middleware\EnsureMinaScope;
use App\Http\Middleware\EnsureWebPermission;
use App\Http\Middleware\WebAuthenticate;
use App\Shared\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

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
            'web.permission' => EnsureWebPermission::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $sessionExpiredResponse = static function (Request $request) {
            $message = 'Tu sesion caduco por inactividad. Recarga la pagina e inicia sesion nuevamente.';

            if ($request->is('api/*') || $request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'ok' => false,
                    'error' => 'SESSION_EXPIRED',
                    'message' => $message,
                    'redirect' => route('login'),
                ], 419);
            }

            session()->forget(['auth_token', 'user', 'user_id']);
            $request->session()->regenerateToken();

            return redirect()
                ->route('login')
                ->with('session_expired', $message);
        };

        $exceptions->render(function (TokenMismatchException $e, Request $request) use ($sessionExpiredResponse) {
            return $sessionExpiredResponse($request);
        });

        $exceptions->render(function (HttpExceptionInterface $e, Request $request) use ($sessionExpiredResponse) {
            if ($e->getStatusCode() !== 419) {
                return null;
            }

            return $sessionExpiredResponse($request);
        });

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
