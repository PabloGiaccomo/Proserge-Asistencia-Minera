<?php

namespace App\Modules\RQProserge\Services;

use App\Models\RQProserge;
use App\Models\RQProsergeDetalle;
use App\Models\RQMinaDetalle;
use App\Models\Usuario;
use App\Modules\RQProserge\Policies\RQProsergePolicy;
use App\Shared\Services\DisponibilidadPersonalService;
use App\Support\Rbac\PermissionMatrix;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class RQProsergeService
{
    public function __construct(
        private readonly RQProsergePolicy $policy,
        private readonly DisponibilidadPersonalService $disponibilidadService,
    ) {
    }

    public function listForUser(Usuario $usuario, array $filters): Collection
    {
        $query = RQProserge::query()->with(['mina:id,nombre', 'responsableRrhh:id,email', 'rqMina:id,estado']);

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
            ->with(['mina:id,nombre', 'responsableRrhh:id,email', 'rqMina:id,estado', 'detalle'])
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
        if (!PermissionMatrix::userCan($usuario, 'rq_proserge', 'crear')) {
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

        return $rq->load(['mina:id,nombre', 'responsableRrhh:id,email', 'rqMina:id,estado', 'detalle']);
    }

    public function assignPersonal(Usuario $usuario, RQProserge $rq, array $payload): array
    {
        if (!$this->policy->assign($usuario, $rq)) {
            return $this->businessError('RQ_PROSERGE_ASSIGN_FORBIDDEN', 'No autorizado para asignar en este RQ Proserge');
        }

        $rqMinaDetalle = RQMinaDetalle::query()
            ->where('id', $payload['rq_mina_detalle_id'])
            ->where('rq_mina_id', $rq->rq_mina_id)
            ->first();

        if (!$rqMinaDetalle) {
            return $this->businessError('RQ_MINA_DETALLE_INVALID', 'El detalle de RQ Mina no pertenece al RQ Proserge');
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

        DB::transaction(function () use ($rq, $payload): void {
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
        });

        return [
            'ok' => true,
            'rq' => $rq->fresh(['mina:id,nombre', 'responsableRrhh:id,email', 'rqMina:id,estado', 'detalle']),
        ];
    }

    public function unassignPersonal(Usuario $usuario, RQProserge $rq, string $rqProsergeDetalleId): array
    {
        if (!$this->policy->unassign($usuario, $rq)) {
            return $this->businessError('RQ_PROSERGE_UNASSIGN_FORBIDDEN', 'No autorizado para desasignar en este RQ Proserge');
        }

        $detalle = RQProsergeDetalle::query()
            ->where('id', $rqProsergeDetalleId)
            ->where('rq_proserge_id', $rq->id)
            ->first();

        if (!$detalle) {
            return $this->businessError('RQ_PROSERGE_DETALLE_NOT_FOUND', 'Asignacion no encontrada en el RQ Proserge');
        }

        $rqMinaDetalleId = $detalle->rq_mina_detalle_id;

        DB::transaction(function () use ($detalle, $rqMinaDetalleId): void {
            $detalle->delete();
            $this->recalculateCantidadAtendida($rqMinaDetalleId);
        });

        return [
            'ok' => true,
            'rq' => $rq->fresh(['mina:id,nombre', 'responsableRrhh:id,email', 'rqMina:id,estado', 'detalle']),
        ];
    }

    public function disponibles(RQProserge $rq, string $fechaInicio, string $fechaFin, int $limit = 25): array
    {
        $candidatos = DB::table('personal as p')
            ->join('personal_mina as pm', 'pm.personal_id', '=', 'p.id')
            ->where('pm.mina_id', $rq->mina_id)
            ->whereIn('pm.estado', ['ACTIVO', 'ASIGNADO', 'EN_PROCESO'])
            ->where('p.estado', 'ACTIVO')
            ->select(['p.id', 'p.nombre_completo', 'p.puesto'])
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
                'puesto' => $row->puesto,
                'disponible' => $evaluacion['available'] ?? false,
                'motivo_codigo' => $evaluacion['reason_code'],
                'motivo' => $evaluacion['reason_message'],
                'error_tecnico' => $evaluacion['technical_error'] ?? false,
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

    private function recalculateCantidadAtendida(string $rqMinaDetalleId): void
    {
        $count = RQProsergeDetalle::query()->where('rq_mina_detalle_id', $rqMinaDetalleId)->count();

        RQMinaDetalle::query()->where('id', $rqMinaDetalleId)->update([
            'cantidad_atendida' => $count,
            'updated_at' => now(),
        ]);
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
            || PermissionMatrix::userCan($usuario, 'rq_proserge', 'administrar');
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
        
        $rq->fill([
            'asignado_a_usuario_id' => null,
            'asignado_at' => null,
        ]);
        $rq->save();
        
        return ['success' => true, 'message' => 'Desasignación realizada'];
    }
}
