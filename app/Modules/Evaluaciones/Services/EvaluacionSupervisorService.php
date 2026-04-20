<?php

namespace App\Modules\Evaluaciones\Services;

use App\Models\EvaluacionSupervisor;
use App\Models\GrupoTrabajo;
use App\Models\Usuario;
use App\Modules\Evaluaciones\Policies\EvaluacionesPolicy;
use App\Modules\Evaluaciones\Support\SupervisorEvaluationTemplate;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class EvaluacionSupervisorService
{
    public function __construct(private readonly EvaluacionesPolicy $policy)
    {
    }

    public function list(Usuario $usuario, array $filters): ?Collection
    {
        if (!$this->policy->manage($usuario)) {
            return null;
        }

        $query = EvaluacionSupervisor::query();

        if (!empty($filters['destino_tipo'])) {
            $query->where('destino_tipo', strtoupper((string) $filters['destino_tipo']));
        }

        if (!empty($filters['destino_id'])) {
            $query->where('destino_id', $filters['destino_id']);
        }

        if (!empty($filters['fecha_desde'])) {
            $query->whereDate('fecha', '>=', $filters['fecha_desde']);
        }

        if (!empty($filters['fecha_hasta'])) {
            $query->whereDate('fecha', '<=', $filters['fecha_hasta']);
        }

        if (!empty($filters['evaluado_id'])) {
            $query->where('evaluado_id', $filters['evaluado_id']);
        }

        if (!empty($filters['grupo_trabajo_id'])) {
            $query->where('grupo_trabajo_id', $filters['grupo_trabajo_id']);
        }

        return $query->orderByDesc('fecha')->get()->filter(
            fn (EvaluacionSupervisor $item): bool => $this->policy->canAccessDestino($usuario, $item->destino_tipo, $item->destino_id)
        )->values();
    }

    public function find(Usuario $usuario, string $id): ?EvaluacionSupervisor
    {
        $item = EvaluacionSupervisor::query()->find($id);

        if (!$item) {
            return null;
        }

        return $this->policy->canAccessDestino($usuario, $item->destino_tipo, $item->destino_id) ? $item : null;
    }

    public function create(Usuario $usuario, array $payload): array
    {
        if (!$this->policy->canAccessDestino($usuario, $payload['destino_tipo'], $payload['destino_id'])) {
            return $this->forbidden();
        }

        if (!$this->isEvaluadoInContext($payload)) {
            return $this->businessError('EVAL_SUP_CONTEXT_INVALID', 'Evaluado no pertenece al contexto operativo indicado');
        }

        if ($this->hasDuplicate($payload)) {
            return $this->businessError('EVAL_SUP_DUPLICATED', 'Ya existe una evaluacion de supervisor para el mismo contexto');
        }

        $responses = SupervisorEvaluationTemplate::normalizeResponses($payload['respuestas']);
        $score = SupervisorEvaluationTemplate::calculateFinalScore($responses);

        $item = EvaluacionSupervisor::query()->create([
            'id' => (string) Str::uuid(),
            'evaluador_id' => $payload['evaluador_id'],
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
        if (!$this->policy->canAccessDestino($usuario, $item->destino_tipo, $item->destino_id)) {
            return $this->forbidden();
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

    private function isEvaluadoInContext(array $payload): bool
    {
        if (empty($payload['grupo_trabajo_id'])) {
            return true;
        }

        return GrupoTrabajo::query()
            ->where('id', $payload['grupo_trabajo_id'])
            ->whereHas('detalle', function ($q) use ($payload): void {
                $q->where('personal_id', $payload['evaluado_id']);
            })
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
