<?php

namespace Tests\Feature;

use App\Models\Personal;
use App\Modules\Personal\Services\MiningExamCatalogOrganizationService;
use App\Modules\Personal\Services\PersonalMinaBulkPreviewService;
use App\Modules\Personal\Services\PersonalMinaExcelImportService;
use App\Modules\Personal\Services\PersonalMinaHabilitacionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Support\MiningCatalogFixtures;
use Tests\TestCase;

class MiningExamIdentityIntegrationTest extends TestCase
{
    use DatabaseTransactions, MiningCatalogFixtures;

    public function test_bulk_resolves_confirmed_group_to_destination_requirement_in_both_directions(): void
    {
        $principal = $this->exam('ESPACIO CONFINADO');
        $variant = $this->exam('ESPACIOS CONFINADOS');
        $this->associate($variant, $principal);
        $worker = $this->worker();
        foreach ([[$principal, $variant], [$variant, $principal]] as [$excel, $required]) {
            $mine = $this->mine();
            $this->requirement($mine, $required);
            $row = $this->bulkRow($worker, $excel->nombre, $mine->nombre);
            $result = app(PersonalMinaBulkPreviewService::class)->plan([$row])[0];
            $this->assertSame($required->id, $result['examen_id']);
            $this->assertSame([], $result['issues']);
        }
    }

    public function test_bulk_blocks_two_requirements_in_same_group_and_suggests_unconfirmed_spelling(): void
    {
        $principal = $this->exam('IZAJE');
        $variant = $this->exam('IZAGE');
        $mine = $this->mine();
        $worker = $this->worker();
        $service = app(PersonalMinaBulkPreviewService::class);
        $missing = $service->plan([$this->bulkRow($worker, 'IZAJEE', $mine->nombre)])[0];
        $this->assertNull($missing['examen_id']);
        $this->assertNotEmpty($missing['examenes_parecidos'] ?? []);
        $this->associate($variant, $principal);
        $this->requirement($mine, $principal);
        $this->requirement($mine, $variant);
        $ambiguous = $service->plan([$this->bulkRow($worker, 'IZAJE', $mine->nombre)])[0];
        $this->assertNull($ambiguous['examen_id']);
        $this->assertContains('examen', $ambiguous['categories']);
    }

    public function test_master_recognizes_alias_without_renaming_or_creating_exam(): void
    {
        $exam = $this->exam('TRABAJOS EN CALIENTE');
        $mine = $this->mine();
        $requirement = $this->requirement($mine, $exam);
        $actor = $this->actor();
        $organization = app(MiningExamCatalogOrganizationService::class);
        $organization->confirm($organization->preview(['accion' => 'asociar', 'principal_id' => $exam->id,
            'aliases' => [['nombre' => 'TABAJO EN CALIENTE', 'mina_id' => $mine->id]],
            'motivo' => 'Nombre corregido por administrador'], $actor), $actor);
        $file = $this->master($mine->nombre, 'TABAJO EN CALIENTE');
        try {
            $importer = app(PersonalMinaExcelImportService::class);
            $preview = $importer->preview($file);
            $this->assertSame($exam->id, $preview['catalogo_examenes'][0]['examenes'][0]['examen_id_existente']);
            $counts = $importer->confirm($preview, $actor);
            $this->assertSame(0, $counts['examenes_creados']);
            $this->assertSame('TRABAJOS EN CALIENTE', $exam->fresh()->nombre);
            $this->assertTrue($requirement->fresh()->activo);
            $this->assertTrue($exam->fresh()->permite_convalidacion);
            $this->assertTrue($requirement->fresh()->permite_convalidacion_mina);
        } finally {
            unlink($file->getPathname());
        }
    }

    public function test_master_blocks_unconfirmed_similar_course_and_changed_group_after_preview(): void
    {
        $principal = $this->exam('ESPACIO CONFINADO');
        $variant = $this->exam('ESPACIOS CONFINADOS');
        $mine = $this->mine();
        $this->requirement($mine, $principal);
        $file = $this->master($mine->nombre, 'ESPACIO CONFINADO');
        $importer = app(PersonalMinaExcelImportService::class);
        try {
            $preview = $importer->preview($file);
            $this->associate($variant, $principal);
            $this->expectException(ValidationException::class);
            $importer->confirm($preview, $this->actor());
        } finally {
            unlink($file->getPathname());
        }
    }

