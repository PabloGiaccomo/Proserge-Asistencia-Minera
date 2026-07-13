<?php

namespace Tests\Feature;

use App\Models\Personal;
use App\Models\PersonalContrato;
use App\Models\PersonalFicha;
use App\Modules\Personal\Resources\PersonalIndexResource;
use App\Modules\Personal\Services\PersonalService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PersonalIndexResourceMineStatusTest extends TestCase
{
    use DatabaseTransactions;

    public function test_active_worker_keeps_operational_status_even_when_mine_is_not_enabled(): void
    {
        $personal = $this->createActivePersonalWithSignedContract();
        $mineId = $this->createMine('BOROO');
        $this->attachMine($personal, $mineId, 'NO_HABILITADO');

        $row = (new PersonalIndexResource(
            $personal->fresh(['minas', 'contratosLaborales', 'contratoDatos', 'fichaColaborador'])
        ))->toArray(Request::create('/personal'));

        $this->assertSame('ACTIVO', $row['estado_operativo']);
        $this->assertSame('habilitado', $row['situacion']);
        $this->assertSame('Habilitado', $row['situacion_label']);
        $this->assertSame('no_habilitado', $row['minas_estado']['BOROO']);
    }

    public function test_mine_status_uses_habilitation_state_before_legacy_state(): void
    {
        $personal = $this->createActivePersonalWithSignedContract();
        $mineId = $this->createMine('BOROO');
        $this->attachMine($personal, $mineId, 'NO_HABILITADO', 'EN_PROCESO');

        $row = (new PersonalIndexResource(
            $personal->fresh(['minas', 'contratosLaborales', 'contratoDatos', 'fichaColaborador'])
        ))->toArray(Request::create('/personal'));

        $this->assertSame('proceso', $row['minas_estado']['BOROO']);
        $this->assertSame('habilitado', $row['situacion']);
    }

    public function test_index_listing_keeps_ficha_relation_when_loaded_with_selected_columns(): void
    {
        $personal = $this->createActivePersonalWithSignedContract();
        $ficha = PersonalFicha::query()->create([
            'id' => (string) Str::uuid(),
            'personal_id' => $personal->id,
            'estado' => PersonalFicha::ESTADO_APROBADO,
            'tipo_documento' => 'DNI',
            'numero_documento' => $personal->dni,
            'datos_json' => [
                'tipo_documento' => 'DNI',
                'numero_documento' => $personal->dni,
            ],
            'submitted_at' => now(),
            'approved_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $workers = app(PersonalService::class)->listForIndex(['ids' => [$personal->id]]);
        $row = (new PersonalIndexResource($workers->first()))->toArray(Request::create('/personal'));

        $this->assertSame($ficha->id, $row['ficha_id']);
        $this->assertSame(PersonalFicha::ESTADO_APROBADO, $row['estado_ficha']);
    }

    private function createActivePersonalWithSignedContract(): Personal
    {
        $personal = Personal::query()->create([
            'id' => (string) Str::uuid(),
            'dni' => (string) random_int(41000000, 41999999),
            'tipo_documento' => 'DNI',
            'numero_documento' => (string) random_int(41000000, 41999999),
            'nombre_completo' => 'TRABAJADOR ACTIVO PRUEBA',
            'puesto' => 'OPERARIO',
            'ocupacion' => '',
            'contrato' => 'FIJO',
            'es_supervisor' => false,
            'qr_code' => 'QR-' . Str::uuid(),
            'fecha_ingreso' => '2026-01-01',
            'estado' => 'ACTIVO',
            'telefono_1' => '999999999',
            'telefono_2' => '',
            'correo' => 'activo@test.local',
        ]);

        DB::table('personal_contratos')->insert([
            'id' => (string) Str::uuid(),
            'personal_id' => $personal->id,
            'contrato_numero' => 1,
            'estado' => PersonalContrato::ESTADO_ACTIVO,
            'fecha_inicio' => '2026-01-01',
            'fecha_fin' => '2099-12-31',
            'tipo_contrato' => 'FIJO',
            'puesto' => 'OPERARIO',
            'activado_at' => '2026-01-01 08:00:00',
            'signed_at' => '2026-01-01 08:00:00',
            'signed_contract_path' => 'personal_contratos/prueba/contrato.pdf',
            'signed_contract_original_name' => 'contrato.pdf',
            'signed_contract_mime' => 'application/pdf',
            'signed_contract_size' => 8,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $personal;
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

    private function attachMine(Personal $personal, string $mineId, string $legacyState, ?string $habilitationState = null): void
    {
        DB::table('personal_mina')->insert([
            'id' => (string) Str::uuid(),
            'personal_id' => $personal->id,
            'mina_id' => $mineId,
            'estado' => $legacyState,
            'estado_habilitacion' => $habilitationState ?: $legacyState,
            'activo' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
