<?php

namespace App\Http\Middleware;

use App\Models\UsuarioMinaScope;
use App\Shared\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureMinaScope
{
    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();

        if (!$usuario) {
            return ApiResponse::error(
                message: 'No autenticado',
                code: 'AUTH_REQUIRED',
                status: 401,
            );
        }

        $rolNombre = strtoupper((string) optional($usuario->rol)->nombre);

        if (in_array($rolNombre, ['ADMIN', 'SUPERADMIN'], true)) {
            return $next($request);
        }

        $minaId = $this->resolveMinaId($request);

        if (!$minaId) {
            return ApiResponse::error(
                message: 'mina_id requerido para validar scope',
                code: 'MINA_SCOPE_REQUIRED',
                status: 422,
            );
        }

        $hasScope = UsuarioMinaScope::query()
            ->where('usuario_id', $usuario->id)
            ->where('mina_id', $minaId)
            ->exists();

        if (!$hasScope) {
            return ApiResponse::error(
                message: 'Usuario sin acceso a la mina solicitada',
                code: 'MINA_SCOPE_FORBIDDEN',
                detail: ['mina_id' => $minaId],
                status: 403,
            );
        }

        $request->attributes->set('scope_mina_id', $minaId);

        return $next($request);
    }

    private function resolveMinaId(Request $request): ?string
    {
        $candidates = [
            $request->route('mina_id'),
            $request->route('minaId'),
            $request->input('mina_id'),
            $request->query('mina_id'),
            $request->header('X-Mina-Id'),
        ];

        foreach ($candidates as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }
}
