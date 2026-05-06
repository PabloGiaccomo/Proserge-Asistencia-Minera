<?php

namespace App\Modules\Personal\Controllers;

use App\Http\Controllers\WebPageController;
use App\Models\Mina;
use App\Models\Oficina;
use App\Models\Taller;
use App\Modules\Personal\Resources\PersonalResource;
use App\Modules\Personal\Services\ExportPersonalService;
use App\Modules\Personal\Services\PersonalFichaExportService;
use App\Modules\Personal\Services\PersonalFichaService;
use App\Modules\Personal\Services\PersonalService;
use App\Modules\Personal\Support\PersonalFichaCatalog;
use App\Modules\Personal\Support\PersonalExportConfig;
use App\Modules\Personal\Support\PersonalNormalizer;
use App\Support\Rbac\PermissionMatrix;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PersonalPageController extends WebPageController
{
    public function __construct(
        private readonly PersonalService $service,
        private readonly ExportPersonalService $exportService,
        private readonly PersonalFichaExportService $fichaExportService,
        private readonly PersonalFichaService $fichaService,
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
        $this->fichaService->expireStaleLinks();

        if (strtolower((string) $request->query('export')) === 'excel') {
            return $this->exportService->download($request->query(), 'personal_web_' . now()->format('Ymd_His') . '.xlsx');
        }

        $filters = $request->query();
        $visibleStateFilter = strtoupper(trim((string) ($filters['estado'] ?? '')));
        if (in_array($visibleStateFilter, ['ACTIVO', 'INACTIVO', 'CESADO'], true) === false) {
            $visibleStateFilter = match (strtolower((string) ($filters['estado'] ?? ''))) {
                'activo' => 'ACTIVO',
                'inactivo' => 'INACTIVO',
                'cesado' => 'CESADO',
                default => '',
            };
        }
        if (in_array($visibleStateFilter, ['ACTIVO', 'INACTIVO', 'CESADO'], true)) {
            unset($filters['estado']);
        }

        $trabajadores = PersonalResource::collection(
            $this->service->list($filters)
        )->resolve();

        if (in_array($visibleStateFilter, ['ACTIVO', 'INACTIVO', 'CESADO'], true)) {
            $trabajadores = array_values(array_filter($trabajadores, fn (array $trabajador): bool => strtoupper((string) ($trabajador['estado'] ?? '')) === $visibleStateFilter));
        }

        $catalogs = $this->getLocationCatalogs();

        return view('personal.index', array_merge($catalogs, compact('trabajadores')));
    }

    public function exportForm(Request $request): View
    {
        $availableColumns = $this->exportService->availableColumns();
        $config = PersonalExportConfig::fromInput($request->query(), array_keys($availableColumns), true);
        $preview = $this->exportService->preview($config);

        $minas = Mina::query()
            ->where('estado', 'ACTIVO')
            ->orderBy('nombre')
            ->get(['id', 'nombre'])
            ->map(fn (Mina $mine): array => [
                'id' => (string) $mine->id,
                'nombre' => (string) $mine->nombre,
            ])
            ->values()
            ->all();

        $recommendedColumns = PersonalExportConfig::recommendedColumns(array_keys($availableColumns));
        $availableFichaColumns = $this->fichaExportService->availableColumns();
        $recommendedFichaColumns = $this->fichaExportService->recommendedColumns();
        $fichaPreview = $this->fichaExportService->preview($request->query());

        return view('personal.export', [
            'config' => $config,
            'availableColumns' => $availableColumns,
            'recommendedColumns' => $recommendedColumns,
            'availableFichaColumns' => $availableFichaColumns,
            'recommendedFichaColumns' => $recommendedFichaColumns,
            'fichaPreview' => $fichaPreview,
            'preview' => $preview,
            'minas' => $minas,
        ]);
    }

    public function exportDownload(Request $request)
    {
        $availableColumns = $this->exportService->availableColumns();
        $config = PersonalExportConfig::fromInput($request->all(), array_keys($availableColumns), false);

        if (count($config->columns) === 0) {
            return redirect()
                ->route('personal.export.form', $request->except('_token'))
                ->with('error', 'Debes seleccionar al menos una columna para exportar.');
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

    public function show(string $id): RedirectResponse
    {
        $personal = $this->service->find($id);
        abort_if(!$personal, 404);

        return redirect()->route('personal.edit', $id);
    }

    public function create(): View
    {
        return view('personal.create', array_merge($this->getLocationCatalogs(), [
            'sections' => PersonalFichaCatalog::sections(),
            'initialFields' => PersonalFichaCatalog::emptyData(),
        ]));
    }

    public function store(Request $request): View|RedirectResponse
    {
        $validated = $request->validate($this->manualCreateRules());

        $result = $this->fichaService->createManual(
            $validated['fields'] ?? [],
            [
                'es_supervisor' => $validated['es_supervisor'] ?? false,
                'minas' => $this->buildMinePayload($validated),
            ],
            $this->requireAuthenticatedUser(),
        );

        return view('personal.fichas.link', [
            'result' => $result,
            'url' => $result['url'],
            'trabajador' => $result['personal'],
            'ficha' => $result['ficha'],
        ]);
    }

    public function edit(string $id): View
    {
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

        return view('personal.edit', array_merge($catalogs, [
            'trabajador' => $trabajador,
            'ficha' => $ficha,
            'sections' => PersonalFichaCatalog::sections(),
            'initialFields' => $this->initialFichaFieldsForEdit($trabajador, $ficha),
            'missingRequiredDocuments' => $this->fichaService->missingRequiredDocumentKeys($ficha),
            'missingRequiredFichaFields' => $regularizationSummary['missing_fields'],
            'regularizationSummary' => $regularizationSummary,
        ]));
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
        $personal = $this->service->find($id);
        abort_if(!$personal, 404);

        if ($request->has('fields')) {
            $validated = $request->validate($this->editFichaRules());

            $this->fichaService->updateManual(
                $personal,
                $validated['fields'] ?? [],
                [
                    'estado' => $validated['estado'] ?? 'ACTIVO',
                    'es_supervisor' => $validated['es_supervisor'] ?? false,
                    'minas' => $this->buildMinePayload($validated),
                    'familiares' => $validated['familiares'] ?? [],
                    'documentos' => $request->file('documentos', []),
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
            'puesto' => ['required', 'string', 'max:120'],
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

    public function destroy(string $id): RedirectResponse
    {
        abort_unless(PermissionMatrix::userCan($this->requireAuthenticatedUser(), 'personal', 'eliminar'), 403);

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

    public function cease(string $id): RedirectResponse
    {
        abort_unless(PermissionMatrix::userCanAny($this->requireAuthenticatedUser(), 'personal', ['editar', 'actualizar', 'administrar']), 403);

        $personal = $this->service->find($id);
        abort_if(!$personal, 404);

        try {
            $this->service->markIndeterminateContractCeased($personal);
        } catch (ValidationException $exception) {
            return redirect()
                ->route('personal.index')
                ->with('error', collect($exception->errors())->flatten()->first() ?: 'No se pudo cesar el trabajador.');
        }

        return redirect()
            ->route('personal.index')
            ->with('success', 'El trabajador indeterminado fue marcado como cesado.');
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
        $rules['familiares'] = ['nullable', 'array'];
        $rules['familiares.*.nombres_apellidos'] = ['nullable', 'string', 'max:191'];
        $rules['familiares.*.parentesco'] = ['nullable', 'string', 'max:80'];
        $rules['familiares.*.fecha_nacimiento'] = ['nullable', 'date'];
        $rules['familiares.*.tipo_documento'] = ['nullable', 'string', 'max:40'];
        $rules['familiares.*.numero_documento'] = ['nullable', 'string', 'max:40'];
        $rules['familiares.*.telefono'] = ['nullable', 'string', 'max:30'];
        $rules['familiares.*.vive_con_trabajador'] = ['nullable'];
        $rules['familiares.*.contacto_emergencia'] = ['nullable'];
        $rules['documentos'] = ['nullable', 'array'];

        foreach (PersonalFichaCatalog::documentRequirements() as $key => $requirement) {
            $rules['documentos.' . $key] = ['nullable', 'file', 'max:10240', 'mimes:pdf,doc,docx,jpg,jpeg,png,webp'];
        }

        return $rules;
    }

    private function editFichaRules(): array
    {
        return [
            ...$this->manualCreateRules(),
            'estado' => ['required', 'in:ACTIVO,INACTIVO,CESADO'],
        ];
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
