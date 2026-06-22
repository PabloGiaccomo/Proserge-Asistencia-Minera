<?php

namespace Tests\Feature;

use App\Modules\Personal\Services\PersonalService;
use App\Support\Rbac\PermissionCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PersonalContractStatusHighlightTest extends TestCase
{
    use DatabaseTransactions;

    public function test_listado_resalta_columna_contrato_segun_estado_documental(): void
    {
        $userId = $this->createUser(['personal' => ['ver', 'actualizar']]);

        app(PersonalService::class)->create([
            'dni' => '75990101',
            'tipo_documento' => 'DNI',
            'numero_documento' => '75990101',
            'nombre_completo' => 'Trabajador Sin Contrato Color',
            'puesto' => 'Operario',
            'contrato' => 'FIJO',
            'estado' => 'PENDIENTE_COMPLETAR_FICHA',
        ]);

        $personalPendiente = app(PersonalService::class)->create([
            'dni' => '75990102',
            'tipo_documento' => 'DNI',
            'numero_documento' => '75990102',
            'nombre_completo' => 'Trabajador Pendiente Pdf Color',
            'puesto' => 'Operario',
            'contrato' => 'FIJO',
            'estado' => 'FALTA_CONTRATO',
            'pendiente_contrato_firmado' => true,
        ]);

        DB::table('personal_contratos')->insert([
            'id' => (string) Str::uuid(),
            'personal_id' => $personalPendiente->id,
            'contrato_numero' => 1,
            'estado' => 'ACTIVO',
            'fecha_inicio' => '2026-06-01',
            'fecha_fin' => '2026-12-31',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $personalAnulado = app(PersonalService::class)->create([
            'dni' => '75990103',
            'tipo_documento' => 'DNI',
            'numero_documento' => '75990103',
            'nombre_completo' => 'Trabajador Solo Contrato Anulado',
            'puesto' => 'Operario',
            'contrato' => 'INTER',
            'estado' => 'INACTIVO',
        ]);

        DB::table('personal_contratos')->insert([
            'id' => (string) Str::uuid(),
            'personal_id' => $personalAnulado->id,
            'contrato_numero' => 1,
            'estado' => 'ANULADO',
            'fecha_inicio' => '2026-01-01',
            'fecha_fin' => '2026-06-30',
            'motivo_anulacion' => 'Error de registro',
            'anulado_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->withSession($this->sessionFor($userId))
            ->get(route('personal.index'));

        $response
            ->assertOk()
            ->assertSee('TRABAJADOR SIN CONTRATO COLOR')
            ->assertSee('TRABAJADOR PENDIENTE PDF COLOR')
            ->assertSee('TRABAJADOR SOLO CONTRATO ANULADO')
            ->assertSee('contract-missing', false)
            ->assertSee('contract-file-pending-cell', false);

        $this->assertMatchesRegularExpression(
            '/<tr[^>]*class="[^"]*contract-missing[^"]*"[^>]*data-dni="75990103"/s',
            $response->getContent()
        );
    }

    public function test_contrato_cerrado_prioriza_estado_cesado_sobre_inactivo(): void
    {
        $userId = $this->createUser(['personal' => ['ver', 'actualizar']]);

        $personal = app(PersonalService::class)->create([
            'dni' => '75990201',
            'tipo_documento' => 'DNI',
            'numero_documento' => '75990201',
            'nombre_completo' => 'Trabajador Cerrado Cesado Visible',
            'puesto' => 'Operario',
            'contrato' => 'FIJO',
            'estado' => 'INACTIVO',
        ]);

        DB::table('personal_contratos')->insert([
            'id' => (string) Str::uuid(),
            'personal_id' => $personal->id,
            'contrato_numero' => 1,
            'estado' => 'CERRADO',
            'fecha_inicio' => '2026-01-01',
            'fecha_fin' => '2026-12-31',
            'motivo_cese' => 'Renuncia',
            'cerrado_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $session = $this->sessionFor($userId);

        $this->withSession($session)
            ->get(route('personal.index', ['q' => 'Trabajador Cerrado Cesado Visible']))
            ->assertOk()
            ->assertSee('TRABAJADOR CERRADO CESADO VISIBLE')
            ->assertSee('data-estado="cesado"', false)
            ->assertSee('Cesado');

        $this->withSession($session)
            ->get(route('personal.contratos.index', $personal->id))
            ->assertOk()
            ->assertSee('contract-summary-value">Cesado', false)
            ->assertSee('Renuncia');
    }

    private function createUser(array $permissions): string
    {
        $roleId = (string) Str::uuid();
        $userId = (string) Str::uuid();

        DB::table('roles')->insert([
            'id' => $roleId,
            'nombre' => 'PERSONAL_CONTRATO_COLOR_' . Str::upper(Str::random(6)),
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
