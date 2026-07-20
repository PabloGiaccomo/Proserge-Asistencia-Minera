<?php

namespace App\Modules\Personal\Controllers;

use App\Http\Controllers\WebPageController;
use App\Models\Mina;
use App\Models\Oficina;
use App\Models\PersonalFicha;
use App\Models\PersonalPuesto;
use App\Models\Taller;
use App\Modules\Personal\Resources\PersonalIndexResource;
use App\Modules\Personal\Resources\PersonalResource;
use App\Modules\Personal\Services\ExportPersonalService;
use App\Modules\Personal\Services\PersonalFichaService;
use App\Modules\Personal\Services\PersonalAntiguoService;
use App\Modules\Personal\Services\PersonalContratoService;
use App\Modules\Personal\Services\PersonalService;
use App\Modules\Personal\Support\PersonalFichaCatalog;
use App\Modules\Personal\Support\PersonalExportConfig;
use App\Modules\Personal\Support\PersonalNormalizer;
use App\Support\Rbac\PermissionMatrix;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PersonalPageController extends WebPageController
{
    public function __construct(
        private readonly PersonalService $service,
        private readonly ExportPersonalService $exportService,
        private readonly PersonalFichaService $fichaService,
        private readonly PersonalAntiguoService $personalAntiguoService,
        private readonly PersonalContratoService $contratoService,
    ) {
    }

    public function home(): View
    {
        $permissions = session('user.permissions', []);
        $dashboards = collect([
            [
                'module' => 'personal',
                'title' => 'Personal',
                'description' => 'Ficha laboral, estados, situacion y seguimiento de trabajadores.',
                'tone' => '#19D3C5',
            ],
            [
                'module' => 'mi_asistencia',
                'title' => 'Mi Asistencia',
                'description' => 'Resumen personal de asistencia, turnos y marcaciones.',
                'tone' => '#4F8CFF',
            ],
            [
                'module' => 'man_power',
                'title' => 'Man Power',
                'description' => 'Vista operativa de grupos, cobertura y distribucion por parada.',
                'tone' => '#10B981',
            ],
            [
                'module' => 'rq_mina',
                'title' => 'RQ Mina',
                'description' => 'Seguimiento de requerimientos, fechas y necesidades por mina.',
                'tone' => '#F59E0B',
            ],
            [
                'module' => 'rq_proserge',
                'title' => 'RQ Proserge',
                'description' => 'Asignaciones internas y paradas gestionadas desde Proserge.',
                'tone' => '#8B5CF6',
            ],
            [
                'module' => 'bienestar',
                'title' => 'Bienestar',
                'description' => 'Vacaciones, descansos medicos y bloqueos activos del personal.',
                'tone' => '#EC4899',
            ],
            [
                'module' => 'evaluaciones',
                'title' => 'Evaluaciones',
                'description' => 'Panel de seguimiento para evaluaciones de supervisor y desempeno.',
                'tone' => '#06B6D4',
            ],
            [
                'module' => 'asistencias',
                'title' => 'Asistencias',
                'description' => 'Operacion de asistencia por grupo, supervisor, parada y mina.',
                'tone' => '#0F766E',
            ],
            [
                'module' => 'faltas',
                'title' => 'Faltas',
                'description' => 'Control de incidencias y seguimiento de correcciones pendientes.',
                'tone' => '#DC2626',
            ],
            [
                'module' => 'catalogos',
                'title' => 'Catalogos',
                'description' => 'Estado general de minas, talleres, oficinas y configuraciones base.',
                'tone' => '#64748B',
            ],
        ])->filter(fn (array $dashboard): bool => PermissionMatrix::allows($permissions, $dashboard['module'], 'dashboards'))
            ->values()
            ->all();

        return view('personal.home', [
            'dashboards' => $dashboards,
        ]);
    }

    public function index(Request $request)
    {
        if (strtolower((string) $request->query('export')) === 'excel') {
            $this->assertPersonalPermission(['exportar_excel', 'exportar']);

            return $this->exportService->download($request->query(), 'personal_web_' . now()->format('Ymd_His') . '.xlsx');
        }

        $this->runIndexMaintenance();

        $filters = $this->extractIndexFilters($request);
        $perPage = $this->resolvePerPage($request);
        $paginator = $this->service->paginatedForIndex($filters, $perPage);
        $trabajadores = PersonalIndexResource::collection($paginator->items())->resolve();
        $trabajadores = $this->filterByVisibleState($trabajadores, (string) ($filters['visible_estado'] ?? ''));
        $currentPageCount = count($trabajadores);
        $firstItem = $paginator->firstItem();

        $catalogs = $this->getLocationCatalogs();

        return view('personal.index', array_merge($catalogs, compact('trabajadores'), [
            'puestoOptions' => $this->puestoOptions(),
            'contractTypeOptions' => $this->contratoService->contractTypeOptions(),
            'paginationMeta' => [
                'total' => $paginator->total(),
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'perPage' => $paginator->perPage(),
                'from' => $currentPageCount > 0 ? $firstItem : null,
                'to' => $currentPageCount > 0 && $firstItem !== null ? $firstItem + $currentPageCount - 1 : null,
                'serverSide' => true,
            ],
        ]));
    }

    public function apiList(Request $request): JsonResponse
    {
        $filters = $this->extractIndexFilters($request);
        $perPage = $this->resolvePerPage($request);

        $paginator = $this->service->paginatedForIndex($filters, $perPage);
        $workers = PersonalIndexResource::collection($paginator->items())->resolve();

        return response()->json([
            'data' => $workers,
            'total' => $paginator->total(),
            'currentPage' => $paginator->currentPage(),
            'lastPage' => $paginator->lastPage(),
            'perPage' => $paginator->perPage(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
        ]);
    }

    private function extractIndexFilters(Request $request): array
    {
        $filters = $request->query();

        $visibleStateFilter = strtoupper(trim((string) ($filters['estado'] ?? '')));
        if (!in_array($visibleStateFilter, ['ACTIVO', 'INACTIVO', 'CESADO'], true)) {
            $visibleStateFilter = match (strtolower((string) ($filters['estado'] ?? ''))) {
                'activo' => 'ACTIVO',
                'inactivo' => 'INACTIVO',
                'cesado' => 'CESADO',
                default => '',
            };
        }

        if (in_array($visibleStateFilter, ['ACTIVO', 'INACTIVO', 'CESADO'], true)) {
            unset($filters['estado']);
            $filters['visible_estado'] = $visibleStateFilter;
        }

        return $filters;
    }

    private function resolvePerPage(Request $request): int
    {
        $perPage = (int) ($request->query('per_page', 25));

        return in_array($perPage, [10, 25, 50, 100], true) ? $perPage : 25;
    }

    private function filterByVisibleState(array $trabajadores, string $visibleStateFilter): array
    {
        if (!in_array($visibleStateFilter, ['ACTIVO', 'INACTIVO', 'CESADO'], true)) {
            return $trabajadores;
        }

        return array_values(array_filter($trabajadores, function (array $trabajador) use ($visibleStateFilter): bool {
            if (!empty($trabajador['en_lista_negra'])) {
                return true;
            }

            return strtoupper((string) ($trabajador['estado_operativo'] ?? $trabajador['estado'] ?? '')) === $visibleStateFilter;
        }));
    }

    private function runIndexMaintenance(): void
    {
        try {
            if (Cache::add('personal:index:expire_stale_links', true, now()->addMinutes(10))) {
                $this->fichaService->expireStaleLinks();
            }

            if (Cache::add('personal:index:sync_expired_contracts', true, now()->addMinutes(30))) {
                $this->service->syncExpiredContractClosures($this->requireAuthenticatedUser());
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    public function exportForm(Request $request): View
    {
        $this->assertPersonalPermission(['exportar_excel', 'exportar']);

        $availableColumns = $this->exportService->availableColumns();
        $config = PersonalExportConfig::fromInput($request->query(), array_keys($availableColumns), true);
        $preview = $this->exportService->preview($config);
        $recommendedColumns = PersonalExportConfig::recommendedColumns(array_keys($availableColumns));

        return view('personal.export', [
            'config' => $config,
            'availableColumns' => $availableColumns,
            'recommendedColumns' => $recommendedColumns,
            'preview' => $preview,
            'selectedWorkers' => $this->exportService->workersByIds($config->personalIds),
            'previewTable' => $this->exportService->previewTable($config),
        ]);
    }

    public function exportWorkers(Request $request): JsonResponse
    {
        $this->assertPersonalPermission(['exportar_excel', 'exportar']);

        return response()->json([
            'workers' => $this->exportService->searchWorkers((string) $request->query('q', '')),
        ]);
    }

    public function exportPreview(Request $request): JsonResponse
    {
        $this->assertPersonalPermission(['exportar_excel', 'exportar']);

        $availableColumns = $this->exportService->availableColumns();
        $config = PersonalExportConfig::fromInput($request->all(), array_keys($availableColumns), false);

        return response()->json(
            $this->exportService->previewTable($config)
        );
    }

    public function exportDownload(Request $request)
    {
        $this->assertPersonalPermission(['exportar_excel', 'exportar']);

        $availableColumns = $this->exportService->availableColumns();
        $config = PersonalExportConfig::fromInput($request->all(), array_keys($availableColumns), false);

        if (count($config->columns) === 0) {
            return redirect()
                ->route('personal.export.form', $request->except('_token'))
                ->with('error', 'Debes seleccionar al menos una columna para exportar.');
        }

        if (count($config->personalIds) === 0) {
            return redirect()
                ->route('personal.export.form', $request->except('_token'))
                ->with('error', 'Debes seleccionar al menos un trabajador para exportar.');
        }

        $preview = $this->exportService->preview($config);
        if (($preview['records'] ?? 0) === 0) {
            return redirect()
                ->route('personal.export.form', $request->except('_token'))
                ->with('error', 'No hay resultados para exportar con la configuración elegida.');
        }

        return $this->exportService->downloadWithConfig(
            $config,
            'personal_web_' . now()->format('Ymd_His') . '.xlsx'
        );
    }

    public function show(string $id): View
    {
        $personal = $this->service->find($id);
        abort_if(!$personal, 404);

        $trabajador = PersonalResource::make($personal)->resolve();
        $ficha = $personal->fichaColaborador;
        $ficha?->loadMissing(['familiares', 'archivos']);

        return view('personal.show', [
            'id' => $id,
            'personal' => $personal,
            'trabajador' => $trabajador,
            'ficha' => $ficha,
        ]);
    }

    public function regularizeAntiguo(string $id): View
    {
        $personal = $this->service->find($id);
        abort_if(!$personal, 404);

        $personal->loadMissing(['contratoDatos', 'contratoLaboralActual']);

        return view('personal.antiguo.regularizar', [
            'personal' => $personal,
            'trabajador' => PersonalResource::make($personal)->resolve(),
            'contratoDatos' => $personal->contratoDatos,
            'contratoActual' => $personal->contratoLaboralActual,
            'hasSignedContract' => $this->service->hasSignedContract($personal),
        ]);
    }

    public function updateRegularizacionAntiguo(Request $request, string $id): RedirectResponse
    {
        $personal = $this->service->find($id);
        abort_if(!$personal, 404);

        $validated = $request->validate($this->legacyRegularizationRules(), [
            'fecha_fin.after_or_equal' => 'La fecha de fin no puede ser anterior al inicio.',
            'contrato_firmado.mimes' => 'El contrato firmado debe ser un PDF.',
        ]);

        try {
            $result = $this->personalAntiguoService->regularizeExisting(
                $personal,
                $validated,
                $request->file('contrato_firmado'),
                $this->requireAuthenticatedUser(),
            );
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            return redirect()
                ->back()
                ->withInput()
                ->with('error', 'No se pudo regularizar el trabajador existente. Revisa los datos o intenta nuevamente.');
        }

        $redirect = redirect()
            ->route('personal.edit', $result['personal']->id)
            ->with('success', ($result['contract_created'] ?? false)
                ? 'Personal antiguo regularizado y contrato laboral sincronizado.'
                : 'Personal antiguo regularizado correctamente.');

        if (!empty($result['warnings'])) {
            $redirect->with('warning', implode(' ', $result['warnings']));
        }

        return $redirect;
    }

    public function edit(string $id): View
    {
        $this->assertPersonalPermission(['editar', 'actualizar', 'editar_ficha']);

        $personal = $this->service->find($id);
        abort_if(!$personal, 404);

        $trabajador = PersonalResource::make($personal)->resolve();
        $ficha = $personal->fichaColaborador;
        $ficha?->loadMissing(['familiares', 'archivos']);

        $catalogs = $this->getLocationCatalogs();
        $knownLocations = collect([
            ...$catalogs['catalogMinas'],
            ...$catalogs['catalogOficinas'],
            ...$catalogs['catalogTalleres'],
        ]);

        $missingLocations = collect($trabajador['minas'] ?? [])
            ->filter(fn ($name) => is_string($name) && trim($name) !== '')
            ->reject(fn (string $name) => $knownLocations->contains($name));

        foreach ($missingLocations as $name) {
            $lower = mb_strtolower($name);

            if (str_contains($lower, 'oficina')) {
                $catalogs['catalogOficinas'][] = $name;
                continue;
            }

            if (str_contains($lower, 'taller')) {
                $catalogs['catalogTalleres'][] = $name;
                continue;
            }

            $catalogs['catalogMinas'][] = $name;
        }

        $catalogs['catalogMinas'] = array_values(array_unique($catalogs['catalogMinas']));
        $catalogs['catalogOficinas'] = array_values(array_unique($catalogs['catalogOficinas']));
        $catalogs['catalogTalleres'] = array_values(array_unique($catalogs['catalogTalleres']));

        $regularizationSummary = $this->fichaService->regularizationSummary($ficha);
        $missingRequiredDocuments = $this->fichaService->missingRequiredDocumentKeys($ficha);

        return view('personal.edit', array_merge($catalogs, [
            'trabajador' => $trabajador,
            'ficha' => $ficha,
            'sections' => PersonalFichaCatalog::sections(),
            'initialFields' => $this->initialFichaFieldsForEdit($trabajador, $ficha),
            'huellaDataUrl' => $this->fichaService->imageDataUrl($ficha?->huella_path),
            'missingRequiredDocuments' => $missingRequiredDocuments,
            'missingRequiredFichaFields' => $regularizationSummary['missing_fields'],
            'regularizationSummary' => $regularizationSummary,
            'showIngresoRegularizationNotice' => $this->shouldShowIngresoRegularizationNotice($trabajador, $ficha, $regularizationSummary, $missingRequiredDocuments),
            'puestoOptions' => $this->puestoOptions(),
        ]));
    }

    private function shouldShowIngresoRegularizationNotice(array $trabajador, ?PersonalFicha $ficha, array $regularizationSummary, array $missingRequiredDocuments): bool
    {
        if (!$ficha) {
            return false;
        }

        $estadoInterno = strtoupper((string) ($trabajador['estado_interno'] ?? $trabajador['estado'] ?? ''));
        if ($estadoInterno === 'NO_FIRMO_CONTRATO') {
            return false;
        }

        return (bool) ($regularizationSummary['can_regularize'] ?? false)
            || count($missingRequiredDocuments) > 0;
    }

    private function getLocationCatalogs(): array
    {
        $allMinaLocations = Mina::query()
            ->where('estado', 'ACTIVO')
            ->orderBy('nombre')
            ->pluck('nombre')
            ->filter(fn ($name) => is_string($name) && trim($name) !== '')
            ->values();

        $officeRows = Oficina::query()
            ->orderBy('nombre')
            ->pluck('nombre')
            ->filter(fn ($name) => is_string($name) && trim($name) !== '')
            ->values();

        $tallerRows = Taller::query()
            ->orderBy('nombre')
            ->pluck('nombre')
            ->filter(fn ($name) => is_string($name) && trim($name) !== '')
            ->values();

        $fallbackOficinas = $allMinaLocations
            ->filter(fn (string $name) => str_contains(mb_strtolower($name), 'oficina'))
            ->values();

        $fallbackTalleres = $allMinaLocations
            ->filter(fn (string $name) => str_contains(mb_strtolower($name), 'taller'))
            ->values();

        $catalogOficinas = $officeRows
            ->merge($fallbackOficinas)
            ->unique(fn (string $name) => PersonalNormalizer::normalizeKey($name))
            ->values()
            ->all();

        $catalogTalleres = $tallerRows
            ->merge($fallbackTalleres)
            ->unique(fn (string $name) => PersonalNormalizer::normalizeKey($name))
            ->values()
            ->all();

        $officeKeys = collect($catalogOficinas)
            ->map(fn (string $name) => PersonalNormalizer::normalizeKey($name))
            ->filter()
            ->flip()
            ->all();

        $tallerKeys = collect($catalogTalleres)
            ->map(fn (string $name) => PersonalNormalizer::normalizeKey($name))
            ->filter()
            ->flip()
            ->all();

        $catalogMinas = $allMinaLocations
            ->reject(function (string $name): bool {
                $lower = mb_strtolower($name);

                return str_contains($lower, 'oficina') || str_contains($lower, 'taller');
            })
            ->reject(function (string $name) use ($officeKeys, $tallerKeys): bool {
                $key = PersonalNormalizer::normalizeKey($name);

                return isset($officeKeys[$key]) || isset($tallerKeys[$key]);
            })
            ->values()
            ->all();

        return [
            'catalogMinas' => $catalogMinas,
            'catalogOficinas' => $catalogOficinas,
            'catalogTalleres' => $catalogTalleres,
        ];
    }

    public function update(Request $request, string $id): RedirectResponse
    {
        $this->assertPersonalPermission(['editar', 'actualizar', 'editar_ficha']);

        $personal = $this->service->find($id);
        abort_if(!$personal, 404);

        if ($request->has('fields')) {
            if ($request->hasFile('documentos')) {
                $this->assertCanUploadPersonalDocuments();
            }

            $validated = $request->validate($this->editFichaRules());

            $this->fichaService->updateManual(
                $personal,
                $validated['fields'] ?? [],
                [
                    'estado' => $validated['estado'] ?? $personal->estado ?? 'PENDIENTE_COMPLETAR_FICHA',
                    'es_supervisor' => $validated['es_supervisor'] ?? false,
                    'minas' => $this->buildMinePayload($validated),
                    'familiares' => $validated['familiares'] ?? [],
                    'documentos' => $request->file('documentos', []),
                    'firma_base64' => $validated['firma_base64'] ?? null,
                    'huella' => $request->file('huella'),
                ],
                $this->requireAuthenticatedUser(),
            );

            return redirect()
                ->route('personal.show', $id)
                ->with('success', 'Trabajador y ficha actualizados correctamente.');
        }

        $validated = $request->validate([
            'dni' => ['required', 'string', 'max:20', 'unique:personal,dni,' . $id . ',id'],
            'tipo_documento' => ['nullable', 'string', 'max:40'],
            'numero_documento' => ['nullable', 'string', 'max:40'],
            'nombre' => ['required', 'string', 'max:191'],
            'puesto' => ['required', 'string', 'max:120', Rule::exists('personal_puestos', 'nombre')],
            'telefono' => ['nullable', 'string', 'max:30'],
            'telefono_1' => ['nullable', 'string', 'max:30'],
            'telefono_2' => ['nullable', 'string', 'max:30'],
            'correo' => ['nullable', 'email', 'max:191'],
            'tipo_contrato' => ['required', 'string', 'max:40'],
            'supervisor' => ['required', 'boolean'],
            'activo' => ['required', 'boolean'],
            'fecha_ingreso' => ['nullable', 'date'],
            'ocupacion' => ['nullable', 'string', 'max:120'],
            'minas' => ['nullable', 'array'],
            'mina_estado' => ['nullable', 'array'],
        ]);

        $this->service->update($personal, $this->buildPayloadFromWeb($validated, [
            'telefono' => $personal->telefono,
            'telefono_1' => $personal->telefono_1,
            'telefono_2' => $personal->telefono_2,
            'correo' => $personal->correo,
            'fecha_ingreso' => optional($personal->fecha_ingreso)->toDateString(),
            'ocupacion' => $personal->ocupacion,
        ]));

        return redirect()->route('personal.index')->with('success', 'Trabajador actualizado correctamente');
    }

    private function assertPersonalPermission(array $actions): void
    {
        abort_unless(
            PermissionMatrix::allowsDirectAny(session('user.permissions', []), 'personal', $actions),
            403,
            'No tienes permiso para realizar esta accion.'
        );
    }

    private function assertCanUploadPersonalDocuments(): void
    {
        $permissions = session('user.permissions', []);

        abort_unless(
            PermissionMatrix::allowsDirect($permissions, 'personal', 'subir_documentos')
                || PermissionMatrix::allowsDirect($permissions, 'personal_documentos', 'subir'),
            403,
            'No tienes permiso para realizar esta accion.'
        );
    }

    public function destroy(string $id): RedirectResponse
    {
        abort_unless(PermissionMatrix::userCanDirect($this->requireAuthenticatedUser(), 'personal', 'eliminar'), 403);

        $personal = $this->service->find($id);
        abort_if(!$personal, 404);

        try {
            $this->service->deleteCompletely($personal);
        } catch (ValidationException $exception) {
            return redirect()
                ->route('personal.show', $id)
                ->with('error', collect($exception->errors())->flatten()->first() ?: 'No se pudo eliminar el trabajador.');
        }

        return redirect()
            ->route('personal.index')
            ->with('success', 'Trabajador eliminado por completo.');
    }

    public function cease(Request $request, string $id): RedirectResponse
    {
        $usuario = $this->requireAuthenticatedUser();
        abort_unless(PermissionMatrix::userCanDirect($usuario, 'personal', 'cesar_trabajador'), 403);

        $personal = $this->service->find($id);
        abort_if(!$personal, 404);

        $validated = $request->validate([
            'motivo_cese' => ['required', 'string', 'max:2000'],
        ], [
            'motivo_cese.required' => 'El motivo de cese es obligatorio.',
            'motivo_cese.max' => 'El motivo de cese no debe superar 2000 caracteres.',
        ]);

        try {
            $this->service->markCeased($personal, $validated['motivo_cese'], $usuario);
        } catch (ValidationException $exception) {
            return redirect()
                ->route('personal.index')
                ->with('error', collect($exception->errors())->flatten()->first() ?: 'No se pudo cesar el trabajador.');
        }

        return redirect()
            ->route('personal.index')
            ->with('success', 'El trabajador fue marcado como cesado.');
    }

    public function addToListaNegra(Request $request, string $id): RedirectResponse
    {
        $usuario = $this->requireAuthenticatedUser();
        abort_unless(PermissionMatrix::userCanDirect($usuario, 'personal', 'gestionar_lista_negra'), 403);

        $personal = $this->service->find($id);
        abort_if(!$personal, 404);

        $validated = $request->validate([
            'motivo_lista_negra' => ['required', 'string', 'max:2000'],
        ], [
            'motivo_lista_negra.required' => 'El motivo de lista negra es obligatorio.',
            'motivo_lista_negra.max' => 'El motivo de lista negra no debe superar 2000 caracteres.',
        ]);

        try {
            $this->service->addToListaNegra($personal, $validated['motivo_lista_negra'], $usuario);
        } catch (ValidationException $exception) {
            return redirect()
                ->route('personal.index')
                ->with('error', collect($exception->errors())->flatten()->first() ?: 'No se pudo agregar a lista negra.');
        }

        return redirect()
            ->route('personal.index')
            ->with('success', 'El trabajador fue agregado a lista negra.');
    }

    public function removeFromListaNegra(string $id): RedirectResponse
    {
        $usuario = $this->requireAuthenticatedUser();
        abort_unless(PermissionMatrix::userCanDirect($usuario, 'personal', 'gestionar_lista_negra'), 403);

        $personal = $this->service->find($id);
        abort_if(!$personal, 404);

        try {
            $this->service->removeFromListaNegra($personal);
        } catch (ValidationException $exception) {
            return redirect()
                ->route('personal.index')
                ->with('error', collect($exception->errors())->flatten()->first() ?: 'No se pudo quitar de lista negra.');
        }

        return redirect()
            ->route('personal.index')
            ->with('success', 'El trabajador fue quitado de lista negra.');
    }

    public function activate(Request $request, string $id): RedirectResponse
    {
        $usuario = $this->requireAuthenticatedUser();
        abort_unless(PermissionMatrix::userCanDirect($usuario, 'personal', 'activar_trabajador'), 403);

        $personal = $this->service->find($id);
        abort_if(!$personal, 404);

        $estadoVisible = strtoupper((string) (PersonalResource::make($personal)->resolve()['estado'] ?? $personal->estado));
        if ($estadoVisible !== 'CESADO') {
            return redirect()
                ->route('personal.index')
                ->with('error', 'Solo se puede activar a un trabajador cesado.');
        }

        $contractTypeOptions = $this->contratoService->contractTypeOptions();
        $contractTypeRules = ['nullable', 'string', 'max:40'];
        if ($contractTypeOptions !== []) {
            $contractTypeRules[] = Rule::in(array_keys($contractTypeOptions));
        }
        $puestoRules = ['nullable', 'string', 'max:191'];
        if (Schema::hasTable('personal_puestos')) {
            $puestoRules[] = Rule::exists('personal_puestos', 'nombre');
        }

        $validated = $request->validate([
            'fecha_inicio' => ['required', 'date'],
            'fecha_fin' => ['nullable', 'date', 'after_or_equal:fecha_inicio'],
            'puesto' => $puestoRules,
            'tipo_contrato' => $contractTypeRules,
            'ocupacion' => ['nullable', 'string', 'max:191'],
            'area' => ['nullable', 'string', 'max:191'],
            'remuneracion' => ['nullable', 'string', 'max:120'],
            'costo_hora' => ['nullable', 'string', 'max:120'],
            'banco' => ['nullable', 'string', 'max:120'],
            'banco_otro' => ['nullable', 'string', 'max:120'],
            'numero_cuenta' => ['nullable', 'string', 'max:60'],
            'cci' => ['nullable', 'string', 'max:60'],
            'sistema_pensionario' => ['nullable', 'string', 'max:120'],
            'tipo_comision' => ['nullable', 'string', 'max:120'],
            'tipo_afp' => ['nullable', 'string', 'max:120'],
            'cuspp' => ['nullable', 'string', 'max:60'],
        ], [
            'fecha_inicio.required' => 'La fecha de inicio es obligatoria.',
            'fecha_fin.after_or_equal' => 'La fecha de fin no puede ser anterior al inicio.',
            'puesto.exists' => 'Selecciona un puesto registrado.',
            'tipo_contrato.in' => 'Selecciona un tipo de contrato registrado.',
        ]);

        try {
            $this->contratoService->activateNextContract(
                $personal,
                $validated['fecha_inicio'],
                $validated['fecha_fin'] ?? null,
                $usuario,
                $validated,
            );
        } catch (ValidationException $exception) {
            return redirect()
                ->route('personal.index')
                ->with('error', collect($exception->errors())->flatten()->first() ?: 'No se pudo activar el trabajador.');
        }

        return redirect()
            ->route('personal.edit', $id)
            ->with('success', 'Trabajador reactivado con un nuevo contrato. Queda pendiente de contrato firmado antes de pasar a activo.');
    }

    private function buildPayloadFromWeb(array $validated, array $existing = []): array
    {
        return [
            'tipo_documento' => $validated['tipo_documento'] ?? 'DNI',
            'numero_documento' => $validated['numero_documento'] ?? $validated['dni'],
            'dni' => $validated['dni'],
            'nombre_completo' => $validated['nombre'],
            'puesto' => $validated['puesto'],
            'telefono' => array_key_exists('telefono', $validated) ? $validated['telefono'] : ($existing['telefono'] ?? null),
            'telefono_1' => array_key_exists('telefono_1', $validated)
                ? $validated['telefono_1']
                : ($validated['telefono'] ?? ($existing['telefono_1'] ?? $existing['telefono'] ?? null)),
            'telefono_2' => array_key_exists('telefono_2', $validated) ? $validated['telefono_2'] : ($existing['telefono_2'] ?? null),
            'correo' => array_key_exists('correo', $validated) ? $validated['correo'] : ($existing['correo'] ?? null),
            'contrato' => $validated['tipo_contrato'],
            'es_supervisor' => (bool) $validated['supervisor'],
            'estado' => (bool) $validated['activo'] ? 'ACTIVO' : 'INACTIVO',
            'fecha_ingreso' => array_key_exists('fecha_ingreso', $validated) ? $validated['fecha_ingreso'] : ($existing['fecha_ingreso'] ?? null),
            'ocupacion' => array_key_exists('ocupacion', $validated) ? $validated['ocupacion'] : ($existing['ocupacion'] ?? null),
            'minas' => $this->buildMinePayload($validated),
        ];
    }

    private function manualCreateRules(): array
    {
        $rules = [
            'fields' => ['required', 'array'],
            'es_supervisor' => ['nullable', 'boolean'],
            'minas' => ['nullable', 'array'],
            'mina_estado' => ['nullable', 'array'],
        ];

        foreach (PersonalFichaCatalog::fields() as $key => $field) {
            $fieldRules = match ($field['type']) {
                'date' => ['date'],
                'email' => ['email', 'max:191'],
                'select' => count($field['options'] ?? []) > 0
                    ? ['string', 'in:' . implode(',', array_map('strval', array_keys($field['options'] ?? [])))]
                    : ['string', 'max:191'],
                'textarea' => ['string', 'max:5000'],
                'hidden' => ['string', 'max:5000'],
                default => ['string', 'max:191'],
            };

            array_unshift($fieldRules, 'nullable');
            $rules['fields.' . $key] = $fieldRules;
        }

        $rules['fields.tipo_documento'] = ['required', 'string', 'in:' . implode(',', array_map('strval', array_keys(PersonalFichaCatalog::DOCUMENT_TYPES)))];
        $rules['fields.numero_documento'] = ['required', 'string', 'max:40'];
        $rules['fields.nombres'] = ['required', 'string', 'max:191'];
        $rules['fields.apellido_paterno'] = ['required', 'string', 'max:191'];
        $rules['fields.apellido_materno'] = ['required', 'string', 'max:191'];
        $rules['fields.telefono'] = ['required', 'string', 'max:30'];
        $rules['fields.correo'] = ['required', 'email', 'max:191'];
        $rules['fields.puesto'] = ['nullable', 'string', 'max:191', Rule::exists('personal_puestos', 'nombre')];
        $rules['familiares'] = ['nullable', 'array'];
        $rules['familiares.*.nombres_apellidos'] = ['nullable', 'string', 'max:191'];
        $rules['familiares.*.parentesco'] = ['nullable', 'string', 'max:80'];
        $rules['familiares.*.fecha_nacimiento'] = ['nullable', 'date'];
        $rules['familiares.*.tipo_documento'] = ['nullable', 'string', 'max:40'];
        $rules['familiares.*.numero_documento'] = ['nullable', 'string', 'max:40'];
        $rules['familiares.*.telefono'] = ['nullable', 'string', 'max:30'];
        $rules['familiares.*.vive_con_trabajador'] = ['nullable'];
        $rules['familiares.*.estudia'] = ['nullable'];
        $rules['familiares.*.contacto_emergencia'] = ['nullable'];
        $rules['documentos'] = ['nullable', 'array'];

        foreach (PersonalFichaCatalog::documentRequirements() as $key => $requirement) {
            $rules['documentos.' . $key] = ['nullable', 'file', 'max:10240', 'mimes:pdf,doc,docx,jpg,jpeg,png,webp'];
        }

        $rules['firma_base64'] = ['nullable', 'string', 'max:2500000'];
        $rules['huella'] = ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'];

        return $rules;
    }

    private function legacyRegularizationRules(): array
    {
        return [
            'origen_registro' => ['required', 'string', 'in:ANTIGUO,HISTORICO,IMPORTADO'],
            'pendiente_regularizacion' => ['nullable', 'boolean'],
            'sincronizar_contrato' => ['nullable', 'boolean'],
            'estado_contrato' => ['required', 'string', 'in:VIGENTE,CERRADO'],
            'contrato' => ['nullable', 'string', 'in:REG,FIJO,INTER,INDET'],
            'fecha_inicio' => ['nullable', 'date'],
            'fecha_fin' => ['nullable', 'date', 'after_or_equal:fecha_inicio'],
            'fecha_firma' => ['nullable', 'date'],
            'area' => ['nullable', 'string', 'max:191'],
            'remuneracion' => ['nullable', 'string', 'max:120'],
            'costo_hora' => ['nullable', 'string', 'max:120'],
            'motivo_cese' => ['nullable', 'string', 'max:2000'],
            'observacion_historica' => ['nullable', 'string', 'max:5000'],
            'contrato_firmado' => ['nullable', 'file', 'max:15360', 'mimes:pdf'],
        ];
    }

    private function editFichaRules(): array
    {
        $rules = $this->manualCreateRules();
        $rules['fields.puesto'] = ['required', 'string', 'max:191', Rule::exists('personal_puestos', 'nombre')];
        $rules['estado'] = ['required', 'in:ACTIVO,FALTA_CONTRATO,NO_FIRMO_CONTRATO,INACTIVO,CESADO,PENDIENTE_COMPLETAR_FICHA,FICHA_ENVIADA,LINK_VENCIDO,APROBADO,OBSERVADO,RECHAZADO'];

        return $rules;
    }

    private function puestoOptions(): array
    {
        if (!Schema::hasTable('personal_puestos')) {
            return [];
        }

        return PersonalPuesto::query()
            ->where('activo', true)
            ->orderBy('nombre')
            ->pluck('nombre')
            ->filter(fn ($name) => is_string($name) && trim($name) !== '')
            ->values()
            ->all();
    }

    private function buildMinePayload(array $validated): array
    {
        $stateMap = $validated['mina_estado'] ?? [];
        $normalizedStateMap = collect($stateMap)
            ->mapWithKeys(function ($state, $name): array {
                return [PersonalNormalizer::normalizeKey((string) $name) => $state];
            })
            ->all();

        $knownMines = Mina::query()->get(['id', 'nombre', 'unidad_minera']);

        $selectedMines = collect($validated['minas'] ?? [])
            ->map(fn ($name) => trim((string) $name))
            ->filter(fn (string $name) => $name !== '')
            ->unique(fn (string $name) => PersonalNormalizer::normalizeKey($name))
            ->values();

        $findMine = function (string $mineName) use ($knownMines): ?Mina {
            $needle = PersonalNormalizer::normalizeKey($mineName);

            return $knownMines->first(function (Mina $mine) use ($needle): bool {
                return in_array($needle, [
                    PersonalNormalizer::normalizeKey((string) $mine->id),
                    PersonalNormalizer::normalizeKey((string) $mine->nombre),
                    PersonalNormalizer::normalizeKey((string) $mine->unidad_minera),
                ], true);
            });
        };

        return collect($selectedMines)
            ->map(function (string $mineName) use ($stateMap, $normalizedStateMap, $knownMines, $findMine): ?array {
                $match = $findMine($mineName);

                if (!$match) {
                    $match = Mina::query()->create([
                        'id' => (string) Str::uuid(),
                        'nombre' => $mineName,
                        'unidad_minera' => $mineName,
                        'ubicacion' => 'Por definir',
                        'estado' => 'ACTIVO',
                    ]);

                    $knownMines->push($match);
                }

                $normalizedKey = PersonalNormalizer::normalizeKey($mineName);
                $state = $stateMap[$mineName]
                    ?? ($normalizedStateMap[$normalizedKey] ?? 'habilitado');

                return [
                    'mina_id' => $match?->id,
                    'mina_nombre' => $mineName,
                    'estado' => $state,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    private function initialFichaFieldsForEdit(array $trabajador, $ficha): array
    {
        if ($ficha) {
            return $this->fichaService->fichaDataForPublic($ficha);
        }

        $fields = PersonalFichaCatalog::emptyData();
        $fullName = preg_split('/\s+/', trim((string) ($trabajador['nombre_completo'] ?? $trabajador['nombre'] ?? ''))) ?: [];

        if (count($fullName) >= 3) {
            $fields['apellido_paterno'] = $fullName[0] ?? '';
            $fields['apellido_materno'] = $fullName[1] ?? '';
            $fields['nombres'] = implode(' ', array_slice($fullName, 2));
        } else {
            $fields['nombres'] = trim((string) ($trabajador['nombre_completo'] ?? $trabajador['nombre'] ?? ''));
        }

        $fields['tipo_documento'] = (string) ($trabajador['tipo_documento'] ?? 'DNI');
        $fields['numero_documento'] = (string) ($trabajador['numero_documento'] ?? $trabajador['dni'] ?? '');
        $fields['telefono'] = (string) ($trabajador['telefono'] ?? '');
        $fields['correo'] = (string) ($trabajador['correo'] ?? '');
        $fields['puesto'] = (string) ($trabajador['puesto'] ?? '');
        $fields['ocupacion'] = (string) ($trabajador['ocupacion'] ?? '');
        $fields['contrato'] = (string) ($trabajador['contrato'] ?? 'REG');
        $fields['fecha_ingreso'] = (string) ($trabajador['fecha_ingreso'] ?? '');

        return $this->fichaService->normalizeFichaData($fields);
    }
}
