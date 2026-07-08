<?php

namespace App\Modules\RQMina\Services;

use App\Models\Mina;
use App\Models\Oficina;
use App\Models\Personal;
use App\Models\RQMina;
use App\Models\RQMinaActividad;
use App\Models\RQMinaActividadGrupo;
use App\Models\RQMinaActividadTransporte;
use App\Models\RQMinaActividadTransporteEvento;
use App\Models\RQMinaDetalle;
use App\Models\RQMinaDetalleCambio;
use App\Models\RQMinaFieldOption;
use App\Models\RQProsergeDetalle;
use App\Models\Taller;
use App\Models\Usuario;
use App\Modules\RQProserge\Services\RQProsergeService;
use App\Modules\RQMina\Policies\RQMinaPolicy;
use App\Support\Rbac\PermissionMatrix;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class RQMinaService
{
    public function __construct(
        private readonly RQMinaPolicy $policy,
        private readonly RQProsergeService $rqProsergeService,
    ) {
    }

    public function listForUser(Usuario $usuario, array $filters, int $perPage = 10, int $page = 1): array
    {
        $query = RQMina::query()->with([
            'mina:id,nombre',
            'creador:id,email,personal_id',
            'creador.personal:id,nombre_completo',
            'supervisor:id,dni,nombre_completo,puesto,es_supervisor',
            'supervisorPets:id,dni,nombre_completo,puesto,es_supervisor',
            'detalle',
            'transportes:id,rq_mina_id,transporte,cantidad',
            'actividadGrupos.actividades.turnos',
            'actividadGrupos.transportes',
        ]);

        $this->applyMineScope($query, $usuario);

        if (!empty($filters['q'])) {
            $search = trim((string) $filters['q']);
            $like = '%' . str_replace(' ', '%', $search) . '%';

            $query->where(function ($innerQuery) use ($like) {
                $innerQuery
                    ->where('area', 'like', $like)
                    ->orWhere('estado', 'like', $like)
                    ->orWhere('observaciones', 'like', $like)
                    ->orWhere('destino_nombre', 'like', $like)
                    ->orWhere('destino_tipo', 'like', $like)
                    ->orWhereHas('mina', fn ($mineQuery) => $mineQuery->where('nombre', 'like', $like))
                    ->orWhereHas('transportes', fn ($transportQuery) => $transportQuery->where('transporte', 'like', $like))
                    ->orWhereHas('supervisor', function ($supervisorQuery) use ($like) {
                        $supervisorQuery
                            ->where('nombre_completo', 'like', $like)
                            ->orWhere('dni', 'like', $like)
                            ->orWhere('puesto', 'like', $like);
                    })
                    ->orWhereHas('supervisorPets', function ($supervisorQuery) use ($like) {
                        $supervisorQuery
                            ->where('nombre_completo', 'like', $like)
                            ->orWhere('dni', 'like', $like)
                            ->orWhere('puesto', 'like', $like);
                    })
                    ->orWhereHas('creador', function ($creatorQuery) use ($like) {
                        $creatorQuery
                            ->where('email', 'like', $like)
                            ->orWhereHas('personal', fn ($personalQuery) => $personalQuery->where('nombre_completo', 'like', $like));
                    });
            });
        }

        if (!empty($filters['mina_id'])) {
            $query->where('mina_id', $filters['mina_id']);
        }

        if (!empty($filters['estado'])) {
            $query->where('estado', strtoupper((string) $filters['estado']));
        }

        if (!empty($filters['created_by_usuario_id'])) {
            $query->where('created_by_usuario_id', (string) $filters['created_by_usuario_id']);
        }

        if (!empty($filters['fecha_inicio_desde'])) {
            $query->whereDate('fecha_inicio', '>=', $filters['fecha_inicio_desde']);
        }

        if (!empty($filters['fecha_inicio_hasta'])) {
            $query->whereDate('fecha_inicio', '<=', $filters['fecha_inicio_hasta']);
        }

        if (!empty($filters['fecha_fin_desde'])) {
            $query->whereDate('fecha_fin', '>=', $filters['fecha_fin_desde']);
        }

        if (!empty($filters['fecha_fin_hasta'])) {
            $query->whereDate('fecha_fin', '<=', $filters['fecha_fin_hasta']);
        }

        $total = $query->count();
        $items = $query->orderByDesc('created_at')->skip(($page - 1) * $perPage)->take($perPage)->get();
        $totalPages = $perPage > 0 ? max(1, (int) ceil($total / $perPage)) : 1;

        return [
            'items' => $items,
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $page,
            'total_pages' => $totalPages,
        ];
    }

    public function getCreatorOptionsForUser(Usuario $usuario): Collection
    {
        $query = RQMina::query()
            ->selectRaw('DISTINCT rq_mina.created_by_usuario_id as id')
            ->selectRaw("COALESCE(personal.nombre_completo, usuarios.email, 'Sin creador') as nombre")
            ->leftJoin('usuarios', 'usuarios.id', '=', 'rq_mina.created_by_usuario_id')
            ->leftJoin('personal', 'personal.id', '=', 'usuarios.personal_id')
            ->whereNotNull('rq_mina.created_by_usuario_id');

        $this->applyMineScope($query, $usuario);

        return $query
            ->orderBy('nombre')
            ->get()
            ->map(fn ($row): array => [
                'id' => (string) ($row->id ?? ''),
                'nombre' => (string) ($row->nombre ?? 'Sin creador'),
            ])
            ->filter(fn (array $row): bool => $row['id'] !== '')
            ->values();
    }

    public function findForUser(Usuario $usuario, string $id): ?RQMina
    {
        $rqMina = RQMina::query()
            ->with(['mina:id,nombre', 'creador:id,email,personal_id', 'creador.personal:id,nombre_completo', 'supervisor:id,dni,nombre_completo,puesto,es_supervisor', 'supervisorPets:id,dni,nombre_completo,puesto,es_supervisor', 'detalle', 'transportes', 'actividadGrupos.actividades.turnos', 'actividadGrupos.transportes'])
            ->find($id);

        if (!$rqMina) {
            return null;
        }

        if (!$this->policy->view($usuario, $rqMina)) {
            return null;
        }

        return $rqMina;
    }

    public function create(Usuario $usuario, array $payload): ?RQMina
    {
        Log::info('rqmina.create_payload_received', [
            'usuario_id' => (string) $usuario->id,
            'mina_id' => (string) ($payload['mina_id'] ?? ''),
            'destino_tipo' => (string) ($payload['destino_tipo'] ?? ''),
            'destino_id' => (string) ($payload['destino_id'] ?? ''),
            'detalle_count' => count($payload['detalle'] ?? []),
            'detalle_total_cantidad' => collect($payload['detalle'] ?? [])->sum(fn (array $item) => (int) ($item['cantidad'] ?? 0)),
            'transporte_count' => count($payload['transporte'] ?? []),
            'plan_operativo_grupos_count' => count($payload['plan_operativo'] ?? []),
            'supervisor_id' => (string) ($payload['supervisor_id'] ?? ''),
            'supervisor_pets_id' => (string) ($payload['supervisor_pets_id'] ?? ''),
        ]);

        $destination = $this->resolveDestination(
            usuario: $usuario,
            destinoTipo: $payload['destino_tipo'] ?? null,
            destinoId: $payload['destino_id'] ?? null,
            legacyMinaId: $payload['mina_id'] ?? null,
            legacyMinaName: $payload['mina'] ?? null,
        );

        if (!$destination || !PermissionMatrix::userCan($usuario, 'rq_mina', 'crear') || !$this->policy->canAccessMina($usuario, $destination['mina_id'])) {
            return null;
        }

        return DB::transaction(function () use ($usuario, $payload, $destination): RQMina {
            $detallePayload = $this->resolveDetallePayload($payload);
            $rqMina = RQMina::query()->create([
                'id' => (string) Str::uuid(),
                'mina_id' => $destination['mina_id'],
                'destino_tipo' => $destination['tipo'],
                'destino_id' => $destination['id'],
                'destino_nombre' => $destination['nombre'],
                'supervisor_id' => $this->resolveSupervisorId($payload['supervisor_id'] ?? null),
                'supervisor_pets_id' => $this->resolveSupervisorId($payload['supervisor_pets_id'] ?? null),
                'area' => $payload['area'],
                'fecha_inicio' => $payload['fecha_inicio'],
                'fecha_fin' => $payload['fecha_fin'],
                'observaciones' => $payload['observaciones'] ?? null,
                'estado' => 'BORRADOR',
                'created_by_usuario_id' => $usuario->id,
            ]);

            $rows = $this->buildDetalleRows((string) $rqMina->id, $detallePayload);

            $rqMina->detalle()->insert($rows);

            $transportRows = $this->buildTransporteRows((string) $rqMina->id, $payload['transporte'] ?? []);
            if (!empty($transportRows)) {
                $rqMina->transportes()->insert($transportRows);
            }

            $this->replacePlanOperativo($rqMina, $payload['plan_operativo'] ?? [], $usuario);
            $this->rememberFieldOptions($usuario, $payload);

            Log::info('rqmina.detail_persisted', [
                'rq_mina_id' => (string) $rqMina->id,
                'detalle_guardado' => array_map(static fn (array $row): array => [
                    'puesto' => (string) ($row['puesto'] ?? ''),
                    'cantidad' => (int) ($row['cantidad'] ?? 0),
                ], $rows),
                'cantidad_puestos' => count($rows),
                'cantidad_total' => collect($rows)->sum(fn (array $row) => (int) ($row['cantidad_total'] ?? $row['cantidad'] ?? 0)),
                'transporte_guardado' => array_map(static fn (array $row): array => [
                    'transporte' => (string) ($row['transporte'] ?? ''),
                    'cantidad' => (int) ($row['cantidad'] ?? 0),
                ], $transportRows),
                'supervisor_id' => (string) ($rqMina->supervisor_id ?? ''),
                'supervisor_pets_id' => (string) ($rqMina->supervisor_pets_id ?? ''),
            ]);

            return $rqMina->load([
                'mina:id,nombre',
                'creador:id,email,personal_id',
                'creador.personal:id,nombre_completo',
                'supervisor:id,dni,nombre_completo,puesto,es_supervisor',
                'supervisorPets:id,dni,nombre_completo,puesto,es_supervisor',
                'detalle',
                'transportes',
                'actividadGrupos.actividades.turnos',
                'actividadGrupos.transportes',
            ]);
        });
    }

    public function update(Usuario $usuario, RQMina $rqMina, array $payload): ?RQMina
    {
        Log::info('rqmina.update_payload_received', [
            'usuario_id' => (string) $usuario->id,
            'rq_mina_id' => (string) $rqMina->id,
            'mina_id' => (string) ($payload['mina_id'] ?? ''),
            'destino_tipo' => (string) ($payload['destino_tipo'] ?? ''),
            'destino_id' => (string) ($payload['destino_id'] ?? ''),
            'detalle_count' => count($payload['detalle'] ?? []),
            'detalle_total_cantidad' => collect($payload['detalle'] ?? [])->sum(fn (array $item) => (int) ($item['cantidad'] ?? 0)),
            'transporte_count' => count($payload['transporte'] ?? []),
            'plan_operativo_grupos_count' => count($payload['plan_operativo'] ?? []),
            'supervisor_id' => (string) ($payload['supervisor_id'] ?? ''),
            'supervisor_pets_id' => (string) ($payload['supervisor_pets_id'] ?? ''),
        ]);

        if (!$this->policy->update($usuario, $rqMina)) {
            return null;
        }

        $destination = $this->resolveDestination(
            usuario: $usuario,
            destinoTipo: $payload['destino_tipo'] ?? null,
            destinoId: $payload['destino_id'] ?? null,
            legacyMinaId: $payload['mina_id'] ?? $rqMina->mina_id,
            legacyMinaName: $payload['mina'] ?? null,
        );

        if (!$destination || !$this->policy->canAccessMina($usuario, $destination['mina_id'])) {
            return null;
        }

        return DB::transaction(function () use ($usuario, $rqMina, $payload, $destination): RQMina {
            $detallePayload = $this->resolveDetallePayload($payload);
            $rqMina->fill([
                'mina_id' => $destination['mina_id'],
                'destino_tipo' => $destination['tipo'],
                'destino_id' => $destination['id'],
                'destino_nombre' => $destination['nombre'],
                'supervisor_id' => $this->resolveSupervisorId($payload['supervisor_id'] ?? null),
                'supervisor_pets_id' => $this->resolveSupervisorId($payload['supervisor_pets_id'] ?? null),
                'area' => $payload['area'],
                'fecha_inicio' => $payload['fecha_inicio'],
                'fecha_fin' => $payload['fecha_fin'],
                'observaciones' => $payload['observaciones'] ?? null,
            ]);
            $rqMina->save();

            $rqMina->transportes()->delete();

            $rows = $this->replaceDetalle($rqMina, $detallePayload, $usuario);

            $transportRows = $this->buildTransporteRows((string) $rqMina->id, $payload['transporte'] ?? []);
            if (!empty($transportRows)) {
                $rqMina->transportes()->insert($transportRows);
            }

            $this->replacePlanOperativo($rqMina, $payload['plan_operativo'] ?? [], $usuario);
            $this->rememberFieldOptions($usuario, $payload);

            Log::info('rqmina.detail_persisted', [
                'rq_mina_id' => (string) $rqMina->id,
                'detalle_guardado' => array_map(static fn (array $row): array => [
                    'puesto' => (string) ($row['puesto'] ?? ''),
                    'cantidad' => (int) ($row['cantidad'] ?? 0),
                ], $rows),
                'cantidad_puestos' => count($rows),
                'cantidad_total' => collect($rows)->sum(fn (array $row) => (int) ($row['cantidad_total'] ?? $row['cantidad'] ?? 0)),
                'transporte_guardado' => array_map(static fn (array $row): array => [
                    'transporte' => (string) ($row['transporte'] ?? ''),
                    'cantidad' => (int) ($row['cantidad'] ?? 0),
                ], $transportRows),
                'supervisor_id' => (string) ($rqMina->supervisor_id ?? ''),
                'supervisor_pets_id' => (string) ($rqMina->supervisor_pets_id ?? ''),
            ]);

            if (strtoupper((string) $rqMina->estado) === 'ENVIADO') {
                $this->rqProsergeService->syncFromRqMina($usuario, $rqMina->fresh('detalle'));
            }

            return $rqMina->load([
                'mina:id,nombre',
                'creador:id,email,personal_id',
                'creador.personal:id,nombre_completo',
                'supervisor:id,dni,nombre_completo,puesto,es_supervisor',
                'supervisorPets:id,dni,nombre_completo,puesto,es_supervisor',
                'detalle',
                'transportes',
                'actividadGrupos.actividades.turnos',
                'actividadGrupos.transportes',
            ]);
        });
    }

    public function updateGeneral(Usuario $usuario, RQMina $rqMina, array $payload): ?RQMina
    {
        Log::info('rqmina.update_general_payload_received', [
            'usuario_id' => (string) $usuario->id,
            'rq_mina_id' => (string) $rqMina->id,
            'mina_id' => (string) ($payload['mina_id'] ?? ''),
            'destino_tipo' => (string) ($payload['destino_tipo'] ?? ''),
            'destino_id' => (string) ($payload['destino_id'] ?? ''),
            'supervisor_id' => (string) ($payload['supervisor_id'] ?? ''),
            'supervisor_pets_id' => (string) ($payload['supervisor_pets_id'] ?? ''),
        ]);

        if (!$this->policy->update($usuario, $rqMina)) {
            return null;
        }

        $destination = $this->resolveDestination(
            usuario: $usuario,
            destinoTipo: $payload['destino_tipo'] ?? null,
            destinoId: $payload['destino_id'] ?? null,
            legacyMinaId: $payload['mina_id'] ?? $rqMina->mina_id,
            legacyMinaName: $payload['mina'] ?? null,
        );

        if (!$destination || !$this->policy->canAccessMina($usuario, $destination['mina_id'])) {
            return null;
        }

        return DB::transaction(function () use ($usuario, $rqMina, $payload, $destination): RQMina {
            $rqMina->fill([
                'mina_id' => $destination['mina_id'],
                'destino_tipo' => $destination['tipo'],
                'destino_id' => $destination['id'],
                'destino_nombre' => $destination['nombre'],
                'supervisor_id' => $this->resolveSupervisorId($payload['supervisor_id'] ?? null),
                'supervisor_pets_id' => $this->resolveSupervisorId($payload['supervisor_pets_id'] ?? null),
                'area' => $payload['area'],
                'fecha_inicio' => $payload['fecha_inicio'],
                'fecha_fin' => $payload['fecha_fin'],
                'observaciones' => $payload['observaciones'] ?? null,
            ]);
            $rqMina->save();
            $this->rememberFieldOptions($usuario, $payload);

            if (strtoupper((string) $rqMina->estado) === 'ENVIADO') {
                $this->rqProsergeService->syncFromRqMina($usuario, $rqMina->fresh('detalle'));
            }

            return $rqMina->load([
                'mina:id,nombre',
                'creador:id,email,personal_id',
                'creador.personal:id,nombre_completo',
                'supervisor:id,dni,nombre_completo,puesto,es_supervisor',
                'supervisorPets:id,dni,nombre_completo,puesto,es_supervisor',
                'detalle',
                'transportes',
                'actividadGrupos.actividades.turnos',
                'actividadGrupos.transportes',
            ]);
        });
    }

    public function send(Usuario $usuario, RQMina $rqMina): ?RQMina
    {
        if (!$this->policy->send($usuario, $rqMina)) {
            return null;
        }

        $rqMina->fill([
            'estado' => 'ENVIADO',
            'enviado_at' => now(),
        ]);
        $rqMina->save();

        $this->rqProsergeService->syncFromRqMina($usuario, $rqMina->fresh('detalle'));

        return $rqMina->load(['mina:id,nombre', 'creador:id,email,personal_id', 'creador.personal:id,nombre_completo', 'supervisor:id,dni,nombre_completo,puesto,es_supervisor', 'supervisorPets:id,dni,nombre_completo,puesto,es_supervisor', 'detalle', 'transportes', 'actividadGrupos.actividades.turnos', 'actividadGrupos.transportes']);
    }

    public function updatePlanOperativo(Usuario $usuario, RQMina $rqMina, array $planOperativo, ?array $detallePayload = null): ?RQMina
    {
        if (!$this->policy->update($usuario, $rqMina)) {
            return null;
        }

        return DB::transaction(function () use ($usuario, $rqMina, $planOperativo, $detallePayload): RQMina {
            if ($detallePayload !== null) {
                $this->replaceDetalle($rqMina, $detallePayload, $usuario);
            }

            $this->replacePlanOperativo($rqMina, $planOperativo, $usuario);
            $this->rememberFieldOptions($usuario, [
                'detalle' => $detallePayload ?? [],
                'plan_operativo' => $planOperativo,
            ]);

            if (strtoupper((string) $rqMina->estado) === 'ENVIADO') {
                $this->rqProsergeService->syncFromRqMina($usuario, $rqMina->fresh('detalle'));
            }

            return $rqMina->fresh([
                'mina:id,nombre',
                'creador:id,email,personal_id',
                'creador.personal:id,nombre_completo',
                'supervisor:id,dni,nombre_completo,puesto,es_supervisor',
                'supervisorPets:id,dni,nombre_completo,puesto,es_supervisor',
                'detalle',
                'transportes',
                'actividadGrupos.actividades.turnos',
                'actividadGrupos.transportes',
            ]);
        });
    }

    private function rememberFieldOptions(?Usuario $usuario, array $payload): void
    {
        $this->rememberFieldOption($usuario, 'rq_mina.parada_nombre', $payload['area'] ?? null);
        $this->rememberFieldOption($usuario, 'rq_mina.parada_observaciones', $payload['observaciones'] ?? null);

        foreach (($payload['detalle'] ?? []) as $row) {
            $this->rememberFieldOption($usuario, 'rq_mina.detalle_puesto', $row['puesto'] ?? null);
        }

        foreach (($payload['transporte'] ?? []) as $row) {
            $this->rememberFieldOption($usuario, 'rq_mina.transporte', $row['transporte'] ?? null);
        }

        foreach (($payload['plan_operativo'] ?? []) as $group) {
            $this->rememberFieldOption($usuario, 'rq_mina.plan.area_operativa', $group['area_operativa'] ?? null);
            $this->rememberFieldOption($usuario, 'rq_mina.plan.modulo', $group['modulo'] ?? null);
            $this->rememberFieldOption($usuario, 'rq_mina.plan.grupo_nombre', $group['nombre'] ?? null);
            $this->rememberFieldOption($usuario, 'rq_mina.plan.grupo_observaciones', $group['observaciones'] ?? null);

            foreach (($group['actividades'] ?? []) as $activity) {
                $this->rememberFieldOption($usuario, 'rq_mina.plan.sait', $activity['sait'] ?? null);
                $this->rememberFieldOption($usuario, 'rq_mina.plan.sector', $activity['sector'] ?? null);
                $this->rememberFieldOption($usuario, 'rq_mina.plan.actividad_area', $activity['area'] ?? null);
                $this->rememberFieldOption($usuario, 'rq_mina.plan.ait_trabajo', $activity['ait_trabajo'] ?? null);
                $this->rememberFieldOption($usuario, 'rq_mina.plan.trabajos_relevantes', $activity['detalle_trabajos_relevantes'] ?? null);
                $this->rememberFieldOption($usuario, 'rq_mina.plan.supervisor_campo_dia', $activity['supervisor_campo_dia'] ?? null);
                $this->rememberFieldOption($usuario, 'rq_mina.plan.supervisor_campo_noche', $activity['supervisor_campo_noche'] ?? null);
                $this->rememberFieldOption($usuario, 'rq_mina.plan.supervisor_seguridad_dia', $activity['supervisor_seguridad_dia'] ?? null);
                $this->rememberFieldOption($usuario, 'rq_mina.plan.supervisor_seguridad_noche', $activity['supervisor_seguridad_noche'] ?? null);

            }

            foreach (($group['transportes'] ?? []) as $transport) {
                $this->rememberFieldOption($usuario, 'rq_mina.plan.transporte_alcance', $transport['alcance'] ?? null);
                $this->rememberFieldOption($usuario, 'rq_mina.plan.unidad_carga', $transport['unidad_carga'] ?? null);
                $this->rememberFieldOption($usuario, 'rq_mina.plan.unidades_transporte', $transport['unidades_transporte'] ?? null);
                $this->rememberFieldOption($usuario, 'rq_mina.plan.placas_transporte', $transport['placas_asignadas'] ?? null);
                $this->rememberFieldOption($usuario, 'rq_mina.plan.transporte_indicaciones', $transport['indicaciones'] ?? null);
            }
        }
    }

    private function rememberFieldOption(?Usuario $usuario, string $fieldKey, mixed $value): void
    {
        $text = preg_replace('/\s+/u', ' ', trim((string) $value));
        if (!$text) {
            return;
        }

        $text = mb_substr($text, 0, 1000);
        $normalized = $this->normalizeFieldOptionValue($text);
        if (!$normalized) {
            return;
        }

        try {
            $option = RQMinaFieldOption::query()
                ->where('field_key', $fieldKey)
                ->where('value_normalized', $normalized)
                ->first();

            if (!$option) {
                $option = new RQMinaFieldOption([
                    'id' => (string) Str::uuid(),
                    'field_key' => $fieldKey,
                    'value_normalized' => $normalized,
                    'usage_count' => 0,
                    'created_by_usuario_id' => $usuario?->id,
                ]);
            }

            $option->value = $text;
            $option->usage_count = ((int) $option->usage_count) + 1;
            $option->save();
        } catch (\Throwable $exception) {
            Log::warning('rqmina.field_option_remember_failed', [
                'field_key' => $fieldKey,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    private function normalizeFieldOptionValue(string $value): string
    {
        $normalized = Str::ascii(Str::lower(preg_replace('/\s+/u', ' ', trim($value))));

        return mb_substr($normalized, 0, 191);
    }

    public function delete(Usuario $usuario, RQMina $rqMina): bool
    {
        if (!$this->policy->delete($usuario, $rqMina)) {
            return false;
        }

        return (bool) DB::transaction(function () use ($rqMina): bool {
            $rqId = (string) $rqMina->id;
            $deleted = [];

            $rqDetalleIds = $this->collectIdsByColumn('rq_mina_detalle', 'rq_mina_id', $rqId);
            $rqProsergeIds = $this->collectIdsByColumn('rq_proserge', 'rq_mina_id', $rqId);
            $grupoTrabajoIds = $this->uniqueIds(
                $this->collectIdsByColumn('grupo_trabajo', 'rq_mina_id', $rqId),
                $this->collectIdsWhereIn('grupo_trabajo', 'rq_proserge_id', $rqProsergeIds),
            );

            $asistenciaEncabezadoIds = $this->collectIdsWhereIn('asistencia_encabezado', 'grupo_trabajo_id', $grupoTrabajoIds);
            $asistenciaDetalleIds = $this->uniqueIds(
                $this->collectIdsWhereIn('asistencia_detalle', 'asistencia_id', $asistenciaEncabezadoIds),
                $this->collectIdsWhereIn('asistencia_detalle', 'asistencia_encabezado_id', $asistenciaEncabezadoIds),
            );

            $actividadGrupoIds = $this->collectIdsByColumn('rq_mina_actividad_grupos', 'rq_mina_id', $rqId);
            $actividadIds = $this->uniqueIds(
                $this->collectIdsByColumn('rq_mina_actividades', 'rq_mina_id', $rqId),
                $this->collectIdsWhereIn('rq_mina_actividades', 'grupo_id', $actividadGrupoIds),
                $this->collectIdsWhereIn('rq_mina_actividades', 'rq_mina_actividad_grupo_id', $actividadGrupoIds),
            );
            $actividadTransporteIds = $this->uniqueIds(
                $this->collectIdsByColumn('rq_mina_actividad_transportes', 'rq_mina_id', $rqId),
                $this->collectIdsWhereIn('rq_mina_actividad_transportes', 'grupo_id', $actividadGrupoIds),
                $this->collectIdsWhereIn('rq_mina_actividad_transportes', 'rq_mina_actividad_grupo_id', $actividadGrupoIds),
                $this->collectIdsWhereIn('rq_mina_actividad_transportes', 'actividad_id', $actividadIds),
                $this->collectIdsWhereIn('rq_mina_actividad_transportes', 'rq_mina_actividad_id', $actividadIds),
            );

            $herramientaListaIds = $this->collectIdsByColumn('parada_herramienta_listas', 'rq_mina_id', $rqId);
            $herramientaGrupoIds = $this->uniqueIds(
                $this->collectIdsWhereIn('parada_herramienta_grupos', 'lista_id', $herramientaListaIds),
                $this->collectIdsWhereIn('parada_herramienta_grupos', 'parada_herramienta_lista_id', $herramientaListaIds),
                $this->collectIdsWhereIn('parada_herramienta_grupos', 'grupo_trabajo_id', $grupoTrabajoIds),
            );

            foreach ([
                'faltas',
                'evaluacion_desempeno',
                'evaluacion_supervisor',
                'evaluacion_residente',
                'promedio_desempeno',
            ] as $table) {
                $this->deleteWhereIn($table, 'asistencia_detalle_id', $asistenciaDetalleIds, $deleted);
                $this->deleteWhereIn($table, 'asistencia_id', $asistenciaEncabezadoIds, $deleted);
                $this->deleteWhereIn($table, 'asistencia_encabezado_id', $asistenciaEncabezadoIds, $deleted);
                $this->deleteWhereIn($table, 'grupo_trabajo_id', $grupoTrabajoIds, $deleted);
                $this->deleteByColumn($table, 'rq_mina_id', $rqId, $deleted);
            }

            $this->deleteWhereIn('asistencia_detalle', 'id', $asistenciaDetalleIds, $deleted);
            $this->deleteWhereIn('asistencia_detalle', 'asistencia_id', $asistenciaEncabezadoIds, $deleted);
            $this->deleteWhereIn('asistencia_detalle', 'asistencia_encabezado_id', $asistenciaEncabezadoIds, $deleted);
            $this->deleteWhereIn('asistencia_encabezado', 'id', $asistenciaEncabezadoIds, $deleted);

            $this->deleteWhereIn('parada_herramienta_items', 'grupo_id', $herramientaGrupoIds, $deleted);
            $this->deleteWhereIn('parada_herramienta_items', 'parada_herramienta_grupo_id', $herramientaGrupoIds, $deleted);
            $this->deleteWhereIn('parada_herramienta_grupos', 'id', $herramientaGrupoIds, $deleted);
            $this->deleteWhereIn('parada_herramienta_listas', 'id', $herramientaListaIds, $deleted);

            $this->deleteWhereIn('rq_mina_actividad_transporte_eventos', 'transporte_id', $actividadTransporteIds, $deleted);
            $this->deleteWhereIn('rq_mina_actividad_transporte_eventos', 'rq_mina_actividad_transporte_id', $actividadTransporteIds, $deleted);
            $this->deleteByColumn('rq_mina_actividad_transporte_eventos', 'rq_mina_id', $rqId, $deleted);
            $this->deleteWhereIn('rq_mina_actividad_transportes', 'id', $actividadTransporteIds, $deleted);
            $this->deleteWhereIn('rq_mina_actividad_turnos', 'actividad_id', $actividadIds, $deleted);
            $this->deleteWhereIn('rq_mina_actividad_turnos', 'rq_mina_actividad_id', $actividadIds, $deleted);
            $this->deleteWhereIn('rq_mina_actividades', 'id', $actividadIds, $deleted);
            $this->deleteWhereIn('rq_mina_actividad_grupos', 'id', $actividadGrupoIds, $deleted);

            foreach ([
                'rq_mina_transporte_detalle',
                'rq_mina_transportes',
                'rq_mina_transporte_detalles',
                'rq_mina_supervisores',
                'rq_mina_registro_supervisores',
                'rq_mina_supervisor_registros',
                'rq_mina_historial',
                'rq_mina_comentarios',
            ] as $table) {
                $this->deleteByColumn($table, 'rq_mina_id', $rqId, $deleted);
            }

            $this->deleteWhereIn('rq_mina_detalle_cambios', 'rq_mina_detalle_id', $rqDetalleIds, $deleted);
            $this->deleteWhereIn('rq_mina_detalle_cambios', 'rq_proserge_id', $rqProsergeIds, $deleted);
            $this->deleteByColumn('rq_mina_detalle_cambios', 'rq_mina_id', $rqId, $deleted);

            $this->deleteWhereIn('rq_proserge_detalle', 'rq_proserge_id', $rqProsergeIds, $deleted);
            $this->deleteWhereIn('rq_proserge_detalle', 'rq_mina_detalle_id', $rqDetalleIds, $deleted);

            $this->deleteWhereIn('grupo_trabajo_detalle', 'grupo_trabajo_id', $grupoTrabajoIds, $deleted);
            $this->deleteWhereIn('grupo_trabajo_detalles', 'grupo_trabajo_id', $grupoTrabajoIds, $deleted);
            $this->deleteWhereIn('grupo_trabajo', 'id', $grupoTrabajoIds, $deleted);

            $this->deleteWhereIn('rq_proserge', 'id', $rqProsergeIds, $deleted);
            $this->deleteWhereIn('rq_mina_detalle', 'id', $rqDetalleIds, $deleted);

            $rqDeleted = RQMina::query()->whereKey($rqId)->delete();
            $this->addDeletedCount($deleted, 'rq_mina', $rqDeleted);

            Log::info('rqmina.deleted_with_dependencies', [
                'rq_mina_id' => $rqId,
                'rq_proserge_ids' => $rqProsergeIds,
                'grupo_trabajo_ids' => $grupoTrabajoIds,
                'deleted' => $deleted,
            ]);

            return $rqDeleted > 0;
        }, 3);
    }

    private function collectIdsByColumn(string $table, string $column, string $value): array
    {
        if (!$this->tableHasColumn($table, $column) || !Schema::hasColumn($table, 'id')) {
            return [];
        }

        return DB::table($table)
            ->where($column, $value)
            ->pluck('id')
            ->map(fn ($id): string => (string) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function collectIdsWhereIn(string $table, string $column, array $values): array
    {
        $values = $this->uniqueIds($values);

        if (empty($values) || !$this->tableHasColumn($table, $column) || !Schema::hasColumn($table, 'id')) {
            return [];
        }

        return DB::table($table)
            ->whereIn($column, $values)
            ->pluck('id')
            ->map(fn ($id): string => (string) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function deleteByColumn(string $table, string $column, string $value, array &$deleted): void
    {
        if (!$this->tableHasColumn($table, $column)) {
            return;
        }

        $this->addDeletedCount($deleted, $table, DB::table($table)->where($column, $value)->delete());
    }

    private function deleteWhereIn(string $table, string $column, array $values, array &$deleted): void
    {
        $values = $this->uniqueIds($values);

        if (empty($values) || !$this->tableHasColumn($table, $column)) {
            return;
        }

        $this->addDeletedCount($deleted, $table, DB::table($table)->whereIn($column, $values)->delete());
    }

    private function tableHasColumn(string $table, string $column): bool
    {
        return Schema::hasTable($table) && Schema::hasColumn($table, $column);
    }

    private function addDeletedCount(array &$deleted, string $table, int $count): void
    {
        if ($count <= 0) {
            return;
        }

        $deleted[$table] = ($deleted[$table] ?? 0) + $count;
    }

    private function uniqueIds(array ...$groups): array
    {
        return collect($groups)
            ->flatten()
            ->map(fn ($id): string => (string) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public function canUpdate(Usuario $usuario, RQMina $rqMina): bool
    {
        return $this->policy->update($usuario, $rqMina);
    }

    public function canAccessMina(Usuario $usuario, string $minaId): bool
    {
        return $this->policy->canAccessMina($usuario, $minaId);
    }

    private function isPrivileged(Usuario $usuario): bool
    {
        $rol = strtoupper((string) optional($usuario->rol)->nombre);

        return in_array($rol, ['ADMIN', 'GERENTE', 'SUPERADMIN'], true)
            || PermissionMatrix::userCan($usuario, 'rq_mina', 'administrar');
    }

    private function applyMineScope($query, Usuario $usuario): void
    {
        if ($this->isPrivileged($usuario)) {
            return;
        }

        $scopeTable = \Illuminate\Support\Facades\Schema::hasTable('usuario_mina_scope') ? 'usuario_mina_scope' : 'usuario_mina_scopes';
        $minaIds = \DB::table($scopeTable)->where('usuario_id', $usuario->id)->pluck('mina_id');
        $query->whereIn('mina_id', $minaIds);
    }

    public function getAvailableMinas(Usuario $usuario): Collection
    {
        if ($this->isPrivileged($usuario)) {
            return Mina::query()->where('estado', 'ACTIVO')->orderBy('nombre')->get(['id', 'nombre']);
        }

        $minaIds = DB::table('usuario_mina_scope')->where('usuario_id', $usuario->id)->pluck('mina_id');

        Log::info('rqmina.available_minas_scope_loaded', [
            'usuario_id' => (string) $usuario->id,
            'scope_minas' => $minaIds->map(fn ($id) => (string) $id)->values()->all(),
        ]);

        return Mina::query()
            ->whereIn('id', $minaIds)
            ->where('estado', 'ACTIVO')
            ->orderBy('nombre')
            ->get(['id', 'nombre']);
    }

    public function getLugarOptions(Usuario $usuario): Collection
    {
        $minas = $this->getAvailableMinas($usuario)
            ->map(fn (Mina $mina): array => [
                'tipo' => 'MINA',
                'id' => (string) $mina->id,
                'nombre' => (string) $mina->nombre,
                'label' => 'Mina - '.$mina->nombre,
            ]);

        $talleres = Taller::query()
            ->where('estado', 'ACTIVO')
            ->orderBy('nombre')
            ->get(['id', 'nombre'])
            ->map(fn (Taller $taller): array => [
                'tipo' => 'TALLER',
                'id' => (string) $taller->id,
                'nombre' => (string) $taller->nombre,
                'label' => 'Taller - '.$taller->nombre,
            ]);

        $oficinas = Oficina::query()
            ->where('estado', 'ACTIVO')
            ->orderBy('nombre')
            ->get(['id', 'nombre'])
            ->map(fn (Oficina $oficina): array => [
                'tipo' => 'OFICINA',
                'id' => (string) $oficina->id,
                'nombre' => (string) $oficina->nombre,
                'label' => 'Oficina - '.$oficina->nombre,
            ]);

        return $minas->concat($talleres)->concat($oficinas)->values();
    }

    public function resolveDestination(
        Usuario $usuario,
        mixed $destinoTipo = null,
        mixed $destinoId = null,
        mixed $legacyMinaId = null,
        mixed $legacyMinaName = null,
    ): ?array {
        $tipo = strtoupper(trim((string) $destinoTipo));
        $id = trim((string) $destinoId);
        $legacyId = trim((string) $legacyMinaId);
        $legacyName = trim((string) $legacyMinaName);

        if ($tipo === '' && $id === '') {
            if ($legacyId !== '') {
                $tipo = 'MINA';
                $id = $legacyId;
            } elseif ($legacyName !== '') {
                $mina = $this->findAvailableMinaByName($usuario, $legacyName);
                if (!$mina) {
                    return null;
                }

                return [
                    'tipo' => 'MINA',
                    'id' => (string) $mina->id,
                    'nombre' => (string) $mina->nombre,
                    'mina_id' => (string) $mina->id,
                ];
            }
        }

        if (!in_array($tipo, ['MINA', 'TALLER', 'OFICINA'], true) || $id === '') {
            return null;
        }

        if ($tipo === 'MINA') {
            $mina = Mina::query()
                ->where('id', $id)
                ->where('estado', 'ACTIVO')
                ->first(['id', 'nombre']);

            return $mina ? [
                'tipo' => 'MINA',
                'id' => (string) $mina->id,
                'nombre' => (string) $mina->nombre,
                'mina_id' => (string) $mina->id,
            ] : null;
        }

        $item = $tipo === 'TALLER'
            ? Taller::query()->where('id', $id)->where('estado', 'ACTIVO')->first(['id', 'nombre'])
            : Oficina::query()->where('id', $id)->where('estado', 'ACTIVO')->first(['id', 'nombre']);

        if (!$item) {
            return null;
        }

        $anchorMinaId = $this->resolveAnchorMinaId($usuario, $legacyId);
        if (!$anchorMinaId) {
            return null;
        }

        return [
            'tipo' => $tipo,
            'id' => (string) $item->id,
            'nombre' => (string) $item->nombre,
            'mina_id' => $anchorMinaId,
        ];
    }

    private function normalizeDetalleRows(mixed $items): array
    {
        if (!is_array($items)) {
            return [];
        }

        $unique = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $puesto = preg_replace('/\s+/u', ' ', trim((string) ($item['puesto'] ?? '')));
            $cantidad = max(0, (int) ($item['cantidad'] ?? 0));
            $key = $this->normalizeFieldOptionValue($puesto);

            if ($puesto === '' || $cantidad <= 0 || $key === '') {
                continue;
            }

            if (!isset($unique[$key])) {
                $unique[$key] = [
                    'puesto' => $puesto,
                    'cantidad' => 0,
                ];
            }

            $unique[$key]['cantidad'] += $cantidad;
        }

        return array_values($unique);
    }

    private function backupForCantidad(int $cantidad): int
    {
        return (int) round(max(0, $cantidad) * 0.2);
    }

    private function buildDetalleRow(string $rqMinaId, array $item, int $cantidadAtendida = 0, ?string $id = null): array
    {
        $cantidad = max(0, (int) ($item['cantidad'] ?? 0));
        $backup = $this->backupForCantidad($cantidad);

        return [
            'id' => $id ?: (string) Str::uuid(),
            'rq_mina_id' => $rqMinaId,
            'puesto' => trim((string) ($item['puesto'] ?? '')),
            'cantidad' => $cantidad,
            'cantidad_backup' => $backup,
            'cantidad_total' => $cantidad + $backup,
            'cantidad_atendida' => max(0, $cantidadAtendida),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    private function buildDetalleRows(string $rqMinaId, array $items): array
    {
        return collect($items)
            ->map(fn (array $item): array => $this->buildDetalleRow($rqMinaId, $item))
            ->values()
            ->all();
    }

    private function replaceDetalle(RQMina $rqMina, array $detallePayload, ?Usuario $usuario = null): array
    {
        $normalized = $this->resolveDetallePayload(['detalle' => $detallePayload]);
        $existingRows = $rqMina->detalle()->withCount('asignaciones')->get();
        $trackChanges = $this->shouldTrackDetalleChanges($rqMina);
        $existingByKey = [];

        foreach ($existingRows as $row) {
            $key = $this->normalizeFieldOptionValue((string) $row->puesto);
            if ($key !== '' && !isset($existingByKey[$key])) {
                $existingByKey[$key] = $row;
            }
        }

        $keptIds = [];
        $rowsForLog = [];
        foreach ($normalized as $item) {
            $key = $this->normalizeFieldOptionValue((string) ($item['puesto'] ?? ''));
            $existing = $key !== '' ? ($existingByKey[$key] ?? null) : null;

            if ($existing) {
                $previousTotal = (int) ($existing->cantidad_total ?: $existing->cantidad);
                $previousCantidad = (int) $existing->cantidad;
                $previousAssigned = (int) ($existing->asignaciones_count ?? 0);
                $row = $this->buildDetalleRow(
                    (string) $rqMina->id,
                    $item,
                    (int) $existing->cantidad_atendida,
                    (string) $existing->id
                );
                $update = $row;
                unset($update['id'], $update['rq_mina_id'], $update['created_at']);
                $existing->fill($update);
                $existing->save();

                $removedAssignments = $this->trimAssignmentsForDetalle($existing, (int) $row['cantidad_total']);
                if ($trackChanges && ($previousTotal !== (int) $row['cantidad_total'] || $previousCantidad !== (int) $row['cantidad'])) {
                    $tipo = (int) $row['cantidad_total'] > $previousTotal
                        ? RQMinaDetalleCambio::TIPO_CANTIDAD_AUMENTADA
                        : RQMinaDetalleCambio::TIPO_CANTIDAD_REDUCIDA;

                    $this->recordDetalleChange(
                        rqMina: $rqMina,
                        detalle: $existing,
                        puesto: (string) $row['puesto'],
                        tipo: $tipo,
                        cantidadAnterior: $previousTotal,
                        cantidadNueva: (int) $row['cantidad_total'],
                        asignacionesRetiradas: $removedAssignments,
                        usuario: $usuario,
                        mensaje: $this->detalleChangeMessage($tipo, (string) $row['puesto'], $previousTotal, (int) $row['cantidad_total'], $removedAssignments)
                    );
                } elseif ($removedAssignments > 0) {
                    $this->recordDetalleChange(
                        rqMina: $rqMina,
                        detalle: $existing,
                        puesto: (string) $row['puesto'],
                        tipo: RQMinaDetalleCambio::TIPO_CANTIDAD_REDUCIDA,
                        cantidadAnterior: $previousAssigned,
                        cantidadNueva: (int) $row['cantidad_total'],
                        asignacionesRetiradas: $removedAssignments,
                        usuario: $usuario,
                        mensaje: $this->detalleChangeMessage(RQMinaDetalleCambio::TIPO_CANTIDAD_REDUCIDA, (string) $row['puesto'], $previousAssigned, (int) $row['cantidad_total'], $removedAssignments)
                    );
                }

                $keptIds[] = (string) $existing->id;
                $rowsForLog[] = $row;
                continue;
            }

            $row = $this->buildDetalleRow((string) $rqMina->id, $item);
            /** @var RQMinaDetalle $created */
            $created = $rqMina->detalle()->create($row);
            if ($trackChanges) {
                $this->recordDetalleChange(
                    rqMina: $rqMina,
                    detalle: $created,
                    puesto: (string) $row['puesto'],
                    tipo: RQMinaDetalleCambio::TIPO_PUESTO_AGREGADO,
                    cantidadAnterior: 0,
                    cantidadNueva: (int) $row['cantidad_total'],
                    asignacionesRetiradas: 0,
                    usuario: $usuario,
                    mensaje: $this->detalleChangeMessage(RQMinaDetalleCambio::TIPO_PUESTO_AGREGADO, (string) $row['puesto'], 0, (int) $row['cantidad_total'], 0)
                );
            }
            $keptIds[] = (string) $row['id'];
            $rowsForLog[] = $row;
        }

        foreach ($existingRows as $row) {
            if (in_array((string) $row->id, $keptIds, true)) {
                continue;
            }

            $previousTotal = (int) ($row->cantidad_total ?: $row->cantidad);
            $removedAssignments = $this->trimAssignmentsForDetalle($row, 0);

            if ($trackChanges) {
                $this->recordDetalleChange(
                    rqMina: $rqMina,
                    detalle: $row,
                    puesto: (string) $row->puesto,
                    tipo: RQMinaDetalleCambio::TIPO_PUESTO_RETIRADO,
                    cantidadAnterior: $previousTotal,
                    cantidadNueva: 0,
                    asignacionesRetiradas: $removedAssignments,
                    usuario: $usuario,
                    mensaje: $this->detalleChangeMessage(RQMinaDetalleCambio::TIPO_PUESTO_RETIRADO, (string) $row->puesto, $previousTotal, 0, $removedAssignments)
                );
            }

            $row->delete();
        }

        return $rowsForLog;
    }

    private function shouldTrackDetalleChanges(RQMina $rqMina): bool
    {
        return strtoupper((string) $rqMina->estado) === 'ENVIADO'
            || $rqMina->rqProserge()->exists();
    }

    private function trimAssignmentsForDetalle(RQMinaDetalle $detalle, int $targetTotal): int
    {
        $targetTotal = max(0, $targetTotal);
        $assignments = RQProsergeDetalle::query()
            ->where('rq_mina_detalle_id', $detalle->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get(['id', 'rq_proserge_id']);

        $surplus = max(0, $assignments->count() - $targetTotal);
        if ($surplus <= 0) {
            $detalle->forceFill([
                'cantidad_atendida' => $assignments->count(),
            ])->save();

            return 0;
        }

        $idsToRemove = $assignments->take($surplus)->pluck('id')->values();
        RQProsergeDetalle::query()->whereIn('id', $idsToRemove)->delete();

        $remaining = RQProsergeDetalle::query()
            ->where('rq_mina_detalle_id', $detalle->id)
            ->count();

        $detalle->forceFill([
            'cantidad_atendida' => $remaining,
        ])->save();

        $this->rqProsergeService->recalculateEstadoForRqMina((string) $detalle->rq_mina_id);

        return $surplus;
    }

    private function recordDetalleChange(
        RQMina $rqMina,
        ?RQMinaDetalle $detalle,
        string $puesto,
        string $tipo,
        ?int $cantidadAnterior,
        ?int $cantidadNueva,
        int $asignacionesRetiradas,
        ?Usuario $usuario,
        string $mensaje
    ): void {
        $rqProsergeId = $rqMina->rqProserge()
            ->orderByDesc('created_at')
            ->value('id');

        RQMinaDetalleCambio::query()->create([
            'id' => (string) Str::uuid(),
            'rq_mina_id' => (string) $rqMina->id,
            'rq_mina_detalle_id' => $detalle?->id,
            'rq_proserge_id' => $rqProsergeId,
            'puesto' => $puesto,
            'tipo' => $tipo,
            'cantidad_anterior' => $cantidadAnterior,
            'cantidad_nueva' => $cantidadNueva,
            'asignaciones_retiradas' => max(0, $asignacionesRetiradas),
            'mensaje' => $mensaje,
            'estado' => RQMinaDetalleCambio::ESTADO_PENDIENTE,
            'created_by_usuario_id' => $usuario?->id,
        ]);
    }

    private function detalleChangeMessage(string $tipo, string $puesto, int $anterior, int $nuevo, int $retiradas): string
    {
        $accion = match ($tipo) {
            RQMinaDetalleCambio::TIPO_PUESTO_AGREGADO => 'Se agrego el cargo',
            RQMinaDetalleCambio::TIPO_PUESTO_RETIRADO => 'Se retiro el cargo',
            RQMinaDetalleCambio::TIPO_CANTIDAD_AUMENTADA => 'Aumento la cantidad solicitada para',
            RQMinaDetalleCambio::TIPO_CANTIDAD_REDUCIDA => 'Disminuyo la cantidad solicitada para',
            default => 'Cambio el pedido de',
        };

        $mensaje = sprintf('%s %s: %d -> %d.', $accion, $puesto, $anterior, $nuevo);

        if ($retiradas > 0) {
            $mensaje .= sprintf(' Se retiro %d asignacion(es) empezando por la ultima registrada.', $retiradas);
        }

        return $mensaje;
    }

    private function buildTransporteRows(string $rqMinaId, array $items): array
    {
        return collect($items)
            ->filter(fn ($item): bool => is_array($item))
            ->map(function (array $item) use ($rqMinaId): ?array {
                $transporte = trim((string) ($item['transporte'] ?? ''));
                $cantidad = (int) ($item['cantidad'] ?? 0);

                if ($transporte === '' || $cantidad <= 0) {
                    return null;
                }

                return [
                    'id' => (string) Str::uuid(),
                    'rq_mina_id' => $rqMinaId,
                    'transporte' => $transporte,
                    'cantidad' => $cantidad,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    private function resolveDetallePayload(array $payload): array
    {
        $detalle = $this->normalizeDetalleRows($payload['detalle'] ?? []);

        if (!empty($detalle)) {
            return $detalle;
        }

        $fromPlan = collect($payload['plan_operativo'] ?? [])
            ->filter(fn ($group): bool => is_array($group) && !empty($group['actividades'] ?? []))
            ->map(function (array $group): array {
                $label = trim(implode(' / ', array_filter([
                    (string) ($group['area_operativa'] ?? ''),
                    (string) ($group['modulo'] ?? ''),
                    (string) ($group['nombre'] ?? ''),
                ])));

                return [
                    'puesto' => $label !== '' ? 'Plan operativo - ' . $label : 'Plan operativo',
                    'cantidad' => max(1, count($group['actividades'] ?? [])),
                ];
            })
            ->values()
            ->all();

        if (!empty($fromPlan)) {
            return $this->normalizeDetalleRows($fromPlan);
        }

        return [
            [
                'puesto' => 'Parada registrada',
                'cantidad' => 1,
            ],
        ];
    }

    private function replacePlanOperativo(RQMina $rqMina, mixed $groups, ?Usuario $usuario = null): void
    {
        $previousTransportes = $this->snapshotActividadTransportes($rqMina);
        $normalized = $this->normalizePlanOperativo($groups);

        $this->recordTransportPlanChanges($rqMina, $previousTransportes, $normalized, $usuario);

        $rqMina->actividadGrupos()->delete();

        foreach ($normalized as $groupIndex => $group) {
            $grupo = RQMinaActividadGrupo::query()->create([
                'id' => (string) Str::uuid(),
                'rq_mina_id' => (string) $rqMina->id,
                'area_operativa' => $group['area_operativa'],
                'modulo' => $group['modulo'],
                'nombre' => $group['nombre'],
                'observaciones' => $group['observaciones'],
                'orden' => $groupIndex + 1,
            ]);

            $actividadIdsByClientKey = [];
            foreach ($group['actividades'] as $activityIndex => $activity) {
                $actividad = RQMinaActividad::query()->create([
                    'id' => (string) Str::uuid(),
                    'grupo_id' => (string) $grupo->id,
                    'sait' => $activity['sait'],
                    'sector' => $activity['sector'],
                    'area' => $activity['area'],
                    'ait_trabajo' => $activity['ait_trabajo'],
                    'detalle_trabajos_relevantes' => $activity['detalle_trabajos_relevantes'],
                    'supervisor_campo_dia' => $activity['supervisor_campo_dia'],
                    'supervisor_campo_noche' => $activity['supervisor_campo_noche'],
                    'supervisor_seguridad_dia' => $activity['supervisor_seguridad_dia'],
                    'supervisor_seguridad_noche' => $activity['supervisor_seguridad_noche'],
                    'orden' => $activityIndex + 1,
                ]);

                if ($activity['client_key'] !== '') {
                    $actividadIdsByClientKey[$activity['client_key']] = (string) $actividad->id;
                }

                $turnoRows = [];
                foreach ($activity['turnos'] as $turnIndex => $turno) {
                    $turnoRows[] = [
                        'id' => (string) Str::uuid(),
                        'actividad_id' => (string) $actividad->id,
                        'fecha' => $turno['fecha'],
                        'dia_label' => $turno['dia_label'],
                        'turno_a' => $turno['turno_a'],
                        'real_turno_a' => $turno['real_turno_a'],
                        'turno_b' => $turno['turno_b'],
                        'real_turno_b' => $turno['real_turno_b'],
                        'real' => $turno['real_turno_b'],
                        'orden' => $turnIndex + 1,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }

                if (!empty($turnoRows)) {
                    $actividad->turnos()->insert($turnoRows);
                }
            }

            $transporteRows = [];
            foreach ($group['transportes'] as $transportIndex => $transporte) {
                $actividadId = $transporte['actividad_key'] !== ''
                    ? ($actividadIdsByClientKey[$transporte['actividad_key']] ?? null)
                    : null;

                $transporteRows[] = [
                    'id' => (string) Str::uuid(),
                    'grupo_id' => (string) $grupo->id,
                    'actividad_id' => $actividadId,
                    'alcance' => $transporte['alcance'],
                    'unidad_carga' => $transporte['unidad_carga'],
                    'origen' => $transporte['origen'],
                    'unidades_transporte' => $transporte['unidades_transporte'],
                    'placas_asignadas' => $transporte['placas_asignadas'],
                    'fecha_inicio' => $transporte['fecha_inicio'],
                    'fecha_fin' => $transporte['fecha_fin'],
                    'dias_uso' => $transporte['dias_uso'],
                    'estado_logistico' => $transporte['estado_logistico'],
                    'indicaciones' => $transporte['indicaciones'],
                    'comentario_cambio' => $transporte['comentario_cambio'],
                    'incidencia_operativa' => $transporte['incidencia_operativa'],
                    'recepcion_fecha' => $transporte['recepcion_fecha'],
                    'recepcion_estado' => $transporte['recepcion_estado'],
                    'recepcion_observacion' => $transporte['recepcion_observacion'],
                    'orden' => $transportIndex + 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            if (!empty($transporteRows)) {
                $grupo->transportes()->insert($transporteRows);
            }
        }
    }

    private function normalizePlanOperativo(mixed $groups): array
    {
        if (!is_array($groups)) {
            return [];
        }

        $normalized = [];
        foreach ($groups as $group) {
            if (!is_array($group)) {
                continue;
            }

            $nombre = trim((string) ($group['nombre'] ?? ''));
            $areaOperativa = trim((string) ($group['area_operativa'] ?? ''));
            $modulo = trim((string) ($group['modulo'] ?? ''));
            $observaciones = trim((string) ($group['observaciones'] ?? ''));
            $actividades = $this->normalizePlanActividades($group['actividades'] ?? []);
            $transportes = $this->normalizePlanTransportes($group['transportes'] ?? []);

            if ($nombre === '' && $areaOperativa === '' && $modulo === '' && empty($actividades) && empty($transportes)) {
                continue;
            }

            $normalized[] = [
                'nombre' => $nombre !== '' ? $nombre : 'Grupo ' . (count($normalized) + 1),
                'area_operativa' => $areaOperativa !== '' ? $areaOperativa : null,
                'modulo' => $modulo !== '' ? $modulo : null,
                'observaciones' => $observaciones !== '' ? $observaciones : null,
                'actividades' => $actividades,
                'transportes' => $transportes,
            ];
        }

        return $normalized;
    }

    private function normalizePlanActividades(mixed $items): array
    {
        if (!is_array($items)) {
            return [];
        }

        $normalized = [];
        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                continue;
            }

            $row = [
                'client_key' => trim((string) ($item['client_key'] ?? $item['key'] ?? $index)),
                'sait' => trim((string) ($item['sait'] ?? '')),
                'sector' => trim((string) ($item['sector'] ?? '')),
                'area' => trim((string) ($item['area'] ?? '')),
                'ait_trabajo' => trim((string) ($item['ait_trabajo'] ?? '')),
                'detalle_trabajos_relevantes' => trim((string) ($item['detalle_trabajos_relevantes'] ?? '')),
                'supervisor_campo_dia' => trim((string) ($item['supervisor_campo_dia'] ?? '')),
                'supervisor_campo_noche' => trim((string) ($item['supervisor_campo_noche'] ?? '')),
                'supervisor_seguridad_dia' => trim((string) ($item['supervisor_seguridad_dia'] ?? '')),
                'supervisor_seguridad_noche' => trim((string) ($item['supervisor_seguridad_noche'] ?? '')),
                'turnos' => $this->normalizePlanTurnos($item['turnos'] ?? []),
            ];

            $hasText = collect($row)
                ->except(['client_key', 'turnos'])
                ->filter(fn ($value): bool => trim((string) $value) !== '')
                ->isNotEmpty();

            if (!$hasText && empty($row['turnos'])) {
                continue;
            }

            foreach (['sait', 'sector', 'area', 'ait_trabajo', 'detalle_trabajos_relevantes', 'supervisor_campo_dia', 'supervisor_campo_noche', 'supervisor_seguridad_dia', 'supervisor_seguridad_noche'] as $field) {
                $row[$field] = $row[$field] !== '' ? $row[$field] : null;
            }

            $normalized[] = $row;
        }

        return $normalized;
    }

    private function normalizePlanTurnos(mixed $items): array
    {
        if (!is_array($items)) {
            return [];
        }

        $normalized = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $fecha = trim((string) ($item['fecha'] ?? ''));
            $diaLabel = trim((string) ($item['dia_label'] ?? ''));
            $turnoA = trim((string) ($item['turno_a'] ?? ''));
            $realTurnoA = trim((string) ($item['real_turno_a'] ?? ''));
            $turnoB = trim((string) ($item['turno_b'] ?? ''));
            $realTurnoB = trim((string) ($item['real_turno_b'] ?? $item['real'] ?? ''));

            if ($fecha === '' && $diaLabel === '' && $turnoA === '' && $realTurnoA === '' && $turnoB === '' && $realTurnoB === '') {
                continue;
            }

            $normalized[] = [
                'fecha' => $fecha !== '' ? $fecha : null,
                'dia_label' => $diaLabel !== '' ? $diaLabel : null,
                'turno_a' => $turnoA !== '' ? $turnoA : null,
                'real_turno_a' => $realTurnoA !== '' ? $realTurnoA : null,
                'turno_b' => $turnoB !== '' ? $turnoB : null,
                'real_turno_b' => $realTurnoB !== '' ? $realTurnoB : null,
                'real' => $realTurnoB !== '' ? $realTurnoB : null,
            ];
        }

        return $normalized;
    }

    private function normalizePlanTransportes(mixed $items): array
    {
        if (!is_array($items)) {
            return [];
        }

        $normalized = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $fechaInicio = $this->normalizeDateValue($item['fecha_inicio'] ?? null);
            $fechaFin = $this->normalizeDateValue($item['fecha_fin'] ?? null);
            $recepcionFecha = $this->normalizeDateValue($item['recepcion_fecha'] ?? null);

            $row = [
                'actividad_key' => trim((string) ($item['actividad_key'] ?? '')),
                'alcance' => trim((string) ($item['alcance'] ?? '')),
                'unidad_carga' => trim((string) ($item['unidad_carga'] ?? '')),
                'origen' => $this->normalizeTransportEnum(
                    $item['origen'] ?? null,
                    RQMinaActividadTransporte::origenes(),
                    RQMinaActividadTransporte::ORIGEN_EMPRESA,
                    true
                ),
                'unidades_transporte' => trim((string) ($item['unidades_transporte'] ?? '')),
                'placas_asignadas' => trim((string) ($item['placas_asignadas'] ?? '')),
                'fecha_inicio' => $fechaInicio,
                'fecha_fin' => $fechaFin,
                'dias_uso' => $this->calculateDiasUso($fechaInicio, $fechaFin, $item['dias_uso'] ?? null),
                'estado_logistico' => $this->normalizeTransportEnum(
                    $item['estado_logistico'] ?? null,
                    RQMinaActividadTransporte::estadosLogisticos(),
                    RQMinaActividadTransporte::ESTADO_REQUERIDO
                ),
                'indicaciones' => trim((string) ($item['indicaciones'] ?? '')),
                'comentario_cambio' => trim((string) ($item['comentario_cambio'] ?? '')),
                'incidencia_operativa' => trim((string) ($item['incidencia_operativa'] ?? '')),
                'recepcion_fecha' => $recepcionFecha,
                'recepcion_estado' => $this->normalizeTransportEnum(
                    $item['recepcion_estado'] ?? null,
                    RQMinaActividadTransporte::estadosRecepcion(),
                    RQMinaActividadTransporte::RECEPCION_PENDIENTE
                ),
                'recepcion_observacion' => trim((string) ($item['recepcion_observacion'] ?? '')),
            ];

            $hasData = collect($row)
                ->except(['actividad_key', 'origen', 'estado_logistico', 'recepcion_estado'])
                ->filter(fn ($value): bool => trim((string) $value) !== '')
                ->isNotEmpty();

            if (!$hasData) {
                continue;
            }

            foreach ([
                'alcance',
                'unidad_carga',
                'unidades_transporte',
                'placas_asignadas',
                'indicaciones',
                'comentario_cambio',
                'incidencia_operativa',
                'recepcion_observacion',
            ] as $field) {
                $row[$field] = $row[$field] !== '' ? $row[$field] : null;
            }

            $normalized[] = $row;
        }

        return $normalized;
    }

    private function normalizeTransportEnum(mixed $value, array $allowed, string $default, bool $nullable = false): ?string
    {
        $text = strtoupper(trim((string) $value));

        if ($text === '') {
            return $nullable ? null : $default;
        }

        return in_array($text, $allowed, true) ? $text : $default;
    }

    private function normalizeDateValue(mixed $value): ?string
    {
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        try {
            return Carbon::parse($text)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function calculateDiasUso(?string $fechaInicio, ?string $fechaFin, mixed $provided = null): ?int
    {
        if ($fechaInicio && $fechaFin) {
            try {
                $inicio = Carbon::parse($fechaInicio)->startOfDay();
                $fin = Carbon::parse($fechaFin)->startOfDay();

                if ($fin->lt($inicio)) {
                    return null;
                }

                return $inicio->diffInDays($fin) + 1;
            } catch (\Throwable) {
                return null;
            }
        }

        if (is_numeric($provided)) {
            return max(0, (int) $provided);
        }

        return null;
    }

    private function snapshotActividadTransportes(RQMina $rqMina): array
    {
        $rqMina->loadMissing('actividadGrupos.transportes');
        $snapshots = [];

        foreach ($rqMina->actividadGrupos as $groupIndex => $group) {
            foreach ($group->transportes as $transportIndex => $transport) {
                $snapshot = [
                    'key' => $this->transportPlanKey([
                        'alcance' => (string) ($transport->alcance ?? ''),
                        'unidad_carga' => (string) ($transport->unidad_carga ?? ''),
                        'unidades_transporte' => (string) ($transport->unidades_transporte ?? ''),
                        'placas_asignadas' => (string) ($transport->placas_asignadas ?? ''),
                    ], (string) ($group->nombre ?? ''), (int) $groupIndex, (int) $transportIndex),
                    'grupo' => (string) ($group->nombre ?? ''),
                    'alcance' => (string) ($transport->alcance ?? ''),
                    'unidad_carga' => (string) ($transport->unidad_carga ?? ''),
                    'origen' => (string) ($transport->origen ?? ''),
                    'unidades_transporte' => (string) ($transport->unidades_transporte ?? ''),
                    'placas_asignadas' => (string) ($transport->placas_asignadas ?? ''),
                    'fecha_inicio' => $transport->fecha_inicio?->toDateString(),
                    'fecha_fin' => $transport->fecha_fin?->toDateString(),
                    'dias_uso' => $transport->dias_uso,
                    'estado_logistico' => (string) ($transport->estado_logistico ?? ''),
                    'indicaciones' => (string) ($transport->indicaciones ?? ''),
                    'comentario_cambio' => (string) ($transport->comentario_cambio ?? ''),
                    'incidencia_operativa' => (string) ($transport->incidencia_operativa ?? ''),
                    'recepcion_fecha' => $transport->recepcion_fecha?->toDateString(),
                    'recepcion_estado' => (string) ($transport->recepcion_estado ?? ''),
                    'recepcion_observacion' => (string) ($transport->recepcion_observacion ?? ''),
                ];

                $snapshots[$snapshot['key']] = $snapshot;
            }
        }

        return $snapshots;
    }

    private function normalizedTransportSnapshots(array $groups): array
    {
        $snapshots = [];

        foreach ($groups as $groupIndex => $group) {
            foreach (($group['transportes'] ?? []) as $transportIndex => $transport) {
                $snapshot = $this->transportSnapshotFromRow($transport, (string) ($group['nombre'] ?? ''), (int) $groupIndex, (int) $transportIndex);
                $snapshots[$snapshot['key']] = $snapshot;
            }
        }

        return $snapshots;
    }

    private function transportSnapshotFromRow(array $row, string $groupName, int $groupIndex, int $transportIndex): array
    {
        return [
            'key' => $this->transportPlanKey($row, $groupName, $groupIndex, $transportIndex),
            'grupo' => $groupName,
            'alcance' => $row['alcance'] ?? null,
            'unidad_carga' => $row['unidad_carga'] ?? null,
            'origen' => $row['origen'] ?? null,
            'unidades_transporte' => $row['unidades_transporte'] ?? null,
            'placas_asignadas' => $row['placas_asignadas'] ?? null,
            'fecha_inicio' => $row['fecha_inicio'] ?? null,
            'fecha_fin' => $row['fecha_fin'] ?? null,
            'dias_uso' => $row['dias_uso'] ?? null,
            'estado_logistico' => $row['estado_logistico'] ?? null,
            'indicaciones' => $row['indicaciones'] ?? null,
            'comentario_cambio' => $row['comentario_cambio'] ?? null,
            'incidencia_operativa' => $row['incidencia_operativa'] ?? null,
            'recepcion_fecha' => $row['recepcion_fecha'] ?? null,
            'recepcion_estado' => $row['recepcion_estado'] ?? null,
            'recepcion_observacion' => $row['recepcion_observacion'] ?? null,
        ];
    }

    private function transportPlanKey(array $row, string $groupName, int $groupIndex, int $transportIndex): string
    {
        $parts = [
            mb_strtolower(trim($groupName)),
            mb_strtolower(trim((string) ($row['alcance'] ?? ''))),
            mb_strtolower(trim((string) ($row['unidad_carga'] ?? ''))),
            mb_strtolower(trim((string) ($row['unidades_transporte'] ?? ''))),
            mb_strtolower(trim((string) ($row['placas_asignadas'] ?? ''))),
            (string) $groupIndex,
            (string) $transportIndex,
        ];

        return md5(implode('|', $parts));
    }

    private function comparableTransportSnapshot(array $snapshot): array
    {
        unset($snapshot['key']);

        ksort($snapshot);

        return $snapshot;
    }

    private function recordTransportPlanChanges(RQMina $rqMina, array $previous, array $groups, ?Usuario $usuario): void
    {
        $current = $this->normalizedTransportSnapshots($groups);

        foreach ($current as $key => $snapshot) {
            if (!isset($previous[$key])) {
                $this->recordTransportEvent(
                    $rqMina,
                    RQMinaActividadTransporteEvento::TIPO_REGISTRO,
                    null,
                    $snapshot['estado_logistico'] ?? null,
                    'Registro de transporte en plan operativo.',
                    $snapshot,
                    $usuario
                );
                continue;
            }

            if ($this->comparableTransportSnapshot($previous[$key]) !== $this->comparableTransportSnapshot($snapshot)) {
                $this->recordTransportEvent(
                    $rqMina,
                    RQMinaActividadTransporteEvento::TIPO_CAMBIO,
                    $previous[$key]['estado_logistico'] ?? null,
                    $snapshot['estado_logistico'] ?? null,
                    $snapshot['comentario_cambio'] ?: 'Cambio en datos de transporte.',
                    ['anterior' => $previous[$key], 'nuevo' => $snapshot],
                    $usuario
                );
            }
        }

        foreach ($previous as $key => $snapshot) {
            if (isset($current[$key])) {
                continue;
            }

            $this->recordTransportEvent(
                $rqMina,
                RQMinaActividadTransporteEvento::TIPO_RETIRO,
                $snapshot['estado_logistico'] ?? null,
                RQMinaActividadTransporte::ESTADO_RETIRADO,
                'Transporte retirado del plan operativo.',
                $snapshot,
                $usuario
            );
        }
    }

    private function recordTransportEvent(
        RQMina $rqMina,
        string $tipo,
        ?string $estadoAnterior,
        ?string $estadoNuevo,
        ?string $descripcion,
        array $snapshot,
        ?Usuario $usuario
    ): void {
        RQMinaActividadTransporteEvento::query()->create([
            'id' => (string) Str::uuid(),
            'rq_mina_id' => (string) $rqMina->id,
            'transporte_id' => null,
            'tipo' => $tipo,
            'estado_anterior' => $estadoAnterior,
            'estado_nuevo' => $estadoNuevo,
            'descripcion' => $descripcion,
            'transporte_snapshot' => $snapshot,
            'fecha_evento' => now(),
            'usuario_id' => $usuario?->id,
        ]);
    }

    private function resolveSupervisorId(mixed $supervisorId): ?string
    {
        $id = trim((string) $supervisorId);
        if ($id === '') {
            return null;
        }

        return Personal::query()
            ->where('id', $id)
            ->where('es_supervisor', true)
            ->exists()
                ? $id
                : null;
    }

    private function findAvailableMinaByName(Usuario $usuario, string $name): ?Mina
    {
        $normalized = mb_strtolower(trim($name));

        return $this->getAvailableMinas($usuario)
            ->first(fn (Mina $mina): bool => mb_strtolower(trim((string) $mina->nombre)) === $normalized);
    }

    private function resolveAnchorMinaId(Usuario $usuario, string $preferredMinaId = ''): ?string
    {
        if (
            $preferredMinaId !== ''
            && Mina::query()->where('id', $preferredMinaId)->where('estado', 'ACTIVO')->exists()
            && $this->policy->canAccessMina($usuario, $preferredMinaId)
        ) {
            return $preferredMinaId;
        }

        $available = $this->getAvailableMinas($usuario)->first();

        return $available ? (string) $available->id : null;
    }

    public function createForUser(Usuario $usuario, array $payload): array
    {
        $rqMina = $this->create($usuario, $payload);
        
        if (!$rqMina) {
            return ['success' => false, 'message' => 'No tienes permiso para crear solicitudes en esta mina'];
        }
        
        return ['success' => true, 'message' => 'Solicitud creada correctamente', 'data' => $rqMina];
    }

    public function updateForUser(Usuario $usuario, string $id, array $payload): array
    {
        $rqMina = RQMina::query()->find($id);
        
        if (!$rqMina) {
            return ['success' => false, 'message' => 'Solicitud no encontrada'];
        }
        
        $updated = $this->update($usuario, $rqMina, $payload);
        
        if (!$updated) {
            return ['success' => false, 'message' => 'No tienes permiso para actualizar esta solicitud'];
        }
        
        return ['success' => true, 'message' => 'Solicitud actualizada correctamente', 'data' => $updated];
    }
}
