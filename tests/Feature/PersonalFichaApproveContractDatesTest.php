<?php

namespace Tests\Feature;

use App\Models\PersonalFicha;
use App\Models\Usuario;
use App\Modules\Personal\Services\PersonalFichaService;
use App\Modules\Personal\Support\PersonalFichaCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PersonalFichaApproveContractDatesTest extends TestCase
{
    use DatabaseTransactions;

    public function test_approve_stores_contract_dates_and_trial_period(): void
    {
        $user = Usuario::query()->findOrFail($this->createUser());
        $personalId = $this->createPersonal();
        $fichaId = $this->createFicha($personalId);

        $approved = app(PersonalFichaService::class)->approve(
            PersonalFicha::query()->with('personal')->findOrFail($fichaId),
            $user,
            'Fechas verificadas.',
            [
                'fecha_ingreso' => '2026-06-02',
                'fecha_fin_contrato' => '2026-12-31',
                'periodo_prueba_inicio' => '2026-06-02',
                'periodo_prueba_fin' => '2026-09-01',
            ],
        );

        $approved->refresh();

        $this->assertSame(PersonalFicha::ESTADO_APROBADO, $approved->estado);
        $this->assertSame('2026-06-02', $approved->datos_json['fecha_ingreso']);
        $this->assertSame('2026-12-31', $approved->datos_json['fecha_fin_contrato']);
        $this->assertSame('2026-06-02', $approved->datos_json['periodo_prueba_inicio']);
        $this->assertSame('2026-09-01', $approved->datos_json['periodo_prueba_fin']);
        $this->assertSame('2026-09-01', $approved->datos_detectados_json['periodo_prueba_fin']);
        $this->assertDatabaseHas('personal', [
            'id' => $personalId,
            'estado' => 'FALTA_CONTRATO',
            'fecha_ingreso' => '2026-06-02',
        ]);
        $this->assertDatabaseHas('personal_contrato_datos', [
            'personal_id' => $personalId,
            'fecha_inicio_contrato' => '2026-06-02',
            'fecha_fin_contrato' => '2026-12-31',
            'periodo_prueba_inicio' => '2026-06-02',
            'periodo_prueba_fin' => '2026-09-01',
            'puesto' => 'Operario',
            'updated_by_usuario_id' => $user->id,
        ]);
    }

    private function createUser(): string
    {
        $roleId = (string) Str::uuid();
        $userId = (string) Str::uuid();

        DB::table('roles')->insert([
            'id' => $roleId,
            'nombre' => 'RRHH_APROBACION_' . Str::upper(Str::random(6)),
            'permisos' => json_encode([]),
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

    private function createPersonal(): string
    {
        $personalId = (string) Str::uuid();

        DB::table('personal')->insert([
            'id' => $personalId,
            'dni' => '12345678',
            'tipo_documento' => 'DNI',
            'numero_documento' => '12345678',
            'nombre_completo' => 'PENDIENTE APROBAR',
            'puesto' => 'Por definir',
            'ocupacion' => null,
            'contrato' => 'FIJO',
            'es_supervisor' => false,
            'qr_code' => 'QR-' . Str::upper(Str::random(10)),
            'fecha_ingreso' => null,
            'estado' => PersonalFicha::ESTADO_ENVIADA,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $personalId;
    }

    private function createFicha(string $personalId): string
    {
        $fichaId = (string) Str::uuid();
        $data = [
            ...PersonalFichaCatalog::emptyData(),
            'nombres' => 'Aprobar',
            'apellido_paterno' => 'Contrato',
            'apellido_materno' => 'Prueba',
            'tipo_documento' => 'DNI',
            'numero_documento' => '12345678',
            'telefono' => '999999999',
            'correo' => 'aprobar@test.local',
            'puesto' => 'Operario',
            'contrato' => 'FIJO',
            'fecha_ingreso' => '2026-06-01',
            'fecha_fin_contrato' => '2026-11-30',
        ];

        DB::table('personal_fichas')->insert([
            'id' => $fichaId,
            'personal_id' => $personalId,
            'estado' => PersonalFicha::ESTADO_ENVIADA,
            'tipo_documento' => 'DNI',
            'numero_documento' => '12345678',
            'datos_json' => json_encode($data),
            'datos_detectados_json' => json_encode($data),
            'submitted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $fichaId;
    }
}
