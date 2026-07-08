<?php

namespace App\Modules\Logistica\Services;

use App\Models\EppEntrega;
use App\Models\EppRegistro;
use App\Models\Mina;
use App\Models\ParadaHerramientaLista;
use App\Models\Personal;
use App\Models\PersonalMina;
use App\Models\RQMina;
use App\Models\RQProsergeDetalle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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

    public function pageData(array $query): array
    {
        $tabs = $this->tabs();
        $filters = $this->filters($query, array_keys($tabs));
        $options = $this->options();
        $workers = $this->workers($filters);
        $catalog = $this->eppCatalog($filters);
        $deliveries = $this->deliveries($workers->pluck('id'), $catalog->pluck('id'));
        $activeDeliveries = $deliveries->where('estado', EppEntrega::ESTADO_ENTREGADO);
        $sizeSummary = $this->sizeSummary($workers);
        $requirements = $this->requirements($workers, $catalog, $activeDeliveries);
        $expiringDeliveries = $this->expiringDeliveries($activeDeliveries);
        $mineSummary = $this->mineSummary($workers);
        $cargoSummary = $this->cargoSummary($workers);
        $pendingEpp = $requirements->sum('pendiente_entrega');
        $expiringEpp = $expiringDeliveries->where('estado_visual', 'POR_VENCER')->count();
        $expiredEpp = $expiringDeliveries->where('estado_visual', 'VENCIDO')->count();

        return [
            'tabs' => $this->tabOptions($tabs),
            'activeTab' => $filters['tab'],
            'filters' => $filters,
            'options' => $options,
            'metrics' => [
                'workers' => $workers->count(),
                'habilitados' => $this->habilitatedWorkersCount($workers, $filters),
                'required_epp' => $requirements->sum('requerido'),
                'pending_epp' => $pendingEpp,
                'expired_epp' => $expiredEpp,
                'expiring_epp' => $expiringEpp,
                'minas' => $mineSummary->count(),
                'cargos' => $cargoSummary->count(),
                'faltantes' => $pendingEpp,
                'porVencer' => $expiringEpp,
                'vencidos' => $expiredEpp,
                'entregasRecientes' => $deliveries->count(),
                'stockCritico' => $requirements->where('stock_estado', 'FALTANTE')->count(),
            ],
            'mineSummary' => $mineSummary,
            'cargoSummary' => $cargoSummary,
            'sizeSummary' => $sizeSummary,
            'requirements' => $requirements,
            'missingWorkers' => $this->missingWorkers($workers, $catalog, $activeDeliveries),
            'recentDeliveries' => $this->recentDeliveries($deliveries),
            'expiringDeliveries' => $expiringDeliveries,
            'toolsRows' => $this->toolsRows(),
            'serviceRows' => $this->serviceRows(),
            'identityRows' => $this->identityRows($catalog),
            'costRows' => $this->costRows($catalog),
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
        $tab = in_array($query['tab'] ?? '', $tabs, true) ? (string) $query['tab'] : self::TAB_DASHBOARD;

        return [
            'tab' => $tab,
            'q' => trim((string) ($query['q'] ?? '')),
            'parada_id' => trim((string) ($query['parada_id'] ?? '')),
            'minas' => $this->arrayFilter($query['minas'] ?? []),
            'cargos' => $this->arrayFilter($query['cargos'] ?? []),
            'estados' => $this->arrayFilter($query['estados'] ?? []),
            'epps' => $this->arrayFilter($query['epps'] ?? []),
            'tallas' => $this->arrayFilter($query['tallas'] ?? []),
        ];
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
                ? Mina::query()->orderBy('nombre')->get(['id', 'nombre'])
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
                'ACTIVO' => 'Activo',
                'FALTA_CONTRATO' => 'Falta contrato',
                'CESADO' => 'Cesado',
                PersonalMina::ESTADO_HABILITADO => 'Habilitado en mina',
                PersonalMina::ESTADO_EN_PROCESO => 'En proceso de mina',
                PersonalMina::ESTADO_NO_HABILITADO => 'No habilitado en mina',
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

        return RQMina::query()
            ->with('mina:id,nombre')
            ->latest('fecha_inicio')
            ->limit(150)
            ->get(['id', 'mina_id', 'area', 'fecha_inicio', 'fecha_fin'])
            ->map(function (RQMina $rqMina): array {
                $mina = $rqMina->mina?->nombre ?: 'Sin mina';
                $area = $rqMina->area ?: 'Sin area';
                $inicio = $rqMina->fecha_inicio?->format('d/m/Y') ?: 'sin inicio';
                $fin = $rqMina->fecha_fin?->format('d/m/Y') ?: 'sin fin';

                return [
                    'id' => $rqMina->id,
                    'label' => "{$mina} - {$area} ({$inicio} al {$fin})",
                ];
            });
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
                    ->with('mina:id,nombre'),
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
                    });
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
                            });
                    });
                }
            });
        }

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

    private function deliveries(Collection $workerIds, Collection $eppIds): Collection
    {
        if (! $this->hasTable('epp_entregas')) {
            return collect();
        }

        $query = EppEntrega::query()
            ->with(['personal:id,nombre_completo,dni,numero_documento,puesto', 'epp:id,codigo,nombre,vida_util_dias,estado'])
            ->latest('fecha_entrega')
            ->limit(800);

        if ($workerIds->isNotEmpty()) {
            $query->whereIn('personal_id', $workerIds->values()->all());
        }

        if ($eppIds->isNotEmpty()) {
            $query->whereIn('epp_id', $eppIds->values()->all());
        }

        return $query->get();
    }

    private function mineSummary(Collection $workers): Collection
    {
        return $workers
            ->flatMap(function (Personal $personal): array {
                return $personal->relacionesMina->map(function ($relation): array {
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
                $relations = $personal->relationLoaded('relacionesMina') ? $personal->relacionesMina : collect();

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
            foreach ($this->workerSizes($worker) as $tipo => $talla) {
                if ($talla === '') {
                    continue;
                }

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
                        ])
                        ->filter(static fn (array $size): bool => $size['talla'] !== '')
                        ->values(),
                ];
            })
            ->values();
    }

    private function workerSizes(Personal $personal): array
    {
        $data = $personal->fichaColaborador?->datos_json ?? [];

        return [
            'Zapatos' => $this->firstData($data, ['zapato_botas', 'zapato', 'botas', 'talla_zapato', 'talla_calzado']),
            'Pantalon' => $this->firstData($data, ['pantalon', 'talla_pantalon']),
            'Camisa / chaleco' => $this->firstData($data, ['camisa_chaleco', 'camisa', 'chaleco', 'talla_camisa', 'talla_chaleco']),
            'Respirador' => $this->firstData($data, ['respirador', 'talla_respirador']),
            'Guantes' => $this->firstData($data, ['guantes', 'talla_guantes']),
            'Casco' => $this->firstData($data, ['casco', 'talla_casco']),
        ];
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

    private function requirements(Collection $workers, Collection $catalog, Collection $activeDeliveries): Collection
    {
        $workersCount = $workers->count();

        return $catalog->map(function (EppRegistro $epp) use ($workers, $workersCount, $activeDeliveries): array {
            $covered = $activeDeliveries
                ->where('epp_id', $epp->id)
                ->pluck('personal_id')
                ->unique()
                ->count();
            $stock = (int) ($epp->stock ?? 0);
            $required = $workersCount;
            $pending = max(0, $required - $covered);

            return [
                'id' => $epp->id,
                'nombre' => $epp->nombre,
                'vida_util_dias' => $epp->vida_util_dias,
                'requerido' => $required,
                'entregado' => $covered,
                'pendiente_entrega' => $pending,
                'stock' => $stock,
                'stock_estado' => $stock >= $pending ? 'OK' : 'FALTANTE',
                'tallas' => $this->sizesForEpp($workers, $epp),
            ];
        })->values();
    }

    private function sizesForEpp(Collection $workers, EppRegistro $epp): Collection
    {
        $type = $this->eppSizeType($epp->nombre);

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
            str_contains($name, 'CAMISA') || str_contains($name, 'CHALECO') || str_contains($name, 'CASACA') => 'Camisa / chaleco',
            str_contains($name, 'RESP') => 'Respirador',
            str_contains($name, 'GUANTE') => 'Guantes',
            str_contains($name, 'CASCO') => 'Casco',
            default => '',
        };
    }

    private function missingWorkers(Collection $workers, Collection $catalog, Collection $activeDeliveries): Collection
    {
        return $workers->map(function (Personal $worker) use ($catalog, $activeDeliveries): ?array {
            $delivered = $activeDeliveries
                ->where('personal_id', $worker->id)
                ->pluck('epp_id')
                ->unique();
            $missing = $catalog
                ->reject(fn (EppRegistro $epp): bool => $delivered->contains($epp->id))
                ->pluck('nombre')
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

    private function expiringDeliveries(Collection $activeDeliveries): Collection
    {
        $today = now()->startOfDay();

        return $activeDeliveries
            ->filter(fn (EppEntrega $delivery): bool => $delivery->fecha_vencimiento_calendario !== null)
            ->map(function (EppEntrega $delivery) use ($today): array {
                $row = $this->deliveryRow($delivery);
                $days = $today->diffInDays($delivery->fecha_vencimiento_calendario, false);
                $row['dias'] = (int) $days;
                $row['estado_visual'] = $days < 0 ? 'VENCIDO' : ($days <= 30 ? 'POR_VENCER' : 'VIGENTE');

                return $row;
            })
            ->filter(fn (array $row): bool => $row['dias'] <= 60)
            ->sortBy('dias')
            ->take(100)
            ->values();
    }

    private function deliveryRow(EppEntrega $delivery): array
    {
        return [
            'trabajador' => $delivery->personal?->nombre_completo ?: 'Sin trabajador',
            'documento' => $delivery->personal?->dni ?: $delivery->personal?->numero_documento ?: '-',
            'puesto' => $delivery->personal?->puesto ?: 'Por definir',
            'epp' => $delivery->epp?->nombre ?: 'Sin EPP',
            'cantidad' => (int) ($delivery->cantidad ?? 1),
            'fecha_entrega' => $delivery->fecha_entrega?->format('d/m/Y') ?: '-',
            'fecha_vencimiento' => $delivery->fecha_vencimiento_calendario?->format('d/m/Y') ?: '-',
            'estado' => $delivery->estado,
            'observacion' => $delivery->observacion ?: $delivery->motivo_cambio ?: '-',
        ];
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
        if (! $this->hasTable('rq_mina')) {
            return collect();
        }

        return RQMina::query()
            ->with(['mina:id,nombre', 'transportes'])
            ->latest('fecha_inicio')
            ->limit(30)
            ->get()
            ->map(function (RQMina $rqMina): array {
                return [
                    'parada' => ($rqMina->mina?->nombre ?: 'Sin mina') . ' - ' . ($rqMina->area ?: 'Sin area'),
                    'inicio' => $rqMina->fecha_inicio?->format('d/m/Y') ?: '-',
                    'fin' => $rqMina->fecha_fin?->format('d/m/Y') ?: '-',
                    'transportes' => $rqMina->transportes->count(),
                    'detalle' => $rqMina->transportes->pluck('transporte')->filter()->take(3)->implode(', ') ?: 'Sin transporte registrado',
                    'estado' => $rqMina->estado ?: 'BORRADOR',
                ];
            });
    }

    private function identityRows(Collection $catalog): Collection
    {
        return $catalog->map(fn (EppRegistro $epp): array => [
            'nombre' => $epp->nombre,
            'codigo' => $epp->codigo ?: Str::slug($epp->nombre, '-'),
            'vida_util' => (int) ($epp->vida_util_dias ?? 0),
            'talla' => $epp->requiere_talla ? collect($epp->tallas ?: [])->implode(', ') : 'No requiere',
            'color' => $epp->requiere_color ? collect($epp->colores ?: [])->implode(', ') : 'No requiere',
            'estado' => $epp->estado,
        ])->values();
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

    private function normalizeText(mixed $value): string
    {
        return Str::upper(trim((string) $value));
    }

    private function hasTable(string $table): bool
    {
        try {
            return Schema::hasTable($table);
        } catch (\Throwable) {
            return false;
        }
    }
}
