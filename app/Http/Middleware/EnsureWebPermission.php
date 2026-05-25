<?php

namespace App\Http\Middleware;

use App\Models\Usuario;
use App\Support\Rbac\PermissionMatrix;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class EnsureWebPermission
{
    public function handle(Request $request, Closure $next, string $module, string $action = 'ver'): Response
    {
        $permissions = session('user.permissions', []);

        if (PermissionMatrix::allows($permissions, $module, $action)) {
            return $next($request);
        }

        $permissions = $this->refreshPermissionsFromDatabase();

        if (PermissionMatrix::allows($permissions, $module, $action)) {
            return $next($request);
        }

        $normalized = PermissionMatrix::normalize($permissions);

        Log::warning('web.permission_denied', [
            'usuario_id' => session('user_id') ?: session('user.id'),
            'roles' => session('user.roles', []),
            'module' => $module,
            'action' => $action,
            'method' => $request->method(),
            'path' => $request->path(),
            'module_permissions' => $normalized[$module] ?? [],
        ]);

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'ok' => false,
                'error' => 'PERMISSION_DENIED',
                'message' => 'No tienes permiso para realizar esta accion.',
            ], 403);
        }

        abort(403, 'No tienes permiso para realizar esta accion.');
    }

    private function refreshPermissionsFromDatabase(): array
    {
        if (!session('auth_token')) {
            return PermissionMatrix::normalize(session('user.permissions', []));
        }

        $userId = session('user_id') ?: session('user.id');
        if (!$userId || $userId === '00000000-0000-0000-0000-000000000001') {
            return PermissionMatrix::normalize(session('user.permissions', []));
        }

        $usuario = Usuario::query()
            ->with(['rol:id,nombre,permisos', 'rolesAdicionales:id,nombre,permisos', 'personal:id,nombre_completo'])
            ->find($userId);

        if (!$usuario) {
            return PermissionMatrix::normalize(session('user.permissions', []));
        }

        $roles = collect([$usuario->rol])
            ->merge($usuario->rolesAdicionales)
            ->filter()
            ->unique('id')
            ->values();

        $permissions = PermissionMatrix::normalizeForRoles($roles->all());

        session([
            'user' => [
                ...(session('user') ?? []),
                'id' => $usuario->id,
                'email' => $usuario->email,
                'name' => $usuario->personal?->nombre_completo ?? $usuario->email,
                'rol' => $usuario->rol?->nombre ?? 'Usuario',
                'roles' => $roles->map(fn ($rol) => $rol->nombre)->values()->all(),
                'permissions' => $permissions,
            ],
            'user_id' => $usuario->id,
        ]);

        return $permissions;
    }
}
