<?php

namespace App\Modules\Seguridad\Services;

use App\Models\Rol;
use App\Models\Usuario;
use App\Support\Rbac\PermissionCatalog;
use App\Support\Rbac\PermissionMatrix;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class AdminProtectionService
{
    private const ADMIN_ROLE_NAME = 'ADMIN';

    public const MESSAGE_ADMIN_FULL_ACCESS = 'El rol ADMIN debe conservar acceso total, incluyendo Usuarios y Roles.';
    public const MESSAGE_ADMIN_ROLE_ACTIVE = 'No se puede desactivar ni renombrar el rol ADMIN.';
    public const MESSAGE_LAST_ADMIN_USER = 'No se puede desactivar ni quitar el rol ADMIN al ultimo usuario ADMIN activo.';

    public function isAdminRoleName(?string $roleName): bool
    {
        return strtoupper(trim((string) $roleName)) === self::ADMIN_ROLE_NAME;
    }

    public function assertRoleUpdateAllowed(Rol $rol, array $payload): void
    {
        $currentName = strtoupper(trim((string) $rol->nombre));
        $nextName = strtoupper(trim((string) ($payload['nombre'] ?? $rol->nombre)));

        if (!$this->isAdminRoleName($currentName) && !$this->isAdminRoleName($nextName)) {
            return;
        }

        if ($this->isAdminRoleName($currentName) && !$this->isAdminRoleName($nextName)) {
            $this->block(self::MESSAGE_ADMIN_ROLE_ACTIVE);
        }

        if (strtoupper(trim((string) ($payload['estado'] ?? $rol->estado))) !== 'ACTIVO') {
            $this->block(self::MESSAGE_ADMIN_ROLE_ACTIVE);
        }

        $permissions = PermissionMatrix::normalize($payload['permisos'] ?? $rol->permisos ?? []);
        if (!$this->hasAdminCriticalPermissions($permissions)) {
            $this->block(self::MESSAGE_ADMIN_FULL_ACCESS);
        }
    }

    public function assertRoleToggleAllowed(Rol $rol): void
    {
        if ($this->isAdminRoleName($rol->nombre) && strtoupper((string) $rol->estado) === 'ACTIVO') {
            $this->block(self::MESSAGE_ADMIN_ROLE_ACTIVE);
        }
    }

    public function assertUserUpdateAllowed(Usuario $usuario, array $payload): void
    {
        if (!$this->isActiveAdminUser((string) $usuario->id)) {
            return;
        }

        if ($this->activeAdminUserCount() > 1) {
            return;
        }

        if (!$this->payloadKeepsActiveAdminUser($payload)) {
            $this->block(self::MESSAGE_LAST_ADMIN_USER);
        }
    }

    public function assertUserToggleAllowed(Usuario $usuario): void
    {
        if (!$this->isActiveAdminUser((string) $usuario->id)) {
            return;
        }

        if ($this->activeAdminUserCount() <= 1) {
            $this->block(self::MESSAGE_LAST_ADMIN_USER);
        }
    }

    public function activeAdminUserCount(): int
    {
        if (!Schema::hasTable('usuarios') || !Schema::hasTable('roles')) {
            return 0;
        }

        return $this->activeAdminUserQuery()->count();
    }

    public function isActiveAdminUser(string $usuarioId): bool
    {
        if ($usuarioId === '' || !Schema::hasTable('usuarios') || !Schema::hasTable('roles')) {
            return false;
        }

        return $this->activeAdminUserQuery()
            ->where('usuarios.id', $usuarioId)
            ->exists();
    }

    private function payloadKeepsActiveAdminUser(array $payload): bool
    {
        if ($this->hasUsuarioEstadoColumn() && strtoupper(trim((string) ($payload['estado'] ?? 'ACTIVO'))) !== 'ACTIVO') {
            return false;
        }

        $roleIds = collect([
                $payload['rol_id'] ?? null,
                ...($payload['area_role_ids'] ?? []),
                ...($payload['cargo_role_ids'] ?? []),
            ])
            ->map(fn ($id) => trim((string) $id))
            ->filter()
            ->unique()
            ->values();

        if ($roleIds->isEmpty()) {
            return false;
        }

        return Rol::query()
            ->whereIn('id', $roleIds->all())
            ->where('nombre', self::ADMIN_ROLE_NAME)
            ->where('estado', 'ACTIVO')
            ->exists();
    }

    private function activeAdminUserQuery()
    {
        $query = Usuario::query();

        if ($this->hasUsuarioEstadoColumn()) {
            $query->where('usuarios.estado', 'ACTIVO');
        }

        return $query->where(function ($adminQuery): void {
            $adminQuery->whereHas('rol', function ($roleQuery): void {
                $this->activeAdminRoleScope($roleQuery);
            });

            if (Schema::hasTable('usuario_roles')) {
                $adminQuery->orWhereHas('rolesAdicionales', function ($roleQuery): void {
                    $this->activeAdminRoleScope($roleQuery);
                });
            }
        });
    }

    private function activeAdminRoleScope($query): void
    {
        $query->where('nombre', self::ADMIN_ROLE_NAME)
            ->where('estado', 'ACTIVO');
    }

    private function hasAdminCriticalPermissions(array $permissions): bool
    {
        $moduleActions = PermissionCatalog::availableModuleActions();

        foreach (['usuarios', 'roles'] as $module) {
            foreach (($moduleActions[$module] ?? ['ver']) as $action) {
                if (($permissions[$module][$action] ?? false) !== true) {
                    return false;
                }
            }
        }

        return true;
    }

    private function hasUsuarioEstadoColumn(): bool
    {
        return Schema::hasTable('usuarios') && Schema::hasColumn('usuarios', 'estado');
    }

    private function block(string $message): void
    {
        throw ValidationException::withMessages([
            'admin' => $message,
        ]);
    }
}
