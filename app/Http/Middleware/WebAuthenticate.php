<?php

namespace App\Http\Middleware;

use App\Models\Usuario;
use App\Support\Rbac\PermissionMatrix;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class WebAuthenticate
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!session('auth_token')) {
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'ok' => false,
                    'error' => 'SESSION_REQUIRED',
                    'message' => 'Sesion no valida o expirada',
                ], 401);
            }

            return redirect()->route('login');
        }

        $this->refreshSessionPermissions();

        return $next($request);
    }

    private function refreshSessionPermissions(): void
    {
        $userId = session('user_id') ?: session('user.id');
        if (!$userId || $userId === '00000000-0000-0000-0000-000000000001') {
            return;
        }

        $usuario = Usuario::query()
            ->with(['rol:id,nombre,permisos', 'rolesAdicionales:id,nombre,permisos', 'personal:id,nombre_completo'])
            ->find($userId);

        if (!$usuario) {
            return;
        }

        $roles = collect([$usuario->rol])
            ->merge($usuario->rolesAdicionales)
            ->filter()
            ->unique('id')
            ->values();

        session([
            'user' => [
                ...(session('user') ?? []),
                'id' => $usuario->id,
                'email' => $usuario->email,
                'name' => $usuario->personal?->nombre_completo ?? $usuario->email,
                'rol' => $usuario->rol?->nombre ?? 'Usuario',
                'roles' => $roles->map(fn ($rol) => $rol->nombre)->values()->all(),
                'permissions' => PermissionMatrix::normalizeForRoles($roles->all()),
            ],
            'user_id' => $usuario->id,
        ]);
    }
}
