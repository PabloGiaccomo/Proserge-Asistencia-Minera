<?php

namespace App\Http\Middleware;

use App\Models\NotificationUserSetting;
use App\Models\Usuario;
use App\Support\Rbac\PermissionMatrix;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class EnsureWebPermission
{
    public function handle(Request $request, Closure $next, string $module, string $action = 'ver'): Response
    {
        $permissions = session('user.permissions', []);

        if ($module === 'notificaciones') {
            $notificationAccess = $this->notificationAccessFromUserSetting();
            if ($notificationAccess === true) {
                return $next($request);
            }
            if ($notificationAccess === false) {
                return $this->deny($request, $permissions, $module, $action);
            }
        }

        if ($this->allowsRoute($permissions, $module, $action)) {
            return $next($request);
        }

        $permissions = $this->refreshPermissionsFromDatabase();

        if ($module === 'notificaciones') {
            $notificationAccess = $this->notificationAccessFromUserSetting();
            if ($notificationAccess === true) {
                return $next($request);
            }
            if ($notificationAccess === false) {
                return $this->deny($request, $permissions, $module, $action);
            }
        }

        if ($this->allowsRoute($permissions, $module, $action)) {
            return $next($request);
        }

        return $this->deny($request, $permissions, $module, $action);
    }

    private function deny(Request $request, array $permissions, string $module, string $action): Response
    {
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
                'message' => $this->deniedMessage($action),
            ], 403);
        }

        abort(403, $this->deniedMessage($action));
    }

    private function allowsRoute(array $permissions, string $module, string $action): bool
    {
        $action = trim($action) !== '' ? trim($action) : 'ver';

        foreach ($this->routeActions($action) as $routeAction) {
            if (PermissionMatrix::allowsDirect($permissions, $module, $routeAction)) {
                return true;
            }

            foreach ($this->routeFallbackPermissions($module, $routeAction) as [$fallbackModule, $fallbackAction]) {
                if (PermissionMatrix::allowsDirect($permissions, $fallbackModule, $fallbackAction)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function routeFallbackPermissions(string $module, string $action): array
    {
        return match ($module) {
            'personal' => match ($action) {
                'ver_documentos' => [['personal_documentos', 'ver']],
                'subir_documentos' => [['personal_documentos', 'subir']],
                'descargar_documentos' => [['personal_documentos', 'descargar'], ['personal_contratos', 'descargar']],
                'ver_contratos' => [['personal_contratos', 'ver']],
                'renovar' => [['personal_contratos', 'renovar']],
                'reingresar' => [['personal_contratos', 'reingresar']],
                default => [],
            },
            'personal_puestos' => [['personal', 'gestionar_puestos']],
            'vencimientos' => match ($action) {
                'registrar' => [['personal_vencimientos', 'registrar'], ['personal_vencimientos', 'actualizar'], ['vencimientos', 'actualizar']],
                default => [['personal_vencimientos', $action]],
            },
            'personal_vencimientos' => match ($action) {
                'registrar' => [['vencimientos', 'registrar'], ['vencimientos', 'actualizar'], ['personal_vencimientos', 'actualizar']],
                default => [['vencimientos', $action]],
            },
            'habilitacion_minera' => match ($action) {
                'ver_matriz',
                'ver_vencimientos',
                'ver_programados',
                'ver_historial_precios' => [['personal', 'ver']],
                'crear',
                'editar',
                'actualizar',
                'asignar',
                'desasignar',
                'configurar',
                'registrar',
                'programar',
                'convalidar',
                'importar',
                'exportar' => [['personal', 'actualizar']],
                default => [['personal', $action]],
            },
            default => [],
        };
    }

    private function routeActions(string $action): array
    {
        $actions = array_map('trim', explode('|', $action));
        $actions = array_filter($actions, fn (string $item): bool => $item !== '');

        return !empty($actions) ? array_values(array_unique($actions)) : ['ver'];
    }

    private function deniedMessage(string $action): string
    {
        return trim($action) === 'ver'
            ? 'No tienes permiso para acceder a este módulo.'
            : 'No tienes permiso para realizar esta accion.';
    }

    private function notificationAccessFromUserSetting(): ?bool
    {
        $userId = (string) (session('user_id') ?: session('user.id') ?: '');
        if ($userId === '' || !Schema::hasTable('notification_user_settings')) {
            return null;
        }

        $setting = NotificationUserSetting::query()
            ->where('usuario_id', $userId)
            ->first();

        if (!$setting) {
            return null;
        }

        if (!$setting->in_app_enabled || ($setting->muted_until && now()->lt($setting->muted_until))) {
            return false;
        }

        return true;
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
