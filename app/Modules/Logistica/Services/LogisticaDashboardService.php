<?php

namespace App\Modules\Logistica\Services;

use App\Models\EppEntrega;
use App\Models\EppRegistro;
use App\Models\Mina;
use App\Models\ParadaHerramientaLista;
use App\Models\ParadaHerramientaCatalogo;
use App\Models\Personal;
use App\Models\PersonalContrato;
use App\Models\PersonalMina;
use App\Models\RQMina;
use App\Models\RQMinaActividadTransporte;
use App\Models\RQMinaActividadTransporteEvento;
use App\Models\RQProsergeDetalle;
use App\Models\Usuario;
use App\Modules\Epps\Services\EppService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class LogisticaDashboardService
{
    private const TAB_DASHBOARD = 'dashboard';
    private const TAB_ENTREGAS = 'entregas';
    private const TAB_VENCIMIENTOS = 'vencimientos';
    private const TAB_HERRAMIENTAS = 'herramientas';
    private const TAB_SERVICIOS = 'servicios';
    private const TAB_IDENTIFICACION = 'identificacion';
    private const TAB_COSTOS = 'costos';
    private const TAB_KARDEX = 'kardex';
    private const TAB_CESADOS = 'cesados';

    private const EPP_ITEMS = [
        'casco' => 'Casco',
        'chaleco' => 'Chaleco',
        'zapatos' => 'Zapatos',
        'camisa' => 'Camisa',
        'pantalon' => 'Pantalon',
        'respirador' => 'Respirador',
    ];

    private const SIZE_FIELDS = [
        'zapatos' => [
            'label' => 'Zapatos',
            'keys' => ['talla_zapato', 'zapato_botas', 'zapato', 'botas', 'talla_calzado'],
            'valid' => ['35', '36', '37', '38', '39', '40', '41', '42', '43', '44', '45'],
        ],
        'camisa' => [
            'label' => 'Camisa',
            'keys' => ['talla_polo', 'camisa_chaleco', 'camisa', 'talla_camisa', 'talla_chaleco'],
            'valid' => ['XS', 'S', 'M', 'L', 'XL', 'XXL', 'XXXL'],
        ],
        'pantalon' => [
            'label' => 'Pantalon',
            'keys' => ['talla_pantalon', 'pantalon'],
            'valid' => ['28', '30', '32', '34', '36', '38', '40', '42', '44'],
        ],
        'respirador' => [
            'label' => 'Respirador',
            'keys' => ['talla_respirador', 'respirador'],
            'valid' => ['S', 'M', 'L', 'XL'],
        ],
    ];

    public function __construct(private readonly EppService $eppService)
    {
    }

    public function pageData(array $query): array
    {
        $tabs = $this->tabs();
        $filters = $this->filters($query, array_keys($tabs));
        $options = $this->options();
        $workers = $this->workers($filters);
        $catalog = $this->eppCatalog($filters);
        $deliveries = $this->deliveries($workers->pluck('id'), $catalog->pluck('id'), $filters);
        $activeDeliveries = $deliveries->where('estado', EppEntrega::ESTADO_ENTREGADO);
        $trackedCatalog = $this->trackedCatalog($catalog);
        $workers = $this->filterWorkersByProfile($workers, $filters);
        $activeDeliveries = $activeDeliveries->whereIn('personal_id', $workers->pluck('id')->all());
        $deliveries = $deliveries->whereIn('personal_id', $workers->pluck('id')->all())->values();
        $workerEppRows = $this->workerEppRows($workers, $trackedCatalog, $activeDeliveries, $filters);
        if ($filters['epp_estado'] !== []) {
            $workers = $workers->whereIn('id', $workerEppRows->pluck('personal_id')->all())->values();
            $activeDeliveries = $activeDeliveries->whereIn('personal_id', $workers->pluck('id')->all())->values();
            $deliveries = $deliveries->whereIn('personal_id', $workers->pluck('id')->all())->values();
            $workerEppRows = $this->workerEppRows($workers, $trackedCatalog, $activeDeliveries, $filters);
        }
        $requirements = $this->requirements($workers, $trackedCatalog, $activeDeliveries);
        $coverageByItem = $this->coverageByItem($trackedCatalog, $workers, $activeDeliveries);
        $sizeSummary = $this->sizeSummary($workers);
        $missingSizeWorkers = $this->missingSizeWorkers($workers);
        $stockRows = $this->stockRows($requirements, $workers);
        $expiringDeliveries = $this->expiringDeliveries($activeDeliveries);
        $filteredExpiringDeliveries = $this->filterExpiringDeliveries(
            $this->expiringDeliveries($activeDeliveries, false),
            $filters['vencimientos']
        );
        $mineSummary = $this->mineSummary($workers);
        $cargoSummary = $this->cargoSummary($workers);
        $heatmap = $this->missingHeatmap($workerEppRows);
        $urgentActions = $this->urgentActions($missingSizeWorkers, $requirements, $expiringDeliveries, $heatmap, $stockRows);
        $filterChips = $this->filterChips($filters, $options);
        $pendingEpp = $requirements->sum('pendiente_entrega');
        $requiredEpp = $requirements->sum('requerido');
        $deliveredEpp = $requirements->sum('entregado');
        $expiringEpp = $expiringDeliveries->where('estado_visual', 'POR_VENCER')->count();
        $expiredEpp = $expiringDeliveries->where('estado_visual', 'VENCIDO')->count();
        $habilitatedWorkers = $this->habilitatedWorkersCount($workers, $filters);
        $coveragePct = $requiredEpp > 0 ? round(($deliveredEpp / $requiredEpp) * 100, 1) : 0;
        $ceasedRows = $this->ceasedEppRows($filters);

        return [
            'tabs' => $this->tabOptions($tabs),
            'activeTab' => $filters['tab'],
            'filters' => $filters,
            'options' => $options,
            'filterChips' => $filterChips,
            'metrics' => [
                'workers' => $workers->count(),
                'habilitados' => $habilitatedWorkers,
                'habilitados_pct' => $workers->isNotEmpty() ? round(($habilitatedWorkers / $workers->count()) * 100, 1) : 0,
                'required_epp' => $requiredEpp,
                'delivered_epp' => $deliveredEpp,
                'coverage_pct' => $coveragePct,
                'pending_epp' => $pendingEpp,
                'expired_epp' => $expiredEpp,
                'expiring_epp' => $expiringEpp,
                'expiring_7' => $expiringDeliveries->filter(fn (array $row): bool => (int) $row['dias'] >= 0 && (int) $row['dias'] <= 7)->count(),
                'expiring_15' => $expiringDeliveries->filter(fn (array $row): bool => (int) $row['dias'] >= 0 && (int) $row['dias'] <= 15)->count(),
                'expiring_30' => $expiringDeliveries->filter(fn (array $row): bool => (int) $row['dias'] >= 0 && (int) $row['dias'] <= 30)->count(),
                'minas' => $mineSummary->count(),
                'cargos' => $cargoSummary->count(),
                'faltantes' => $pendingEpp,
                'porVencer' => $expiringEpp,
                'vencidos' => $expiredEpp,
                'entregasRecientes' => $deliveries->count(),
                'stockCritico' => $stockRows->whereIn('estado', ['Critico', 'Sin stock'])->count(),
                'fichas_incompletas_tallas' => $missingSizeWorkers->count(),
            ],
            'mineSummary' => $mineSummary,
            'cargoSummary' => $cargoSummary,
            'sizeSummary' => $sizeSummary,
            'requirements' => $requirements,
            'coverageByItem' => $coverageByItem,
            'missingHeatmap' => $heatmap,
            'missingWorkers' => $this->missingWorkers($workers, $trackedCatalog, $activeDeliveries),
            'missingSizeWorkers' => $missingSizeWorkers,
            'stockRows' => $stockRows,
            'recentDeliveries' => $this->recentDeliveries($deliveries),
            'expiringDeliveries' => $expiringDeliveries,
            'filteredExpiringDeliveries' => $filteredExpiringDeliveries,
            'urgentActions' => $urgentActions,
            'toolsRows' => $this->toolsRows(),
            'serviceRows' => $this->serviceRows(),
            'identityRows' => $this->identityRows($filters),
            'costRows' => $this->costRows($catalog),
            'ceasedRows' => $ceasedRows,
            'ceasedSummary' => [
                'trabajadores' => $ceasedRows->count(),
                'pendientes' => $ceasedRows->sum('pendientes'),
                'resueltos' => $ceasedRows->sum('resueltos'),
            ],
        ];
    }

    private function tabs(): array
    {
        return [
            self::TAB_DASHBOARD => 'Dashboard',
            self::TAB_ENTREGAS => 'Entregas y cambios de EPP',
            self::TAB_VENCIMIENTOS => 'Proximos vencimientos de EPP',
            self::TAB_HERRAMIENTAS => 'Herramientas',
            self::TAB_SERVICIOS => 'Servicios y alquileres',
            self::TAB_IDENTIFICACION => 'Identificacion de items',
            self::TAB_COSTOS => 'Costos y facturacion',
            self::TAB_KARDEX => 'Kardex',
            self::TAB_CESADOS => 'Cesados por entregar',
        ];
    }

    private function tabOptions(array $tabs): array
    {
        return collect($tabs)
            ->map(static fn (string $label, string $key): array => [
                'key' => $key,
                'label' => $label,
            ])
            ->values()
            ->all();
    }

    private function filters(array $query, array $tabs): array
    {
        $tab = $this->normalizeTab((string) ($query['tab'] ?? ''), $tabs);

        return [
            'tab' => $tab,
            'q' => trim((string) ($query['q'] ?? '')),
            'parada_id' => trim((string) ($query['parada_id'] ?? '')),
            'minas' => $this->arrayFilter($query['minas'] ?? []),
            'cargos' => $this->arrayFilter($query['cargos'] ?? []),
            'estados' => $this->arrayFilter($query['estados'] ?? []),
            'epp_estado' => $this->arrayFilter($query['epp_estado'] ?? []),
            'ficha' => trim((string) ($query['ficha'] ?? '')),
            'talla_estado' => trim((string) ($query['talla_estado'] ?? '')),
            'fecha_desde' => trim((string) ($query['fecha_desde'] ?? '')),
            'fecha_hasta' => trim((string) ($query['fecha_hasta'] ?? '')),
            'epps' => $this->arrayFilter($query['epps'] ?? []),
            'tallas' => $this->arrayFilter($query['tallas'] ?? []),
            'ident_categoria' => strtoupper(trim((string) ($query['ident_categoria'] ?? 'EPP'))),
            'vencimientos' => [
                'q' => trim((string) ($query['venc_q'] ?? '')),
                'mina_id' => trim((string) ($query['venc_mina_id'] ?? '')),
                'epp_id' => trim((string) ($query['venc_epp_id'] ?? '')),
                'talla' => trim((string) ($query['venc_talla'] ?? '')),
                'estado' => trim((string) ($query['venc_estado'] ?? '')),
                'rango' => trim((string) ($query['venc_rango'] ?? '30')),
                'fecha_desde' => trim((string) ($query['venc_fecha_desde'] ?? '')),
                'fecha_hasta' => trim((string) ($query['venc_fecha_hasta'] ?? '')),
            ],
        ];
    }

    private function normalizeTab(string $tab, array $tabs): string
    {
        $tab = match ($tab) {
            'entregas-epp', 'entregas_epp', 'epp-entregas' => self::TAB_ENTREGAS,
            default => $tab,
        };

        return in_array($tab, $tabs, true) ? $tab : self::TAB_DASHBOARD;
    }

    private function arrayFilter(mixed $value): array
    {
        if (! is_array($value)) {
            $value = $value === null || $value === '' ? [] : [$value];
        }

        return array_values(array_filter(array_map(static fn ($item): string => trim((string) $item), $value), static fn ($item): bool => $item !== ''));
    }

    private function options(): array
    {
        $epps = $this->hasTable('epp_registro')
            ? EppRegistro::query()->orderBy('nombre')->get(['id', 'nombre', 'tallas'])
            : collect();

        return [
            'minas' => $this->hasTable('minas')
                ? Mina::query()->activeOperational()->orderBy('nombre')->get(['id', 'nombre'])
                : collect(),
            'cargos' => $this->hasTable('personal')
                ? Personal::query()
                    ->whereNotNull('puesto')
                    ->where('puesto', '<>', '')
                    ->distinct()
                    ->orderBy('puesto')
                    ->limit(250)
                    ->pluck('puesto')
                : collect(),
            'paradas' => $this->paradaOptions(),
            'estados' => collect([
                'ENTREGADO' => 'Entregado',
                'PENDIENTE' => 'Pendiente',
                'VENCIDO' => 'Vencido',
                'POR_VENCER' => 'Por vencer',
                'NO_APLICA' => 'No aplica',
            ]),
            'fichas' => collect([
                '' => 'Todas',
                'completa' => 'Completa',
                'incompleta' => 'Incompleta',
            ]),
            'talla_estados' => collect([
                '' => 'Todas',
                'con_talla' => 'Con talla',
                'sin_talla' => 'Sin talla',
            ]),
            'epps' => $epps,
            'tallas' => $this->tallaOptions($epps),
        ];
    }

    private function paradaOptions(): Collection
    {
        if (! $this->hasTable('rq_mina')) {
            return collect();
        }

        $today = now()->startOfDay();

        return RQMina::query()
            ->with(['mina' => fn ($mine) => $mine->activeOperational()->select('id', 'nombre')])
            ->whereHas('mina', fn ($mine) => $mine->activeOperational())
            ->latest('fecha_inicio')
            ->limit(150)
            ->get(['id', 'mina_id', 'area', 'fecha_inicio', 'fecha_fin'])
            ->map(function (RQMina $rqMina) use ($today): array {
                $mina = $rqMina->mina?->nombre ?: 'Sin mina';
                $area = $rqMina->area ?: 'Sin area';
                $inicioDate = $rqMina->fecha_inicio;
                $finDate = $rqMina->fecha_fin;
                $inicioLabel = $inicioDate?->format('d/m/Y') ?: 'sin inicio';
                $finLabel = $finDate?->format('d/m/Y') ?: 'sin fin';

                $diasParaInicio = $inicioDate ? (int) $today->diffInDays($inicioDate, false) : null;
                $diasParaFin = $finDate ? (int) $today->diffInDays($finDate, false) : null;

                if ($inicioDate && $inicioDate->lte($today) && $finDate && $finDate->gte($today)) {
                    $estado = 'EN_CURSO';
                    $estadoLabel = 'En curso';
                    $tiempoTexto = 'Día ' . ((int) $today->diffInDays($inicioDate) + 1) . ' de ' . ((int) $inicioDate->diffInDays($finDate) + 1);
                } elseif ($diasParaInicio !== null && $diasParaInicio < 0) {
                    $estado = 'FINALIZADA';
                    $estadoLabel = 'Finalizada';
                    $diasTranscurridos = abs($diasParaFin ?? abs($diasParaInicio));
                    $tiempoTexto = 'Terminó hace ' . $diasTranscurridos . ' día' . ($diasTranscurridos !== 1 ? 's' : '');
                } elseif ($diasParaInicio !== null && $diasParaInicio <= 7) {
                    $estado = 'POR_INICIAR';
                    $estadoLabel = 'Muy próxima';
                    $tiempoTexto = 'Comienza en ' . $diasParaInicio . ' día' . ($diasParaInicio !== 1 ? 's' : '');
                } elseif ($diasParaInicio !== null) {
                    $estado = 'PROXIMA';
                    $estadoLabel = 'Próxima';
                    $tiempoTexto = 'Comienza en ' . $diasParaInicio . ' día' . ($diasParaInicio !== 1 ? 's' : '');
                } else {
                    $estado = 'SIN_FECHA';
                    $estadoLabel = 'Sin fecha';
                    $tiempoTexto = '';
                }

                return [
                    'id' => $rqMina->id,
                    'mina_id' => $rqMina->mina_id,
                    'mina_nombre' => $mina,
                    'area' => $rqMina->area ?: '',
                    'fecha_inicio' => $inicioDate?->toDateString() ?: '',
                    'fecha_fin' => $finDate?->toDateString() ?: '',
                    'fecha_inicio_label' => $inicioLabel,
                    'fecha_fin_label' => $finLabel,
                    'estado' => $estado,
                    'estado_label' => $estadoLabel,
                    'tiempo_texto' => $tiempoTexto,
                    'dias_para_inicio' => $diasParaInicio,
                    'label' => "{$mina} - {$area} ({$inicioLabel} al {$finLabel})",
                ];
            })
            ->sort(function (array $a, array $b): int {
                $order = ['EN_CURSO' => 0, 'POR_INICIAR' => 1, 'PROXIMA' => 2, 'FINALIZADA' => 3, 'SIN_FECHA' => 4];
                $aOrder = $order[$a['estado']] ?? 99;
                $bOrder = $order[$b['estado']] ?? 99;

                if ($aOrder !== $bOrder) {
                    return $aOrder <=> $bOrder;
                }

                return ($a['dias_para_inicio'] ?? 999) <=> ($b['dias_para_inicio'] ?? 999);
            })
            ->values();
    }

    private function tallaOptions(Collection $epps): Collection
    {
        $base = collect(['XS', 'S', 'M', 'L', 'XL', 'XXL', '28', '30', '32', '34', '36', '38', '40', '42', '43', '44']);
        $fromCatalog = $epps
            ->flatMap(fn (EppRegistro $epp): array => is_array($epp->tallas) ? $epp->tallas : [])
            ->map(fn ($talla): string => $this->normalizeText($talla))
            ->filter();

        return $base->merge($fromCatalog)->unique()->sort()->values();
    }

    private function workers(array $filters): Collection
    {
        if (! $this->hasTable('personal')) {
            return collect();
        }

        $query = Personal::query()
            ->select(['id', 'nombre_completo', 'dni', 'numero_documento', 'puesto', 'estado', 'contrato'])
            ->with([
                'relacionesMina' => fn ($relation) => $relation
                    ->where(function ($query): void {
                        $query->where('activo', true)->orWhereNull('activo');
                    })
                    ->whereHas('mina', fn ($mine) => $mine->activeOperational())
                    ->with(['mina' => fn ($mine) => $mine->activeOperational()->select('id', 'nombre')]),
                'fichaColaborador' => fn ($query) => $query->select([
                    'personal_fichas.id',
                    'personal_fichas.personal_id',
                    'personal_fichas.datos_json',
                ]),
            ]);

        if ($filters['q'] !== '') {
            $q = $filters['q'];
            $query->where(function ($where) use ($q): void {
                $where->where('nombre_completo', 'like', "%{$q}%")
                    ->orWhere('dni', 'like', "%{$q}%")
                    ->orWhere('numero_documento', 'like', "%{$q}%")
                    ->orWhere('puesto', 'like', "%{$q}%");
            });
        }

        if ($filters['cargos'] !== []) {
            $query->whereIn('puesto', $filters['cargos']);
        }

        if ($filters['minas'] !== [] && $this->hasTable('personal_mina')) {
            $query->whereHas('relacionesMina', function ($relation) use ($filters): void {
                $relation->whereIn('mina_id', $filters['minas'])
                    ->where(function ($where): void {
                        $where->where('activo', true)->orWhereNull('activo');
                    })
                    ->whereHas('mina', fn ($mine) => $mine->activeOperational());
            });
        }

        if ($filters['estados'] !== []) {
            $laborStates = array_values(array_intersect($filters['estados'], ['ACTIVO', 'FALTA_CONTRATO', 'CESADO', 'INACTIVO', 'PENDIENTE_COMPLETAR_FICHA']));
            $mineStates = array_values(array_intersect($filters['estados'], [
                PersonalMina::ESTADO_HABILITADO,
                PersonalMina::ESTADO_EN_PROCESO,
                PersonalMina::ESTADO_NO_HABILITADO,
                PersonalMina::ESTADO_OBSERVADO,
            ]));

            $query->where(function ($where) use ($laborStates, $mineStates): void {
                if ($laborStates !== []) {
                    $where->whereIn('estado', $laborStates);
                }

                if ($mineStates !== [] && $this->hasTable('personal_mina')) {
                    $method = $laborStates !== [] ? 'orWhereHas' : 'whereHas';
                    $where->{$method}('relacionesMina', function ($relation) use ($mineStates): void {
                        $relation->whereIn('estado_habilitacion', $mineStates)
                            ->where(function ($active): void {
                                $active->where('activo', true)->orWhereNull('activo');
                            })
                            ->whereHas('mina', fn ($mine) => $mine->activeOperational());
                    });
                }
            });
        }

        $this->applyReachableWorkerScope($query);

        $paradaPersonalIds = $this->paradaPersonalIds($filters['parada_id']);
        if ($filters['parada_id'] !== '') {
            $query->whereIn('id', $paradaPersonalIds);
        }

        $workers = $query->orderBy('nombre_completo')->limit(2500)->get();

        if ($filters['tallas'] !== []) {
            $workers = $workers->filter(function (Personal $personal) use ($filters): bool {
                return collect($this->workerSizes($personal))
                    ->map(fn ($value): string => $this->normalizeText($value))
                    ->intersect($filters['tallas'])
                    ->isNotEmpty();
            })->values();
        }

        return $workers;
    }

    private function applyReachableWorkerScope($query): void
    {
        $query->where(function ($scope): void {
            $scope->where('estado', 'ACTIVO');

            if (! $this->hasTable('personal_mina')) {
                return;
            }

            $scope->orWhereHas('relacionesMina', function ($relation): void {
                $relation
                    ->where(function ($active): void {
                        $active->where('activo', true)->orWhereNull('activo');
                    })
                    ->whereHas('mina', fn ($mine) => $mine->activeOperational())
                    ->where(function ($state): void {
                        $state->where('estado_habilitacion', PersonalMina::ESTADO_HABILITADO)
                            ->orWhere(function ($legacy): void {
                                $legacy
                                    ->whereNull('estado_habilitacion')
                                    ->where('estado', PersonalMina::ESTADO_HABILITADO);
                            });
                    });
            });
        });
    }

    private function paradaPersonalIds(string $rqMinaId): array
    {
        if ($rqMinaId === '' || ! $this->hasTable('rq_proserge_detalle') || ! $this->hasTable('rq_proserge')) {
            return [];
        }

        return RQProsergeDetalle::query()
            ->whereHas('rqProserge', fn ($query) => $query->where('rq_mina_id', $rqMinaId))
            ->whereNotNull('personal_id')
            ->pluck('personal_id')
            ->unique()
            ->values()
            ->all();
    }

    private function eppCatalog(array $filters): Collection
    {
        if (! $this->hasTable('epp_registro')) {
            return collect();
        }

        $query = EppRegistro::query()
            ->where('estado', EppRegistro::ESTADO_ACTIVO)
            ->orderBy('nombre');

        if ($filters['epps'] !== []) {
            $query->whereIn('id', $filters['epps']);
        }

        return $query->get();
    }

    private function deliveries(Collection $workerIds, Collection $eppIds, array $filters): Collection
    {
        if (! $this->hasTable('epp_entregas') || $workerIds->isEmpty()) {
            return collect();
        }

        $query = EppEntrega::query()
            ->with([
                'personal:id,nombre_completo,dni,numero_documento,puesto',
                'personal.fichaColaborador' => fn ($query) => $query->select([
                    'personal_fichas.id',
                    'personal_fichas.personal_id',
                    'personal_fichas.datos_json',
                ]),
                'personal.relacionesMina' => fn ($relation) => $relation
                    ->where(function ($active): void {
                        $active->where('activo', true)->orWhereNull('activo');
                    })
                    ->whereHas('mina', fn ($mine) => $mine->activeOperational())
                    ->with(['mina' => fn ($mine) => $mine->activeOperational()->select('id', 'nombre')]),
                'epp:id,codigo,nombre,vida_util_dias,estado,precio_unitario,precio_alquiler,proveedor,stock,requiere_talla,tallas',
                'registradoPor:id,email',
            ])
            ->latest('fecha_entrega')
            ->limit(1500);

        $query->whereIn('personal_id', $workerIds->values()->all());

        if ($eppIds->isNotEmpty()) {
            $query->whereIn('epp_id', $eppIds->values()->all());
        }

        if ($filters['fecha_desde'] !== '') {
            try {
                $query->whereDate('fecha_entrega', '>=', Carbon::parse($filters['fecha_desde'])->toDateString());
            } catch (\Throwable) {
                // Invalid UI dates are ignored to keep the dashboard available.
            }
        }

        if ($filters['fecha_hasta'] !== '') {
            try {
                $query->whereDate('fecha_entrega', '<=', Carbon::parse($filters['fecha_hasta'])->toDateString());
            } catch (\Throwable) {
                // Invalid UI dates are ignored to keep the dashboard available.
            }
        }

        return $query->get();
    }

    private function mineSummary(Collection $workers): Collection
    {
        return $workers
            ->flatMap(function (Personal $personal): array {
                return $this->activeOperationalMineRelations($personal)->map(function ($relation): array {
                    return [
                        'mina' => $relation->mina?->nombre ?: 'Sin mina',
                        'estado' => $relation->estado_habilitacion ?: $relation->estado ?: 'SIN_ESTADO',
                    ];
                })->all();
            })
            ->groupBy('mina')
            ->map(fn (Collection $items, string $mina): array => [
                'label' => $mina,
                'total' => $items->count(),
                'habilitados' => $items->where('estado', PersonalMina::ESTADO_HABILITADO)->count(),
            ])
            ->sortByDesc('total')
            ->values();
    }

    private function cargoSummary(Collection $workers): Collection
    {
        return $workers
            ->groupBy(fn (Personal $personal): string => $personal->puesto ?: 'Por definir')
            ->map(fn (Collection $items, string $cargo): array => [
                'label' => $cargo,
                'total' => $items->count(),
            ])
            ->sortByDesc('total')
            ->take(12)
            ->values();
    }

    private function habilitatedWorkersCount(Collection $workers, array $filters): int
    {
        $mineIds = collect($filters['minas'] ?? [])
            ->map(static fn ($mineId): string => (string) $mineId)
            ->filter()
            ->values();

        return $workers
            ->filter(function (Personal $personal) use ($mineIds): bool {
                $relations = $this->activeOperationalMineRelations($personal);

                return $relations->contains(function ($relation) use ($mineIds): bool {
                    $state = $relation->estado_habilitacion ?: $relation->estado;

                    if ($state !== PersonalMina::ESTADO_HABILITADO) {
                        return false;
                    }

                    if ($mineIds->isEmpty()) {
                        return true;
                    }

                    return $mineIds->contains((string) $relation->mina_id);
                });
            })
            ->count();
    }

    private function sizeSummary(Collection $workers): Collection
    {
        $rows = collect();

        foreach ($workers as $worker) {
            foreach ($this->workerSizeStatuses($worker) as $status) {
                $tipo = $status['label'];
                $talla = $status['missing'] ? 'Sin talla' : $status['value'];
                $key = $tipo . '|' . $talla;
                $current = $rows->get($key, [
                    'tipo' => $tipo,
                    'talla' => $talla,
                    'total' => 0,
                ]);
                $current['total']++;
                $rows->put($key, $current);
            }
        }

        return $rows
            ->sortBy([['tipo', 'asc'], ['total', 'desc']])
            ->values()
            ->groupBy('tipo')
            ->map(static function (Collection $sizes, string $tipo): array {
                return [
                    'tipo' => $tipo,
                    'tallas' => $sizes
                        ->map(static fn (array $size): array => [
                            'talla' => $size['talla'] ?? '',
                            'total' => (int) ($size['total'] ?? 0),
                            'tone' => ($size['talla'] ?? '') === 'Sin talla' ? 'danger' : 'ok',
                        ])
                        ->values(),
                ];
            })
            ->values();
    }

    private function workerSizes(Personal $personal): array
    {
        $data = $personal->fichaColaborador?->datos_json ?? [];

        return collect(self::SIZE_FIELDS)
            ->mapWithKeys(fn (array $config, string $key): array => [
                $config['label'] => $this->firstData($data, $config['keys']),
            ])
            ->all();
    }

    private function firstData(array $data, array $keys): string
    {
        foreach ($keys as $key) {
            $value = $data[$key] ?? null;
            if ($value !== null && trim((string) $value) !== '') {
                return $this->normalizeText($value);
            }
        }

        return '';
    }

    private function filterWorkersByProfile(Collection $workers, array $filters): Collection
    {
        if ($filters['ficha'] === '' && $filters['talla_estado'] === '') {
            return $workers->values();
        }

        return $workers
            ->filter(function (Personal $worker) use ($filters): bool {
                $incomplete = $this->workerHasMissingSizes($worker);

                if ($filters['ficha'] === 'completa' && $incomplete) {
                    return false;
                }

                if ($filters['ficha'] === 'incompleta' && ! $incomplete) {
                    return false;
                }

                if ($filters['talla_estado'] === 'con_talla' && $incomplete) {
                    return false;
                }

                if ($filters['talla_estado'] === 'sin_talla' && ! $incomplete) {
                    return false;
                }

                return true;
            })
            ->values();
    }

    private function workerSizeStatuses(Personal $worker): array
    {
        $data = $worker->fichaColaborador?->datos_json ?? [];

        return collect(self::SIZE_FIELDS)
            ->map(function (array $config, string $key) use ($data): array {
                $value = $this->firstData($data, $config['keys']);

                return [
                    'key' => $key,
                    'label' => $config['label'],
                    'value' => $value,
                    'missing' => $this->isMissingSizeValue($value, $config['valid']),
                ];
            })
            ->values()
            ->all();
    }

    private function workerHasMissingSizes(Personal $worker): bool
    {
        return collect($this->workerSizeStatuses($worker))->contains('missing', true);
    }

    private function isMissingSizeValue(mixed $value, array $valid): bool
    {
        $normalized = $this->normalizeText($value);
        $normalized = str_replace(['.', '-'], '', $normalized);

        if ($normalized === '' || $normalized === '0') {
            return true;
        }

        $invalidTexts = ['NA', 'N/A', 'NO APLICA', 'NO REGISTRA', 'SIN TALLA', 'SINTALLA', 'NINGUNA'];
        if (in_array($normalized, $invalidTexts, true)) {
            return true;
        }

        return ! in_array($normalized, $valid, true);
    }

    private function missingSizeWorkers(Collection $workers): Collection
    {
        return $workers
            ->map(function (Personal $worker): ?array {
                $statuses = collect($this->workerSizeStatuses($worker))->keyBy('key');
                $missing = $statuses->filter(fn (array $status): bool => (bool) $status['missing']);

                if ($missing->isEmpty()) {
                    return null;
                }

                return [
                    'personal_id' => $worker->id,
                    'trabajador' => $worker->nombre_completo ?: 'Sin nombre',
                    'documento' => $worker->dni ?: $worker->numero_documento ?: '-',
                    'mina' => $this->workerMines($worker),
                    'cargo' => $worker->puesto ?: 'Por definir',
                    'zapatos' => (bool) data_get($statuses, 'zapatos.missing', false),
                    'camisa' => (bool) data_get($statuses, 'camisa.missing', false),
                    'pantalon' => (bool) data_get($statuses, 'pantalon.missing', false),
                    'respirador' => (bool) data_get($statuses, 'respirador.missing', false),
                    'estado_ficha' => 'Incompleta',
                ];
            })
            ->filter()
            ->values();
    }

    private function workerMines(Personal $worker): string
    {
        $relations = $this->activeOperationalMineRelations($worker);

        return $relations
            ->pluck('mina.nombre')
            ->filter()
            ->unique()
            ->values()
            ->implode(', ') ?: 'Sin mina';
    }

    private function workerPrimaryMine(Personal $worker): string
    {
        $mine = $this->workerPrimaryOperationalMine($worker);

        return $mine !== '' ? $mine : 'Sin mina';
    }

    private function workerMineIds(Personal $worker): array
    {
        $relations = $this->activeOperationalMineRelations($worker);

        return $relations
            ->pluck('mina_id')
            ->filter()
            ->map(static fn ($id): string => (string) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function activeOperationalMineRelations(Personal $worker): Collection
    {
        $relations = $worker->relationLoaded('relacionesMina') ? $worker->relacionesMina : collect();

        return $relations
            ->filter(fn ($relation): bool => $relation->mina !== null && $this->isOperationalMineName($relation->mina?->nombre))
            ->values();
    }

    private function workerPrimaryOperationalMine(Personal $worker): string
    {
        return (string) ($this->activeOperationalMineRelations($worker)->first()?->mina?->nombre ?: '');
    }

    private function isOperationalMineName(?string $name): bool
    {
        $normalized = $this->normalizeText($name);

        return $normalized !== '' && ! in_array($normalized, ['SIN MINA', 'SIN_MINA', 'NO APLICA', 'N/A'], true);
    }

    private function classifyDeliveryState(?EppEntrega $delivery): string
    {
        if (! $delivery) {
            return 'PENDIENTE';
        }

        if (! $delivery->fecha_vencimiento_calendario) {
            return 'ENTREGADO';
        }

        $days = now()->startOfDay()->diffInDays($delivery->fecha_vencimiento_calendario, false);

        if ($days < 0) {
            return 'VENCIDO';
        }

        if ($days <= 30) {
            return 'POR_VENCER';
        }

        return 'ENTREGADO';
    }

    private function stateLabel(string $state): string
    {
        return match ($state) {
            'ENTREGADO' => 'Entregado',
            'PENDIENTE' => 'Pendiente',
            'VENCIDO' => 'Vencido',
            'POR_VENCER' => 'Por vencer',
            'NO_APLICA' => 'No aplica',
            'SIN_STOCK' => 'Sin stock',
            default => Str::headline(Str::lower($state)),
        };
    }

    private function workerEppRows(Collection $workers, Collection $trackedCatalog, Collection $activeDeliveries, array $filters): Collection
    {
        $rows = $workers->map(function (Personal $worker) use ($trackedCatalog, $activeDeliveries): ?array {
            $primaryMine = $this->workerPrimaryOperationalMine($worker);

            if ($primaryMine === '') {
                return null;
            }

            $deliveries = $activeDeliveries->where('personal_id', $worker->id);
            $items = [];
            $pendingCost = 0.0;
            $nextExpiration = null;
            $overall = 'Entregado';

            foreach ($trackedCatalog as $item) {
                $ids = collect($item['ids'] ?? []);
                $delivery = $deliveries
                    ->filter(fn (EppEntrega $row): bool => $ids->contains($row->epp_id))
                    ->sortByDesc('fecha_entrega')
                    ->first();
                $state = $this->classifyDeliveryState($delivery);
                $stock = (int) ($item['stock'] ?? 0);

                if ($state === 'PENDIENTE' && $stock <= 0) {
                    $state = 'SIN_STOCK';
                }

                if (in_array($state, ['PENDIENTE', 'SIN_STOCK'], true)) {
                    $pendingCost += (float) ($item['precio_unitario'] ?? 0);
                }

                if ($delivery?->fecha_vencimiento_calendario) {
                    $expiration = $delivery->fecha_vencimiento_calendario->format('d/m/Y');
                    $nextExpiration = $nextExpiration === null ? $expiration : $nextExpiration;
                }

                if (in_array($state, ['VENCIDO', 'SIN_STOCK'], true)) {
                    $overall = 'Critico';
                } elseif ($overall !== 'Critico' && in_array($state, ['PENDIENTE', 'POR_VENCER'], true)) {
                    $overall = 'Pendiente';
                }

                $items[$item['key']] = [
                    'estado' => $state,
                    'label' => $this->stateLabel($state),
                    'tone' => $this->stateTone($state),
                ];
            }

            $sizesIncomplete = $this->workerHasMissingSizes($worker);

            return [
                'personal_id' => $worker->id,
                'trabajador' => $worker->nombre_completo ?: 'Sin nombre',
                'documento' => $worker->dni ?: $worker->numero_documento ?: '-',
                'mina' => $primaryMine,
                'minas' => $this->workerMines($worker),
                'cargo' => $worker->puesto ?: 'Por definir',
                'items' => $items,
                'estado_epp' => $overall,
                'estado_ficha' => $sizesIncomplete ? 'Incompleta' : 'Completa',
                'proximo_vencimiento' => $nextExpiration ?: '-',
                'costo_pendiente' => round($pendingCost, 2),
            ];
        });

        if ($filters['epp_estado'] === []) {
            return $rows->filter()->values();
        }

        return $rows
            ->filter()
            ->filter(function (array $row) use ($filters): bool {
                $states = collect($row['items'] ?? [])->pluck('estado')->all();

                return collect($filters['epp_estado'])->contains(fn (string $state): bool => in_array($state, $states, true));
            })
            ->values();
    }

    private function coverageByItem(Collection $trackedCatalog, Collection $workers, Collection $activeDeliveries): Collection
    {
        $totalWorkers = $workers->count();

        return $trackedCatalog
            ->map(function (array $item) use ($activeDeliveries, $totalWorkers): array {
                $ids = collect($item['ids'] ?? []);
                $states = [
                    'entregado' => 0,
                    'pendiente' => 0,
                    'vencido' => 0,
                    'por_vencer' => 0,
                    'no_aplica' => 0,
                ];

                $deliveredByWorker = $activeDeliveries
                    ->filter(fn (EppEntrega $delivery): bool => $ids->contains($delivery->epp_id))
                    ->groupBy('personal_id')
                    ->map(function (Collection $rows): string {
                        return $this->classifyDeliveryState($rows->sortByDesc('fecha_entrega')->first());
                    });

                foreach ($deliveredByWorker as $state) {
                    if ($state === 'VENCIDO') {
                        $states['vencido']++;
                    } elseif ($state === 'POR_VENCER') {
                        $states['por_vencer']++;
                    } else {
                        $states['entregado']++;
                    }
                }

                $states['pendiente'] = max(0, $totalWorkers - array_sum($states));
                $covered = $states['entregado'] + $states['por_vencer'] + $states['vencido'];

                return [
                    'key' => $item['key'],
                    'nombre' => $item['label'],
                    'requerido' => $totalWorkers,
                    'entregado' => $covered,
                    'pendiente' => $states['pendiente'],
                    'vencido' => $states['vencido'],
                    'por_vencer' => $states['por_vencer'],
                    'no_aplica' => $states['no_aplica'],
                    'coverage_pct' => $totalWorkers > 0 ? round(($covered / $totalWorkers) * 100, 1) : 0,
                    'segments' => $states,
                ];
            })
            ->values();
    }

    private function missingHeatmap(Collection $workerEppRows): Collection
    {
        $matrix = [];

        foreach ($workerEppRows as $row) {
            $mine = (string) ($row['mina'] ?? 'Sin mina');
            if (! $this->isOperationalMineName($mine)) {
                continue;
            }

            $matrix[$mine] ??= [
                'mina' => $mine,
                'items' => collect(self::EPP_ITEMS)->mapWithKeys(fn (string $label, string $key): array => [$key => 0])->all(),
                'total' => 0,
            ];

            foreach (($row['items'] ?? []) as $key => $item) {
                if (in_array($item['estado'] ?? '', ['PENDIENTE', 'SIN_STOCK', 'VENCIDO'], true)) {
                    $matrix[$mine]['items'][$key] = ($matrix[$mine]['items'][$key] ?? 0) + 1;
                    $matrix[$mine]['total']++;
                }
            }
        }

        $max = max(1, collect($matrix)->flatMap(fn (array $row): array => array_values($row['items']))->max() ?: 1);

        return collect($matrix)
            ->map(function (array $row) use ($max): array {
                $row['cells'] = collect($row['items'])
                    ->map(function (int $value, string $key) use ($max): array {
                        $ratio = $value / $max;

                        return [
                            'key' => $key,
                            'label' => self::EPP_ITEMS[$key] ?? $key,
                            'value' => $value,
                            'tone' => $value === 0 ? 'ok' : ($ratio >= .66 ? 'danger' : ($ratio >= .33 ? 'warning' : 'low')),
                        ];
                    })
                    ->values();

                return $row;
            })
            ->sortByDesc('total')
            ->values();
    }

    private function stockRows(Collection $requirements, Collection $workers): Collection
    {
        return $requirements
            ->flatMap(function (array $row) use ($workers): array {
                $sizes = collect($row['tallas'] ?? []);

                if ($sizes->isEmpty()) {
                    return [[
                        'item' => $row['nombre'],
                        'talla' => 'No aplica',
                        'requerido' => (int) $row['requerido'],
                        'stock' => (int) $row['stock'],
                        'pendiente_compra' => (int) $row['pendiente_compra'],
                        'estado' => $this->stockState((int) $row['stock'], (int) $row['pendiente_entrega']),
                    ]];
                }

                return $sizes->map(function (array $size) use ($row, $workers): array {
                    $required = (int) ($size['total'] ?? 0);
                    $stock = (int) $row['stock'];
                    $pending = max(0, $required - $stock);

                    return [
                        'item' => $row['nombre'],
                        'talla' => $size['talla'] ?: 'Sin talla',
                        'requerido' => $required,
                        'stock' => $stock,
                        'pendiente_compra' => $pending,
                        'estado' => $this->stockState($stock, $pending),
                    ];
                })->all();
            })
            ->sortByDesc('pendiente_compra')
            ->values();
    }

    private function stockState(int $stock, int $pending): string
    {
        if ($pending <= 0) {
            return 'OK';
        }

        if ($stock <= 0) {
            return 'Sin stock';
        }

        if ($stock < $pending) {
            return 'Critico';
        }

        return 'Bajo';
    }

    private function stateTone(string $state): string
    {
        return match ($state) {
            'ENTREGADO', 'OK' => 'ok',
            'POR_VENCER', 'PENDIENTE', 'Bajo' => 'warning',
            'VENCIDO', 'SIN_STOCK', 'Critico', 'Sin stock' => 'danger',
            'NO_APLICA' => 'muted',
            default => 'info',
        };
    }

    private function urgentActions(Collection $missingSizeWorkers, Collection $requirements, Collection $expiringDeliveries, Collection $heatmap, Collection $stockRows): Collection
    {
        $actions = collect();

        if ($missingSizeWorkers->isNotEmpty()) {
            $actions->push('Completar tallas de ' . number_format($missingSizeWorkers->count()) . ' trabajadores.');
        }

        $pendingItems = $requirements->where('pendiente_entrega', '>', 0)->sortByDesc('pendiente_entrega')->take(3);
        foreach ($pendingItems as $item) {
            $actions->push('Regularizar entrega de ' . number_format((int) $item['pendiente_entrega']) . ' ' . Str::lower($item['nombre']) . '.');
        }

        $expired = $expiringDeliveries->where('estado_visual', 'VENCIDO')->count();
        if ($expired > 0) {
            $actions->push('Revisar ' . number_format($expired) . ' EPP vencidos.');
        }

        $expiring = $expiringDeliveries->where('estado_visual', 'POR_VENCER')->count();
        if ($expiring > 0) {
            $actions->push('Programar cambio de ' . number_format($expiring) . ' EPP proximos a vencer.');
        }

        $criticalStock = $stockRows->whereIn('estado', ['Critico', 'Sin stock'])->first();
        if ($criticalStock) {
            $actions->push('Revisar stock critico de ' . Str::lower($criticalStock['item']) . ' talla ' . $criticalStock['talla'] . '.');
        }

        $topMine = $heatmap->first();
        if ($topMine && (int) ($topMine['total'] ?? 0) > 0) {
            $actions->push('Atender faltantes de ' . $topMine['mina'] . ' (' . number_format((int) $topMine['total']) . ' pendientes).');
        }

        return $actions->take(8)->values();
    }

    private function requirements(Collection $workers, Collection $catalog, Collection $activeDeliveries): Collection
    {
        $workersCount = $workers->count();

        return $catalog->map(function (array $item) use ($workers, $workersCount, $activeDeliveries): array {
            $ids = collect($item['ids'] ?? []);
            $covered = $activeDeliveries
                ->filter(fn (EppEntrega $delivery): bool => $ids->contains($delivery->epp_id))
                ->pluck('personal_id')
                ->unique()
                ->count();
            $stock = (int) ($item['stock'] ?? 0);
            $required = $workersCount;
            $pending = max(0, $required - $covered);
            $pendingPurchase = max(0, $pending - $stock);

            return [
                'id' => $ids->first(),
                'key' => $item['key'],
                'nombre' => $item['label'],
                'vida_util_dias' => data_get($item, 'catalog.vida_util_dias', 0),
                'requerido' => $required,
                'entregado' => $covered,
                'pendiente_entrega' => $pending,
                'stock' => $stock,
                'pendiente_compra' => $pendingPurchase,
                'stock_estado' => $this->stockState($stock, $pending),
                'precio_unitario' => (float) ($item['precio_unitario'] ?? 0),
                'costo_pendiente' => $pending * (float) ($item['precio_unitario'] ?? 0),
                'proveedor' => $item['proveedor'] ?? 'Sin proveedor',
                'tallas' => $this->sizesForEpp($workers, $item),
            ];
        })->values();
    }

    private function sizesForEpp(Collection $workers, array $item): Collection
    {
        $type = $this->eppSizeType((string) ($item['label'] ?? ''));

        if ($type === '') {
            return collect();
        }

        return $workers
            ->map(fn (Personal $personal): string => $this->workerSizes($personal)[$type] ?? '')
            ->filter()
            ->countBy()
            ->sortDesc()
            ->map(fn (int $total, string $talla): array => ['talla' => $talla, 'total' => $total])
            ->values();
    }

    private function eppSizeType(?string $name): string
    {
        $name = Str::upper((string) $name);

        return match (true) {
            str_contains($name, 'ZAP') || str_contains($name, 'BOTA') => 'Zapatos',
            str_contains($name, 'PANT') => 'Pantalon',
            str_contains($name, 'CAMISA') || str_contains($name, 'POLO') || str_contains($name, 'CASACA') => 'Camisa',
            str_contains($name, 'CHALECO') => 'Camisa',
            str_contains($name, 'RESP') => 'Respirador',
            default => '',
        };
    }

    private function eppItemKey(?string $name): string
    {
        $name = Str::upper((string) $name);

        return match (true) {
            str_contains($name, 'CASCO') => 'casco',
            str_contains($name, 'CHALECO') => 'chaleco',
            str_contains($name, 'ZAP') || str_contains($name, 'BOTA') => 'zapatos',
            str_contains($name, 'CAMISA') || str_contains($name, 'POLO') || str_contains($name, 'CASACA') => 'camisa',
            str_contains($name, 'PANT') => 'pantalon',
            str_contains($name, 'RESP') => 'respirador',
            default => Str::slug((string) $name, '_'),
        };
    }

    private function trackedCatalog(Collection $catalog): Collection
    {
        return collect(self::EPP_ITEMS)
            ->map(function (string $label, string $key) use ($catalog): array {
                $matches = $catalog
                    ->filter(fn (EppRegistro $epp): bool => $this->eppItemKey($epp->nombre) === $key)
                    ->values();
                $primary = $matches->first();

                return [
                    'key' => $key,
                    'label' => $label,
                    'ids' => $matches->pluck('id')->values(),
                    'catalog' => $primary,
                    'nombre' => $primary?->nombre ?: $label,
                    'stock' => (int) ($primary?->stock ?? 0),
                    'precio_unitario' => (float) ($primary?->precio_unitario ?? 0),
                    'proveedor' => $primary?->proveedor ?: 'Sin proveedor',
                    'requiere_talla' => in_array($key, ['zapatos', 'camisa', 'pantalon', 'respirador'], true),
                ];
            })
            ->values();
    }

    private function missingWorkers(Collection $workers, Collection $catalog, Collection $activeDeliveries): Collection
    {
        return $workers->map(function (Personal $worker) use ($catalog, $activeDeliveries): ?array {
            $delivered = $activeDeliveries
                ->where('personal_id', $worker->id)
                ->pluck('epp_id')
                ->unique();
            $missing = $catalog
                ->reject(fn (array $item): bool => collect($item['ids'] ?? [])->intersect($delivered)->isNotEmpty())
                ->pluck('label')
                ->take(5)
                ->values();

            if ($missing->isEmpty()) {
                return null;
            }

            return [
                'trabajador' => $worker->nombre_completo,
                'documento' => $worker->dni ?: $worker->numero_documento,
                'puesto' => $worker->puesto ?: 'Por definir',
                'faltantes' => $missing,
            ];
        })->filter()->take(20)->values();
    }

    private function recentDeliveries(Collection $deliveries): Collection
    {
        return $deliveries
            ->sortByDesc('fecha_entrega')
            ->take(20)
            ->map(fn (EppEntrega $delivery): array => $this->deliveryRow($delivery))
            ->values();
    }

    private function ceasedEppRows(array $filters): Collection
    {
        if (! $this->hasTable('personal') || ! $this->hasTable('epp_entregas')) {
            return collect();
        }

        $query = Personal::query()
            ->select(['id', 'nombre_completo', 'dni', 'numero_documento', 'puesto', 'estado', 'fecha_cese', 'cesado_at', 'motivo_cese'])
            ->with([
                'contratosLaborales' => fn ($contracts) => $contracts
                    ->select(['id', 'personal_id', 'contrato_numero', 'estado', 'fecha_inicio', 'fecha_fin', 'motivo_cese', 'fecha_cese_controlado', 'motivo_cese_controlado'])
                    ->whereIn('estado', [PersonalContrato::ESTADO_CESADO, PersonalContrato::ESTADO_NO_RENOVADO])
                    ->orderByDesc('contrato_numero'),
                'relacionesMina' => fn ($relation) => $relation
                    ->where(function ($active): void {
                        $active->where('activo', true)->orWhereNull('activo');
                    })
                    ->whereHas('mina', fn ($mine) => $mine->activeOperational())
                    ->with(['mina' => fn ($mine) => $mine->activeOperational()->select('id', 'nombre')]),
            ])
            ->where(function ($where): void {
                $where->where('estado', 'CESADO')
                    ->orWhereNotNull('fecha_cese')
                    ->orWhereNotNull('cesado_at');

                if ($this->hasTable('personal_contratos')) {
                    $where->orWhereHas('contratosLaborales', function ($contracts): void {
                        $contracts->whereIn('estado', [PersonalContrato::ESTADO_CESADO, PersonalContrato::ESTADO_NO_RENOVADO]);
                    });
                }
            });

        $search = trim((string) ($filters['q'] ?? ''));
        if ($search !== '') {
            $query->where(function ($where) use ($search): void {
                $where->where('nombre_completo', 'like', "%{$search}%")
                    ->orWhere('dni', 'like', "%{$search}%")
                    ->orWhere('numero_documento', 'like', "%{$search}%")
                    ->orWhere('puesto', 'like', "%{$search}%");
            });
        }

        $workers = $query->orderByDesc('fecha_cese')->orderBy('nombre_completo')->limit(250)->get();
        if ($workers->isEmpty()) {
            return collect();
        }

        $deliveries = EppEntrega::query()
            ->with(['epp:id,codigo,nombre', 'registradoPor:id,email'])
            ->whereIn('personal_id', $workers->pluck('id')->all())
            ->orderByDesc('fecha_entrega')
            ->orderByDesc('created_at')
            ->get()
            ->groupBy('personal_id');

        return $workers
            ->map(function (Personal $worker) use ($deliveries): ?array {
                $items = $this->ceasedWorkerEppItems($deliveries->get($worker->id, collect()));
                if ($items->isEmpty()) {
                    return null;
                }

                $contract = $worker->contratosLaborales->sortByDesc('contrato_numero')->first();
                $pending = $items->where('resuelto', false)->count();
                $resolved = $items->where('resuelto', true)->count();
                $fechaCese = $worker->fecha_cese?->format('d/m/Y')
                    ?: ($contract?->fecha_cese_controlado?->format('d/m/Y')
                        ?: ($contract?->fecha_fin?->format('d/m/Y') ?: ($worker->cesado_at?->format('d/m/Y') ?: '-')));

                return [
                    'personal_id' => (string) $worker->id,
                    'trabajador' => (string) ($worker->nombre_completo ?: 'Sin nombre'),
                    'documento' => (string) ($worker->dni ?: $worker->numero_documento ?: '-'),
                    'puesto' => (string) ($worker->puesto ?: 'Por definir'),
                    'mina' => $this->workerPrimaryMine($worker),
                    'estado_laboral' => (string) ($worker->estado ?: 'CESADO'),
                    'contrato_estado' => (string) ($contract?->estado ?: 'CESADO'),
                    'fecha_cese' => $fechaCese,
                    'motivo_cese' => (string) ($worker->motivo_cese ?: $contract?->motivo_cese_controlado ?: $contract?->motivo_cese ?: '-'),
                    'pendientes' => $pending,
                    'resueltos' => $resolved,
                    'total' => $items->count(),
                    'estado_logistico' => $pending > 0 ? 'PENDIENTE' : 'RESUELTO',
                    'items' => $items->values()->all(),
                ];
            })
            ->filter()
            ->sortByDesc('pendientes')
            ->values();
    }

    private function ceasedWorkerEppItems(Collection $deliveries): Collection
    {
        return $deliveries
            ->groupBy(fn (EppEntrega $delivery): string => (string) ($delivery->epp_id ?: $delivery->id))
            ->map(function (Collection $itemDeliveries): array {
                /** @var EppEntrega $delivery */
                $delivery = $itemDeliveries
                    ->sortByDesc(fn (EppEntrega $row): string => ($row->fecha_entrega?->toDateString() ?: '') . ' ' . ($row->created_at?->toDateTimeString() ?: ''))
                    ->first();
                $resuelto = $this->isCeasedEppResolved((string) $delivery->estado);

                return [
                    'epp' => (string) ($delivery->epp?->nombre ?: 'EPP sin catalogo'),
                    'codigo' => (string) ($delivery->epp?->codigo ?: '-'),
                    'estado' => (string) $delivery->estado,
                    'estado_label' => $this->eppDeliveryStateLabel((string) $delivery->estado),
                    'resuelto' => $resuelto,
                    'estado_resolucion' => $resuelto ? 'Resuelto' : 'Pendiente de entrega',
                    'fecha_entrega' => $delivery->fecha_entrega?->format('d/m/Y') ?: '-',
                    'fecha_cierre' => $delivery->devuelto_at?->format('d/m/Y') ?: '-',
                    'cantidad' => (int) ($delivery->cantidad ?: 1),
                    'responsable' => $delivery->registradoPor?->email ?: 'Sistema',
                    'observacion' => (string) ($delivery->observacion ?: $delivery->motivo_cambio ?: ''),
                ];
            })
            ->values()
            ->sortBy([
                ['resuelto', 'asc'],
                ['epp', 'asc'],
            ])
            ->values();
    }

    private function isCeasedEppResolved(string $estado): bool
    {
        return in_array($estado, [
            EppEntrega::ESTADO_DEVUELTO,
            EppEntrega::ESTADO_USO_INCORRECTO,
            EppEntrega::ESTADO_PERDIDA_OLVIDO,
        ], true);
    }

    private function eppDeliveryStateLabel(string $estado): string
    {
        return match ($estado) {
            EppEntrega::ESTADO_CAMBIADO => 'Cambio de EPP',
            EppEntrega::ESTADO_USO_INCORRECTO => 'Uso incorrecto',
            EppEntrega::ESTADO_PERDIDA_OLVIDO => 'Perdida / olvido',
            EppEntrega::ESTADO_DEVUELTO => 'Devuelto por internamiento',
            default => 'Entrega / nuevo',
        };
    }

    private function expiringDeliveries(Collection $activeDeliveries, bool $onlyNextThirtyDays = true): Collection
    {
        $today = now()->startOfDay();

        $rows = $activeDeliveries
            ->filter(fn (EppEntrega $delivery): bool => $delivery->fecha_vencimiento_calendario !== null)
            ->map(function (EppEntrega $delivery) use ($today): array {
                $row = $this->deliveryRow($delivery);
                $effectiveUsage = $this->eppService->presentEntrega($delivery);
                $days = $today->diffInDays($delivery->fecha_vencimiento_calendario, false);
                $row['dias'] = (int) $days;
                $row['estado_visual'] = $days < 0 ? 'VENCIDO' : ($days <= 30 ? 'POR_VENCER' : 'VIGENTE');
                $row['dias_uso_efectivo'] = (int) data_get($effectiveUsage, 'dias_uso_efectivo', 0);
                $row['vida_dias'] = (int) data_get($effectiveUsage, 'vida_dias', 0);
                $row['dias_restantes_uso'] = (int) data_get($effectiveUsage, 'dias_restantes_uso', 0);
                $row['uso_efectivo'] = sprintf(
                    '%d / %d dias',
                    $row['dias_uso_efectivo'],
                    $row['vida_dias']
                );

                return $row;
            });

        if ($onlyNextThirtyDays) {
            $rows = $rows->filter(fn (array $row): bool => $row['dias'] <= 30);
        }

        return $rows
            ->sortBy('dias')
            ->take($onlyNextThirtyDays ? 100 : 300)
            ->values();
    }

    private function filterExpiringDeliveries(Collection $rows, array $filters): Collection
    {
        $search = $this->normalizeText($filters['q'] ?? '');
        $mineId = trim((string) ($filters['mina_id'] ?? ''));
        $eppId = trim((string) ($filters['epp_id'] ?? ''));
        $talla = $this->normalizeText($filters['talla'] ?? '');
        $estado = strtoupper(trim((string) ($filters['estado'] ?? '')));
        $rango = trim((string) ($filters['rango'] ?? '30'));
        $desde = $this->normalizeDate($filters['fecha_desde'] ?? null);
        $hasta = $this->normalizeDate($filters['fecha_hasta'] ?? null);

        return $rows
            ->filter(function (array $row) use ($search): bool {
                if ($search === '') {
                    return true;
                }

                $haystack = $this->normalizeText(implode(' ', [
                    $row['trabajador'] ?? '',
                    $row['documento'] ?? '',
                    $row['epp'] ?? '',
                ]));

                return str_contains($haystack, $search);
            })
            ->filter(fn (array $row): bool => $mineId === '' || in_array($mineId, (array) ($row['mina_ids'] ?? []), true))
            ->filter(fn (array $row): bool => $eppId === '' || (string) ($row['epp_id'] ?? '') === $eppId)
            ->filter(fn (array $row): bool => $talla === '' || $this->normalizeText($row['talla'] ?? '') === $talla)
            ->filter(fn (array $row): bool => $estado === '' || (string) ($row['estado_visual'] ?? '') === $estado)
            ->filter(function (array $row) use ($rango): bool {
                $days = (int) ($row['dias'] ?? 0);

                return match ($rango) {
                    'vencidos' => $days < 0,
                    '7' => $days >= 0 && $days <= 7,
                    '15' => $days >= 0 && $days <= 15,
                    '30' => $days <= 30,
                    default => true,
                };
            })
            ->filter(function (array $row) use ($desde, $hasta): bool {
                $date = $row['fecha_vencimiento_iso'] ?? null;
                if (! $date) {
                    return false;
                }

                if ($desde && $date < $desde) {
                    return false;
                }

                if ($hasta && $date > $hasta) {
                    return false;
                }

                return true;
            })
            ->values();
    }

    private function deliveryRow(EppEntrega $delivery): array
    {
        $mine = $delivery->personal ? $this->workerPrimaryMine($delivery->personal) : 'Sin mina';
        $movement = match ($delivery->estado) {
            EppEntrega::ESTADO_CAMBIADO => 'Cambio',
            EppEntrega::ESTADO_USO_INCORRECTO => 'Uso incorrecto',
            EppEntrega::ESTADO_PERDIDA_OLVIDO => 'Perdida / olvido',
            EppEntrega::ESTADO_DEVUELTO => 'Devuelto por internamiento',
            default => $delivery->motivo_cambio ? 'Renovacion' : 'Entrega',
        };

        return [
            'trabajador' => $delivery->personal?->nombre_completo ?: 'Sin trabajador',
            'personal_id' => (string) ($delivery->personal_id ?? ''),
            'documento' => $delivery->personal?->dni ?: $delivery->personal?->numero_documento ?: '-',
            'mina' => $mine,
            'mina_ids' => $delivery->personal ? $this->workerMineIds($delivery->personal) : [],
            'puesto' => $delivery->personal?->puesto ?: 'Por definir',
            'epp_id' => (string) ($delivery->epp_id ?? ''),
            'epp' => $delivery->epp?->nombre ?: 'Sin EPP',
            'talla' => $this->deliverySize($delivery),
            'color' => (string) ($delivery->color ?: ''),
            'atributos' => $delivery->atributos_json ?: [],
            'cantidad' => (int) ($delivery->cantidad ?? 1),
            'fecha_entrega' => $delivery->fecha_entrega?->format('d/m/Y') ?: '-',
            'fecha_entrega_iso' => $delivery->fecha_entrega?->toDateString(),
            'fecha_vencimiento' => $delivery->fecha_vencimiento_calendario?->format('d/m/Y') ?: '-',
            'fecha_vencimiento_iso' => $delivery->fecha_vencimiento_calendario?->toDateString(),
            'estado' => $delivery->estado,
            'tipo_movimiento' => $movement,
            'responsable' => $delivery->registradoPor?->email ?: 'Sistema',
            'observacion' => $delivery->observacion ?: $delivery->motivo_cambio ?: '-',
        ];
    }

    private function deliverySize(EppEntrega $delivery): string
    {
        if (filled($delivery->talla)) {
            return (string) $delivery->talla;
        }

        if (! $delivery->personal || ! $delivery->epp) {
            return '-';
        }

        $type = $this->eppSizeType($delivery->epp->nombre);
        if ($type === '') {
            return 'No aplica';
        }

        return $this->workerSizes($delivery->personal)[$type] ?? '-';
    }

    private function toolsRows(): Collection
    {
        if (! $this->hasTable('parada_herramienta_listas')) {
            return collect();
        }

        return ParadaHerramientaLista::query()
            ->with(['rqMina.mina:id,nombre', 'grupos.items'])
            ->latest('created_at')
            ->limit(30)
            ->get()
            ->map(function (ParadaHerramientaLista $lista): array {
                $items = $lista->grupos->flatMap->items;

                return [
                    'rq_mina_id' => $lista->rq_mina_id,
                    'parada' => ($lista->rqMina?->mina?->nombre ?: 'Sin mina') . ' - ' . ($lista->rqMina?->area ?: 'Sin area'),
                    'semana' => 'Sem. ' . $lista->semana_iso . ' / ' . $lista->anio_iso,
                    'fecha_limite' => $lista->fecha_limite_envio?->format('d/m/Y') ?: '-',
                    'estado' => $lista->estado ?: 'BORRADOR',
                    'herramientas' => $items->where('categoria', 'HERRAMIENTA')->count(),
                    'consumibles' => $items->where('categoria', 'CONSUMIBLE')->count(),
                    'solicitado' => $items->sum('cantidad_solicitada'),
                    'recibido' => $items->sum('cantidad_recibida'),
                ];
            });
    }

    private function serviceRows(): Collection
    {
        if (! $this->hasTable('rq_mina_actividad_transportes')) {
            return collect();
        }

        return RQMinaActividadTransporte::query()
            ->with(['grupo.rqMina.mina:id,nombre'])
            ->whereHas('grupo.rqMina')
            ->latest('updated_at')
            ->limit(120)
            ->get()
            ->map(function (RQMinaActividadTransporte $transporte): array {
                $grupo = $transporte->grupo;
                $rqMina = $grupo?->rqMina;
                $parada = trim(sprintf(
                    '%s - %s',
                    $rqMina?->mina?->nombre ?: 'Sin mina',
                    $rqMina?->destino_nombre ?: $rqMina?->area ?: 'Sin area'
                ));

                return [
                    'id' => (string) $transporte->id,
                    'rq_mina_id' => (string) ($rqMina?->id ?? ''),
                    'parada' => $parada,
                    'grupo' => $grupo?->nombre ?: $grupo?->area_operativa ?: 'Sin grupo',
                    'alcance' => $transporte->alcance ?: '-',
                    'unidad_carga' => $transporte->unidad_carga ?: '-',
                    'origen' => $transporte->origen ?: '',
                    'origen_label' => $this->transportOriginLabel($transporte->origen),
                    'solicitado' => $transporte->unidades_transporte ?: 'Sin detalle solicitado',
                    'placas_asignadas' => $transporte->placas_asignadas ?: '',
                    'fecha_inicio' => $transporte->fecha_inicio?->toDateString() ?: '',
                    'fecha_inicio_label' => $transporte->fecha_inicio?->format('d/m/Y') ?: '-',
                    'fecha_fin' => $transporte->fecha_fin?->toDateString() ?: '',
                    'fecha_fin_label' => $transporte->fecha_fin?->format('d/m/Y') ?: '-',
                    'dias_uso' => $transporte->dias_uso,
                    'estado' => $transporte->estado_logistico ?: RQMinaActividadTransporte::ESTADO_REQUERIDO,
                    'estado_label' => $this->transportStateLabel($transporte->estado_logistico ?: RQMinaActividadTransporte::ESTADO_REQUERIDO),
                    'indicaciones' => $transporte->indicaciones ?: '',
                    'comentario_cambio' => $transporte->comentario_cambio ?: '',
                    'incidencia_operativa' => $transporte->incidencia_operativa ?: '',
                    'recepcion_fecha' => $transporte->recepcion_fecha?->toDateString() ?: '',
                    'recepcion_fecha_label' => $transporte->recepcion_fecha?->format('d/m/Y') ?: '-',
                    'recepcion_estado' => $transporte->recepcion_estado ?: RQMinaActividadTransporte::RECEPCION_PENDIENTE,
                    'recepcion_estado_label' => $this->receptionStateLabel($transporte->recepcion_estado ?: RQMinaActividadTransporte::RECEPCION_PENDIENTE),
                    'recepcion_observacion' => $transporte->recepcion_observacion ?: '',
                    'origen' => $transporte->origen ?: '',
                    'origen_label' => $this->transportOriginLabel($transporte->origen),
                    'placas_asignadas' => $transporte->placas_asignadas ?: '',
                    'capacidad_camion' => $transporte->capacidad_camion ?: '',
                    'doc_vehiculo_path' => $transporte->doc_vehiculo_path ?: '',
                    'doc_proserge_path' => $transporte->doc_proserge_path ?: '',
                    'doc_mantenimiento_path' => $transporte->doc_mantenimiento_path ?: '',
                    'doc_checklist_path' => $transporte->doc_checklist_path ?: '',
                    'documentos' => $this->hasColumn('rq_mina_actividad_transportes', 'documentos')
                        ? $this->normalizeTransportDocuments($transporte->documentos)
                        : [],
                ];
            });
    }

    public function updateTransportRequirement(string $id, array $payload, Usuario $usuario): RQMinaActividadTransporte
    {
        $transporte = RQMinaActividadTransporte::query()
            ->with('grupo.rqMina')
            ->findOrFail($id);
        $previousState = (string) ($transporte->estado_logistico ?: RQMinaActividadTransporte::ESTADO_REQUERIDO);
        $data = $this->normalizeTransportPayload($payload, $transporte);

        return DB::transaction(function () use ($transporte, $data, $previousState, $usuario): RQMinaActividadTransporte {
            $transporte->forceFill($data)->save();
            $transporte->refresh();

            $rqMinaId = (string) ($transporte->grupo?->rq_mina_id ?: $transporte->grupo?->rqMina?->id ?: '');
            if ($rqMinaId !== '') {
                RQMinaActividadTransporteEvento::query()->create([
                    'id' => (string) Str::uuid(),
                    'rq_mina_id' => $rqMinaId,
                    'transporte_id' => $transporte->id,
                    'tipo' => RQMinaActividadTransporteEvento::TIPO_CAMBIO,
                    'estado_anterior' => $previousState,
                    'estado_nuevo' => (string) $transporte->estado_logistico,
                    'descripcion' => $transporte->comentario_cambio ?: $transporte->incidencia_operativa ?: $transporte->recepcion_observacion,
                    'transporte_snapshot' => $transporte->only([
                        'alcance',
                        'unidad_carga',
                        'origen',
                        'unidades_transporte',
                        'placas_asignadas',
                        'fecha_inicio',
                        'fecha_fin',
                        'dias_uso',
                        'estado_logistico',
                        'indicaciones',
                        'comentario_cambio',
                        'incidencia_operativa',
                        'recepcion_fecha',
                        'recepcion_estado',
                        'recepcion_observacion',
                        'capacidad_camion',
                        'doc_vehiculo_path',
                        'doc_proserge_path',
                        'doc_mantenimiento_path',
                        'doc_checklist_path',
                        'documentos',
                    ]),
                    'fecha_evento' => now(),
                    'usuario_id' => $usuario->id,
                ]);
            }

            return $transporte;
        });
    }

    private function normalizeTransportPayload(array $payload, RQMinaActividadTransporte $transporte): array
    {
        $inicio = $this->normalizeDate($payload['fecha_inicio'] ?? null);
        $fin = $this->normalizeDate($payload['fecha_fin'] ?? null);
        $estado = strtoupper(trim((string) ($payload['estado_logistico'] ?? RQMinaActividadTransporte::ESTADO_REQUERIDO)));
        $recepcion = strtoupper(trim((string) ($payload['recepcion_estado'] ?? RQMinaActividadTransporte::RECEPCION_PENDIENTE)));
        $origen = strtoupper(trim((string) ($payload['origen'] ?? '')));

        if (! in_array($estado, RQMinaActividadTransporte::estadosLogisticos(), true)) {
            $estado = RQMinaActividadTransporte::ESTADO_REQUERIDO;
        }

        if (! in_array($recepcion, RQMinaActividadTransporte::estadosRecepcion(), true)) {
            $recepcion = RQMinaActividadTransporte::RECEPCION_PENDIENTE;
        }

        if ($origen !== '' && ! in_array($origen, RQMinaActividadTransporte::origenes(), true)) {
            $origen = RQMinaActividadTransporte::ORIGEN_OTRO;
        }

        $data = [
            'origen' => $origen ?: null,
            'placas_asignadas' => trim((string) ($payload['placas_asignadas'] ?? '')) ?: null,
            'fecha_inicio' => $inicio,
            'fecha_fin' => $fin,
            'dias_uso' => $this->transportUsageDays($inicio, $fin) ?? $transporte->dias_uso,
            'estado_logistico' => $estado,
            'comentario_cambio' => trim((string) ($payload['comentario_cambio'] ?? '')) ?: null,
            'incidencia_operativa' => trim((string) ($payload['incidencia_operativa'] ?? '')) ?: null,
            'recepcion_fecha' => $this->normalizeDate($payload['recepcion_fecha'] ?? null),
            'recepcion_estado' => $recepcion,
            'recepcion_observacion' => trim((string) ($payload['recepcion_observacion'] ?? '')) ?: null,
            'capacidad_camion' => trim((string) ($payload['capacidad_camion'] ?? '')) ?: null,
            'doc_vehiculo_path' => trim((string) ($payload['doc_vehiculo_path'] ?? '')) ?: $transporte->getOriginal('doc_vehiculo_path'),
            'doc_proserge_path' => trim((string) ($payload['doc_proserge_path'] ?? '')) ?: $transporte->getOriginal('doc_proserge_path'),
            'doc_mantenimiento_path' => trim((string) ($payload['doc_mantenimiento_path'] ?? '')) ?: $transporte->getOriginal('doc_mantenimiento_path'),
            'doc_checklist_path' => trim((string) ($payload['doc_checklist_path'] ?? '')) ?: $transporte->getOriginal('doc_checklist_path'),
        ];

        if ($this->hasColumn('rq_mina_actividad_transportes', 'documentos')) {
            $data['documentos'] = $this->mergeTransportDocuments(
                $this->normalizeTransportDocuments($transporte->documentos),
                $payload['documentos_nuevos'] ?? []
            );
        }

        return $data;
    }

    private function normalizeTransportDocuments(mixed $documents): array
    {
        if (is_string($documents)) {
            $decoded = json_decode($documents, true);
            $documents = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($documents)) {
            return [];
        }

        return collect($documents)
            ->filter(fn ($document): bool => is_array($document) && trim((string) ($document['path'] ?? '')) !== '')
            ->map(fn (array $document): array => [
                'tipo' => trim((string) ($document['tipo'] ?? 'adicional')) ?: 'adicional',
                'nombre' => trim((string) ($document['nombre'] ?? 'Documento')) ?: 'Documento',
                'path' => trim((string) ($document['path'] ?? '')),
                'original_name' => trim((string) ($document['original_name'] ?? '')),
                'uploaded_at' => trim((string) ($document['uploaded_at'] ?? '')),
            ])
            ->values()
            ->all();
    }

    private function mergeTransportDocuments(array $existing, mixed $newDocuments): array
    {
        $next = $existing;
        foreach ($this->normalizeTransportDocuments($newDocuments) as $document) {
            $next[] = $document;
        }

        return collect($next)
            ->unique(fn (array $document): string => $document['path'])
            ->values()
            ->all();
    }

    private function normalizeDate(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function transportUsageDays(?string $inicio, ?string $fin): ?int
    {
        if (! $inicio || ! $fin) {
            return null;
        }

        $start = Carbon::parse($inicio)->startOfDay();
        $end = Carbon::parse($fin)->startOfDay();

        if ($end->lt($start)) {
            return 0;
        }

        return (int) $start->diffInDays($end) + 1;
    }

    private function transportStateLabel(?string $state): string
    {
        return match ($state) {
            RQMinaActividadTransporte::ESTADO_ASIGNADO => 'Asignado',
            RQMinaActividadTransporte::ESTADO_EN_USO => 'En uso',
            RQMinaActividadTransporte::ESTADO_RETIRADO => 'Retirado',
            RQMinaActividadTransporte::ESTADO_REEMPLAZADO => 'Reemplazado',
            RQMinaActividadTransporte::ESTADO_DEVUELTO => 'Devuelto',
            RQMinaActividadTransporte::ESTADO_INCIDENCIA => 'Incidencia',
            default => 'Requerido',
        };
    }

    private function receptionStateLabel(?string $state): string
    {
        return match ($state) {
            RQMinaActividadTransporte::RECEPCION_RECIBIDO => 'Recibido',
            RQMinaActividadTransporte::RECEPCION_INCOMPLETO => 'Incompleto',
            RQMinaActividadTransporte::RECEPCION_NO_LLEGO => 'No llego',
            RQMinaActividadTransporte::RECEPCION_CON_OBSERVACION => 'Con observacion',
            default => 'Pendiente',
        };
    }

    private function transportOriginLabel(?string $origin): string
    {
        return match ($origin) {
            RQMinaActividadTransporte::ORIGEN_EMPRESA => 'Empresa',
            RQMinaActividadTransporte::ORIGEN_ALQUILADO => 'Alquilado',
            RQMinaActividadTransporte::ORIGEN_OTRO => 'Otro',
            default => 'Sin definir',
        };
    }

    private function identityRows(array $filters): Collection
    {
        $categoria = $filters['ident_categoria'] ?? 'EPP';

        $allCatalog = $this->hasTable('epp_registro')
            ? EppRegistro::query()
                ->where('categoria', $categoria)
                ->orderBy('nombre')
                ->get()
            : collect();

        $rows = $allCatalog->toBase()->map(fn (EppRegistro $epp): array => [
            'id' => (string) $epp->id,
            'nombre' => $epp->nombre,
            'codigo' => $epp->codigo ?: Str::slug($epp->nombre, '-'),
            'vida_util' => (int) ($epp->vida_util_dias ?? 0),
            'requiere_talla' => (bool) $epp->requiere_talla,
            'talla_label' => $epp->requiere_talla ? collect($epp->tallas ?: [])->implode(', ') : 'No requiere',
            'tallas' => $epp->tallas ?: [],
            'requiere_color' => (bool) $epp->requiere_color,
            'color_label' => $epp->requiere_color ? collect($epp->colores ?: [])->implode(', ') : 'No requiere',
            'colores' => $epp->colores ?: [],
            'categoria' => $epp->categoria ?: 'EPP',
            'otros_atributos' => $epp->otros_atributos ?: [],
            'estado' => $epp->estado,
            'readonly' => false,
            'fuente' => 'ITEM',
        ]);

        if (! in_array($categoria, ['HERRAMIENTA', 'CONSUMIBLE'], true) || ! $this->hasTable('parada_herramienta_catalogos')) {
            return $rows->values();
        }

        $registeredNames = $rows
            ->pluck('nombre')
            ->map(fn ($name): string => $this->normalizeText($name))
            ->filter()
            ->flip();

        $toolCatalogQuery = ParadaHerramientaCatalogo::query();

        if ($this->hasColumn('parada_herramienta_catalogos', 'categoria')) {
            $toolCatalogQuery->where('categoria', $categoria);
        }

        if ($this->hasColumn('parada_herramienta_catalogos', 'activo')) {
            $toolCatalogQuery->where('activo', true);
        }

        if ($this->hasColumn('parada_herramienta_catalogos', 'descripcion')) {
            $toolCatalogQuery->orderBy('descripcion');
        }

        $toolCatalogRows = $toolCatalogQuery
            ->get()
            ->toBase()
            ->reject(fn (ParadaHerramientaCatalogo $item): bool => $registeredNames->has($this->normalizeText($item->descripcion)))
            ->map(fn (ParadaHerramientaCatalogo $item): array => [
                'id' => 'herramienta-catalogo-' . $item->id,
                'catalogo_id' => (string) $item->id,
                'nombre' => $item->descripcion,
                'codigo' => Str::of($item->descripcion)->ascii()->slug('_')->upper()->limit(60, '')->toString() ?: 'SIN_CODIGO',
                'vida_util' => 0,
                'requiere_talla' => false,
                'talla_label' => 'No requiere',
                'tallas' => [],
                'requiere_color' => false,
                'color_label' => 'No requiere',
                'colores' => [],
                'categoria' => $item->categoria,
                'unidad' => $item->unidad ?: '',
                'otros_atributos' => $item->unidad ? [[
                    'nombre' => 'Unidad',
                    'valores' => [$item->unidad],
                ]] : [],
                'estado' => 'ACTIVO',
                'readonly' => true,
                'fuente' => 'CATALOGO_PARADA',
            ]);

        return $rows
            ->merge($toolCatalogRows)
            ->sortBy('nombre')
            ->values();
    }

    private function costRows(Collection $catalog): Collection
    {
        return $catalog->map(fn (EppRegistro $epp): array => [
            'nombre' => $epp->nombre,
            'proveedor' => $epp->proveedor ?: '-',
            'precio_unitario' => $epp->precio_unitario !== null ? number_format((float) $epp->precio_unitario, 2) : '-',
            'precio_alquiler' => $epp->precio_alquiler !== null ? number_format((float) $epp->precio_alquiler, 2) : '-',
            'orden_compra' => $epp->orden_compra ?: '-',
            'facturacion' => $epp->facturacion ?: '-',
            'stock' => (int) ($epp->stock ?? 0),
        ])->values();
    }

    private function filterChips(array $filters, array $options): Collection
    {
        $chips = collect();

        if ($filters['q'] !== '') {
            $chips->push(['label' => 'Buscar', 'value' => $filters['q']]);
        }

        $mineLabels = collect($options['minas'] ?? [])
            ->whereIn('id', $filters['minas'])
            ->pluck('nombre')
            ->values();
        if ($mineLabels->isNotEmpty()) {
            $chips->push(['label' => 'Mina', 'value' => $mineLabels->implode(', ')]);
        }

        if ($filters['parada_id'] !== '') {
            $label = collect($options['paradas'] ?? [])->firstWhere('id', $filters['parada_id'])['label'] ?? 'Parada seleccionada';
            $chips->push(['label' => 'Parada / RQ', 'value' => $label]);
        }

        $eppLabels = collect($options['epps'] ?? [])
            ->whereIn('id', $filters['epps'])
            ->pluck('nombre')
            ->values();
        if ($eppLabels->isNotEmpty()) {
            $chips->push(['label' => 'EPP', 'value' => $eppLabels->implode(', ')]);
        }

        $stateLabels = collect($options['estados'] ?? [])->only($filters['epp_estado'])->values();
        if ($stateLabels->isNotEmpty()) {
            $chips->push(['label' => 'Estado', 'value' => $stateLabels->implode(', ')]);
        }

        if ($filters['ficha'] !== '') {
            $chips->push(['label' => 'Ficha', 'value' => $filters['ficha'] === 'completa' ? 'Completa' : 'Incompleta']);
        }

        if ($filters['talla_estado'] !== '') {
            $chips->push(['label' => 'Talla', 'value' => $filters['talla_estado'] === 'con_talla' ? 'Con talla' : 'Sin talla']);
        }

        if ($filters['fecha_desde'] !== '') {
            $chips->push(['label' => 'Desde', 'value' => $filters['fecha_desde']]);
        }

        if ($filters['fecha_hasta'] !== '') {
            $chips->push(['label' => 'Hasta', 'value' => $filters['fecha_hasta']]);
        }

        return $chips;
    }

    private function normalizeText(mixed $value): string
    {
        return Str::upper(trim(Str::ascii((string) $value)));
    }

    private function hasTable(string $table): bool
    {
        try {
            return Schema::hasTable($table);
        } catch (\Throwable) {
            return false;
        }
    }

    private function hasColumn(string $table, string $column): bool
    {
        try {
            return Schema::hasColumn($table, $column);
        } catch (\Throwable) {
            return false;
        }
    }
}
