<?php

namespace Tests\Feature;

use App\Models\Personal;
use App\Models\PersonalContrato;
use App\Models\Rol;
use App\Models\Usuario;
use App\Modules\Personal\Services\PersonalContratoSignedBatchService;
use App\Support\Rbac\PermissionCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class PersonalContratoSignedBatchTest extends TestCase
{
    use DatabaseTransactions;

    public function test_uploads_and_confirms_multiple_pdfs_by_exact_contract_and_resumes_results(): void
    {
        Storage::fake('local');
        $actor = $this->actor();
        $this->login($actor);
        $first = $this->contract();
        $second = $this->contract();
        $this->get('/personal/contratos/firmados')->assertOk()->assertSee('Carga de contratos firmados');
        $lot = $this->postJson('/personal/contratos/firmados/lotes')->assertCreated()->json('id');
        $base = '/personal/contratos/firmados/lotes/'.$lot;
        $this->postJson('/personal/contratos/firmados/buscar', ['q' => $first->personal->dni])
            ->assertOk()->assertJsonPath('contratos.0.id', $first->id)->assertDontSee($first->personal->dni);
        $rows = [];
        foreach ([$first, $second] as $index => $contract) {
            $row = $this->postJson($base.'/archivos', [
                'archivo' => $this->pdf('firmado-'.$index.'.pdf', 'Contrato de prueba '.$index),
                'operacion_id' => (string) Str::uuid(),
            ])->assertCreated()->json('id');
            $this->patchJson($base.'/archivos/'.$row, ['contrato_id' => $contract->id])->assertOk();
            $this->get($base.'/archivos/'.$row.'/pdf')->assertOk()->assertHeader('Referrer-Policy', 'no-referrer');
            $rows[] = $row;
        }
        self::assertFalse($first->fresh()->hasSignedFile());
        self::assertFalse($second->fresh()->hasSignedFile());
        $preview = $this->getJson($base)->assertOk()->json();
        $this->postJson($base.'/confirmar', ['revision' => $preview['revision'], 'archivos' => $rows, 'revisado' => true])
            ->assertOk()->assertJsonCount(2, 'resultados')->assertJsonPath('resultados.0.estado', 'GUARDADO');
        self::assertSame('firmado-0.pdf', $first->fresh()->signed_contract_original_name);
        self::assertSame('firmado-1.pdf', $second->fresh()->signed_contract_original_name);
        $saved = $first->fresh()->signed_contract_path;
        $this->postJson($base.'/confirmar', ['revision' => $preview['revision'], 'archivos' => $rows, 'revisado' => true])
            ->assertOk()->assertJsonPath('resultados.0.estado', 'GUARDADO');
        self::assertSame($saved, $first->fresh()->signed_contract_path);
        $this->getJson($base)->assertOk()->assertJsonPath('archivos.0.estado', 'GUARDADO');

        $this->login($this->actor());
        $this->getJson($base)->assertNotFound();
        $this->get($base.'/archivos/'.$rows[0].'/pdf')->assertNotFound();
        $this->login($actor);
        $this->travel(3)->hours();
        $this->getJson($base)->assertGone();
        app(PersonalContratoSignedBatchService::class)->purgeExpiredFiles();
        Storage::disk('local')->assertMissing('contratos-firmados-temporales/'.$lot.'/'.$rows[0].'.pdf');
        Storage::disk('local')->assertExists($saved);
    }

    public function test_invalid_file_and_changed_period_cannot_activate_any_contract(): void
    {
        Storage::fake('local');
        $this->login($this->actor());
        $contract = $this->contract();
        $lot = $this->postJson('/personal/contratos/firmados/lotes')->assertCreated()->json('id');
        $base = '/personal/contratos/firmados/lotes/'.$lot;
        $this->postJson($base.'/archivos', [
            'archivo' => UploadedFile::fake()->createWithContent('invalido.pdf', '%PDF-1.4 contenido incompleto'),
            'operacion_id' => (string) Str::uuid(),
        ])->assertUnprocessable();
        $row = $this->postJson($base.'/archivos', [
            'archivo' => $this->pdf('firmado.pdf', 'Contrato de prueba'), 'operacion_id' => (string) Str::uuid(),
        ])->assertCreated()->json('id');
        $this->patchJson($base.'/archivos/'.$row, ['contrato_id' => $contract->id])->assertOk();
        $preview = $this->getJson($base)->assertOk()->json();
        $contract->forceFill(['remuneracion' => '9999'])->save();
        $this->postJson($base.'/confirmar', ['revision' => $preview['revision'], 'archivos' => [$row], 'revisado' => true])
            ->assertOk()->assertJsonPath('resultados.0.estado', 'REVISAR');
        self::assertFalse($contract->fresh()->hasSignedFile());
    }

    public function test_future_pdfs_become_eligible_for_bulk_activation_on_start_date(): void
    {
        Storage::fake('local');
        $this->login($this->actor());
        $contract = $this->contract();
        $contract->forceFill(['fecha_inicio' => now(config('app.display_timezone', 'America/Lima'))->addDays(2)->toDateString()])->save();
        $base = '/personal/contratos/firmados';
        $lot = $this->postJson($base.'/lotes')->assertCreated()->json('id');
        $upload = $base.'/lotes/'.$lot;
        $row = $this->postJson($upload.'/archivos', [
            'archivo' => $this->pdf('futuro.pdf', 'Contrato con inicio futuro'), 'operacion_id' => (string) Str::uuid(),
        ])->assertCreated()->json('id');
        $this->patchJson($upload.'/archivos/'.$row, ['contrato_id' => $contract->id])->assertOk();
        $revision = $this->getJson($upload)->assertOk()->json('revision');
        $this->postJson($upload.'/confirmar', ['revision' => $revision, 'archivos' => [$row], 'revisado' => true])
            ->assertOk()->assertJsonPath('resultados.0.estado', 'FIRMADO_FUTURO');
        $this->getJson($base.'/listos')->assertOk()->assertJsonCount(0, 'contratos');
        $this->travel(2)->days();
        $ready = $this->getJson($base.'/listos')->assertOk()->assertJsonPath('contratos.0.id', $contract->id)->json('contratos.0');
        $payload = ['contratos' => [['id' => $ready['id'], 'revision' => $ready['revision']]], 'revisado' => true];
        $contract->refresh()->forceFill(['remuneracion' => '8888'])->save();
        $this->postJson($base.'/activar', $payload)->assertOk()->assertJsonPath('resultados.0.estado', 'REVISAR');
        self::assertSame('PREPARACION', $contract->fresh()->estado);
        $payload['contratos'][0]['revision'] = $this->getJson($base.'/listos')->assertOk()->json('contratos.0.revision');
        $this->postJson($base.'/activar', $payload)->assertOk()->assertJsonPath('resultados.0.estado', 'ACTIVO');
        $this->postJson($base.'/activar', $payload)->assertOk()->assertJsonPath('resultados.0.estado', 'ACTIVO');
        self::assertSame(PersonalContrato::ESTADO_ACTIVO, $contract->fresh()->estado);
        $this->travelBack();
    }

    private function actor(): Usuario
    {
        $role = Rol::query()->create([
            'id' => (string) Str::uuid(), 'nombre' => 'RRHH_PRUEBA_'.Str::random(8), 'estado' => 'ACTIVO',
            'permisos' => PermissionCatalog::matrixFromSelections(['personal' => ['subir_contrato_firmado', 'descargar_documentos']]),
        ]);

        return Usuario::query()->create([
            'id' => (string) Str::uuid(), 'email' => Str::uuid().'@test.local', 'password' => 'synthetic-password',
            'estado' => 'ACTIVO', 'rol_id' => $role->id,
        ]);
    }

    private function login(Usuario $actor): void
    {
        $this->withSession(['auth_token' => 'test', 'user_id' => $actor->id, 'user' => ['id' => $actor->id]]);
    }

    private function contract(): PersonalContrato
    {
        $person = Personal::query()->create([
            'id' => (string) Str::uuid(), 'dni' => (string) random_int(10000000, 99999999),
            'nombre_completo' => 'Trabajador de prueba '.Str::random(8), 'puesto' => 'Operario',
            'qr_code' => (string) Str::uuid(), 'estado' => 'FALTA_CONTRATO',
        ]);

        return PersonalContrato::query()->create([
            'id' => (string) Str::uuid(), 'personal_id' => $person->id, 'contrato_numero' => 1,
            'estado' => 'PREPARACION', 'fecha_inicio' => now()->subDay()->toDateString(),
        ]);
    }

    private function pdf(string $name, string $content): UploadedFile
    {
        $pdf = new \FPDF;
        $pdf->AddPage();
        $pdf->SetFont('Arial', '', 12);
        $pdf->Cell(40, 10, $content);

        return UploadedFile::fake()->createWithContent($name, $pdf->Output('S'));
    }
}
