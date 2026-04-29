<?php

namespace App\Http\Middleware;

use App\Models\AuthToken;
use App\Shared\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();

        if (!$bearer) {
            return ApiResponse::error(
                message: 'Token requerido',
                code: 'AUTH_TOKEN_REQUIRED',
                status: 401,
            );
        }

        $tokenHash = hash('sha256', $bearer);

        $token = AuthToken::query()
            ->with(['usuario.rol', 'usuario.rolesAdicionales'])
            ->where('token_hash', $tokenHash)
            ->whereNull('revoked_at')
            ->first();

        if (!$token || !$token->usuario) {
            return ApiResponse::error(
                message: 'Token invalido',
                code: 'AUTH_TOKEN_INVALID',
                status: 401,
            );
        }

        if ($token->expires_at && $token->expires_at->isPast()) {
            return ApiResponse::error(
                message: 'Token expirado',
                code: 'AUTH_TOKEN_EXPIRED',
                status: 401,
            );
        }

        $token->forceFill([
            'last_used_at' => now(),
        ])->save();

        $request->setUserResolver(fn () => $token->usuario);
        $request->attributes->set('auth_token_id', $token->id);

        return $next($request);
    }
}
