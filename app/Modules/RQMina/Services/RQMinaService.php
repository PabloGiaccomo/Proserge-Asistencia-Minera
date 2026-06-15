<?php

namespace App\Modules\RQMina\Services;

use App\Models\Mina;
use App\Models\Oficina;
use App\Models\Personal;
use App\Models\RQMina;
use App\Models\RQMinaActividad;
use App\Models\RQMinaActividadGrupo;
use App\Models\RQMinaFieldOption;
use App\Models\Taller;
use App\Models\Usuario;
use App\Modules\RQMina\Policies\RQMinaPolicy;
use App\Support\Rbac\PermissionMatrix;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class RQMinaService
{
    public function __construct(private readonly RQMinaPolicy $policy)
    {
    }

    public function listForUser(Usuario $usuario, array $filters, int $perPage = 10, int $page = 1): array
    {
        $query = RQMina::query()->with([
            'mina:id,nombre',
            'creador:id,email,personal_id',
            'creador.personal:id,nombre_completo',
            'supervisor:id,dni,nombre_completo,puesto,es_supervisor',
            'detalle:id,rq_mina_id,puesto,cantidad,cantidad_atendida',
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
            ->with(['mina:id,nombre', 'creador:id,email,personal_id', 'creador.personal:id,nombre_completo', 'supervisor:id,dni,nombre_completo,puesto,es_supervisor', 'detalle', 'transportes', 'actividadGrupos.actividades.turnos', 'actividadGrupos.transportes'])
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
                'area' => $payload['area'],
                'fecha_inicio' => $payload['fecha_inicio'],
                'fecha_fin' => $payload['fecha_fin'],
                'observaciones' => $payload['observaciones'] ?? null,
                'estado' => 'BORRADOR',
                'created_by_usuario_id' => $usuario->id,
            ]);

            $rows = collect($detallePayload)->map(fn (array $item): array => [
                'id' => (string) Str::uuid(),
                'rq_mina_id' => $rqMina->id,
                'puesto' => $item['puesto'],
                'cantidad' => (int) $item['cantidad'],
                'cantidad_atendida' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ])->all();

            $rqMina->detalle()->insert($rows);

            $transportRows = $this->buildTransporteRows((string) $rqMina->id, $payload['transporte'] ?? []);
            if (!empty($transportRows)) {
                $rqMina->transportes()->insert($transportRows);
            }

            $this->replacePlanOperativo($rqMina, $payload['plan_operativo'] ?? []);
            $this->rememberFieldOptions($usuario, $payload);

            Log::info('rqmina.detail_persisted', [
                'rq_mina_id' => (string) $rqMina->id,
                'detalle_guardado' => array_map(static fn (array $row): array => [
                    'puesto' => (string) ($row['puesto'] ?? ''),
                    'cantidad' => (int) ($row['cantidad'] ?? 0),
                ], $rows),
                'cantidad_puestos' => count($rows),
                'cantidad_total' => collect($rows)->sum(fn (array $row) => (int) ($row['cantidad'] ?? 0)),
                'transporte_guardado' => array_map(static fn (array $row): array => [
                    'transporte' => (string) ($row['transporte'] ?? ''),
                    'cantidad' => (int) ($row['cantidad'] ?? 0),
                ], $transportRows),
                'supervisor_id' => (string) ($rqMina->supervisor_id ?? ''),
            ]);

            return $rqMina->load([
                'mina:id,nombre',
                'creador:id,email,personal_id',
                'creador.personal:id,nombre_completo',
                'supervisor:id,dni,nombre_completo,puesto,es_supervisor',
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
                'area' => $payload['area'],
                'fecha_inicio' => $payload['fecha_inicio'],
                'fecha_fin' => $payload['fecha_fin'],
                'observaciones' => $payload['observaciones'] ?? null,
            ]);
            $rqMina->save();

            $rqMina->detalle()->delete();
            $rqMina->transportes()->delete();

            $rows = collect($detallePayload)->map(fn (array $item): array => [
                'id' => (string) Str::uuid(),
                'rq_mina_id' => $rqMina->id,
                'puesto' => $item['puesto'],
                'cantidad' => (int) $item['cantidad'],
                'cantidad_atendida' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ])->all();

            $rqMina->detalle()->insert($rows);

            $transportRows = $this->buildTransporteRows((string) $rqMina->id, $payload['transporte'] ?? []);
            if (!empty($transportRows)) {
                $rqMina->transportes()->insert($transportRows);
            }

            $this->replacePlanOperativo($rqMina, $payload['plan_operativo'] ?? []);
            $this->rememberFieldOptions($usuario, $payload);

            Log::info('rqmina.detail_persisted', [
                'rq_mina_id' => (string) $rqMina->id,
                'detalle_guardado' => array_map(static fn (array $row): array => [
                    'puesto' => (string) ($row['puesto'] ?? ''),
                    'cantidad' => (int) ($row['cantidad'] ?? 0),
                ], $rows),
                'cantidad_puestos' => count($rows),
                'cantidad_total' => collect($rows)->sum(fn (array $row) => (int) ($row['cantidad'] ?? 0)),
                'transporte_guardado' => array_map(static fn (array $row): array => [
                    'transporte' => (string) ($row['transporte'] ?? ''),
                    'cantidad' => (int) ($row['cantidad'] ?? 0),
                ], $transportRows),
                'supervisor_id' => (string) ($rqMina->supervisor_id ?? ''),
            ]);

            return $rqMina->load([
                'mina:id,nombre',
                'creador:id,email,personal_id',
                'creador.personal:id,nombre_completo',
                'supervisor:id,dni,nombre_completo,puesto,es_supervisor',
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
                'area' => $payload['area'],
                'fecha_inicio' => $payload['fecha_inicio'],
                'fecha_fin' => $payload['fecha_fin'],
                'observaciones' => $payload['observaciones'] ?? null,
            ]);
            $rqMina->save();
            $this->rememberFieldOptions($usuario, $payload);

            return $rqMina->load([
                'mina:id,nombre',
                'creador:id,email,personal_id',
                'creador.personal:id,nombre_completo',
                'supervisor:id,dni,nombre_completo,puesto,es_supervisor',
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

        return $rqMina->load(['mina:id,nombre', 'creador:id,email,personal_id', 'creador.personal:id,nombre_completo', 'supervisor:id,dni,nombre_completo,puesto,es_supervisor', 'detalle', 'transportes', 'actividadGrupos.actividades.turnos', 'actividadGrupos.transportes']);
    }

    public function updatePlanOperativo(Usuario $usuario, RQMina $rqMina, array $planOperativo): ?RQMina
    {
        if (!$this->policy->update($usuario, $rqMina)) {
            return null;
        }

        return DB::transaction(function () use ($usuario, $rqMina, $planOperativo): RQMina {
            $this->replacePlanOperativo($rqMina, $planOperativo);
            $this->rememberFieldOptions($usuario, ['plan_operativo' => $planOperativo]);

            return $rqMina->fresh([
                'mina:id,nombre',
                'creador:id,email,personal_id',
                'creador.personal:id,nombre_completo',
                'supervisor:id,dni,nombre_completo,puesto,es_supervisor',
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

                foreach (($activity['turnos'] ?? []) as $turno) {
                    $this->rememberFieldOption($usuario, 'rq_mina.plan.turno_a', $turno['turno_a'] ?? null);
                    $this->rememberFieldOption($usuario, 'rq_mina.plan.real_turno_a', $turno['real_turno_a'] ?? null);
                    $this->rememberFieldOption($usuario, 'rq_mina.plan.turno_b', $turno['turno_b'] ?? null);
                    $this->rememberFieldOption($usuario, 'rq_mina.plan.real_turno_b', $turno['real_turno_b'] ?? $turno['real'] ?? null);
                }
            }

            foreach (($group['transportes'] ?? []) as $transport) {
                $this->rememberFieldOption($usuario, 'rq_mina.plan.transporte_alcance', $transport['alcance'] ?? null);
                $this->rememberFieldOption($usuario, 'rq_mina.plan.unidad_carga', $transport['unidad_carga'] ?? null);
                $this->rememberFieldOption($usuario, 'rq_mina.plan.unidades_transporte', $transport['unidades_transporte'] ?? null);
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
            $rqMina->delete();

            Log::info('rqmina.deleted', [
                'rq_mina_id' => $rqId,
            ]);

            return true;
        });
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
        $detalle = collect($payload['detalle'] ?? [])
            ->filter(fn ($item): bool => is_array($item) && trim((string) ($item['puesto'] ?? '')) !== '' && (int) ($item['cantidad'] ?? 0) > 0)
            ->values()
            ->all();

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
            return $fromPlan;
        }

        return [
            [
                'puesto' => 'Parada registrada',
                'cantidad' => 1,
            ],
        ];
    }

    private function replacePlanOperativo(RQMina $rqMina, mixed $groups): void
    {
        $normalized = $this->normalizePlanOperativo($groups);

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
                    'unidades_transporte' => $transporte['unidades_transporte'],
                    'indicaciones' => $transporte['indicaciones'],
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

            $row = [
                'actividad_key' => trim((string) ($item['actividad_key'] ?? '')),
                'alcance' => trim((string) ($item['alcance'] ?? '')),
                'unidad_carga' => trim((string) ($item['unidad_carga'] ?? '')),
                'unidades_transporte' => trim((string) ($item['unidades_transporte'] ?? '')),
                'indicaciones' => trim((string) ($item['indicaciones'] ?? '')),
            ];

            if ($row['alcance'] === '' && $row['unidad_carga'] === '' && $row['unidades_transporte'] === '' && $row['indicaciones'] === '') {
                continue;
            }

            foreach (['alcance', 'unidad_carga', 'unidades_transporte', 'indicaciones'] as $field) {
                $row[$field] = $row[$field] !== '' ? $row[$field] : null;
            }

            $normalized[] = $row;
        }

        return $normalized;
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
