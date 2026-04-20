<?php

namespace App\Modules\ManPower\Services;

use App\Models\GrupoTrabajo;
use App\Models\GrupoTrabajoDetalle;
use App\Models\Mina;
use App\Models\Oficina;
use App\Models\RQMina;
use App\Models\Taller;
use App\Models\Usuario;
use App\Modules\ManPower\Policies\ManPowerPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GrupoTrabajoService
{
    public function __construct(
        private readonly ManPowerPolicy $policy,
        private readonly ManPowerParadasService $paradasService,
    ) {
    }

    public function createGrupo(Usuario $usuario, array $payload): array
    {
        if (!$this->policy->manageGrupos($usuario)) {
            return $this->forbidden('MANPOWER_FORBIDDEN');
        }

        $rqMina = RQMina::query()->with('mina:id,nombre')->find($payload['rq_mina_id']);

        if (!$rqMina) {
            return $this->businessError('MANPOWER_RQ_MINA_NOT_FOUND', 'RQ Mina no encontrado');
        }

        if (!$this->policy->canAccessMina($usuario, $rqMina->mina_id)) {
            return $this->forbidden('MINA_SCOPE_FORBIDDEN');
        }

        if ($this->hasAsistenciaIniciada($rqMina->mina_id, $payload['fecha'])) {
            return $this->businessError('MANPOWER_ASSISTENCIA_LOCKED', 'No se puede crear grupo con asistencia iniciada o cerrada');
        }

        $aprobados = collect($this->paradasService->aprobadosPorFecha(
            rqMinaId: $rqMina->id,
            fecha: $payload['fecha'],
            rqProsergeId: $payload['rq_proserge_id'] ?? null,
        ));

        if ($aprobados->isEmpty()) {
            return $this->businessError('MANPOWER_NO_APPROVED_PERSONAL', 'La parada no tiene personal aprobado para la fecha');
        }

        $supervisorValido = $aprobados->contains(function (array $item) use ($payload): bool {
            return $item['personal_id'] === $payload['supervisor_id'] && $item['es_supervisor'] === true;
        });

        if (!$supervisorValido) {
            return $this->businessError('MANPOWER_INVALID_SUPERVISOR', 'Supervisor no valido para la parada y fecha');
        }

        $destination = $this->resolveDestino($payload['destino_tipo'], $payload['destino_id']);

        if (!$destination) {
            return $this->businessError('MANPOWER_INVALID_DESTINATION', 'Destino invalido');
        }

        $grupo = DB::transaction(function () use ($usuario, $payload, $destination): GrupoTrabajo {
            $grupo = GrupoTrabajo::query()->create([
                'id' => (string) Str::uuid(),
                'fecha' => $payload['fecha'],
                'supervisor_id' => $payload['supervisor_id'],
                'mina' => $destination['nombre'],
                'rq_mina_id' => $payload['rq_mina_id'],
                'rq_proserge_id' => $payload['rq_proserge_id'] ?? null,
                'servicio' => $payload['servicio'],
                'area' => $payload['area'],
                'paradero' => $payload['paradero'] ?? null,
                'paradero_link' => $payload['paradero_link'] ?? null,
                'unidad' => $destination['tipo'],
                'destino_tipo' => $destination['tipo'],
                'destino_id' => $destination['id'],
                'horario_salida' => $payload['horario_salida'],
                'turno' => $payload['turno'],
                'estado' => 'BORRADOR',
                'observaciones' => $payload['observaciones'] ?? null,
                'created_by_id' => $usuario->id,
            ]);

            foreach (($payload['personal_ids'] ?? []) as $personalId) {
                $this->addPersonalToGrupo($grupo, $personalId);
            }

            return $grupo;
        });

        return [
            'ok' => true,
            'grupo' => $grupo->load(['rqMina.mina:id,nombre', 'rqProserge:id,estado', 'supervisor', 'detalle.personal']),
        ];
    }

    public function updateGrupo(Usuario $usuario, GrupoTrabajo $grupo, array $payload): array
    {
        if (!$this->policy->manageGrupo($usuario, $grupo)) {
            return $this->forbidden('MANPOWER_FORBIDDEN');
        }

        $minaId = (string) optional($grupo->rqMina)->mina_id;

        if ($minaId === '') {
            return $this->businessError('MANPOWER_RQ_MINA_NOT_FOUND', 'Grupo sin RQ Mina valido');
        }

        if ($this->hasAsistenciaIniciada($minaId, $grupo->fecha->toDateString())) {
            return $this->businessError('MANPOWER_ASSISTENCIA_LOCKED', 'No se puede modificar grupo con asistencia iniciada o cerrada');
        }

        $aprobados = collect($this->paradasService->aprobadosPorFecha(
            rqMinaId: $grupo->rq_mina_id,
            fecha: $payload['fecha'],
            rqProsergeId: $grupo->rq_proserge_id,
        ));

        $supervisorValido = $aprobados->contains(function (array $item) use ($payload): bool {
            return $item['personal_id'] === $payload['supervisor_id'] && $item['es_supervisor'] === true;
        });

        if (!$supervisorValido) {
            return $this->businessError('MANPOWER_INVALID_SUPERVISOR', 'Supervisor no valido para la parada y fecha');
        }

        $destination = $this->resolveDestino($payload['destino_tipo'], $payload['destino_id']);

        if (!$destination) {
            return $this->businessError('MANPOWER_INVALID_DESTINATION', 'Destino invalido');
        }

        $grupo->fill([
            'fecha' => $payload['fecha'],
            'turno' => $payload['turno'],
            'supervisor_id' => $payload['supervisor_id'],
            'servicio' => $payload['servicio'],
            'area' => $payload['area'],
            'paradero' => $payload['paradero'] ?? null,
            'paradero_link' => $payload['paradero_link'] ?? null,
            'horario_salida' => $payload['horario_salida'],
            'mina' => $destination['nombre'],
            'unidad' => $destination['tipo'],
            'destino_tipo' => $destination['tipo'],
            'destino_id' => $destination['id'],
            'observaciones' => $payload['observaciones'] ?? null,
            'estado' => $payload['estado'] ?? $grupo->estado,
        ]);
        $grupo->save();

        return [
            'ok' => true,
            'grupo' => $grupo->fresh(['rqMina.mina:id,nombre', 'rqProserge:id,estado', 'supervisor', 'detalle.personal']),
        ];
    }

    public function addPersonal(Usuario $usuario, GrupoTrabajo $grupo, string $personalId): array
    {
        if (!$this->policy->manageGrupo($usuario, $grupo)) {
            return $this->forbidden('MANPOWER_FORBIDDEN');
        }

        $minaId = (string) optional($grupo->rqMina)->mina_id;

        if ($this->hasAsistenciaIniciada($minaId, $grupo->fecha->toDateString())) {
            return $this->businessError('MANPOWER_ASSISTENCIA_LOCKED', 'No se puede modificar grupo con asistencia iniciada o cerrada');
        }

        $validation = $this->validatePersonalForGrupo($grupo, $personalId);

        if (($validation['ok'] ?? false) === false) {
            return $validation;
        }

        $this->addPersonalToGrupo($grupo, $personalId);

        return [
            'ok' => true,
            'grupo' => $grupo->fresh(['rqMina.mina:id,nombre', 'rqProserge:id,estado', 'supervisor', 'detalle.personal']),
        ];
    }

    public function removePersonal(Usuario $usuario, GrupoTrabajo $grupo, string $personalId): array
    {
        if (!$this->policy->manageGrupo($usuario, $grupo)) {
            return $this->forbidden('MANPOWER_FORBIDDEN');
        }

        $minaId = (string) optional($grupo->rqMina)->mina_id;

        if ($this->hasAsistenciaIniciada($minaId, $grupo->fecha->toDateString())) {
            return $this->businessError('MANPOWER_ASSISTENCIA_LOCKED', 'No se puede modificar grupo con asistencia iniciada o cerrada');
        }

        $deleted = GrupoTrabajoDetalle::query()
            ->where('grupo_trabajo_id', $grupo->id)
            ->where('personal_id', $personalId)
            ->delete();

        if ($deleted === 0) {
            return $this->businessError('MANPOWER_PERSON_NOT_IN_GROUP', 'Personal no pertenece al grupo');
        }

        return [
            'ok' => true,
            'grupo' => $grupo->fresh(['rqMina.mina:id,nombre', 'rqProserge:id,estado', 'supervisor', 'detalle.personal']),
        ];
    }

    public function showGrupo(Usuario $usuario, GrupoTrabajo $grupo): ?GrupoTrabajo
    {
        if (!$this->policy->manageGrupo($usuario, $grupo)) {
            return null;
        }

        return $grupo->load(['rqMina.mina:id,nombre', 'rqProserge:id,estado', 'supervisor', 'detalle.personal']);
    }

    private function validatePersonalForGrupo(GrupoTrabajo $grupo, string $personalId): array
    {
        $aprobados = collect($this->paradasService->aprobadosPorFecha(
            rqMinaId: $grupo->rq_mina_id,
            fecha: $grupo->fecha->toDateString(),
            rqProsergeId: $grupo->rq_proserge_id,
        ));

        if (!$aprobados->contains(fn (array $item): bool => $item['personal_id'] === $personalId)) {
            return $this->businessError('MANPOWER_PERSON_NOT_APPROVED', 'Personal fuera del universo aprobado para la parada');
        }

        $alreadyInSameGroup = GrupoTrabajoDetalle::query()
            ->where('grupo_trabajo_id', $grupo->id)
            ->where('personal_id', $personalId)
            ->exists();

        if ($alreadyInSameGroup) {
            return $this->businessError('MANPOWER_PERSON_ALREADY_IN_GROUP', 'Personal ya registrado en el grupo');
        }

        $conflict = DB::table('grupo_trabajo_detalle as gtd')
            ->join('grupo_trabajo as gt', 'gt.id', '=', 'gtd.grupo_trabajo_id')
            ->where('gtd.personal_id', $personalId)
            ->where('gt.fecha', $grupo->fecha->toDateString())
            ->where('gt.turno', $grupo->turno)
            ->where('gt.id', '!=', $grupo->id)
            ->whereNotIn('gt.estado', ['CANCELADO'])
            ->exists();

        if ($conflict) {
            return $this->businessError('MANPOWER_PERSON_GROUP_CONFLICT', 'Personal ya pertenece a otro grupo incompatible');
        }

        return ['ok' => true];
    }

    private function addPersonalToGrupo(GrupoTrabajo $grupo, string $personalId): void
    {
        GrupoTrabajoDetalle::query()->create([
            'id' => (string) Str::uuid(),
            'grupo_trabajo_id' => $grupo->id,
            'personal_id' => $personalId,
            'estado_asistencia' => 'AUSENTE',
            'observaciones' => null,
        ]);
    }

    private function resolveDestino(string $tipo, string $destinoId): ?array
    {
        if ($tipo === 'MINA') {
            $item = Mina::query()->find($destinoId);

            return $item ? ['tipo' => 'MINA', 'id' => $item->id, 'nombre' => $item->nombre] : null;
        }

        if ($tipo === 'TALLER') {
            $item = Taller::query()->find($destinoId);

            return $item ? ['tipo' => 'TALLER', 'id' => $item->id, 'nombre' => $item->nombre] : null;
        }

        if ($tipo === 'OFICINA') {
            $item = Oficina::query()->find($destinoId);

            return $item ? ['tipo' => 'OFICINA', 'id' => $item->id, 'nombre' => $item->nombre] : null;
        }

        return null;
    }

    private function hasAsistenciaIniciada(string $minaId, string $fecha): bool
    {
        return DB::table('asistencia_encabezado')
            ->where('mina_id', $minaId)
            ->where('fecha', $fecha)
            ->whereIn('estado', ['REGISTRADO', 'CERRADO', 'ENVIADO', 'FINALIZADO'])
            ->exists();
    }

    private function businessError(string $code, string $message): array
    {
        return [
            'ok' => false,
            'code' => $code,
            'message' => $message,
        ];
    }

    private function forbidden(string $code): array
    {
        return [
            'ok' => false,
            'code' => $code,
            'message' => 'No autorizado',
            'forbidden' => true,
        ];
    }

    public function listForUser(Usuario $usuario, array $filters): array
    {
        if (!$this->policy->manageGrupos($usuario)) {
            return [];
        }

        $query = GrupoTrabajo::query()
            ->with(['supervisor:id,nombre', 'rqMina:id,estado'])
            ->orderByDesc('created_at');

        if (!empty($filters['fecha'])) {
            $query->where('fecha', $filters['fecha']);
        }

        return $query->get()->toArray();
    }

    public function findForUser(Usuario $usuario, string $id): ?array
    {
        $grupo = $this->showGrupo($usuario, GrupoTrabajo::query()->findOrFail($id));
        
        return $grupo?->toArray();
    }

    public function createForUser(Usuario $usuario, array $payload): array
    {
        return $this->createGrupo($usuario, $payload);
    }

    public function updateForUser(Usuario $usuario, string $id, array $payload): array
    {
        $grupo = GrupoTrabajo::query()->find($id);
        
        if (!$grupo) {
            return ['success' => false, 'message' => 'Grupo no encontrado'];
        }
        
        return $this->updateGrupo($usuario, $grupo, $payload);
    }

    public function quitarPersonal(Usuario $usuario, string $id, string $personalId): array
    {
        $grupo = GrupoTrabajo::query()->find($id);
        
        if (!$grupo) {
            return ['success' => false, 'message' => 'Grupo no encontrado'];
        }
        
        $result = $this->removePersonal($usuario, $grupo, $personalId);
        
        return $result['ok'] ?? false
            ? ['success' => true, 'message' => 'Personal removido']
            : ['success' => false, 'message' => $result['message'] ?? 'Error'];
    }
}
