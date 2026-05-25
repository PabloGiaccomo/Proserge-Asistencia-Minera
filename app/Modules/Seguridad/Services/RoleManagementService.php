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
        $rol->nombre = strtoupper(trim((string) $payload['nombre']));
        $rol->estado = strtoupper(trim((string) ($payload['estado'] ?? 'ACTIVO')));
        $rol->permisos = PermissionMatrix::normalize($payload['permisos'] ?? []);

        if ($this->hasDescripcionColumn()) {
            $rol->descripcion = trim((string) ($payload['descripcion'] ?? '')) ?: null;
        }

        $rol->save();

        return $this->decorate($rol->fresh(['usuarios']));
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
        $rol->estado = strtoupper((string) $rol->estado) === 'ACTIVO' ? 'INACTIVO' : 'ACTIVO';
        $rol->save();

        return $this->decorate($rol);
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

        DB::transaction(function () use ($rol, $ids, $enabledIds, $existing): void {
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
        });
    }

    private function buildCreatePayload(array $payload): array
    {
        return [
            'id' => $payload['id'] ?? (string) Str::uuid(),
            'nombre' => strtoupper(trim((string) $payload['nombre'])),
            'permisos' => PermissionMatrix::normalize($payload['permisos'] ?? []),
            'estado' => strtoupper(trim((string) ($payload['estado'] ?? 'ACTIVO'))),
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
        $rol->permisos = PermissionMatrix::normalize($rol->permisos ?? []);

        return $rol;
    }
}
