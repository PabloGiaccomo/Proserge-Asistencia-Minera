<?php

namespace App\Modules\Seguridad\Services;

use App\Models\Rol;
use Illuminate\Support\Collection;
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
        return PermissionCatalog::modules();
    }

    public function actions(): array
    {
        return PermissionCatalog::actions();
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