    public function test_master_similar_unknown_name_requires_catalog_review_before_any_write(): void
    {
        $this->exam('ESPACIO CONFINADO');
        $mine = $this->mine();
        $file = $this->master($mine->nombre, 'ESPACIOS CONFINADOS');
        try {
            $importer = app(PersonalMinaExcelImportService::class);
            $preview = $importer->preview($file);
            $this->assertNotEmpty($preview['catalog_identity_errors'] ?? []);
            $this->expectException(ValidationException::class);
            $importer->confirm($preview, $this->actor());
        } finally {
            unlink($file->getPathname());
        }
    }

    public function test_group_suggests_origin_but_only_explicit_convalidation_changes_destination_with_null_grade(): void
    {
        [$service, $actor, $origin, $target] = $this->convalidationContext();
        $this->assertSame('PENDIENTE', $target->fresh()->estado);
        $options = $service->convalidationOptions($target);
        $this->assertSame($origin->id, $options['options'][0]['id'] ?? null);
        $service->convalidateExam($target, $origin->id, [], $actor);
        $this->assertSame('CONVALIDADO', $target->fresh()->estado);
        $this->assertNull($target->fresh()->nota_obtenida);
        $this->assertSame($origin->examen_id, $target->fresh()->contexto_convalidacion_json['examen_origen_id']);
    }

    public function test_master_uses_destination_variant_for_results_and_reimport_omits_duplicate(): void
    {
        $principal = $this->exam('ESPACIO CONFINADO');
        $variant = $this->exam('ESPACIOS CONFINADOS');
        $this->associate($variant, $principal);
        $mine = $this->mine();
        $this->requirement($mine, $variant);
        $file = $this->master($mine->nombre, $principal->nombre, $this->worker());
        $importer = app(PersonalMinaExcelImportService::class);
        $actor = $this->actor();
        try {
            $preview = $importer->preview($file);
            $this->assertSame($variant->id, $preview['rows'][0]['examenes'][0]['examen_id_existente']);
            $this->assertSame($variant->id, $preview['catalogo_examenes'][0]['examenes'][0]['examen_id_existente']);
            $counts = $importer->confirm($preview, $actor);
            $this->assertSame(1, $counts['intentos_importados']);
            $counts = $importer->confirm($importer->preview($file), $actor);
            $this->assertSame(0, $counts['intentos_importados']);
            $this->assertSame(1, $counts['intentos_omitidos_duplicados']);
        } finally {
            unlink($file->getPathname());
        }
    }

    public function test_master_blocks_two_destination_requirements_from_same_group(): void
    {
        $principal = $this->exam('ESPACIO CONFINADO');
        $variant = $this->exam('ESPACIOS CONFINADOS');
        $this->associate($variant, $principal);
        $mine = $this->mine();
        $this->requirement($mine, $principal);
        $this->requirement($mine, $variant);
        $file = $this->master($mine->nombre, $principal->nombre);
        try {
            $importer = app(PersonalMinaExcelImportService::class);
            $preview = $importer->preview($file);
            $this->assertNotEmpty($preview['catalog_identity_errors']);
            $this->expectException(ValidationException::class);
            $importer->confirm($preview, $this->actor());
        } finally {
            unlink($file->getPathname());
        }
    }

