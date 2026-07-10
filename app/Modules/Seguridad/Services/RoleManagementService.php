<?php

namespace App\Modules\Seguridad\Services;

use App\Models\NotificationRolePreference;
use App\Models\NotificationType;
use App\Models\Rol;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use App\Support\Rbac\PermissionCatalog;
use App\Support\Rbac\PermissionMatrix;

class RoleManagementService
{
    public function __construct(private readonly AdminProtectionService $adminProtection)
    {
    }

    public function ensureBaseRoles(): void
    {
        if (!Schema::hasTable('roles')) {
            return;
        }

        foreach (PermissionCatalog::baseRoleDefinitions() as $definition) {
            $rol = Rol::query()->firstOrCreate(
                ['nombre' => $definition['nombre']],
                $this->buildCreatePayload($definition)
            );

            $updates = [];

            if ($this->hasDescripcionColumn() && blank($rol->descripcion ?? null)) {
                $updates['descripcion'] = $definition['descripcion'];
            }

            if (empty($rol->permisos) || !$this->usesStructuredPermissions($rol->permisos)) {
                $updates['permisos'] = $definition['permisos'];
            }

            if (blank($rol->estado ?? null)) {
                $updates['estado'] = $definition['estado'];
            }

            if ($this->adminProtection->isAdminRoleName($rol->nombre)) {
                if (json_encode(PermissionMatrix::normalize($rol->permisos ?? [])) !== json_encode(PermissionCatalog::fullAccessMatrix())) {
                    $updates['permisos'] = PermissionCatalog::fullAccessMatrix();
                }

                if (strtoupper((string) $rol->estado) !== 'ACTIVO') {
                    $updates['estado'] = 'ACTIVO';
                }
            }

            if ($updates !== []) {
                $rol->fill($updates)->save();
            }
        }
    }

    public function list(): Collection
    {
        $this->ensureBaseRoles();

        return Rol::query()
            ->withCount('usuarios')
            ->orderBy('nombre')
            ->get()
            ->map(fn (Rol $rol) => $this->decorate($rol));
    }

    public function active(): Collection
    {
        $this->ensureBaseRoles();

        return Rol::query()
            ->where('estado', 'ACTIVO')
            ->orderBy('nombre')
            ->get();
    }

    public function find(string $id): ?Rol
    {
        $this->ensureBaseRoles();

        $rol = Rol::query()->withCount('usuarios')->with('usuarios:id,rol_id,email')->find($id);

        return $rol ? $this->decorate($rol) : null;
    }

    public function create(array $payload): Rol
    {
        $this->ensureBaseRoles();

        return Rol::query()->create($this->buildCreatePayload($payload));
    }

    public function update(Rol $rol, array $payload): Rol
    {
        $this->ensureBaseRoles();

        return DB::transaction(function () use ($rol, $payload): Rol {
            $lockedRol = Rol::query()->lockForUpdate()->findOrFail($rol->getKey());
            $this->adminProtection->assertRoleUpdateAllowed($lockedRol, $payload);

            return $this->decorate($this->persistRolePayload($lockedRol, $payload)->fresh(['usuarios']));
        });
    }

    public function updateWithNotificationPreferences(Rol $rol, array $payload, array $typeIds, array $enabledMap): Rol
    {
        $this->ensureBaseRoles();

        return DB::transaction(function () use ($rol, $payload, $typeIds, $enabledMap): Rol {
            $lockedRol = Rol::query()->lockForUpdate()->findOrFail($rol->getKey());
            $this->adminProtection->assertRoleUpdateAllowed($lockedRol, $payload);
            $updatedRol = $this->persistRolePayload($lockedRol, $payload);

            $this->syncNotificationRolePreferenceRows($updatedRol, $typeIds, $enabledMap);

            return $this->decorate($updatedRol->fresh(['usuarios']));
        });
    }

    public function duplicate(Rol $rol): Rol
    {
        $copy = Rol::query()->create([
            'id' => (string) Str::uuid(),
            'nombre' => $this->nextDuplicateName($rol->nombre),
            'permisos' => PermissionMatrix::normalize($rol->permisos ?? []),
            'estado' => 'ACTIVO',
            ...($this->hasDescripcionColumn() ? ['descripcion' => trim((string) ($rol->descripcion ?? '')) . ' (Copia)'] : []),
        ]);

        return $this->decorate($copy);
    }

    public function toggle(Rol $rol): Rol
    {
        return DB::transaction(function () use ($rol): Rol {
            $lockedRol = Rol::query()->lockForUpdate()->findOrFail($rol->getKey());
            $this->adminProtection->assertRoleToggleAllowed($lockedRol);

            $lockedRol->estado = strtoupper((string) $lockedRol->estado) === 'ACTIVO' ? 'INACTIVO' : 'ACTIVO';
            $lockedRol->save();

            return $this->decorate($lockedRol);
        });
    }

    public function modules(): array
    {
        return PermissionCatalog::availableModules(['notificaciones']);
    }

    public function actions(): array
    {
        return PermissionCatalog::availableActions(['notificaciones']);
    }

    public function moduleActions(): array
    {
        return PermissionCatalog::availableModuleActions(['notificaciones']);
    }

