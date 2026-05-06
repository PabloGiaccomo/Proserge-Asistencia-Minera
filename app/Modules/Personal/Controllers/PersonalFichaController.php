<?php

namespace App\Modules\Personal\Controllers;

use App\Http\Controllers\WebPageController;
use App\Models\PersonalFicha;
use App\Models\PersonalFichaArchivo;
use App\Modules\Personal\Services\PersonalFichaExportService;
use App\Modules\Personal\Services\PersonalFichaMacroExtractor;
use App\Modules\Personal\Services\PersonalFichaPdfService;
use App\Modules\Personal\Services\PersonalFichaService;
use App\Modules\Personal\Support\PersonalFichaCatalog;
use App\Support\Rbac\PermissionMatrix;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PersonalFichaController extends WebPageController
{
    public function __construct(
        private readonly PersonalFichaMacroExtractor $extractor,
        private readonly PersonalFichaService $fichaService,
        private readonly PersonalFichaPdfService $pdfService,
        private readonly PersonalFichaExportService $exportService,
    ) {
    }

    public function importForm(): View
    {
        return view('personal.fichas.import');
    }

    public function temporales(): View
    {
        return view('personal.fichas.temporales', [
            'rows' => $this->fichaService->temporaryLinkRows(),
        ]);
    }

    public function parseMacro(Request $request): View|RedirectResponse
    {
        $validated = $request->validate([
            'macro' => ['required', 'file', 'max:20480', 'extensions:xlsx,xls,xlsm,csv,txt,docx,pdf'],
        ]);

        $file = $validated['macro'];
        $key = (string) Str::uuid();
        $safeBase = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)) ?: 'macro';
        $extension = strtolower($file->getClientOriginalExtension() ?: 'bin');
        $path = $file->storeAs('personal_fichas/imports/tmp/' . $key, $safeBase . '.' . $extension, 'local');

        $batchExtraction = $this->extractor->extractMany($file);
        $items = $this->annotateImportItems($batchExtraction['items'] ?? []);
        $firstExtraction = $items[0] ?? $this->extractor->extract($file);
        $source = [
            'key' => $key,
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime' => $file->getMimeType(),
            'size' => $file->getSize(),
            'detected' => $firstExtraction['fields'] ?? [],
            'items' => $items,
            'warnings' => array_values(array_unique(array_merge($batchExtraction['warnings'] ?? [], $firstExtraction['warnings'] ?? []))),
        ];

        if (count($items) === 0) {
            $items = [[
                'row_number' => 1,
                'fields' => $firstExtraction['fields'] ?? [],
                'warnings' => $firstExtraction['warnings'] ?? [],
                'availability' => $firstExtraction['availability'] ?? $this->fichaService->documentAvailability($firstExtraction['fields'] ?? []),
            ]];
            $source['items'] = $items;
        }

        $result = $this->fichaService->createManyFromConfirmation(
            $items,
            $this->workerReviewFieldKeys(),
            $source,
            $this->requireAuthenticatedUser(),
        );

        $message = $result['created_count'] . ' link(s) temporal(es) generado(s).';
        if ($result['skipped_count'] > 0) {
            $message .= ' ' . $result['skipped_count'] . ' registro(s) omitido(s).';
        }

        $warningLines = collect($source['warnings'] ?? [])
            ->merge(collect($result['skipped'] ?? [])->map(fn (array $skip): string => 'Fila ' . ($skip['row_number'] ?? '-') . ': ' . ($skip['message'] ?? 'Registro omitido.')))
            ->values()
            ->all();

        return redirect()
            ->route('personal.fichas.temporales')
            ->with('success', $message)
            ->with('warning_lines', $warningLines);
    }

    public function generateLink(Request $request): RedirectResponse|View
    {
        $validated = $request->validate([
            'session_key' => ['required', 'string'],
            'fields' => ['nullable', 'array'],
            'fields.tipo_documento' => ['required_without:items', 'string', 'max:40'],
            'fields.numero_documento' => ['required_without:items', 'string', 'max:40'],
            'items' => ['nullable', 'array'],
            'items.*.fields' => ['nullable', 'array'],
            'verify_fields' => ['nullable', 'array'],
        ]);

        $source = session('personal_ficha_import.' . $validated['session_key']);
        if (!$source) {
            return redirect()
                ->route('personal.fichas.import')
                ->with('error', 'La carga temporal ya no esta disponible. Vuelve a subir el archivo.');
        }

        $user = $this->requireAuthenticatedUser();
        if (count($source['items'] ?? []) > 1) {
            $items = $this->hydrateBatchItemsFromRequest($request, $source['items']);
            $result = $this->fichaService->createManyFromConfirmation(
                $items,
                $validated['verify_fields'] ?? [],
                $source,
                $user,
            );

            session()->forget('personal_ficha_import.' . $validated['session_key']);

            return view('personal.fichas.link', [
                'batchResult' => $result,
                'results' => $result['created'],
                'skipped' => $result['skipped'],
            ]);
        }

        $fields = $this->mergeSubmittedFields($source['detected'] ?? [], $validated['fields'] ?? []);
        $source['detected'] = $fields;

        $result = $this->fichaService->createFromConfirmation(
            $fields,
            $validated['verify_fields'] ?? [],
            $source,
            $user,
        );

        session()->forget('personal_ficha_import.' . $validated['session_key']);

        return view('personal.fichas.link', [
            'result' => $result,
            'ficha' => $result['ficha'],
            'trabajador' => $result['personal'],
            'url' => $result['url'],
        ]);
    }

    public function review(string $id): View
    {
        $ficha = PersonalFicha::query()
            ->with(['personal', 'familiares', 'link', 'archivos'])
            ->findOrFail($id);

        return view('personal.fichas.review', [
            'ficha' => $ficha,
            'data' => $ficha->datos_json ?? [],
            'sections' => PersonalFichaCatalog::sections(),
            'estadoLabel' => PersonalFichaCatalog::stateLabel($ficha->estado),
            'huellaDataUrl' => $this->fichaService->imageDataUrl($ficha->huella_path),
        ]);
    }

    public function approve(Request $request, string $id): RedirectResponse
    {
        $validated = $request->validate([
            'observaciones_revision' => ['nullable', 'string', 'max:2000'],
        ]);

        $ficha = PersonalFicha::query()->with(['personal', 'link'])->findOrFail($id);
        $this->fichaService->approve($ficha, $this->requireAuthenticatedUser(), $validated['observaciones_revision'] ?? null);

        return redirect()
            ->route('personal.show', $ficha->personal_id)
            ->with('success', 'Ficha aprobada. El trabajador ya queda activo en Personal.');
    }

    public function observe(Request $request, string $id): RedirectResponse
    {
        $validated = $request->validate([
            'observaciones_revision' => ['required', 'string', 'max:2000'],
        ]);

        $ficha = PersonalFicha::query()->with(['personal', 'link'])->findOrFail($id);
        $this->fichaService->observe($ficha, $this->requireAuthenticatedUser(), $validated['observaciones_revision']);

        return redirect()
            ->route('personal.fichas.review', $ficha->id)
            ->with('success', 'La ficha quedo observada.');
    }

    public function pdf(string $id): Response
    {
        $ficha = PersonalFicha::query()
            ->with(['personal', 'familiares'])
            ->findOrFail($id);

        return $this->pdfService->download($ficha);
    }

    public function exportExcel(Request $request)
    {
        return $this->exportService->downloadExcel(
            $request->all(),
            'fichas_personal_' . now()->format('Ymd_His') . '.xlsx'
        );
    }

    public function startPdfExport(Request $request): JsonResponse
    {
        $job = $this->exportService->startPdfJob($request->all());

        return response()->json($job);
    }

    public function processPdfExport(string $jobId): JsonResponse
    {
        return response()->json($this->exportService->processPdfJob($jobId));
    }

    public function downloadPdfExport(string $jobId)
    {
        $path = $this->exportService->zipDownloadPath($jobId);

        return response()->download($path, 'fichas_personal_' . $jobId . '.zip');
    }

    public function downloadArchivo(string $id)
    {
        $archivo = PersonalFichaArchivo::query()->findOrFail($id);

        abort_unless(Storage::disk('local')->exists($archivo->path), 404);

        return Storage::disk('local')->download($archivo->path, $archivo->nombre_original ?: basename($archivo->path));
    }

    public function extendTemporal(string $id): RedirectResponse
    {
        $this->assertCanDeletePersonal();

        $ficha = PersonalFicha::query()->with(['personal', 'link'])->findOrFail($id);
        $this->fichaService->extendLink($ficha, 24);

        return redirect()
            ->route('personal.fichas.temporales')
            ->with('success', 'El link temporal fue ampliado por 1 dia mas.');
    }

    public function regularizeLink(string $id): RedirectResponse
    {
        $ficha = PersonalFicha::query()->with(['personal', 'link', 'archivos'])->findOrFail($id);
        $result = $this->fichaService->ensureRegularizationLink($ficha, 24);

        return redirect()
            ->back()
            ->with('success', 'Se habilito un link temporal para regularizar la ficha.')
            ->with('regularization_link', $result['url'] ?? null);
    }

    public function destroyTemporal(string $id): RedirectResponse
    {
        $this->assertCanDeletePersonal();

        $ficha = PersonalFicha::query()->with(['personal', 'link', 'familiares', 'archivos'])->findOrFail($id);

        try {
            $this->fichaService->deleteDraftFicha($ficha);
        } catch (ValidationException $exception) {
            return redirect()
                ->route('personal.fichas.temporales')
                ->with('error', collect($exception->errors())->flatten()->first() ?: 'No se pudo eliminar el trabajador temporal.');
        }

        return redirect()
            ->route('personal.fichas.temporales')
            ->with('success', 'Trabajador temporal eliminado por completo.');
    }

    public function cancelImport(Request $request): RedirectResponse
    {
        $key = (string) $request->input('session_key');
        $source = session('personal_ficha_import.' . $key);
        if ($source && !empty($source['path'])) {
            Storage::disk('local')->delete($source['path']);
        }
        session()->forget('personal_ficha_import.' . $key);

        return redirect()->route('personal.index');
    }

    private function annotateImportItems(array $items): array
    {
        $seen = [];

        return collect($items)
            ->map(function (array $item) use (&$seen): array {
                $fields = $item['fields'] ?? [];
                $availability = $this->fichaService->documentAvailability($fields);
                $docKey = ($availability['type'] ?? '') . ':' . ($availability['number'] ?? '');

                if (($availability['available'] ?? false) && $docKey !== ':' && isset($seen[$docKey])) {
                    $availability = [
                        ...$availability,
                        'available' => false,
                        'message' => 'Documento duplicado dentro del archivo en la fila ' . $seen[$docKey] . '.',
                    ];
                }

                if ($docKey !== ':') {
                    $seen[$docKey] = $item['row_number'] ?? '?';
                }

                return [
                    ...$item,
                    'availability' => $availability,
                ];
            })
            ->values()
            ->all();
    }

    private function hydrateBatchItemsFromRequest(Request $request, array $sessionItems): array
    {
        $requestItems = $request->input('items', []);

        return collect($sessionItems)
            ->map(function (array $item, int $index) use ($requestItems): array {
                $requestFields = $requestItems[$index]['fields'] ?? [];

                return [
                    ...$item,
                    'fields' => $this->mergeSubmittedFields($item['fields'] ?? [], is_array($requestFields) ? $requestFields : []),
                ];
            })
            ->values()
            ->all();
    }

    private function mergeSubmittedFields(array $detected, array $submitted): array
    {
        $merged = $detected;

        foreach ($submitted as $key => $value) {
            if (is_array($value)) {
                continue;
            }

            $text = trim((string) $value);
            if ($text === '' && trim((string) ($merged[$key] ?? '')) !== '') {
                continue;
            }

            $merged[$key] = $value;
        }

        return $merged;
    }

    private function workerReviewFieldKeys(): array
    {
        return collect(PersonalFichaCatalog::fields())
            ->reject(fn (array $field): bool => ($field['type'] ?? '') === 'hidden')
            ->reject(fn (array $field): bool => (bool) ($field['locked_public'] ?? false))
            ->keys()
            ->values()
            ->all();
    }

    private function assertCanDeletePersonal(): void
    {
        abort_unless(PermissionMatrix::userCan($this->requireAuthenticatedUser(), 'personal', 'eliminar'), 403);
    }
}
