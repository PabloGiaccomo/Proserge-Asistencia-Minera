<?php

namespace Tests\Feature;

use App\Models\Personal;
use App\Models\Usuario;
use App\Modules\Personal\Services\PersonalContratoService;
use App\Modules\Personal\Services\PersonalService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class PersonalContratoServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_cese_guarda_snapshot_y_activacion_abre_siguiente_contrato(): void
    {
        Carbon::setTestNow('2026-06-01 09:00:00');

        $actor = Usuario::query()->find($this->createUser($this->createRole('RRHH_CONTRATOS'), 'rrhh'));
        $workerRoleId = $this->createRole('TRABAJADOR_CONTRATOS');
        $personalId = $this->createPersonal();
        $this->createFicha($personalId);
        $this->createUser($workerRoleId, 'trabajador', $personalId);

        $personal = Personal::query()->with('fichaColaborador')->findOrFail($personalId);
        app(PersonalService::class)->markCeased($personal, 'Termino de contrato', $actor, '2026-05-31');

        $expectedPersonal = [
            'id' => $personalId,
            'estado' => 'CESADO',
        ];
        if (Schema::hasColumn('personal', 'fecha_cese')) {
            $expectedPersonal['fecha_cese'] = '2026-05-31';
        }
        if (Schema::hasColumn('personal', 'motivo_cese')) {
            $expectedPersonal['motivo_cese'] = 'Termino de contrato';
        }
        $this->assertDatabaseHas('personal', $expectedPersonal);

        $closed = DB::table('personal_contratos')
            ->where('personal_id', $personalId)
            ->where('contrato_numero', 1)
            ->first();

        $this->assertNotNull($closed);
        $this->assertSame('CERRADO', $closed->estado);
        $this->assertSame('2026-05-31', $closed->fecha_fin);

        $snapshot = json_decode($closed->snapshot_json, true);
        $this->assertSame('cierre_contrato', $snapshot['evento']);
        $this->assertSame('Termino de contrato', data_get($snapshot, 'extra.motivo_cese'));
        $this->assertSame('Contrato Test', data_get($snapshot, 'trabajador.nombre_completo'));
        $this->assertTrue((bool) data_get($snapshot, 'usuario_proserge.tiene_usuario'));
        $this->assertSame('trabajador-contrato@test.local', data_get($snapshot, 'usuario_proserge.usuario.email'));

        $newContract = app(PersonalContratoService::class)->activateNextContract(
            Personal::query()->findOrFail($personalId),
            '2026-06-01',
            null,
            $actor,
        );

        $this->assertSame(2, (int) $newContract->contrato_numero);
        $this->assertSame($closed->id, $newContract->origen_contrato_id);
        $this->assertDatabaseHas('personal_contratos', [
            'personal_id' => $personalId,
            'contrato_numero' => 2,
            'estado' => 'ACTIVO',
            'fecha_inicio' => '2026-06-01',
            'fecha_fin' => null,
        ]);
        $expectedActivatedPersonal = [
            'id' => $personalId,
            'estado' => 'ACTIVO',
            'fecha_ingreso' => '2026-06-01',
        ];
        if (Schema::hasColumn('personal', 'fecha_cese')) {
            $expectedActivatedPersonal['fecha_cese'] = null;
        }
        if (Schema::hasColumn('personal', 'motivo_cese')) {
            $expectedActivatedPersonal['motivo_cese'] = null;
        }
        $this->assertDatabaseHas('personal', $expectedActivatedPersonal);

        $fichaData = json_decode(DB::table('personal_fichas')->where('personal_id', $personalId)->value('datos_json'), true);
        $this->assertSame('2026-06-01', $fichaData['fecha_ingreso']);
        $this->assertSame('', $fichaData['fecha_fin_contrato']);

    }

    private function createRole(string $name): string
    {
        $id = (string) Str::uuid();

        DB::table('roles')->insert([
            'id' => $id,
            'nombre' => $name . '_' . Str::upper(Str::random(6)),
            'permisos' => json_encode([]),
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function createUser(string $roleId, string $prefix, ?string $personalId = null): string
    {
        $id = (string) Str::uuid();

        DB::table('usuarios')->insert([
            'id' => $id,
            'email' => $prefix === 'trabajador' ? 'trabajador-contrato@test.local' : $prefix . '-' . Str::lower(Str::random(6)) . '@test.local',
            'password' => bcrypt('secret123'),
            'rol_id' => $roleId,
            'personal_id' => $personalId,
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function createPersonal(): string
    {
        $id = (string) Str::uuid();

        DB::table('personal')->insert([
            'id' => $id,
            'dni' => (string) random_int(10000000, 99999999),
            'tipo_documento' => 'DNI',
            'numero_documento' => (string) random_int(10000000, 99999999),
            'nombre_completo' => 'Contrato Test',
            'puesto' => 'Operario',
            'ocupacion' => 'Tecnico',
            'contrato' => 'FIJO',
            'es_supervisor' => false,
            'qr_code' => 'QR-' . Str::upper(Str::random(10)),
            'fecha_ingreso' => '2026-01-15',
            'estado' => 'ACTIVO',
            'telefono' => '999999999',
            'telefono_1' => '999999999',
            'correo' => 'contrato@test.local',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function createFicha(string $personalId): void
    {
        DB::table('personal_fichas')->insert([
            'id' => (string) Str::uuid(),
            'personal_id' => $personalId,
            'estado' => 'APROBADO',
            'tipo_documento' => 'DNI',
            'numero_documento' => '12345678',
            'datos_json' => json_encode([
                'contrato' => 'FIJO',
                'fecha_ingreso' => '2026-01-15',
                'fecha_fin_contrato' => '2026-05-31',
                'banco' => 'BCP',
                'numero_cuenta' => '123-456',
            ]),
            'datos_detectados_json' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
