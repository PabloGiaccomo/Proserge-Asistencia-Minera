<?php

namespace App\Modules\Evaluaciones\Controllers;

use App\Http\Controllers\WebPageController;
use App\Models\EvaluacionesImportLote;
use App\Models\EvaluacionSupervisor;
use App\Modules\Evaluaciones\Services\EvaluacionesHistoricalImportService;
use App\Support\Rbac\PermissionMatrix;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class EvaluacionesHistoricalImportPageController extends WebPageController
{
    public function __construct(private readonly EvaluacionesHistoricalImportService $service) {}

    public function index(Request $request)
    {
        $this->authorizeImport();
        abort_unless($this->service->isOpen(), 409, 'La carga historica esta cerrada.');
        $filters = $request->validate([
            'lote' => ['nullable', 'uuid'],
            'estado' => ['nullable', 'in:proposed,blocked,unchanged,warnings,all'],
            'tipo' => ['nullable', 'in:supervisor'],
            'q' => ['nullable', 'string', 'max:191'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $batch = null;
        $manifest = ['proposed' => [], 'blocked' => [], 'unchanged' => [], 'warnings' => []];
        if (! empty($filters['lote'])) {
            [$batch, $manifest] = $this->loadManifest($filters['lote']);
            if (($manifest['scope'] ?? null) !== 'SUPERVISORS') {
                return redirect()->route('evaluaciones.historico.importar')->withErrors(['importacion' => 'La carga ahora es solo de supervisores. Revisa nuevamente el archivo Control.']);
            }
        }
        $status = $filters['estado'] ?? ($manifest['blocked'] ? 'blocked' : 'proposed');
        $rows = collect();
        foreach (['proposed', 'blocked', 'unchanged', 'warnings'] as $group) {
            if (($status === 'all' && $group !== 'warnings') || $status === $group) {
                foreach ($manifest[$group] as $entry) {
                    $rows->push([...$entry, 'review_status' => $group]);
                }
            }
        }
        $search = mb_strtolower(trim((string) ($filters['q'] ?? '')));
        $rows = $rows->filter(fn ($row) => (empty($filters['tipo']) || ($row['type'] ?? '') === $filters['tipo'])
            && ($search === '' || str_contains(mb_strtolower(implode(' ', [
                $row['dni'] ?? '', $row['name'] ?? '', $row['personal_name'] ?? '', $row['source_sheet'] ?? '',
                $row['source_row'] ?? '', $row['reason'] ?? '',
            ])), $search)))->values();
        $page = (int) ($filters['page'] ?? 1);
        $paginator = new LengthAwarePaginator($rows->slice(($page - 1) * 50, 50)->values(), $rows->count(), 50, $page, [
            'path' => route('evaluaciones.historico.importar'), 'query' => $request->except('page'),
        ]);

        return view('evaluaciones.historical-import', compact('batch', 'manifest', 'filters', 'status', 'paginator'));
    }

    public function preview(Request $request)
    {
        $actor = $this->authorizeImport();
        abort_unless($this->service->isOpen(), 409, 'La carga historica esta cerrada.');
        $request->validate([
            'control' => ['required', 'file', 'mimes:xlsx', 'max:20480'],
        ]);
        try {
            $result = $this->service->previewSupervisors($actor, $request->file('control')->getRealPath());
        } catch (\Throwable $exception) {
            Log::warning('evaluaciones.historical_preview_failed', ['exception_class' => $exception::class]);

            return back()->withErrors(['importacion' => 'No se pudo revisar los libros. Verifica su formato e intenta nuevamente.']);
        }
        if (! $result['ok']) {
            return back()->withErrors(['importacion' => $result['message']]);
        }

        return redirect()->route('evaluaciones.historico.importar', ['lote' => $result['lote_id']]);
    }

    public function confirm(Request $request)
    {
        $actor = $this->authorizeImport();
        $payload = $request->validate([
            'lote_id' => ['required', 'uuid'], 'hash' => ['required', 'string', 'size:64'],
            'confirmacion' => ['accepted'],
        ]);
        try {
            $result = $this->service->confirmSupervisors($actor, $payload['lote_id'], $payload['hash']);
        } catch (\Throwable $exception) {
            Log::warning('evaluaciones.historical_confirmation_failed', ['lote_id' => $payload['lote_id'], 'exception_class' => $exception::class]);

            return back()->withErrors(['importacion' => 'La carga no pudo completarse. No se guardaron cambios. Revisa el reporte antes de reintentar.']);
        }
        if (! $result['ok']) {
            return back()->withErrors(['importacion' => $result['message']]);
        }

        return redirect()->route('evaluaciones.index', ['tipo' => 'supervisores'])->with('success',
            'Carga historica completada: '.$result['counts']['proposed'].' registros guardados. La carga ha quedado cerrada.');
    }

    public function report(string $lote)
    {
        $this->authorizeImport();
        [$batch, $manifest] = $this->loadManifest($lote);
        $saved = EvaluacionSupervisor::query()->where('import_lote_id', $batch->id)->get()->keyBy('source_key');

        return response()->streamDownload(function () use ($manifest, $saved): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, ['Estado revision', 'Tipo', 'Libro', 'Hoja', 'Fila', 'DNI', 'Fecha', 'Nota original', 'Motivo revision', 'Referencia original', 'Coincidencias por nombre sin asignar', 'Estado actual', 'Criterios pendientes', 'Seguimiento', 'Nota actual', 'Version calculo'], ',', '"', '');
            foreach (['proposed', 'blocked', 'unchanged', 'warnings'] as $group) {
                foreach ($manifest[$group] as $row) {
                    $item = $saved->get($row['source_key'] ?? '');
                    $cells = [
                        $group, $row['type'] ?? '', $row['source_file'] ?? '', $row['source_sheet'] ?? '',
                        $row['source_row'] ?? '', $row['dni'] ?? '', $row['date'] ?? '',
                        $row['payload']['resultado_final'] ?? $row['payload']['total'] ?? $row['total'] ?? '', $row['reason'] ?? '',
                        $row['dni_ref'] ?? '',
                        implode(' | ', array_map(fn ($person) => $person['nombre_completo'].' / '.$person['dni'], $row['identity_suggestions'] ?? [])),
                        $item?->estado ?? '', implode(', ', $item?->missingCriteria() ?? []),
                        implode(' | ', array_map(fn ($event) => $event['fecha'].' '.$event['accion'].' / '.($event['motivo'] ?? '').' / '.($event['actor'] ?? $event['usuario_id']), $item?->regularizationEvents() ?? [])),
                        $item?->resultado_final ?? '', $item?->calculo_version ?? '',
                    ];
                    $cells = array_map(fn ($value) => preg_match('/^[=+@\-\t\r]/', (string) $value) ? "'".$value : $value, $cells);
                    fputcsv($output, $cells, ',', '"', '');
                }
            }
            fclose($output);
        }, 'revision-historica-'.$batch->id.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function authorizeImport()
    {
        $user = $this->requireAuthenticatedUser();
        abort_unless(PermissionMatrix::userCanDirect($user, 'evaluaciones', 'administrar'), 403);

        return $user;
    }

    private function loadManifest(string $id): array
    {
        $batch = EvaluacionesImportLote::query()->findOrFail($id);
        $path = "evaluaciones-historicas/{$batch->id}/manifest.json";
        abort_unless($batch->manifest_path === $path && Storage::disk('local')->exists($path), 404);

        return [$batch, json_decode(Storage::disk('local')->get($path), true, 512, JSON_THROW_ON_ERROR)];
    }
}
