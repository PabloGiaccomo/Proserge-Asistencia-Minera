<?php

namespace App\Modules\ManPower\Services;

use App\Models\GrupoTrabajo;
use App\Models\GrupoTrabajoDetalle;
use App\Models\Mina;
use App\Models\Oficina;
use App\Models\RQMina;
use App\Models\RQMinaActividad;
use App\Models\RQMinaActividadGrupo;
use App\Models\RQMinaPlan;
use App\Models\RQProsergeDetalle;
use App\Models\Taller;
use App\Models\Usuario;
use App\Modules\ManPower\Policies\ManPowerPolicy;
use App\Modules\Transporte\Services\TransportePlanningService;
use App\Support\Rbac\PermissionMatrix;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class GrupoTrabajoService
{
    public function __construct(
        private readonly ManPowerPolicy $policy,
        private readonly ManPowerParadasService $paradasService,
        private readonly ManPowerPlanningService $planningService,
        private readonly TransportePlanningService $transportePlanningService,
    ) {
    }

    public function createGrupo(Usuario $usuario, array $payload): array
    {
        if (!PermissionMatrix::userCanDirect($usuario, 'man_power', 'crear') || !$this->policy->manageGrupos($usuario)) {
            return $this->forbidden('MANPOWER_FORBIDDEN');
        }

        $rqMina = RQMina::query()->with(['mina:id,nombre'])->find($payload['rq_mina_id']);

        if (!$rqMina) {
            return $this->businessError('MANPOWER_RQ_MINA_NOT_FOUND', 'RQ Mina no encontrado');
        }

        if (!$this->policy->canAccessMina($usuario, (string) $rqMina->mina_id)) {
            return $this->forbidden('MINA_SCOPE_FORBIDDEN');
        }

        if (!$this->dateFitsRqMina($rqMina, (string) $payload['fecha'])) {
            return $this->businessError('MANPOWER_DATE_OUT_OF_RQ', 'La fecha no esta dentro del rango de la parada');
        }

        if ($this->hasAsistenciaIniciada((string) $rqMina->mina_id, (string) $payload['fecha'])) {
            return $this->businessError('MANPOWER_ASSISTENCIA_LOCKED', 'No se puede crear grupo con asistencia iniciada o cerrada');
        }

        try {
            $plan = $this->resolvePlan($rqMina, $payload['rq_mina_plan_id'] ?? null);
            if ($plan && $plan->estado === RQMinaPlan::ESTADO_ARCHIVADO) {
                return $this->businessError('MANPOWER_PLAN_ARCHIVED', 'El plan archivado es solo consulta');
            }

            if ($plan && !$this->dateFitsPlan($plan, (string) $payload['fecha'])) {
                return $this->businessError('MANPOWER_DATE_OUT_OF_PLAN', 'La fecha no esta dentro del rango del plan');
            }

            $grupoOperativo = $this->resolveGrupoOperativo($rqMina, $plan, $payload['rq_mina_actividad_grupo_id'] ?? null);
            $activityIds = $this->resolveActivityIds($grupoOperativo, $payload['actividad_ids'] ?? []);
        } catch (\InvalidArgumentException $exception) {
            return $this->businessError($exception->getMessage(), 'El plan, grupo operativo o actividades no pertenecen a la parada seleccionada');
        }

        if ($grupoOperativo && count($activityIds) !== 1) {
            return $this->businessError('MANPOWER_SAIT_REQUIRED', 'Selecciona un unico SAIT para preparar el grupo');
        }

        $activityId = (string) ($activityIds[0] ?? '');
        if ($activityId !== '' && GrupoTrabajo::query()
            ->where('rq_mina_id', $rqMina->id)
            ->whereDate('fecha', (string) $payload['fecha'])
            ->where('turno', (string) $payload['turno'])
            ->where('estado', '!=', GrupoTrabajo::ESTADO_CANCELADO)
            ->whereHas('actividades', fn ($query) => $query->where('rq_mina_actividades.id', $activityId))
            ->exists()) {
            return $this->businessError('MANPOWER_SAIT_GROUP_EXISTS', 'Este SAIT ya tiene un grupo preparado para el turno seleccionado');
        }

        $destination = $this->resolveDestino((string) $payload['destino_tipo'], (string) $payload['destino_id']);

        if (!$destination) {
            return $this->businessError('MANPOWER_INVALID_DESTINATION', 'Destino invalido');
        }

        $hasInitialMembers = collect($payload['rq_proserge_detalle_ids'] ?? [])->filter()->isNotEmpty()
            || collect($payload['personal_ids'] ?? [])->filter()->isNotEmpty()
            || !empty($payload['supervisor_id']);
        $assignments = $hasInitialMembers
            ? $this->resolveAssignments($rqMina->id, (string) $payload['fecha'], $payload)
            : ['ok' => true, 'items' => collect()];

        if (($assignments['ok'] ?? false) === false) {
            return $assignments;
        }

        /** @var Collection<int, RQProsergeDetalle> $assignmentItems */
        $assignmentItems = $assignments['items'];
        $plannedSnapshot = $grupoOperativo
            ? $this->planningService->buildGroupSnapshot($grupoOperativo, (string) $payload['fecha'], (string) $payload['turno'], $activityIds)
            : ['actividad_cantidades' => [], 'cantidad_planificada_snapshot' => 0];

        if (!empty($payload['supervisor_id']) && !$assignmentItems->contains(fn (RQProsergeDetalle $item): bool => $item->personal_id === $payload['supervisor_id'])) {
            return $this->businessError('MANPOWER_INVALID_SUPERVISOR', 'El responsable de lista debe formar parte del grupo');
        }

        $conflict = $this->hasDistributionConflict(
            $assignmentItems->pluck('personal_id')->all(),
            $assignmentItems->pluck('id')->all(),
            (string) $payload['fecha'],
            (string) $payload['turno'],
        );

        if ($conflict) {
            return $this->businessError('MANPOWER_PERSON_GROUP_CONFLICT', 'Hay personal que ya pertenece a otro grupo en la misma fecha y turno');
        }

        try {
            $grupo = DB::transaction(function () use ($usuario, $payload, $destination, $plan, $grupoOperativo, $activityIds, $assignmentItems, $plannedSnapshot): GrupoTrabajo {
                $assignmentItems = $this->lockAssignments($assignmentItems->pluck('id')->all());

                if ($this->hasDistributionConflict(
                    $assignmentItems->pluck('personal_id')->all(),
                    $assignmentItems->pluck('id')->all(),
                    (string) $payload['fecha'],
                    (string) $payload['turno'],
                )) {
                    throw new \RuntimeException('MANPOWER_PERSON_GROUP_CONFLICT');
                }

                $snapshot = $plannedSnapshot;

                $grupo = GrupoTrabajo::query()->create($this->filterColumns('grupo_trabajo', [
                    'id' => (string) Str::uuid(),
                    'fecha' => $payload['fecha'],
                    'supervisor_id' => $payload['supervisor_id'] ?? null,
                    'mina' => $destination['nombre'],
                    'rq_mina_id' => $payload['rq_mina_id'],
                    'rq_mina_plan_id' => $plan?->id,
                    'rq_mina_actividad_grupo_id' => $grupoOperativo?->id,
                    'codigo_grupo' => $grupoOperativo?->modulo ?: $grupoOperativo?->area_operativa,
                    'nombre_snapshot' => $snapshot['nombre_snapshot'] ?? null,
                    'area_snapshot' => $snapshot['area_snapshot'] ?? null,
                    'sector_snapshot' => $snapshot['sector_snapshot'] ?? null,
                    'modulo_snapshot' => $snapshot['modulo_snapshot'] ?? null,
                    'sait_snapshot' => $snapshot['sait_snapshot'] ?? null,
                    'supervisor_operativo_snapshot' => $snapshot['supervisor_operativo_snapshot'] ?? null,
                    'supervisor_seguridad_snapshot' => $snapshot['supervisor_seguridad_snapshot'] ?? null,
                    'cantidad_planificada_snapshot' => $snapshot['cantidad_planificada_snapshot'] ?? null,
                    'rq_proserge_id' => $assignmentItems->pluck('rq_proserge_id')->unique()->count() === 1
                        ? $assignmentItems->first()?->rq_proserge_id
                        : ($payload['rq_proserge_id'] ?? null),
                    'servicio' => $payload['servicio'],
                    'area' => $payload['area'],
                    'paradero' => $payload['paradero'] ?? null,
                    'paradero_link' => $payload['paradero_link'] ?? null,
                    'unidad' => $destination['tipo'],
                    'destino_tipo' => $destination['tipo'],
                    'destino_id' => $destination['id'],
                    'horario_salida' => $payload['horario_salida'],
                    'turno' => $payload['turno'],
                    'estado' => $payload['estado'] ?? GrupoTrabajo::ESTADO_BORRADOR,
                    'observaciones' => $payload['observaciones'] ?? null,
                    'observacion_planificacion' => $payload['observacion_planificacion'] ?? null,
                    'justificacion_brecha' => $payload['justificacion_brecha'] ?? null,
                    'created_by_id' => $usuario->id,
                    'updated_by_id' => $usuario->id,
                ]));

                $this->attachActivities($grupo, $activityIds, $snapshot['actividad_cantidades'] ?? []);

                foreach ($assignmentItems as $assignment) {
                    $this->addAssignmentToGrupo($grupo, $assignment, $usuario);
                }

                return $grupo;
            });
        } catch (\RuntimeException $exception) {
            if ($exception->getMessage() === 'MANPOWER_PERSON_GROUP_CONFLICT') {
                return $this->businessError('MANPOWER_PERSON_GROUP_CONFLICT', 'Hay personal que ya pertenece a otro grupo en la misma fecha y turno');
            }

            throw $exception;
        }

        return [
            'ok' => true,
            'grupo' => $this->loadGrupo($grupo->fresh()),
        ];
    }

    public function updateGrupo(Usuario $usuario, GrupoTrabajo $grupo, array $payload): array
    {
        if (!PermissionMatrix::userCanDirectAny($usuario, 'man_power', ['editar', 'actualizar']) || !$this->policy->manageGrupo($usuario, $grupo)) {
            return $this->forbidden('MANPOWER_FORBIDDEN');
        }

        $grupo = GrupoTrabajo::query()->with(['rqMina'])->find($grupo->id);
        if (!$grupo || !$grupo->rqMina) {
            return $this->businessError('MANPOWER_RQ_MINA_NOT_FOUND', 'Grupo sin RQ Mina valido');
        }

        $minaId = (string) optional($grupo->rqMina)->mina_id;
        if ($this->groupHasClosedAttendance($grupo) || $this->hasAsistenciaIniciada($minaId, $grupo->fecha->toDateString())) {
            return $this->businessError('MANPOWER_ASSISTENCIA_LOCKED', 'No se puede modificar integrantes con asistencia cerrada');
        }

        if (!$this->dateFitsRqMina($grupo->rqMina, (string) $payload['fecha'])) {
            return $this->businessError('MANPOWER_DATE_OUT_OF_RQ', 'La fecha no esta dentro del rango de la parada');
        }

        try {
            $plan = $this->resolvePlan($grupo->rqMina, $payload['rq_mina_plan_id'] ?? $grupo->rq_mina_plan_id);
            if ($plan && $plan->estado === RQMinaPlan::ESTADO_ARCHIVADO) {
                return $this->businessError('MANPOWER_PLAN_ARCHIVED', 'El plan archivado es solo consulta');
            }

            $grupoOperativo = $this->resolveGrupoOperativo($grupo->rqMina, $plan, $payload['rq_mina_actividad_grupo_id'] ?? $grupo->rq_mina_actividad_grupo_id);
            $activityIds = $this->resolveActivityIds($grupoOperativo, $payload['actividad_ids'] ?? []);
        } catch (\InvalidArgumentException $exception) {
            return $this->businessError($exception->getMessage(), 'El plan, grupo operativo o actividades no pertenecen al grupo seleccionado');
        }
        $destination = $this->resolveDestino((string) $payload['destino_tipo'], (string) $payload['destino_id']);

        if (!$destination) {
            return $this->businessError('MANPOWER_INVALID_DESTINATION', 'Destino invalido');
        }

        $syncMembers = array_key_exists('rq_proserge_detalle_ids', $payload) || array_key_exists('personal_ids', $payload);
        $assignmentItems = collect();

        if ($syncMembers) {
            $assignments = $this->resolveAssignments($grupo->rq_mina_id, (string) $payload['fecha'], $payload);

            if (($assignments['ok'] ?? false) === false) {
                return $assignments;
            }

            $assignmentItems = $assignments['items'];

            if (!$assignmentItems->contains(fn (RQProsergeDetalle $item): bool => $item->personal_id === $payload['supervisor_id'])) {
                return $this->businessError('MANPOWER_INVALID_SUPERVISOR', 'El responsable de lista debe formar parte del grupo');
            }
        }
        $plannedSnapshot = $grupoOperativo
            ? $this->planningService->buildGroupSnapshot($grupoOperativo, (string) $payload['fecha'], (string) $payload['turno'], $activityIds)
            : ['actividad_cantidades' => [], 'cantidad_planificada_snapshot' => 0];

        try {
            DB::transaction(function () use ($usuario, $grupo, $payload, $destination, $plan, $grupoOperativo, $activityIds, $syncMembers, $assignmentItems, $plannedSnapshot): void {
            if ($syncMembers) {
                $assignmentItems = $this->lockAssignments($assignmentItems->pluck('id')->all());

                if ($this->hasDistributionConflict(
                    $assignmentItems->pluck('personal_id')->all(),
                    $assignmentItems->pluck('id')->all(),
                    (string) $payload['fecha'],
                    (string) $payload['turno'],
                    $grupo->id,
                )) {
                    throw new \RuntimeException('MANPOWER_PERSON_GROUP_CONFLICT');
                }
            }

            $snapshot = $plannedSnapshot;

            $grupo->fill($this->filterColumns('grupo_trabajo', [
                'fecha' => $payload['fecha'],
                'turno' => $payload['turno'],
                'supervisor_id' => $payload['supervisor_id'],
                'servicio' => $payload['servicio'],
                'area' => $payload['area'],
                'paradero' => $payload['paradero'] ?? null,
                'paradero_link' => $payload['paradero_link'] ?? null,
                'horario_salida' => $payload['horario_salida'],
                'mina' => $destination['nombre'],
                'unidad' => $destination['tipo'],
                'destino_tipo' => $destination['tipo'],
                'destino_id' => $destination['id'],
                'rq_mina_plan_id' => $plan?->id,
                'rq_mina_actividad_grupo_id' => $grupoOperativo?->id,
                'codigo_grupo' => $grupoOperativo?->modulo ?: $grupoOperativo?->area_operativa,
                'nombre_snapshot' => $snapshot['nombre_snapshot'] ?? null,
                'area_snapshot' => $snapshot['area_snapshot'] ?? null,
                'sector_snapshot' => $snapshot['sector_snapshot'] ?? null,
                'modulo_snapshot' => $snapshot['modulo_snapshot'] ?? null,
                'sait_snapshot' => $snapshot['sait_snapshot'] ?? null,
                'supervisor_operativo_snapshot' => $snapshot['supervisor_operativo_snapshot'] ?? null,
                'supervisor_seguridad_snapshot' => $snapshot['supervisor_seguridad_snapshot'] ?? null,
                'cantidad_planificada_snapshot' => $snapshot['cantidad_planificada_snapshot'] ?? null,
                'observaciones' => $payload['observaciones'] ?? null,
                'observacion_planificacion' => $payload['observacion_planificacion'] ?? null,
                'justificacion_brecha' => $payload['justificacion_brecha'] ?? null,
                'estado' => $payload['estado'] ?? $grupo->estado,
                'updated_by_id' => $usuario->id,
            ]));
            $grupo->save();
            $this->attachActivities($grupo, $activityIds, $snapshot['actividad_cantidades'] ?? []);

            if ($syncMembers) {
                $this->syncAssignments($grupo, $assignmentItems, $usuario);
            }
            });
        } catch (\RuntimeException $exception) {
            if ($exception->getMessage() === 'MANPOWER_PERSON_GROUP_CONFLICT') {
                return $this->businessError('MANPOWER_PERSON_GROUP_CONFLICT', 'Hay personal que ya pertenece a otro grupo en la misma fecha y turno');
            }

            throw $exception;
        }

        return [
            'ok' => true,
            'grupo' => $this->loadGrupo($grupo->fresh()),
        ];
    }

    public function addPersonal(Usuario $usuario, GrupoTrabajo $grupo, ?string $personalId = null, ?string $rqProsergeDetalleId = null): array
    {
        if (!PermissionMatrix::userCanDirect($usuario, 'man_power', 'asignar') || !$this->policy->manageGrupo($usuario, $grupo)) {
            return $this->forbidden('MANPOWER_FORBIDDEN');
        }

        $minaId = (string) optional($grupo->rqMina)->mina_id;
        if ($this->groupHasClosedAttendance($grupo) || $this->hasAsistenciaIniciada($minaId, $grupo->fecha->toDateString())) {
            return $this->businessError('MANPOWER_ASSISTENCIA_LOCKED', 'No se puede modificar grupo con asistencia cerrada');
        }

        $assignment = $this->resolveSingleAssignment($grupo, $personalId, $rqProsergeDetalleId);
        if (!$assignment) {
            return $this->businessError('MANPOWER_PERSON_NOT_APPROVED', 'Personal fuera del universo aprobado para la parada');
        }

        if ($this->hasDistributionConflict([$assignment->personal_id], [$assignment->id], $grupo->fecha->toDateString(), (string) $grupo->turno, $grupo->id)) {
            return $this->businessError('MANPOWER_PERSON_GROUP_CONFLICT', 'El trabajador ya esta asignado en otro turno o en un grupo incompatible de esta fecha');
        }

        $this->addAssignmentToGrupo($grupo, $assignment, $usuario);

        return [
            'ok' => true,
            'grupo' => $this->loadGrupo($grupo->fresh()),
        ];
    }

    public function setResponsable(Usuario $usuario, GrupoTrabajo $grupo, string $detalleId): array
    {
        if (!PermissionMatrix::userCanDirect($usuario, 'man_power', 'asignar') || !$this->policy->manageGrupo($usuario, $grupo)) {
            return $this->forbidden('MANPOWER_FORBIDDEN');
        }

        $minaId = (string) optional($grupo->rqMina)->mina_id;
        if ($this->groupHasClosedAttendance($grupo) || $this->hasAsistenciaIniciada($minaId, $grupo->fecha->toDateString())) {
            return $this->businessError('MANPOWER_ASSISTENCIA_LOCKED', 'No se puede cambiar el responsable con asistencia cerrada');
        }

        $personalId = null;

        try {
            DB::transaction(function () use ($usuario, $grupo, $detalleId, &$personalId): void {
                $detalle = GrupoTrabajoDetalle::query()
                    ->where('grupo_trabajo_id', $grupo->id)
                    ->where(function ($query): void {
                        $query->where('estado_distribucion', GrupoTrabajoDetalle::ESTADO_DISTRIBUCION_ASIGNADO)
                            ->orWhereNull('estado_distribucion');
                    })
                    ->lockForUpdate()
                    ->find($detalleId);

                if (!$detalle || !$detalle->personal_id) {
                    throw new \RuntimeException('MANPOWER_RESPONSIBLE_NOT_ACTIVE_MEMBER');
                }

                $lockedGroup = GrupoTrabajo::query()->lockForUpdate()->findOrFail($grupo->id);
                $changes = ['supervisor_id' => $detalle->personal_id];
                if (Usuario::query()->whereKey($usuario->id)->exists()) {
                    $changes['updated_by_id'] = $usuario->id;
                }
                $lockedGroup->forceFill($changes)->save();

                $personalId = (string) $detalle->personal_id;
            });
        } catch (\RuntimeException $exception) {
            if ($exception->getMessage() === 'MANPOWER_RESPONSIBLE_NOT_ACTIVE_MEMBER') {
                return $this->businessError(
                    'MANPOWER_RESPONSIBLE_NOT_ACTIVE_MEMBER',
                    'El responsable debe ser un integrante activo del grupo',
                );
            }

            throw $exception;
        }

        return [
            'ok' => true,
            'responsable_id' => $personalId,
            'grupo' => $this->loadGrupo($grupo->fresh()),
        ];
    }

    public function removePersonal(Usuario $usuario, GrupoTrabajo $grupo, string $personalId): array
    {
        if (!PermissionMatrix::userCanDirect($usuario, 'man_power', 'asignar') || !$this->policy->manageGrupo($usuario, $grupo)) {
            return $this->forbidden('MANPOWER_FORBIDDEN');
        }

        $minaId = (string) optional($grupo->rqMina)->mina_id;
        if ($this->groupHasClosedAttendance($grupo) || $this->hasAsistenciaIniciada($minaId, $grupo->fecha->toDateString())) {
            return $this->businessError('MANPOWER_ASSISTENCIA_LOCKED', 'No se puede modificar grupo con asistencia cerrada');
        }

        $detalle = GrupoTrabajoDetalle::query()
            ->where('grupo_trabajo_id', $grupo->id)
            ->where('personal_id', $personalId)
            ->first();

        if (!$detalle) {
            return $this->businessError('MANPOWER_PERSON_NOT_IN_GROUP', 'Personal no pertenece al grupo');
        }

        DB::transaction(function () use ($detalle, $usuario): void {
            if ($detalle->rq_proserge_detalle_id) {
                $this->markDetalleRetired($detalle, $usuario, 'Retirado desde Man Power');
            } else {
                $detalle->delete();
            }

            $this->transportePlanningService->retireActivePassengerForDetail($usuario, $detalle->id);
        });

        return [
            'ok' => true,
            'grupo' => $this->loadGrupo($grupo->fresh()),
        ];
    }

    public function retireDetalle(Usuario $usuario, GrupoTrabajo $grupo, string $detalleId, string $motivo): array
    {
        if (!PermissionMatrix::userCanDirect($usuario, 'man_power', 'asignar') || !$this->policy->manageGrupo($usuario, $grupo)) {
            return $this->forbidden('MANPOWER_FORBIDDEN');
        }

        $minaId = (string) optional($grupo->rqMina)->mina_id;
        if ($this->groupHasClosedAttendance($grupo) || $this->hasAsistenciaIniciada($minaId, $grupo->fecha->toDateString())) {
            return $this->businessError('MANPOWER_ASSISTENCIA_LOCKED', 'No se puede modificar grupo con asistencia cerrada');
        }

        $detalle = GrupoTrabajoDetalle::query()->where('grupo_trabajo_id', $grupo->id)->find($detalleId);
        if (!$detalle) {
            return $this->businessError('MANPOWER_PERSON_NOT_IN_GROUP', 'Integrante no pertenece al grupo');
        }

        DB::transaction(function () use ($detalle, $usuario, $motivo): void {
            $this->markDetalleRetired($detalle, $usuario, $motivo);
            $this->transportePlanningService->retireActivePassengerForDetail($usuario, $detalle->id);
        });

        return [
            'ok' => true,
            'grupo' => $this->loadGrupo($grupo->fresh()),
        ];
    }

    public function reubicarDetalle(Usuario $usuario, GrupoTrabajo $origen, string $detalleId, string $grupoDestinoId, string $motivo): array
    {
        if (!PermissionMatrix::userCanDirect($usuario, 'man_power', 'asignar') || !$this->policy->manageGrupo($usuario, $origen)) {
            return $this->forbidden('MANPOWER_FORBIDDEN');
        }

        $destino = GrupoTrabajo::query()->with(['rqMina'])->find($grupoDestinoId);
        if (!$destino || !$this->policy->manageGrupo($usuario, $destino)) {
            return $this->businessError('MANPOWER_DESTINATION_GROUP_NOT_FOUND', 'Grupo destino no encontrado o sin acceso');
        }

        if ($origen->rq_mina_id !== $destino->rq_mina_id || !$origen->fecha->isSameDay($destino->fecha)) {
            return $this->businessError('MANPOWER_RELOCATE_CONTEXT_MISMATCH', 'La reubicacion debe ser en la misma parada y fecha');
        }

        $minaId = (string) optional($origen->rqMina)->mina_id;
        if ($this->groupHasClosedAttendance($origen) || $this->groupHasClosedAttendance($destino) || $this->hasAsistenciaIniciada($minaId, $origen->fecha->toDateString())) {
            return $this->businessError('MANPOWER_ASSISTENCIA_LOCKED', 'No se puede reubicar con asistencia cerrada');
        }

        try {
            DB::transaction(function () use ($usuario, $origen, $destino, $detalleId, $motivo): void {
            $detalle = GrupoTrabajoDetalle::query()
                ->where('grupo_trabajo_id', $origen->id)
                ->lockForUpdate()
                ->findOrFail($detalleId);

            if ($this->hasDistributionConflict([$detalle->personal_id], [$detalle->rq_proserge_detalle_id], $destino->fecha->toDateString(), (string) $destino->turno, $origen->id)) {
                throw new \RuntimeException('MANPOWER_PERSON_GROUP_CONFLICT');
            }

            $detalle->fill($this->filterColumns('grupo_trabajo_detalle', [
                'estado_distribucion' => GrupoTrabajoDetalle::ESTADO_DISTRIBUCION_REUBICADO,
                'retirado_por_id' => $usuario->id,
                'retirado_at' => now(),
                'motivo_retiro' => $motivo,
            ]));
            $detalle->save();
            $this->transportePlanningService->retireActivePassengerForDetail($usuario, $detalle->id, 'INTEGRANTE_RETIRADO_DE_MAN_POWER');

            GrupoTrabajoDetalle::query()->create($this->filterColumns('grupo_trabajo_detalle', [
                'id' => (string) Str::uuid(),
                'grupo_trabajo_id' => $destino->id,
                'personal_id' => $detalle->personal_id,
                'rq_proserge_detalle_id' => $detalle->rq_proserge_detalle_id,
                'puesto_asignado_snapshot' => $detalle->puesto_asignado_snapshot,
                'posicion_asignacion_snapshot' => $detalle->posicion_asignacion_snapshot,
                'tipo_asignacion_snapshot' => $detalle->tipo_asignacion_snapshot,
                'estado_habilitacion_snapshot' => $detalle->estado_habilitacion_snapshot,
                'estado_distribucion' => GrupoTrabajoDetalle::ESTADO_DISTRIBUCION_ASIGNADO,
                'asignado_por_id' => $usuario->id,
                'asignado_at' => now(),
                'estado_asistencia' => 'AUSENTE',
                'observaciones' => null,
            ]));
            });
        } catch (\RuntimeException $exception) {
            if ($exception->getMessage() === 'MANPOWER_PERSON_GROUP_CONFLICT') {
                return $this->businessError('MANPOWER_PERSON_GROUP_CONFLICT', 'Personal ya pertenece a otro grupo incompatible');
            }

            throw $exception;
        }

        return ['ok' => true, 'grupo' => $this->loadGrupo($destino->fresh())];
    }

    public function copyGrupo(Usuario $usuario, GrupoTrabajo $grupo, array $payload): array
    {
        if (!PermissionMatrix::userCanDirect($usuario, 'man_power', 'duplicar') && !PermissionMatrix::userCanDirect($usuario, 'man_power', 'crear')) {
            return $this->forbidden('MANPOWER_FORBIDDEN');
        }

        if (!$this->policy->manageGrupo($usuario, $grupo)) {
            return $this->forbidden('MANPOWER_FORBIDDEN');
        }

        $grupo->loadMissing(['detalle.rqProsergeDetalle', 'actividades', 'rqMina']);
        $copiarIntegrantes = (bool) ($payload['copiar_integrantes'] ?? true);
        $copiados = [];
        $omitidos = [];
        $assignmentIds = [];

        if ($copiarIntegrantes) {
            foreach ($grupo->detalle as $detalle) {
                if (($detalle->estado_distribucion ?? GrupoTrabajoDetalle::ESTADO_DISTRIBUCION_ASIGNADO) !== GrupoTrabajoDetalle::ESTADO_DISTRIBUCION_ASIGNADO) {
                    $omitidos[] = ['personal_id' => $detalle->personal_id, 'motivo' => 'Integrante no activo'];
                    continue;
                }

                $assignment = $detalle->rqProsergeDetalle;
                if (!$assignment || !$assignment->isActiva() || !$this->assignmentFitsDate($assignment, (string) $payload['fecha_destino'])) {
                    $omitidos[] = ['personal_id' => $detalle->personal_id, 'motivo' => 'Asignacion fuera de rango o inactiva'];
                    continue;
                }

                if ($this->hasDistributionConflict([$assignment->personal_id], [$assignment->id], (string) $payload['fecha_destino'], (string) $payload['turno_destino'])) {
                    $omitidos[] = ['personal_id' => $detalle->personal_id, 'motivo' => 'Ya distribuido en fecha y turno destino'];
                    continue;
                }

                $assignmentIds[] = $assignment->id;
                $copiados[] = $assignment->personal_id;
            }
        }

        $supervisorId = $grupo->supervisor_id && in_array($grupo->supervisor_id, $copiados, true)
            ? $grupo->supervisor_id
            : null;

        $result = $this->createGrupo($usuario, [
            'rq_mina_id' => $grupo->rq_mina_id,
            'rq_proserge_id' => $grupo->rq_proserge_id,
            'rq_mina_plan_id' => $payload['rq_mina_plan_id'] ?? $grupo->rq_mina_plan_id,
            'rq_mina_actividad_grupo_id' => $payload['rq_mina_actividad_grupo_id'] ?? $grupo->rq_mina_actividad_grupo_id,
            'actividad_ids' => !empty($payload['actividad_id'])
                ? [(string) $payload['actividad_id']]
                : ($grupo->relationLoaded('actividades') ? $grupo->actividades->pluck('id')->all() : []),
            'fecha' => $payload['fecha_destino'],
            'turno' => $payload['turno_destino'],
            'supervisor_id' => $supervisorId,
            'servicio' => $payload['servicio'] ?? $grupo->servicio,
            'area' => $payload['area'] ?? $grupo->area,
            'paradero' => $grupo->paradero,
            'paradero_link' => $grupo->paradero_link,
            'horario_salida' => substr((string) $grupo->horario_salida, 0, 5),
            'destino_tipo' => $grupo->destino_tipo ?? $grupo->unidad,
            'destino_id' => $grupo->destino_id,
            'observaciones' => $payload['observaciones'] ?? $grupo->observaciones,
            'observacion_planificacion' => $grupo->observacion_planificacion,
            'justificacion_brecha' => $grupo->justificacion_brecha,
            'rq_proserge_detalle_ids' => $assignmentIds,
        ]);

        $result['copiados'] = $copiados;
        $result['omitidos'] = $omitidos;

        return $result;
    }

    public function copyDayGroups(Usuario $usuario, array $payload): array
    {
        if (!PermissionMatrix::userCanDirect($usuario, 'man_power', 'duplicar') && !PermissionMatrix::userCanDirect($usuario, 'man_power', 'crear')) {
            return $this->forbidden('MANPOWER_FORBIDDEN');
        }

        $rqMina = RQMina::query()->find($payload['rq_mina_id']);
        if (!$rqMina || !$this->policy->manageGrupos($usuario)) {
            return $this->forbidden('MANPOWER_FORBIDDEN');
        }

        try {
            $plan = $this->resolvePlan($rqMina, $payload['rq_mina_plan_id'] ?? null);
            $sourceActivityId = (string) ($payload['rq_mina_actividad_origen_id'] ?? $payload['rq_mina_actividad_id'] ?? '');
            $destinationActivityId = (string) ($payload['rq_mina_actividad_destino_id'] ?? $payload['rq_mina_actividad_id'] ?? $sourceActivityId);
            $sourceActivity = $this->resolveCopyActivity($rqMina, $plan, $sourceActivityId);
            $destinationActivity = $this->resolveCopyActivity($rqMina, $plan, $destinationActivityId);
        } catch (\InvalidArgumentException $exception) {
            return $this->businessError($exception->getMessage(), 'El SAIT de origen o destino no pertenece al plan seleccionado');
        }

        $sourceQuery = GrupoTrabajo::query()
            ->where('rq_mina_id', $rqMina->id)
            ->whereDate('fecha', $payload['fecha_origen'])
            ->where('estado', '!=', GrupoTrabajo::ESTADO_CANCELADO)
            ->orderBy('turno')
            ->orderBy('created_at');

        if (!empty($payload['rq_mina_plan_id'])) {
            $sourceQuery->where(function ($query) use ($payload): void {
                $query->where('rq_mina_plan_id', $payload['rq_mina_plan_id'])
                    ->orWhereNull('rq_mina_plan_id');
            });
        } else {
            $sourceQuery->whereNull('rq_mina_plan_id');
        }

        if ($sourceActivityId !== '') {
            $sourceQuery->whereHas('actividades', fn ($query) => $query->where('rq_mina_actividades.id', $sourceActivityId));
        }

        $sourceGroups = $sourceQuery->get();
        if ($sourceGroups->isEmpty()) {
            return $this->businessError('MANPOWER_DAY_COPY_EMPTY', 'No hay grupos preparados en el dia de origen');
        }

        try {
            return DB::transaction(function () use ($usuario, $payload, $sourceGroups, $plan, $destinationActivityId, $destinationActivity): array {
                $groupsCopied = 0;
                $membersCopied = 0;
                $membersSkipped = 0;
                $groupsSkipped = 0;
                $groupsReplaced = 0;
                $overwriteDestination = (bool) ($payload['sobrescribir_destino'] ?? true);

                if ($overwriteDestination) {
                    $destinationGroupsQuery = GrupoTrabajo::query()
                        ->where('rq_mina_id', $payload['rq_mina_id'])
                        ->whereDate('fecha', $payload['fecha_destino'])
                        ->where('estado', '!=', GrupoTrabajo::ESTADO_CANCELADO);

                    if (!empty($payload['rq_mina_plan_id'])) {
                        $destinationGroupsQuery->where(function ($query) use ($payload): void {
                            $query->where('rq_mina_plan_id', $payload['rq_mina_plan_id'])
                                ->orWhereNull('rq_mina_plan_id');
                        });
                    } else {
                        $destinationGroupsQuery->whereNull('rq_mina_plan_id');
                    }

                    if ($destinationActivityId !== '') {
                        $destinationGroupsQuery->whereHas('actividades', fn ($query) => $query->where('rq_mina_actividades.id', $destinationActivityId));
                    }

                    $destinationGroups = $destinationGroupsQuery->lockForUpdate()->get();

                    foreach ($destinationGroups as $destinationGroup) {
                        $destinationDetails = GrupoTrabajoDetalle::query()
                            ->where('grupo_trabajo_id', $destinationGroup->id)
                            ->lockForUpdate()
                            ->get();

                        foreach ($destinationDetails as $detalle) {
                            if (!$detalle->isDistribucionActiva()) {
                                continue;
                            }

                            $this->markDetalleRetired(
                                $detalle,
                                $usuario,
                                'Reemplazado al pegar los grupos del '.$payload['fecha_origen']
                            );
                            $this->transportePlanningService->retireActivePassengerForDetail(
                                $usuario,
                                $detalle->id,
                                'GRUPO_REEMPLAZADO_AL_PEGAR_DIA'
                            );
                        }

                        $destinationGroup->fill([
                            'estado' => GrupoTrabajo::ESTADO_CANCELADO,
                            'updated_by_id' => $usuario->id,
                        ])->save();
                        $groupsReplaced++;
                    }
                }

                foreach ($sourceGroups as $source) {
                    if (!$overwriteDestination) {
                        $destinationQuery = GrupoTrabajo::query()
                            ->where('rq_mina_id', $source->rq_mina_id)
                            ->whereDate('fecha', $payload['fecha_destino'])
                            ->where('turno', $source->turno)
                            ->where('estado', '!=', GrupoTrabajo::ESTADO_CANCELADO);

                        if ($source->rq_mina_plan_id) {
                            $destinationQuery->where('rq_mina_plan_id', $source->rq_mina_plan_id);
                        } else {
                            $destinationQuery->whereNull('rq_mina_plan_id');
                        }

                        $destinationOperationalGroupId = $destinationActivity?->grupo_id ?: $source->rq_mina_actividad_grupo_id;
                        if ($destinationOperationalGroupId) {
                            $destinationQuery->where('rq_mina_actividad_grupo_id', $destinationOperationalGroupId);
                        } else {
                            $destinationQuery->whereNull('rq_mina_actividad_grupo_id');
                        }

                        if ($destinationActivityId !== '') {
                            $destinationQuery->whereHas('actividades', fn ($query) => $query->where('rq_mina_actividades.id', $destinationActivityId));
                        }

                        $destinationQuery
                            ->where('servicio', $source->servicio)
                            ->where('area', $source->area)
                            ->where('horario_salida', $source->horario_salida);

                        if ($destinationQuery->exists()) {
                            $groupsSkipped++;
                            continue;
                        }
                    }

                    $result = $this->copyGrupo($usuario, $source, [
                        'fecha_destino' => $payload['fecha_destino'],
                        'turno_destino' => $source->turno,
                        'copiar_integrantes' => (bool) ($payload['copiar_integrantes'] ?? true),
                        'rq_mina_plan_id' => $plan?->id,
                        'rq_mina_actividad_grupo_id' => $destinationActivity?->grupo_id,
                        'actividad_id' => $destinationActivityId !== '' ? $destinationActivityId : null,
                        'servicio' => $destinationActivity?->sait ?: $source->servicio,
                        'area' => $destinationActivity?->area ?: $source->area,
                    ]);

                    if (($result['ok'] ?? false) === false) {
                        throw new \RuntimeException((string) ($result['message'] ?? 'No se pudo copiar uno de los grupos'));
                    }

                    $groupsCopied++;
                    $membersCopied += count($result['copiados'] ?? []);
                    $membersSkipped += count($result['omitidos'] ?? []);
                }

                return [
                    'ok' => true,
                    'grupos_copiados' => $groupsCopied,
                    'grupos_reemplazados' => $groupsReplaced,
                    'grupos_omitidos' => $groupsSkipped,
                    'integrantes_copiados' => $membersCopied,
                    'integrantes_omitidos' => $membersSkipped,
                ];
            });
        } catch (\RuntimeException $exception) {
            return $this->businessError('MANPOWER_DAY_COPY_FAILED', $exception->getMessage());
        }
    }

    public function copyDayGroupsToRange(Usuario $usuario, array $payload): array
    {
        if (!PermissionMatrix::userCanDirect($usuario, 'man_power', 'duplicar') && !PermissionMatrix::userCanDirect($usuario, 'man_power', 'crear')) {
            return $this->forbidden('MANPOWER_FORBIDDEN');
        }

        $rqMina = RQMina::query()->find($payload['rq_mina_id']);
        if (!$rqMina || !$this->policy->manageGrupos($usuario)) {
            return $this->forbidden('MANPOWER_FORBIDDEN');
        }

        try {
            $plan = $this->resolvePlan($rqMina, $payload['rq_mina_plan_id'] ?? null);
            $this->resolveCopyActivity($rqMina, $plan, (string) $payload['rq_mina_actividad_id']);
        } catch (\InvalidArgumentException $exception) {
            return $this->businessError($exception->getMessage(), 'El SAIT no pertenece al plan seleccionado');
        }

        $sourceDate = CarbonImmutable::parse($payload['fecha_origen'])->startOfDay();
        $today = CarbonImmutable::today();
        $rangeStart = $sourceDate->addDay();

        if ($rangeStart->lessThan($today)) {
            $rangeStart = $today;
        }

        $paradaEnd = CarbonImmutable::parse($rqMina->fecha_fin ?? $sourceDate)->endOfDay();
        $planEnd = $plan?->fecha_fin
            ? CarbonImmutable::parse($plan->fecha_fin)->endOfDay()
            : $paradaEnd;
        $operationalEnd = $planEnd->lessThan($paradaEnd) ? $planEnd : $paradaEnd;
        $rangeEnd = $payload['alcance'] === 'SEMANA'
            ? $sourceDate->endOfWeek(CarbonInterface::SUNDAY)->endOfDay()
            : $operationalEnd;

        if ($rangeEnd->greaterThan($operationalEnd)) {
            $rangeEnd = $operationalEnd;
        }

        if ($rangeStart->greaterThan($rangeEnd)) {
            return $this->businessError(
                'MANPOWER_RANGE_COPY_EMPTY',
                $payload['alcance'] === 'SEMANA'
                    ? 'No quedan dias futuros de esta semana dentro de la parada'
                    : 'No quedan dias futuros dentro de la parada'
            );
        }

        $dates = [];
        for ($date = $rangeStart; $date->lessThanOrEqualTo($rangeEnd); $date = $date->addDay()) {
            $dates[] = $date->toDateString();
        }

        try {
            return DB::transaction(function () use ($usuario, $payload, $dates): array {
                $totals = [
                    'ok' => true,
                    'alcance' => $payload['alcance'],
                    'fecha_origen' => $payload['fecha_origen'],
                    'fecha_inicio_copia' => $dates[0],
                    'fecha_fin_copia' => $dates[count($dates) - 1],
                    'dias_copiados' => 0,
                    'grupos_copiados' => 0,
                    'grupos_reemplazados' => 0,
                    'grupos_omitidos' => 0,
                    'integrantes_copiados' => 0,
                    'integrantes_omitidos' => 0,
                ];

                foreach ($dates as $destinationDate) {
                    $result = $this->copyDayGroups($usuario, [
                        'rq_mina_id' => $payload['rq_mina_id'],
                        'rq_mina_plan_id' => $payload['rq_mina_plan_id'] ?? null,
                        'rq_mina_actividad_origen_id' => $payload['rq_mina_actividad_id'],
                        'rq_mina_actividad_destino_id' => $payload['rq_mina_actividad_id'],
                        'fecha_origen' => $payload['fecha_origen'],
                        'fecha_destino' => $destinationDate,
                        'copiar_integrantes' => (bool) ($payload['copiar_integrantes'] ?? true),
                        'sobrescribir_destino' => (bool) ($payload['sobrescribir_destino'] ?? true),
                    ]);

                    if (($result['ok'] ?? false) === false) {
                        throw new \RuntimeException((string) ($result['message'] ?? 'No se pudieron copiar los grupos del rango'));
                    }

                    $totals['dias_copiados']++;
                    foreach (['grupos_copiados', 'grupos_reemplazados', 'grupos_omitidos', 'integrantes_copiados', 'integrantes_omitidos'] as $key) {
                        $totals[$key] += (int) ($result[$key] ?? 0);
                    }
                }

                return $totals;
            });
        } catch (\RuntimeException $exception) {
            return $this->businessError('MANPOWER_RANGE_COPY_FAILED', $exception->getMessage());
        }
    }

    public function cancelDayGroups(Usuario $usuario, array $payload): array
    {
        if (!PermissionMatrix::userCanDirectAny($usuario, 'man_power', ['editar', 'actualizar']) || !$this->policy->manageGrupos($usuario)) {
            return $this->forbidden('MANPOWER_FORBIDDEN');
        }

        $rqMina = RQMina::query()->find($payload['rq_mina_id']);
        if (!$rqMina || !$this->policy->canAccessMina($usuario, (string) $rqMina->mina_id)) {
            return $this->forbidden('MINA_SCOPE_FORBIDDEN');
        }

        try {
            $plan = $this->resolvePlan($rqMina, $payload['rq_mina_plan_id'] ?? null);
            $this->resolveCopyActivity($rqMina, $plan, (string) $payload['rq_mina_actividad_id']);
        } catch (\InvalidArgumentException $exception) {
            return $this->businessError($exception->getMessage(), 'El SAIT no pertenece al plan seleccionado');
        }

        return DB::transaction(function () use ($usuario, $payload): array {
            $groupsQuery = GrupoTrabajo::query()
                ->where('rq_mina_id', $payload['rq_mina_id'])
                ->whereDate('fecha', $payload['fecha'])
                ->where('estado', '!=', GrupoTrabajo::ESTADO_CANCELADO)
                ->whereHas('actividades', fn ($query) => $query->where('rq_mina_actividades.id', $payload['rq_mina_actividad_id']));

            if (!empty($payload['rq_mina_plan_id'])) {
                $groupsQuery->where('rq_mina_plan_id', $payload['rq_mina_plan_id']);
            } else {
                $groupsQuery->whereNull('rq_mina_plan_id');
            }

            $groups = $groupsQuery->with('asistencia:id,grupo_trabajo_id,estado')->lockForUpdate()->get();
            if ($groups->isEmpty()) {
                return $this->businessError('MANPOWER_DAY_CANCEL_EMPTY', 'No hay grupos preparados para eliminar en este dia');
            }

            if ($groups->contains(fn (GrupoTrabajo $group): bool => $group->asistencia !== null)) {
                return $this->businessError('MANPOWER_ASSISTENCIA_LOCKED', 'No se pueden eliminar grupos que ya tienen asistencia registrada');
            }

            $membersRetired = 0;
            foreach ($groups as $group) {
                $details = GrupoTrabajoDetalle::query()
                    ->where('grupo_trabajo_id', $group->id)
                    ->lockForUpdate()
                    ->get();

                foreach ($details as $detail) {
                    if (!$detail->isDistribucionActiva()) {
                        continue;
                    }

                    $this->markDetalleRetired($detail, $usuario, 'Grupo cancelado desde la seleccion diaria de Man Power');
                    $this->transportePlanningService->retireActivePassengerForDetail(
                        $usuario,
                        $detail->id,
                        'GRUPO_CANCELADO_DESDE_MAN_POWER'
                    );
                    $membersRetired++;
                }

                $group->fill([
                    'estado' => GrupoTrabajo::ESTADO_CANCELADO,
                    'updated_by_id' => $usuario->id,
                ])->save();
            }

            return [
                'ok' => true,
                'grupos_cancelados' => $groups->count(),
                'integrantes_retirados' => $membersRetired,
            ];
        });
    }

    public function showGrupo(Usuario $usuario, GrupoTrabajo $grupo): ?GrupoTrabajo
    {
        if (!$this->policy->manageGrupo($usuario, $grupo)) {
            return null;
        }

        return $this->loadGrupo($grupo);
    }

    public function listForUser(Usuario $usuario, array $filters): array
    {
        if (!$this->policy->viewParadas($usuario)) {
            return [];
        }

        $query = GrupoTrabajo::query()
            ->with($this->relations())
            ->orderBy('fecha')
            ->orderBy('area')
            ->orderBy('paradero')
            ->orderBy('horario_salida');

        if (!empty($filters['fecha'])) {
            $query->where('fecha', $filters['fecha']);
        }

        if (!empty($filters['rq_mina_id'])) {
            $query->where('rq_mina_id', $filters['rq_mina_id']);
        }

        if (!empty($filters['turno'])) {
            $query->where('turno', strtoupper((string) $filters['turno']));
        }

        if (!empty($filters['area'])) {
            $query->where('area', 'like', '%'.$filters['area'].'%');
        }

        if (!empty($filters['paradero'])) {
            $query->where('paradero', 'like', '%'.$filters['paradero'].'%');
        }

        if (!$this->isPrivileged($usuario)) {
            $scopeMinaIds = $usuario->scopesMina()->pluck('mina_id');
            $query->whereHas('rqMina', fn ($rqQuery) => $rqQuery->whereIn('mina_id', $scopeMinaIds));
        }

        return $query->get()->toArray();
    }

    public function findForUser(Usuario $usuario, string $id): ?array
    {
        $grupo = $this->showGrupo($usuario, GrupoTrabajo::query()->findOrFail($id));

        return $grupo?->toArray();
    }

    public function createForUser(Usuario $usuario, array $payload): array
    {
        return $this->createGrupo($usuario, $payload);
    }

    public function updateForUser(Usuario $usuario, string $id, array $payload): array
    {
        $grupo = GrupoTrabajo::query()->find($id);

        if (!$grupo) {
            return ['success' => false, 'message' => 'Grupo no encontrado'];
        }

        return $this->updateGrupo($usuario, $grupo, $payload);
    }

    public function quitarPersonal(Usuario $usuario, string $id, string $personalId): array
    {
        $grupo = GrupoTrabajo::query()->find($id);

        if (!$grupo) {
            return ['success' => false, 'message' => 'Grupo no encontrado'];
        }

        $result = $this->removePersonal($usuario, $grupo, $personalId);

        return $result['ok'] ?? false
            ? ['success' => true, 'message' => 'Personal removido']
            : ['success' => false, 'message' => $result['message'] ?? 'Error'];
    }

    private function resolveAssignments(string $rqMinaId, string $fecha, array $payload): array
    {
        $assignmentIds = collect($payload['rq_proserge_detalle_ids'] ?? [])
            ->filter()
            ->unique()
            ->values();

        $personalIds = collect($payload['personal_ids'] ?? [])
            ->push($payload['supervisor_id'] ?? null)
            ->filter()
            ->unique()
            ->values();

        if ($assignmentIds->isEmpty() && $personalIds->isEmpty()) {
            return $this->businessError('MANPOWER_NO_PERSONAL', 'Selecciona al menos un integrante');
        }

        $base = RQProsergeDetalle::query()
            ->with(['personal:id,nombre_completo,puesto,dni,numero_documento', 'rqMinaDetalle:id,puesto,compartible_man_power', 'rqProserge:id,rq_mina_id,estado'])
            ->whereHas('rqProserge', fn ($q) => $q->where('rq_mina_id', $rqMinaId))
            ->whereIn('estado', RQProsergeDetalle::ESTADOS_ACTIVOS)
            ->whereDate('fecha_inicio', '<=', $fecha)
            ->whereDate('fecha_fin', '>=', $fecha);

        if (!empty($payload['rq_proserge_id'])) {
            $base->where('rq_proserge_id', $payload['rq_proserge_id']);
        }

        if ($assignmentIds->isNotEmpty()) {
            $items = (clone $base)->whereIn('id', $assignmentIds->all())->get();

            if ($items->count() !== $assignmentIds->count()) {
                return $this->businessError('MANPOWER_ASSIGNMENT_NOT_AVAILABLE', 'Hay asignaciones que no estan disponibles para esta fecha');
            }

            $missingSupervisor = !$items->contains(fn (RQProsergeDetalle $item): bool => $item->personal_id === ($payload['supervisor_id'] ?? null));
            if ($missingSupervisor && !empty($payload['supervisor_id'])) {
                $supervisorAssignment = (clone $base)->where('personal_id', $payload['supervisor_id'])->first();
                if ($supervisorAssignment) {
                    $items->push($supervisorAssignment);
                }
            }

            return ['ok' => true, 'items' => $items->unique('id')->values()];
        }

        if (!empty($payload['supervisor_id'])) {
            $supervisorMatches = (clone $base)->where('personal_id', $payload['supervisor_id'])->count();
            if ($supervisorMatches === 0) {
                return $this->businessError('MANPOWER_INVALID_SUPERVISOR', 'Supervisor no valido para la parada y fecha');
            }
        }

        $items = (clone $base)
            ->whereIn('personal_id', $personalIds->all())
            ->get()
            ->groupBy('personal_id')
            ->map(function (Collection $matches) {
                return $matches->count() === 1 ? $matches->first() : null;
            });

        if ($items->filter()->count() !== $personalIds->count()) {
            return $this->businessError('MANPOWER_PERSON_ASSIGNMENT_AMBIGUOUS', 'Hay personal sin asignacion unica de RQ Proserge para esta fecha');
        }

        return ['ok' => true, 'items' => $items->filter()->values()];
    }

    private function resolveSingleAssignment(GrupoTrabajo $grupo, ?string $personalId, ?string $rqProsergeDetalleId): ?RQProsergeDetalle
    {
        $query = RQProsergeDetalle::query()
            ->with(['personal:id,nombre_completo,puesto,dni,numero_documento', 'rqMinaDetalle:id,puesto,compartible_man_power'])
            ->whereHas('rqProserge', fn ($q) => $q->where('rq_mina_id', $grupo->rq_mina_id))
            ->whereIn('estado', RQProsergeDetalle::ESTADOS_ACTIVOS)
            ->whereDate('fecha_inicio', '<=', $grupo->fecha->toDateString())
            ->whereDate('fecha_fin', '>=', $grupo->fecha->toDateString());

        if ($grupo->rq_proserge_id) {
            $query->where('rq_proserge_id', $grupo->rq_proserge_id);
        }

        if ($rqProsergeDetalleId) {
            return $query->where('id', $rqProsergeDetalleId)->first();
        }

        if (!$personalId) {
            return null;
        }

        $items = $query->where('personal_id', $personalId)->get();

        return $items->count() === 1 ? $items->first() : null;
    }

    private function lockAssignments(array $ids): Collection
    {
        return RQProsergeDetalle::query()
            ->with(['personal:id,nombre_completo,puesto,dni,numero_documento', 'rqMinaDetalle:id,puesto,compartible_man_power'])
            ->whereIn('id', $ids)
            ->lockForUpdate()
            ->get();
    }

    private function syncAssignments(GrupoTrabajo $grupo, Collection $assignmentItems, Usuario $usuario): void
    {
        $desiredIds = $assignmentItems->pluck('id')->all();
        $existing = GrupoTrabajoDetalle::query()
            ->where('grupo_trabajo_id', $grupo->id)
            ->get();

        foreach ($existing as $detalle) {
            $currentId = $detalle->rq_proserge_detalle_id;
            if ($currentId && in_array($currentId, $desiredIds, true)) {
                continue;
            }

            if ($detalle->rq_proserge_detalle_id) {
                $this->markDetalleRetired($detalle, $usuario, 'Actualizacion de grupo');
            } else {
                $detalle->delete();
            }
        }

        foreach ($assignmentItems as $assignment) {
            $exists = GrupoTrabajoDetalle::query()
                ->where('grupo_trabajo_id', $grupo->id)
                ->where('rq_proserge_detalle_id', $assignment->id)
                ->exists();

            if (!$exists) {
                $this->addAssignmentToGrupo($grupo, $assignment, $usuario);
            }
        }
    }

    private function addAssignmentToGrupo(GrupoTrabajo $grupo, RQProsergeDetalle $assignment, Usuario $usuario): void
    {
        $existingRetired = GrupoTrabajoDetalle::query()
            ->where('grupo_trabajo_id', $grupo->id)
            ->where('personal_id', $assignment->personal_id)
            ->first();

        if ($existingRetired) {
            $existingRetired->fill($this->filterColumns('grupo_trabajo_detalle', [
                'rq_proserge_detalle_id' => $assignment->id,
                'puesto_asignado_snapshot' => $assignment->puesto_asignado_snapshot ?: $assignment->puesto_asignado ?: $assignment->personal?->puesto,
                'posicion_asignacion_snapshot' => $assignment->posicion_asignacion,
                'tipo_asignacion_snapshot' => $assignment->tipo_asignacion,
                'estado_habilitacion_snapshot' => $assignment->estado_habilitacion_snapshot,
                'estado_distribucion' => GrupoTrabajoDetalle::ESTADO_DISTRIBUCION_ASIGNADO,
                'asignado_por_id' => $usuario->id,
                'asignado_at' => now(),
                'retirado_por_id' => null,
                'retirado_at' => null,
                'motivo_retiro' => null,
            ]));
            $existingRetired->save();

            return;
        }

        GrupoTrabajoDetalle::query()->create($this->filterColumns('grupo_trabajo_detalle', [
            'id' => (string) Str::uuid(),
            'grupo_trabajo_id' => $grupo->id,
            'personal_id' => $assignment->personal_id,
            'rq_proserge_detalle_id' => $assignment->id,
            'puesto_asignado_snapshot' => $assignment->puesto_asignado_snapshot ?: $assignment->puesto_asignado ?: $assignment->personal?->puesto,
            'posicion_asignacion_snapshot' => $assignment->posicion_asignacion,
            'tipo_asignacion_snapshot' => $assignment->tipo_asignacion,
            'estado_habilitacion_snapshot' => $assignment->estado_habilitacion_snapshot,
            'estado_distribucion' => GrupoTrabajoDetalle::ESTADO_DISTRIBUCION_ASIGNADO,
            'asignado_por_id' => $usuario->id,
            'asignado_at' => now(),
            'estado_asistencia' => 'AUSENTE',
            'observaciones' => null,
        ]));
    }

    private function markDetalleRetired(GrupoTrabajoDetalle $detalle, Usuario $usuario, string $motivo): void
    {
        $detalle->fill($this->filterColumns('grupo_trabajo_detalle', [
            'estado_distribucion' => GrupoTrabajoDetalle::ESTADO_DISTRIBUCION_RETIRADO,
            'retirado_por_id' => $usuario->id,
            'retirado_at' => now(),
            'motivo_retiro' => $motivo,
        ]));
        $detalle->save();
    }

    private function attachActivities(GrupoTrabajo $grupo, array $activityIds, array $quantities): void
    {
        if (!Schema::hasTable('grupo_trabajo_actividades')) {
            return;
        }

        DB::table('grupo_trabajo_actividades')->where('grupo_trabajo_id', $grupo->id)->delete();

        foreach (array_unique($activityIds) as $activityId) {
            DB::table('grupo_trabajo_actividades')->insert([
                'id' => (string) Str::uuid(),
                'grupo_trabajo_id' => $grupo->id,
                'rq_mina_actividad_id' => $activityId,
                'cantidad_planificada_snapshot' => $quantities[$activityId] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function resolvePlan(RQMina $rqMina, ?string $planId): ?RQMinaPlan
    {
        if (!$planId) {
            return null;
        }

        $plan = RQMinaPlan::query()->where('rq_mina_id', $rqMina->id)->find($planId);

        if (!$plan) {
            throw new \InvalidArgumentException('MANPOWER_PLAN_MISMATCH');
        }

        return $plan;
    }

    private function resolveGrupoOperativo(RQMina $rqMina, ?RQMinaPlan $plan, ?string $grupoId): ?RQMinaActividadGrupo
    {
        if (!$grupoId) {
            return null;
        }

        $query = RQMinaActividadGrupo::query()->with(['actividades.turnos'])->where('rq_mina_id', $rqMina->id);

        if ($plan) {
            $hasPlanGroups = RQMinaActividadGrupo::query()
                ->where('rq_mina_id', $rqMina->id)
                ->where('rq_mina_plan_id', $plan->id)
                ->exists();

            if ($hasPlanGroups) {
                $query->where('rq_mina_plan_id', $plan->id);
            } else {
                $query->whereNull('rq_mina_plan_id');
            }
        } else {
            $query->whereNull('rq_mina_plan_id');
        }

        $grupo = $query->find($grupoId);

        if (!$grupo) {
            throw new \InvalidArgumentException('MANPOWER_ACTIVITY_GROUP_MISMATCH');
        }

        return $grupo;
    }

    private function resolveActivityIds(?RQMinaActividadGrupo $grupoOperativo, array $activityIds): array
    {
        if (!$grupoOperativo) {
            return [];
        }

        $validIds = $grupoOperativo->actividades->pluck('id')->all();
        $selected = collect($activityIds)->filter()->unique()->values();

        if ($selected->isEmpty()) {
            return $validIds;
        }

        if ($selected->diff($validIds)->isNotEmpty()) {
            throw new \InvalidArgumentException('MANPOWER_ACTIVITY_MISMATCH');
        }

        return $selected->all();
    }

    private function resolveCopyActivity(RQMina $rqMina, ?RQMinaPlan $plan, string $activityId): ?RQMinaActividad
    {
        if ($activityId === '') {
            return null;
        }

        $activity = RQMinaActividad::query()->with('grupo')->find($activityId);
        if (!$activity || !$activity->grupo || (string) $activity->grupo->rq_mina_id !== (string) $rqMina->id) {
            throw new \InvalidArgumentException('MANPOWER_COPY_ACTIVITY_MISMATCH');
        }

        $grupoOperativo = $this->resolveGrupoOperativo($rqMina, $plan, (string) $activity->grupo_id);
        if (!$grupoOperativo || !$grupoOperativo->actividades->contains('id', $activity->id)) {
            throw new \InvalidArgumentException('MANPOWER_COPY_ACTIVITY_MISMATCH');
        }

        return $activity;
    }

    private function hasDistributionConflict(array $personalIds, array $assignmentIds, string $fecha, string $turno, ?string $excludeGroupId = null): bool
    {
        $personalIds = array_values(array_filter($personalIds));
        $assignmentIds = array_values(array_filter($assignmentIds));

        $buildQuery = function (array $queryPersonalIds, array $queryAssignmentIds) use ($fecha, $excludeGroupId) {
            $query = DB::table('grupo_trabajo_detalle as gtd')
                ->join('grupo_trabajo as gt', 'gt.id', '=', 'gtd.grupo_trabajo_id')
                ->where('gt.fecha', $fecha)
                ->whereNotIn('gt.estado', [GrupoTrabajo::ESTADO_CANCELADO]);

            if ($excludeGroupId) {
                $query->where('gt.id', '!=', $excludeGroupId);
            }

            if (Schema::hasColumn('grupo_trabajo_detalle', 'estado_distribucion')) {
                $query->where(function ($state): void {
                    $state->where('gtd.estado_distribucion', GrupoTrabajoDetalle::ESTADO_DISTRIBUCION_ASIGNADO)
                        ->orWhereNull('gtd.estado_distribucion');
                });
            }

            return $query->where(function ($q) use ($queryPersonalIds, $queryAssignmentIds): void {
                if ($queryPersonalIds !== []) {
                    $q->whereIn('gtd.personal_id', $queryPersonalIds);
                }

                if (Schema::hasColumn('grupo_trabajo_detalle', 'rq_proserge_detalle_id') && $queryAssignmentIds !== []) {
                    if ($queryPersonalIds !== []) {
                        $q->orWhereIn('gtd.rq_proserge_detalle_id', $queryAssignmentIds);
                    } else {
                        $q->whereIn('gtd.rq_proserge_detalle_id', $queryAssignmentIds);
                    }
                }
            });
        };

        if (($personalIds !== [] || $assignmentIds !== [])
            && $buildQuery($personalIds, $assignmentIds)->where('gt.turno', '!=', $turno)->lockForUpdate()->exists()) {
            return true;
        }

        if (Schema::hasColumn('rq_mina_detalle', 'compartible_man_power') && $assignmentIds !== []) {
            $shareableAssignments = RQProsergeDetalle::query()
                ->whereIn('id', $assignmentIds)
                ->whereHas('rqMinaDetalle', fn ($q) => $q->where('compartible_man_power', true))
                ->get(['id', 'personal_id']);

            $assignmentIds = array_values(array_diff($assignmentIds, $shareableAssignments->pluck('id')->all()));
            $personalIds = array_values(array_diff($personalIds, $shareableAssignments->pluck('personal_id')->all()));
        }

        if ($personalIds === [] && $assignmentIds === []) {
            return false;
        }

        return $buildQuery($personalIds, $assignmentIds)
            ->where('gt.turno', $turno)
            ->lockForUpdate()
            ->exists();
    }

    private function resolveDestino(string $tipo, string $destinoId): ?array
    {
        if ($tipo === 'MINA') {
            $item = Mina::query()->find($destinoId);

            return $item ? ['tipo' => 'MINA', 'id' => $item->id, 'nombre' => $item->nombre] : null;
        }

        if ($tipo === 'TALLER') {
            $item = Taller::query()->find($destinoId);

            return $item ? ['tipo' => 'TALLER', 'id' => $item->id, 'nombre' => $item->nombre] : null;
        }

        if ($tipo === 'OFICINA') {
            $item = Oficina::query()->find($destinoId);

            return $item ? ['tipo' => 'OFICINA', 'id' => $item->id, 'nombre' => $item->nombre] : null;
        }

        return null;
    }

    private function hasAsistenciaIniciada(string $minaId, string $fecha): bool
    {
        return DB::table('asistencia_encabezado')
            ->where('mina_id', $minaId)
            ->where('fecha', $fecha)
            ->whereIn('estado', ['REGISTRADO', 'CERRADO', 'ENVIADO', 'FINALIZADO'])
            ->exists();
    }

    private function groupHasClosedAttendance(GrupoTrabajo $grupo): bool
    {
        return DB::table('asistencia_encabezado')
            ->where('grupo_trabajo_id', $grupo->id)
            ->whereIn('estado', ['CERRADO', 'ENVIADO', 'FINALIZADO'])
            ->exists();
    }

    private function dateFitsRqMina(RQMina $rqMina, string $fecha): bool
    {
        return (!$rqMina->fecha_inicio || $rqMina->fecha_inicio->toDateString() <= $fecha)
            && (!$rqMina->fecha_fin || $rqMina->fecha_fin->toDateString() >= $fecha);
    }

    private function dateFitsPlan(RQMinaPlan $plan, string $fecha): bool
    {
        return (!$plan->fecha_inicio || $plan->fecha_inicio->toDateString() <= $fecha)
            && (!$plan->fecha_fin || $plan->fecha_fin->toDateString() >= $fecha);
    }

    private function assignmentFitsDate(RQProsergeDetalle $assignment, string $fecha): bool
    {
        return (!$assignment->fecha_inicio || $assignment->fecha_inicio->toDateString() <= $fecha)
            && (!$assignment->fecha_fin || $assignment->fecha_fin->toDateString() >= $fecha);
    }

    private function loadGrupo(GrupoTrabajo $grupo): GrupoTrabajo
    {
        return $grupo->load($this->relations());
    }

    private function relations(): array
    {
        $relations = [
            'rqMina.mina:id,nombre',
            'rqProserge:id,estado',
            'plan:id,codigo,nombre,estado,fecha_inicio,fecha_fin',
            'grupoOperativo:id,nombre,area_operativa,modulo',
            'supervisor:id,nombre_completo,puesto,dni,numero_documento',
            'detalle.personal:id,nombre_completo,puesto,dni,numero_documento',
            'asistencia:id,grupo_trabajo_id,estado',
        ];

        if (Schema::hasTable('grupo_trabajo_actividades')) {
            $relations[] = 'actividades:id';
        }

        if (Schema::hasColumn('grupo_trabajo_detalle', 'rq_proserge_detalle_id')) {
            $relations[] = 'detalle.rqProsergeDetalle:id,personal_id,puesto_asignado,posicion_asignacion,tipo_asignacion,estado_habilitacion_snapshot,estado';
        }

        if (Schema::hasTable('grupo_trabajo_detalle_actividades')) {
            $relations[] = 'detalle.actividadesPrincipales.actividad:id,sait,area,sector,ait_trabajo';
        }

        return $relations;
    }

    private function filterColumns(string $table, array $data): array
    {
        return collect($data)
            ->filter(fn ($value, string $column): bool => Schema::hasColumn($table, $column))
            ->all();
    }

    private function isPrivileged(Usuario $usuario): bool
    {
        $rol = strtoupper((string) optional($usuario->rol)->nombre);

        return in_array($rol, ['ADMIN', 'GERENTE', 'SUPERADMIN'], true)
            || PermissionMatrix::userCanDirect($usuario, 'man_power', 'administrar');
    }

    private function businessError(string $code, string $message): array
    {
        return [
            'ok' => false,
            'code' => $code,
            'message' => $message,
        ];
    }

    private function forbidden(string $code): array
    {
        return [
            'ok' => false,
            'code' => $code,
            'message' => 'No autorizado',
            'forbidden' => true,
        ];
    }
}
