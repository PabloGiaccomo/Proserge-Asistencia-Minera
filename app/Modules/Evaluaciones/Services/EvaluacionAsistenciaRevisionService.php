<?php

namespace App\Modules\Evaluaciones\Services;

use App\Models\AsistenciaDetalle;
use App\Models\AsistenciaEncabezado;
use App\Models\EvaluacionDesempeno;
use App\Models\GrupoTrabajo;
use App\Models\Usuario;
use App\Modules\Evaluaciones\Policies\EvaluacionesPolicy;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EvaluacionAsistenciaRevisionService
{
    public function __construct(private readonly EvaluacionesPolicy $policy, private readonly PromedioDesempenoService $averages) {}

    public function markPendingForDetail(AsistenciaDetalle $detail, ?Usuario $actor): void
    {
        if ($detail->estado !== AsistenciaDetalle::ESTADO_AUSENTE) {
            return;
        }
        $evaluations = EvaluacionDesempeno::query()->incluidasEnPromedio()
            ->where('asistencia_detalle_id', $detail->id)->orderBy('id')->lockForUpdate()->get();
        foreach ($evaluations as $evaluation) {
            $this->transition($evaluation, $actor, 'PENDIENTE_REVISION', 'La asistencia vinculada cambió a ausente.');
        }
        foreach ($evaluations->pluck('trabajador_id')->unique() as $worker) {
            $this->averages->refreshForTrabajador($worker);
        }
    }

    public function pending(Usuario $actor): Paginator
    {
        abort_unless($this->policy->canViewType($actor, 'desempeno'), 403);

        $query = EvaluacionDesempeno::query()->with('trabajador:id,nombre_completo')
            ->where('estado_revision', 'PENDIENTE_REVISION')
            ->where(fn ($query) => $query->where('evaluado_por_usuario_id', $actor->id)->orWhere(
                fn ($legacy) => $legacy->whereNull('evaluado_por_usuario_id')->where('supervisor_id', $actor->personal_id ?? '')
            ));
        if (! $this->policy->isPrivileged($actor)) {
            $query->where(fn ($scope) => $scope->whereNull('destino_tipo')->orWhere('destino_tipo', '<>', 'MINA')
                ->orWhereIn('destino_id', $actor->scopesMina()->pluck('mina_id')));
        }

        return $query->orderByDesc('fecha')->simplePaginate(25);
    }

    public function resolve(string $id, Usuario $actor, int $revision, string $decision, string $reason): EvaluacionDesempeno
    {
        abort_unless($this->policy->canEvaluateType($actor, 'desempeno'), 403);
        $reason = trim($reason);
        if (! in_array($decision, ['RATIFICAR', 'EXCLUIR'], true) || $reason === '' || mb_strlen($reason) > 1000 || $revision < 1) {
            throw ValidationException::withMessages(['decision' => 'Selecciona una decisión e indica un motivo de hasta 1000 caracteres.']);
        }

        return DB::transaction(function () use ($id, $actor, $revision, $decision, $reason): EvaluacionDesempeno {
            $context = EvaluacionDesempeno::query()->findOrFail($id);
            $detail = $context->asistenciaDetalle;
            $header = $detail ? AsistenciaEncabezado::query()->find($detail->asistencia_id) : null;
            if ($header?->grupo_trabajo_id) {
                GrupoTrabajo::query()->lockForUpdate()->findOrFail($header->grupo_trabajo_id);
            }
            if ($header) {
                $header = AsistenciaEncabezado::query()->lockForUpdate()->findOrFail($header->id);
            }
            if ($detail) {
                $detail = AsistenciaDetalle::query()->lockForUpdate()->findOrFail($detail->id);
            }
            $evaluation = EvaluacionDesempeno::query()->lockForUpdate()->findOrFail($id);
            $actor = Usuario::query()->whereKey($actor->id)->sharedLock()->first();
            abort_unless($actor && $actor->estado === 'ACTIVO', 403);
            $actor->setRelation('rol', $actor->rol()->sharedLock()->first());
            $actor->setRelation('rolesAdicionales', $actor->rolesAdicionales()->orderBy('roles.id')->sharedLock()->get());
            abort_unless($this->policy->canEvaluateType($actor, 'desempeno'), 403);
            $owner = $evaluation->evaluado_por_usuario_id
                ? $evaluation->evaluado_por_usuario_id === $actor->id
                : ($actor->personal_id && $evaluation->supervisor_id === $actor->personal_id);
            $canAccessDestination = $this->policy->isPrivileged($actor)
                || strtoupper((string) $evaluation->destino_tipo) !== 'MINA'
                || $actor->scopesMina()->where('mina_id', $evaluation->destino_id)->sharedLock()->first() !== null;
            abort_unless($owner && $canAccessDestination, 403);
            abort_unless($evaluation->asistencia_detalle_id === $context->asistencia_detalle_id, 409);
            abort_unless(! $detail || $detail->asistencia_id === $header?->id, 409, 'La asistencia vinculada cambió. Vuelve a consultar la evaluación.');
            if ($decision === 'RATIFICAR' && $detail) {
                abort_unless($detail->trabajador_id === $evaluation->trabajador_id, 409, 'La asistencia ya no corresponde al trabajador evaluado.');
            }
            $next = $decision === 'RATIFICAR' ? 'VALIDA' : 'EXCLUIDA';
            if ($evaluation->estado_revision === $next && (int) $evaluation->revision_asistencia === $revision + 1) {
                return $evaluation;
            }
            abort_unless($evaluation->estado_revision === 'PENDIENTE_REVISION' && (int) $evaluation->revision_asistencia === $revision, 409, 'La revisión cambió. Vuelve a abrir la evaluación.');
            if ($decision === 'RATIFICAR' && (! $detail || ! in_array($detail->estado, AsistenciaDetalle::ESTADOS_REAL, true)
                || $header?->estado !== 'CERRADO')) {
                throw ValidationException::withMessages(['decision' => 'Para ratificar, la asistencia debe estar cerrada con una marca elegible.']);
            }
            $this->transition($evaluation, $actor, $next, $reason);
            $this->averages->refreshForTrabajador($evaluation->trabajador_id);

            return $evaluation;
        }, 3);
    }

    private function transition(EvaluacionDesempeno $evaluation, ?Usuario $actor, string $next, string $reason): void
    {
        $previous = $evaluation->estado_revision;
        $evaluation->forceFill(['estado_revision' => $next, 'revision_asistencia' => (int) $evaluation->revision_asistencia + 1])->save();
        DB::table('evaluacion_asistencia_revision_eventos')->insert([
            'id' => (string) Str::uuid(), 'evaluacion_id' => $evaluation->id, 'revision' => $evaluation->revision_asistencia,
            'actor_usuario_id' => $actor?->id, 'estado_anterior' => $previous, 'estado_nuevo' => $next,
            'motivo' => Crypt::encryptString($reason), 'created_at' => now(),
        ]);
    }
}
