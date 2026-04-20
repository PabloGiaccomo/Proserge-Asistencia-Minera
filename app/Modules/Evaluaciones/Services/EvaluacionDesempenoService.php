<?php

namespace App\Modules\Evaluaciones\Services;

use App\Models\AsistenciaDetalle;
use App\Models\AsistenciaEncabezado;
use App\Models\EvaluacionDesempeno;
use App\Models\EvaluacionResidente;
use App\Models\EvaluacionSupervisor;
use App\Models\GrupoTrabajo;
use App\Models\Usuario;
use App\Modules\Evaluaciones\Policies\EvaluacionesPolicy;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class EvaluacionDesempenoService
{
    public function __construct(
        private readonly EvaluacionesPolicy $policy,
        private readonly PromedioDesempenoService $promedios,
    ) {
    }

    public function list(Usuario $usuario, array $filters): ?Collection
    {
        if (!$this->policy->manage($usuario)) {
            return null;
        }

        $query = EvaluacionDesempeno::query();

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

        if (!empty($filters['trabajador_id'])) {
            $query->where('trabajador_id', $filters['trabajador_id']);
        }

        if (!empty($filters['grupo_trabajo_id'])) {
            $query->where('grupo_trabajo_id', $filters['grupo_trabajo_id']);
        }

        $items = $query->orderByDesc('fecha')->get();

        return $items->filter(fn (EvaluacionDesempeno $e): bool => $this->policy->canAccessDestino($usuario, $e->destino_tipo, $e->destino_id))->values();
    }

    public function find(Usuario $usuario, string $id): ?EvaluacionDesempeno
    {
        $item = EvaluacionDesempeno::query()->find($id);

        if (!$item) {
            return null;
        }

        return $this->policy->canAccessDestino($usuario, $item->destino_tipo, $item->destino_id) ? $item : null;
    }

    public function create(Usuario $usuario, array $payload): array
    {
        if (!$this->policy->manage($usuario)) {
            return $this->forbidden();
        }

        $grupo = GrupoTrabajo::query()->with('rqMina')->find($payload['grupo_trabajo_id']);

        if (!$grupo) {
            return $this->businessError('EVAL_GRUPO_NOT_FOUND', 'Grupo de trabajo no encontrado');
        }

        if (!$this->policy->canAccessDestino($usuario, $grupo->destino_tipo, $grupo->destino_id)) {
            return $this->forbidden();
        }

        $belongs = $grupo->detalle()->where('personal_id', $payload['trabajador_id'])->exists();
        if (!$belongs) {
            return $this->businessError('EVAL_TRABAJADOR_NOT_IN_GROUP', 'Trabajador no pertenece al grupo');
        }

        $existing = EvaluacionDesempeno::query()
            ->where('grupo_trabajo_id', $grupo->id)
            ->where('trabajador_id', $payload['trabajador_id'])
            ->exists();
        if ($existing) {
            return $this->businessError('EVAL_DUPLICATED', 'Ya existe evaluacion para este trabajador en el grupo');
        }

        $asistenciaEnc = AsistenciaEncabezado::query()->where('grupo_trabajo_id', $grupo->id)->first();
        $asistenciaDet = $asistenciaEnc
            ? AsistenciaDetalle::query()->where('asistencia_id', $asistenciaEnc->id)->where('trabajador_id', $payload['trabajador_id'])->first()
            : null;

        if (!$asistenciaEnc || !$asistenciaDet) {
            return $this->businessError('EVAL_ASISTENCIA_REQUIRED', 'No se encontro asistencia del trabajador en el grupo');
        }

        $total = $this->calculateTotal($payload);

        $item = EvaluacionDesempeno::query()->create([
            'id' => (string) Str::uuid(),
            'fecha' => $grupo->fecha->toDateString(),
            'hora' => now()->format('H:i:s'),
            'mina_id' => $grupo->rqMina?->mina_id,
            'grupo_trabajo_id' => $grupo->id,
            'semana_parada' => $payload['semana_parada'] ?? null,
            'desempeno_trabajo' => $payload['desempeno_trabajo'],
            'orden_limpieza' => $payload['orden_limpieza'],
            'compromiso' => $payload['compromiso'],
            'respuesta_emocional' => $payload['respuesta_emocional'],
            'seguridad_trabajo' => $payload['seguridad_trabajo'],
            'total' => $total,
            'observaciones' => $payload['observaciones'] ?? null,
            'supervisor_id' => $grupo->supervisor_id,
            'trabajador_id' => $payload['trabajador_id'],
            'tuvo_incidencia' => (bool) ($payload['tuvo_incidencia'] ?? false),
            'descripcion_incidencia' => $payload['descripcion_incidencia'] ?? null,
            'asistencia_detalle_id' => $asistenciaDet->id,
            'asistencia_encabezado_id' => $asistenciaEnc->id,
            'destino_tipo' => $grupo->destino_tipo,
            'destino_id' => $grupo->destino_id,
            'evaluado_por_usuario_id' => $usuario->id,
        ]);

        $this->promedios->refreshForTrabajador($item->trabajador_id);

        return ['ok' => true, 'item' => $item];
    }

    public function update(Usuario $usuario, EvaluacionDesempeno $item, array $payload): array
    {
        if (!$this->policy->canAccessDestino($usuario, $item->destino_tipo, $item->destino_id)) {
            return $this->forbidden();
        }

        $item->fill([
            'desempeno_trabajo' => $payload['desempeno_trabajo'],
            'orden_limpieza' => $payload['orden_limpieza'],
            'compromiso' => $payload['compromiso'],
            'respuesta_emocional' => $payload['respuesta_emocional'],
            'seguridad_trabajo' => $payload['seguridad_trabajo'],
            'total' => $this->calculateTotal($payload),
            'observaciones' => $payload['observaciones'] ?? null,
            'tuvo_incidencia' => (bool) ($payload['tuvo_incidencia'] ?? false),
            'descripcion_incidencia' => $payload['descripcion_incidencia'] ?? null,
        ]);
        $item->save();

        $this->promedios->refreshForTrabajador($item->trabajador_id);

        return ['ok' => true, 'item' => $item->fresh()];
    }

    public function createSupervisor(Usuario $usuario, array $payload): array
    {
        if (!$this->policy->canAccessDestino($usuario, $payload['destino_tipo'], $payload['destino_id'])) {
            return $this->forbidden();
        }

        $item = EvaluacionSupervisor::query()->create([
            'id' => (string) Str::uuid(),
            ...$payload,
        ]);

        return ['ok' => true, 'item' => $item];
    }

    public function createResidente(Usuario $usuario, array $payload): array
    {
        if (!$this->policy->canAccessDestino($usuario, $payload['destino_tipo'], $payload['destino_id'])) {
            return $this->forbidden();
        }

        $total = round((
            $payload['indicadores_kpi'] +
            $payload['costos_servicio'] +
            $payload['eventos_seguridad'] +
            $payload['reportes_calidad'] +
            $payload['liderazgo_gestion'] +
            $payload['innovacion']
        ) / 6, 2);

        $item = EvaluacionResidente::query()->create([
            'id' => (string) Str::uuid(),
            ...$payload,
            'total' => $total,
        ]);

        return ['ok' => true, 'item' => $item];
    }

    private function calculateTotal(array $payload): int
    {
        return (int) (
            $payload['desempeno_trabajo'] +
            $payload['orden_limpieza'] +
            $payload['compromiso'] +
            $payload['respuesta_emocional'] +
            $payload['seguridad_trabajo']
        );
    }

    private function businessError(string $code, string $message): array
    {
        return ['ok' => false, 'code' => $code, 'message' => $message];
    }

    private function forbidden(): array
    {
        return ['ok' => false, 'code' => 'EVAL_FORBIDDEN', 'message' => 'No autorizado', 'forbidden' => true];
    }

    public function listForUser(Usuario $usuario, array $filters): array
    {
        $result = $this->list($usuario, $filters);
        
        return $result?->toArray() ?? [];
    }

    public function findForUser(Usuario $usuario, string $id): ?array
    {
        $item = $this->find($usuario, $id);
        
        return $item?->toArray();
    }

    public function createForUser(Usuario $usuario, array $payload): array
    {
        return $this->create($usuario, $payload);
    }

    public function updateForUser(Usuario $usuario, string $id, array $payload): array
    {
        $item = $this->find($id);
        
        if (!$item) {
            return ['success' => false, 'message' => 'Evaluación no encontrada'];
        }
        
        return $this->update($usuario, $item, $payload);
    }
}
