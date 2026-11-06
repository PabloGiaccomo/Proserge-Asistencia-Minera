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
use App\Modules\ManPower\Policies\ManPowerPolicy;
use App\Modules\Transporte\Policies\TransportePolicy;
use App\Support\Rbac\MinaScopeAccess;
use App\Support\Rbac\PermissionMatrix;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TransportePlanningService
{
    public function __construct(
        private readonly TransportePolicy $policy,
        private readonly ManPowerPolicy $manPowerPolicy,
    ) {}

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

        if (!$rqMina || !MinaScopeAccess::allows($usuario, $rqMina->mina_id)) {
            return $this->emptyContext($paradas, $fecha, $turno);
        }

        $plan = $this->resolvePlan($rqMina, $planId, $fecha);
        $fecha = $this->clampDateToPlan($fecha, $plan);
        $requirements = $this->requirements($rqMina, $plan, $fecha, $turno);
        $groups = $this->manPowerGroups($rqMina, $plan, $fecha, $turno);
        $services = $this->services($usuario, $rqMina, $plan, $fecha, $turno);
        $canCreate = $this->policy->canCreate($usuario, $rqMina) && $plan?->estado !== RQMinaPlan::ESTADO_ARCHIVADO;
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
            'can_create' => $canCreate,
            'conductores' => ($canCreate || collect($services)->contains('capabilities.editar', true))
                ? Personal::query()->select('id', 'nombre_completo')
                    ->where('estado', 'ACTIVO')
                    ->whereHas('relacionesMina', fn ($query) => $query->where('mina_id', $rqMina->mina_id)->where('activo', true))
                    ->orderBy('nombre_completo')->get()->toArray()
                : [],
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
        if (!$this->policy->grants($usuario, 'crear')) {
            return $this->forbidden('TRANSPORTE_CREATE_FORBIDDEN');
        }

        $normalized = $this->normalizeServicePayload($payload);
        $requestedScopes = $payload['alcances'] ?? [];

        return DB::transaction(function () use ($usuario, $normalized, $requestedScopes): array {
            $proposed = new TransporteServicio($normalized);
            $validation = $this->validateContext($usuario, $proposed);
            if (!$validation['ok']) {
                return $validation;
            }

            if ($normalized['estado'] !== TransporteServicio::ESTADO_BORRADOR) {
                return $this->businessError('TRANSPORTE_STATE_CHANGE_REQUIRED', 'El servicio debe crearse en borrador.');
            }

            $alcances = $this->prepareAlcances($proposed, $requestedScopes);
            if (!$alcances['ok']) {
                return $alcances;
            }

            $driver = $this->resolveDriver($usuario, $normalized['conductor_personal_id']);
            if ($normalized['conductor_personal_id'] && !$driver) {
                return $this->forbidden('TRANSPORTE_DRIVER_FORBIDDEN');
            }

            $validation = $this->validateResources($normalized);
            if (!$validation['ok']) {
                return $validation;
            }

            $service = TransporteServicio::query()->create([
                ...$normalized,
                'id' => (string) Str::uuid(),
                'conductor_nombre_snapshot' => $driver?->nombre_completo,
                'created_by' => $usuario->id,
                'updated_by' => $usuario->id,
            ]);
            if (!$service->exists) {
                throw new \RuntimeException('No se pudo guardar el servicio de transporte.');
            }

            $this->replaceAlcances($usuario, $service, $alcances['rows']);
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
        return DB::transaction(function () use ($usuario, $service, $payload): array {
            $service = $this->lockServicio($service->id);
            $validation = $this->authorizeMutation($usuario, $service, 'editar', 'TRANSPORTE_UPDATE_FORBIDDEN');
            if (!$validation['ok']) {
                return $validation;
            }

            $normalized = $this->normalizeServicePayload([...$service->attributesToArray(), ...$payload]);
            $proposed = new TransporteServicio($normalized);
            $validation = $this->validateContext($usuario, $proposed);
            if (!$validation['ok']) {
                return $validation;
            }

            if ($proposed->estado !== $service->estado) {
                return $this->businessError('TRANSPORTE_STATE_CHANGE_REQUIRED', 'Usa la accion de cambio de estado del servicio.');
            }

            $driverName = $service->conductor_nombre_snapshot;
            if ($proposed->conductor_personal_id !== $service->conductor_personal_id) {
                $driver = $this->resolveDriver($usuario, $proposed->conductor_personal_id);
                if ($proposed->conductor_personal_id && !$driver) {
                    return $this->forbidden('TRANSPORTE_DRIVER_FORBIDDEN');
                }
                $driverName = $driver?->nombre_completo;
            }

            $validation = $this->validateResources($normalized, $service);
            if (!$validation['ok']) {
                return $validation;
            }

            $replaceScopes = array_key_exists('alcances', $payload);
            $activePassengers = $service->pasajeros()->where('estado', TransporteServicioPasajero::ESTADO_ASIGNADO)->get();
            $existingScopes = $service->alcances()->get();
            $alcances = $this->prepareAlcances($proposed, $replaceScopes ? ($payload['alcances'] ?? []) : $existingScopes->toArray());
            if (!$alcances['ok']) {
                return $alcances;
            }

            $proposed->conductor_nombre_snapshot = $driverName;
            $proposed->setRelation('alcances', $alcances['rows']);
            $proposed->setRelation('pasajeros', $activePassengers);
            $validation = $this->validatePassengerContexts($proposed);
            if (!$validation['ok']) {
                return $validation;
            }

            $validation = $this->validateStateChange($proposed, $proposed->estado);
            if (!$validation['ok']) {
                return $validation;
            }

            $previous = $service->only(['placa', 'conductor_personal_id', 'capacidad', 'estado']);
            $saved = $service->forceFill([
                ...$normalized,
                'conductor_nombre_snapshot' => $driverName,
                'updated_by' => $usuario->id,
            ])->save();
            if (!$saved) {
                throw new \RuntimeException('No se pudo actualizar el servicio de transporte.');
            }

            if ($replaceScopes) {
                $this->replaceAlcances($usuario, $service, $alcances['rows']);
            }

            $this->recordEvent($service, 'MODIFICACION', $previous['estado'] ?? null, $service->estado, [
                'anterior' => $previous,
                'nuevo' => $service->fresh()->toArray(),
            ], $usuario, 'Servicio de transporte actualizado.');

            return ['ok' => true, 'servicio' => $this->loadServicio($service)];
        });
    }

    public function syncAlcances(Usuario $usuario, TransporteServicio $service, array $alcances): array
    {
        return DB::transaction(function () use ($usuario, $service, $alcances): array {
            $service = $this->lockServicio($service->id);
            $validation = $this->authorizeMutation($usuario, $service, 'alcances', 'TRANSPORTE_SCOPES_FORBIDDEN');
            if (!$validation['ok']) {
                return $validation;
            }

            $prepared = $this->prepareAlcances($service, $alcances);
            if (!$prepared['ok']) {
                return $prepared;
            }

            $service->setRelation('alcances', $prepared['rows']);
            $service->load('pasajeros');
            $validation = $this->validatePassengerContexts($service);
            if (!$validation['ok']) {
                return $validation;
            }

            $validation = $this->validateStateChange($service, $service->estado);
            if (!$validation['ok']) {
                return $validation;
            }

            $this->replaceAlcances($usuario, $service, $prepared['rows']);

            return ['ok' => true, 'servicio' => $this->loadServicio($service)];
        });
    }

    private function prepareAlcances(TransporteServicio $service, mixed $alcances): array
    {
        if (!is_array($alcances)) {
            return $this->businessError('TRANSPORTE_SCOPE_INVALID', 'El listado de alcances no es válido.');
        }

        $fields = ['rq_mina_actividad_grupo_id', 'rq_mina_actividad_id', 'grupo_trabajo_id', 'sait_snapshot'];
        foreach ($alcances as $item) {
            if (!is_array($item) || collect($item)->only($fields)->contains(fn ($value): bool => $value !== null && !is_string($value))) {
                return $this->businessError('TRANSPORTE_SCOPE_INVALID', 'Hay una fila de alcance inválida.');
            }
        }

        $rows = collect($alcances)
            ->map(fn (array $item): array => [
                'rq_mina_actividad_grupo_id' => trim((string) ($item['rq_mina_actividad_grupo_id'] ?? '')) ?: null,
                'rq_mina_actividad_id' => trim((string) ($item['rq_mina_actividad_id'] ?? '')) ?: null,
                'grupo_trabajo_id' => trim((string) ($item['grupo_trabajo_id'] ?? '')) ?: null,
                'sait_snapshot' => trim((string) ($item['sait_snapshot'] ?? '')) ?: null,
            ])
            ->filter(fn (array $item): bool => $this->alcanceHasValue($item))
            ->unique(fn (array $item): string => implode('|', array_map(fn ($value) => (string) $value, $item)))
            ->values();

        foreach ($rows as $row) {
            $message = $this->invalidAlcanceMessage($service, $row);
            if ($message !== null) {
                return $this->businessError('TRANSPORTE_SCOPE_INVALID', $message);
            }
        }

        return ['ok' => true, 'rows' => $rows];
    }

    private function replaceAlcances(Usuario $usuario, TransporteServicio $service, Collection $rows): void
    {
        TransporteServicioAlcance::query()
            ->where('transporte_servicio_id', $service->id)
            ->delete();

        foreach ($rows as $index => $row) {
            $scope = TransporteServicioAlcance::query()->create([
                'id' => (string) Str::uuid(),
                'transporte_servicio_id' => $service->id,
                ...$row,
                'orden' => $index + 1,
            ]);
            if (!$scope->exists) {
                throw new \RuntimeException('No se pudo guardar el alcance de transporte.');
            }
        }

        $this->recordEvent($service, 'ALCANCES_SYNC', null, $service->estado, ['alcances' => $rows->all()], $usuario, 'Alcances de transporte actualizados.');
    }

    public function addPasajeros(Usuario $usuario, TransporteServicio $service, array $payload): array
    {
        if (!$this->policy->allows($usuario, 'agregar_pasajeros', $service)) {
            return $this->forbidden('TRANSPORTE_PASSENGER_FORBIDDEN');
        }

        $selectedIds = collect($payload['grupo_trabajo_detalle_ids'] ?? [])
            ->push($payload['grupo_trabajo_detalle_id'] ?? null)
            ->filter()
            ->unique()
            ->values();
        $groupId = $payload['grupo_trabajo_id'] ?? null;
        $groupDetailIds = $groupId
            ? GrupoTrabajoDetalle::query()->where('grupo_trabajo_id', $groupId)->pluck('id')
            : collect();
        $detailIds = $selectedIds->merge($groupDetailIds)->unique();
        $lockIds = $this->relatedDetailIds($detailIds->all(), $service);

        return DB::transaction(function () use ($usuario, $service, $detailIds, $lockIds, $selectedIds, $groupId): array {
            $details = $this->lockDetails($lockIds)->only($detailIds->all())->keyBy('id');
            $lockedService = $this->lockServicio($service->id);
            $dateChanged = !$service->fecha->isSameDay($lockedService->fecha);
            $service = $lockedService;
            $validation = $this->authorizeMutation($usuario, $service, 'agregar_pasajeros', 'TRANSPORTE_PASSENGER_FORBIDDEN');
            if (!$validation['ok']) {
                return $validation;
            }
            if ($dateChanged) {
                return $this->invalidPassengerContext();
            }

            if ($service->tipo !== TransporteServicio::TIPO_PERSONAL) {
                return $this->businessError('TRANSPORTE_CARGA_NO_PASAJEROS', 'Un servicio de carga no admite pasajeros.');
            }

            $groupIds = $service->alcances()->whereNotNull('grupo_trabajo_id')->pluck('grupo_trabajo_id');
            $assigned = $this->assignedPeople($details->pluck('personal_id')->unique()->all(), $service);
            $details->load('grupoTrabajo');
            $candidates = $details
                ->filter(fn (GrupoTrabajoDetalle $detail): bool => ($selectedIds->contains($detail->id) || $detail->grupo_trabajo_id === $groupId)
                    && $this->isEligiblePassenger($detail, $service, $groupIds)
                    && !$assigned->contains($detail->personal_id));

            if ($selectedIds->diff($candidates->keys())->isNotEmpty()
                || $candidates->pluck('personal_id')->duplicates()->isNotEmpty()
                || ($groupId && !$groupIds->contains($groupId))) {
                return $this->invalidPassengerContext();
            }

            if ($candidates->isEmpty()) {
                return $this->businessError('TRANSPORTE_NO_PASSENGERS', 'Selecciona al menos un pasajero.');
            }

            $validation = $this->validateCapacity($service, $candidates->count());
            if (!$validation['ok']) {
                return $validation;
            }

            $this->writePassengers($usuario, $service, $candidates);

            return ['ok' => true, 'servicio' => $this->loadServicio($service)];
        }, 3);
    }

    public function retirePasajero(Usuario $usuario, TransporteServicio $service, string $pasajeroId, string $motivo, string $estado = TransporteServicioPasajero::ESTADO_RETIRADO): array
    {
        return DB::transaction(function () use ($usuario, $service, $pasajeroId, $motivo, $estado): array {
            $service = $this->lockServicio($service->id);
            $validation = $this->authorizeMutation($usuario, $service, 'retirar_pasajero', 'TRANSPORTE_PASSENGER_RETIRE_FORBIDDEN');
            if (!$validation['ok']) {
                return $validation;
            }

            $pasajero = TransporteServicioPasajero::query()
                ->where('transporte_servicio_id', $service->id)
                ->where('id', $pasajeroId)
                ->lockForUpdate()
                ->first();

            if (!$pasajero || $pasajero->estado !== TransporteServicioPasajero::ESTADO_ASIGNADO) {
                return $this->businessError('TRANSPORTE_PASSENGER_NOT_FOUND', 'Pasajero activo no encontrado.');
            }

            $this->writeRetirement($usuario, $service, $pasajero, $motivo, $estado);

            return ['ok' => true, 'servicio' => $this->loadServicio($service)];
        });
    }

    public function reubicarPasajero(Usuario $usuario, TransporteServicio $service, string $pasajeroId, string $destinoId, string $motivo): array
    {
        if (!$this->policy->allows($usuario, 'retirar_pasajero', $service)) {
            return $this->forbidden('TRANSPORTE_PASSENGER_RETIRE_FORBIDDEN');
        }

        $detailId = $service->pasajeros()->whereKey($pasajeroId)->value('grupo_trabajo_detalle_id');
        $lockIds = $detailId ? $this->relatedDetailIds([$detailId], $service) : [];

        return DB::transaction(function () use ($usuario, $service, $pasajeroId, $destinoId, $motivo, $detailId, $lockIds): array {
            $detail = $detailId ? $this->lockDetails($lockIds)->get($detailId) : null;
            $parents = $this->lockServicios([$service->id, $destinoId]);
            $origin = $parents->get($service->id);
            $destination = $parents->get($destinoId);
            if (!$origin || !$destination) {
                return $this->invalidRelocation();
            }

            $validation = $this->authorizeMutation($usuario, $origin, 'retirar_pasajero', 'TRANSPORTE_PASSENGER_RETIRE_FORBIDDEN');
            if (!$validation['ok']) {
                return $validation;
            }

            $validation = $this->authorizeMutation($usuario, $destination, 'agregar_pasajeros', 'TRANSPORTE_PASSENGER_FORBIDDEN');
            if (!$validation['ok']) {
                return $validation;
            }
            if (!$service->fecha->isSameDay($origin->fecha)) {
                return $this->invalidPassengerContext();
            }

            $passenger = $origin->pasajeros()->lockForUpdate()->find($pasajeroId);
            if (!$detail || !$passenger || $passenger->grupo_trabajo_detalle_id !== $detail->id
                || $passenger->estado !== TransporteServicioPasajero::ESTADO_ASIGNADO) {
                return $this->businessError('TRANSPORTE_PASSENGER_NOT_FOUND', 'Pasajero activo no encontrado.');
            }

            $validation = $this->validatePassengerTransfer($origin, $destination, $passenger, $detail);
            if (!$validation['ok']) {
                return $validation;
            }

            $this->writeRetirement($usuario, $origin, $passenger, $motivo, TransporteServicioPasajero::ESTADO_REUBICADO);
            $this->writePassengers($usuario, $destination, collect([$detail]));

            return ['ok' => true, 'servicio' => $this->loadServicio($destination)];
        }, 3);
    }

    private function validatePassengerTransfer(
        TransporteServicio $origin,
        TransporteServicio $destination,
        TransporteServicioPasajero $passenger,
        GrupoTrabajoDetalle $detail,
    ): array {
        if ($origin->id === $destination->id
            || $origin->tipo !== TransporteServicio::TIPO_PERSONAL
            || $destination->tipo !== TransporteServicio::TIPO_PERSONAL
            || !$origin->fecha->isSameDay($destination->fecha)
            || !$this->turnsCompatible($origin->turno, $destination->turno)
            || $passenger->tramo !== $origin->tramo
            || $destination->tramo !== $origin->tramo) {
            return $this->invalidRelocation();
        }

        $groupIds = $destination->alcances()->whereNotNull('grupo_trabajo_id')->pluck('grupo_trabajo_id');
        if ($passenger->personal_id !== $detail->personal_id
            || !$this->isEligiblePassenger($detail, $destination, $groupIds)
            || $this->assignedPeople([$detail->personal_id], $destination, $passenger->id)->isNotEmpty()) {
            return $this->invalidPassengerContext();
        }

        return $this->validateCapacity($destination, 1);
    }

    private function invalidRelocation(): array
    {
        return $this->businessError('TRANSPORTE_RELOCATION_INVALID', 'Elige otro transporte de personal de la misma fecha, turno y tramo.');
    }

    private function invalidPassengerContext(): array
    {
        return $this->businessError('TRANSPORTE_PASSENGER_CONTEXT_INVALID', 'Hay pasajeros fuera del contexto del servicio o ya asignados al mismo tramo.');
    }

    private function validateCapacity(TransporteServicio $service, int $candidates): array
    {
        $available = $this->availableSeats($service);
        if ($available !== null && $candidates > $available) {
            return $this->businessError('TRANSPORTE_CAPACITY_EXCEEDED', 'La cantidad supera la capacidad disponible.', [
                'capacidad_disponible' => $available,
                'candidatos' => $candidates,
                'exceso' => $candidates - $available,
            ]);
        }

        return ['ok' => true];
    }

    private function writePassengers(Usuario $usuario, TransporteServicio $service, Collection $details): void
    {
        foreach ($details as $detail) {
            $passenger = TransporteServicioPasajero::query()->create([
                'id' => (string) Str::uuid(),
                'transporte_servicio_id' => $service->id,
                'grupo_trabajo_detalle_id' => $detail->id,
                'personal_id' => $detail->personal_id,
                'tramo' => $service->tramo,
                'estado' => TransporteServicioPasajero::ESTADO_ASIGNADO,
                'asignado_por_id' => $usuario->id,
                'asignado_at' => now(),
            ]);
            if (!$passenger->exists) {
                throw new \RuntimeException('No se pudo guardar el pasajero de transporte.');
            }
        }

        $this->recordEvent($service, 'PASAJEROS_ASIGNADOS', null, $service->estado, ['cantidad' => $details->count()], $usuario, 'Pasajeros asignados al transporte.');
    }

    private function writeRetirement(Usuario $usuario, TransporteServicio $service, TransporteServicioPasajero $passenger, string $reason, string $state): void
    {
        $saved = $passenger->forceFill([
            'estado' => $state,
            'retirado_por_id' => $usuario->id,
            'retirado_at' => now(),
            'motivo_retiro' => $reason,
        ])->save();
        if (!$saved) {
            throw new \RuntimeException('No se pudo retirar al pasajero de transporte.');
        }

        $this->recordEvent($service, 'PASAJERO_RETIRADO', null, $service->estado, $passenger->toArray(), $usuario, $reason);
    }

    public function copyServicio(Usuario $usuario, TransporteServicio $service, array $payload): array
    {
        return DB::transaction(function () use ($usuario, $service, $payload): array {
            $service = $this->lockServicio($service->id);
            $validation = $this->authorizeMutation($usuario, $service, 'copiar', 'TRANSPORTE_COPY_FORBIDDEN');
            if (!$validation['ok']) {
                return $validation;
            }

            return $this->createServicio($usuario, $this->copyPayload($service, $payload));
        });
    }

    private function copyPayload(TransporteServicio $service, array $payload): array
    {
        $fecha = $this->normalizeDate((string) $payload['fecha']);
        $turno = $this->normalizeTurno((string) $payload['turno']) ?? 'A';
        $copyPlate = (bool) ($payload['copiar_placa'] ?? false);
        $copyDriver = (bool) ($payload['copiar_conductor'] ?? false);

        return [
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
    }

    public function changeEstado(Usuario $usuario, TransporteServicio $service, string $estado, ?string $observacion = null): array
    {
        return DB::transaction(function () use ($usuario, $service, $estado, $observacion): array {
            $service = $this->lockServicio($service->id);
            $validation = $this->authorizeMutation($usuario, $service, 'cambiar_estado', 'TRANSPORTE_STATE_FORBIDDEN');
            if (!$validation['ok']) {
                return $validation;
            }

            if (!in_array($estado, TransporteServicio::estados(), true)) {
                return $this->businessError('TRANSPORTE_STATE_INVALID', 'Estado de servicio invalido.');
            }

            $service->load(['alcances', 'pasajeros']);
            $previous = $service->estado;
            $validation = $this->validateStateChange($service, $estado);
            if (($validation['ok'] ?? false) === false) {
                return $validation;
            }

            $saved = $service->forceFill([
                'estado' => $estado,
                'updated_by' => $usuario->id,
            ])->save();
            if (!$saved) {
                throw new \RuntimeException('No se pudo guardar el estado de transporte.');
            }

            $this->recordEvent($service, 'CAMBIO_ESTADO', $previous, $estado, $this->serviceMetrics($service), $usuario, $observacion);

            return ['ok' => true, 'servicio' => $this->loadServicio($service)];
        });
    }

    /** The calling Man Power operation holds the distribution locks and records the withdrawal in this transaction. */
    public function retireActivePassengersForDetails(Usuario $usuario, Collection $retiredDetails, string $reason = 'INTEGRANTE_RETIRADO_DE_MAN_POWER'): bool
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('El retiro de transporte debe ocurrir dentro de la operacion de Man Power.');
        }

        $actions = match ($reason) {
            'GRUPO_REEMPLAZADO_AL_PEGAR_DIA' => ['crear', 'duplicar'],
            'GRUPO_CANCELADO_DESDE_MAN_POWER' => ['editar', 'actualizar'],
            'INTEGRANTE_RETIRADO_DE_MAN_POWER' => ['asignar'],
            default => [],
        };
        if (!PermissionMatrix::userCanDirectAny($usuario, 'man_power', $actions)) {
            throw new AuthorizationException();
        }

        $details = $this->lockDetails($retiredDetails->pluck('id')->all());
        $history = TransporteServicioPasajero::query()
            ->whereIn('grupo_trabajo_detalle_id', $details->keys()->all())
            ->get(['id', 'transporte_servicio_id', 'estado']);

        $details->load('grupoTrabajo.rqMina');
        foreach ($retiredDetails as $expected) {
            $detail = $details->get($expected->id);
            if (!$detail || !$detail->grupoTrabajo || $detail->grupo_trabajo_id !== $expected->grupo_trabajo_id
                || $detail->isDistribucionActiva() || $detail->retirado_por_id !== $usuario->id
                || !$this->manPowerPolicy->manageGrupo($usuario, $detail->grupoTrabajo)) {
                throw new AuthorizationException();
            }
        }

        $active = $history->where('estado', TransporteServicioPasajero::ESTADO_ASIGNADO);
        $services = $this->lockServicios($active->pluck('transporte_servicio_id')->all());
        $passengers = TransporteServicioPasajero::query()->whereKey($active->modelKeys())
            ->orderBy('id')->lockForUpdate()->get();

        foreach ($passengers as $passenger) {
            if ($passenger->estado !== TransporteServicioPasajero::ESTADO_ASIGNADO) {
                continue;
            }
            $service = $services->get($passenger->transporte_servicio_id);
            $detail = $details->get($passenger->grupo_trabajo_detalle_id);
            if (!$service || !$detail || $service->rq_mina_id !== $detail->grupoTrabajo->rq_mina_id
                || $passenger->personal_id !== $detail->personal_id) {
                throw new \RuntimeException('El pasajero no corresponde al integrante retirado de Man Power.');
            }

            $this->writeRetirement($usuario, $service, $passenger, $reason, TransporteServicioPasajero::ESTADO_RETIRADO);
        }

        return $history->isNotEmpty();
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

        if (!MinaScopeAccess::isPrivileged($usuario)) {
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
                'estado_logistico_label' => RQMinaActividadTransporte::estadoLogisticoLabel($item->estado_logistico),
                'estado_logistico_tone' => RQMinaActividadTransporte::estadoLogisticoTone($item->estado_logistico),
                'atendido' => $item->estaAtendido(),
                'placas_asignadas' => $item->placas_asignadas,
                'origen_label' => RQMinaActividadTransporte::origenLabel($item->origen),
                'recepcion_estado' => $item->recepcion_estado,
                'recepcion_label' => RQMinaActividadTransporte::recepcionEstadoLabel($item->recepcion_estado),
                'fechas_uso' => trim(sprintf(
                    '%s - %s',
                    $item->fecha_inicio?->format('d/m/Y') ?? '',
                    $item->fecha_fin?->format('d/m/Y') ?? ''
                ), ' -'),
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

    private function services(Usuario $usuario, RQMina $rqMina, ?RQMinaPlan $plan, string $fecha, string $turno): array
    {
        $query = TransporteServicio::query()
            ->with([
                'plan:id,estado',
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
            ->map(function (TransporteServicio $service) use ($usuario, $rqMina, $plan): array {
                $service->setRelation('rqMina', $rqMina);

                return [
                    ...$this->serviceArray($service),
                    'capabilities' => $this->capabilities($usuario, $service, $plan),
                    'faltantes_confirmacion' => $this->confirmationRequirements($service),
                ];
            })
            ->values()
            ->all();
    }

    private function capabilities(Usuario $usuario, TransporteServicio $service, ?RQMinaPlan $selectedPlan): array
    {
        $writable = !$this->isReadOnly($service)
            && $service->plan?->estado !== RQMinaPlan::ESTADO_ARCHIVADO
            && $selectedPlan?->estado !== RQMinaPlan::ESTADO_ARCHIVADO;
        $personal = $writable && $service->tipo === TransporteServicio::TIPO_PERSONAL;
        $add = $personal && $this->policy->allows($usuario, 'agregar_pasajeros', $service);
        $retire = $personal && $this->policy->allows($usuario, 'retirar_pasajero', $service);

        return [
            'editar' => $writable && $this->policy->allows($usuario, 'editar', $service),
            'confirmar' => $writable && $service->estado !== TransporteServicio::ESTADO_CONFIRMADO
                && $this->policy->allows($usuario, 'cambiar_estado', $service),
            'agregar_pasajeros' => $add,
            'retirar_pasajero' => $retire,
            'copiar' => $writable && $this->policy->allows($usuario, 'copiar', $service),
            'reubicar' => $add && $retire,
        ];
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
            'conductor_personal_id' => $service->conductor_personal_id,
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
                'rq_mina_actividad_grupo_id' => $alcance->rq_mina_actividad_grupo_id,
                'rq_mina_actividad_id' => $alcance->rq_mina_actividad_id,
                'grupo_trabajo_id' => $alcance->grupo_trabajo_id,
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

    private function lockServicio(string $id): TransporteServicio
    {
        return TransporteServicio::query()->lockForUpdate()->findOrFail($id);
    }

    private function lockServicios(array $ids): Collection
    {
        return TransporteServicio::query()->whereKey(array_unique($ids))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
    }

    private function lockDetails(array $ids): Collection
    {
        return GrupoTrabajoDetalle::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
    }

    private function relatedDetailIds(array $detailIds, TransporteServicio $service): array
    {
        // A shared Man Power position may have several distributions for one worker on the same day.
        $people = GrupoTrabajoDetalle::query()->whereKey($detailIds)->select('personal_id');

        return GrupoTrabajoDetalle::query()
            ->whereIn('personal_id', $people)
            ->whereHas('grupoTrabajo', fn ($query) => $query->whereDate('fecha', $service->fecha->toDateString()))
            ->pluck('id')->merge($detailIds)->unique()->all();
    }

    private function assignedPeople(array $people, TransporteServicio $service, ?string $excludedPassenger = null): Collection
    {
        $turns = $this->normalizeTurno($service->turno) === 'B' ? ['B', 'NOCHE', 'NIGHT'] : ['A', 'DIA', 'DÍA', 'DAY'];

        return TransporteServicioPasajero::query()
            ->whereIn('personal_id', $people)
            ->where('tramo', $service->tramo)
            ->where('estado', TransporteServicioPasajero::ESTADO_ASIGNADO)
            ->whereHas('servicio', fn ($query) => $query->whereDate('fecha', $service->fecha->toDateString())->whereIn('turno', $turns))
            ->when($excludedPassenger, fn ($query) => $query->where('id', '!=', $excludedPassenger))
            ->pluck('personal_id');
    }

    private function authorizeMutation(Usuario $usuario, TransporteServicio $service, string $action, string $code): array
    {
        if (!$this->policy->grants($usuario, $action)) {
            return $this->forbidden($code);
        }

        $service->setRelation('rqMina', RQMina::query()->sharedLock()->find($service->rq_mina_id));
        if (!$this->policy->allows($usuario, $action, $service)) {
            return $this->forbidden($code);
        }
        if ($this->isReadOnly($service)) {
            return $this->businessError('TRANSPORTE_SERVICE_READ_ONLY', 'El servicio cerrado es solo consulta.');
        }

        return $this->validateContext($usuario, $service);
    }

    private function isReadOnly(TransporteServicio $service): bool
    {
        return in_array($service->estado, [TransporteServicio::ESTADO_FINALIZADO, TransporteServicio::ESTADO_CANCELADO, 'ANULADO'], true);
    }

    private function validateContext(Usuario $usuario, TransporteServicio $service): array
    {
        if (!$service->relationLoaded('rqMina')) {
            $service->setRelation('rqMina', RQMina::query()->sharedLock()->find($service->rq_mina_id));
        }
        $rqMina = $service->rqMina;
        if (!$rqMina || !MinaScopeAccess::allows($usuario, $rqMina->mina_id)) {
            return $this->forbidden('TRANSPORTE_RQ_FORBIDDEN');
        }

        $fecha = $service->fecha->toDateString();
        if (!$this->dateFits($fecha, $rqMina->fecha_inicio?->toDateString(), $rqMina->fecha_fin?->toDateString())) {
            return $this->businessError('TRANSPORTE_DATE_OUT_OF_RQ', 'La fecha del servicio debe estar dentro de la parada.');
        }

        if ($service->rq_mina_plan_id) {
            $plan = RQMinaPlan::query()->sharedLock()->find($service->rq_mina_plan_id);
            if (!$plan || $plan->rq_mina_id !== $rqMina->id) {
                return $this->businessError('TRANSPORTE_PLAN_INVALID', 'El plan no pertenece a la parada.');
            }
            if ($plan->estado === RQMinaPlan::ESTADO_ARCHIVADO) {
                return $this->businessError('TRANSPORTE_PLAN_ARCHIVED', 'El plan archivado es solo consulta.');
            }
            if (!$this->dateFits($fecha, $plan->fecha_inicio?->toDateString(), $plan->fecha_fin?->toDateString())) {
                return $this->businessError('TRANSPORTE_DATE_OUT_OF_PLAN', 'La fecha del servicio debe estar dentro del plan.');
            }
        }

        return ['ok' => true];
    }

    private function validateResources(array $payload, ?TransporteServicio $current = null): array
    {
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
        $missing = $targetState === TransporteServicio::ESTADO_CONFIRMADO
            ? ($this->confirmationRequirements($service)[0] ?? null)
            : null;
        if ($missing === null) {
            return ['ok' => true];
        }

        return match ($missing) {
            'placa' => $this->businessError('TRANSPORTE_CONFIRM_REQUIRES_PLATE', 'Para confirmar transporte de personal se requiere placa.'),
            'conductor' => $this->businessError('TRANSPORTE_CONFIRM_REQUIRES_DRIVER', 'Para confirmar transporte de personal se requiere conductor.'),
            'capacidad' => $this->businessError('TRANSPORTE_CONFIRM_REQUIRES_CAPACITY', 'Para confirmar transporte de personal se requiere capacidad.'),
            'sobreocupacion' => $this->businessError('TRANSPORTE_CONFIRM_OVERBOOKED', 'No se puede confirmar un transporte sobreocupado.'),
            'alcance' => $this->businessError('TRANSPORTE_CONFIRM_REQUIRES_SCOPE', 'Para confirmar se requiere al menos un alcance.'),
        };
    }

    public function confirmationRequirements(TransporteServicio $service): array
    {
        $service->loadMissing(['alcances', 'pasajeros']);
        $missing = [];

        if ($service->tipo === TransporteServicio::TIPO_PERSONAL) {
            if (trim((string) $service->placa) === '') {
                $missing[] = 'placa';
            }
            if (trim((string) $service->conductor_personal_id) === '' && trim((string) $service->conductor_nombre_snapshot) === '') {
                $missing[] = 'conductor';
            }
            if (!$service->capacidad || $service->capacidad <= 0) {
                $missing[] = 'capacidad';
            }
            if ($this->serviceMetrics($service)['sobreocupacion'] > 0) {
                $missing[] = 'sobreocupacion';
            }
        }

        if ($service->alcances->isEmpty()) {
            $missing[] = 'alcance';
        }

        return $missing;
    }

    private function invalidAlcanceMessage(TransporteServicio $service, array $row): ?string
    {
        if (!empty($row['rq_mina_actividad_grupo_id'])) {
            $group = RQMinaActividadGrupo::query()->find($row['rq_mina_actividad_grupo_id']);
            if (!$group || $group->rq_mina_id !== $service->rq_mina_id) {
                return 'El grupo operativo no pertenece a la parada.';
            }
            if ($service->rq_mina_plan_id && $group->rq_mina_plan_id && $group->rq_mina_plan_id !== $service->rq_mina_plan_id) {
                return 'El grupo operativo no pertenece al plan.';
            }
        }

        if (!empty($row['rq_mina_actividad_id'])) {
            $activity = RQMinaActividad::query()->with('grupo')->find($row['rq_mina_actividad_id']);
            if (!$activity || $activity->grupo?->rq_mina_id !== $service->rq_mina_id) {
                return 'La actividad no pertenece a la parada.';
            }
            if ($service->rq_mina_plan_id && $activity->grupo?->rq_mina_plan_id && $activity->grupo->rq_mina_plan_id !== $service->rq_mina_plan_id) {
                return 'La actividad no pertenece al plan.';
            }
            if (!empty($row['rq_mina_actividad_grupo_id']) && $activity->grupo_id !== $row['rq_mina_actividad_grupo_id']) {
                return 'La actividad no pertenece al grupo operativo seleccionado.';
            }
        }

        if (!empty($row['grupo_trabajo_id'])) {
            $group = GrupoTrabajo::query()->find($row['grupo_trabajo_id']);
            if (!$group || $group->rq_mina_id !== $service->rq_mina_id) {
                return 'El grupo de Man Power no pertenece a la parada.';
            }
            if ($service->rq_mina_plan_id && $group->rq_mina_plan_id && $group->rq_mina_plan_id !== $service->rq_mina_plan_id) {
                return 'El grupo de Man Power no pertenece al plan.';
            }
            if (!$this->groupFitsService($group, $service)) {
                return 'El grupo de Man Power debe ser de la misma fecha y turno.';
            }
        }

        return null;
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

        return $group && $this->groupFitsService($group, $service);
    }

    private function isEligiblePassenger(GrupoTrabajoDetalle $detail, TransporteServicio $service, Collection $groupIds): bool
    {
        return $detail->isDistribucionActiva()
            && $groupIds->contains($detail->grupo_trabajo_id)
            && $detail->grupoTrabajo?->estado !== GrupoTrabajo::ESTADO_CANCELADO
            && $this->detailFitsService($detail, $service);
    }

    private function groupFitsService(GrupoTrabajo $group, TransporteServicio $service): bool
    {
        return $group->rq_mina_id === $service->rq_mina_id
            && (!$group->rq_mina_plan_id || !$service->rq_mina_plan_id || $group->rq_mina_plan_id === $service->rq_mina_plan_id)
            && $group->fecha?->toDateString() === $service->fecha?->toDateString()
            && $this->turnsCompatible((string) $group->turno, (string) $service->turno);
    }

    private function validatePassengerContexts(TransporteServicio $service): array
    {
        $activePassengers = $service->pasajeros->where('estado', TransporteServicioPasajero::ESTADO_ASIGNADO);
        $activePassengers->loadMissing('grupoTrabajoDetalle.grupoTrabajo');
        $groupIds = $service->alcances->pluck('grupo_trabajo_id')->filter();

        foreach ($activePassengers as $passenger) {
            $detail = $passenger->grupoTrabajoDetalle;
            if ($service->tipo !== TransporteServicio::TIPO_PERSONAL
                || $passenger->tramo !== $service->tramo
                || !$detail
                || !$groupIds->contains($detail->grupo_trabajo_id)
                || !$this->detailFitsService($detail, $service)) {
                return $this->businessError('TRANSPORTE_PASSENGER_CONTEXT_INVALID', 'El cambio deja pasajeros activos fuera del contexto del servicio.');
            }
        }

        return ['ok' => true];
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
        $event = TransporteServicioEvento::query()->create([
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
        if (!$event->exists) {
            throw new \RuntimeException('No se pudo guardar el evento de transporte.');
        }
    }

    private function resolveDriver(Usuario $usuario, ?string $personalId): ?Personal
    {
        if (!$personalId) {
            return null;
        }

        return Personal::query()->select('id', 'nombre_completo')
            ->when(!MinaScopeAccess::isPrivileged($usuario), function ($query) use ($usuario): void {
                $scopes = UsuarioMinaScope::query()->where('usuario_id', $usuario->id)->select('mina_id');
                $query->whereHas('relacionesMina', fn ($relations) => $relations->whereIn('mina_id', $scopes)->where('activo', true));
            })->find($personalId);
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

    private function emptyContext(Collection $paradas, string $fecha, string $turno): array
    {
        return [
            'paradas' => $paradas->values()->all(),
            'selected' => ['rq_mina_id' => '', 'rq_mina_plan_id' => '', 'fecha' => $fecha, 'turno' => $turno],
            'rq_mina' => null,
            'plans' => [],
            'plan' => null,
            'can_create' => false,
            'conductores' => [],
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
