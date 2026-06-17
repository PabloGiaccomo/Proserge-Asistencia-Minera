<?php

namespace Tests\Feature;

use App\Models\Personal;
use App\Support\Rbac\PermissionCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PersonalExportExcelTest extends TestCase
{
    use DatabaseTransactions;

    public function test_export_page_uses_worker_selection_without_ficha_export(): void
    {
        $userId = $this->createUser(['personal' => ['ver', 'exportar']]);
        $worker = $this->createPersonal([
            'dni' => '12345678',
            'nombre_completo' => 'PEREZ GOMEZ JUAN',
            'puesto' => 'MECANICO',
        ]);

        $this->withSession($this->sessionFor($userId))
            ->get(route('personal.export.form'))
            ->assertOk()
            ->assertSee('Exportar en Excel')
            ->assertSee('Exportando...')
            ->assertSee('Exportado')
            ->assertSee('triggerExcelDownload', false)
            ->assertSee('data-preview-remove-worker', false)
            ->assertSee('Columnas disponibles')
            ->assertSee('Seleccionar personal')
            ->assertDontSee('Exportar fichas');

        $this->withSession($this->sessionFor($userId))
            ->getJson(route('personal.export.workers', ['q' => 'perez']))
            ->assertOk()
            ->assertJsonPath('workers.0.id', $worker->id);

        $this->withSession($this->sessionFor($userId))
            ->postJson(route('personal.export.preview'), [
                'columns' => ['dni', 'nombre_completo', 'puesto'],
                'personal_ids' => [$worker->id],
            ])
            ->assertOk()
            ->assertJsonPath('headers.0', 'DNI')
            ->assertJsonPath('rows.0.0', '12345678')
            ->assertJsonPath('rows.0.1', 'PEREZ GOMEZ JUAN')
            ->assertJsonPath('rows.0.2', 'MECANICO')
            ->assertJsonPath('row_ids.0', $worker->id);

        $this->withSession($this->sessionFor($userId))
            ->post(route('personal.export.download'), [
                'columns' => ['dni', 'nombre_completo', 'puesto'],
                'personal_ids' => [$worker->id],
            ])
            ->assertOk()
            ->assertDownload();
    }

    public function test_export_page_can_open_with_workers_selected_from_personal_table(): void
    {
        $userId = $this->createUser(['personal' => ['ver', 'exportar']]);
        $worker = $this->createPersonal([
            'dni' => '87654321',
            'nombre_completo' => 'RAMOS QUISPE MARIA',
            'puesto' => 'SUPERVISOR',
        ]);

        $this->withSession($this->sessionFor($userId))
            ->get(route('personal.export.form', ['personal_ids' => [$worker->id]]))
            ->assertOk()
            ->assertSee('1 trabajador(es) seleccionado(s)')
            ->assertSee('RAMOS QUISPE MARIA')
            ->assertSee('87654321')
            ->assertDontSee('Exportar fichas');
    }

    public function test_export_preview_expands_mines_into_status_columns_with_colors(): void
    {
        $userId = $this->createUser(['personal' => ['ver', 'exportar']]);
        $workerOne = $this->createPersonal([
            'dni' => '11111111',
            'numero_documento' => '11111111',
            'nombre_completo' => 'TRABAJADOR MINA UNO',
        ]);
        $workerTwo = $this->createPersonal([
            'dni' => '22222222',
            'numero_documento' => '22222222',
            'nombre_completo' => 'TRABAJADOR MINA DOS',
        ]);
        $cerroVerdeId = $this->createMine('CERRO VERDE');
        $marcobreId = $this->createMine('MARCOBRE');

        $this->attachMine($workerOne, $marcobreId, 'HABILITADO');
        $this->attachMine($workerOne, $cerroVerdeId, 'EN_PROCESO');
        $this->attachMine($workerTwo, $cerroVerdeId, 'NO_HABILITADO');

        $this->withSession($this->sessionFor($userId))
            ->postJson(route('personal.export.preview'), [
                'columns' => ['dni', 'minas', 'estado_mina'],
                'personal_ids' => [$workerOne->id, $workerTwo->id],
            ])
            ->assertOk()
            ->assertJsonPath('headers.0', 'DNI')
            ->assertJsonPath('headers.1', 'CERRO VERDE')
            ->assertJsonPath('headers.2', 'MARCOBRE')
            ->assertJsonPath('rows.0.1', 'En proceso')
            ->assertJsonPath('rows.0.2', 'Habilitado')
            ->assertJsonPath('rows.1.1', 'No habilitado')
            ->assertJsonPath('rows.1.2', '-')
            ->assertJsonPath('cell_styles.0.1', 'mine-warn')
            ->assertJsonPath('cell_styles.0.2', 'mine-ok')
            ->assertJsonPath('cell_styles.1.1', 'mine-neutral');
    }

    public function test_export_download_requires_selected_workers(): void
    {
        $userId = $this->createUser(['personal' => ['ver', 'exportar']]);

        $response = $this->withSession($this->sessionFor($userId))
            ->from(route('personal.export.form'))
            ->post(route('personal.export.download'), [
                'columns' => ['dni', 'nombre_completo'],
            ]);

        $response->assertStatus(302)
            ->assertSessionHas('error', 'Debes seleccionar al menos un trabajador para exportar.');
        $this->assertStringStartsWith(route('personal.export.form'), $response->headers->get('Location'));
    }

    private function createPersonal(array $overrides = []): Personal
    {
        return Personal::query()->create([
            'id' => (string) Str::uuid(),
            'dni' => '10000000',
            'tipo_documento' => 'DNI',
            'numero_documento' => '10000000',
            'nombre_completo' => 'TRABAJADOR EXPORT TEST',
            'puesto' => 'OPERARIO',
            'ocupacion' => '',
            'contrato' => 'FIJO',
            'es_supervisor' => false,
            'qr_code' => 'QR-' . Str::uuid(),
            'fecha_ingreso' => '2026-06-01',
            'estado' => 'ACTIVO',
            'telefono_1' => '999999999',
            'telefono_2' => '',
            'correo' => 'export@test.local',
            ...$overrides,
        ]);
    }

    private function createMine(string $name): string
    {
        $id = (string) Str::uuid();

        DB::table('minas')->insert([
            'id' => $id,
            'nombre' => $name,
            'unidad_minera' => $name,
            'ubicacion' => '',
            'link_ubicacion' => '',
            'color' => '#0d9488',
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function attachMine(Personal $personal, string $mineId, string $state): void
    {
        DB::table('personal_mina')->insert([
            'id' => (string) Str::uuid(),
            'personal_id' => $personal->id,
            'mina_id' => $mineId,
            'estado' => $state,
            'estado_habilitacion' => $state,
            'activo' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createUser(array $permissions): string
    {
        $roleId = (string) Str::uuid();
        $userId = (string) Str::uuid();

        DB::table('roles')->insert([
            'id' => $roleId,
            'nombre' => 'PERSONAL_EXPORT_' . Str::upper(Str::random(6)),
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
        $plain = 'test-token-' . Str::random(12);

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
