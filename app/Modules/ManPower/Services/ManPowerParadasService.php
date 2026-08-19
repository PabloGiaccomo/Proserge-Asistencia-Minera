<?php

namespace App\Modules\ManPower\Services;

use App\Models\Mina;
use App\Models\RQMina;
use App\Models\RQProsergeDetalle;
use App\Models\Usuario;
use App\Modules\ManPower\Policies\ManPowerPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ManPowerParadasService
{
    public function __construct(private readonly ManPowerPolicy $policy)
    {
    }

    public function listParadas(Usuario $usuario, array $filters): Collection
    {
        if (!$this->policy->viewParadas($usuario)) {
            return collect();
        }

        $fecha = array_key_exists('fecha', $filters)
            ? $this->normalizeDate((string) ($filters['fecha'] ?? ''))
            : null;
        $query = RQMina::query()->with(['mina:id,nombre,unidad_minera', 'planes:id,rq_mina_id,codigo,nombre,estado,fecha_inicio,fecha_fin']);

        $query->whereExists(function ($q) use ($fecha): void {
            $q->select(DB::raw(1))
                ->from('rq_proserge as rp')
                ->join('rq_proserge_detalle as rpd', 'rpd.rq_proserge_id', '=', 'rp.id')
                ->whereColumn('rp.rq_mina_id', 'rq_mina.id')
                ->whereIn('rpd.estado', RQProsergeDetalle::ESTADOS_ACTIVOS)
                ->when($fecha, function ($dateQuery) use ($fecha): void {
                    $dateQuery
                        ->where(function ($rangeQuery) use ($fecha): void {
                            $rangeQuery
                                ->whereNull('rpd.fecha_inicio')
                                ->orWhereDate('rpd.fecha_inicio', '<=', $fecha);
                        })
                        ->where(function ($rangeQuery) use ($fecha): void {
                            $rangeQuery
                                ->whereNull('rpd.fecha_fin')
                                ->orWhereDate('rpd.fecha_fin', '>=', $fecha);
                        });
                });
        });

        if (!empty($filters['mina_id'])) {
            $query->where('mina_id', $filters['mina_id']);
        }

        $unidadMinera = trim((string) ($filters['unidad_minera'] ?? ''));
        if ($unidadMinera !== '') {
            $query->whereHas('mina', fn ($minaQuery) => $minaQuery->where('unidad_minera', $unidadMinera));
        }

        if (!empty($filters['estado'])) {
            $query->where('estado', strtoupper((string) $filters['estado']));
        } else {
            $query->whereNotIn('estado', ['CERRADO', 'CANCELADO']);
        }

        if ($fecha) {
            $query->where(function ($dateQuery) use ($fecha): void {
                $dateQuery
                    ->where(function ($rangeQuery) use ($fecha): void {
                        $rangeQuery
                            ->whereNull('fecha_inicio')
                            ->orWhereDate('fecha_inicio', '<=', $fecha);
                    })
                    ->where(function ($rangeQuery) use ($fecha): void {
                        $rangeQuery
                            ->whereNull('fecha_fin')
                            ->orWhereDate('fecha_fin', '>=', $fecha);
                    });
            });
        }

        $search = trim((string) ($filters['q'] ?? ''));
        if ($search !== '') {
            $query->where(function ($searchQuery) use ($search): void {
                $like = '%'.$search.'%';

                $searchQuery
                    ->where('rq_mina.area', 'like', $like)
                    ->orWhere('rq_mina.destino_nombre', 'like', $like)
                    ->orWhere('rq_mina.estado', 'like', $like)
                    ->orWhereHas('mina', fn ($minaQuery) => $minaQuery
                        ->where('nombre', 'like', $like)
                        ->orWhere('unidad_minera', 'like', $like));
            });
        }

        if (!$this->isPrivileged($usuario)) {
            $scopeMinaIds = $usuario->scopesMina()->pluck('mina_id');
            $query->whereIn('mina_id', $scopeMinaIds);
        }

        return $query->orderBy('fecha_inicio')->orderByDesc('created_at')->get()->map(function (RQMina $rq) use ($fecha): array {
            $asignadosActivos = DB::table('rq_proserge_detalle as rpd')
                ->join('rq_proserge as rp', 'rp.id', '=', 'rpd.rq_proserge_id')
                ->where('rp.rq_mina_id', $rq->id)
                ->whereIn('rpd.estado', RQProsergeDetalle::ESTADOS_ACTIVOS)
                ->count();
            $asignadosFechaQuery = DB::table('rq_proserge_detalle as rpd')
                ->join('rq_proserge as rp', 'rp.id', '=', 'rpd.rq_proserge_id')
                ->where('rp.rq_mina_id', $rq->id)
                ->whereIn('rpd.estado', RQProsergeDetalle::ESTADOS_ACTIVOS);

            if ($fecha) {
                $asignadosFechaQuery
                    ->where(function ($rangeQuery) use ($fecha): void {
                        $rangeQuery
                            ->whereNull('rpd.fecha_inicio')
                            ->orWhereDate('rpd.fecha_inicio', '<=', $fecha);
                    })
                    ->where(function ($rangeQuery) use ($fecha): void {
                        $rangeQuery
                            ->whereNull('rpd.fecha_fin')
                            ->orWhereDate('rpd.fecha_fin', '>=', $fecha);
                    });
            }

            $asignadosFecha = $asignadosFechaQuery->count();
            $gruposCount = DB::table('grupo_trabajo')
                ->where('rq_mina_id', $rq->id)
                ->whereNotIn('estado', ['CANCELADO'])
                ->count();
            $gruposFechaQuery = DB::table('grupo_trabajo')
                ->where('rq_mina_id', $rq->id)
                ->whereNotIn('estado', ['CANCELADO']);

            if ($fecha) {
                $gruposFechaQuery->whereDate('fecha', $fecha);
            }

            $gruposFecha = $gruposFechaQuery->count();
            $totalRequerido = DB::table('rq_mina_detalle')
                ->where('rq_mina_id', $rq->id)
                ->sum(DB::raw('COALESCE(NULLIF(cantidad_total, 0), COALESCE(cantidad, 0) + COALESCE(cantidad_backup, 0))'));

            return [
                'rq_mina_id' => $rq->id,
                'mina_id' => $rq->mina_id,
                'mina_nombre' => $rq->mina?->nombre,
                'unidad_minera' => $rq->mina?->unidad_minera,
                'destino_tipo' => $rq->destino_tipo ?? 'MINA',
                'destino_id' => $rq->destino_id ?? $rq->mina_id,
                'destino_nombre' => $rq->destino_nombre ?? $rq->mina?->nombre,
                'area' => $rq->area,
                'fecha_inicio' => optional($rq->fecha_inicio)->toDateString(),
                'fecha_fin' => optional($rq->fecha_fin)->toDateString(),
                'estado' => $rq->estado,
                'fecha_filtro' => $fecha,
                'total_requerido' => (int) $totalRequerido,
                'asignados_activos' => $asignadosActivos,
                'asignados_fecha' => $asignadosFecha,
                'grupos_count' => $gruposCount,
                'grupos_fecha' => $gruposFecha,
                'planes_count' => $rq->planes->count(),
                'plan_vigente' => $rq->planes->firstWhere('estado', 'VIGENTE')?->only(['id', 'codigo', 'nombre', 'estado', 'fecha_inicio', 'fecha_fin']),
            ];
        });
    }

    public function listUnidades(Usuario $usuario): Collection
    {
        $query = Mina::query()->activeOperational()->whereNotNull('unidad_minera');

        if (!$this->isPrivileged($usuario)) {
            $query->whereIn('id', $usuario->scopesMina()->pluck('mina_id'));
        }

        return $query
            ->orderBy('unidad_minera')
            ->pluck('unidad_minera')
            ->map(fn ($value): string => trim((string) $value))
            ->filter()
            ->unique()
            ->values();
    }

    public function paradaDetalle(Usuario $usuario, string $rqMinaId, string $fecha): ?array
    {
        $rq = RQMina::query()->with(['mina:id,nombre', 'detalle'])->find($rqMinaId);

        if (!$rq) {
            return null;
        }

        if (!$this->policy->canAccessMina($usuario, $rq->mina_id)) {
            return null;
        }

        $aprobados = $this->aprobadosPorFecha($rqMinaId, $fecha);

        return [
            'rq_mina_id' => $rq->id,
            'mina_id' => $rq->mina_id,
            'mina_nombre' => $rq->mina?->nombre,
            'destino_tipo' => $rq->destino_tipo ?? 'MINA',
            'destino_id' => $rq->destino_id ?? $rq->mina_id,
            'destino_nombre' => $rq->destino_nombre ?? $rq->mina?->nombre,
            'area' => $rq->area,
            'fecha' => $fecha,
            'detalle_requerido' => $rq->detalle->map(function ($item): array {
                $cantidad = max(0, (int) $item->cantidad);
                $backup = (int) ($item->cantidad_backup ?? round($cantidad * 0.2));
                $total = (int) ($item->cantidad_total ?? ($cantidad + $backup));

                return [
                    'rq_mina_detalle_id' => $item->id,
                    'puesto' => $item->puesto,
                    'cantidad' => $cantidad,
                    'cantidad_backup' => $backup,
                    'cantidad_total' => $total,
                    'cantidad_atendida' => (int) $item->cantidad_atendida,
                ];
            })->values()->all(),
            'aprobados' => $aprobados,
        ];
    }

    public function aprobadosPorFecha(string $rqMinaId, string $fecha, ?string $rqProsergeId = null): array
    {
        return DB::table('rq_proserge_detalle as rpd')
            ->join('rq_proserge as rp', 'rp.id', '=', 'rpd.rq_proserge_id')
            ->join('personal as p', 'p.id', '=', 'rpd.personal_id')
            ->where('rp.rq_mina_id', $rqMinaId)
            ->when($rqProsergeId, fn ($q) => $q->where('rp.id', $rqProsergeId))
            ->whereIn('rpd.estado', RQProsergeDetalle::ESTADOS_ACTIVOS)
            ->where(function ($rangeQuery) use ($fecha): void {
                $rangeQuery
                    ->whereNull('rpd.fecha_inicio')
                    ->orWhereDate('rpd.fecha_inicio', '<=', $fecha);
            })
            ->where(function ($rangeQuery) use ($fecha): void {
                $rangeQuery
                    ->whereNull('rpd.fecha_fin')
                    ->orWhereDate('rpd.fecha_fin', '>=', $fecha);
            })
            ->select([
                'rpd.id as rq_proserge_detalle_id',
                'rpd.rq_proserge_id',
                'rpd.puesto_asignado',
                'rpd.puesto_asignado_snapshot',
                'rpd.posicion_asignacion',
                'rpd.tipo_asignacion',
                'rpd.estado_habilitacion_snapshot',
                'rpd.fecha_inicio',
                'rpd.fecha_fin',
                'p.id as personal_id',
                'p.dni',
                'p.numero_documento',
                'p.nombre_completo',
                'p.puesto',
                'p.es_supervisor',
                'rpd.rq_mina_detalle_id',
            ])
            ->orderBy('p.nombre_completo')
            ->get()
            ->map(fn ($row): array => [
                'rq_proserge_detalle_id' => $row->rq_proserge_detalle_id,
                'rq_proserge_id' => $row->rq_proserge_id,
                'personal_id' => $row->personal_id,
                'dni' => $row->dni,
                'numero_documento' => $row->numero_documento,
                'nombre_completo' => $row->nombre_completo,
                'puesto' => $row->puesto,
                'puesto_asignado' => $row->puesto_asignado_snapshot ?: $row->puesto_asignado,
                'posicion_asignacion' => $row->posicion_asignacion,
                'tipo_asignacion' => $row->tipo_asignacion,
                'estado_habilitacion_snapshot' => $row->estado_habilitacion_snapshot,
                'fecha_inicio' => $row->fecha_inicio,
                'fecha_fin' => $row->fecha_fin,
                'sin_clasificar' => !$row->posicion_asignacion || !$row->tipo_asignacion,
                'es_supervisor' => (bool) $row->es_supervisor,
                'rq_mina_detalle_id' => $row->rq_mina_detalle_id,
            ])
            ->values()
            ->all();
    }

    private function isPrivileged(Usuario $usuario): bool
    {
        $rol = strtoupper((string) optional($usuario->rol)->nombre);

        return in_array($rol, ['ADMIN', 'GERENTE', 'SUPERADMIN'], true);
    }

    private function normalizeDate(string $date): string
    {
        try {
            return CarbonImmutable::parse($date !== '' ? $date : now()->toDateString())->toDateString();
        } catch (\Throwable) {
            return now()->toDateString();
        }
    }

    public function listForUser(Usuario $usuario, array $filters): Collection
    {
        return $this->listParadas($usuario, $filters);
    }

    public function findForUser(Usuario $usuario, string $rqMinaId): ?array
    {
        return $this->paradaDetalle($usuario, $rqMinaId, now()->toDateString());
    }
}
