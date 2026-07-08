<?php

namespace Tests\Feature;

use App\Models\PersonalPuesto;
use App\Modules\Personal\Services\PersonalService;
use App\Support\Rbac\PermissionCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PersonalPuestoManagementTest extends TestCase
{
    use DatabaseTransactions;

    public function test_boton_puestos_aparece_en_acciones_de_personal(): void
    {
        $userId = $this->createUser(['personal' => ['ver', 'actualizar']]);

        $this->withSession($this->sessionFor($userId))
            ->get(route('personal.index'))
            ->assertOk()
            ->assertSee('Puestos')
            ->assertSee(route('personal.puestos.index'), false);
    }

    public function test_crea_puesto_con_funciones(): void
    {
        $userId = $this->createUser(['personal' => ['ver', 'actualizar']]);

        $this->withSession($this->sessionFor($userId))
            ->post(route('personal.puestos.store'), [
                'nombre' => 'Operador de pruebas',
                'funciones' => 'Operar equipos y reportar condiciones.',
            ])
            ->assertRedirect(route('personal.puestos.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('personal_puestos', [
            'nombre' => 'Operador de pruebas',
            'funciones' => 'Operar equipos y reportar condiciones.',
        ]);
    }

    public function test_editar_puesto_mantiene_id_y_actualiza_trabajadores_enlazados(): void
    {
        $userId = $this->createUser(['personal' => ['ver', 'actualizar']]);
        $puesto = PersonalPuesto::query()->create([
            'id' => (string) Str::uuid(),
            'nombre' => 'AUXILIAR BASE',
            'funciones' => 'Funcion inicial',
            'activo' => true,
        ]);

        $personal = app(PersonalService::class)->create([
            'dni' => '70998877',
            'tipo_documento' => 'DNI',
            'numero_documento' => '70998877',
            'nombre_completo' => 'Trabajador Puesto',
            'puesto' => 'AUXILIAR BASE',
            'contrato' => 'FIJO',
            'estado' => 'PENDIENTE_COMPLETAR_FICHA',
        ]);

        $this->assertSame($puesto->id, $personal->fresh()->puesto_id);

        $this->withSession($this->sessionFor($userId))
            ->put(route('personal.puestos.update', $puesto->id), [
                'nombre' => 'Auxiliar actualizado',
                'funciones' => 'Nueva funcion estable.',
                'activo' => '1',
            ])
            ->assertRedirect(route('personal.puestos.index'))
            ->assertSessionHas('success');

        $personal->refresh();
        $this->assertSame($puesto->id, $personal->puesto_id);
        $this->assertSame('Auxiliar actualizado', $personal->puesto);
        $this->assertDatabaseHas('personal_puestos', [
            'id' => $puesto->id,
            'nombre' => 'Auxiliar actualizado',
            'funciones' => 'Nueva funcion estable.',
        ]);
    }

    public function test_elimina_puesto_sin_trabajadores_asociados(): void
    {
        $userId = $this->createUser(['personal' => ['ver', 'actualizar']]);
        $puesto = PersonalPuesto::query()->create([
            'id' => (string) Str::uuid(),
            'nombre' => 'PUESTO SIN USO',
            'funciones' => null,
            'activo' => true,
        ]);

        $this->withSession($this->sessionFor($userId))
            ->delete(route('personal.puestos.destroy', $puesto->id))
            ->assertRedirect(route('personal.puestos.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('personal_puestos', [
            'id' => $puesto->id,
        ]);
    }

    public function test_no_elimina_puesto_con_trabajadores_asociados(): void
    {
        $userId = $this->createUser(['personal' => ['ver', 'actualizar']]);
        $puesto = PersonalPuesto::query()->create([
            'id' => (string) Str::uuid(),
            'nombre' => 'PUESTO EN USO',
            'funciones' => null,
            'activo' => true,
        ]);

        app(PersonalService::class)->create([
            'dni' => '70112233',
            'tipo_documento' => 'DNI',
            'numero_documento' => '70112233',
            'nombre_completo' => 'Trabajador Puesto En Uso',
            'puesto' => 'PUESTO EN USO',
            'contrato' => 'FIJO',
            'estado' => 'PENDIENTE_COMPLETAR_FICHA',
        ]);

        $this->withSession($this->sessionFor($userId))
            ->delete(route('personal.puestos.destroy', $puesto->id))
            ->assertRedirect(route('personal.puestos.index'))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('personal_puestos', [
            'id' => $puesto->id,
        ]);
    }

    private function createUser(array $permissions): string
    {
        $roleId = (string) Str::uuid();
        $userId = (string) Str::uuid();

        DB::table('roles')->insert([
            'id' => $roleId,
            'nombre' => 'PERSONAL_PUESTOS_' . Str::upper(Str::random(6)),
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

    private function sessionFor(string $userId): array
    {
        $plain = 'test-token';

        DB::table('auth_tokens')->insert([
            'id' => (string) Str::uuid(),
            'usuario_id' => $userId,
            'token_hash' => hash('sha256', $plain),
            'expires_at' => now()->addHour(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'auth_token' => $plain,
            'user' => [
                'id' => $userId,
                'permissions' => DB::table('roles')
                    ->join('usuarios', 'usuarios.rol_id', '=', 'roles.id')
                    ->where('usuarios.id', $userId)
                    ->value('permisos'),
            ],
        ];
    }
}
