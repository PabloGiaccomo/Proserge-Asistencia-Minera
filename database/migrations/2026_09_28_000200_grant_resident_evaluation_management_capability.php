<?php

use App\Models\Rol;
use App\Support\Rbac\PermissionMatrix;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('roles')) {
            return;
        }

        Rol::query()->where('nombre', 'like', '%GERENTE%')->get()->each(function (Rol $role): void {
            $matrix = PermissionMatrix::normalize($role->permisos);
            $isBaseManager = strtoupper((string) $role->nombre) === 'GERENTE';
            if (! $isBaseManager && ! PermissionMatrix::allowsDirect($matrix, 'evaluaciones', 'evaluar_residentes')) {
                return;
            }

            $matrix['evaluaciones']['evaluar_residentes'] = true;
            $matrix['evaluaciones']['habilitar_evaluacion_residentes'] = true;
            $role->permisos = $matrix;
            $role->save();
        });
    }

    public function down(): void
    {
        // Role grants may have been edited after migration; do not revoke them automatically.
    }
};
