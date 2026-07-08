<?php

namespace Tests\Feature;

use App\Modules\Personal\Services\PersonalService;
use App\Support\Rbac\PermissionCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PersonalListaNegraTest extends TestCase
{
    use DatabaseTransactions;

    public function test_usuario_con_permiso_agrega_trabajador_a_lista_negra_sin_cambiar_estado(): void
    {
        $userId = $this->createUser(['personal' => ['ver', 'actualizar']]);
        $personal = app(PersonalService::class)->create([
            'dni' => '75990001',
            'tipo_documento' => 'DNI',
            'numero_documento' => '75990001',
            'nombre_completo' => 'Trabajador Lista Negra',
            'puesto' => 'Operario',
            'contrato' => 'FIJO',
            'estado' => 'PENDIENTE_COMPLETAR_FICHA',
        ]);

        $this->withSession($this->sessionFor($userId))
            ->post(route('personal.lista-negra.store', $personal->id), [
                'motivo_lista_negra' => 'No considerar para futura activacion por incidente operativo.',
            ])
            ->assertRedirect(route('personal.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('personal', [
            'id' => $personal->id,
            'estado' => 'PENDIENTE_COMPLETAR_FICHA',
            'en_lista_negra' => true,
            'lista_negra_by_usuario_id' => $userId,
        ]);

        $this->assertSame(
            'No considerar para futura activacion por incidente operativo.',
            (string) DB::table('personal')->where('id', $personal->id)->value('lista_negra_motivo')
        );
    }

    public function test_usuario_sin_permiso_no_puede_agregar_lista_negra(): void
    {
        $userId = $this->createUser(['personal' => ['ver']]);
        $personal = app(PersonalService::class)->create([
            'dni' => '75990002',
            'tipo_documento' => 'DNI',
            'numero_documento' => '75990002',
            'nombre_completo' => 'Trabajador Sin Permiso',
            'puesto' => 'Operario',
            'contrato' => 'FIJO',
            'estado' => 'PENDIENTE_COMPLETAR_FICHA',
        ]);

        $this->withSession($this->sessionFor($userId))
            ->post(route('personal.lista-negra.store', $personal->id), [
                'motivo_lista_negra' => 'Motivo no autorizado.',
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('personal', [
            'id' => $personal->id,
            'en_lista_negra' => false,
        ]);
    }

    public function test_usuario_con_permiso_quita_trabajador_de_lista_negra_sin_cambiar_estado(): void
    {
        $userId = $this->createUser(['personal' => ['ver', 'actualizar']]);
        $personal = app(PersonalService::class)->create([
            'dni' => '75990003',
            'tipo_documento' => 'DNI',
            'numero_documento' => '75990003',
            'nombre_completo' => 'Trabajador Sale Lista Negra',
            'puesto' => 'Operario',
            'contrato' => 'FIJO',
            'estado' => 'CESADO',
        ]);

        DB::table('personal')->where('id', $personal->id)->update([
            'en_lista_negra' => true,
            'lista_negra_motivo' => 'Revision anterior.',
            'lista_negra_at' => now(),
            'lista_negra_by_usuario_id' => $userId,
        ]);

        $this->withSession($this->sessionFor($userId))
            ->post(route('personal.lista-negra.remove', $personal->id))
            ->assertRedirect(route('personal.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('personal', [
            'id' => $personal->id,
            'estado' => 'CESADO',
            'en_lista_negra' => false,
            'lista_negra_motivo' => null,
            'lista_negra_at' => null,
            'lista_negra_by_usuario_id' => null,
        ]);
    }

    public function test_usuario_sin_permiso_no_puede_quitar_lista_negra(): void
    {
        $userId = $this->createUser(['personal' => ['ver']]);
        $personal = app(PersonalService::class)->create([
            'dni' => '75990004',
            'tipo_documento' => 'DNI',
            'numero_documento' => '75990004',
            'nombre_completo' => 'Trabajador Lista Negra Protegida',
            'puesto' => 'Operario',
            'contrato' => 'FIJO',
            'estado' => 'CESADO',
        ]);

        DB::table('personal')->where('id', $personal->id)->update([
            'en_lista_negra' => true,
            'lista_negra_motivo' => 'Motivo protegido.',
        ]);

        $this->withSession($this->sessionFor($userId))
            ->post(route('personal.lista-negra.remove', $personal->id))
            ->assertForbidden();

        $this->assertDatabaseHas('personal', [
            'id' => $personal->id,
            'en_lista_negra' => true,
            'lista_negra_motivo' => 'Motivo protegido.',
        ]);
    }

    public function test_listado_mantiene_visible_lista_negra_aunque_filtre_estado(): void
    {
        $userId = $this->createUser(['personal' => ['ver', 'actualizar']]);

        app(PersonalService::class)->create([
            'dni' => '75990005',
            'tipo_documento' => 'DNI',
            'numero_documento' => '75990005',
            'nombre_completo' => 'Trabajador Activo Filtro',
            'puesto' => 'Operario',
            'contrato' => 'FIJO',
            'estado' => 'ACTIVO',
        ]);

        $blacklisted = app(PersonalService::class)->create([
            'dni' => '75990006',
            'tipo_documento' => 'DNI',
            'numero_documento' => '75990006',
            'nombre_completo' => 'Trabajador Cesado Lista Negra Visible',
            'puesto' => 'Operario',
            'contrato' => 'FIJO',
            'estado' => 'CESADO',
        ]);

        app(PersonalService::class)->create([
            'dni' => '75990007',
            'tipo_documento' => 'DNI',
            'numero_documento' => '75990007',
            'nombre_completo' => 'Trabajador Cesado Sin Lista Negra',
            'puesto' => 'Operario',
            'contrato' => 'FIJO',
            'estado' => 'CESADO',
        ]);

        DB::table('personal')->where('id', $blacklisted->id)->update([
            'en_lista_negra' => true,
            'lista_negra_motivo' => 'Debe revisarse antes de una futura activacion.',
            'lista_negra_at' => now(),
            'lista_negra_by_usuario_id' => $userId,
        ]);

        $this->withSession($this->sessionFor($userId))
            ->get(route('personal.index', ['estado' => 'ACTIVO']))
            ->assertOk()
            ->assertSee('TRABAJADOR CESADO LISTA NEGRA VISIBLE')
            ->assertSee('data-lista-negra="1"', false)
            ->assertDontSee('TRABAJADOR CESADO SIN LISTA NEGRA');
    }

    private function createUser(array $permissions): string
    {
        $roleId = (string) Str::uuid();
        $userId = (string) Str::uuid();

        DB::table('roles')->insert([
            'id' => $roleId,
            'nombre' => 'PERSONAL_LISTA_NEGRA_' . Str::upper(Str::random(6)),
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
