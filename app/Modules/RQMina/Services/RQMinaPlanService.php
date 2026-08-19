<?php

namespace App\Modules\RQMina\Services;

use App\Models\RQMina;
use App\Models\RQMinaActividad;
use App\Models\RQMinaActividadGrupo;
use App\Models\RQMinaActividadTransporte;
use App\Models\RQMinaActividadTurno;
use App\Models\RQMinaPlan;
use App\Models\Usuario;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class RQMinaPlanService
{
    public function createDefaultPlan(RQMina $rqMina, ?Usuario $usuario = null): RQMinaPlan
    {
        return DB::transaction(function () use ($rqMina, $usuario): RQMinaPlan {
            RQMina::query()->whereKey((string) $rqMina->id)->lockForUpdate()->first();

            $existing = $this->defaultPlanQuery($rqMina)->lockForUpdate()->first();
            if ($existing) {
                return $existing;
            }

            $this->validatePlanRange($rqMina, $rqMina->fecha_inicio, $rqMina->fecha_fin);

            return RQMinaPlan::query()->create([
                'id' => (string) Str::uuid(),
                'rq_mina_id' => (string) $rqMina->id,
                'codigo' => RQMinaPlan::CODIGO_DEFAULT,
                'nombre' => 'Plan operativo inicial',
                'version' => 1,
                'fecha_inicio' => $this->toDateString($rqMina->fecha_inicio),
                'fecha_fin' => $this->toDateString($rqMina->fecha_fin),
                'semana_referencia' => $this->buildSemanaReferencia($rqMina->fecha_inicio, $rqMina->fecha_fin),
                'estado' => RQMinaPlan::ESTADO_BORRADOR,
                'observaciones' => null,
                'created_by_usuario_id' => $usuario?->id,
                'updated_by_usuario_id' => $usuario?->id,
            ]);
        });
    }

    public function ensureDefaultPlan(RQMina $rqMina, ?Usuario $usuario = null): RQMinaPlan
    {
        $existing = $this->defaultPlanQuery($rqMina)->first();

        return $existing ?: $this->createDefaultPlan($rqMina, $usuario);
    }

    public function createPlan(RQMina $rqMina, array $payload, ?Usuario $usuario = null): RQMinaPlan
    {
        return DB::transaction(function () use ($rqMina, $payload, $usuario): RQMinaPlan {
            RQMina::query()->whereKey((string) $rqMina->id)->lockForUpdate()->first();
            $this->ensureDefaultPlan($rqMina, $usuario);

            $fechaInicio = $this->toDateString($payload['fecha_inicio'] ?? null);
            $fechaFin = $this->toDateString($payload['fecha_fin'] ?? null);
            $this->validatePlanRange($rqMina, $fechaInicio, $fechaFin);

            $estado = $this->normalizeEditableStatus($payload['estado'] ?? RQMinaPlan::ESTADO_BORRADOR);
            $codigo = $this->nextPlanCode($rqMina);

            return RQMinaPlan::query()->create([
                'id' => (string) Str::uuid(),
                'rq_mina_id' => (string) $rqMina->id,
                'codigo' => $codigo,
                'nombre' => $this->requiredText($payload['nombre'] ?? null, 'El nombre del plan es requerido.'),
                'version' => 1,
                'fecha_inicio' => $fechaInicio,
                'fecha_fin' => $fechaFin,
                'semana_referencia' => $this->normalizeSemanaReferencia($payload['semana_referencia'] ?? null, $fechaInicio, $fechaFin),
                'estado' => $estado,
                'observaciones' => $this->nullableText($payload['observaciones'] ?? null),
                'created_by_usuario_id' => $usuario?->id,
                'updated_by_usuario_id' => $usuario?->id,
            ]);
        });
    }

    public function updatePlan(RQMina $rqMina, RQMinaPlan $plan, array $payload, ?Usuario $usuario = null): RQMinaPlan
    {
        $this->assertPlanBelongsToRQMina($rqMina, $plan);

        return DB::transaction(function () use ($rqMina, $plan, $payload, $usuario): RQMinaPlan {
            $locked = RQMinaPlan::query()
                ->whereKey((string) $plan->id)
                ->where('rq_mina_id', (string) $rqMina->id)
                ->lockForUpdate()
                ->first();

            if (!$locked) {
                throw new InvalidArgumentException('El plan operativo seleccionado no pertenece a esta parada.');
            }

            if ($locked->estado === RQMinaPlan::ESTADO_ARCHIVADO) {
                throw new InvalidArgumentException('No se puede editar un plan archivado.');
            }

            $fechaInicio = array_key_exists('fecha_inicio', $payload) ? $this->toDateString($payload['fecha_inicio']) : $this->toDateString($locked->fecha_inicio);
            $fechaFin = array_key_exists('fecha_fin', $payload) ? $this->toDateString($payload['fecha_fin']) : $this->toDateString($locked->fecha_fin);
            $this->validatePlanRange($rqMina, $fechaInicio, $fechaFin);

            if ($fechaInicio !== $this->toDateString($locked->fecha_inicio) || $fechaFin !== $this->toDateString($locked->fecha_fin)) {
                $this->assertExistingRecordsFitRange($rqMina, $locked, $fechaInicio, $fechaFin);
            }

            $locked->fill([
                'nombre' => array_key_exists('nombre', $payload)
                    ? $this->requiredText($payload['nombre'], 'El nombre del plan es requerido.')
                    : $locked->nombre,
                'fecha_inicio' => $fechaInicio,
                'fecha_fin' => $fechaFin,
                'semana_referencia' => array_key_exists('semana_referencia', $payload)
                    ? $this->normalizeSemanaReferencia($payload['semana_referencia'], $fechaInicio, $fechaFin)
                    : $locked->semana_referencia,
                'estado' => array_key_exists('estado', $payload)
                    ? $this->normalizeEditableStatus($payload['estado'])
                    : $locked->estado,
                'observaciones' => array_key_exists('observaciones', $payload)
                    ? $this->nullableText($payload['observaciones'])
                    : $locked->observaciones,
                'updated_by_usuario_id' => $usuario?->id,
            ]);
            $locked->save();

            return $locked->fresh(['grupos.actividades.turnos', 'grupos.transportes']);
        });
    }

    public function duplicatePlan(RQMina $rqMina, RQMinaPlan $sourcePlan, array $payload, ?Usuario $usuario = null): RQMinaPlan
    {
        $this->assertPlanBelongsToRQMina($rqMina, $sourcePlan);

        return DB::transaction(function () use ($rqMina, $sourcePlan, $payload, $usuario): RQMinaPlan {
            RQMina::query()->whereKey((string) $rqMina->id)->lockForUpdate()->first();

            $source = RQMinaPlan::query()
                ->with(['grupos.actividades.turnos', 'grupos.transportes'])
                ->whereKey((string) $sourcePlan->id)
                ->where('rq_mina_id', (string) $rqMina->id)
                ->lockForUpdate()
                ->first();

            if (!$source) {
                throw new InvalidArgumentException('El plan origen no pertenece a esta parada.');
            }

            $fechaInicio = $this->toDateString($payload['fecha_inicio'] ?? null);
            $fechaFin = $this->toDateString($payload['fecha_fin'] ?? null);
            $this->validatePlanRange($rqMina, $fechaInicio, $fechaFin);
            $offsetDays = (int) Carbon::parse($source->fecha_inicio)->startOfDay()->diffInDays(Carbon::parse($fechaInicio)->startOfDay(), false);

            $this->assertDuplicatedRecordsFitRange($source, $fechaInicio, $fechaFin, $offsetDays);

            $newPlan = RQMinaPlan::query()->create([
                'id' => (string) Str::uuid(),
                'rq_mina_id' => (string) $rqMina->id,
                'codigo' => $this->nextPlanCode($rqMina),
                'nombre' => $this->requiredText($payload['nombre'] ?? null, 'El nombre del nuevo plan es requerido.'),
                'version' => 1,
                'fecha_inicio' => $fechaInicio,
                'fecha_fin' => $fechaFin,
                'semana_referencia' => $this->buildSemanaReferencia($fechaInicio, $fechaFin),
                'estado' => RQMinaPlan::ESTADO_BORRADOR,
                'observaciones' => $this->nullableText($payload['observaciones'] ?? null),
                'created_by_usuario_id' => $usuario?->id,
                'updated_by_usuario_id' => $usuario?->id,
            ]);

            $this->copyPlanStructure($source, $newPlan, $offsetDays);

            return $newPlan->fresh(['grupos.actividades.turnos', 'grupos.transportes']);
        });
    }

    public function archivePlan(RQMina $rqMina, RQMinaPlan $plan, ?Usuario $usuario = null): RQMinaPlan
    {
        $this->assertPlanBelongsToRQMina($rqMina, $plan);

        return DB::transaction(function () use ($rqMina, $plan, $usuario): RQMinaPlan {
            $locked = RQMinaPlan::query()
                ->whereKey((string) $plan->id)
                ->where('rq_mina_id', (string) $rqMina->id)
                ->lockForUpdate()
                ->first();

            if (!$locked) {
                throw new InvalidArgumentException('El plan operativo seleccionado no pertenece a esta parada.');
            }

            $activeCount = RQMinaPlan::query()
                ->where('rq_mina_id', (string) $rqMina->id)
                ->where('estado', '!=', RQMinaPlan::ESTADO_ARCHIVADO)
                ->lockForUpdate()
                ->get(['id'])
                ->count();

            if ($activeCount <= 1 && $locked->estado !== RQMinaPlan::ESTADO_ARCHIVADO) {
                throw new InvalidArgumentException('Debe existir al menos un plan no archivado dentro de la parada.');
            }

            if ($this->isDefaultPlan($locked) && $this->hasLegacyGroups($rqMina)) {
                throw new InvalidArgumentException('No se puede archivar PLAN-001 mientras existan grupos historicos sin plan vinculado.');
            }

            $locked->fill([
                'estado' => RQMinaPlan::ESTADO_ARCHIVADO,
                'updated_by_usuario_id' => $usuario?->id,
            ]);
            $locked->save();

            return $locked->fresh(['grupos.actividades.turnos', 'grupos.transportes']);
        });
    }

    public function resolveSelectedPlan(RQMina $rqMina, ?string $planId, ?Usuario $usuario = null): RQMinaPlan
    {
        $planId = trim((string) $planId);

        if ($planId !== '') {
            $plan = RQMinaPlan::query()
                ->whereKey($planId)
                ->where('rq_mina_id', (string) $rqMina->id)
                ->first();

            if (!$plan) {
                throw new InvalidArgumentException('El plan operativo seleccionado no pertenece a esta parada.');
            }

            return $plan;
        }

        $default = $this->ensureDefaultPlan($rqMina, $usuario);
        if ($default->estado !== RQMinaPlan::ESTADO_ARCHIVADO) {
            return $default;
        }

        $fallback = RQMinaPlan::query()
            ->where('rq_mina_id', (string) $rqMina->id)
            ->where('estado', '!=', RQMinaPlan::ESTADO_ARCHIVADO)
            ->orderBy('codigo')
            ->first();

        if ($fallback) {
            return $fallback;
        }

        return $default;
    }

    public function firstEditablePlanId(RQMina $rqMina, ?string $excludePlanId = null): ?string
    {
        return RQMinaPlan::query()
            ->where('rq_mina_id', (string) $rqMina->id)
            ->where('estado', '!=', RQMinaPlan::ESTADO_ARCHIVADO)
            ->when($excludePlanId, fn ($query) => $query->where('id', '!=', (string) $excludePlanId))
            ->orderBy('codigo')
            ->value('id');
    }

    public function validatePlanRange(RQMina $rqMina, mixed $fechaInicio, mixed $fechaFin): void
    {
        $inicio = $this->parseDate($fechaInicio, 'fecha_inicio');
        $fin = $this->parseDate($fechaFin, 'fecha_fin');
        $rqInicio = $this->parseDate($rqMina->fecha_inicio, 'rq_fecha_inicio');
        $rqFin = $this->parseDate($rqMina->fecha_fin, 'rq_fecha_fin');

        if ($inicio->gt($fin)) {
            throw new InvalidArgumentException('La fecha fin del plan no puede ser anterior a su fecha de inicio.');
        }

        if ($inicio->lt($rqInicio)) {
            throw new InvalidArgumentException('El plan no puede iniciar antes de la parada.');
        }

        if ($fin->gt($rqFin)) {
            throw new InvalidArgumentException('El plan no puede terminar despues de la parada.');
        }
    }

    public function resolvePlanForLegacyPayload(RQMina $rqMina, ?string $planId, ?Usuario $usuario = null): RQMinaPlan
    {
        $plan = $this->resolveSelectedPlan($rqMina, $planId, $usuario);

        $this->validatePlanRange($rqMina, $plan->fecha_inicio, $plan->fecha_fin);

        if ($plan->estado === RQMinaPlan::ESTADO_ARCHIVADO) {
            throw new InvalidArgumentException('No se puede modificar un plan archivado.');
        }

        return $plan;
    }

    public function isDefaultPlan(RQMinaPlan $plan): bool
    {
        return (string) $plan->codigo === RQMinaPlan::CODIGO_DEFAULT && (int) $plan->version === 1;
    }

    public function planToArray(RQMinaPlan $plan): array
    {
        return [
            'id' => (string) $plan->id,
            'codigo' => (string) $plan->codigo,
            'nombre' => (string) $plan->nombre,
            'version' => (int) $plan->version,
            'fecha_inicio' => $plan->fecha_inicio?->toDateString(),
            'fecha_fin' => $plan->fecha_fin?->toDateString(),
            'semana_referencia' => (string) ($plan->semana_referencia ?? ''),
            'estado' => (string) $plan->estado,
            'observaciones' => (string) ($plan->observaciones ?? ''),
        ];
    }

    private function defaultPlanQuery(RQMina $rqMina)
    {
        return RQMinaPlan::query()
            ->where('rq_mina_id', (string) $rqMina->id)
            ->where('codigo', RQMinaPlan::CODIGO_DEFAULT)
            ->where('version', 1);
    }

    private function nextPlanCode(RQMina $rqMina): string
    {
        $max = RQMinaPlan::query()
            ->where('rq_mina_id', (string) $rqMina->id)
            ->lockForUpdate()
            ->pluck('codigo')
            ->map(function ($codigo): int {
                return preg_match('/^PLAN-(\d+)$/', (string) $codigo, $matches) ? (int) $matches[1] : 0;
            })
            ->max() ?? 0;

        return 'PLAN-' . str_pad((string) ($max + 1), 3, '0', STR_PAD_LEFT);
    }

    private function assertPlanBelongsToRQMina(RQMina $rqMina, RQMinaPlan $plan): void
    {
        if ((string) $plan->rq_mina_id !== (string) $rqMina->id) {
            throw new InvalidArgumentException('El plan operativo seleccionado no pertenece a esta parada.');
        }
    }

    private function assertExistingRecordsFitRange(RQMina $rqMina, RQMinaPlan $plan, string $fechaInicio, string $fechaFin): void
    {
        $errors = [];
        $inicio = Carbon::parse($fechaInicio)->startOfDay();
        $fin = Carbon::parse($fechaFin)->startOfDay();
        $groups = $this->groupsForPlanQuery($rqMina, $plan)->pluck('id');

        if ($groups->isEmpty()) {
            return;
        }

        $turnosFuera = RQMinaActividadTurno::query()
            ->whereHas('actividad', fn ($query) => $query->whereIn('grupo_id', $groups))
            ->whereNotNull('fecha')
            ->where(function ($query) use ($inicio, $fin): void {
                $query->whereDate('fecha', '<', $inicio->toDateString())
                    ->orWhereDate('fecha', '>', $fin->toDateString());
            })
            ->limit(5)
            ->pluck('fecha')
            ->map(fn ($fecha): string => Carbon::parse($fecha)->format('d/m/Y'))
            ->all();

        if (!empty($turnosFuera)) {
            $errors[] = 'turnos fuera del nuevo rango: ' . implode(', ', $turnosFuera);
        }

        $transportesFuera = RQMinaActividadTransporte::query()
            ->whereIn('grupo_id', $groups)
            ->where(function ($query) use ($inicio, $fin): void {
                $query->where(function ($inner) use ($inicio, $fin): void {
                    $inner->whereNotNull('fecha_inicio')
                        ->whereDate('fecha_inicio', '<', $inicio->toDateString());
                })->orWhere(function ($inner) use ($inicio, $fin): void {
                    $inner->whereNotNull('fecha_fin')
                        ->whereDate('fecha_fin', '>', $fin->toDateString());
                });
            })
            ->limit(5)
            ->get(['fecha_inicio', 'fecha_fin'])
            ->map(fn ($row): string => trim(($row->fecha_inicio?->format('d/m/Y') ?? '-') . ' al ' . ($row->fecha_fin?->format('d/m/Y') ?? '-')))
            ->all();

        if (!empty($transportesFuera)) {
            $errors[] = 'transportes fuera del nuevo rango: ' . implode(', ', $transportesFuera);
        }

        if (!empty($errors)) {
            throw new InvalidArgumentException('No se puede reducir el rango del plan porque existen ' . implode('; ', $errors) . '.');
        }
    }

    private function assertDuplicatedRecordsFitRange(RQMinaPlan $source, string $fechaInicio, string $fechaFin, int $offsetDays): void
    {
        $inicio = Carbon::parse($fechaInicio)->startOfDay();
        $fin = Carbon::parse($fechaFin)->startOfDay();
        $errors = [];

        foreach ($source->grupos as $group) {
            foreach ($group->actividades as $activity) {
                foreach ($activity->turnos as $turno) {
                    if (!$turno->fecha) {
                        continue;
                    }

                    $shifted = Carbon::parse($turno->fecha)->addDays($offsetDays)->startOfDay();
                    if ($shifted->lt($inicio) || $shifted->gt($fin)) {
                        $errors[] = 'turno ' . $shifted->format('d/m/Y');
                    }
                }
            }

            foreach ($group->transportes as $transport) {
                foreach (['fecha_inicio', 'fecha_fin'] as $field) {
                    if (!$transport->{$field}) {
                        continue;
                    }

                    $shifted = Carbon::parse($transport->{$field})->addDays($offsetDays)->startOfDay();
                    if ($shifted->lt($inicio) || $shifted->gt($fin)) {
                        $errors[] = 'transporte ' . $shifted->format('d/m/Y');
                    }
                }
            }
        }

        if (!empty($errors)) {
            $sample = implode(', ', array_slice(array_unique($errors), 0, 6));
            throw new InvalidArgumentException('El nuevo rango no contiene toda la estructura copiada. Registros fuera: ' . $sample . '.');
        }
    }

    private function copyPlanStructure(RQMinaPlan $source, RQMinaPlan $destination, int $offsetDays): void
    {
        foreach ($source->grupos as $groupIndex => $sourceGroup) {
            $newGroup = RQMinaActividadGrupo::query()->create([
                'id' => (string) Str::uuid(),
                'rq_mina_id' => (string) $destination->rq_mina_id,
                'rq_mina_plan_id' => (string) $destination->id,
                'area_operativa' => $sourceGroup->area_operativa,
                'modulo' => $sourceGroup->modulo,
                'nombre' => $sourceGroup->nombre,
                'observaciones' => $sourceGroup->observaciones,
                'orden' => $sourceGroup->orden ?: ($groupIndex + 1),
            ]);

            $activityMap = [];
            foreach ($sourceGroup->actividades as $activityIndex => $sourceActivity) {
                $newActivity = RQMinaActividad::query()->create([
                    'id' => (string) Str::uuid(),
                    'grupo_id' => (string) $newGroup->id,
                    'sait' => $sourceActivity->sait,
                    'sector' => $sourceActivity->sector,
                    'area' => $sourceActivity->area,
                    'ait_trabajo' => $sourceActivity->ait_trabajo,
                    'detalle_trabajos_relevantes' => $sourceActivity->detalle_trabajos_relevantes,
                    'supervisor_campo_dia' => $sourceActivity->supervisor_campo_dia,
                    'supervisor_campo_noche' => $sourceActivity->supervisor_campo_noche,
                    'supervisor_seguridad_dia' => $sourceActivity->supervisor_seguridad_dia,
                    'supervisor_seguridad_noche' => $sourceActivity->supervisor_seguridad_noche,
                    'orden' => $sourceActivity->orden ?: ($activityIndex + 1),
                ]);
                $activityMap[(string) $sourceActivity->id] = (string) $newActivity->id;

                $turnos = [];
                foreach ($sourceActivity->turnos as $turnoIndex => $sourceTurno) {
                    $turnos[] = [
                        'id' => (string) Str::uuid(),
                        'actividad_id' => (string) $newActivity->id,
                        'fecha' => $sourceTurno->fecha ? Carbon::parse($sourceTurno->fecha)->addDays($offsetDays)->toDateString() : null,
                        'dia_label' => $sourceTurno->dia_label,
                        'turno_a' => $sourceTurno->turno_a,
                        'real_turno_a' => null,
                        'turno_b' => $sourceTurno->turno_b,
                        'real_turno_b' => null,
                        'real' => null,
                        'orden' => $sourceTurno->orden ?: ($turnoIndex + 1),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }

                if (!empty($turnos)) {
                    $newActivity->turnos()->insert($turnos);
                }
            }

            $transportes = [];
            foreach ($sourceGroup->transportes as $transportIndex => $sourceTransport) {
                $fechaInicio = $sourceTransport->fecha_inicio ? Carbon::parse($sourceTransport->fecha_inicio)->addDays($offsetDays)->toDateString() : null;
                $fechaFin = $sourceTransport->fecha_fin ? Carbon::parse($sourceTransport->fecha_fin)->addDays($offsetDays)->toDateString() : null;
                $fecha = $sourceTransport->fecha ? Carbon::parse($sourceTransport->fecha)->addDays($offsetDays)->toDateString() : null;

                $transportes[] = [
                    'id' => (string) Str::uuid(),
                    'grupo_id' => (string) $newGroup->id,
                    'actividad_id' => $sourceTransport->actividad_id ? ($activityMap[(string) $sourceTransport->actividad_id] ?? null) : null,
                    'rq_mina_plan_id' => (string) $destination->id,
                    'alcance' => $sourceTransport->alcance,
                    'unidad_carga' => $sourceTransport->unidad_carga,
                    'fecha' => $fecha,
                    'turno' => $sourceTransport->turno,
                    'tipo_transporte' => $sourceTransport->tipo_transporte,
                    'capacidad_requerida' => $sourceTransport->capacidad_requerida,
                    'cantidad_unidades_requeridas' => $sourceTransport->cantidad_unidades_requeridas,
                    'origen' => $sourceTransport->origen,
                    'origen_snapshot' => $sourceTransport->origen_snapshot,
                    'destino_snapshot' => $sourceTransport->destino_snapshot,
                    'unidades_transporte' => $sourceTransport->unidades_transporte,
                    'placas_asignadas' => null,
                    'fecha_inicio' => $fechaInicio,
                    'fecha_fin' => $fechaFin,
                    'dias_uso' => $this->calculateDiasUso($fechaInicio, $fechaFin),
                    'estado_logistico' => RQMinaActividadTransporte::ESTADO_REQUERIDO,
                    'indicaciones' => $sourceTransport->indicaciones,
                    'observaciones' => $sourceTransport->observaciones,
                    'comentario_cambio' => null,
                    'incidencia_operativa' => null,
                    'recepcion_fecha' => null,
                    'recepcion_estado' => RQMinaActividadTransporte::RECEPCION_PENDIENTE,
                    'recepcion_observacion' => null,
                    'orden' => $sourceTransport->orden ?: ($transportIndex + 1),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            if (!empty($transportes)) {
                $newGroup->transportes()->insert($transportes);
            }
        }
    }

    private function groupsForPlanQuery(RQMina $rqMina, RQMinaPlan $plan)
    {
        $query = RQMinaActividadGrupo::query()->where('rq_mina_id', (string) $rqMina->id);

        return $query->where(function ($inner) use ($plan): void {
            $inner->where('rq_mina_plan_id', (string) $plan->id);

            if ($this->isDefaultPlan($plan)) {
                $inner->orWhereNull('rq_mina_plan_id');
            }
        });
    }

    private function hasLegacyGroups(RQMina $rqMina): bool
    {
        return RQMinaActividadGrupo::query()
            ->where('rq_mina_id', (string) $rqMina->id)
            ->whereNull('rq_mina_plan_id')
            ->exists();
    }

    private function normalizeEditableStatus(mixed $estado): string
    {
        $estado = strtoupper(trim((string) $estado));
        if ($estado === '') {
            return RQMinaPlan::ESTADO_BORRADOR;
        }

        if (!in_array($estado, [RQMinaPlan::ESTADO_BORRADOR, RQMinaPlan::ESTADO_VIGENTE], true)) {
            throw new InvalidArgumentException('El estado del plan solo puede ser BORRADOR o VIGENTE.');
        }

        return $estado;
    }

    private function normalizeSemanaReferencia(mixed $value, mixed $fechaInicio, mixed $fechaFin): string
    {
        $text = $this->nullableText($value);

        return $text ?: $this->buildSemanaReferencia($fechaInicio, $fechaFin);
    }

    private function buildSemanaReferencia(mixed $fechaInicio, mixed $fechaFin): string
    {
        $inicio = Carbon::parse($fechaInicio)->startOfDay();
        $fin = Carbon::parse($fechaFin)->startOfDay();
        $startWeek = $inicio->isoWeek();
        $endWeek = $fin->isoWeek();
        $startYear = $inicio->isoWeekYear();
        $endYear = $fin->isoWeekYear();

        if ($startWeek === $endWeek && $startYear === $endYear) {
            return "Semana {$startWeek} / {$startYear}";
        }

        if ($startYear === $endYear) {
            return "Semanas {$startWeek}-{$endWeek} / {$startYear}";
        }

        return "Semana {$startWeek} / {$startYear} - Semana {$endWeek} / {$endYear}";
    }

    private function requiredText(mixed $value, string $message): string
    {
        $text = $this->nullableText($value);
        if ($text === null) {
            throw new InvalidArgumentException($message);
        }

        return $text;
    }

    private function nullableText(mixed $value): ?string
    {
        $text = preg_replace('/\s+/u', ' ', trim((string) $value));

        return $text !== '' ? mb_substr($text, 0, 1000) : null;
    }

    private function calculateDiasUso(?string $fechaInicio, ?string $fechaFin): ?int
    {
        if (!$fechaInicio || !$fechaFin) {
            return null;
        }

        return Carbon::parse($fechaInicio)->startOfDay()->diffInDays(Carbon::parse($fechaFin)->startOfDay()) + 1;
    }

    private function parseDate(mixed $value, string $field): Carbon
    {
        if ($value === null || trim((string) $value) === '') {
            throw new InvalidArgumentException("La fecha {$field} es requerida.");
        }

        return Carbon::parse($value)->startOfDay();
    }

    private function toDateString(mixed $value): string
    {
        if ($value === null || trim((string) $value) === '') {
            throw new InvalidArgumentException('Las fechas del plan son requeridas.');
        }

        return Carbon::parse($value)->toDateString();
    }
}
