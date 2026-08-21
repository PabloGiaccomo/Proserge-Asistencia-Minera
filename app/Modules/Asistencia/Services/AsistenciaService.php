<?php

namespace App\Modules\Asistencia\Services;

use App\Models\AsistenciaDetalle;
use App\Models\AsistenciaEncabezado;
use App\Models\GrupoTrabajo;
use App\Models\GrupoTrabajoDetalle;
use App\Models\Usuario;
use App\Modules\Asistencia\Policies\AsistenciaPolicy;
use App\Support\Rbac\PermissionMatrix;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AsistenciaService
{
    public function __construct(
        private readonly AsistenciaPolicy $policy,
        private readonly AsistenciaCierreService $cierreService,
        private readonly ParadaExecutionMetricsService $metricsService,
    ) {
    }

    public function listGrupos(Usuario $usuario, array $filters): ?Collection
    {
        if (!$this->policy->manage($usuario)) {
            return null;
        }

        $query = GrupoTrabajo::query()->with(['rqMina.mina:id,nombre', 'supervisor']);

        if (!empty($filters['fecha'])) {
            $query->where('fecha', $filters['fecha']);
        }

        if (!empty($filters['turno'])) {
            $query->where('turno', strtoupper((string) $filters['turno']));
        }

        if (!empty($filters['mina_id'])) {
            $query->whereHas('rqMina', function ($q) use ($filters): void {
                $q->where('mina_id', $filters['mina_id']);
            });
        }

        if (!empty($filters['destino_tipo'])) {
            $query->where('destino_tipo', $filters['destino_tipo']);
        }

        if (!empty($filters['destino_id'])) {
            $query->where('destino_id', $filters['destino_id']);
        }

        if (!$this->isPrivileged($usuario)) {
            $scopeIds = $usuario->scopesMina()->pluck('mina_id');
            $query->whereHas('rqMina', function ($q) use ($scopeIds): void {
                $q->whereIn('mina_id', $scopeIds);
            });
        }

        return $query->orderByDesc('fecha')->get()->map(function (GrupoTrabajo $grupo): array {
            $asistencia = AsistenciaEncabezado::query()->where('grupo_trabajo_id', $grupo->id)->first();

            return [
                'grupo_id' => $grupo->id,
                'fecha' => optional($grupo->fecha)->toDateString(),
                'turno' => $grupo->turno,
                'estado_grupo' => $grupo->estado,
                'mina_id' => $grupo->rqMina?->mina_id,
                'mina_nombre' => $grupo->rqMina?->mina?->nombre,
                'destino_tipo' => $grupo->destino_tipo ?? $grupo->unidad,
                'destino_id' => $grupo->destino_id,
                'destino_nombre' => $grupo->mina,
                'supervisor' => $grupo->supervisor?->nombre_completo,
                'estado_asistencia' => $asistencia?->estado ?? 'PENDIENTE',
            ];
        });
    }

    public function listMiAsistencia(Usuario $usuario, array $filters): ?Collection
    {
        if (!PermissionMatrix::userCanDirect($usuario, 'mi_asistencia', 'ver')) {
            return null;
        }

        $canViewAll = $this->policy->canViewAllAttendances($usuario);
        if (!$canViewAll && blank($usuario->personal_id)) {
            return collect();
        }

        $query = GrupoTrabajo::query()
            ->with([
                'rqMina.mina:id,nombre',
                'plan:id,codigo,nombre',
                'grupoOperativo:id,nombre,area_operativa,modulo',
                'actividades:id,sait,area,sector',
                'supervisor:id,nombre_completo,dni,numero_documento',
                'detalle.personal:id,nombre_completo,dni,numero_documento,puesto',
                'asistencia.supervisor:id,nombre_completo,dni,numero_documento',
                'asistencia.detalle:id,asistencia_id,grupo_trabajo_detalle_id,trabajador_id,estado,hora_marcado',
            ])
            ->where('estado', '!=', GrupoTrabajo::ESTADO_CANCELADO);

        if (!empty($filters['fecha'])) {
            $query->whereDate('fecha', $filters['fecha']);
        }

        if (!empty($filters['turno'])) {
            $query->where('turno', strtoupper((string) $filters['turno']));
        }

        if ($canViewAll) {
            if (!$this->isPrivileged($usuario)) {
                $scopeIds = $usuario->scopesMina()->pluck('mina_id');
                $query->whereHas('rqMina', fn ($rqQuery) => $rqQuery->whereIn('mina_id', $scopeIds));
            }
        } else {
            $query->where('supervisor_id', $usuario->personal_id);
        }

        return $query
            ->orderBy('fecha')
            ->orderByRaw("CASE WHEN turno = 'DIA' THEN 0 ELSE 1 END")
            ->orderBy('horario_salida')
            ->get()
            ->map(function (GrupoTrabajo $grupo) use ($usuario): array {
                $integrantes = $grupo->detalle
                    ->filter(fn (GrupoTrabajoDetalle $detalle): bool => $detalle->isDistribucionActiva());
                $marcas = $grupo->asistencia?->detalle ?? collect();
                $presentes = $marcas->whereIn('estado', AsistenciaDetalle::ESTADOS_REAL)->count();
                $ausentes = $marcas->where('estado', AsistenciaDetalle::ESTADO_AUSENTE)->count();
                $pendientes = max(0, $integrantes->count() - $marcas->whereNotIn('estado', [AsistenciaDetalle::ESTADO_NO_CORRESPONDE])->count());
                $actividad = $grupo->actividades->first();
                $canRegister = $this->policy->canRegisterGrupo($usuario, $grupo)
                    && (filled($grupo->supervisor_id) || filled($grupo->asistencia?->id) || filled($usuario->personal_id));

                return [
                    'id' => (string) $grupo->id,
                    'fecha' => optional($grupo->fecha)->toDateString(),
                    'turno' => (string) $grupo->turno,
                    'horario' => substr((string) $grupo->horario_salida, 0, 5),
                    'mina' => $grupo->rqMina?->mina?->nombre ?: $grupo->mina ?: 'Sin unidad minera',
                    'servicio' => $grupo->servicio ?: $grupo->nombre_snapshot ?: 'Grupo operativo',
                    'area' => $grupo->area ?: $actividad?->area ?: $grupo->area_snapshot,
                    'sait' => $actividad?->sait ?: $grupo->sait_snapshot,
                    'sector' => $actividad?->sector ?: $grupo->sector_snapshot,
                    'plan' => $grupo->plan?->codigo,
                    'responsable' => $grupo->supervisor?->nombre_completo,
                    'responsable_id' => $grupo->supervisor_id,
                    'responsable_registro' => $grupo->asistencia?->supervisor?->nombre_completo,
                    'responsable_pendiente' => blank($grupo->supervisor_id) && !$grupo->asistencia,
                    'puede_registrar' => $canRegister,
                    'estado_grupo' => (string) $grupo->estado,
                    'estado_asistencia' => $grupo->asistencia?->estado ?? 'PENDIENTE',
                    'total' => $integrantes->count(),
                    'presentes' => $presentes,
                    'ausentes' => $ausentes,
                    'pendientes' => $pendientes,
                ];
            });
    }

    public function getGrupo(Usuario $usuario, string $grupoId): ?GrupoTrabajo
    {
        $grupo = GrupoTrabajo::query()->with([
            'rqMina.mina:id,nombre',
            'plan:id,codigo,nombre',
            'grupoOperativo:id,nombre,area_operativa,modulo',
            'actividades:id,sait,area,sector,ait_trabajo',
            'supervisor',
            'asistencia.supervisor',
            'detalle.personal',
            'detalle.rqProsergeDetalle',
            'detalle.actividadesPrincipales.actividad:id,sait,area,sector,ait_trabajo',
            'asistencia.detalle.trabajador',
            'asistencia.detalle.grupoTrabajoDetalle',
            'asistencia.detalle.rqProsergeDetalle',
            'asistencia.detalle.marcador:id,email,personal_id',
            'asistencia.detalle.marcador.personal:id,nombre_completo',
        ])->find($grupoId);

        if (!$grupo) {
            return null;
        }

        if (!$this->policy->manageGrupo($usuario, $grupo)) {
            return null;
        }

        return $grupo;
    }

    public function marcar(Usuario $usuario, GrupoTrabajo $grupo, array $payload): array
    {
        if (!$this->policy->canRegisterGrupo($usuario, $grupo)) {
            return $this->forbidden();
        }

        if ($grupo->estado === GrupoTrabajo::ESTADO_CANCELADO) {
            return $this->businessError('ASISTENCIA_GROUP_CANCELLED', 'No se puede marcar asistencia de un grupo cancelado');
        }

        $readiness = $this->validateAttendanceResponsible($grupo, $usuario);
        if (($readiness['ok'] ?? false) === false) {
            return $readiness;
        }

        $encabezado = $this->getOrCreateEncabezado($grupo, $usuario);

        if ($encabezado->estado === 'CERRADO') {
            return $this->businessError('ASISTENCIA_ALREADY_CLOSED', 'Asistencia cerrada');
        }

        $detalle = $this->resolveAsistenciaDetalle($encabezado, $grupo, $payload);

        if (($detalle['ok'] ?? false) === false) {
            return $detalle;
        }

        $validation = $this->validateStatePayload($payload);
        if (($validation['ok'] ?? false) === false) {
            return $validation;
        }

        DB::transaction(function () use ($usuario, $detalle, $payload): void {
            $locked = AsistenciaDetalle::query()
                ->where('id', $detalle['detalle']->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->markDetalle($usuario, $locked, $payload, 'MANUAL');
        });

        $this->metricsService->recalculateGrupo($grupo->fresh(), persist: true);

        return ['ok' => true, 'grupo' => $this->getGrupo($usuario, $grupo->id)];
    }

    public function marcarMasivo(Usuario $usuario, GrupoTrabajo $grupo, array $payload): array
    {
        if (!$this->policy->canRegisterGrupo($usuario, $grupo)) {
            return $this->forbidden();
        }

        if ($grupo->estado === GrupoTrabajo::ESTADO_CANCELADO) {
            return $this->businessError('ASISTENCIA_GROUP_CANCELLED', 'No se puede marcar asistencia de un grupo cancelado');
        }

        $readiness = $this->validateAttendanceResponsible($grupo, $usuario);
        if (($readiness['ok'] ?? false) === false) {
            return $readiness;
        }

        $encabezado = $this->getOrCreateEncabezado($grupo, $usuario);

        if ($encabezado->estado === 'CERRADO') {
            return $this->businessError('ASISTENCIA_ALREADY_CLOSED', 'Asistencia cerrada');
        }

        $resolved = $this->resolveMassiveDetalles($encabezado, $grupo, $payload);
        if (($resolved['ok'] ?? false) === false) {
            return $resolved;
        }

        $validation = $this->validateStatePayload($payload);
        if (($validation['ok'] ?? false) === false) {
            return $validation;
        }

        $summary = [
            'actualizados' => 0,
            'omitidos' => 0,
            'errores' => [],
        ];

        DB::transaction(function () use ($usuario, $payload, $resolved, &$summary): void {
            foreach ($resolved['detalles'] as $detalle) {
                if ((string) $detalle->estado === AsistenciaDetalle::ESTADO_NO_CORRESPONDE) {
                    $summary['omitidos']++;
                    continue;
                }

                $this->markDetalle($usuario, $detalle, $payload, 'MASIVO');
                $summary['actualizados']++;
            }
        });

        $this->metricsService->recalculateGrupo($grupo->fresh(), persist: true);

        return ['ok' => true, 'grupo' => $this->getGrupo($usuario, $grupo->id), 'resumen' => $summary];
    }

    public function cerrar(Usuario $usuario, GrupoTrabajo $grupo, array $payload): array
    {
        if (!$this->policy->canCloseGrupo($usuario, $grupo)) {
            return $this->forbidden();
        }

        if ($grupo->estado === GrupoTrabajo::ESTADO_CANCELADO) {
            return $this->businessError('ASISTENCIA_GROUP_CANCELLED', 'No se puede cerrar asistencia de un grupo cancelado');
        }

        $readiness = $this->validateAttendanceResponsible($grupo, $usuario);
        if (($readiness['ok'] ?? false) === false) {
            return $readiness;
        }

        $encabezado = $this->getOrCreateEncabezado($grupo, $usuario);
        $result = $this->cierreService->cerrar($usuario, $grupo, $encabezado, $payload);

        if (($result['ok'] ?? false) === false) {
            return $result;
        }

        $this->metricsService->recalculateGrupo($grupo->fresh(), persist: true);

        return ['ok' => true, 'grupo' => $this->getGrupo($usuario, $grupo->id)];
    }

    public function reabrir(Usuario $usuario, GrupoTrabajo $grupo): array
    {
        if (!PermissionMatrix::userCanDirect($usuario, 'asistencias', 'reabrir') || !$this->policy->manageGrupo($usuario, $grupo)) {
            return $this->forbidden();
        }

        $encabezado = AsistenciaEncabezado::query()->where('grupo_trabajo_id', $grupo->id)->first();

        if (!$encabezado) {
            return $this->businessError('ASISTENCIA_NOT_FOUND', 'Asistencia no existe para este grupo');
        }

        $result = $this->cierreService->reabrir($grupo, $encabezado);

        if (($result['ok'] ?? false) === false) {
            return $result;
        }

        $this->syncPadron($encabezado, $grupo);
        $this->metricsService->recalculateGrupo($grupo->fresh(), persist: true);

        return ['ok' => true, 'grupo' => $this->getGrupo($usuario, $grupo->id)];
    }

    public function sincronizarPadron(Usuario $usuario, GrupoTrabajo $grupo): array
    {
        if (!PermissionMatrix::userCanDirect($usuario, 'asistencias', 'registrar') || !$this->policy->manageGrupo($usuario, $grupo)) {
            return $this->forbidden();
        }

        if ($grupo->estado === GrupoTrabajo::ESTADO_CANCELADO) {
            return $this->businessError('ASISTENCIA_GROUP_CANCELLED', 'No se puede sincronizar asistencia de un grupo cancelado');
        }

        $readiness = $this->validateAttendanceResponsible($grupo, $usuario);
        if (($readiness['ok'] ?? false) === false) {
            return $readiness;
        }

        $encabezado = $this->getOrCreateEncabezado($grupo, $usuario);
        if ($encabezado->estado === 'CERRADO') {
            return $this->businessError('ASISTENCIA_ALREADY_CLOSED', 'Asistencia cerrada');
        }

        $this->syncPadron($encabezado, $grupo);
        $this->metricsService->recalculateGrupo($grupo->fresh(), persist: true);

        return ['ok' => true, 'grupo' => $this->getGrupo($usuario, $grupo->id)];
    }

    public function asignarActividadPrincipal(Usuario $usuario, GrupoTrabajo $grupo, string $detalleId, string $actividadId, ?string $observacion = null): array
    {
        if (!PermissionMatrix::userCanDirectAny($usuario, 'man_power', ['editar', 'actualizar', 'asignar']) || !$this->policy->manageGrupo($usuario, $grupo)) {
            return $this->forbidden();
        }

        $encabezado = AsistenciaEncabezado::query()->where('grupo_trabajo_id', $grupo->id)->first();
        if ($encabezado && $encabezado->estado === 'CERRADO') {
            return $this->businessError('ASISTENCIA_ALREADY_CLOSED', 'No se puede cambiar actividad con asistencia cerrada');
        }

        $activityBelongsToGroup = DB::table('grupo_trabajo_actividades')
            ->where('grupo_trabajo_id', $grupo->id)
            ->where('rq_mina_actividad_id', $actividadId)
            ->exists();

        if (!$activityBelongsToGroup) {
            return $this->businessError('ASISTENCIA_ACTIVITY_NOT_IN_GROUP', 'La actividad no pertenece al grupo');
        }

        $detalle = GrupoTrabajoDetalle::query()
            ->where('grupo_trabajo_id', $grupo->id)
            ->where('id', $detalleId)
            ->first();

        if (!$detalle || !$detalle->isDistribucionActiva()) {
            return $this->businessError('ASISTENCIA_PERSON_NOT_IN_GROUP', 'Distribucion no activa para este grupo');
        }

        DB::transaction(function () use ($detalleId, $actividadId, $observacion): void {
            DB::table('grupo_trabajo_detalle_actividades')
                ->where('grupo_trabajo_detalle_id', $detalleId)
                ->delete();

            DB::table('grupo_trabajo_detalle_actividades')->insert([
                'id' => (string) Str::uuid(),
                'grupo_trabajo_detalle_id' => $detalleId,
                'rq_mina_actividad_id' => $actividadId,
                'es_principal' => 1,
                'observacion' => $observacion,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->metricsService->recalculateGrupo($grupo->fresh(), persist: true);

        return ['ok' => true, 'grupo' => $this->getGrupo($usuario, $grupo->id)];
    }

    public function ejecucionResumen(Usuario $usuario, array $filters): array
    {
        if (!PermissionMatrix::userCanDirect($usuario, 'asistencias', 'ver')) {
            return $this->forbidden();
        }

        $query = \App\Models\ParadaEjecucionResumen::query()
            ->with([
                'rqMina:id,mina_id,area,fecha_inicio,fecha_fin',
                'rqMina.mina:id,nombre',
                'plan:id,codigo,nombre',
                'grupoOperativo:id,nombre,area_operativa,modulo',
                'actividad:id,sait,area,sector,ait_trabajo',
            ]);

        foreach ([
            'rq_mina_id',
            'rq_mina_plan_id',
            'rq_mina_actividad_grupo_id',
            'rq_mina_actividad_id',
            'fecha',
            'turno',
        ] as $field) {
            if (!empty($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }

        if (!$this->isPrivileged($usuario)) {
            $scopeIds = $usuario->scopesMina()->pluck('mina_id');
            $query->whereHas('rqMina', function ($q) use ($scopeIds): void {
                $q->whereIn('mina_id', $scopeIds);
            });
        }

        return ['ok' => true, 'items' => $query->orderBy('fecha')->orderBy('turno')->get()];
    }

    private function getOrCreateEncabezado(GrupoTrabajo $grupo, Usuario $usuario): AsistenciaEncabezado
    {
        return DB::transaction(function () use ($grupo, $usuario): AsistenciaEncabezado {
            $encabezado = AsistenciaEncabezado::query()
                ->where('grupo_trabajo_id', $grupo->id)
                ->lockForUpdate()
                ->first();

            if (!$encabezado) {
                $minaId = (string) optional($grupo->rqMina)->mina_id;

                $encabezado = AsistenciaEncabezado::query()->create([
                    'id' => (string) Str::uuid(),
                    'grupo_trabajo_id' => $grupo->id,
                    'fecha' => $grupo->fecha->toDateString(),
                    'hora_ingreso' => $grupo->horario_salida,
                    'mina_id' => $minaId,
                    'destino_tipo' => $grupo->destino_tipo ?? $grupo->unidad,
                    'destino_id' => $grupo->destino_id,
                    'supervisor_id' => $grupo->supervisor_id ?: $usuario->personal_id,
                    'actividad_realizada' => null,
                    'reporte_suceso' => null,
                    'estado' => 'REGISTRADO',
                ]);
            } elseif ($encabezado->estado !== 'CERRADO'
                && filled($grupo->supervisor_id)
                && (string) $encabezado->supervisor_id !== (string) $grupo->supervisor_id) {
                $encabezado->forceFill(['supervisor_id' => $grupo->supervisor_id])->save();
            }

            $this->syncPadron($encabezado, $grupo);

            return $encabezado->fresh(['detalle']);
        });
    }

    private function syncPadron(AsistenciaEncabezado $encabezado, GrupoTrabajo $grupo): void
    {
        $detalles = GrupoTrabajoDetalle::query()
            ->with(['rqProsergeDetalle'])
            ->where('grupo_trabajo_id', $grupo->id)
            ->get();

        $activeDetails = $detalles->filter(fn (GrupoTrabajoDetalle $detalle): bool => $detalle->isDistribucionActiva());

        foreach ($activeDetails as $detalleGrupo) {
            $asistenciaDetalle = AsistenciaDetalle::query()
                ->where('asistencia_id', $encabezado->id)
                ->where('grupo_trabajo_detalle_id', $detalleGrupo->id)
                ->first();

            if (!$asistenciaDetalle) {
                $asistenciaDetalle = AsistenciaDetalle::query()
                    ->where('asistencia_id', $encabezado->id)
                    ->where('trabajador_id', $detalleGrupo->personal_id)
                    ->whereNull('grupo_trabajo_detalle_id')
                    ->first();
            }

            $data = $this->detalleSnapshot($detalleGrupo, [
                'asistencia_id' => $encabezado->id,
                'trabajador_id' => $detalleGrupo->personal_id,
                'hora_marcado' => '00:00:00',
                'estado' => AsistenciaDetalle::ESTADO_AUSENTE,
                'observaciones' => null,
                'origen_registro' => $asistenciaDetalle?->origen_registro ?: 'SISTEMA',
            ]);

            if ($asistenciaDetalle) {
                $asistenciaDetalle->forceFill($this->filterColumns('asistencia_detalle', $this->detalleSnapshot($detalleGrupo, [])))->save();
            } else {
                AsistenciaDetalle::query()->create($this->filterColumns('asistencia_detalle', array_merge(['id' => (string) Str::uuid()], $data)));
            }
        }

        $inactiveIds = $detalles
            ->reject(fn (GrupoTrabajoDetalle $detalle): bool => $detalle->isDistribucionActiva())
            ->pluck('id')
            ->filter()
            ->values();

        if ($inactiveIds->isNotEmpty() && Schema::hasColumn('asistencia_detalle', 'grupo_trabajo_detalle_id')) {
            AsistenciaDetalle::query()
                ->where('asistencia_id', $encabezado->id)
                ->whereIn('grupo_trabajo_detalle_id', $inactiveIds)
                ->whereIn('estado', [AsistenciaDetalle::ESTADO_AUSENTE, 'PENDIENTE'])
                ->update($this->filterColumns('asistencia_detalle', [
                    'estado' => AsistenciaDetalle::ESTADO_NO_CORRESPONDE,
                    'motivo_estado' => 'Distribucion retirada o reubicada antes del cierre',
                    'origen_registro' => 'SISTEMA',
                ]));
        }

        $this->autoAssignSingleActivity($grupo, $activeDetails);
    }

    private function autoAssignSingleActivity(GrupoTrabajo $grupo, Collection $activeDetails): void
    {
        if (!Schema::hasTable('grupo_trabajo_actividades') || !Schema::hasTable('grupo_trabajo_detalle_actividades')) {
            return;
        }

        $activityIds = DB::table('grupo_trabajo_actividades')
            ->where('grupo_trabajo_id', $grupo->id)
            ->pluck('rq_mina_actividad_id')
            ->filter()
            ->unique()
            ->values();

        if ($activityIds->count() !== 1) {
            return;
        }

        $activityId = (string) $activityIds->first();
        foreach ($activeDetails as $detalle) {
            $exists = DB::table('grupo_trabajo_detalle_actividades')
                ->where('grupo_trabajo_detalle_id', $detalle->id)
                ->where('es_principal', 1)
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('grupo_trabajo_detalle_actividades')->insert([
                'id' => (string) Str::uuid(),
                'grupo_trabajo_detalle_id' => $detalle->id,
                'rq_mina_actividad_id' => $activityId,
                'es_principal' => 1,
                'observacion' => 'Asignado automaticamente por grupo con una sola actividad',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function resolveAsistenciaDetalle(AsistenciaEncabezado $encabezado, GrupoTrabajo $grupo, array $payload): array
    {
        $query = AsistenciaDetalle::query()->where('asistencia_id', $encabezado->id);

        if (!empty($payload['asistencia_detalle_id'])) {
            $detalle = (clone $query)->where('id', $payload['asistencia_detalle_id'])->first();

            return $detalle
                ? ['ok' => true, 'detalle' => $detalle]
                : $this->businessError('ASISTENCIA_DETAIL_NOT_FOUND', 'Detalle de asistencia no pertenece al grupo');
        }

        if (!empty($payload['grupo_trabajo_detalle_id'])) {
            $detalleGrupo = GrupoTrabajoDetalle::query()
                ->where('grupo_trabajo_id', $grupo->id)
                ->where('id', $payload['grupo_trabajo_detalle_id'])
                ->first();

            if (!$detalleGrupo || !$detalleGrupo->isDistribucionActiva()) {
                return $this->businessError('ASISTENCIA_PERSON_NOT_IN_GROUP', 'Distribucion no activa para este grupo');
            }

            $detalle = (clone $query)->where('grupo_trabajo_detalle_id', $detalleGrupo->id)->first();

            return $detalle
                ? ['ok' => true, 'detalle' => $detalle]
                : $this->businessError('ASISTENCIA_DETAIL_NOT_FOUND', 'No existe padron para esta distribucion');
        }

        $personalId = (string) ($payload['personal_id'] ?? '');
        if ($personalId === '') {
            return $this->businessError('ASISTENCIA_IDENTIFIER_REQUIRED', 'Selecciona un trabajador o detalle de asistencia');
        }

        $detalles = (clone $query)->where('trabajador_id', $personalId)->get();
        if ($detalles->count() > 1) {
            return [
                'ok' => false,
                'code' => 'ASISTENCIA_LEGACY_AMBIGUOUS',
                'message' => 'El trabajador tiene mas de una distribucion en esta asistencia. Usa el detalle exacto.',
                'status' => 409,
            ];
        }

        if ($detalles->count() === 1) {
            return ['ok' => true, 'detalle' => $detalles->first()];
        }

        return $this->businessError('ASISTENCIA_PERSON_NOT_IN_GROUP', 'Personal no pertenece al grupo');
    }

    private function resolveMassiveDetalles(AsistenciaEncabezado $encabezado, GrupoTrabajo $grupo, array $payload): array
    {
        $base = AsistenciaDetalle::query()->where('asistencia_id', $encabezado->id);

        if (!empty($payload['asistencia_detalle_ids'])) {
            $ids = collect($payload['asistencia_detalle_ids'])->filter()->values();
            $detalles = (clone $base)->whereIn('id', $ids)->get();

            return $detalles->count() === $ids->count()
                ? ['ok' => true, 'detalles' => $detalles]
                : $this->businessError('ASISTENCIA_DETAIL_NOT_FOUND', 'Hay detalles que no pertenecen al grupo');
        }

        if (!empty($payload['grupo_trabajo_detalle_ids'])) {
            $ids = collect($payload['grupo_trabajo_detalle_ids'])->filter()->values();
            $validIds = GrupoTrabajoDetalle::query()
                ->where('grupo_trabajo_id', $grupo->id)
                ->whereIn('id', $ids)
                ->get()
                ->filter(fn (GrupoTrabajoDetalle $detalle): bool => $detalle->isDistribucionActiva())
                ->pluck('id')
                ->values();

            if ($validIds->count() !== $ids->count()) {
                return $this->businessError('ASISTENCIA_PERSON_NOT_IN_GROUP', 'Hay distribuciones fuera del grupo o no activas');
            }

            return ['ok' => true, 'detalles' => (clone $base)->whereIn('grupo_trabajo_detalle_id', $validIds)->get()];
        }

        $personalIds = collect($payload['personal_ids'] ?? [])->filter()->values();
        if ($personalIds->isEmpty()) {
            return $this->businessError('ASISTENCIA_IDENTIFIER_REQUIRED', 'Selecciona personal para marcar');
        }

        $detalles = (clone $base)->whereIn('trabajador_id', $personalIds)->get();
        if ($detalles->count() !== $personalIds->count()) {
            return $this->businessError('ASISTENCIA_PERSON_NOT_IN_GROUP', 'Existe personal fuera del grupo');
        }

        $duplicated = $detalles->groupBy('trabajador_id')->filter(fn (Collection $items): bool => $items->count() > 1);
        if ($duplicated->isNotEmpty()) {
            return [
                'ok' => false,
                'code' => 'ASISTENCIA_LEGACY_AMBIGUOUS',
                'message' => 'Existe personal con mas de una distribucion. Usa detalles exactos.',
                'status' => 409,
            ];
        }

        return ['ok' => true, 'detalles' => $detalles];
    }

    private function markDetalle(Usuario $usuario, AsistenciaDetalle $detalle, array $payload, string $origen): void
    {
        $estado = strtoupper((string) ($payload['estado'] ?? AsistenciaDetalle::ESTADO_AUSENTE));
        $observacion = trim((string) ($payload['observaciones'] ?? $payload['observacion'] ?? ''));
        $motivo = trim((string) ($payload['motivo_estado'] ?? $payload['motivo'] ?? ''));

        $hora = (string) ($payload['hora_marcado'] ?? now()->format('H:i'));
        if (strlen($hora) === 5) {
            $hora .= ':00';
        }

        $detalle->forceFill($this->filterColumns('asistencia_detalle', [
            'hora_marcado' => $hora,
            'estado' => $estado,
            'observaciones' => $observacion !== '' ? $observacion : null,
            'motivo_estado' => $motivo !== '' ? $motivo : null,
            'marcado_por_id' => $usuario->id,
            'marcado_at' => now(),
            'updated_by' => $usuario->id,
            'origen_registro' => $origen,
        ]))->save();
    }

    private function detalleSnapshot(GrupoTrabajoDetalle $detalleGrupo, array $base): array
    {
        return array_merge($base, [
            'grupo_trabajo_detalle_id' => $detalleGrupo->id,
            'rq_proserge_detalle_id' => $detalleGrupo->rq_proserge_detalle_id,
            'puesto_snapshot' => $detalleGrupo->puesto_asignado_snapshot ?: $detalleGrupo->personal?->puesto,
            'posicion_asignacion_snapshot' => $detalleGrupo->posicion_asignacion_snapshot,
            'tipo_asignacion_snapshot' => $detalleGrupo->tipo_asignacion_snapshot,
            'estado_distribucion_snapshot' => $detalleGrupo->estado_distribucion ?: GrupoTrabajoDetalle::ESTADO_DISTRIBUCION_ASIGNADO,
        ]);
    }

    private function businessError(string $code, string $message): array
    {
        return ['ok' => false, 'code' => $code, 'message' => $message];
    }

    private function validateAttendanceResponsible(GrupoTrabajo $grupo, Usuario $usuario): array
    {
        if (filled($grupo->supervisor_id)
            || filled($usuario->personal_id)
            || AsistenciaEncabezado::query()->where('grupo_trabajo_id', $grupo->id)->exists()) {
            return ['ok' => true];
        }

        return $this->businessError(
            'ASISTENCIA_RESPONSIBLE_REQUIRED',
            'Vincula esta cuenta con un trabajador o asigna un responsable al grupo antes de registrar la asistencia.',
        );
    }

    private function validateStatePayload(array $payload): array
    {
        $estado = strtoupper((string) ($payload['estado'] ?? AsistenciaDetalle::ESTADO_AUSENTE));
        $observacion = trim((string) ($payload['observaciones'] ?? $payload['observacion'] ?? ''));
        $motivo = trim((string) ($payload['motivo_estado'] ?? $payload['motivo'] ?? ''));

        if (!in_array($estado, AsistenciaDetalle::ESTADOS_VALIDOS, true)) {
            return $this->businessError('ASISTENCIA_INVALID_STATUS', 'Estado de asistencia no valido');
        }

        if (in_array($estado, [AsistenciaDetalle::ESTADO_JUSTIFICADO, AsistenciaDetalle::ESTADO_NO_CORRESPONDE], true) && $observacion === '' && $motivo === '') {
            return $this->businessError('ASISTENCIA_MOTIVE_REQUIRED', 'Registra un motivo u observacion para este estado');
        }

        return ['ok' => true];
    }

    private function filterColumns(string $table, array $data): array
    {
        return collect($data)
            ->filter(fn ($value, string $column): bool => Schema::hasColumn($table, $column))
            ->all();
    }

    private function forbidden(): array
    {
        return ['ok' => false, 'code' => 'ASISTENCIA_FORBIDDEN', 'message' => 'No autorizado', 'forbidden' => true];
    }

    private function isPrivileged(Usuario $usuario): bool
    {
        $rol = strtoupper((string) optional($usuario->rol)->nombre);

        return in_array($rol, ['ADMIN', 'GERENTE', 'SUPERADMIN'], true);
    }

    public function listGruposForUser(Usuario $usuario, array $filters): array
    {
        $result = $this->listGrupos($usuario, $filters);
        
        return $result?->toArray() ?? [];
    }

    public function getGrupoForUser(Usuario $usuario, string $grupoId): ?array
    {
        $grupo = $this->getGrupo($usuario, $grupoId);
        
        return $grupo?->toArray();
    }

    public function marcarAsistencia(Usuario $usuario, string $grupoId, array $payload): array
    {
        $grupo = $this->getGrupo($usuario, $grupoId);
        
        if (!$grupo) {
            return ['success' => false, 'message' => 'Grupo no encontrado'];
        }
        
        $result = $this->marcar($usuario, $grupo, $payload);
        
        return $result['ok'] ?? false
            ? ['success' => true, 'message' => 'Asistencia marcada']
            : ['success' => false, 'message' => $result['message'] ?? 'Error'];
    }

    public function marcarMasivoPorGrupoId(Usuario $usuario, string $grupoId, array $payload): array
    {
        $grupo = $this->getGrupo($usuario, $grupoId);
        
        if (!$grupo) {
            return ['success' => false, 'message' => 'Grupo no encontrado'];
        }
        
        $result = $this->marcarMasivo($usuario, $grupo, $payload);
        
        return $result['ok'] ?? false
            ? ['success' => true, 'message' => 'Asistencia marcada']
            : ['success' => false, 'message' => $result['message'] ?? 'Error'];
    }

    public function cerrarAsistencia(Usuario $usuario, string $grupoId): array
    {
        $grupo = $this->getGrupo($usuario, $grupoId);
        
        if (!$grupo) {
            return ['success' => false, 'message' => 'Grupo no encontrado'];
        }
        
        $result = $this->cerrar($usuario, $grupo, []);
        
        return $result['ok'] ?? false
            ? ['success' => true, 'message' => 'Asistencia cerrada']
            : ['success' => false, 'message' => $result['message'] ?? 'Error'];
    }

    public function reabrirAsistencia(Usuario $usuario, string $grupoId): array
    {
        $grupo = $this->getGrupo($usuario, $grupoId);
        
        if (!$grupo) {
            return ['success' => false, 'message' => 'Grupo no encontrado'];
        }
        
        $result = $this->reabrir($usuario, $grupo);
        
        return $result['ok'] ?? false
            ? ['success' => true, 'message' => 'Asistencia reopenida']
            : ['success' => false, 'message' => $result['message'] ?? 'Error'];
    }
}
