<?php

namespace Tests\Feature;

use App\Models\Personal;
use App\Models\PersonalFicha;
use App\Models\PersonalFichaLink;
use App\Modules\Personal\Resources\PersonalResource;
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

    public function test_unapproved_public_submit_does_not_keep_worker_active(): void
    {
        Carbon::setTestNow('2026-06-03 09:00:00');

        $personalId = (string) Str::uuid();
        $fichaId = (string) Str::uuid();
        $linkId = (string) Str::uuid();
        $data = $this->fichaData([
            'numero_documento' => '87654321',
            'telefono' => '977777777',
            'correo' => 'revision@test.local',
            'puesto' => 'Operario pendiente',
        ]);

        DB::table('personal')->insert([
            'id' => $personalId,
            'dni' => '87654321',
            'tipo_documento' => 'DNI',
            'numero_documento' => '87654321',
            'nombre_completo' => 'Trabajador Activo Sin Aprobar',
            'puesto' => 'Operario pendiente',
            'ocupacion' => 'Operario',
            'contrato' => 'INDET',
            'es_supervisor' => false,
            'qr_code' => 'QR-' . Str::upper(Str::random(10)),
            'fecha_ingreso' => '2026-06-01',
            'estado' => 'ACTIVO',
            'telefono' => '977777777',
            'telefono_1' => '977777777',
            'correo' => 'revision@test.local',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('personal_fichas')->insert([
            'id' => $fichaId,
            'personal_id' => $personalId,
            'estado' => PersonalFicha::ESTADO_PENDIENTE,
            'tipo_documento' => 'DNI',
            'numero_documento' => '87654321',
            'datos_detectados_json' => json_encode($data),
            'datos_json' => json_encode($data),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('personal_ficha_links')->insert([
            'id' => $linkId,
            'personal_ficha_id' => $fichaId,
            'token_hash' => hash('sha256', 'active-not-approved-token'),
            'token_encrypted' => encrypt('active-not-approved-token'),
            'estado' => PersonalFichaLink::ESTADO_ACTIVO,
            'expires_at' => now()->addDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(PersonalFichaService::class)->submitFromWorker(
            PersonalFichaLink::query()->findOrFail($linkId),
            $data,
            [],
            'data:image/png;base64,pending',
            null,
            [],
        );

        $this->assertDatabaseHas('personal_fichas', [
            'id' => $fichaId,
            'estado' => PersonalFicha::ESTADO_ENVIADA,
        ]);
        $this->assertDatabaseHas('personal', [
            'id' => $personalId,
            'estado' => PersonalFicha::ESTADO_ENVIADA,
        ]);
    }

    public function test_public_mobile_submit_uses_files_already_saved_as_draft(): void
    {
        Carbon::setTestNow('2026-06-04 08:00:00');

        $personalId = (string) Str::uuid();
        $fichaId = (string) Str::uuid();
        $linkId = (string) Str::uuid();
        $token = 'mobile-public-token';
        $data = $this->fichaData([
            'numero_documento' => '45678912',
            'telefono' => '955444333',
            'correo' => 'mobile@test.local',
            'puesto' => 'Operario mobile',
        ]);

        DB::table('personal')->insert([
            'id' => $personalId,
            'dni' => '45678912',
            'tipo_documento' => 'DNI',
            'numero_documento' => '45678912',
            'nombre_completo' => 'Trabajador Mobile',
            'puesto' => 'Operario mobile',
            'ocupacion' => 'Operario',
            'contrato' => 'INDET',
            'es_supervisor' => false,
            'qr_code' => 'QR-' . Str::upper(Str::random(10)),
            'fecha_ingreso' => '2026-06-01',
            'estado' => PersonalFicha::ESTADO_PENDIENTE,
            'telefono' => '955444333',
            'telefono_1' => '955444333',
            'correo' => 'mobile@test.local',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('personal_fichas')->insert([
            'id' => $fichaId,
            'personal_id' => $personalId,
            'estado' => PersonalFicha::ESTADO_PENDIENTE,
            'tipo_documento' => 'DNI',
            'numero_documento' => '45678912',
            'datos_detectados_json' => json_encode($data),
            'datos_json' => json_encode($data),
            'huella_path' => 'personal_fichas/' . $fichaId . '/huella_borrador.jpg',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('personal_ficha_links')->insert([
            'id' => $linkId,
            'personal_ficha_id' => $fichaId,
            'token_hash' => hash('sha256', $token),
            'token_encrypted' => encrypt($token),
            'estado' => PersonalFichaLink::ESTADO_ACTIVO,
            'expires_at' => now()->addDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (array_keys(PersonalFichaCatalog::documentRequirements()) as $tipo) {
            if (in_array($tipo, ['matrimonio_union', 'dni_hijos_menores', 'dni_hijos_mayores_estudiantes', 'constancia_estudios_hijos'], true)) {
                continue;
            }

            DB::table('personal_ficha_archivos')->insert([
                'id' => (string) Str::uuid(),
                'personal_ficha_id' => $fichaId,
                'tipo' => $tipo,
                'nombre_original' => $tipo . '.pdf',
                'path' => 'personal_fichas/' . $fichaId . '/documentos/' . $tipo . '.pdf',
                'mime' => 'application/pdf',
                'size' => 1234,
                'uploaded_by_public' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('personal_ficha_archivos')->insert([
            'id' => (string) Str::uuid(),
            'personal_ficha_id' => $fichaId,
            'tipo' => 'huella',
            'nombre_original' => 'huella.jpg',
            'path' => 'personal_fichas/' . $fichaId . '/huella_borrador.jpg',
            'mime' => 'image/jpeg',
            'size' => 1234,
            'uploaded_by_public' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->post(route('ficha-colaborador.submit', ['token' => $token]), [
            'fields' => [
                ...$data,
                'telefono' => '966555444',
                'correo' => 'mobile-corregido@test.local',
                'domicilio_direccion' => 'Av. enviada desde celular 456',
            ],
            'familiares' => [],
            'firma_base64' => 'data:image/png;base64,mobile',
            'declaraciones' => collect(PersonalFichaCatalog::declarationCheckboxes())
                ->mapWithKeys(fn ($_label, string $key): array => [$key => '1'])
                ->all(),
        ]);

        $response->assertRedirect(route('ficha-colaborador.show', ['token' => $token]));

        $ficha = PersonalFicha::query()->findOrFail($fichaId);

        $this->assertSame(PersonalFicha::ESTADO_ENVIADA, $ficha->estado);
        $this->assertSame('966555444', $ficha->datos_json['telefono']);
        $this->assertSame('mobile-corregido@test.local', $ficha->datos_json['correo']);
        $this->assertSame('Av. enviada desde celular 456', $ficha->datos_json['domicilio_direccion']);
        $this->assertSame('data:image/png;base64,mobile', $ficha->firma_base64);
        $this->assertSame('personal_fichas/' . $fichaId . '/huella_borrador.jpg', $ficha->huella_path);
        $this->assertDatabaseHas('personal_ficha_links', [
            'id' => $linkId,
            'estado' => PersonalFichaLink::ESTADO_ENVIADO,
        ]);
    }

    public function test_public_draft_data_is_saved_without_submitting_ficha(): void
    {
        Carbon::setTestNow('2026-06-05 09:15:00');

        $personalId = (string) Str::uuid();
        $fichaId = (string) Str::uuid();
        $linkId = (string) Str::uuid();
        $token = 'server-draft-token';
        $baseData = $this->fichaData([
            'numero_documento' => '45871236',
            'telefono' => '900111222',
            'correo' => 'borrador@test.local',
        ]);

        DB::table('personal')->insert([
            'id' => $personalId,
            'dni' => '45871236',
            'tipo_documento' => 'DNI',
            'numero_documento' => '45871236',
            'nombre_completo' => 'Trabajador Borrador',
            'puesto' => 'Operario',
            'ocupacion' => 'Operario',
            'contrato' => 'INDET',
            'es_supervisor' => false,
            'qr_code' => 'QR-' . Str::upper(Str::random(10)),
            'estado' => PersonalFicha::ESTADO_PENDIENTE,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('personal_fichas')->insert([
            'id' => $fichaId,
            'personal_id' => $personalId,
            'estado' => PersonalFicha::ESTADO_PENDIENTE,
            'tipo_documento' => 'DNI',
            'numero_documento' => '45871236',
            'datos_detectados_json' => json_encode($baseData),
            'datos_json' => json_encode($baseData),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('personal_ficha_links')->insert([
            'id' => $linkId,
            'personal_ficha_id' => $fichaId,
            'token_hash' => hash('sha256', $token),
            'token_encrypted' => encrypt($token),
            'estado' => PersonalFichaLink::ESTADO_ACTIVO,
            'expires_at' => now()->addDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->postJson(route('ficha-colaborador.datos-borrador', ['token' => $token]), [
            'fields' => [
                'telefono' => '944333222',
                'correo' => 'guardado-servidor@test.local',
                'domicilio_direccion' => 'Calle guardada 123',
            ],
            'familiares' => [
                [
                    'parentesco' => 'Hijo',
                    'nombres_apellidos' => 'Familiar Guardado',
                    'fecha_nacimiento' => '2015-01-01',
                    'tipo_documento' => 'DNI',
                    'numero_documento' => '01234567',
                    'telefono' => '955111222',
                    'vive_con_trabajador' => '1',
                    'estudia' => '1',
                ],
            ],
            'firma_base64' => 'data:image/png;base64,draft',
            'declaraciones' => collect(PersonalFichaCatalog::declarationCheckboxes())
                ->mapWithKeys(fn ($_label, string $key): array => [$key => '1'])
                ->all(),
        ]);

        $response->assertOk();

        $ficha = PersonalFicha::query()->with('familiares')->findOrFail($fichaId);

        $this->assertSame(PersonalFicha::ESTADO_PENDIENTE, $ficha->estado);
        $this->assertNull($ficha->submitted_at);
        $this->assertSame('944333222', $ficha->datos_json['telefono']);
        $this->assertSame('guardado-servidor@test.local', $ficha->datos_json['correo']);
        $this->assertSame('Calle guardada 123', $ficha->datos_json['domicilio_direccion']);
        $this->assertSame('data:image/png;base64,draft', $ficha->firma_base64);
        $this->assertCount(1, $ficha->familiares);
        $this->assertSame('Familiar Guardado', $ficha->familiares->first()->nombres_apellidos);
    }

    public function test_public_submit_uses_server_draft_when_final_post_loses_form_fields(): void
    {
        Carbon::setTestNow('2026-06-05 10:00:00');

        $personalId = (string) Str::uuid();
        $fichaId = (string) Str::uuid();
        $linkId = (string) Str::uuid();
        $token = 'server-draft-submit-token';
        $data = $this->fichaData([
            'numero_documento' => '41785623',
            'telefono' => '977123456',
            'correo' => 'respaldo@test.local',
            'domicilio_direccion' => 'Av. respaldada 456',
            'declaraciones_json' => json_encode(array_keys(PersonalFichaCatalog::declarationCheckboxes())),
        ]);

        DB::table('personal')->insert([
            'id' => $personalId,
            'dni' => '41785623',
            'tipo_documento' => 'DNI',
            'numero_documento' => '41785623',
            'nombre_completo' => 'Trabajador Respaldo',
            'puesto' => 'Operario respaldo',
            'ocupacion' => 'Operario',
            'contrato' => 'INDET',
            'es_supervisor' => false,
            'qr_code' => 'QR-' . Str::upper(Str::random(10)),
            'estado' => PersonalFicha::ESTADO_PENDIENTE,
            'telefono' => '977123456',
            'telefono_1' => '977123456',
            'correo' => 'respaldo@test.local',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('personal_fichas')->insert([
            'id' => $fichaId,
            'personal_id' => $personalId,
            'estado' => PersonalFicha::ESTADO_PENDIENTE,
            'tipo_documento' => 'DNI',
            'numero_documento' => '41785623',
            'datos_detectados_json' => json_encode(PersonalFichaCatalog::emptyData()),
            'datos_json' => json_encode($data),
            'firma_base64' => 'data:image/png;base64,server-draft',
            'huella_path' => 'personal_fichas/' . $fichaId . '/huella_borrador.jpg',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('personal_ficha_links')->insert([
            'id' => $linkId,
            'personal_ficha_id' => $fichaId,
            'token_hash' => hash('sha256', $token),
            'token_encrypted' => encrypt($token),
            'estado' => PersonalFichaLink::ESTADO_ACTIVO,
            'expires_at' => now()->addDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (array_keys(PersonalFichaCatalog::documentRequirements()) as $tipo) {
            if (in_array($tipo, ['matrimonio_union', 'dni_hijos_menores', 'dni_hijos_mayores_estudiantes', 'constancia_estudios_hijos'], true)) {
                continue;
            }

            DB::table('personal_ficha_archivos')->insert([
                'id' => (string) Str::uuid(),
                'personal_ficha_id' => $fichaId,
                'tipo' => $tipo,
                'nombre_original' => $tipo . '.pdf',
                'path' => 'personal_fichas/' . $fichaId . '/documentos/' . $tipo . '.pdf',
                'mime' => 'application/pdf',
                'size' => 1234,
                'uploaded_by_public' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('personal_ficha_archivos')->insert([
            'id' => (string) Str::uuid(),
            'personal_ficha_id' => $fichaId,
            'tipo' => 'huella',
            'nombre_original' => 'huella.jpg',
            'path' => 'personal_fichas/' . $fichaId . '/huella_borrador.jpg',
            'mime' => 'image/jpeg',
            'size' => 1234,
            'uploaded_by_public' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->post(route('ficha-colaborador.submit', ['token' => $token]), []);

        $response->assertRedirect(route('ficha-colaborador.show', ['token' => $token]));

        $ficha = PersonalFicha::query()->findOrFail($fichaId);

        $this->assertSame(PersonalFicha::ESTADO_ENVIADA, $ficha->estado);
        $this->assertSame('977123456', $ficha->datos_json['telefono']);
        $this->assertSame('respaldo@test.local', $ficha->datos_json['correo']);
        $this->assertSame('Av. respaldada 456', $ficha->datos_json['domicilio_direccion']);
        $this->assertSame('data:image/png;base64,server-draft', $ficha->firma_base64);
    }

    public function test_observed_worker_is_shown_as_review_ficha_in_personal_list(): void
    {
        $personalId = (string) Str::uuid();

        DB::table('personal')->insert([
            'id' => $personalId,
            'dni' => '76543210',
            'tipo_documento' => 'DNI',
            'numero_documento' => '76543210',
            'nombre_completo' => 'Trabajador Observado Lista',
            'puesto' => 'Operario',
            'ocupacion' => 'Operario',
            'contrato' => 'INDET',
            'es_supervisor' => false,
            'qr_code' => 'QR-' . Str::upper(Str::random(10)),
            'fecha_ingreso' => '2026-06-01',
            'estado' => PersonalFicha::ESTADO_OBSERVADO,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = (new PersonalResource(Personal::query()->findOrFail($personalId)))->resolve();

        $this->assertSame('revisar_ficha', $row['situacion']);
        $this->assertSame('Revisar ficha', $row['situacion_label']);
    }

    public function test_pending_contract_worker_is_not_operationally_active_before_signed_contract(): void
    {
        $personalId = (string) Str::uuid();
        $fichaId = (string) Str::uuid();
        $data = $this->fichaData([
            'contrato' => 'INTER',
            'puesto' => 'Operario intermitente',
        ]);

        DB::table('personal')->insert([
            'id' => $personalId,
            'dni' => '76543211',
            'tipo_documento' => 'DNI',
            'numero_documento' => '76543211',
            'nombre_completo' => 'Trabajador Falta Contrato',
            'puesto' => 'Operario intermitente',
            'ocupacion' => 'Operario',
            'contrato' => 'INTER',
            'es_supervisor' => false,
            'qr_code' => 'QR-' . Str::upper(Str::random(10)),
            'fecha_ingreso' => null,
            'estado' => 'FALTA_CONTRATO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('personal_fichas')->insert([
            'id' => $fichaId,
            'personal_id' => $personalId,
            'estado' => PersonalFicha::ESTADO_APROBADO,
            'tipo_documento' => 'DNI',
            'numero_documento' => '76543211',
            'datos_detectados_json' => json_encode($data),
            'datos_json' => json_encode($data),
            'submitted_at' => now(),
            'approved_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('personal_contrato_datos')->insert([
            'id' => (string) Str::uuid(),
            'personal_id' => $personalId,
            'puesto' => 'Operario intermitente',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $personal = Personal::query()
            ->with(['fichaColaborador', 'contratoDatos'])
            ->findOrFail($personalId);
        $row = (new PersonalResource($personal))->resolve();

        $this->assertSame('FALTA_CONTRATO', $row['estado']);
        $this->assertSame('INACTIVO', $row['estado_operativo']);
        $this->assertFalse($row['activo']);
        $this->assertSame('falta_contrato', $row['situacion']);
        $this->assertSame('Falta contrato firmado', $row['situacion_label']);
        $this->assertFalse($row['contrato_datos_downloaded']);
        $this->assertFalse($row['contrato_firmado']);
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
