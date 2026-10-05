<?php

namespace App\Modules\Evaluaciones\Services;

use App\Models\AsistenciaDetalle;
use App\Models\AsistenciaEncabezado;
use App\Models\EvaluacionDesempeno;
use App\Models\EvaluacionResidente;
use App\Models\EvaluacionSupervisor;
use App\Models\GrupoTrabajo;
use App\Models\Mina;
use App\Models\Personal;
use App\Models\Usuario;
use App\Modules\Evaluaciones\Policies\EvaluacionesPolicy;
use App\Modules\Evaluaciones\Support\ResidentEvaluationTemplate;
use App\Support\Rbac\PermissionMatrix;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EvaluacionDesempenoService
{
    public function __construct(
        private readonly EvaluacionesPolicy $policy,
        private readonly PromedioDesempenoService $promedios,
    ) {}

    public function list(Usuario $usuario, array $filters): ?Collection
    {
        if (! $this->policy->canViewType($usuario, 'desempeno')) {
            return null;
        }

        $query = EvaluacionDesempeno::query();

        if (! $this->policy->canViewOthers($usuario)) {
            $query->where('evaluado_por_usuario_id', $usuario->id);
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

        if (! empty($filters['trabajador_id'])) {
            $query->where('trabajador_id', $filters['trabajador_id']);
        }

        if (! empty($filters['grupo_trabajo_id'])) {
            $query->where('grupo_trabajo_id', $filters['grupo_trabajo_id']);
        }

        $items = $query->orderByDesc('fecha')->get();

        return $items->filter(fn (EvaluacionDesempeno $e): bool => $this->canReadEvaluation($usuario, $e))->values();
    }

    public function find(Usuario $usuario, string $id): ?EvaluacionDesempeno
    {
        $item = EvaluacionDesempeno::query()->find($id);

        if (! $item) {
            return null;
        }

        if (! $this->policy->canViewType($usuario, 'desempeno')
            || (! $this->policy->canViewOthers($usuario) && (string) $item->evaluado_por_usuario_id !== (string) $usuario->id)
        ) {
            return null;
        }

        return $this->canReadEvaluation($usuario, $item) ? $item : null;
    }

    public function create(Usuario $usuario, array $payload): array
    {
        if (! $this->policy->canEvaluateType($usuario, 'desempeno')) {
            return $this->forbidden();
        }

        $grupo = GrupoTrabajo::query()->with('rqMina')->find($payload['grupo_trabajo_id']);

        if (! $grupo) {
            return $this->businessError('EVAL_GRUPO_NOT_FOUND', 'Grupo de trabajo no encontrado');
        }

        if (! $this->policy->canAccessDestino($usuario, $grupo->destino_tipo, $grupo->destino_id)) {
            return $this->forbidden();
        }

        $belongs = $grupo->detalle()->where('personal_id', $payload['trabajador_id'])->exists();
        if (! $belongs) {
            return $this->businessError('EVAL_TRABAJADOR_NOT_IN_GROUP', 'Trabajador no pertenece al grupo');
        }

        $asistenciaEnc = AsistenciaEncabezado::query()
            ->where('grupo_trabajo_id', $grupo->id)
            ->first();
        $asistenciaDet = $asistenciaEnc
            ? AsistenciaDetalle::query()->where('asistencia_id', $asistenciaEnc->id)->where('trabajador_id', $payload['trabajador_id'])->first()
            : null;

        if (! $asistenciaEnc || ! $asistenciaDet) {
            return $this->businessError('EVAL_ASISTENCIA_REQUIRED', 'No se encontro asistencia del trabajador en el grupo');
        }

        return $this->createDaily($usuario, [
            ...$payload,
            'asistencia_detalle_id' => $asistenciaDet->id,
        ]);
    }

    public function createDaily(Usuario $usuario, array $payload): array
    {
        $detail = AsistenciaDetalle::query()
            ->with(['asistencia.grupoTrabajo', 'trabajador'])
            ->find($payload['asistencia_detalle_id']);

        $availability = $this->dailyAvailability($usuario, $detail);
        if (! $availability['ok']) {
            return $availability;
        }

        $attendance = $detail->asistencia;

        try {
            $item = DB::transaction(function () use ($usuario, $payload, $detail, $attendance): ?EvaluacionDesempeno {
                Personal::query()->whereKey($detail->trabajador_id)->lockForUpdate()->firstOrFail();
                $exists = EvaluacionDesempeno::query()
                    ->where('asistencia_detalle_id', $detail->id)
                    ->lockForUpdate()
                    ->exists();

                if ($exists) {
                    return null;
                }

                $date = Carbon::parse($attendance->fecha);
                $hasIncident = (bool) ($payload['tuvo_incidencia'] ?? false);

                $created = EvaluacionDesempeno::query()->create([
                    'id' => (string) Str::uuid(),
                    'fecha' => $date->toDateString(),
                    'hora' => now()->format('H:i:s'),
                    'mina_id' => $attendance->mina_id,
                    'grupo_trabajo_id' => $attendance->grupo_trabajo_id,
                    'semana_parada' => $date->isoWeek(),
                    'desempeno_trabajo' => $payload['desempeno_trabajo'],
                    'orden_limpieza' => $payload['orden_limpieza'],
                    'compromiso' => $payload['compromiso'],
                    'respuesta_emocional' => $payload['respuesta_emocional'],
                    'seguridad_trabajo' => $payload['seguridad_trabajo'],
                    'total' => $this->calculateTotal($payload),
                    'observaciones' => $payload['observaciones'] ?? null,
                    'supervisor_id' => $usuario->personal_id,
                    'trabajador_id' => $detail->trabajador_id,
                    'tuvo_incidencia' => $hasIncident,
                    'descripcion_incidencia' => $hasIncident ? ($payload['descripcion_incidencia'] ?? null) : null,
                    'asistencia_detalle_id' => $detail->id,
                    'asistencia_encabezado_id' => $attendance->id,
                    'destino_tipo' => $attendance->destino_tipo,
                    'destino_id' => $attendance->destino_id,
                    'evaluado_por_usuario_id' => $usuario->id,
                    'origen' => 'ASISTENCIA',
                ]);

                $this->promedios->refreshForTrabajador($detail->trabajador_id);

                return $created;
            });
        } catch (QueryException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) === 1062) {
                return $this->businessError('EVAL_DUPLICATED', 'Esta asistencia ya cuenta con una evaluacion para el trabajador.');
            }

            throw $exception;
        }

        if (! $item) {
            return $this->businessError('EVAL_DUPLICATED', 'Esta asistencia ya cuenta con una evaluacion para el trabajador.');
        }

        return ['ok' => true, 'item' => $item];
    }

    public function createDirect(Usuario $usuario, array $payload): array
    {
        if (! $this->policy->canEvaluateType($usuario, 'desempeno')) {
            return $this->forbidden();
        }

        if ((string) $usuario->personal_id !== '' && (string) $usuario->personal_id === (string) $payload['trabajador_id']) {
            return $this->businessError('EVAL_SELF_NOT_ALLOWED', 'No puedes evaluarte a ti mismo.');
        }

        if (! Mina::query()->activeOperational()->whereKey($payload['mina_id'])->exists()) {
            return $this->businessError('EVAL_MINE_INVALID', 'Selecciona una mina activa.');
        }

        try {
            $item = DB::transaction(function () use ($usuario, $payload): ?EvaluacionDesempeno {
                $worker = Personal::query()->whereKey($payload['trabajador_id'])->lockForUpdate()->first();
                if (! $worker) {
                    return null;
                }

                if (EvaluacionDesempeno::query()->where('submission_key', $payload['submission_key'])->exists()) {
                    return null;
                }

                $date = Carbon::parse($payload['fecha']);
                $hasIncident = (bool) ($payload['tuvo_incidencia'] ?? false);
                $created = EvaluacionDesempeno::query()->create([
                    'id' => (string) Str::uuid(),
                    'fecha' => $date->toDateString(),
                    'hora' => now()->format('H:i:s'),
                    'mina_id' => $payload['mina_id'],
                    'semana_parada' => null,
                    'desempeno_trabajo' => $payload['desempeno_trabajo'],
                    'orden_limpieza' => $payload['orden_limpieza'],
                    'compromiso' => $payload['compromiso'],
                    'respuesta_emocional' => $payload['respuesta_emocional'],
                    'seguridad_trabajo' => $payload['seguridad_trabajo'],
                    'total' => $this->calculateTotal($payload),
                    'observaciones' => $payload['observaciones'] ?? null,
                    'supervisor_id' => $usuario->personal_id,
                    'trabajador_id' => $worker->id,
                    'tuvo_incidencia' => $hasIncident,
                    'descripcion_incidencia' => $hasIncident ? ($payload['descripcion_incidencia'] ?? null) : null,
                    'destino_tipo' => 'MINA',
                    'destino_id' => $payload['mina_id'],
                    'evaluado_por_usuario_id' => $usuario->id,
                    'origen' => 'DIRECTA',
                    'submission_key' => $payload['submission_key'],
                ]);

                $this->promedios->refreshForTrabajador($worker->id);

                return $created;
            });
        } catch (QueryException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) === 1062
                && str_contains((string) ($exception->errorInfo[2] ?? ''), 'uq_eval_des_submission_key')) {
                return $this->businessError('EVAL_DUPLICATED', 'Este envio ya fue registrado.');
            }

            throw $exception;
        }

        if (! $item) {
            if (! Personal::query()->whereKey($payload['trabajador_id'])->exists()) {
                return $this->businessError('EVAL_WORKER_NOT_FOUND', 'No se encontro al trabajador seleccionado.');
            }

            return $this->businessError('EVAL_DUPLICATED', 'Este envio ya fue registrado.');
        }

        return ['ok' => true, 'item' => $item];
    }

    public function dailyAvailability(Usuario $usuario, ?AsistenciaDetalle $detail, ?bool $alreadyEvaluated = null, ?bool $markedByUser = null): array
    {
        if (! $this->policy->canEvaluateType($usuario, 'desempeno')) {
            return $this->forbidden();
        }

        if (! $usuario->personal_id) {
            return $this->businessError('EVAL_EVALUATOR_NOT_LINKED', 'Tu cuenta debe estar vinculada a Personal para registrar evaluaciones.');
        }

        if (! $detail || ! $detail->asistencia || ! $detail->trabajador) {
            return $this->businessError('EVAL_ATTENDANCE_NOT_FOUND', 'No se encontro la asistencia seleccionada.');
        }

        $attendance = $detail->asistencia;
        $isAttendanceOwner = (string) $attendance->supervisor_id === (string) $usuario->personal_id
            || ($markedByUser ?? AsistenciaDetalle::query()
                ->where('asistencia_id', $attendance->id)
                ->where('marcado_por_id', $usuario->id)
                ->exists());

        if (! $isAttendanceOwner && ! $this->policy->canEvaluateOthers($usuario)) {
            return $this->businessError('EVAL_ATTENDANCE_OWNER_REQUIRED', 'Solo el encargado que registro esta asistencia puede evaluarla.');
        }

        $alreadyEvaluated ??= EvaluacionDesempeno::query()->where('asistencia_detalle_id', $detail->id)->exists();
        if ($alreadyEvaluated) {
            return $this->businessError('EVAL_DUPLICATED', 'Esta asistencia ya cuenta con una evaluacion para el trabajador.');
        }

        if ((string) $attendance->estado !== 'CERRADO') {
            return $this->businessError('EVAL_ATTENDANCE_OPEN', 'Cierra la asistencia antes de realizar la evaluacion diaria.');
        }

        if (! in_array((string) $detail->estado, AsistenciaDetalle::ESTADOS_REAL, true)) {
            return $this->businessError('EVAL_WORKER_NOT_PRESENT', 'Solo se puede evaluar a personal que trabajo ese dia.');
        }

        if ((string) $detail->trabajador_id === (string) $usuario->personal_id) {
            return $this->businessError('EVAL_SELF_NOT_ALLOWED', 'No puedes evaluarte a ti mismo.');
        }

        return ['ok' => true, 'code' => 'EVAL_AVAILABLE'];
    }

    public function update(Usuario $usuario, EvaluacionDesempeno $item, array $payload): array
    {
        if (! PermissionMatrix::userCanDirectAny($usuario, 'evaluaciones', ['editar', 'actualizar'])
            || ! $this->policy->canAccessDestino($usuario, $item->destino_tipo, $item->destino_id)
        ) {
            return $this->forbidden();
        }

        if ($item->import_lote_id && $item->origen === 'HISTORICA') {
            return $this->businessError('EVAL_HIST_READ_ONLY', 'Esta evaluacion conserva la nota historica. Su correccion requiere revision administrativa.');
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
        if (! $this->policy->canEvaluateType($usuario, 'supervisores')
            || ! $this->policy->canAccessDestino($usuario, $payload['destino_tipo'], $payload['destino_id'])
        ) {
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
        $availability = $this->residentAvailability(
            $usuario,
            (string) ($payload['residente_id'] ?? ''),
            substr((string) ($payload['periodo_mes'] ?? $payload['fecha'] ?? ''), 0, 7),
        );
        if (! $availability['ok']) {
            return $availability;
        }

        $calculation = ResidentEvaluationTemplate::calculate($payload);
        $period = $availability['period'];

        try {
            $item = EvaluacionResidente::query()->create([
                'id' => (string) Str::uuid(),
                'fecha' => $period,
                'periodo_mes' => $period,
                'destino_tipo' => null,
                'destino_id' => null,
                ...$calculation,
                'residente_id' => $payload['residente_id'],
                'evaluador_id' => $usuario->personal_id,
                'comentarios' => $payload['comentarios'] ?? null,
                'estado' => 'REGISTRADA',
                'created_by_usuario_id' => $usuario->id,
                'updated_by_usuario_id' => $usuario->id,
            ]);
        } catch (QueryException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) === 1062
                && str_contains((string) ($exception->errorInfo[2] ?? ''), 'uq_eval_res_residente_periodo')) {
                return $this->businessError('EVAL_RESIDENT_DUPLICATED', 'Este residente ya cuenta con una evaluación para el periodo seleccionado.');
            }

            throw $exception;
        }

        return ['ok' => true, 'item' => $item];
    }

    public function residentAvailability(Usuario $usuario, string $residentId, string $month): array
    {
        if (! $this->policy->canEvaluateType($usuario, 'residentes')) {
            return $this->forbidden();
        }

        if (! $usuario->personal_id && ! $this->policy->isPrivileged($usuario)) {
            return $this->businessError('EVAL_EVALUATOR_NOT_LINKED', 'La cuenta debe estar vinculada a Personal para identificar al evaluador.');
        }

        if (! $this->policy->canEvaluateResidentTarget($usuario, $residentId)) {
            return $this->businessError('EVAL_RESIDENT_TARGET_INVALID', 'Selecciona un trabajador registrado como residente.');
        }

        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            return $this->businessError('EVAL_PERIOD_INVALID', 'Selecciona un mes válido.');
        }

        $period = $month.'-01';
        if (EvaluacionResidente::query()->where('residente_id', $residentId)->whereDate('periodo_mes', $period)->exists()) {
            return $this->businessError('EVAL_RESIDENT_DUPLICATED', 'Este residente ya cuenta con una evaluación para el periodo seleccionado.');
        }

        return ['ok' => true, 'period' => $period];
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

    private function canReadEvaluation(Usuario $usuario, EvaluacionDesempeno $item): bool
    {
        if ((string) $item->origen === 'HISTORICA' && $item->mina_id === null) {
            return $this->policy->canViewOthers($usuario);
        }

        return ((string) $item->origen === 'DIRECTA' && (string) $item->evaluado_por_usuario_id === (string) $usuario->id)
            || $this->policy->canAccessDestino($usuario, $item->destino_tipo, $item->destino_id);
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
        $item = EvaluacionDesempeno::query()->find($id);

        if (! $item) {
            return ['success' => false, 'message' => 'Evaluación no encontrada'];
        }

        return $this->update($usuario, $item, $payload);
    }
}
