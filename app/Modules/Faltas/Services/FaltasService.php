<?php

namespace App\Modules\Faltas\Services;

use App\Models\Falta;
use App\Models\Usuario;
use App\Modules\Faltas\Policies\FaltaPolicy;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class FaltasService
{
    public function __construct(
        private readonly FaltaPolicy $policy,
        private readonly CorregirFaltaService $corregirService,
    ) {
    }

    public function list(Usuario $usuario, array $filters): ?Collection
    {
        if (!$this->policy->manage($usuario)) {
            return null;
        }

        $query = Falta::query()
            ->leftJoin('asistencia_encabezado as ae', 'ae.id', '=', 'faltas.asistencia_encabezado_id')
            ->select('faltas.*', DB::raw('ae.grupo_trabajo_id as grupo_trabajo_id'));

        if (!empty($filters['destino_tipo'])) {
            $query->where('faltas.destino_tipo', strtoupper((string) $filters['destino_tipo']));
        }

        if (!empty($filters['destino_id'])) {
            $query->where('faltas.destino_id', $filters['destino_id']);
        }

        if (!empty($filters['fecha_desde'])) {
            $query->whereDate('faltas.fecha', '>=', $filters['fecha_desde']);
        }

        if (!empty($filters['fecha_hasta'])) {
            $query->whereDate('faltas.fecha', '<=', $filters['fecha_hasta']);
        }

        if (!empty($filters['personal_id'])) {
            $query->where('faltas.trabajador_id', $filters['personal_id']);
        }

        if (!empty($filters['grupo_trabajo_id'])) {
            $query->where('ae.grupo_trabajo_id', $filters['grupo_trabajo_id']);
        }

        if (!empty($filters['estado'])) {
            $query->where('faltas.estado', strtoupper((string) $filters['estado']));
        }

        if (!empty($filters['motivo'])) {
            $query->where('faltas.motivo', strtoupper((string) $filters['motivo']));
        }

        if (!$this->isPrivileged($usuario)) {
            $scopeMinaIds = $usuario->scopesMina()->pluck('mina_id');
            $query->where(function ($q) use ($scopeMinaIds): void {
                $q->where('faltas.destino_tipo', '!=', 'MINA')
                    ->orWhereIn('faltas.destino_id', $scopeMinaIds);
            });
        }

        return $query->orderByDesc('faltas.fecha')->get();
    }

    public function findForUser(Usuario $usuario, string $id): ?Falta
    {
        $falta = Falta::query()
            ->leftJoin('asistencia_encabezado as ae', 'ae.id', '=', 'faltas.asistencia_encabezado_id')
            ->select('faltas.*', DB::raw('ae.grupo_trabajo_id as grupo_trabajo_id'))
            ->where('faltas.id', $id)
            ->first();

        if (!$falta) {
            return null;
        }

        return $this->policy->view($usuario, $falta) ? $falta : null;
    }

    public function update(Usuario $usuario, Falta $falta, array $payload): array
    {
        if (!$this->policy->update($usuario, $falta)) {
            return $this->forbidden();
        }

        $falta->fill($payload);
        $falta->save();

        return ['ok' => true, 'falta' => $this->findForUser($usuario, $falta->id)];
    }

    public function corregirAsistencia(Usuario $usuario, Falta $falta, array $payload): array
    {
        if (!$this->policy->update($usuario, $falta)) {
            return $this->forbidden();
        }

        return $this->corregirService->corregir($falta, $usuario, $payload);
    }

    public function anular(Usuario $usuario, Falta $falta, array $payload): array
    {
        if (!$this->policy->update($usuario, $falta)) {
            return $this->forbidden();
        }

        if (strtoupper((string) $falta->estado) === 'ANULADA') {
            return [
                'ok' => false,
                'code' => 'FALTA_ALREADY_ANULADA',
                'message' => 'La falta ya se encuentra anulada',
            ];
        }

        $falta->fill([
            'estado' => 'ANULADA',
            'motivo_anulacion' => $payload['motivo_anulacion'],
            'anulado_por_usuario_id' => $usuario->id,
            'anulado_at' => now(),
        ]);
        $falta->save();

        return ['ok' => true, 'falta' => $this->findForUser($usuario, $falta->id)];
    }

    private function forbidden(): array
    {
        return [
            'ok' => false,
            'code' => 'FALTAS_FORBIDDEN',
            'message' => 'No autorizado',
            'forbidden' => true,
        ];
    }

    private function isPrivileged(Usuario $usuario): bool
    {
        $rol = strtoupper((string) optional($usuario->rol)->nombre);

        return in_array($rol, ['ADMIN', 'GERENTE', 'SUPERADMIN'], true);
    }

    public function listForUser(Usuario $usuario, array $filters): array
    {
        $result = $this->list($usuario, $filters);
        
        return $result?->toArray() ?? [];
    }

    public function corregir(Usuario $usuario, string $id, array $payload): array
    {
        $falta = $this->findForUser($usuario, $id);
        
        if (!$falta) {
            return ['success' => false, 'message' => 'Falta no encontrada'];
        }
        
        $result = $this->corregirAsistencia($usuario, $falta, $payload);
        
        return $result['ok'] ?? false
            ? ['success' => true, 'message' => 'Falta corregida']
            : ['success' => false, 'message' => $result['message'] ?? 'Error'];
    }

    public function anular(Usuario $usuario, string $id): array
    {
        $falta = $this->findForUser($usuario, $id);
        
        if (!$falta) {
            return ['success' => false, 'message' => 'Falta no encontrada'];
        }
        
        $result = $this->anular($usuario, $falta, []);
        
        return $result['ok'] ?? false
            ? ['success' => true, 'message' => 'Falta anulada']
            : ['success' => false, 'message' => $result['message'] ?? 'Error'];
    }
}