    public function notificationModules(): array
    {
        return array_filter(
            PermissionCatalog::availableModules(),
            static fn (string $label, string $module) => $module === 'notificaciones',
            ARRAY_FILTER_USE_BOTH
        );
    }

    public function notificationActions(): array
    {
        $moduleActions = PermissionCatalog::availableModuleActions();

        return $moduleActions['notificaciones'] ?? [];
    }

    public function notificationModuleActions(): array
    {
        $moduleActions = PermissionCatalog::availableModuleActions();

        return isset($moduleActions['notificaciones'])
            ? ['notificaciones' => $moduleActions['notificaciones']]
            : [];
    }

    public function notificationTypes(): Collection
    {
        if (!Schema::hasTable('notification_types')) {
            return collect();
        }

        return NotificationType::query()
            ->orderBy('module')
            ->orderBy('default_title')
            ->get();
    }

    public function notificationRolePreferences(?Rol $rol): Collection
    {
        if (!$rol || !Schema::hasTable('notification_role_preferences')) {
            return collect();
        }

        return NotificationRolePreference::query()
            ->where('rol_id', $rol->id)
            ->get()
            ->keyBy(fn (NotificationRolePreference $pref) => (string) $pref->notification_type_id);
    }

    public function syncNotificationRolePreferences(Rol $rol, array $typeIds, array $enabledMap): void
    {
        DB::transaction(function () use ($rol, $typeIds, $enabledMap): void {
            $this->syncNotificationRolePreferenceRows($rol, $typeIds, $enabledMap);
        });
    }

    private function syncNotificationRolePreferenceRows(Rol $rol, array $typeIds, array $enabledMap): void
    {
        if (!Schema::hasTable('notification_role_preferences') || !Schema::hasTable('notification_types')) {
            return;
        }

        $ids = collect($typeIds)
            ->map(fn ($id) => trim((string) $id))
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return;
        }

        $existing = NotificationType::query()
            ->whereIn('id', $ids->all())
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->flip();

        $enabledIds = collect(array_keys($enabledMap))
            ->map(fn ($id) => trim((string) $id))
            ->filter()
            ->unique()
            ->values();

        foreach ($ids as $typeId) {
            if (!$existing->has($typeId)) {
                continue;
            }

            $isEnabled = $enabledIds->contains($typeId);

            NotificationRolePreference::query()->updateOrCreate(
                [
                    'rol_id' => $rol->id,
                    'notification_type_id' => $typeId,
                ],
                [
                    'id' => NotificationRolePreference::query()
                            ->where('rol_id', $rol->id)
                            ->where('notification_type_id', $typeId)
                            ->value('id') ?? (string) Str::uuid(),
                    'is_enabled' => $isEnabled,
                ]
            );
        }
    }

    private function persistRolePayload(Rol $rol, array $payload): Rol
    {
        $name = strtoupper(trim((string) $payload['nombre']));
        $isAdmin = $this->adminProtection->isAdminRoleName($rol->nombre)
            || $this->adminProtection->isAdminRoleName($name);

        $rol->nombre = $name;
        $rol->estado = $isAdmin ? 'ACTIVO' : strtoupper(trim((string) ($payload['estado'] ?? 'ACTIVO')));
        $rol->permisos = $isAdmin
            ? PermissionCatalog::fullAccessMatrix()
            : PermissionMatrix::normalize($payload['permisos'] ?? ($rol->permisos ?? []));

        if ($this->hasDescripcionColumn()) {
            $rol->descripcion = trim((string) ($payload['descripcion'] ?? '')) ?: null;
        }

        $rol->save();

        return $rol;
    }

    private function buildCreatePayload(array $payload): array
    {
        $name = strtoupper(trim((string) $payload['nombre']));
        $isAdmin = $this->adminProtection->isAdminRoleName($name);

        return [
            'id' => $payload['id'] ?? (string) Str::uuid(),
            'nombre' => $name,
            'permisos' => $isAdmin
                ? PermissionCatalog::fullAccessMatrix()
                : PermissionMatrix::normalize($payload['permisos'] ?? []),
            'estado' => $isAdmin ? 'ACTIVO' : strtoupper(trim((string) ($payload['estado'] ?? 'ACTIVO'))),
            ...($this->hasDescripcionColumn() ? ['descripcion' => trim((string) ($payload['descripcion'] ?? '')) ?: null] : []),
        ];
    }

    private function hasDescripcionColumn(): bool
    {
        return Schema::hasTable('roles') && Schema::hasColumn('roles', 'descripcion');
    }

    private function usesStructuredPermissions(mixed $permissions): bool
    {
        if (!is_array($permissions)) {
            return false;
        }

        if (isset($permissions['matrix']) && is_array($permissions['matrix'])) {
            return true;
        }

        return !array_is_list($permissions);
    }

    private function nextDuplicateName(string $baseName): string
    {
        $base = strtoupper(trim($baseName)) . '_COPIA';
        $name = $base;
        $counter = 2;

        while (Rol::query()->where('nombre', $name)->exists()) {
            $name = $base . '_' . $counter;
            $counter++;
        }

        return $name;
    }

    private function decorate(Rol $rol): Rol
    {
        $rol->permisos = $this->adminProtection->isAdminRoleName($rol->nombre)
            ? PermissionCatalog::fullAccessMatrix()
            : PermissionMatrix::normalize($rol->permisos ?? []);

        return $rol;
    }
}
