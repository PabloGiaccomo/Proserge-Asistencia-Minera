<?php

namespace App\Modules\Transporte\Services;

use App\Models\GrupoTrabajo;
use App\Models\GrupoTrabajoDetalle;
use App\Models\Personal;
use App\Models\RQMina;
use App\Models\RQMinaActividad;
use App\Models\RQMinaActividadGrupo;
use App\Models\RQMinaActividadTransporte;
use App\Models\RQMinaPlan;
use App\Models\TransporteServicio;
use App\Models\TransporteServicioAlcance;
use App\Models\TransporteServicioEvento;
use App\Models\TransporteServicioPasajero;
use App\Models\Usuario;
use App\Models\UsuarioMinaScope;
use App\Support\Rbac\PermissionMatrix;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class TransportePlanningService
{
    public function buildContext(Usuario $usuario, array $filters): array
    {
        $fecha = $this->normalizeDate((string) ($filters['fecha'] ?? now()->addDay()->toDateString()));
        $turno = $this->normalizeTurno((string) ($filters['turno'] ?? 'A')) ?? 'A';
        $rqMinaId = trim((string) ($filters['rq_mina_id'] ?? ''));
        $planId = trim((string) ($filters['rq_mina_plan_id'] ?? $filters['plan_id'] ?? ''));

        $paradas = $this->listParadas($usuario);
        if ($rqMinaId === '') {
            $rqMinaId = (string) ($paradas->first()['id'] ?? '');
        }

        $rqMina = $rqMinaId !== ''
            ? RQMina::query()->with(['mina:id,nombre', 'planes'])->find($rqMinaId)
            : null;

        if (!$rqMina || !$this->canAccessMina($usuario, (string) $rqMina->mina_id)) {
            return $this->emptyContext($paradas, $fecha, $turno);
        }

        $plan = $this->resolvePlan($rqMina, $planId, $fecha);
        $fecha = $this->clampDateToPlan($fecha, $plan);
        $requirements = $this->requirements($rqMina, $plan, $fecha, $turno);
        $groups = $this->manPowerGroups($rqMina, $plan, $fecha, $turno);
        $services = $this->services($rqMina, $plan, $fecha, $turno);
        $passengers = collect($services)->flatMap(fn (array $service): array => $service['pasajeros'])->values();
        $activeDetails = collect($groups)->flatMap(fn (array $group): array => $group['integrantes'])->values();
        $transportedDetailIds = $passengers
            ->where('estado', TransporteServicioPasajero::ESTADO_ASIGNADO)
            ->pluck('grupo_trabajo_detalle_id')
            ->unique();

        return [
            'paradas' => $paradas->values()->all(),
            'selected' => [
                'rq_mina_id' => $rqMina->id,
                'rq_mina_plan_id' => $plan?->id,
                'fecha' => $fecha,
                'turno' => $turno,
            ],
            'rq_mina' => [
                'id' => $rqMina->id,
                'mina_id' => $rqMina->mina_id,
                'mina_nombre' => $rqMina->mina?->nombre,
                'destino_nombre' => $rqMina->destino_nombre ?: $rqMina->area,
                'fecha_inicio' => optional($rqMina->fecha_inicio)->toDateString(),
                'fecha_fin' => optional($rqMina->fecha_fin)->toDateString(),
                'estado' => $rqMina->estado,
            ],
            'plans' => $rqMina->planes->map(fn (RQMinaPlan $item): array => [
                'id' => $item->id,
                'codigo' => $item->codigo,
                'nombre' => $item->nombre,
                'estado' => $item->estado,
                'fecha_inicio' => optional($item->fecha_inicio)->toDateString(),
                'fecha_fin' => optional($item->fecha_fin)->toDateString(),
            ])->values()->all(),
            'plan' => $plan ? [
                'id' => $plan->id,
                'codigo' => $plan->codigo,
                'nombre' => $plan->nombre,
                'estado' => $plan->estado,
                'archivado' => $plan->estado === RQMinaPlan::ESTADO_ARCHIVADO,
            ] : null,
            'requerimientos' => $requirements,
            'grupos_man_power' => $groups,
            'servicios' => $services,
            'pasajeros_pendientes' => $activeDetails
                ->reject(fn (array $item): bool => $transportedDetailIds->contains($item['grupo_trabajo_detalle_id']))
                ->values()
                ->all(),
            'resumen' => $this->summary($requirements, $groups, $services, $passengers, $activeDetails),
        ];
    }

    public function createServicio(Usuario $usuario, array $payload): array
    {
        if (!PermissionMatrix::userCanDirectAny($usuario, 'transportes', ['crear', 'administrar'])) {
            return $this->forbidden('TRANSPORTE_CREATE_FORBIDDEN');
        }

        $normalized = $this->normalizeServicePayload($payload);

        return DB::transaction(function () use ($usuario, $normalized): array {
            $validation = $this->validateServicePayload($usuario, $normalized);
            if (($validation['ok'] ?? false) === false) {
                return $validation;
            }

            $driver = $this->resolveDriver($normalized['conductor_personal_id'] ?? null);
            $service = TransporteServicio::query()->create([
                ...collect($normalized)->except(['alcances'])->all(),
                'id' => (string) Str::uuid(),
                'conductor_nombre_snapshot' => $driver?->nombre_completo,
                'created_by' => $usuario->id,
                'updated_by' => $usuario->id,
            ]);

            $this->syncAlcances($usuario, $service, $normalized['alcances'] ?? []);
            $this->recordEvent($service, 'CREACION', null, $service->estado, $service->toArray(), $usuario, 'Servicio de transporte creado.');

            return ['ok' => true, 'servicio' => $this->loadServicio($service)];
        });
    }

    public function serializeServicio(TransporteServicio $service): array
    {
        return $this->serviceArray($this->loadServicio($service));
    }

    public function updateServicio(Usuario $usuario, TransporteServicio $service, array $payload): array
    {
        if (!PermissionMatrix::userCanDirectAny($usuario, 'transportes', ['editar', 'actualizar', 'administrar'])) {
            return $this->forbidden('TRANSPORTE_UPDATE_FORBIDDEN');
        }

        $normalized = $this->normalizeServicePayload([...$service->toArray(), ...$payload]);

        return DB::transaction(function () use ($usuario, $service, $normalized, $payload): array {
            $service->refresh();
            $validation = $this->validateServicePayload($usuario, $normalized, $service);
            if (($validation['ok'] ?? false) === false) {
                return $validation;
            }

            $previous = $service->only(['placa', 'conductor_personal_id', 'capacidad', 'estado']);
            $driver = $this->resolveDriver($normalized['conductor_personal_id'] ?? null);
            $service->forceFill([
                ...collect($normalized)->except(['alcances'])->all(),
                'conductor_nombre_snapshot' => $driver?->nombre_completo,
                'updated_by' => $usuario->id,
            ])->save();

            if (array_key_exists('alcances', $payload)) {
                $this->syncAlcances($usuario, $service, $normalized['alcances'] ?? []);
            }

            $this->recordEvent($service, 'MODIFICACION', $previous['estado'] ?? null, $service->estado, [
                'anterior' => $previous,
                'nuevo' => $service->fresh()->toArray(),
            ], $usuario, 'Servicio de transporte actualizado.');

            return ['ok' => true, 'servicio' => $this->loadServicio($service)];
        });
    }

    public function syncAlcances(Usuario $usuario, TransporteServicio $service, array $alcances): void
    {
        $rows = collect($alcances)
            ->filter(fn ($item): bool => is_array($item))
            ->map(fn (array $item): array => [
                'rq_mina_actividad_grupo_id' => trim((string) ($item['rq_mina_actividad_grupo_id'] ?? '')) ?: null,
                'rq_mina_actividad_id' => trim((string) ($item['rq_mina_actividad_id'] ?? '')) ?: null,
                'grupo_trabajo_id' => trim((string) ($item['grupo_trabajo_id'] ?? '')) ?: null,
                'sait_snapshot' => trim((string) ($item['sait_snapshot'] ?? '')) ?: null,
            ])
            ->filter(fn (array $item): bool => $this->alcanceHasValue($item))
            ->unique(fn (array $item): string => implode('|', array_map(fn ($value) => (string) $value, $item)))
            ->values();

        TransporteServicioAlcance::query()
            ->where('transporte_servicio_id', $service->id)
            ->delete();

        foreach ($rows as $index => $row) {
            $this->validateAlcanceBelongs($service, $row);
            TransporteServicioAlcance::query()->create([
                'id' => (string) Str::uuid(),
                'transporte_servicio_id' => $service->id,
                ...$row,
                'orden' => $index + 1,
            ]);
        }

        $this->recordEvent($service, 'ALCANCES_SYNC', null, $service->estado, ['alcances' => $rows->all()], $usuario, 'Alcances de transporte actualizados.');
    }

    public function addPasajeros(Usuario $usuario, TransporteServicio $service, array $payload): array
    {
        if (!PermissionMatrix::userCanDirectAny($usuario, 'transportes', ['entregar', 'actualizar', 'administrar'])) {
            return $this->forbidden('TRANSPORTE_PASSENGER_FORBIDDEN');
        }

        return DB::transaction(function () use ($usuario, $service, $payload): array {
            $service = $this->loadServicio($service);
            if ($service->tipo !== TransporteServicio::TIPO_PERSONAL) {
                return $this->businessError('TRANSPORTE_CARGA_NO_PASAJEROS', 'Un servicio de carga no admite pasajeros.');
            }

            $detailIds = collect($payload['grupo_trabajo_detalle_ids'] ?? [])
                ->push($payload['grupo_trabajo_detalle_id'] ?? null)
                ->filter()
                ->unique()
                ->values();

            if (!empty($payload['grupo_trabajo_id'])) {
                $detailIds = $detailIds
                    ->merge($this->candidatePassengers($service, (string) $payload['grupo_trabajo_id'])->pluck('grupo_trabajo_detalle_id'))
                    ->unique()
                    ->values();
            }

            if ($detailIds->isEmpty()) {
                return $this->businessError('TRANSPORTE_NO_PASSENGERS', 'Selecciona al menos un pasajero.');
            }

            $candidates = $this->candidatePassengers($service)
                ->whereIn('grupo_trabajo_detalle_id', $detailIds->all())
                ->values();

            if ($candidates->count() !== $detailIds->count()) {
                return $this->businessError('TRANSPORTE_PASSENGER_CONTEXT_INVALID', 'Hay pasajeros fuera del contexto del servicio o ya asignados al mismo tramo.');
            }

            $available = $this->availableSeats($service);
            if ($available !== null && $candidates->count() > $available) {
                return $this->businessError('TRANSPORTE_CAPACITY_EXCEEDED', 'La cantidad supera la capacidad disponible.', [
                    'capacidad_disponible' => $available,
                    'candidatos' => $candidates->count(),
                    'exceso' => $candidates->count() - $available,
                ]);
            }

            foreach ($candidates as $candidate) {
                TransporteServicioPasajero::query()->create([
                    'id' => (string) Str::uuid(),
                    'transporte_servicio_id' => $service->id,
                    'grupo_trabajo_detalle_id' => $candidate['grupo_trabajo_detalle_id'],
                    'personal_id' => $candidate['personal_id'],
                    'tramo' => $service->tramo,
                    'estado' => TransporteServicioPasajero::ESTADO_ASIGNADO,
                    'asignado_por_id' => $usuario->id,
                    'asignado_at' => now(),
                ]);
            }

            $this->recordEvent($service, 'PASAJEROS_ASIGNADOS', null, $service->estado, ['cantidad' => $candidates->count()], $usuario, 'Pasajeros asignados al transporte.');

            return ['ok' => true, 'servicio' => $this->loadServicio($service)];
        });
    }

    public function retirePasajero(Usuario $usuario, TransporteServicio $service, string $pasajeroId, string $motivo, string $estado = TransporteServicioPasajero::ESTADO_RETIRADO): array
    {
        if (!PermissionMatrix::userCanDirectAny($usuario, 'transportes', ['actualizar', 'recepcionar', 'administrar'])) {
            return $this->forbidden('TRANSPORTE_PASSENGER_RETIRE_FORBIDDEN');
        }

        return DB::transaction(function () use ($usuario, $service, $pasajeroId, $motivo, $estado): array {
            $pasajero = TransporteServicioPasajero::query()
                ->where('transporte_servicio_id', $service->id)
                ->where('id', $pasajeroId)
                ->lockForUpdate()
                ->first();

            if (!$pasajero || $pasajero->estado !== TransporteServicioPasajero::ESTADO_ASIGNADO) {
                return $this->businessError('TRANSPORTE_PASSENGER_NOT_FOUND', 'Pasajero activo no encontrado.');
            }

            $pasajero->forceFill([
                'estado' => $estado,
                'retirado_por_id' => $usuario->id,
                'retirado_at' => now(),
                'motivo_retiro' => $motivo,
            ])->save();

            $this->recordEvent($service, 'PASAJERO_RETIRADO', null, $service->estado, $pasajero->toArray(), $usuario, $motivo);

            return ['ok' => true, 'servicio' => $this->loadServicio($service)];
        });
    }

    public function reubicarPasajero(Usuario $usuario, TransporteServicio $service, string $pasajeroId, string $destinoId, string $motivo): array
    {
        return DB::transaction(function () use ($usuario, $service, $pasajeroId, $destinoId, $motivo): array {
            $destino = TransporteServicio::query()->find($destinoId);
            if (!$destino || $destino->fecha?->toDateString() !== $service->fecha?->toDateString() || $destino->turno !== $service->turno || $destino->tipo !== TransporteServicio::TIPO_PERSONAL) {
                return $this->businessError('TRANSPORTE_RELOCATION_INVALID', 'El destino debe ser un transporte de personal de la misma fecha y turno.');
            }

            $pasajero = TransporteServicioPasajero::query()
                ->where('transporte_servicio_id', $service->id)
                ->where('id', $pasajeroId)
                ->where('estado', TransporteServicioPasajero::ESTADO_ASIGNADO)
                ->lockForUpdate()
                ->first();

            if (!$pasajero) {
                return $this->businessError('TRANSPORTE_PASSENGER_NOT_FOUND', 'Pasajero activo no encontrado.');
            }

            $retired = $this->retirePasajero($usuario, $service, $pasajeroId, $motivo, TransporteServicioPasajero::ESTADO_REUBICADO);
            if (($retired['ok'] ?? false) === false) {
                return $retired;
            }

            return $this->addPasajeros($usuario, $destino, [
                'grupo_trabajo_detalle_id' => $pasajero->grupo_trabajo_detalle_id,
            ]);
        });
    }

    public function copyServicio(Usuario $usuario, TransporteServicio $service, array $payload): array
    {
        if (!PermissionMatrix::userCanDirectAny($usuario, 'transportes', ['crear', 'duplicar', 'administrar'])) {
            return $this->forbidden('TRANSPORTE_COPY_FORBIDDEN');
        }

        $fecha = $this->normalizeDate((string) $payload['fecha']);
        $turno = $this->normalizeTurno((string) $payload['turno']) ?? 'A';
        $copyPlate = (bool) ($payload['copiar_placa'] ?? false);
        $copyDriver = (bool) ($payload['copiar_conductor'] ?? false);

        $data = [
            ...$service->only([
                'rq_mina_id',
                'rq_mina_plan_id',
                'tipo',
                'tramo',
                'transportista',
                'tipo_vehiculo',
                'capacidad',
                'hora_salida',
                'hora_retorno',
                'origen',
                'destino',
                'observaciones',
            ]),
            'fecha' => $fecha,
            'turno' => $turno,
            'estado' => TransporteServicio::ESTADO_BORRADOR,
            'placa' => $copyPlate ? $service->placa : null,
            'conductor_personal_id' => $copyDriver ? $service->conductor_personal_id : null,
            'alcances' => $service->alcances
                ->map(fn (TransporteServicioAlcance $alcance): array => [
                    'rq_mina_actividad_grupo_id' => $alcance->rq_mina_actividad_grupo_id,
                    'rq_mina_actividad_id' => $alcance->rq_mina_actividad_id,
                    'grupo_trabajo_id' => $this->copyableGrupoTrabajoId($alcance, $fecha, $turno),
                    'sait_snapshot' => $alcance->sait_snapshot,
                ])
                ->filter(fn (array $alcance): bool => collect($alcance)->filter()->isNotEmpty())
                ->values()
                ->all(),
        ];

        return $this->createServicio($usuario, $data);
    }

    public function changeEstado(Usuario $usuario, TransporteServicio $service, string $estado, ?string $observacion = null): array
    {
        if (!PermissionMatrix::userCanDirectAny($usuario, 'transportes', ['actualizar', 'entregar', 'recepcionar', 'administrar'])) {
            return $this->forbidden('TRANSPORTE_STATE_FORBIDDEN');
        }

        return DB::transaction(function () use ($usuario, $service, $estado, $observacion): array {
            $service = $this->loadServicio($service);
            $previous = $service->estado;
            $validation = $this->validateStateChange($service, $estado);
            if (($validation['ok'] ?? false) === false) {
                return $validation;
            }

            $service->forceFill([
                'estado' => $estado,
                'updated_by' => $usuario->id,
            ])->save();

            $this->recordEvent($service, 'CAMBIO_ESTADO', $previous, $estado, $this->serviceMetrics($service), $usuario, $observacion);

            return ['ok' => true, 'servicio' => $this->loadServicio($service)];
        });
    }

    public function retireActivePassengerForDetail(Usuario $usuario, string $grupoTrabajoDetalleId, string $motivo = 'INTEGRANTE_RETIRADO_DE_MAN_POWER'): void
    {
        TransporteServicioPasajero::query()
            ->with('servicio')
            ->where('grupo_trabajo_detalle_id', $grupoTrabajoDetalleId)
            ->where('estado', TransporteServicioPasajero::ESTADO_ASIGNADO)
            ->get()
            ->each(function (TransporteServicioPasajero $pasajero) use ($usuario, $motivo): void {
                if ($pasajero->servicio) {
                    $this->retirePasajero($usuario, $pasajero->servicio, $pasajero->id, $motivo);
                }
            });
    }

    public function candidatePassengers(TransporteServicio $service, ?string $grupoTrabajoId = null): Collection
    {
        $service = $this->loadServicio($service);
        $scopeGroupIds = $service->alcances->pluck('grupo_trabajo_id')->filter()->values();

        if ($grupoTrabajoId !== null) {
            $scopeGroupIds = collect([$grupoTrabajoId]);
        }

        if ($scopeGroupIds->isEmpty()) {
            return collect();
        }

        $assignedDetailIds = TransporteServicioPasajero::query()
            ->where('tramo', $service->tramo)
            ->where('estado', TransporteServicioPasajero::ESTADO_ASIGNADO)
            ->pluck('grupo_trabajo_detalle_id');

        return GrupoTrabajoDetalle::query()
            ->with(['personal:id,nombre_completo,dni,numero_documento,puesto', 'grupoTrabajo:id,fecha,turno,servicio,area,paradero,rq_mina_actividad_grupo_id,sait_snapshot'])
            ->whereIn('grupo_trabajo_id', $scopeGroupIds->all())
            ->whereNotIn('id', $assignedDetailIds->all())
            ->where(function ($q): void {
                if (Schema::hasColumn('grupo_trabajo_detalle', 'estado_distribucion')) {
                    $q->where('estado_distribucion', GrupoTrabajoDetalle::ESTADO_DISTRIBUCION_ASIGNADO)
                        ->orWhereNull('estado_distribucion');
                }
            })
            ->get()
            ->filter(fn (GrupoTrabajoDetalle $detalle): bool => $this->detailFitsService($detalle, $service))
            ->map(fn (GrupoTrabajoDetalle $detalle): array => $this->detailPassengerArray($detalle))
            ->values();
    }

    private function listParadas(Usuario $usuario): Collection
    {
        $query = RQMina::query()
            ->with(['mina:id,nombre', 'planes'])
            ->where(function ($q): void {
                $q->whereHas('actividadGrupos.transportes')
                    ->orWhereHas('serviciosTransporte')
                    ->orWhereHas('gruposTrabajo');
            })
            ->orderByDesc('created_at');

        if (!$this->isPrivileged($usuario)) {
            $query->whereIn('mina_id', UsuarioMinaScope::query()
                ->where('usuario_id', $usuario->id)
                ->pluck('mina_id'));
        }

        return $query->get()->map(fn (RQMina $rq): array => [
            'id' => $rq->id,
            'mina_id' => $rq->mina_id,
            'mina_nombre' => $rq->mina?->nombre,
            'destino_nombre' => $rq->destino_nombre ?: $rq->area,
            'fecha_inicio' => optional($rq->fecha_inicio)->toDateString(),
            'fecha_fin' => optional($rq->fecha_fin)->toDateString(),
        ]);
    }

    private function requirements(RQMina $rqMina, ?RQMinaPlan $plan, string $fecha, string $turno): array
    {
        $query = RQMinaActividadTransporte::query()
            ->with(['grupo:id,rq_mina_id,rq_mina_plan_id,nombre,area_operativa,modulo', 'actividad:id,grupo_id,sait,sector,area,ait_trabajo'])
            ->whereHas('grupo', fn ($q) => $q->where('rq_mina_id', $rqMina->id));

        if ($plan) {
            $query->where(function ($q) use ($plan): void {
                $q->where('rq_mina_plan_id', $plan->id)
                    ->orWhereHas('grupo', fn ($grupoQ) => $grupoQ->where('rq_mina_plan_id', $plan->id));
            });
        }

        return $query->orderBy('orden')->get()
            ->filter(function (RQMinaActividadTransporte $item) use ($fecha, $turno): bool {
                $itemTurn = $this->normalizeTurno((string) ($item->turno ?? ''));
                if ($itemTurn && $itemTurn !== $turno) {
                    return false;
                }

                $start = $item->fecha?->toDateString() ?: $item->fecha_inicio?->toDateString();
                $end = $item->fecha?->toDateString() ?: $item->fecha_fin?->toDateString();

                return (!$start || $start <= $fecha) && (!$end || $end >= $fecha);
            })
            ->map(fn (RQMinaActividadTransporte $item): array => [
                'id' => $item->id,
                'rq_mina_plan_id' => $item->rq_mina_plan_id ?: $item->grupo?->rq_mina_plan_id,
                'grupo_operativo_id' => $item->grupo_id,
                'grupo_operativo' => $item->grupo?->nombre,
                'actividad_id' => $item->actividad_id,
                'sait' => $item->actividad?->sait ?: $item->alcance,
                'alcance' => $item->alcance,
                'tipo' => $item->tipo_transporte ?: $this->inferTransportType($item),
                'unidad_carga' => $item->unidad_carga,
                'cantidad_unidades_requeridas' => $item->cantidad_unidades_requeridas,
                'capacidad_requerida' => $item->capacidad_requerida,
                'origen' => $item->origen_snapshot ?: $item->origen,
                'destino' => $item->destino_snapshot,
                'solicitado' => $item->unidades_transporte,
                'estado' => $item->estado_logistico,
                'legacy' => !$item->rq_mina_plan_id || !$item->tipo_transporte,
                'legacy_label' => (!$item->rq_mina_plan_id || !$item->tipo_transporte) ? 'TRANSPORTE LEGACY SIN ESTRUCTURA COMPLETA' : null,
            ])->values()->all();
    }

    private function manPowerGroups(RQMina $rqMina, ?RQMinaPlan $plan, string $fecha, string $turno): array
    {
        $query = GrupoTrabajo::query()
            ->with(['detalle.personal:id,nombre_completo,dni,numero_documento,puesto', 'serviciosTransporte'])
            ->where('rq_mina_id', $rqMina->id)
            ->whereDate('fecha', $fecha)
            ->where(function ($q) use ($turno): void {
                $q->where('turno', $turno)->orWhere('turno', $turno === 'A' ? 'DIA' : 'NOCHE');
            });

        if ($plan) {
            $query->where(function ($q) use ($plan): void {
                $q->where('rq_mina_plan_id', $plan->id)->orWhereNull('rq_mina_plan_id');
            });
        }

        return $query->orderBy('area')->orderBy('paradero')->get()->map(function (GrupoTrabajo $grupo): array {
            $integrantes = $grupo->detalle
                ->filter(fn (GrupoTrabajoDetalle $detalle): bool => $detalle->isDistribucionActiva())
                ->map(fn (GrupoTrabajoDetalle $detalle): array => $this->detailPassengerArray($detalle))
                ->values();

            return [
                'id' => $grupo->id,
                'rq_mina_actividad_grupo_id' => $grupo->rq_mina_actividad_grupo_id,
                'servicio' => $grupo->servicio,
                'area' => $grupo->area,
                'paradero' => $grupo->paradero,
                'turno' => $grupo->turno,
                'integrantes_count' => $integrantes->count(),
                'integrantes' => $integrantes->all(),
                'servicios_transporte_ids' => $grupo->serviciosTransporte->pluck('id')->values()->all(),
            ];
        })->values()->all();
    }

    private function services(RQMina $rqMina, ?RQMinaPlan $plan, string $fecha, string $turno): array
    {
        $query = TransporteServicio::query()
            ->with([
                'conductor:id,nombre_completo,dni,numero_documento,puesto',
                'alcances.grupoOperativo:id,nombre,area_operativa,modulo',
                'alcances.actividad:id,sait,sector,area,ait_trabajo',
                'alcances.grupoManPower:id,servicio,area,paradero',
                'pasajeros.personal:id,nombre_completo,dni,numero_documento,puesto',
                'pasajeros.grupoTrabajoDetalle.grupoTrabajo:id,servicio,area,paradero',
            ])
            ->where('rq_mina_id', $rqMina->id)
            ->whereDate('fecha', $fecha)
            ->where('turno', $turno);

        if ($plan) {
            $query->where(function ($q) use ($plan): void {
                $q->where('rq_mina_plan_id', $plan->id)->orWhereNull('rq_mina_plan_id');
            });
        }

        return $query->orderBy('hora_salida')->orderBy('created_at')->get()
            ->map(fn (TransporteServicio $service): array => $this->serviceArray($service))
            ->values()
            ->all();
    }

    private function serviceArray(TransporteServicio $service): array
    {
        $metrics = $this->serviceMetrics($service);

        return [
            'id' => $service->id,
            'rq_mina_id' => $service->rq_mina_id,
            'rq_mina_plan_id' => $service->rq_mina_plan_id,
            'tipo' => $service->tipo,
            'fecha' => optional($service->fecha)->toDateString(),
            'turno' => $service->turno,
            'tramo' => $service->tramo,
            'transportista' => $service->transportista,
            'tipo_vehiculo' => $service->tipo_vehiculo,
            'placa' => $service->placa,
            'conductor' => $service->conductor_nombre_snapshot ?: $service->conductor?->nombre_completo,
            'capacidad' => $service->capacidad,
            'hora_salida' => $service->hora_salida,
            'hora_retorno' => $service->hora_retorno,
            'origen' => $service->origen,
            'destino' => $service->destino,
            'estado' => $service->estado,
            'observaciones' => $service->observaciones,
            'alcances' => $service->alcances->map(fn (TransporteServicioAlcance $alcance): array => [
                'id' => $alcance->id,
                'grupo_operativo' => $alcance->grupoOperativo?->nombre,
                'actividad' => $alcance->actividad?->sait ?: $alcance->actividad?->ait_trabajo,
                'grupo_man_power' => $alcance->grupoManPower?->servicio,
                'sait_snapshot' => $alcance->sait_snapshot,
            ])->values()->all(),
            'pasajeros' => $service->pasajeros->map(fn (TransporteServicioPasajero $pasajero): array => [
                'id' => $pasajero->id,
                'grupo_trabajo_detalle_id' => $pasajero->grupo_trabajo_detalle_id,
                'personal_id' => $pasajero->personal_id,
                'trabajador' => $pasajero->personal?->nombre_completo,
                'dni' => $pasajero->personal?->dni ?: $pasajero->personal?->numero_documento,
                'puesto' => $pasajero->personal?->puesto,
                'grupo' => $pasajero->grupoTrabajoDetalle?->grupoTrabajo?->servicio,
                'estado' => $pasajero->estado,
            ])->values()->all(),
            'metricas' => $metrics,
        ];
    }

    private function summary(array $requirements, array $groups, array $services, Collection $passengers, Collection $activeDetails): array
    {
        $capacity = collect($services)->where('tipo', TransporteServicio::TIPO_PERSONAL)->sum('capacidad');
        $activePassengers = $passengers->where('estado', TransporteServicioPasajero::ESTADO_ASIGNADO)->count();

        return [
            'unidades_requeridas' => collect($requirements)->sum(fn (array $item): int => (int) ($item['cantidad_unidades_requeridas'] ?? 0)),
            'servicios_creados' => count($services),
            'servicios_confirmados' => collect($services)->where('estado', TransporteServicio::ESTADO_CONFIRMADO)->count(),
            'capacidad_total' => (int) $capacity,
            'personas_distribuidas' => $activeDetails->count(),
            'personas_con_transporte' => $activePassengers,
            'personas_sin_transporte' => max(0, $activeDetails->count() - $activePassengers),
            'asientos_disponibles' => max(0, (int) $capacity - $activePassengers),
            'sobreocupacion' => max(0, $activePassengers - (int) $capacity),
            'servicios_sin_conductor' => collect($services)->where('tipo', TransporteServicio::TIPO_PERSONAL)->filter(fn (array $service): bool => empty($service['conductor']))->count(),
            'servicios_sin_placa' => collect($services)->where('tipo', TransporteServicio::TIPO_PERSONAL)->filter(fn (array $service): bool => empty($service['placa']))->count(),
            'grupos_sin_transporte' => collect($groups)->filter(fn (array $group): bool => empty($group['servicios_transporte_ids']))->count(),
            'transportes_sin_alcances' => collect($services)->filter(fn (array $service): bool => empty($service['alcances']))->count(),
            'unidades_carga_requeridas' => collect($requirements)->where('tipo', TransporteServicio::TIPO_CARGA)->count(),
            'unidades_carga_asignadas' => collect($services)->where('tipo', TransporteServicio::TIPO_CARGA)->count(),
        ];
    }

    private function serviceMetrics(TransporteServicio $service): array
    {
        $activePassengers = $service->pasajeros
            ->where('estado', TransporteServicioPasajero::ESTADO_ASIGNADO)
            ->count();
        $capacity = $service->tipo === TransporteServicio::TIPO_PERSONAL ? $service->capacidad : null;

        return [
            'capacidad' => $capacity,
            'ocupacion' => $service->tipo === TransporteServicio::TIPO_PERSONAL ? $activePassengers : null,
            'asientos_disponibles' => $capacity !== null ? max($capacity - $activePassengers, 0) : null,
            'sobreocupacion' => $capacity !== null ? max($activePassengers - $capacity, 0) : 0,
            'porcentaje_ocupacion' => $capacity && $capacity > 0 ? round(($activePassengers / $capacity) * 100, 1) : null,
        ];
    }

    private function validateServicePayload(Usuario $usuario, array $payload, ?TransporteServicio $current = null): array
    {
        $rqMina = RQMina::query()->find((string) $payload['rq_mina_id']);
        if (!$rqMina || !$this->canAccessMina($usuario, (string) $rqMina->mina_id)) {
            return $this->businessError('TRANSPORTE_RQ_FORBIDDEN', 'Parada no encontrada o sin acceso.');
        }

        if (!$this->dateFits($payload['fecha'], $rqMina->fecha_inicio?->toDateString(), $rqMina->fecha_fin?->toDateString())) {
            return $this->businessError('TRANSPORTE_DATE_OUT_OF_RQ', 'La fecha del servicio debe estar dentro de la parada.');
        }

        if (!empty($payload['rq_mina_plan_id'])) {
            $plan = RQMinaPlan::query()->find((string) $payload['rq_mina_plan_id']);
            if (!$plan || $plan->rq_mina_id !== $rqMina->id) {
                return $this->businessError('TRANSPORTE_PLAN_INVALID', 'El plan no pertenece a la parada.');
            }
            if ($plan->estado === RQMinaPlan::ESTADO_ARCHIVADO) {
                return $this->businessError('TRANSPORTE_PLAN_ARCHIVED', 'El plan archivado es solo consulta.');
            }
            if (!$this->dateFits($payload['fecha'], $plan->fecha_inicio?->toDateString(), $plan->fecha_fin?->toDateString())) {
                return $this->businessError('TRANSPORTE_DATE_OUT_OF_PLAN', 'La fecha del servicio debe estar dentro del plan.');
            }
        }

        if ($payload['estado'] === TransporteServicio::ESTADO_CONFIRMADO) {
            $fake = $current ?: new TransporteServicio();
            $fake->forceFill($payload);
            $fake->setRelation('alcances', collect($payload['alcances'] ?? []));
            $state = $this->validateStateChange($fake, TransporteServicio::ESTADO_CONFIRMADO);
            if (($state['ok'] ?? false) === false) {
                return $state;
            }
        }

        if ($this->hasPlateConflict($payload, $current)) {
            return $this->businessError('TRANSPORTE_PLATE_DUPLICATED', 'La placa ya esta asignada en la misma fecha, turno y tramo.');
        }

        if ($this->hasDriverConflict($payload, $current)) {
            return $this->businessError('TRANSPORTE_DRIVER_DUPLICATED', 'El conductor ya esta asignado en la misma fecha, turno y tramo.');
        }

        return ['ok' => true];
    }

    private function validateStateChange(TransporteServicio $service, string $targetState): array
    {
        if ($targetState !== TransporteServicio::ESTADO_CONFIRMADO) {
            return ['ok' => true];
        }

        if ($service->tipo === TransporteServicio::TIPO_PERSONAL) {
            if (trim((string) $service->placa) === '') {
                return $this->businessError('TRANSPORTE_CONFIRM_REQUIRES_PLATE', 'Para confirmar transporte de personal se requiere placa.');
            }
            if (trim((string) $service->conductor_personal_id) === '' && trim((string) $service->conductor_nombre_snapshot) === '') {
                return $this->businessError('TRANSPORTE_CONFIRM_REQUIRES_DRIVER', 'Para confirmar transporte de personal se requiere conductor.');
            }
            if (!$service->capacidad || $service->capacidad <= 0) {
                return $this->businessError('TRANSPORTE_CONFIRM_REQUIRES_CAPACITY', 'Para confirmar transporte de personal se requiere capacidad.');
            }
            if ($this->serviceMetrics($service)['sobreocupacion'] > 0) {
                return $this->businessError('TRANSPORTE_CONFIRM_OVERBOOKED', 'No se puede confirmar un transporte sobreocupado.');
            }
        }

        if ($service->relationLoaded('alcances') && $service->alcances->count() === 0) {
            return $this->businessError('TRANSPORTE_CONFIRM_REQUIRES_SCOPE', 'Para confirmar se requiere al menos un alcance.');
        }

        return ['ok' => true];
    }

    private function validateAlcanceBelongs(TransporteServicio $service, array $row): void
    {
        if (!empty($row['rq_mina_actividad_grupo_id'])) {
            $group = RQMinaActividadGrupo::query()->find($row['rq_mina_actividad_grupo_id']);
            abort_if(!$group || $group->rq_mina_id !== $service->rq_mina_id, 422, 'El grupo operativo no pertenece a la parada.');
            abort_if($service->rq_mina_plan_id && $group->rq_mina_plan_id && $group->rq_mina_plan_id !== $service->rq_mina_plan_id, 422, 'El grupo operativo no pertenece al plan.');
        }

        if (!empty($row['rq_mina_actividad_id'])) {
            $activity = RQMinaActividad::query()->with('grupo')->find($row['rq_mina_actividad_id']);
            abort_if(!$activity || $activity->grupo?->rq_mina_id !== $service->rq_mina_id, 422, 'La actividad no pertenece a la parada.');
            abort_if($service->rq_mina_plan_id && $activity->grupo?->rq_mina_plan_id && $activity->grupo->rq_mina_plan_id !== $service->rq_mina_plan_id, 422, 'La actividad no pertenece al plan.');
        }

        if (!empty($row['grupo_trabajo_id'])) {
            $group = GrupoTrabajo::query()->find($row['grupo_trabajo_id']);
            abort_if(!$group || $group->rq_mina_id !== $service->rq_mina_id, 422, 'El grupo de Man Power no pertenece a la parada.');
            abort_if(optional($group->fecha)->toDateString() !== optional($service->fecha)->toDateString() || !$this->turnsCompatible((string) $group->turno, (string) $service->turno), 422, 'El grupo de Man Power debe ser de la misma fecha y turno.');
        }
    }

    private function normalizeServicePayload(array $payload): array
    {
        $tipo = strtoupper(trim((string) ($payload['tipo'] ?? TransporteServicio::TIPO_PERSONAL)));
        $estado = strtoupper(trim((string) ($payload['estado'] ?? TransporteServicio::ESTADO_BORRADOR)));
        $tramo = strtoupper(trim((string) ($payload['tramo'] ?? TransporteServicio::TRAMO_IDA)));

        return [
            'rq_mina_id' => (string) ($payload['rq_mina_id'] ?? ''),
            'rq_mina_plan_id' => trim((string) ($payload['rq_mina_plan_id'] ?? '')) ?: null,
            'tipo' => in_array($tipo, TransporteServicio::tipos(), true) ? $tipo : TransporteServicio::TIPO_PERSONAL,
            'fecha' => $this->normalizeDate((string) ($payload['fecha'] ?? now()->toDateString())),
            'turno' => $this->normalizeTurno((string) ($payload['turno'] ?? 'A')) ?? 'A',
            'tramo' => in_array($tramo, TransporteServicio::tramos(), true) ? $tramo : TransporteServicio::TRAMO_IDA,
            'transportista' => trim((string) ($payload['transportista'] ?? '')) ?: null,
            'tipo_vehiculo' => trim((string) ($payload['tipo_vehiculo'] ?? '')) ?: null,
            'placa' => mb_strtoupper(trim((string) ($payload['placa'] ?? '')), 'UTF-8') ?: null,
            'conductor_personal_id' => trim((string) ($payload['conductor_personal_id'] ?? '')) ?: null,
            'capacidad' => is_numeric($payload['capacidad'] ?? null) ? max(0, (int) $payload['capacidad']) : null,
            'hora_salida' => trim((string) ($payload['hora_salida'] ?? '')) ?: null,
            'hora_retorno' => trim((string) ($payload['hora_retorno'] ?? '')) ?: null,
            'origen' => trim((string) ($payload['origen'] ?? '')) ?: null,
            'destino' => trim((string) ($payload['destino'] ?? '')) ?: null,
            'estado' => in_array($estado, TransporteServicio::estados(), true) ? $estado : TransporteServicio::ESTADO_BORRADOR,
            'observaciones' => trim((string) ($payload['observaciones'] ?? '')) ?: null,
            'alcances' => is_array($payload['alcances'] ?? null) ? $payload['alcances'] : [],
        ];
    }

    private function hasPlateConflict(array $payload, ?TransporteServicio $current = null): bool
    {
        if (empty($payload['placa'])) {
            return false;
        }

        return TransporteServicio::query()
            ->where('placa', $payload['placa'])
            ->whereDate('fecha', $payload['fecha'])
            ->where('turno', $payload['turno'])
            ->where('tramo', $payload['tramo'])
            ->where('estado', '!=', TransporteServicio::ESTADO_CANCELADO)
            ->when($current, fn ($q) => $q->where('id', '!=', $current->id))
            ->exists();
    }

    private function hasDriverConflict(array $payload, ?TransporteServicio $current = null): bool
    {
        if (empty($payload['conductor_personal_id'])) {
            return false;
        }

        return TransporteServicio::query()
            ->where('conductor_personal_id', $payload['conductor_personal_id'])
            ->whereDate('fecha', $payload['fecha'])
            ->where('turno', $payload['turno'])
            ->where('tramo', $payload['tramo'])
            ->where('estado', '!=', TransporteServicio::ESTADO_CANCELADO)
            ->when($current, fn ($q) => $q->where('id', '!=', $current->id))
            ->exists();
    }

    private function detailFitsService(GrupoTrabajoDetalle $detalle, TransporteServicio $service): bool
    {
        $group = $detalle->grupoTrabajo;

        return $group
            && optional($group->fecha)->toDateString() === optional($service->fecha)->toDateString()
            && $this->turnsCompatible((string) $group->turno, (string) $service->turno);
    }

    private function detailPassengerArray(GrupoTrabajoDetalle $detalle): array
    {
        return [
            'grupo_trabajo_detalle_id' => $detalle->id,
            'personal_id' => $detalle->personal_id,
            'trabajador' => $detalle->personal?->nombre_completo,
            'dni' => $detalle->personal?->dni ?: $detalle->personal?->numero_documento,
            'puesto' => $detalle->puesto_asignado_snapshot ?: $detalle->personal?->puesto,
            'posicion' => $detalle->posicion_asignacion_snapshot,
            'tipo' => $detalle->tipo_asignacion_snapshot,
            'grupo_trabajo_id' => $detalle->grupo_trabajo_id,
            'grupo' => $detalle->grupoTrabajo?->servicio,
            'paradero' => $detalle->grupoTrabajo?->paradero,
        ];
    }

    private function availableSeats(TransporteServicio $service): ?int
    {
        if ($service->capacidad === null) {
            return null;
        }

        $activePassengers = $service->pasajeros()
            ->where('estado', TransporteServicioPasajero::ESTADO_ASIGNADO)
            ->count();

        return max(0, (int) $service->capacidad - $activePassengers);
    }

    private function loadServicio(TransporteServicio $service): TransporteServicio
    {
        return TransporteServicio::query()->with([
            'conductor',
            'alcances.grupoOperativo',
            'alcances.actividad',
            'alcances.grupoManPower',
            'pasajeros.personal',
            'pasajeros.grupoTrabajoDetalle.grupoTrabajo',
        ])->findOrFail($service->id);
    }

    private function copyableGrupoTrabajoId(TransporteServicioAlcance $alcance, string $fecha, string $turno): ?string
    {
        if (!$alcance->grupo_trabajo_id) {
            return null;
        }

        $grupo = $alcance->grupoManPower;
        if (!$grupo || $grupo->fecha?->toDateString() !== $fecha || $this->normalizeTurno((string) $grupo->turno) !== $turno) {
            return null;
        }

        return $alcance->grupo_trabajo_id;
    }

    private function recordEvent(TransporteServicio $service, string $type, ?string $previous, ?string $next, array $snapshot, Usuario $usuario, ?string $observacion = null): void
    {
        TransporteServicioEvento::query()->create([
            'id' => (string) Str::uuid(),
            'transporte_servicio_id' => $service->id,
            'tipo' => $type,
            'estado_anterior' => $previous,
            'estado_nuevo' => $next,
            'snapshot' => $snapshot,
            'observacion' => $observacion,
            'usuario_id' => $usuario->id,
            'fecha_evento' => now(),
        ]);
    }

    private function resolveDriver(?string $personalId): ?Personal
    {
        return $personalId ? Personal::query()->find($personalId) : null;
    }

    private function resolvePlan(RQMina $rqMina, string $planId, string $fecha): ?RQMinaPlan
    {
        if ($planId !== '') {
            $plan = $rqMina->planes->firstWhere('id', $planId);
            if ($plan) {
                return $plan;
            }
        }

        return $rqMina->planes->first(function (RQMinaPlan $plan) use ($fecha): bool {
            return $plan->estado !== RQMinaPlan::ESTADO_ARCHIVADO
                && $plan->fecha_inicio?->toDateString() <= $fecha
                && $plan->fecha_fin?->toDateString() >= $fecha;
        }) ?? $rqMina->planes->first();
    }

    private function clampDateToPlan(string $fecha, ?RQMinaPlan $plan): string
    {
        if (!$plan) {
            return $fecha;
        }

        if ($plan->fecha_inicio && $fecha < $plan->fecha_inicio->toDateString()) {
            return $plan->fecha_inicio->toDateString();
        }

        if ($plan->fecha_fin && $fecha > $plan->fecha_fin->toDateString()) {
            return $plan->fecha_fin->toDateString();
        }

        return $fecha;
    }

    private function dateFits(string $date, ?string $start, ?string $end): bool
    {
        return (!$start || $start <= $date) && (!$end || $end >= $date);
    }

    private function inferTransportType(RQMinaActividadTransporte $item): string
    {
        $text = mb_strtoupper(trim(($item->unidad_carga ?? '').' '.($item->unidades_transporte ?? '')), 'UTF-8');

        return str_contains($text, 'CARGA') || str_contains($text, 'CAMION')
            ? TransporteServicio::TIPO_CARGA
            : TransporteServicio::TIPO_PERSONAL;
    }

    private function alcanceHasValue(array $item): bool
    {
        return !empty($item['rq_mina_actividad_grupo_id'])
            || !empty($item['rq_mina_actividad_id'])
            || !empty($item['grupo_trabajo_id'])
            || !empty($item['sait_snapshot']);
    }

    private function normalizeDate(string $date): string
    {
        try {
            return CarbonImmutable::parse($date)->toDateString();
        } catch (\Throwable) {
            return now()->toDateString();
        }
    }

    private function normalizeTurno(string $turno): ?string
    {
        $value = strtoupper(trim($turno));

        return match ($value) {
            'A', 'DIA', 'DÍA', 'DAY' => 'A',
            'B', 'NOCHE', 'NIGHT' => 'B',
            default => null,
        };
    }

    private function turnsCompatible(string $groupTurn, string $serviceTurn): bool
    {
        return $this->normalizeTurno($groupTurn) === $this->normalizeTurno($serviceTurn);
    }

    private function canAccessMina(Usuario $usuario, string $minaId): bool
    {
        if ($this->isPrivileged($usuario)) {
            return true;
        }

        return UsuarioMinaScope::query()
            ->where('usuario_id', $usuario->id)
            ->where('mina_id', $minaId)
            ->exists();
    }

    private function isPrivileged(Usuario $usuario): bool
    {
        $rol = strtoupper((string) optional($usuario->rol)->nombre);

        return in_array($rol, ['ADMIN', 'GERENTE', 'SUPERADMIN'], true)
            || PermissionMatrix::userCanDirect($usuario, 'transportes', 'administrar');
    }

    private function emptyContext(Collection $paradas, string $fecha, string $turno): array
    {
        return [
            'paradas' => $paradas->values()->all(),
            'selected' => ['rq_mina_id' => '', 'rq_mina_plan_id' => '', 'fecha' => $fecha, 'turno' => $turno],
            'rq_mina' => null,
            'plans' => [],
            'plan' => null,
            'requerimientos' => [],
            'grupos_man_power' => [],
            'servicios' => [],
            'pasajeros_pendientes' => [],
            'resumen' => [],
        ];
    }

    private function businessError(string $code, string $message, array $detail = []): array
    {
        return ['ok' => false, 'code' => $code, 'message' => $message, 'detail' => $detail];
    }

    private function forbidden(string $code): array
    {
        return ['ok' => false, 'code' => $code, 'message' => 'No autorizado', 'forbidden' => true];
    }
}
