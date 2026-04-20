<?php

namespace App\Modules\Asistencia\Services;

use App\Models\AsistenciaDetalle;
use App\Models\AsistenciaEncabezado;
use App\Models\GrupoTrabajo;
use App\Models\GrupoTrabajoDetalle;
use App\Models\Usuario;
use App\Modules\Asistencia\Policies\AsistenciaPolicy;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AsistenciaService
{
    public function __construct(
        private readonly AsistenciaPolicy $policy,
        private readonly AsistenciaCierreService $cierreService,
    ) {
    }

    public function listGrupos(Usuario $usuario, array $filters): ?Collection
    {
        if (!$this->policy->manage($usuario)) {
            return null;
        }

        $query = GrupoTrabajo::query()->with(['rqMina.mina:id,nombre', 'supervisor']);

        if (!empty($filters['fecha'])) {
            $query->where('fecha', $filters['fecha']);
        }

        if (!empty($filters['turno'])) {
            $query->where('turno', strtoupper((string) $filters['turno']));
        }

        if (!empty($filters['mina_id'])) {
            $query->whereHas('rqMina', function ($q) use ($filters): void {
                $q->where('mina_id', $filters['mina_id']);
            });
        }

        if (!empty($filters['destino_tipo'])) {
            $query->where('destino_tipo', $filters['destino_tipo']);
        }

        if (!empty($filters['destino_id'])) {
            $query->where('destino_id', $filters['destino_id']);
        }

        if (!$this->isPrivileged($usuario)) {
            $scopeIds = $usuario->scopesMina()->pluck('mina_id');
            $query->whereHas('rqMina', function ($q) use ($scopeIds): void {
                $q->whereIn('mina_id', $scopeIds);
            });
        }

        return $query->orderByDesc('fecha')->get()->map(function (GrupoTrabajo $grupo): array {
            $asistencia = AsistenciaEncabezado::query()->where('grupo_trabajo_id', $grupo->id)->first();

            return [
                'grupo_id' => $grupo->id,
                'fecha' => optional($grupo->fecha)->toDateString(),
                'turno' => $grupo->turno,
                'estado_grupo' => $grupo->estado,
                'mina_id' => $grupo->rqMina?->mina_id,
                'mina_nombre' => $grupo->rqMina?->mina?->nombre,
                'destino_tipo' => $grupo->destino_tipo ?? $grupo->unidad,
                'destino_id' => $grupo->destino_id,
                'destino_nombre' => $grupo->mina,
                'supervisor' => $grupo->supervisor?->nombre_completo,
                'estado_asistencia' => $asistencia?->estado ?? 'PENDIENTE',
            ];
        });
    }

    public function getGrupo(Usuario $usuario, string $grupoId): ?GrupoTrabajo
    {
        $grupo = GrupoTrabajo::query()->with(['rqMina.mina:id,nombre', 'supervisor', 'detalle.personal', 'asistencia.detalle.trabajador'])->find($grupoId);

        if (!$grupo) {
            return null;
        }

        if (!$this->policy->manageGrupo($usuario, $grupo)) {
            return null;
        }

        return $grupo;
    }

    public function marcar(Usuario $usuario, GrupoTrabajo $grupo, array $payload): array
    {
        if (!$this->policy->manageGrupo($usuario, $grupo)) {
            return $this->forbidden();
        }

        $belongs = GrupoTrabajoDetalle::query()
            ->where('grupo_trabajo_id', $grupo->id)
            ->where('personal_id', $payload['personal_id'])
            ->exists();

        if (!$belongs) {
            return $this->businessError('ASISTENCIA_PERSON_NOT_IN_GROUP', 'Personal no pertenece al grupo');
        }

        $encabezado = $this->getOrCreateEncabezado($grupo);

        if ($encabezado->estado === 'CERRADO') {
            return $this->businessError('ASISTENCIA_ALREADY_CLOSED', 'Asistencia cerrada');
        }

        AsistenciaDetalle::query()->updateOrCreate(
            [
                'asistencia_id' => $encabezado->id,
                'trabajador_id' => $payload['personal_id'],
            ],
            [
                'id' => (string) Str::uuid(),
                'hora_marcado' => ($payload['hora_marcado'] ?? now()->format('H:i')).':00',
                'estado' => $payload['estado'],
                'observaciones' => $payload['observaciones'] ?? null,
            ]
        );

        return ['ok' => true, 'grupo' => $this->getGrupo($usuario, $grupo->id)];
    }

    public function marcarMasivo(Usuario $usuario, GrupoTrabajo $grupo, array $payload): array
    {
        if (!$this->policy->manageGrupo($usuario, $grupo)) {
            return $this->forbidden();
        }

        $encabezado = $this->getOrCreateEncabezado($grupo);

        if ($encabezado->estado === 'CERRADO') {
            return $this->businessError('ASISTENCIA_ALREADY_CLOSED', 'Asistencia cerrada');
        }

        $allowedIds = GrupoTrabajoDetalle::query()
            ->where('grupo_trabajo_id', $grupo->id)
            ->pluck('personal_id')
            ->all();

        $invalid = collect($payload['personal_ids'])
            ->filter(fn (string $id): bool => !in_array($id, $allowedIds, true))
            ->values();

        if ($invalid->isNotEmpty()) {
            return $this->businessError('ASISTENCIA_PERSON_NOT_IN_GROUP', 'Existe personal fuera del grupo');
        }

        DB::transaction(function () use ($encabezado, $payload): void {
            foreach ($payload['personal_ids'] as $personalId) {
                AsistenciaDetalle::query()->updateOrCreate(
                    [
                        'asistencia_id' => $encabezado->id,
                        'trabajador_id' => $personalId,
                    ],
                    [
                        'id' => (string) Str::uuid(),
                        'hora_marcado' => ($payload['hora_marcado'] ?? now()->format('H:i')).':00',
                        'estado' => $payload['estado'],
                        'observaciones' => $payload['observaciones'] ?? null,
                    ]
                );
            }
        });

        return ['ok' => true, 'grupo' => $this->getGrupo($usuario, $grupo->id)];
    }

    public function cerrar(Usuario $usuario, GrupoTrabajo $grupo, array $payload): array
    {
        if (!$this->policy->manageGrupo($usuario, $grupo)) {
            return $this->forbidden();
        }

        $encabezado = $this->getOrCreateEncabezado($grupo);
        $result = $this->cierreService->cerrar($grupo, $encabezado, $payload);

        if (($result['ok'] ?? false) === false) {
            return $result;
        }

        return ['ok' => true, 'grupo' => $this->getGrupo($usuario, $grupo->id)];
    }

    public function reabrir(Usuario $usuario, GrupoTrabajo $grupo): array
    {
        if (!$this->policy->manageGrupo($usuario, $grupo)) {
            return $this->forbidden();
        }

        $encabezado = AsistenciaEncabezado::query()->where('grupo_trabajo_id', $grupo->id)->first();

        if (!$encabezado) {
            return $this->businessError('ASISTENCIA_NOT_FOUND', 'Asistencia no existe para este grupo');
        }

        $result = $this->cierreService->reabrir($grupo, $encabezado);

        if (($result['ok'] ?? false) === false) {
            return $result;
        }

        return ['ok' => true, 'grupo' => $this->getGrupo($usuario, $grupo->id)];
    }

    private function getOrCreateEncabezado(GrupoTrabajo $grupo): AsistenciaEncabezado
    {
        $encabezado = AsistenciaEncabezado::query()->where('grupo_trabajo_id', $grupo->id)->first();

        if ($encabezado) {
            return $encabezado;
        }

        $minaId = (string) optional($grupo->rqMina)->mina_id;

        return AsistenciaEncabezado::query()->create([
            'id' => (string) Str::uuid(),
            'grupo_trabajo_id' => $grupo->id,
            'fecha' => $grupo->fecha->toDateString(),
            'hora_ingreso' => $grupo->horario_salida,
            'mina_id' => $minaId,
            'destino_tipo' => $grupo->destino_tipo ?? $grupo->unidad,
            'destino_id' => $grupo->destino_id,
            'supervisor_id' => $grupo->supervisor_id,
            'actividad_realizada' => null,
            'reporte_suceso' => null,
            'estado' => 'REGISTRADO',
        ]);
    }

    private function businessError(string $code, string $message): array
    {
        return ['ok' => false, 'code' => $code, 'message' => $message];
    }

    private function forbidden(): array
    {
        return ['ok' => false, 'code' => 'ASISTENCIA_FORBIDDEN', 'message' => 'No autorizado', 'forbidden' => true];
    }

    private function isPrivileged(Usuario $usuario): bool
    {
        $rol = strtoupper((string) optional($usuario->rol)->nombre);

        return in_array($rol, ['ADMIN', 'GERENTE', 'SUPERADMIN'], true);
    }

    public function listGruposForUser(Usuario $usuario, array $filters): array
    {
        $result = $this->listGrupos($usuario, $filters);
        
        return $result?->toArray() ?? [];
    }

    public function getGrupoForUser(Usuario $usuario, string $grupoId): ?array
    {
        $grupo = $this->getGrupo($usuario, $grupoId);
        
        return $grupo?->toArray();
    }

    public function marcarAsistencia(Usuario $usuario, string $grupoId, array $payload): array
    {
        $grupo = $this->getGrupo($usuario, $grupoId);
        
        if (!$grupo) {
            return ['success' => false, 'message' => 'Grupo no encontrado'];
        }
        
        $result = $this->marcar($usuario, $grupo, $payload);
        
        return $result['ok'] ?? false
            ? ['success' => true, 'message' => 'Asistencia marcada']
            : ['success' => false, 'message' => $result['message'] ?? 'Error'];
    }

    public function marcarMasivo(Usuario $usuario, string $grupoId, array $payload): array
    {
        $grupo = $this->getGrupo($usuario, $grupoId);
        
        if (!$grupo) {
            return ['success' => false, 'message' => 'Grupo no encontrado'];
        }
        
        $result = $this->marcarMasivo($usuario, $grupo, $payload);
        
        return $result['ok'] ?? false
            ? ['success' => true, 'message' => 'Asistencia marcada']
            : ['success' => false, 'message' => $result['message'] ?? 'Error'];
    }

    public function cerrarAsistencia(Usuario $usuario, string $grupoId): array
    {
        $grupo = $this->getGrupo($usuario, $grupoId);
        
        if (!$grupo) {
            return ['success' => false, 'message' => 'Grupo no encontrado'];
        }
        
        $result = $this->cerrar($usuario, $grupo, []);
        
        return $result['ok'] ?? false
            ? ['success' => true, 'message' => 'Asistencia cerrada']
            : ['success' => false, 'message' => $result['message'] ?? 'Error'];
    }

    public function reabrirAsistencia(Usuario $usuario, string $grupoId): array
    {
        $grupo = $this->getGrupo($usuario, $grupoId);
        
        if (!$grupo) {
            return ['success' => false, 'message' => 'Grupo no encontrado'];
        }
        
        $result = $this->reabrir($usuario, $grupo);
        
        return $result['ok'] ?? false
            ? ['success' => true, 'message' => 'Asistencia reopenida']
            : ['success' => false, 'message' => $result['message'] ?? 'Error'];
    }
}
