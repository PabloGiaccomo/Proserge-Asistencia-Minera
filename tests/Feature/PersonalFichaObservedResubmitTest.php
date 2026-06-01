<?php

namespace Tests\Feature;

use App\Models\PersonalFicha;
use App\Models\PersonalFichaLink;
use App\Modules\Personal\Services\PersonalFichaService;
use App\Modules\Personal\Support\PersonalFichaCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PersonalFichaObservedResubmitTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_observed_resubmit_replaces_previous_ficha_data(): void
    {
        Carbon::setTestNow('2026-06-01 10:30:00');

        $personalId = (string) Str::uuid();
        $fichaId = (string) Str::uuid();
        $linkId = (string) Str::uuid();
        $oldData = $this->fichaData([
            'telefono' => '900000001',
            'correo' => 'primero@test.local',
            'puesto' => 'Ayudante antiguo',
        ]);
        $newData = $this->fichaData([
            'telefono' => '988777666',
            'correo' => 'corregido@test.local',
            'puesto' => 'Operario corregido',
            'domicilio_direccion' => 'Av. corregida 123',
        ]);

        DB::table('personal')->insert([
            'id' => $personalId,
            'dni' => '12345678',
            'tipo_documento' => 'DNI',
            'numero_documento' => '12345678',
            'nombre_completo' => 'Trabajador Observado',
            'puesto' => 'Ayudante antiguo',
            'ocupacion' => 'Ayudante',
            'contrato' => 'INDET',
            'es_supervisor' => false,
            'qr_code' => 'QR-' . Str::upper(Str::random(10)),
            'fecha_ingreso' => '2026-05-01',
            'estado' => PersonalFicha::ESTADO_OBSERVADO,
            'telefono' => '900000001',
            'telefono_1' => '900000001',
            'correo' => 'primero@test.local',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('personal_fichas')->insert([
            'id' => $fichaId,
            'personal_id' => $personalId,
            'estado' => PersonalFicha::ESTADO_OBSERVADO,
            'tipo_documento' => 'DNI',
            'numero_documento' => '12345678',
            'datos_detectados_json' => json_encode($oldData),
            'datos_json' => json_encode($oldData),
            'firma_base64' => 'data:image/png;base64,old',
            'submitted_at' => now()->subDays(2),
            'observed_at' => now()->subDay(),
            'observaciones_revision' => 'Corrige telefono y cargo.',
            'created_at' => now()->subDays(5),
            'updated_at' => now()->subDay(),
        ]);

        DB::table('personal_ficha_links')->insert([
            'id' => $linkId,
            'personal_ficha_id' => $fichaId,
            'token_hash' => hash('sha256', 'observed-link-token'),
            'token_encrypted' => encrypt('observed-link-token'),
            'estado' => PersonalFichaLink::ESTADO_ACTIVO,
            'expires_at' => now()->addDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $submitted = app(PersonalFichaService::class)->submitFromWorker(
            PersonalFichaLink::query()->findOrFail($linkId),
            $newData,
            [],
            'data:image/png;base64,new',
            null,
            [],
        );

        $submitted->refresh();

        $this->assertSame(PersonalFicha::ESTADO_ENVIADA, $submitted->estado);
        $this->assertSame('988777666', $submitted->datos_json['telefono']);
        $this->assertSame('corregido@test.local', $submitted->datos_json['correo']);
        $this->assertSame('Operario corregido', $submitted->datos_json['puesto']);
        $this->assertSame('Av. corregida 123', $submitted->datos_json['domicilio_direccion']);
        $this->assertSame('988777666', $submitted->datos_detectados_json['telefono']);
        $this->assertSame('corregido@test.local', $submitted->datos_detectados_json['correo']);
        $this->assertSame('data:image/png;base64,new', $submitted->firma_base64);
        $this->assertDatabaseHas('personal', [
            'id' => $personalId,
            'estado' => PersonalFicha::ESTADO_ENVIADA,
        ]);
    }

    private function fichaData(array $overrides = []): array
    {
        return [
            ...PersonalFichaCatalog::emptyData(),
            'nombres' => 'Trabajador',
            'apellido_paterno' => 'Observado',
            'apellido_materno' => 'Prueba',
            'sexo' => 'Masculino',
            'estado_civil' => 'Soltero',
            'nacionalidad' => 'Peruana',
            'tipo_documento' => 'DNI',
            'numero_documento' => '12345678',
            'fecha_nacimiento' => '1990-01-15',
            'pais_nacimiento' => 'Peru',
            'departamento_nacimiento' => 'Arequipa',
            'provincia_nacimiento' => 'Arequipa',
            'distrito_nacimiento' => 'Cerro Colorado',
            'telefono' => '900000001',
            'correo' => 'primero@test.local',
            'domicilio_tipo' => 'Peru',
            'domicilio_departamento' => 'Arequipa',
            'domicilio_provincia' => 'Arequipa',
            'domicilio_distrito' => 'Cerro Colorado',
            'domicilio_direccion' => 'Av. inicial 100',
            'puesto' => 'Ayudante antiguo',
            'contrato' => 'INDET',
            'fecha_ingreso' => '2026-05-01',
            'banco' => 'BCP',
            'numero_cuenta' => '1234567890123',
            'grado_instruccion' => 'Secundaria completa',
            ...$overrides,
        ];
    }
}
