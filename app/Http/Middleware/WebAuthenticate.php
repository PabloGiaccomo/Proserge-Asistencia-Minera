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
            return $this->invalidSessionResponse($request, 'SESSION_REQUIRED');
        }

        if (!$this->refreshSessionPermissions()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return $this->invalidSessionResponse($request, 'SESSION_USER_NOT_FOUND');
        }

        return $next($request);
    }

    private function refreshSessionPermissions(): bool
    {
        $userId = session('user_id') ?: session('user.id');
        if (!$userId) {
            return false;
        }

        $usuario = Usuario::query()
            ->with(['rol:id,nombre,permisos', 'rolesAdicionales:id,nombre,permisos', 'personal:id,nombre_completo'])
            ->find($userId);

        if (!$usuario) {
            return false;
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
                'personal_id' => $usuario->personal_id,
                'rol' => $usuario->rol?->nombre ?? 'Usuario',
                'roles' => $roles->map(fn ($rol) => $rol->nombre)->values()->all(),
                'permissions' => PermissionMatrix::normalizeForRoles($roles->all()),
            ],
            'user_id' => $usuario->id,
        ]);

        return true;
    }

    private function invalidSessionResponse(Request $request, string $error): Response
    {
        $message = 'Tu sesion ya no es valida. Inicia sesion nuevamente para continuar.';

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'ok' => false,
                'error' => $error,
                'message' => $message,
                'redirect' => route('login'),
            ], 401);
        }

        return redirect()
            ->route('login')
            ->with('session_expired', $message);
    }
}
