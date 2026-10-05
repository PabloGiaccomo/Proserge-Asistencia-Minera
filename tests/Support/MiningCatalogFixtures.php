<?php

namespace Tests\Support;

use App\Models\ExamenMinero;
use App\Models\Mina;
use App\Models\MinaRequisito;
use App\Models\Usuario;
use App\Support\Rbac\PermissionCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait MiningCatalogFixtures
{
    protected function exam(string $name, array $extra = []): ExamenMinero
    {
        return ExamenMinero::query()->create($extra + [
            'id' => (string) Str::uuid(), 'nombre' => $name, 'tipo' => 'General',
            'activo' => true, 'tiene_vigencia' => false, 'requiere_nota' => false,
            'permite_reintento' => true, 'max_intentos' => 2, 'permite_convalidacion' => true,
        ]);
    }

    protected function mine(): Mina
    {
        return Mina::query()->create(['id' => (string) Str::uuid(), 'nombre' => 'Unidad '.Str::random(6),
            'unidad_minera' => 'Unidad operativa', 'ubicacion' => 'Operacion', 'estado' => 'ACTIVO']);
    }

    protected function requirement(Mina $mine, ExamenMinero $exam): MinaRequisito
    {
        return MinaRequisito::query()->create(['id' => (string) Str::uuid(), 'mina_id' => $mine->id,
            'examen_id' => $exam->id, 'nombre' => $exam->nombre, 'activo' => true, 'obligatorio' => true,
            'permite_convalidacion_mina' => true, 'convalidar_desde_otras_minas' => true]);
    }

    protected function actor(bool $configure = true): Usuario
    {
        $role = (string) Str::uuid();
        DB::table('roles')->insert(['id' => $role, 'nombre' => 'CATALOG_'.Str::random(8), 'estado' => 'ACTIVO',
            'permisos' => json_encode(PermissionCatalog::matrixFromSelections(['habilitacion_minera' => $configure ? ['ver', 'configurar'] : ['ver']]))]);
        return Usuario::query()->create(['id' => (string) Str::uuid(), 'email' => Str::random(8).'@test.local',
            'password' => bcrypt('test'), 'rol_id' => $role, 'estado' => 'ACTIVO']);
    }

    protected function catalogSession(Usuario $actor): array
    {
        return ['auth_token' => 'test', 'user_id' => $actor->id,
            'user' => ['id' => $actor->id, 'email' => $actor->email, 'permissions' => PermissionCatalog::emptyMatrix()]];
    }

    protected function associate(ExamenMinero $variant, ExamenMinero $principal): void
    {
        DB::table('examen_minero_asociaciones')->insert(['id' => (string) Str::uuid(),
            'variante_id' => $variant->id, 'principal_id' => $principal->id,
            'motivo' => 'Variante confirmada', 'created_at' => now(), 'updated_at' => now()]);
    }
}
