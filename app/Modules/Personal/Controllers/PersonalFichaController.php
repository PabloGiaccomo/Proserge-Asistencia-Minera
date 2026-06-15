<?php

namespace App\Modules\Personal\Controllers;

use App\Http\Controllers\WebPageController;
use App\Models\Personal;
use App\Models\PersonalFicha;
use App\Models\PersonalFichaArchivo;
use App\Models\PersonalFichaLink;
use App\Modules\Personal\Services\PersonalFichaExportService;
use App\Modules\Personal\Services\PersonalFichaMacroExtractor;
use App\Modules\Personal\Services\PersonalFichaPdfService;
use App\Modules\Personal\Services\PersonalFichaEmailTemplateService;
use App\Modules\Personal\Services\PersonalFichaService;
use App\Modules\Personal\Services\PersonalService;
use App\Modules\Personal\Support\PersonalFichaCatalog;
use App\Support\Rbac\PermissionMatrix;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Carbon;
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
        private readonly PersonalFichaEmailTemplateService $emailTemplateService,
        private readonly PersonalService $personalService,
    ) {
    }

    public function importForm(): View
    {
        return view('personal.fichas.import');
    }

    public function temporales(Request $request): View
    {
        $estado = strtoupper((string) $request->query('estado', ''));
        $allRows = $this->fichaService->temporaryLinkRows(
            $estado !== '' ? $estado : null
        );
        $rows = collect($allRows)->values();
        $stateOrder = [
            PersonalFicha::ESTADO_PENDIENTE,
            PersonalFichaService::TEMPORAL_ESTADO_LINK_ENVIADO_PENDIENTE,
            PersonalFicha::ESTADO_ENVIADA,
            PersonalFicha::ESTADO_OBSERVADO,
            PersonalFichaService::TEMPORAL_ESTADO_VENCIDO,
            PersonalFichaService::TEMPORAL_ESTADO_LINK_ENVIADO_VENCIDO,
        ];

        $availableStates = collect($allRows)
            ->pluck('estado_key')
            ->filter()
            ->unique()
            ->values();

        $estadoOptions = ['' => 'Todos'];
        foreach ($stateOrder as $stateKey) {
            if ($availableStates->contains($stateKey)) {
                $estadoOptions[$stateKey] = $this->fichaService->temporaryDisplayLabel($stateKey);
            }
        }

        if ($estado !== '' && !array_key_exists($estado, $estadoOptions)) {
            $estadoOptions[$estado] = $this->fichaService->temporaryDisplayLabel($estado);
        }

        return view('personal.fichas.temporales', [
            'rows' => $rows,
            'rowsTotal' => $rows->count(),
            'estadoFilter' => $estado,
            'estadoOptions' => $estadoOptions,
            'emailTemplate' => $this->emailTemplateService->get(),
        ]);
    }

    public function updateEmailTemplate(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'subject' => ['required', 'string', 'max:180'],
            'body' => ['required', 'string', 'max:8000'],
        ]);

        if (!str_contains((string) $validated['body'], '{{ link }}')) {
            $message = 'El mensaje debe incluir el marcador {{ link }} para ubicar el enlace en el correo.';

            if ($request->wantsJson()) {
                return response()->json(['error' => $message], 422);
            }

            return redirect()
                ->route('personal.fichas.temporales')
                ->with('error', $message);
        }

        $template = $this->emailTemplateService->save($validated['subject'], $validated['body']);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Plantilla de correo actualizada.',
                'template' => $template,
            ]);
        }

        return redirect()
            ->route('personal.fichas.temporales')
            ->with('success', 'Plantilla de correo actualizada.');
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
            ->with(['personal', 'familiares', 'link', 'archivos', 'documentoEstados'])
            ->findOrFail($id);
        $permissions = session('user.permissions', []);

        return view('personal.fichas.review', [
            'ficha' => $ficha,
            'data' => $ficha->datos_json ?? [],
            'sections' => PersonalFichaCatalog::sections(),
            'estadoLabel' => PersonalFichaCatalog::stateLabel($ficha->estado),
            'huellaDataUrl' => $this->fichaService->imageDataUrl($ficha->huella_path),
            'documentMatrix' => $this->fichaService->documentMatrix($ficha),
            'documentSummary' => $this->fichaService->documentSummary($ficha),
            'documentStateLabels' => PersonalFichaCatalog::documentStateLabels(),
            'vidaLeyPhysicalStateLabels' => PersonalFichaCatalog::vidaLeyPhysicalStateLabels(),
            'canUploadDocuments' => PermissionMatrix::allowsAny($permissions, 'personal', ['actualizar', 'administrar']),
            'canReviewDocuments' => PermissionMatrix::allowsAny($permissions, 'personal', ['aprobar', 'administrar']),
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
            ->with('success', 'Ficha aprobada. El trabajador queda pendiente de contrato firmado.');
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

    public function resendObservationEmail(Request $request, string $id): JsonResponse|RedirectResponse
    {
        $ficha = PersonalFicha::query()->with(['personal', 'link'])->findOrFail($id);

        try {
            $result = $this->fichaService->sendObservedFichaEmail($ficha);
        } catch (ValidationException $exception) {
            $error = collect($exception->errors())->flatten()->first() ?: 'No se pudo reenviar el correo de observacion.';

            if ($request->wantsJson()) {
                return response()->json(['error' => $error], 422);
            }

            return redirect()
                ->route('personal.fichas.review', $ficha->id)
                ->with('error', $error);
        }

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'email' => $result['email'],
                'resent' => $result['resent'],
                'url' => $result['url'] ?? null,
            ]);
        }

        return redirect()
            ->route('personal.fichas.review', $ficha->id)
            ->with('success', 'Correo de observacion reenviado a ' . $result['email'] . '.');
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

    public function extendTemporal(Request $request, string $id): JsonResponse|RedirectResponse
    {
        $this->assertCanDeletePersonal();

        $ficha = PersonalFicha::query()->with(['personal', 'link'])->findOrFail($id);
        $this->fichaService->extendLink($ficha, 24);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'El link temporal fue ampliado por 1 dia mas.',
                ...$this->temporaryRowResponse($ficha->fresh(['personal', 'link', 'archivos'])),
            ]);
        }

        return redirect()
            ->route('personal.fichas.temporales')
            ->with('success', 'El link temporal fue ampliado por 1 dia mas.');
    }

    public function regularizeLink(Request $request, string $id): JsonResponse|RedirectResponse
    {
        $ficha = PersonalFicha::query()->with(['personal', 'link', 'archivos'])->findOrFail($id);
        $result = $this->fichaService->ensureRegularizationLink($ficha, 24);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Se habilito un link temporal para regularizar la ficha.',
                'url' => $result['url'] ?? null,
                ...$this->temporaryRowResponse($ficha->fresh(['personal', 'link', 'archivos'])),
            ]);
        }

        return redirect()
            ->back()
            ->with('success', 'Se habilito un link temporal para regularizar la ficha.')
            ->with('regularization_link', $result['url'] ?? null);
    }

    public function searchActivateLinkWorkers(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('q', ''));

        if (mb_strlen($search) < 2) {
            return response()->json(['items' => []]);
        }

        $items = $this->personalService
            ->searchSelector($search, false, 12)
            ->filter(fn (Personal $personal): bool => strtoupper((string) $personal->estado) !== 'CESADO')
            ->map(function (Personal $personal): array {
                return [
                    'id' => (string) $personal->id,
                    'nombre' => (string) $personal->nombre_completo,
                    'documento' => trim((string) (($personal->tipo_documento ?? 'DNI') . ' ' . ($personal->numero_documento ?? $personal->dni ?? ''))),
                    'puesto' => (string) ($personal->puesto ?? 'Puesto pendiente'),
                    'correo' => (string) ($personal->correo ?? ''),
                    'estado' => (string) ($personal->estado ?? ''),
                ];
            })
            ->values()
            ->all();

        return response()->json(['items' => $items]);
    }

    public function activateLinkForWorker(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'personal_id' => ['required', 'string', 'exists:personal,id'],
        ]);

        $personal = Personal::query()->findOrFail($validated['personal_id']);

        try {
            $result = $this->fichaService->activateTemporaryLinkForPersonal($personal, $this->requireAuthenticatedUser(), 24);
        } catch (ValidationException $exception) {
            $error = collect($exception->errors())->flatten()->first() ?: 'No se pudo activar el link temporal.';

            if ($request->wantsJson()) {
                return response()->json(['error' => $error], 422);
            }

            return redirect()
                ->route('personal.fichas.temporales')
                ->with('error', $error);
        }

        $ficha = $result['ficha'];

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Link temporal activado para ' . ($ficha->personal?->nombre_completo ?: 'trabajador') . '.',
                'url' => $result['url'] ?? null,
                ...$this->temporaryRowResponse($ficha),
            ]);
        }

        return redirect()
            ->route('personal.fichas.temporales')
            ->with('success', 'Link temporal activado.');
    }

    public function sendTemporalEmail(Request $request, string $id): JsonResponse|RedirectResponse
    {
        $ficha = PersonalFicha::query()->with(['personal', 'link'])->findOrFail($id);

        try {
            $result = $this->fichaService->sendLinkByEmail($ficha);
        } catch (ValidationException $exception) {
            $error = collect($exception->errors())->flatten()->first() ?: 'No se pudo enviar el correo.';

            if ($request->wantsJson()) {
                return response()->json(['error' => $error], 422);
            }

            return redirect()
                ->route('personal.fichas.temporales')
                ->with('error', $error);
        }

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'email' => $result['email'],
                'resent' => $result['resent'],
                ...$this->temporaryRowResponse($ficha->fresh(['personal', 'link', 'archivos'])),
            ]);
        }

        return redirect()
            ->route('personal.fichas.temporales')
            ->with('success', 'Correo enviado a ' . $result['email'] . '.');
    }

    public function sendBulkTemporalEmails(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ficha_ids' => ['required', 'array', 'min:1', 'max:10'],
            'ficha_ids.*' => ['string', 'size:36'],
        ]);

        $requestedIds = collect($validated['ficha_ids'])
            ->map(fn ($id): string => (string) $id)
            ->unique()
            ->values();

        $fichas = PersonalFicha::query()
            ->with(['personal', 'link', 'archivos'])
            ->whereIn('id', $requestedIds)
            ->get()
            ->keyBy('id');

        $sent = [];
        $failed = [];

        foreach ($requestedIds as $id) {
            /** @var PersonalFicha|null $ficha */
            $ficha = $fichas->get($id);
            if (!$ficha) {
                $failed[] = [
                    'id' => $id,
                    'name' => 'Registro no encontrado',
                    'message' => 'No se encontro la ficha seleccionada.',
                ];
                continue;
            }

            $row = $this->fichaService->temporaryLinkRow($ficha);
            if (!$row || empty($row['url'])) {
                $failed[] = [
                    'id' => $id,
                    'name' => $ficha->personal?->nombre_completo ?: 'Trabajador pendiente',
                    'message' => 'No tiene un link temporal habilitado.',
                ];
                continue;
            }

            try {
                $result = $this->fichaService->sendLinkByEmail($ficha);
                $sent[] = [
                    'id' => $id,
                    'name' => $ficha->personal?->nombre_completo ?: 'Trabajador pendiente',
                    'email' => $result['email'],
                    'resent' => $result['resent'],
                ];
            } catch (ValidationException $exception) {
                $failed[] = [
                    'id' => $id,
                    'name' => $ficha->personal?->nombre_completo ?: 'Trabajador pendiente',
                    'message' => collect($exception->errors())->flatten()->first() ?: 'No se pudo enviar el correo.',
                ];
            }
        }

        return response()->json([
            'success' => count($sent) > 0,
            'message' => count($sent) . ' correo(s) enviado(s).',
            'sent' => $sent,
            'failed' => $failed,
        ], count($sent) > 0 ? 200 : 422);
    }

    public function extendBulkActiveLinks(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ficha_ids' => ['required', 'array', 'min:1'],
            'ficha_ids.*' => ['string', 'size:36'],
            'expires_at' => ['required', 'date', 'after:now'],
        ]);

        $targetExpiresAt = Carbon::parse($validated['expires_at'])->seconds(0);
        $requestedIds = collect($validated['ficha_ids'])
            ->map(fn ($id): string => (string) $id)
            ->unique()
            ->values();

        $fichas = PersonalFicha::query()
            ->with(['personal', 'link', 'archivos'])
            ->whereIn('id', $requestedIds)
            ->get()
            ->keyBy('id');

        $extended = [];
        $skipped = [];

        foreach ($requestedIds as $id) {
            /** @var PersonalFicha|null $ficha */
            $ficha = $fichas->get($id);
            if (!$ficha) {
                $skipped[] = [
                    'id' => $id,
                    'name' => 'Registro no encontrado',
                    'message' => 'No se encontro la ficha seleccionada.',
                ];
                continue;
            }

            $row = $this->fichaService->temporaryLinkRow($ficha);
            $link = $ficha->link;

            if (!$row || empty($row['url']) || !$link || $link->estado !== PersonalFichaLink::ESTADO_ACTIVO || $link->disabled_at) {
                $skipped[] = [
                    'id' => $id,
                    'name' => $ficha->personal?->nombre_completo ?: 'Trabajador pendiente',
                    'message' => 'No tiene un link temporal activo habilitado.',
                ];
                continue;
            }

            if ($link->expires_at && $link->expires_at->greaterThanOrEqualTo($targetExpiresAt)) {
                $skipped[] = [
                    'id' => $id,
                    'name' => $ficha->personal?->nombre_completo ?: 'Trabajador pendiente',
                    'message' => 'Ya vence en una fecha igual o posterior.',
                ];
                continue;
            }

            $link->forceFill([
                'estado' => PersonalFichaLink::ESTADO_ACTIVO,
                'disabled_at' => null,
                'submitted_at' => null,
                'read_until' => null,
                'expires_at' => $targetExpiresAt,
                'enabled_manually_at' => $link->enabled_manually_at ?: now(),
            ])->save();

            $extended[] = [
                'id' => $id,
                'name' => $ficha->personal?->nombre_completo ?: 'Trabajador pendiente',
                'expires_at' => $targetExpiresAt->format('d/m/Y H:i'),
            ];
        }

        return response()->json([
            'success' => count($extended) > 0,
            'message' => count($extended) . ' link(s) ampliado(s) hasta ' . $targetExpiresAt->format('d/m/Y H:i') . '.',
            'extended' => $extended,
            'skipped' => $skipped,
        ], count($extended) > 0 ? 200 : 422);
    }

    public function destroyTemporal(Request $request, string $id): JsonResponse|RedirectResponse
    {
        $this->assertCanDeletePersonal();

        $ficha = PersonalFicha::query()->with(['personal', 'link', 'familiares', 'archivos'])->findOrFail($id);

        try {
            $this->fichaService->removeFromTemporaryList($ficha);
        } catch (ValidationException $exception) {
            $error = collect($exception->errors())->flatten()->first() ?: 'No se pudo eliminar el trabajador temporal.';

            if ($request->wantsJson()) {
                return response()->json(['error' => $error], 422);
            }

            return redirect()
                ->route('personal.fichas.temporales')
                ->with('error', $error);
        }

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'removed' => true,
                'id' => $id,
                'message' => 'Registro eliminado de Temporales y links.',
            ]);
        }

        return redirect()
            ->route('personal.fichas.temporales')
            ->with('success', 'Registro eliminado de Temporales y links.');
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

    private function temporaryRowResponse(PersonalFicha $ficha): array
    {
        $row = $this->fichaService->temporaryLinkRow($ficha);

        return [
            'row' => $row ? [
                'id' => $ficha->id,
                'estado_label' => $row['estado_label'] ?? null,
                'url' => $row['url'] ?? null,
                'can_regularize' => $row['can_regularize'] ?? false,
            ] : null,
            'row_html' => $row
                ? view('personal.fichas.partials.temporal-row', [
                    'row' => $row,
                    'rowKey' => $ficha->id,
                ])->render()
                : null,
        ];
    }

    private function assertCanDeletePersonal(): void
    {
        abort_unless(PermissionMatrix::userCan($this->requireAuthenticatedUser(), 'personal', 'eliminar'), 403);
    }
}
