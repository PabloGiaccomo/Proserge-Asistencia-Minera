<?php

namespace App\Modules\Evaluaciones\Services;

use App\Models\EvaluacionSupervisor;
use App\Models\Mina;
use App\Models\Usuario;
use App\Modules\Evaluaciones\Policies\EvaluacionesPolicy;
use App\Modules\Evaluaciones\Support\SupervisorEvaluationTemplate;
use App\Support\Rbac\PermissionMatrix;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EvaluacionSupervisorService
{
    public function __construct(private readonly EvaluacionesPolicy $policy) {}

    public function list(Usuario $usuario, array $filters): ?Collection
    {
        if (! $this->policy->canViewType($usuario, 'supervisores')) {
            return null;
        }

        $query = EvaluacionSupervisor::query();

        if (! $this->policy->canViewOthers($usuario)) {
            $this->policy->ownedBy($query, $usuario);
        }

        if (! empty($filters['destino_tipo'])) {
            $query->where('destino_tipo', strtoupper((string) $filters['destino_tipo']));
        }

        if (! empty($filters['destino_id'])) {
            $query->where('destino_id', $filters['destino_id']);
        }

        if (! empty($filters['fecha_desde'])) {
            $query->whereDate('fecha', '>=', $filters['fecha_desde']);
        }

        if (! empty($filters['fecha_hasta'])) {
            $query->whereDate('fecha', '<=', $filters['fecha_hasta']);
        }

        if (! empty($filters['evaluado_id'])) {
            $query->where('evaluado_id', $filters['evaluado_id']);
        }

        if (! empty($filters['grupo_trabajo_id'])) {
            $query->where('grupo_trabajo_id', $filters['grupo_trabajo_id']);
        }

        return $query->orderByDesc('fecha')->get();
    }

    public function find(Usuario $usuario, string $id): ?EvaluacionSupervisor
    {
        $item = EvaluacionSupervisor::query()->find($id);

        if (! $item) {
            return null;
        }

        if (! $this->policy->canViewType($usuario, 'supervisores')
            || (! $this->policy->canViewOthers($usuario) && ! $this->policy->owns($usuario, $item))
        ) {
            return null;
        }

        return $item;
    }

    public function create(Usuario $usuario, array $payload): array
    {
        if (! $this->policy->canEvaluateType($usuario, 'supervisores')) {
            return $this->forbidden();
        }

        if (strtoupper((string) $payload['destino_tipo']) === 'MINA'
            && ! Mina::query()->whereKey($payload['destino_id'])->exists()) {
            return $this->businessError('EVAL_SUP_CONTEXT_INVALID', 'Selecciona una unidad minera válida.');
        }

        if (! $this->policy->canEvaluateSupervisorTarget(
            $usuario,
            (string) $payload['evaluado_id'],
            (string) $payload['destino_tipo'],
            (string) $payload['destino_id'],
            $payload['grupo_trabajo_id'] ?? null,
        )) {
            return $this->businessError('EVAL_SUP_CONTEXT_INVALID', 'Selecciona un trabajador registrado como supervisor.');
        }

        if ($this->hasDuplicate($payload)) {
            return $this->businessError('EVAL_SUP_DUPLICATED', 'Ya existe una evaluacion de supervisor para el mismo contexto');
        }

        if (! SupervisorEvaluationTemplate::hasCompleteResponses($payload['respuestas'] ?? null)) {
            return $this->businessError('EVAL_CRITERIA_REQUIRED', 'Completa todos los criterios de la evaluacion con notas entre 1 y 5.');
        }

        $responses = SupervisorEvaluationTemplate::normalizeResponses($payload['respuestas']);
        $score = SupervisorEvaluationTemplate::calculateFinalScore($responses);

        $item = EvaluacionSupervisor::query()->create([
            'id' => (string) Str::uuid(),
            'evaluador_id' => $usuario->personal_id,
            'evaluado_id' => $payload['evaluado_id'],
            'fecha' => $payload['fecha'],
            'mina_id' => $payload['mina_id'] ?? null,
            'grupo_trabajo_id' => $payload['grupo_trabajo_id'] ?? null,
            'asistencia_encabezado_id' => $payload['asistencia_encabezado_id'] ?? null,
            'destino_tipo' => strtoupper((string) $payload['destino_tipo']),
            'destino_id' => $payload['destino_id'],
            'resultado_final' => $score,
            'comentarios_finales' => $payload['comentarios_finales'] ?? null,
            'aspectos_positivos' => $payload['aspectos_positivos'] ?? null,
            'capacitaciones_recomendadas' => $payload['capacitaciones_recomendadas'] ?? null,
            'firma_supervisor' => $payload['firma'] ?? null,
            'respuestas' => $responses,
            'estado' => 'REGISTRADA',
            'created_by_usuario_id' => $usuario->id,
            'updated_by_usuario_id' => $usuario->id,
        ]);

        return ['ok' => true, 'item' => $item];
    }

    public function update(Usuario $usuario, EvaluacionSupervisor $item, array $payload): array
    {
        if (! PermissionMatrix::userCanDirectAny($usuario, 'evaluaciones', ['editar', 'actualizar'])) {
            return $this->forbidden();
        }

        if ($item->isHistorical()) {
            if ($item->estado === EvaluacionSupervisor::PENDING_REGULARIZATION) {
                return $this->regularizeHistorical($usuario, $item, $payload);
            }

            return $this->businessError('EVAL_HIST_READ_ONLY', 'Esta evaluacion conserva la nota historica. Su correccion requiere revision administrativa.');
        }

        if (! SupervisorEvaluationTemplate::hasCompleteResponses($payload['respuestas'] ?? null)) {
            return $this->businessError('EVAL_CRITERIA_REQUIRED', 'Completa todos los criterios de la evaluacion con notas entre 1 y 5.');
        }

        $responses = SupervisorEvaluationTemplate::normalizeResponses($payload['respuestas']);
        $score = SupervisorEvaluationTemplate::calculateFinalScore($responses);

        $item->fill([
            'resultado_final' => $score,
            'comentarios_finales' => $payload['comentarios_finales'] ?? null,
            'aspectos_positivos' => $payload['aspectos_positivos'] ?? null,
            'capacitaciones_recomendadas' => $payload['capacitaciones_recomendadas'] ?? null,
            'firma_supervisor' => $payload['firma'] ?? null,
            'respuestas' => $responses,
            'estado' => $payload['estado'] ?? $item->estado,
            'updated_by_usuario_id' => $usuario->id,
        ]);
        $item->save();

        return ['ok' => true, 'item' => $item->fresh()];
    }

    public function calculate(array $responses): array
    {
        $normalized = SupervisorEvaluationTemplate::normalizeResponses($responses);

        return [
            'respuestas' => $normalized,
            'resultado_final' => SupervisorEvaluationTemplate::calculateFinalScore($normalized),
        ];
    }

    public function canRegularize(Usuario $usuario): bool
    {
        return PermissionMatrix::userCanDirect($usuario, 'evaluaciones', 'administrar')
            && PermissionMatrix::userCanDirectAny($usuario, 'evaluaciones', ['editar', 'actualizar']);
    }

    private function regularizeHistorical(Usuario $usuario, EvaluacionSupervisor $item, array $payload): array
    {
        if (! $this->canRegularize($usuario)) {
            return $this->forbidden();
        }
        if (! SupervisorEvaluationTemplate::hasCompleteResponses($payload['respuestas'] ?? null)) {
            return $this->businessError('EVAL_CRITERIA_REQUIRED', 'Completa todos los criterios antes de regularizar.');
        }

        return DB::transaction(function () use ($usuario, $item, $payload): array {
            $locked = EvaluacionSupervisor::query()->lockForUpdate()->findOrFail($item->id);
            if (! $locked->isHistorical() || $locked->estado !== EvaluacionSupervisor::PENDING_REGULARIZATION) {
                return $this->businessError('EVAL_HIST_READ_ONLY', 'Esta evaluacion ya no esta pendiente de regularizacion.');
            }
            $responses = SupervisorEvaluationTemplate::normalizeResponses($payload['respuestas']);
            $source = $locked->source_payload ?? [];
            $source['_regularizacion'][] = ['accion' => 'REGULARIZADA', 'usuario_id' => $usuario->id,
                'actor' => $usuario->personal?->nombre_completo ?: $usuario->email,
                'fecha' => now()->toIso8601String(), 'criterios_pendientes' => $locked->missingCriteria()];
            $locked->fill([
                'respuestas' => $responses,
                'resultado_final' => SupervisorEvaluationTemplate::calculateFinalScore($responses),
                'calculo_version' => 'REGULARIZADA', 'estado' => 'REGISTRADA',
                'source_payload' => $source, 'updated_by_usuario_id' => $usuario->id,
            ]);
            foreach (['comentarios_finales', 'aspectos_positivos', 'capacitaciones_recomendadas'] as $field) {
                if (array_key_exists($field, $payload)) {
                    $locked->{$field} = $payload[$field];
                }
            }
            $locked->save();

            return ['ok' => true, 'item' => $locked->fresh()];
        });
    }

    public function recordHistoricalFollowUp(Usuario $usuario, EvaluacionSupervisor $item, string $reason): array
    {
        if (! $this->canRegularize($usuario)) {
            return $this->forbidden();
        }
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 3000) {
            return $this->businessError('EVAL_HIST_REASON_REQUIRED', 'Indica por que sigue pendiente la regularizacion.');
        }

        return DB::transaction(function () use ($usuario, $item, $reason): array {
            $locked = EvaluacionSupervisor::query()->lockForUpdate()->findOrFail($item->id);
            if (! $locked->isHistorical() || $locked->estado !== EvaluacionSupervisor::PENDING_REGULARIZATION) {
                return $this->businessError('EVAL_HIST_READ_ONLY', 'Esta evaluacion ya no esta pendiente de regularizacion.');
            }
            $source = $locked->source_payload ?? [];
            $source['_regularizacion'][] = ['accion' => 'PENDIENTE', 'motivo' => $reason,
                'actor' => $usuario->personal?->nombre_completo ?: $usuario->email,
                'usuario_id' => $usuario->id, 'fecha' => now()->toIso8601String(), 'criterios_pendientes' => $locked->missingCriteria()];
            $locked->fill(['source_payload' => $source, 'updated_by_usuario_id' => $usuario->id])->save();

            return ['ok' => true, 'item' => $locked->fresh()];
        });
    }

    private function hasDuplicate(array $payload): bool
    {
        if (empty($payload['grupo_trabajo_id'])) {
            return false;
        }

        return EvaluacionSupervisor::query()
            ->where('evaluado_id', $payload['evaluado_id'])
            ->where('grupo_trabajo_id', $payload['grupo_trabajo_id'])
            ->whereDate('fecha', $payload['fecha'])
            ->exists();
    }

    private function businessError(string $code, string $message): array
    {
        return ['ok' => false, 'code' => $code, 'message' => $message];
    }

    private function forbidden(): array
    {
        return ['ok' => false, 'code' => 'EVAL_FORBIDDEN', 'message' => 'No autorizado', 'forbidden' => true];
    }
}
