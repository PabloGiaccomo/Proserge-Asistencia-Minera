<?php

namespace App\Modules\RQProserge\Services;

use App\Models\RQProserge;
use App\Models\RQProsergeDetalle;
use App\Models\RQMina;
use App\Models\RQMinaDetalleCambio;
use App\Models\RQMinaDetalle;
use App\Models\PersonalContrato;
use App\Models\Usuario;
use App\Modules\Notificaciones\Services\OperationalNotificationService;
use App\Modules\RQProserge\Policies\RQProsergePolicy;
use App\Shared\Services\DisponibilidadPersonalService;
use App\Support\Rbac\PermissionMatrix;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class RQProsergeService
{
    private const PARADA_FINALIZADA_CODE = 'RQ_PROSERGE_PARADA_FINALIZADA';

    private const PARADA_FINALIZADA_MESSAGE = 'La parada ya finalizo. No se pueden modificar asignaciones ni seguimiento.';

    public function __construct(
        private readonly RQProsergePolicy $policy,
        private readonly DisponibilidadPersonalService $disponibilidadService,
        private readonly OperationalNotificationService $operationalNotifications,
    ) {
    }

    public function listForUser(Usuario $usuario, array $filters): Collection
    {
        $query = RQProserge::query()->with(['mina:id,nombre', 'responsableRrhh:id,email', 'rqMina:id,estado,fecha_inicio,fecha_fin']);

        if (!empty($filters['mina_id'])) {
            $query->where('mina_id', $filters['mina_id']);
        }

        if (!empty($filters['estado'])) {
            $query->where('estado', strtoupper((string) $filters['estado']));
        }

        if (!empty($filters['responsable_rrhh_id'])) {
            $query->where('responsable_rrhh_id', $filters['responsable_rrhh_id']);
        }

        if (!empty($filters['rq_mina_id'])) {
            $query->where('rq_mina_id', $filters['rq_mina_id']);
        }

        if (!$this->isPrivileged($usuario)) {
            $minaIds = $usuario->scopesMina()->pluck('mina_id');
            $query->whereIn('mina_id', $minaIds);
        }

        return $query->orderByDesc('created_at')->get();
    }

    public function findForUser(Usuario $usuario, string $id): ?RQProserge
    {
        $rq = RQProserge::query()
            ->with(['mina:id,nombre', 'responsableRrhh:id,email', 'rqMina:id,estado,fecha_inicio,fecha_fin', 'detalle'])
            ->find($id);

        if (!$rq) {
            return null;
        }

        if (!$this->policy->view($usuario, $rq)) {
            return null;
        }

        return $rq;
    }

    public function create(Usuario $usuario, array $payload): ?RQProserge
    {
        if (!PermissionMatrix::userCanDirect($usuario, 'rq_proserge', 'crear')) {
            return null;
        }

        if (!$this->policy->canAccessMina($usuario, $payload['mina_id'])) {
            return null;
        }

        $rq = RQProserge::query()->create([
            'id' => (string) Str::uuid(),
            'rq_mina_id' => $payload['rq_mina_id'],
            'mina_id' => $payload['mina_id'],
            'responsable_rrhh_id' => $payload['responsable_rrhh_id'],
            'estado' => 'BORRADOR',
            'comentario_planner' => $payload['comentario_planner'] ?? null,
            'comentario_rrhh' => $payload['comentario_rrhh'] ?? null,
        ]);

        return $rq->load(['mina:id,nombre', 'responsableRrhh:id,email', 'rqMina:id,estado,fecha_inicio,fecha_fin', 'detalle']);
    }

    public function syncFromRqMina(Usuario $usuario, RQMina $rqMina): ?RQProserge
    {
        $rqMina->loadMissing('detalle');

        if ($rqMina->detalle->isEmpty()) {
            return null;
        }

        $rq = RQProserge::query()
            ->where('rq_mina_id', $rqMina->id)
            ->orderByDesc('created_at')
            ->first();

        if (!$rq) {
            $rq = RQProserge::query()->create([
                'id' => (string) Str::uuid(),
                'rq_mina_id' => $rqMina->id,
                'mina_id' => $rqMina->mina_id,
                'responsable_rrhh_id' => $this->resolveResponsibleRrhhId($usuario),
                'estado' => 'PENDIENTE',
                'comentario_planner' => null,
                'comentario_rrhh' => null,
            ]);
        } else {
            $rq->fill([
                'mina_id' => $rqMina->mina_id,
            ]);
            $rq->save();
        }

        $pendingChanges = RQMinaDetalleCambio::query()
            ->where('rq_mina_id', $rqMina->id)
            ->where('estado', RQMinaDetalleCambio::ESTADO_PENDIENTE)
            ->orderBy('created_at')
            ->get();

        RQMinaDetalleCambio::query()
            ->where('rq_mina_id', $rqMina->id)
            ->whereNull('rq_proserge_id')
            ->update([
                'rq_proserge_id' => $rq->id,
                'updated_at' => now(),
            ]);

        $this->recalculateEstado($rq, $usuario);

        if ($pendingChanges->isNotEmpty()) {
            $this->operationalNotifications->rqMinaPedidoModificado(
                $rqMina,
                $rq->fresh(),
                $usuario,
                $pendingChanges->map(fn (RQMinaDetalleCambio $change): array => [
                    'id' => (string) $change->id,
                    'tipo' => (string) $change->tipo,
                    'puesto' => (string) $change->puesto,
                    'mensaje' => (string) $change->mensaje,
                ])->values()->all()
            );
        }

        return $rq->fresh(['mina:id,nombre', 'responsableRrhh:id,email', 'rqMina:id,estado,fecha_inicio,fecha_fin', 'detalle']);
    }

    public function recalculateEstadoForRqMina(string $rqMinaId): void
    {
        RQProserge::query()
            ->where('rq_mina_id', $rqMinaId)
            ->get()
            ->each(fn (RQProserge $rq): bool => $this->recalculateEstado($rq));
    }

    public function listOperationalForUser(Usuario $usuario, array $filters = []): Collection
    {
        $today = CarbonImmutable::now()->startOfDay();
        $recentPastLimit = $today->subDays(14);

        $query = RQProserge::query()->with([
            'mina:id,nombre',
            'responsableRrhh:id,email',
            'rqMina:id,mina_id,destino_tipo,destino_id,destino_nombre,area,fecha_inicio,fecha_fin,estado,observaciones',
            'rqMina.detalle.rqMina:id,fecha_inicio,fecha_fin',
            'rqMina.detalle.asignaciones.personal:id,dni,nombre_completo,puesto',
            'rqMina.detalle.asignaciones.personal.minas:id,nombre',
            'rqMina.detalle.cambios',
            'cambiosRqMina',
        ])->whereHas('rqMina', function ($rqMinaQuery) use ($recentPastLimit): void {
            $rqMinaQuery
                ->whereNull('fecha_fin')
                ->orWhereDate('fecha_fin', '>=', $recentPastLimit->toDateString());
        });

        if (!empty($filters['mina_id'])) {
            $query->where('mina_id', $filters['mina_id']);
        }

        if (!empty($filters['estado'])) {
            $query->where('estado', strtoupper((string) $filters['estado']));
        }

        if (!empty($filters['rq_mina_id'])) {
            $query->where('rq_mina_id', $filters['rq_mina_id']);
        }

        if (!$this->isPrivileged($usuario)) {
            $minaIds = $usuario->scopesMina()->pluck('mina_id');
            $query->whereIn('mina_id', $minaIds);
        }

        return $query->get()
            ->sort(fn (RQProserge $left, RQProserge $right): int => $this->compareOperationalPriority($left, $right, $today))
            ->values();
    }

    public function assignPersonal(Usuario $usuario, RQProserge $rq, array $payload): array
    {
        if (!$this->policy->assign($usuario, $rq)) {
            return $this->businessError('RQ_PROSERGE_ASSIGN_FORBIDDEN', 'No autorizado para asignar en este RQ Proserge');
        }

        if ($blocked = $this->finishedParadaModificationError($rq)) {
            return $blocked;
        }

        $rqMinaDetalle = RQMinaDetalle::query()
            ->where('id', $payload['rq_mina_detalle_id'])
            ->where('rq_mina_id', $rq->rq_mina_id)
            ->first();

        if (!$rqMinaDetalle) {
            return $this->businessError('RQ_MINA_DETALLE_INVALID', 'El detalle de RQ Mina no pertenece al RQ Proserge');
        }

        if (!$this->assignmentFitsRqMinaDates($rq, $payload['fecha_inicio'], $payload['fecha_fin'])) {
            return $this->businessError(
                'RQ_PROSERGE_ASSIGNMENT_DATE_OUT_OF_RANGE',
                'Las fechas de asignacion deben estar dentro de las fechas de la parada'
            );
        }

        $disponibilidad = $this->disponibilidadService->evaluar(
            personalId: $payload['personal_id'],
            minaId: $rq->mina_id,
            fechaInicio: $payload['fecha_inicio'],
            fechaFin: $payload['fecha_fin'],
        );

        if (($disponibilidad['technical_error'] ?? false) === true) {
            throw new RuntimeException($disponibilidad['reason_message'] ?? 'Error tecnico');
        }

        if (($disponibilidad['available'] ?? false) === false) {
            return $this->businessError(
                (string) ($disponibilidad['reason_code'] ?? 'PERSONAL_UNAVAILABLE'),
                (string) ($disponibilidad['reason_message'] ?? 'Personal no disponible')
            );
        }

        $alreadyAssigned = RQProsergeDetalle::query()
            ->where('rq_proserge_id', $rq->id)
            ->where('rq_mina_detalle_id', $payload['rq_mina_detalle_id'])
            ->where('personal_id', $payload['personal_id'])
            ->whereDate('fecha_inicio', '<=', $payload['fecha_fin'])
            ->whereDate('fecha_fin', '>=', $payload['fecha_inicio'])
            ->exists();

        if ($alreadyAssigned) {
            return $this->businessError('RQ_PROSERGE_DUPLICATE_ASSIGNMENT', 'Personal ya asignado en este rango dentro del RQ');
        }

        DB::transaction(function () use ($rq, $payload, $usuario): void {
            RQProsergeDetalle::query()->create([
                'id' => (string) Str::uuid(),
                'rq_proserge_id' => $rq->id,
                'rq_mina_detalle_id' => $payload['rq_mina_detalle_id'],
                'personal_id' => $payload['personal_id'],
                'puesto_asignado' => $payload['puesto_asignado'],
                'fecha_inicio' => $payload['fecha_inicio'],
                'fecha_fin' => $payload['fecha_fin'],
                'comentario' => $payload['comentario'] ?? null,
                'ultimo_turno_referencia' => $payload['ultimo_turno_referencia'] ?? null,
                'estado' => 'ASIGNADO',
            ]);

            $this->recalculateCantidadAtendida($payload['rq_mina_detalle_id']);
            $this->recalculateEstado($rq, $usuario);
        });

        return [
            'ok' => true,
            'rq' => $rq->fresh(['mina:id,nombre', 'responsableRrhh:id,email', 'rqMina:id,estado,fecha_inicio,fecha_fin', 'detalle']),
        ];
    }

    public function unassignPersonal(Usuario $usuario, RQProserge $rq, string $rqProsergeDetalleId): array
    {
        if (!$this->policy->unassign($usuario, $rq)) {
            return $this->businessError('RQ_PROSERGE_UNASSIGN_FORBIDDEN', 'No autorizado para desasignar en este RQ Proserge');
        }

        if ($blocked = $this->finishedParadaModificationError($rq)) {
            return $blocked;
        }

        $detalle = RQProsergeDetalle::query()
            ->where('id', $rqProsergeDetalleId)
            ->where('rq_proserge_id', $rq->id)
            ->first();

        if (!$detalle) {
            return $this->businessError('RQ_PROSERGE_DETALLE_NOT_FOUND', 'Asignacion no encontrada en el RQ Proserge');
        }

        $rqMinaDetalleId = $detalle->rq_mina_detalle_id;

        DB::transaction(function () use ($detalle, $rqMinaDetalleId, $rq, $usuario): void {
            $detalle->delete();
            $this->recalculateCantidadAtendida($rqMinaDetalleId);
            $this->recalculateEstado($rq, $usuario);
        });

        return [
            'ok' => true,
            'rq' => $rq->fresh(['mina:id,nombre', 'responsableRrhh:id,email', 'rqMina:id,estado,fecha_inicio,fecha_fin', 'detalle']),
        ];
    }

    public function disponibles(RQProserge $rq, string $fechaInicio, string $fechaFin, int $limit = 25): array
    {
        $candidatos = DB::table('personal as p')
            ->where(function ($query): void {
                $query->whereNull('p.estado')
                    ->orWhere('p.estado', '!=', 'CESADO');
            })
            ->whereExists(function ($query) use ($fechaInicio, $fechaFin): void {
                $query->selectRaw('1')
                    ->from('personal_contratos as pc')
                    ->whereColumn('pc.personal_id', 'p.id')
                    ->whereIn('pc.estado', [PersonalContrato::ESTADO_ACTIVO, PersonalContrato::ESTADO_PREPARACION])
                    ->whereNotNull('pc.fecha_inicio')
                    ->whereDate('pc.fecha_inicio', '<=', $fechaFin)
                    ->where(function ($subQuery) use ($fechaInicio): void {
                        $subQuery->whereNull('pc.fecha_fin')
                            ->orWhereDate('pc.fecha_fin', '>=', $fechaInicio);
                    });
            })
            ->select(['p.id', 'p.dni', 'p.numero_documento', 'p.nombre_completo', 'p.puesto'])
            ->orderBy('p.nombre_completo')
            ->limit($limit)
            ->get();

        return $candidatos->map(function ($row) use ($rq, $fechaInicio, $fechaFin): array {
            $evaluacion = $this->disponibilidadService->evaluar(
                personalId: (string) $row->id,
                minaId: (string) $rq->mina_id,
                fechaInicio: $fechaInicio,
                fechaFin: $fechaFin,
            );

            return [
                'personal_id' => $row->id,
                'nombre_completo' => $row->nombre_completo,
                'documento' => $row->dni ?: $row->numero_documento,
                'puesto' => $row->puesto,
                'disponible' => $evaluacion['available'] ?? false,
                'motivo_codigo' => $evaluacion['reason_code'],
                'motivo' => $evaluacion['reason_message'],
                'lineas' => $evaluacion['lineas'] ?? [],
                'error_tecnico' => $evaluacion['technical_error'] ?? false,
                'mina_estado' => $evaluacion['mina_estado'] ?? null,
            ];
        })->values()->all();
    }

    public function searchAvailablePersonal(RQProserge $rq, string $search, string $fechaInicio, string $fechaFin, int $limit = 20): array
    {
        $needle = trim(mb_strtolower($search));
        $tokens = collect(preg_split('/\s+/', $needle) ?: [])
            ->filter()
            ->take(5)
            ->values();

        if ($tokens->isEmpty()) {
            return [];
        }

        $candidatos = DB::table('personal as p')
            ->where(function ($query) use ($tokens): void {
                foreach ($tokens as $token) {
                    $like = '%' . $token . '%';
                    $query->where(function ($subQuery) use ($like): void {
                        $subQuery
                            ->whereRaw('LOWER(COALESCE(p.nombre_completo, "")) LIKE ?', [$like])
                            ->orWhereRaw('LOWER(COALESCE(p.dni, "")) LIKE ?', [$like])
                            ->orWhereRaw('LOWER(COALESCE(p.numero_documento, "")) LIKE ?', [$like])
                            ->orWhereRaw('LOWER(COALESCE(p.puesto, "")) LIKE ?', [$like]);
                    });
                }
            })
            ->select(['p.id', 'p.dni', 'p.numero_documento', 'p.nombre_completo', 'p.puesto'])
            ->distinct()
            ->orderBy('p.nombre_completo')
            ->limit($limit)
            ->get();

        return $candidatos->map(function ($row) use ($rq, $fechaInicio, $fechaFin): array {
            $evaluacion = $this->disponibilidadService->evaluar(
                personalId: (string) $row->id,
                minaId: (string) $rq->mina_id,
                fechaInicio: $fechaInicio,
                fechaFin: $fechaFin,
            );

            return [
                'personal_id' => (string) $row->id,
                'nombre_completo' => (string) $row->nombre_completo,
                'documento' => (string) ($row->dni ?: $row->numero_documento ?: ''),
                'puesto' => (string) ($row->puesto ?: ''),
                'disponible' => (bool) ($evaluacion['available'] ?? false),
                'motivo_codigo' => $evaluacion['reason_code'] ?? null,
                'motivo' => $evaluacion['reason_message'] ?? null,
                'lineas' => $evaluacion['lineas'] ?? [],
                'error_tecnico' => (bool) ($evaluacion['technical_error'] ?? false),
                'mina_estado' => $evaluacion['mina_estado'] ?? null,
            ];
        })->values()->all();
    }

    public function canList(Usuario $usuario): bool
    {
        return $this->policy->viewAny($usuario);
    }

    public function canAssign(Usuario $usuario, RQProserge $rq): bool
    {
        return $this->policy->assign($usuario, $rq);
    }

    public function canUpdate(Usuario $usuario, RQProserge $rq): bool
    {
        return $this->policy->update($usuario, $rq);
    }

    public function update(Usuario $usuario, RQProserge $rq, array $payload): ?RQProserge
    {
        if (!$this->policy->update($usuario, $rq)) {
            return null;
        }

        if ($this->finishedParadaModificationError($rq)) {
            return null;
        }

        $rq->fill($payload);
        $rq->save();

        return $rq->load(['mina:id,nombre', 'responsableRrhh:id,email', 'rqMina:id,estado,fecha_inicio,fecha_fin', 'detalle']);
    }

    public function modificationBlockedByFinishedParada(RQProserge $rq): ?array
    {
        return $this->finishedParadaModificationError($rq);
    }

    private function recalculateCantidadAtendida(string $rqMinaDetalleId): void
    {
        $count = RQProsergeDetalle::query()->where('rq_mina_detalle_id', $rqMinaDetalleId)->count();

        RQMinaDetalle::query()->where('id', $rqMinaDetalleId)->update([
            'cantidad_atendida' => $count,
            'updated_at' => now(),
        ]);
    }

    private function assignmentFitsRqMinaDates(RQProserge $rq, string $fechaInicio, string $fechaFin): bool
    {
        $rqMina = $rq->relationLoaded('rqMina') ? $rq->rqMina : null;

        if (!$rqMina || !$rqMina->fecha_inicio || !$rqMina->fecha_fin) {
            $rqMina = RQMina::query()
                ->select(['id', 'fecha_inicio', 'fecha_fin'])
                ->find($rq->rq_mina_id);
        }

        if (!$rqMina || !$rqMina->fecha_inicio || !$rqMina->fecha_fin) {
            return true;
        }

        $assignmentStart = CarbonImmutable::parse($fechaInicio)->startOfDay();
        $assignmentEnd = CarbonImmutable::parse($fechaFin)->startOfDay();
        $rqStart = $this->immutableDate($rqMina->fecha_inicio);
        $rqEnd = $this->immutableDate($rqMina->fecha_fin);

        if (!$rqStart || !$rqEnd) {
            return true;
        }

        return $assignmentStart->gte($rqStart) && $assignmentEnd->lte($rqEnd);
    }

    private function finishedParadaModificationError(RQProserge $rq): ?array
    {
        if (!$this->rqMinaHasFinished($rq)) {
            return null;
        }

        return $this->businessError(self::PARADA_FINALIZADA_CODE, self::PARADA_FINALIZADA_MESSAGE);
    }

    private function rqMinaHasFinished(RQProserge $rq): bool
    {
        $rqMina = $rq->relationLoaded('rqMina') ? $rq->rqMina : null;

        if (!$rqMina || !$rqMina->fecha_fin) {
            $rqMina = RQMina::query()
                ->select(['id', 'fecha_fin'])
                ->find($rq->rq_mina_id);
        }

        if (!$rqMina || !$rqMina->fecha_fin) {
            return false;
        }

        $end = $this->immutableDate($rqMina->fecha_fin);

        return $end !== null && $end->endOfDay()->lt(CarbonImmutable::now());
    }

    private function compareOperationalPriority(RQProserge $left, RQProserge $right, CarbonImmutable $today): int
    {
        $leftKey = $this->operationalPriorityKey($left, $today);
        $rightKey = $this->operationalPriorityKey($right, $today);

        foreach ($leftKey as $index => $leftValue) {
            $rightValue = $rightKey[$index] ?? null;

            if ($leftValue === $rightValue) {
                continue;
            }

            return $leftValue <=> $rightValue;
        }

        return 0;
    }

    /**
     * Orden operativo:
     * 1. Paradas activas o futuras antes que las ya vencidas.
     * 2. Fechas de inicio cercanas en grupos de 3 dias.
     * 3. Dentro de fechas cercanas, mayor faltante y solicitado primero.
     */
    private function operationalPriorityKey(RQProserge $rq, CarbonImmutable $today): array
    {
        $rqMina = $rq->rqMina;
        $start = $this->immutableDate($rqMina?->fecha_inicio);
        $end = $this->immutableDate($rqMina?->fecha_fin);
        $needs = $this->operationalNeeds($rq);
        $createdAt = $this->immutableDate($rq->created_at);

        $isPast = $end !== null && $end->lt($today);

        if ($isPast) {
            $daysExpired = (int) abs($end->diffInDays($today, false));

            return [
                1,
                $daysExpired,
                -$needs['faltante'],
                -$needs['solicitado'],
                -($end?->getTimestamp() ?? 0),
                -($createdAt?->getTimestamp() ?? 0),
            ];
        }

        $startDistance = $start ? (int) abs($today->diffInDays($start, false)) : 99999;
        $closeDateBand = intdiv($startDistance, 3);

        return [
            0,
            $closeDateBand,
            -$needs['faltante'],
            -$needs['solicitado'],
            $start?->getTimestamp() ?? PHP_INT_MAX,
            -($createdAt?->getTimestamp() ?? 0),
        ];
    }

    private function operationalNeeds(RQProserge $rq): array
    {
        $detalles = $rq->rqMina?->detalle ?? collect();

        $solicitado = 0;
        $atendido = 0;

        foreach ($detalles as $detalle) {
            $solicitado += (int) ($detalle->cantidad_total ?: $detalle->cantidad);
            $atendido += $detalle->relationLoaded('asignaciones')
                ? $detalle->asignaciones->count()
                : (int) $detalle->cantidad_atendida;
        }

        return [
            'solicitado' => $solicitado,
            'atendido' => $atendido,
            'faltante' => max(0, $solicitado - $atendido),
        ];
    }

    private function immutableDate(mixed $date): ?CarbonImmutable
    {
        if ($date instanceof CarbonImmutable) {
            return $date->startOfDay();
        }

        if ($date instanceof CarbonInterface) {
            return CarbonImmutable::instance($date)->startOfDay();
        }

        if (!$date) {
            return null;
        }

        return CarbonImmutable::parse((string) $date)->startOfDay();
    }

    private function recalculateEstado(RQProserge $rq, ?Usuario $actor = null): bool
    {
        $previousState = strtoupper((string) $rq->estado);
        $totals = RQMinaDetalle::query()
            ->where('rq_mina_id', $rq->rq_mina_id)
            ->selectRaw('COALESCE(SUM(CASE WHEN cantidad_total > 0 THEN cantidad_total ELSE cantidad END), 0) as solicitado')
            ->selectRaw('COALESCE(SUM(cantidad_atendida), 0) as atendido')
            ->first();

        $solicitado = (int) ($totals->solicitado ?? 0);
        $atendido = (int) ($totals->atendido ?? 0);

        $estado = 'PENDIENTE';
        if ($solicitado > 0 && $atendido >= $solicitado) {
            $estado = 'COMPLETADO';
        } elseif ($atendido > 0) {
            $estado = 'PARCIAL';
        }

        $changed = false;
        if (!in_array($rq->estado, ['CERRADO', 'CANCELADO'], true) && $rq->estado !== $estado) {
            $rq->forceFill(['estado' => $estado])->save();
            $changed = true;
        }

        if ($changed && $previousState !== $estado && $estado === 'COMPLETADO') {
            $this->operationalNotifications->rqProsergeCompletado($rq->fresh(['rqMina.creador', 'rqMina.mina']) ?: $rq, $actor);
        }

        return true;
    }

    private function resolveResponsibleRrhhId(Usuario $actor): string
    {
        if (PermissionMatrix::userCanDirectAny($actor, 'rq_proserge', ['asignar', 'actualizar', 'administrar'])) {
            return (string) $actor->id;
        }

        $candidate = Usuario::query()
            ->with(['rol', 'rolesAdicionales'])
            ->get()
            ->first(fn (Usuario $usuario): bool => PermissionMatrix::userCanDirectAny($usuario, 'rq_proserge', ['asignar', 'actualizar', 'administrar']));

        return (string) ($candidate?->id ?? $actor->id);
    }

    private function businessError(string $code, string $message): array
    {
        return [
            'ok' => false,
            'code' => $code,
            'message' => $message,
            'technical_error' => false,
        ];
    }

    private function isPrivileged(Usuario $usuario): bool
    {
        $rol = strtoupper((string) optional($usuario->rol)->nombre);

        return in_array($rol, ['ADMIN', 'GERENTE', 'SUPERADMIN'], true)
            || PermissionMatrix::userCanDirect($usuario, 'rq_proserge', 'administrar');
    }

    public function createForUser(Usuario $usuario, array $payload): array
    {
        $rq = $this->create($usuario, $payload);
        
        if (!$rq) {
            return ['success' => false, 'message' => 'No tienes permiso para crear solicitudes'];
        }
        
        return ['success' => true, 'message' => 'Solicitud creada correctamente', 'data' => $rq];
    }

    public function updateForUser(Usuario $usuario, string $id, array $payload): array
    {
        $rq = RQProserge::query()->find($id);
        
        if (!$rq) {
            return ['success' => false, 'message' => 'Solicitud no encontrada'];
        }

        if ($blocked = $this->finishedParadaModificationError($rq)) {
            return ['success' => false, 'message' => $blocked['message'], 'code' => $blocked['code']];
        }
        
        $updated = $this->update($usuario, $rq, $payload);
        
        if (!$updated) {
            return ['success' => false, 'message' => 'No tienes permiso para actualizar esta solicitud'];
        }
        
        return ['success' => true, 'message' => 'Solicitud actualizada correctamente', 'data' => $updated];
    }

    public function asignar(Usuario $usuario, string $id, string $asignadoId): array
    {
        $rq = RQProserge::query()->find($id);
        
        if (!$rq) {
            return ['success' => false, 'message' => 'Solicitud no encontrada'];
        }

        if ($blocked = $this->finishedParadaModificationError($rq)) {
            return ['success' => false, 'message' => $blocked['message'], 'code' => $blocked['code']];
        }
        
        $result = $this->assign($usuario, $rq, $asignadoId);
        
        return $result['ok'] ?? false 
            ? ['success' => true, 'message' => 'Asignación realizada']
            : ['success' => false, 'message' => $result['message'] ?? 'Error al asignar'];
    }

    public function desasignar(Usuario $usuario, string $id): array
    {
        $rq = RQProserge::query()->find($id);
        
        if (!$rq) {
            return ['success' => false, 'message' => 'Solicitud no encontrada'];
        }

        if ($blocked = $this->finishedParadaModificationError($rq)) {
            return ['success' => false, 'message' => $blocked['message'], 'code' => $blocked['code']];
        }
        
        $rq->fill([
            'asignado_a_usuario_id' => null,
            'asignado_at' => null,
        ]);
        $rq->save();
        
        return ['success' => true, 'message' => 'Desasignación realizada'];
    }
}
