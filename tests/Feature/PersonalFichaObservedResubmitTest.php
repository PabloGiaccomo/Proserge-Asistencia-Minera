<?php

namespace Tests\Feature;

use App\Models\Personal;
use App\Models\PersonalFicha;
use App\Models\PersonalFichaLink;
use App\Models\PersonalPuesto;
use App\Models\Usuario;
use App\Modules\Personal\Resources\PersonalResource;
use App\Modules\Personal\Services\PersonalFichaService;
use App\Modules\Personal\Support\PersonalFichaCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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

    public function test_public_submit_allows_missing_documents_for_later_regularization(): void
    {
        Carbon::setTestNow('2026-06-11 09:00:00');

        $personalId = (string) Str::uuid();
        $fichaId = (string) Str::uuid();
        $linkId = (string) Str::uuid();
        $token = 'submit-without-documents-token';
        $data = $this->fichaData([
            'numero_documento' => '45871299',
            'telefono' => '955111333',
            'correo' => 'sin-documentos@test.local',
            'puesto' => 'Operario sin documentos',
        ]);

        DB::table('personal')->insert([
            'id' => $personalId,
            'dni' => '45871299',
            'tipo_documento' => 'DNI',
            'numero_documento' => '45871299',
            'nombre_completo' => 'Trabajador Sin Documentos',
            'puesto' => 'Operario sin documentos',
            'ocupacion' => 'Operario',
            'contrato' => 'INDET',
            'es_supervisor' => false,
            'qr_code' => 'QR-' . Str::upper(Str::random(10)),
            'estado' => PersonalFicha::ESTADO_PENDIENTE,
            'telefono' => '955111333',
            'telefono_1' => '955111333',
            'correo' => 'sin-documentos@test.local',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('personal_fichas')->insert([
            'id' => $fichaId,
            'personal_id' => $personalId,
            'estado' => PersonalFicha::ESTADO_PENDIENTE,
            'tipo_documento' => 'DNI',
            'numero_documento' => '45871299',
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

        $this->get(route('ficha-colaborador.show', ['token' => $token]))
            ->assertOk()
            ->assertSee('Guia para completar tu ficha')
            ->assertSee('Abrir reporte de experiencia')
            ->assertSee('quedara pendiente de regularizacion documentaria');

        $response = $this->post(route('ficha-colaborador.submit', ['token' => $token]), [
            'fields' => $data,
            'familiares' => [],
            'firma_base64' => 'data:image/png;base64,signature',
            'declaraciones' => collect(PersonalFichaCatalog::declarationCheckboxes())
                ->mapWithKeys(fn ($_label, string $key): array => [$key => '1'])
                ->all(),
        ]);

        $response->assertRedirect(route('ficha-colaborador.show', ['token' => $token]));
        $response->assertSessionHasNoErrors();

        $ficha = PersonalFicha::query()->with(['personal', 'link', 'archivos'])->findOrFail($fichaId);
        $summary = app(PersonalFichaService::class)->regularizationSummary($ficha);

        $this->assertSame(PersonalFicha::ESTADO_ENVIADA, $ficha->estado);
        $this->assertSame(PersonalFicha::ESTADO_ENVIADA, $ficha->personal->estado);
        $this->assertSame(PersonalFichaLink::ESTADO_ENVIADO, $ficha->link->estado);
        $this->assertNotEmpty($summary['missing_documents']);
        $this->assertTrue($summary['can_regularize']);
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

    public function test_manual_edit_can_replace_signature_and_huella(): void
    {
        Storage::fake('local');
        Carbon::setTestNow('2026-06-13 10:00:00');

        $roleId = (string) Str::uuid();
        $userId = (string) Str::uuid();

        DB::table('roles')->insert([
            'id' => $roleId,
            'nombre' => 'RRHH_FIRMA_HUELLA_' . Str::upper(Str::random(6)),
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

        $actor = Usuario::query()->findOrFail($userId);
        $personalId = (string) Str::uuid();
        $fichaId = (string) Str::uuid();
        $oldHuellaPath = 'personal_fichas/' . $fichaId . '/huella_antigua.jpg';
        $data = $this->fichaData([
            'numero_documento' => '45879614',
            'telefono' => '977111224',
            'correo' => 'firma-huella@test.local',
            'puesto' => 'Operario firma huella',
        ]);

        Storage::disk('local')->put($oldHuellaPath, 'huella anterior');

        DB::table('personal')->insert([
            'id' => $personalId,
            'dni' => '45879614',
            'tipo_documento' => 'DNI',
            'numero_documento' => '45879614',
            'nombre_completo' => 'Trabajador Firma Huella',
            'puesto' => 'Operario firma huella',
            'ocupacion' => 'Operario',
            'contrato' => 'INDET',
            'es_supervisor' => false,
            'qr_code' => 'QR-' . Str::upper(Str::random(10)),
            'estado' => PersonalFicha::ESTADO_ENVIADA,
            'telefono' => '977111224',
            'telefono_1' => '977111224',
            'correo' => 'firma-huella@test.local',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('personal_fichas')->insert([
            'id' => $fichaId,
            'personal_id' => $personalId,
            'estado' => PersonalFicha::ESTADO_ENVIADA,
            'tipo_documento' => 'DNI',
            'numero_documento' => '45879614',
            'datos_detectados_json' => json_encode($data),
            'datos_json' => json_encode($data),
            'firma_base64' => 'data:image/png;base64,old-internal',
            'huella_path' => $oldHuellaPath,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('personal_ficha_archivos')->insert([
            'id' => (string) Str::uuid(),
            'personal_ficha_id' => $fichaId,
            'tipo' => 'huella',
            'nombre_original' => 'huella-antigua.jpg',
            'path' => $oldHuellaPath,
            'mime' => 'image/jpeg',
            'size' => 128,
            'uploaded_by_public' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $personal = Personal::query()->with('fichaColaborador')->findOrFail($personalId);

        app(PersonalFichaService::class)->updateManual($personal, $data, [
            'estado' => PersonalFicha::ESTADO_ENVIADA,
            'es_supervisor' => false,
            'minas' => [],
            'familiares' => [],
            'documentos' => [],
            'firma_base64' => 'data:image/png;base64,new-internal',
            'huella' => UploadedFile::fake()->image('huella-nueva.jpg', 320, 320),
        ], $actor);

        $ficha = PersonalFicha::query()->with('archivos')->findOrFail($fichaId);
        $huella = $ficha->archivos->firstWhere('tipo', 'huella');

        $this->assertSame('data:image/png;base64,new-internal', $ficha->firma_base64);
        $this->assertNotSame($oldHuellaPath, $ficha->huella_path);
        $this->assertNotNull($huella);
        $this->assertSame('huella-nueva.jpg', $huella->nombre_original);
        $this->assertFalse((bool) $huella->uploaded_by_public);
        $this->assertSame($actor->id, $huella->uploaded_by_usuario_id);
        Storage::disk('local')->assertExists($ficha->huella_path);
        Storage::disk('local')->assertMissing($oldHuellaPath);
    }

    public function test_active_temporary_link_without_manual_marker_is_immediately_usable(): void
    {
        Carbon::setTestNow('2026-06-10 08:00:00');

        $personalId = (string) Str::uuid();
        $fichaId = (string) Str::uuid();
        $linkId = (string) Str::uuid();
        $token = 'active-link-without-manual-marker';
        $data = $this->fichaData([
            'numero_documento' => '45879612',
            'telefono' => '977111222',
            'correo' => 'link-activo@test.local',
            'puesto' => 'Operario link activo',
        ]);

        DB::table('personal')->insert([
            'id' => $personalId,
            'dni' => '45879612',
            'tipo_documento' => 'DNI',
            'numero_documento' => '45879612',
            'nombre_completo' => 'Trabajador Link Activo',
            'puesto' => 'Operario link activo',
            'ocupacion' => 'Operario',
            'contrato' => 'INDET',
            'es_supervisor' => false,
            'qr_code' => 'QR-' . Str::upper(Str::random(10)),
            'estado' => PersonalFicha::ESTADO_PENDIENTE,
            'telefono' => '977111222',
            'telefono_1' => '977111222',
            'correo' => 'link-activo@test.local',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('personal_fichas')->insert([
            'id' => $fichaId,
            'personal_id' => $personalId,
            'estado' => PersonalFicha::ESTADO_PENDIENTE,
            'tipo_documento' => 'DNI',
            'numero_documento' => '45879612',
            'datos_detectados_json' => json_encode($data),
            'datos_json' => json_encode($data),
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
            'enabled_manually_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = app(PersonalFichaService::class);
        $ficha = PersonalFicha::query()->with(['personal', 'link', 'archivos'])->findOrFail($fichaId);

        $row = $service->temporaryLinkRow($ficha);

        $this->assertNotNull($row);
        $this->assertSame(route('ficha-colaborador.show', ['token' => $token]), $row['url']);
        $this->assertSame($fichaId, $row['link']->personal_ficha_id);
        $this->assertSame('edit', $service->resolveToken($token)['mode']);

        $extended = $service->extendLink($ficha, 24);

        $this->assertNotNull($extended->enabled_manually_at);
        $this->assertSame(PersonalFichaLink::ESTADO_ACTIVO, $extended->estado);
    }

    public function test_regularization_link_does_not_reuse_disabled_or_submitted_active_link(): void
    {
        Carbon::setTestNow('2026-06-12 11:00:00');

        $personalId = (string) Str::uuid();
        $fichaId = (string) Str::uuid();
        $oldLinkId = (string) Str::uuid();
        $oldToken = Str::random(80);
        $data = $this->fichaData([
            'correo' => 'regularizar@test.local',
            'puesto' => 'Ayudante regularizacion',
        ]);

        DB::table('personal')->insert([
            'id' => $personalId,
            'dni' => '45879613',
            'tipo_documento' => 'DNI',
            'numero_documento' => '45879613',
            'nombre_completo' => 'Trabajador Regularizacion',
            'puesto' => 'Ayudante regularizacion',
            'ocupacion' => 'Ayudante',
            'contrato' => 'INDET',
            'es_supervisor' => false,
            'qr_code' => 'QR-' . Str::upper(Str::random(10)),
            'estado' => PersonalFicha::ESTADO_OBSERVADO,
            'telefono' => '977111223',
            'telefono_1' => '977111223',
            'correo' => 'regularizar@test.local',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('personal_fichas')->insert([
            'id' => $fichaId,
            'personal_id' => $personalId,
            'estado' => PersonalFicha::ESTADO_OBSERVADO,
            'tipo_documento' => 'DNI',
            'numero_documento' => '45879613',
            'datos_detectados_json' => json_encode($data),
            'datos_json' => json_encode($data),
            'firma_base64' => 'data:image/png;base64,old',
            'submitted_at' => now()->subDays(2),
            'observed_at' => now()->subDay(),
            'observaciones_revision' => 'Regularizar documentos pendientes.',
            'created_at' => now()->subDays(3),
            'updated_at' => now()->subDay(),
        ]);

        DB::table('personal_ficha_links')->insert([
            'id' => $oldLinkId,
            'personal_ficha_id' => $fichaId,
            'token_hash' => hash('sha256', $oldToken),
            'token_encrypted' => encrypt($oldToken),
            'estado' => PersonalFichaLink::ESTADO_ACTIVO,
            'expires_at' => now()->addDay(),
            'submitted_at' => now()->subDays(2),
            'disabled_at' => now()->subHour(),
            'enabled_manually_at' => now()->subDay(),
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ]);

        $service = app(PersonalFichaService::class);
        $ficha = PersonalFicha::query()->with(['personal', 'link', 'archivos'])->findOrFail($fichaId);

        $result = $service->ensureRegularizationLink($ficha, 24);
        $newLink = $result['link'];
        $newToken = Str::afterLast((string) $result['url'], '/');

        $this->assertNotSame($oldLinkId, $newLink->id);
        $this->assertSame(PersonalFichaLink::ESTADO_INHABILITADO, PersonalFichaLink::query()->findOrFail($oldLinkId)->estado);
        $this->assertSame(PersonalFichaLink::ESTADO_ACTIVO, $newLink->estado);
        $this->assertNull($newLink->submitted_at);
        $this->assertNull($newLink->disabled_at);
        $this->assertNotNull($newLink->enabled_manually_at);
        $this->assertSame('edit', $service->resolveToken($newToken)['mode']);

        $summary = $service->regularizationSummary($ficha->fresh(['personal', 'link', 'archivos']));

        $this->assertTrue($summary['has_active_link']);
        $this->assertSame($result['url'], $summary['url']);
    }

    public function test_activate_temporary_link_for_worker_creates_link_for_three_days(): void
    {
        Carbon::setTestNow('2026-06-15 09:00:00');

        $personalId = (string) Str::uuid();
        DB::table('personal')->insert([
            'id' => $personalId,
            'dni' => '45879615',
            'tipo_documento' => 'DNI',
            'numero_documento' => '45879615',
            'nombre_completo' => 'Trabajador Link Tres Dias',
            'puesto' => 'Operario link tres dias',
            'ocupacion' => 'Operario',
            'contrato' => 'INDET',
            'es_supervisor' => false,
            'qr_code' => 'QR-' . Str::upper(Str::random(10)),
            'estado' => PersonalFicha::ESTADO_PENDIENTE,
            'telefono' => '977111225',
            'telefono_1' => '977111225',
            'correo' => 'link-tres-dias@test.local',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = app(PersonalFichaService::class)->activateTemporaryLinkForPersonal(
            Personal::query()->findOrFail($personalId),
            $this->createUsuario(),
        );

        $link = $result['link'];
        $this->assertSame(PersonalFichaLink::ESTADO_ACTIVO, $link->estado);
        $this->assertNotNull($link->enabled_manually_at);
        $this->assertTrue(now()->copy()->addHours(PersonalFichaService::DEFAULT_LINK_HOURS)->equalTo($link->expires_at));
        $this->assertSame('edit', app(PersonalFichaService::class)->resolveToken(Str::afterLast($result['url'], '/'))['mode']);
    }

    public function test_activate_existing_usable_temporary_link_extends_short_window_to_three_days(): void
    {
        Carbon::setTestNow('2026-06-15 09:00:00');

        $personalId = (string) Str::uuid();
        $fichaId = (string) Str::uuid();
        $linkId = (string) Str::uuid();
        $token = 'short-active-link-token';
        $data = $this->fichaData([
            'numero_documento' => '45879616',
            'telefono' => '977111226',
            'correo' => 'short-link@test.local',
            'puesto' => 'Operario link corto',
        ]);

        DB::table('personal')->insert([
            'id' => $personalId,
            'dni' => '45879616',
            'tipo_documento' => 'DNI',
            'numero_documento' => '45879616',
            'nombre_completo' => 'Trabajador Link Corto',
            'puesto' => 'Operario link corto',
            'ocupacion' => 'Operario',
            'contrato' => 'INDET',
            'es_supervisor' => false,
            'qr_code' => 'QR-' . Str::upper(Str::random(10)),
            'estado' => PersonalFicha::ESTADO_PENDIENTE,
            'telefono' => '977111226',
            'telefono_1' => '977111226',
            'correo' => 'short-link@test.local',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('personal_fichas')->insert([
            'id' => $fichaId,
            'personal_id' => $personalId,
            'estado' => PersonalFicha::ESTADO_PENDIENTE,
            'tipo_documento' => 'DNI',
            'numero_documento' => '45879616',
            'datos_detectados_json' => json_encode($data),
            'datos_json' => json_encode($data),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('personal_ficha_links')->insert([
            'id' => $linkId,
            'personal_ficha_id' => $fichaId,
            'token_hash' => hash('sha256', $token),
            'token_encrypted' => encrypt($token),
            'estado' => PersonalFichaLink::ESTADO_ACTIVO,
            'expires_at' => now()->addHour(),
            'enabled_manually_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = app(PersonalFichaService::class)->activateTemporaryLinkForPersonal(
            Personal::query()->findOrFail($personalId),
            $this->createUsuario(),
        );

        $this->assertSame($linkId, $result['link']->id);
        $this->assertTrue(now()->copy()->addHours(PersonalFichaService::DEFAULT_LINK_HOURS)->equalTo($result['link']->expires_at));
        $this->assertNotNull($result['link']->enabled_manually_at);
        $this->assertSame('edit', app(PersonalFichaService::class)->resolveToken($token)['mode']);
    }

    public function test_regularization_reuses_active_link_and_extends_short_window_to_three_days(): void
    {
        Carbon::setTestNow('2026-06-15 09:00:00');

        $personalId = (string) Str::uuid();
        $fichaId = (string) Str::uuid();
        $linkId = (string) Str::uuid();
        $token = 'regularization-short-link-token';
        $data = $this->fichaData([
            'numero_documento' => '45879617',
            'telefono' => '977111227',
            'correo' => 'regularization-short@test.local',
            'puesto' => 'Operario regularizacion corta',
        ]);

        DB::table('personal')->insert([
            'id' => $personalId,
            'dni' => '45879617',
            'tipo_documento' => 'DNI',
            'numero_documento' => '45879617',
            'nombre_completo' => 'Trabajador Regularizacion Corta',
            'puesto' => 'Operario regularizacion corta',
            'ocupacion' => 'Operario',
            'contrato' => 'INDET',
            'es_supervisor' => false,
            'qr_code' => 'QR-' . Str::upper(Str::random(10)),
            'estado' => PersonalFicha::ESTADO_OBSERVADO,
            'telefono' => '977111227',
            'telefono_1' => '977111227',
            'correo' => 'regularization-short@test.local',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('personal_fichas')->insert([
            'id' => $fichaId,
            'personal_id' => $personalId,
            'estado' => PersonalFicha::ESTADO_OBSERVADO,
            'tipo_documento' => 'DNI',
            'numero_documento' => '45879617',
            'datos_detectados_json' => json_encode($data),
            'datos_json' => json_encode($data),
            'submitted_at' => now()->subDay(),
            'observed_at' => now()->subHour(),
            'observaciones_revision' => 'Regularizar datos pendientes.',
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subHour(),
        ]);

        DB::table('personal_ficha_links')->insert([
            'id' => $linkId,
            'personal_ficha_id' => $fichaId,
            'token_hash' => hash('sha256', $token),
            'token_encrypted' => encrypt($token),
            'estado' => PersonalFichaLink::ESTADO_ACTIVO,
            'expires_at' => now()->addHour(),
            'enabled_manually_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $ficha = PersonalFicha::query()->with(['personal', 'link', 'archivos'])->findOrFail($fichaId);
        $result = app(PersonalFichaService::class)->ensureRegularizationLink($ficha);

        $this->assertSame($linkId, $result['link']->id);
        $this->assertTrue(now()->copy()->addHours(PersonalFichaService::DEFAULT_LINK_HOURS)->equalTo($result['link']->expires_at));
        $this->assertNotNull($result['link']->enabled_manually_at);
        $this->assertSame(route('ficha-colaborador.show', ['token' => $token]), $result['url']);
    }

    private function fichaData(array $overrides = []): array
    {
        $data = [
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

        $this->ensurePuestoCatalogo($data['puesto'] ?? null);

        return $data;
    }

    private function ensurePuestoCatalogo(?string $nombre): void
    {
        $nombre = trim((string) $nombre);
        if ($nombre === '') {
            return;
        }

        PersonalPuesto::query()->firstOrCreate(
            ['nombre' => $nombre],
            [
                'id' => (string) Str::uuid(),
                'funciones' => null,
                'activo' => true,
            ]
        );
    }

    private function createUsuario(): Usuario
    {
        $roleId = (string) Str::uuid();
        $userId = (string) Str::uuid();

        DB::table('roles')->insert([
            'id' => $roleId,
            'nombre' => 'RRHH_LINKS_' . Str::upper(Str::random(6)),
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

        return Usuario::query()->findOrFail($userId);
    }
}
