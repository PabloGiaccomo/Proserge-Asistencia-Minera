<?php

namespace Tests\Feature;

use App\Models\EppEntrega;
use App\Models\Personal;
use App\Models\Rol;
use App\Models\Usuario;
use App\Modules\Epps\Services\EppDeliverySignatureService;
use App\Modules\Epps\Services\EppKardexExcelService;
use App\Modules\Epps\Services\EppService;
use App\Modules\Epps\Support\EppSignaturePayload;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class EppKardexPresentationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_kardex_uses_only_movement_letters_and_worker_items_without_added_footer(): void
    {
        $this->assertSame('proserge_app_test', DB::connection()->getDatabaseName());
        $role = Rol::query()->firstOrCreate(['nombre' => 'ADMIN'], ['id' => (string) Str::uuid(), 'estado' => 'ACTIVO']);
        $actor = Usuario::query()->create(['id' => (string) Str::uuid(), 'email' => Str::uuid().'@test.local', 'password' => 'secret', 'rol_id' => $role->id, 'estado' => 'ACTIVO']);
        $worker = Personal::query()->create(['id' => (string) Str::uuid(), 'dni' => (string) random_int(10000000, 99999999), 'nombre_completo' => 'PRUEBA PRESENTACION KARDEX', 'puesto' => 'OPERARIO', 'qr_code' => (string) Str::uuid(), 'estado' => 'ACTIVO']);
        $product = app(EppService::class)->storeCatalog(['nombre' => 'CASCO', 'vida_util_dias' => 365]);
        EppEntrega::query()->create(['id' => (string) Str::uuid(), 'personal_id' => $worker->id, 'epp_id' => $product->id, 'cantidad' => 3, 'fecha_entrega' => '2026-10-09', 'devuelto_at' => '2026-10-12', 'estado' => 'DEVUELTO']);
        $book = app(EppKardexExcelService::class)->workbook($worker, $actor, '2026-10-09', '2026-10-12');
        $sheet = $book->getSheet(0);
        foreach (range(14, 16) as $row) {
            $this->assertSame('N', $sheet->getCell('C'.$row)->getValue());
            $this->assertSame('09/10/2026', $sheet->getCell('B'.$row)->getFormattedValue());
            $this->assertSame('FF000000', $sheet->getStyle('B'.$row)->getFont()->getColor()->getARGB());
        }
        foreach (range(17, 19) as $row) {
            $this->assertSame('D', $sheet->getCell('C'.$row)->getValue());
            $this->assertSame('12/10/2026', $sheet->getCell('B'.$row)->getFormattedValue());
        }
        $this->assertStringContainsString('CASCO', strtoupper($sheet->getCell('C12')->getValue()));
        $this->assertNull($sheet->getCell('D12')->getValue());
        $this->assertNull($book->getSheet(1)->getCell('C5')->getValue());
        $this->assertSame(40, $sheet->getHighestRow());
        $this->assertSame('A1:AE40', $sheet->getPageSetup()->getPrintArea());
        // The return legend must not stretch into the wide signature column.
        $this->assertContains('AB8:AD9', $sheet->getMergeCells());
        $this->assertContains('W8:Y9', $sheet->getMergeCells());
        $this->assertSame('D', $sheet->getCell('AA8')->getValue());
        $this->assertSame('Devolución', $sheet->getCell('AB8')->getValue());
        $this->assertNull($sheet->getCell('Z8')->getValue());
        $this->assertNull($sheet->getCell('AE8')->getValue());
        $this->assertSame('none', $sheet->getStyle('Z8')->getBorders()->getTop()->getBorderStyle());
        $this->assertSame('none', $sheet->getStyle('AE8')->getBorders()->getTop()->getBorderStyle());
        $this->assertSame($sheet->getStyle('V8')->getFill()->getStartColor()->getARGB(), $sheet->getStyle('AA8')->getFill()->getStartColor()->getARGB());
        $this->assertSame($sheet->getStyle('W8')->getFill()->getStartColor()->getARGB(), $sheet->getStyle('AB8')->getFill()->getStartColor()->getARGB());
        $this->assertFalse($sheet->getStyle('AB8')->getFont()->getBold());
    }

    public function test_exported_signature_has_white_background_and_appears_only_once_in_footer(): void
    {
        $this->assertSame('proserge_app_test', DB::connection()->getDatabaseName());
        Storage::fake('local');
        $role = Rol::query()->firstOrCreate(['nombre' => 'ADMIN'], ['id' => (string) Str::uuid(), 'estado' => 'ACTIVO']);
        $actor = Usuario::query()->create(['id' => (string) Str::uuid(), 'email' => Str::uuid().'@test.local', 'password' => 'secret', 'rol_id' => $role->id, 'estado' => 'ACTIVO']);
        $worker = Personal::query()->create(['id' => (string) Str::uuid(), 'dni' => (string) random_int(10000000, 99999999), 'nombre_completo' => 'PRUEBA FIRMA TRANSPARENTE', 'puesto' => 'OPERARIO', 'qr_code' => (string) Str::uuid(), 'estado' => 'ACTIVO']);
        $product = app(EppService::class)->storeCatalog(['nombre' => 'CASCO', 'vida_util_dias' => 365]);
        $service = app(EppDeliverySignatureService::class);
        $operation = $service->prepare(['personal_id' => $worker->id, 'epp_id' => $product->id, 'cantidad' => 1, 'fecha_entrega' => '2026-10-09', 'submission_uuid' => (string) Str::uuid()], $actor);
        $operation->refresh();
        $image = imagecreatetruecolor(600, 300);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        imageline($image, 30, 150, 500, 80, imagecolorallocatealpha($image, 10, 20, 30, 0));
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        imagedestroy($image);
        $input = ['submission_uuid' => $operation->submission_uuid, 'personal_id' => $worker->id, 'review_hash' => $operation->resumen_sha256, 'review_version' => $operation->version, 'ink' => ['point_count' => 40, 'bounds' => ['min_x' => 30, 'min_y' => 80, 'max_x' => 500, 'max_y' => 150]]];
        $input['firma_data_url'] = 'data:image/png;base64,'.base64_encode($bytes);
        $service->confirm($operation, EppSignaturePayload::fromArray($input), $actor);
        $acceptances = [$operation->fresh()->aceptacion];
        $acceptances[0]->update(['firmado_at_utc' => '2026-10-09 18:00:00']);
        foreach (['2026-10-10', '2026-10-11'] as $index => $date) {
            $next = $service->prepare(['personal_id' => $worker->id, 'epp_id' => $product->id, 'cantidad' => 1, 'fecha_entrega' => $date, 'submission_uuid' => (string) Str::uuid()], $actor);
            $next->refresh();
            $nextInput = array_replace($input, ['submission_uuid' => $next->submission_uuid, 'review_hash' => $next->resumen_sha256, 'review_version' => $next->version]);
            $service->confirm($next, EppSignaturePayload::fromArray($nextInput), $actor);
            $acceptance = $next->fresh()->aceptacion;
            $acceptance->update(['firmado_at_utc' => '2026-10-'.(10 + $index).' 18:00:00']);
            $acceptances[] = $acceptance;
        }
        $book = app(EppKardexExcelService::class)->workbook($worker, $actor, '2026-10-09', '2026-10-10');
        $drawings = collect($book->getSheet(0)->getDrawingCollection())->filter(fn ($drawing) => str_starts_with($drawing->getDescription(), 'Firma del trabajador'));
        $this->assertCount(1, $drawings);
        $this->assertSame(['AE34'], $drawings->map->getCoordinates()->values()->all());
        $this->assertStringContainsString($acceptances[1]->id, $drawings->first()->getName());
        $this->assertStringNotContainsString($acceptances[2]->id, $drawings->first()->getName());
        $this->assertSame('10/10/2026', $book->getSheet(0)->getCell('B15')->getFormattedValue());
        $this->assertNull($book->getSheet(0)->getCell('B16')->getValue());
        $this->assertSame(22.0, $book->getSheet(0)->getRowDimension(14)->getRowHeight());
        foreach ($drawings as $drawing) {
            $pixel = imagecolorsforindex($drawing->getImageResource(), imagecolorat($drawing->getImageResource(), 0, 0));
            $this->assertSame([255, 255, 255, 0], array_values($pixel));
        }
        foreach ($acceptances as $acceptance) {
            $this->assertSame($acceptance->firma_sha256, hash('sha256', Storage::disk('local')->get($acceptance->firma_path)));
        }
        $path = tempnam(sys_get_temp_dir(), 'kardex-white-');
        try {
            (new Xlsx($book))->save($path);
            $loaded = IOFactory::load($path);
            $savedSignatures = collect($loaded->getSheet(0)->getDrawingCollection())->filter(fn ($drawing) => str_starts_with($drawing->getDescription(), 'Firma del trabajador'));
            $this->assertSame(['AE34'], $savedSignatures->map->getCoordinates()->values()->all());
            foreach ($loaded->getSheet(0)->getDrawingCollection() as $drawing) {
                if (! str_starts_with($drawing->getDescription(), 'Firma del trabajador')) {
                    continue;
                }
                $exported = imagecreatefromstring(file_get_contents($drawing->getPath()));
                $pixel = imagecolorsforindex($exported, imagecolorat($exported, 0, 0));
                $this->assertSame([255, 255, 255, 0], array_values($pixel));
                imagedestroy($exported);
            }
        } finally {
            unlink($path);
        }
    }
}
