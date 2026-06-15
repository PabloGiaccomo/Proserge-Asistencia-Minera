<?php

namespace Tests\Feature;

use App\Support\Rbac\PermissionCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CatalogoMinaDeleteTest extends TestCase
{
    use DatabaseTransactions;

    public function test_usuario_con_permiso_ve_boton_eliminar_mina(): void
    {
        $userId = $this->createUser(['catalogos' => ['ver'], 'minas' => ['ver', 'eliminar']]);
        $mineId = $this->createMine();

        $this->withSession($this->sessionFor($userId))
            ->get(route('catalogos.minas.index'))
            ->assertOk()
            ->assertSee(route('catalogos.minas.destroy', $mineId), false)
            ->assertSee('Eliminar');
    }

    public function test_elimina_mina_sin_movimientos_y_sus_paraderos(): void
    {
        $userId = $this->createUser(['minas' => ['eliminar']]);
        $mineId = $this->createMine();
        $paraderoId = (string) Str::uuid();

        DB::table('mina_paraderos')->insert([
            'id' => $paraderoId,
            'mina_id' => $mineId,
            'nombre' => 'Paradero temporal',
            'ubicacion' => 'Entrada',
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withSession($this->sessionFor($userId))
            ->post(route('catalogos.minas.destroy', $mineId))
            ->assertRedirect(route('catalogos.minas.index'))
            ->assertSessionHas('success', 'Mina eliminada correctamente.');

        $this->assertDatabaseMissing('minas', ['id' => $mineId]);
        $this->assertDatabaseMissing('mina_paraderos', ['id' => $paraderoId]);
    }

    public function test_no_elimina_mina_con_movimientos_asociados(): void
    {
        $userId = $this->createUser(['minas' => ['eliminar']]);
        $mineId = $this->createMine();

        DB::table('rq_mina')->insert([
            'id' => (string) Str::uuid(),
            'mina_id' => $mineId,
            'area' => 'Parada',
            'fecha_inicio' => '2026-06-12',
            'fecha_fin' => '2026-06-13',
            'estado' => 'BORRADOR',
            'created_by_usuario_id' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withSession($this->sessionFor($userId))
            ->from(route('catalogos.minas.index'))
            ->post(route('catalogos.minas.destroy', $mineId))
            ->assertRedirect(route('catalogos.minas.index'))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('minas', ['id' => $mineId]);
    }

    private function createUser(array $permissions): string
    {
        $roleId = (string) Str::uuid();
        $userId = (string) Str::uuid();

        DB::table('roles')->insert([
            'id' => $roleId,
            'nombre' => 'CATALOGO_MINAS_' . Str::upper(Str::random(6)),
            'permisos' => json_encode(PermissionCatalog::matrixFromSelections($permissions)),
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('usuarios')->insert([
            'id' => $userId,
            'email' => Str::lower(Str::random(8)) . '@test.local',
            'password' => bcrypt('secret123'),
            'rol_id' => $roleId,
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $userId;
    }

    private function createMine(): string
    {
        $mineId = (string) Str::uuid();

        DB::table('minas')->insert([
            'id' => $mineId,
            'nombre' => 'MINA DELETE ' . Str::upper(Str::random(6)),
            'unidad_minera' => 'UM DELETE',
            'ubicacion' => 'Operacion',
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $mineId;
    }

    private function sessionFor(string $userId): array
    {
        return [
            'auth_token' => 'test-token',
            'user_id' => $userId,
            'user' => [
                'id' => $userId,
                'email' => 'catalogo@test.local',
                'permissions' => PermissionCatalog::emptyMatrix(),
            ],
        ];
    }
}