    public function test_group_rejects_expired_foreign_worker_and_low_grade_sources(): void
    {
        [$service, $actor, $origin, $target] = $this->convalidationContext();
        foreach (['expired', 'foreign', 'grade'] as $case) {
            $origin->forceFill(['fecha_vencimiento' => $case === 'expired' ? now()->subDay()->toDateString() : null,
                'nota_obtenida' => $case === 'grade' ? 13 : null])->save();
            $origin->asignacion()->update(['personal_id' => $case === 'foreign' ? $this->worker()->id : $target->asignacion->personal_id]);
            $target->forceFill(['requiere_nota_snapshot' => $case === 'grade', 'nota_minima_snapshot' => 14])->save();
            $this->assertFalse($service->convalidationOptions($target)['available']);
            try {
                $service->convalidateExam($target, $origin->id, [], $actor);
                $this->fail('Invalid source must not be convalidated: '.$case);
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
            $this->assertSame('PENDIENTE', $target->fresh()->estado);
        }
    }

    public function test_group_does_not_bypass_grade_requirement_or_disabled_convalidation(): void
    {
        [$service, $actor, $origin, $target] = $this->convalidationContext();
        $target->forceFill(['requiere_nota_snapshot' => true, 'nota_minima_snapshot' => 14])->save();
        $this->assertFalse($service->convalidationOptions($target)['available']);
        try {
            $service->convalidateExam($target, $origin->id, [], $actor);
            $this->fail('Null grade must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('nota', $exception->errors());
        }
        $target->forceFill(['requiere_nota_snapshot' => false])->save();
        $target->requisitoMina()->update(['permite_convalidacion_mina' => false]);
        $this->assertFalse($service->convalidationOptions($target)['available']);
    }

    private function worker(): Personal
    {
        $id = (string) Str::uuid();
        $dni = (string) random_int(72000000, 72999999);
        DB::table('personal')->insert(['id' => $id, 'dni' => $dni, 'tipo_documento' => 'DNI',
            'numero_documento' => $dni, 'nombre_completo' => 'PERSONAL DE PRUEBA', 'puesto' => 'Operario',
            'ocupacion' => 'Tecnico', 'contrato' => 'FIJO', 'es_supervisor' => false,
            'qr_code' => 'QR-'.Str::random(10), 'fecha_ingreso' => '2026-01-01', 'estado' => 'EN_PROCESO']);

        return Personal::query()->findOrFail($id);
    }

    private function bulkRow(Personal $worker, string $exam, string $mine): array
    {
        return ['sheet' => 'resultados', 'line' => 2, 'type' => 'Resultado', 'dni' => $worker->dni,
            'name' => $worker->nombre_completo, 'exam' => $exam, 'mine' => $mine,
            'date' => now()->toDateString(), 'result' => 'APROBADO', 'score' => null,
            'expiry' => null, 'observation' => '', 'issues' => []];
    }

    private function master(string $mine, string $exam, ?Personal $worker = null): UploadedFile
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle($mine);
        $sheet->setCellValue('A2', 'DNI');
        $sheet->setCellValue('B2', 'Nombre completo');
        $sheet->setCellValue('C1', $exam);
        $sheet->setCellValue('D1', $exam);
        $sheet->setCellValue('C2', 'Fecha de realizacion');
        $sheet->setCellValue('D2', 'Resultado');
        if ($worker) {
            $sheet->setCellValue('A3', $worker->dni);
            $sheet->setCellValue('B3', $worker->nombre_completo);
            $sheet->setCellValue('C3', now()->format('d/m/Y'));
            $sheet->setCellValue('D3', 'APROBADO');
        }
        $path = storage_path('app/testing/identity-'.Str::uuid().'.xlsx');
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();

        return new UploadedFile($path, 'master.xlsx', null, null, true);
    }

    private function convalidationContext(): array
    {
        $principal = $this->exam('CURSO PRINCIPAL');
        $variant = $this->exam('CURSO VARIANTE');
        $this->associate($variant, $principal);
        $service = app(PersonalMinaHabilitacionService::class);
        $actor = $this->actor();
        $worker = $this->worker();
        $results = [];
        foreach ([$principal, $variant] as $exam) {
            $mine = $this->mine();
            $this->requirement($mine, $exam);
            $assignment = $service->assignMine(['personal_id' => $worker->id, 'mina_id' => $mine->id,
                'estado_habilitacion' => 'EN_PROCESO'], $actor);
            $results[] = $assignment->examenes->first();
        }
        $service->registerAttempt($results[0], ['resultado' => 'APROBADO',
            'fecha_realizacion' => now()->toDateString()], null, $actor);

        return [$service, $actor, $results[0]->fresh(), $results[1]->fresh()];
    }
}
