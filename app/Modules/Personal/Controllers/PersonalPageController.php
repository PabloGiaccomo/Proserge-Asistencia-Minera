<?php

namespace App\Modules\Personal\Controllers;

use App\Http\Controllers\WebPageController;
use App\Models\Mina;
use App\Models\Oficina;
use App\Models\Taller;
use App\Modules\Personal\Resources\PersonalResource;
use App\Modules\Personal\Services\ExportPersonalService;
use App\Modules\Personal\Services\PersonalService;
use App\Modules\Personal\Support\PersonalExportConfig;
use App\Modules\Personal\Support\PersonalNormalizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class PersonalPageController extends WebPageController
{
    public function __construct(
        private readonly PersonalService $service,
        private readonly ExportPersonalService $exportService,
    ) {
    }

    public function home(): View
    {
        return view('personal.home');
    }

    public function index(Request $request)
    {
        if (strtolower((string) $request->query('export')) === 'excel') {
            return $this->exportService->download($request->query(), 'personal_web_' . now()->format('Ymd_His') . '.xlsx');
        }

        $trabajadores = PersonalResource::collection(
            $this->service->list($request->query())
        )->resolve();

        return view('personal.index', compact('trabajadores'));
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

        return view('personal.export', [
            'config' => $config,
            'availableColumns' => $availableColumns,
            'recommendedColumns' => $recommendedColumns,
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

    public function show(string $id): View
    {
        $personal = $this->service->find($id);
        abort_if(!$personal, 404);

        $trabajador = PersonalResource::make($personal)->resolve();

        return view('personal.show', compact('id', 'trabajador'));
    }

    public function create(): View
    {
        return view('personal.create', $this->getLocationCatalogs());
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'dni' => ['required', 'string', 'max:20', 'unique:personal,dni'],
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

        $this->service->create($this->buildPayloadFromWeb($validated));

        return redirect()->route('personal.index')->with('success', 'Trabajador creado correctamente');
    }

    public function edit(string $id): View
    {
        $personal = $this->service->find($id);
        abort_if(!$personal, 404);

        $trabajador = PersonalResource::make($personal)->resolve();

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

        return view('personal.edit', array_merge($catalogs, ['trabajador' => $trabajador]));
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

        $validated = $request->validate([
            'dni' => ['required', 'string', 'max:20', 'unique:personal,dni,' . $id . ',id'],
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

    private function buildPayloadFromWeb(array $validated, array $existing = []): array
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

        $mines = collect($selectedMines)
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

        return [
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
            'minas' => $mines,
        ];
    }
}
