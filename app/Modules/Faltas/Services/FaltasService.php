<?php

namespace App\Modules\Faltas\Services;

use App\Models\Falta;
use App\Models\Usuario;
use App\Modules\Faltas\Policies\FaltaPolicy;
use App\Support\Rbac\PermissionMatrix;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class FaltasService
{
    public function __construct(
        private readonly FaltaPolicy $policy,
        private readonly CorregirFaltaService $corregirService,
    ) {
    }

    public function list(Usuario $usuario, array $filters): ?Collection
    {
        if (!$this->policy->manage($usuario)) {
            return null;
        }

        $query = Falta::query()
            ->leftJoin('asistencia_encabezado as ae', 'ae.id', '=', 'faltas.asistencia_encabezado_id')
            ->leftJoin('personal as p', 'p.id', '=', 'faltas.trabajador_id')
            ->leftJoin('minas as m', function ($join): void {
                $join->on('m.id', '=', 'faltas.destino_id')->where('faltas.destino_tipo', '=', 'MINA');
            })
            ->leftJoin('talleres as t', function ($join): void {
                $join->on('t.id', '=', 'faltas.destino_id')->where('faltas.destino_tipo', '=', 'TALLER');
            })
            ->leftJoin('oficinas as o', function ($join): void {
                $join->on('o.id', '=', 'faltas.destino_id')->where('faltas.destino_tipo', '=', 'OFICINA');
            })
            ->select(
                'faltas.*',
                DB::raw('ae.grupo_trabajo_id as grupo_trabajo_id'),
                DB::raw('p.nombre_completo as trabajador_nombre'),
                DB::raw('COALESCE(p.numero_documento, p.dni) as trabajador_documento'),
                DB::raw('p.puesto as trabajador_puesto'),
                DB::raw('COALESCE(m.nombre, t.nombre, o.nombre) as destino_nombre'),
            );

        if (!empty($filters['destino_tipo'])) {
            $query->where('faltas.destino_tipo', strtoupper((string) $filters['destino_tipo']));
        }

        if (!empty($filters['destino_id'])) {
            $query->where('faltas.destino_id', $filters['destino_id']);
        }

        if (!empty($filters['fecha_desde'])) {
            $query->whereDate('faltas.fecha', '>=', $filters['fecha_desde']);
        }

        if (!empty($filters['fecha_hasta'])) {
            $query->whereDate('faltas.fecha', '<=', $filters['fecha_hasta']);
        }

        if (!empty($filters['personal_id'])) {
            $query->where('faltas.trabajador_id', $filters['personal_id']);
        }

        if (!empty($filters['grupo_trabajo_id'])) {
            $query->where('ae.grupo_trabajo_id', $filters['grupo_trabajo_id']);
        }

        if (!empty($filters['estado'])) {
            $query->where('faltas.estado', strtoupper((string) $filters['estado']));
        }

        if (!empty($filters['motivo'])) {
            $query->where('faltas.motivo', strtoupper((string) $filters['motivo']));
        }

        if (!empty($filters['buscar'])) {
            $search = trim((string) $filters['buscar']);
            $query->where(function ($nested) use ($search): void {
                $nested->where('p.nombre_completo', 'like', "%{$search}%")
                    ->orWhere('p.numero_documento', 'like', "%{$search}%")
                    ->orWhere('p.dni', 'like', "%{$search}%")
                    ->orWhere('faltas.descripcion', 'like', "%{$search}%")
                    ->orWhere('faltas.observaciones', 'like', "%{$search}%");
            });
        }

        if (!$this->isPrivileged($usuario)) {
            $scopeMinaIds = $usuario->scopesMina()->pluck('mina_id');
            $query->where(function ($q) use ($scopeMinaIds): void {
                $q->where('faltas.destino_tipo', '!=', 'MINA')
                    ->orWhereIn('faltas.destino_id', $scopeMinaIds);
            });
        }

        return $query->orderByDesc('faltas.fecha')->get();
    }

    public function findForUser(Usuario $usuario, string $id): ?Falta
    {
        $falta = Falta::query()
            ->leftJoin('asistencia_encabezado as ae', 'ae.id', '=', 'faltas.asistencia_encabezado_id')
            ->leftJoin('personal as p', 'p.id', '=', 'faltas.trabajador_id')
            ->leftJoin('minas as m', function ($join): void {
                $join->on('m.id', '=', 'faltas.destino_id')->where('faltas.destino_tipo', '=', 'MINA');
            })
            ->leftJoin('talleres as t', function ($join): void {
                $join->on('t.id', '=', 'faltas.destino_id')->where('faltas.destino_tipo', '=', 'TALLER');
            })
            ->leftJoin('oficinas as o', function ($join): void {
                $join->on('o.id', '=', 'faltas.destino_id')->where('faltas.destino_tipo', '=', 'OFICINA');
            })
            ->select(
                'faltas.*',
                DB::raw('ae.grupo_trabajo_id as grupo_trabajo_id'),
                DB::raw('p.nombre_completo as trabajador_nombre'),
                DB::raw('COALESCE(p.numero_documento, p.dni) as trabajador_documento'),
                DB::raw('p.puesto as trabajador_puesto'),
                DB::raw('COALESCE(m.nombre, t.nombre, o.nombre) as destino_nombre'),
            )
            ->where('faltas.id', $id)
            ->first();

        if (!$falta) {
            return null;
        }

        return $this->policy->view($usuario, $falta) ? $falta : null;
    }

    public function update(Usuario $usuario, Falta $falta, array $payload): array
    {
        if (!PermissionMatrix::userCanDirectAny($usuario, 'faltas', ['editar', 'actualizar']) || !$this->policy->update($usuario, $falta)) {
            return $this->forbidden();
        }

        $falta->fill($payload);
        $falta->save();

        return ['ok' => true, 'falta' => $this->findForUser($usuario, $falta->id)];
    }

    public function corregirAsistencia(Usuario $usuario, Falta $falta, array $payload): array
    {
        if (!PermissionMatrix::userCanDirect($usuario, 'faltas', 'corregir') || !$this->policy->update($usuario, $falta)) {
            return $this->forbidden();
        }

        return $this->corregirService->corregir($falta, $usuario, $payload);
    }

    public function anular(Usuario $usuario, Falta $falta, array $payload): array
    {
        if (!PermissionMatrix::userCanDirect($usuario, 'faltas', 'anular') || !$this->policy->update($usuario, $falta)) {
            return $this->forbidden();
        }

        if (strtoupper((string) $falta->estado) === 'ANULADA') {
            return [
                'ok' => false,
                'code' => 'FALTA_ALREADY_ANULADA',
                'message' => 'La falta ya se encuentra anulada',
            ];
        }

        $falta->fill([
            'estado' => 'ANULADA',
            'motivo_anulacion' => $payload['motivo_anulacion'],
            'anulado_por_usuario_id' => $usuario->id,
            'anulado_at' => now(),
        ]);
        $falta->save();

        return ['ok' => true, 'falta' => $this->findForUser($usuario, $falta->id)];
    }

    private function forbidden(): array
    {
        return [
            'ok' => false,
            'code' => 'FALTAS_FORBIDDEN',
            'message' => 'No autorizado',
            'forbidden' => true,
        ];
    }

    private function isPrivileged(Usuario $usuario): bool
    {
        $rol = strtoupper((string) optional($usuario->rol)->nombre);

        return in_array($rol, ['ADMIN', 'GERENTE', 'SUPERADMIN'], true);
    }

    public function listForUser(Usuario $usuario, array $filters): array
    {
        $result = $this->list($usuario, $filters);
        
        return $result?->toArray() ?? [];
    }

    public function dashboard(Collection $items): array
    {
        $active = $items->filter(fn (Falta $item): bool => strtoupper((string) $item->estado) === 'ACTIVA');

        return [
            'summary' => [
                'total' => $items->count(),
                'active' => $active->count(),
                'corrected' => $items->filter(fn (Falta $item): bool => strtoupper((string) $item->estado) === 'CORREGIDA')->count(),
                'annulled' => $items->filter(fn (Falta $item): bool => strtoupper((string) $item->estado) === 'ANULADA')->count(),
                'workers' => $active->pluck('trabajador_id')->filter()->unique()->count(),
            ],
            'by_state' => $items->countBy(fn (Falta $item): string => strtoupper((string) ($item->estado ?: 'ACTIVA')))
                ->map(fn ($total, $state) => ['state' => $state, 'total' => (int) $total])
                ->sortByDesc('total')->values()->all(),
            'trend' => $items->groupBy(fn (Falta $item): string => Carbon::parse($item->fecha)->toDateString())
                ->sortKeys()
                ->map(fn (Collection $day, string $date) => [
                    'date' => $date,
                    'label' => Carbon::parse($date)->format('d/m'),
                    'total' => $day->count(),
                    'active' => $day->filter(fn (Falta $item): bool => strtoupper((string) $item->estado) === 'ACTIVA')->count(),
                ])->values()->all(),
            'top_workers' => $active->groupBy('trabajador_id')
                ->map(fn (Collection $workerItems) => [
                    'id' => $workerItems->first()->trabajador_id,
                    'name' => $workerItems->first()->trabajador_nombre ?: 'Trabajador sin nombre',
                    'document' => $workerItems->first()->trabajador_documento,
                    'total' => $workerItems->count(),
                ])->sortByDesc('total')->take(8)->values()->all(),
            'by_destination' => $active->groupBy(fn (Falta $item): string => $item->destino_nombre ?: ($item->destino_tipo ?: 'Sin destino'))
                ->map(fn (Collection $destinationItems, string $name) => [
                    'name' => $name,
                    'total' => $destinationItems->count(),
                ])->sortByDesc('total')->values()->all(),
        ];
    }

    public function corregir(Usuario $usuario, string $id, array $payload): array
    {
        $falta = $this->findForUser($usuario, $id);
        
        if (!$falta) {
            return ['success' => false, 'message' => 'Falta no encontrada'];
        }
        
        $result = $this->corregirAsistencia($usuario, $falta, $payload);
        
        return $result['ok'] ?? false
            ? ['success' => true, 'message' => 'Falta corregida']
            : ['success' => false, 'message' => $result['message'] ?? 'Error'];
    }

    public function anularPorId(Usuario $usuario, string $id, string $reason): array
    {
        $falta = $this->findForUser($usuario, $id);
        
        if (!$falta) {
            return ['success' => false, 'message' => 'Falta no encontrada'];
        }
        
        $result = $this->anular($usuario, $falta, ['motivo_anulacion' => $reason]);
        
        return $result['ok'] ?? false
            ? ['success' => true, 'message' => 'Falta anulada']
            : ['success' => false, 'message' => $result['message'] ?? 'Error'];
    }
}
