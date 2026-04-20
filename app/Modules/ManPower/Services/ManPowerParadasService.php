<?php

namespace App\Modules\ManPower\Services;

use App\Models\RQMina;
use App\Models\Usuario;
use App\Modules\ManPower\Policies\ManPowerPolicy;
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

        $query = RQMina::query()->with(['mina:id,nombre']);

        $query->whereExists(function ($q): void {
            $q->select(DB::raw(1))
                ->from('rq_proserge as rp')
                ->join('rq_proserge_detalle as rpd', 'rpd.rq_proserge_id', '=', 'rp.id')
                ->whereColumn('rp.rq_mina_id', 'rq_mina.id');
        });

        if (!empty($filters['mina_id'])) {
            $query->where('mina_id', $filters['mina_id']);
        }

        if (!empty($filters['estado'])) {
            $query->where('estado', strtoupper((string) $filters['estado']));
        }

        if (!$this->isPrivileged($usuario)) {
            $scopeMinaIds = $usuario->scopesMina()->pluck('mina_id');
            $query->whereIn('mina_id', $scopeMinaIds);
        }

        return $query->orderByDesc('created_at')->get()->map(function (RQMina $rq): array {
            $atendidos = DB::table('rq_proserge_detalle as rpd')
                ->join('rq_proserge as rp', 'rp.id', '=', 'rpd.rq_proserge_id')
                ->where('rp.rq_mina_id', $rq->id)
                ->count();

            return [
                'rq_mina_id' => $rq->id,
                'mina_id' => $rq->mina_id,
                'mina_nombre' => $rq->mina?->nombre,
                'area' => $rq->area,
                'fecha_inicio' => optional($rq->fecha_inicio)->toDateString(),
                'fecha_fin' => optional($rq->fecha_fin)->toDateString(),
                'estado' => $rq->estado,
                'atendidos' => $atendidos,
            ];
        });
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
            'area' => $rq->area,
            'fecha' => $fecha,
            'detalle_requerido' => $rq->detalle->map(fn ($item): array => [
                'rq_mina_detalle_id' => $item->id,
                'puesto' => $item->puesto,
                'cantidad' => (int) $item->cantidad,
                'cantidad_atendida' => (int) $item->cantidad_atendida,
            ])->values()->all(),
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
            ->whereDate('rpd.fecha_inicio', '<=', $fecha)
            ->whereDate('rpd.fecha_fin', '>=', $fecha)
            ->select([
                'p.id as personal_id',
                'p.nombre_completo',
                'p.puesto',
                'p.es_supervisor',
                'rpd.rq_mina_detalle_id',
            ])
            ->orderBy('p.nombre_completo')
            ->get()
            ->map(fn ($row): array => [
                'personal_id' => $row->personal_id,
                'nombre_completo' => $row->nombre_completo,
                'puesto' => $row->puesto,
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

    public function listForUser(Usuario $usuario, array $filters): Collection
    {
        return $this->listParadas($usuario, $filters);
    }

    public function findForUser(Usuario $usuario, string $rqMinaId): ?array
    {
        return $this->paradaDetalle($usuario, $rqMinaId, now()->toDateString());
    }
}
