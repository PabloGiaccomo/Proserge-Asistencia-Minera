<?php

namespace App\Modules\MiAsistencia\Controllers;

use App\Http\Controllers\WebPageController;
use App\Models\GrupoTrabajoDetalle;
use App\Modules\Asistencia\Resources\AsistenciaGrupoResource;
use App\Modules\Asistencia\Policies\AsistenciaPolicy;
use App\Modules\Asistencia\Services\AsistenciaService;
use App\Support\Rbac\PermissionMatrix;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class MiAsistenciaPageController extends WebPageController
{
    public function __construct(
        private readonly AsistenciaService $service,
        private readonly AsistenciaPolicy $policy,
    ) {
    }

    public function index(Request $request): View
    {
        $user = $this->requireAuthenticatedUser();
        $filters = $request->validate([
            'fecha' => ['nullable', 'date'],
            'turno' => ['nullable', 'string', 'in:DIA,NOCHE'],
        ]);
        $filters['fecha'] = $filters['fecha'] ?? now()->toDateString();

        $grupos = $this->service->listMiAsistencia($user, $filters) ?? collect();
        $canViewAll = PermissionMatrix::userCanDirect($user, 'mi_asistencia', 'ver_todas_asistencias');

        return view('mi-asistencia.index', [
            'user' => $user,
            'fecha' => $filters['fecha'],
            'turno' => $filters['turno'] ?? '',
            'grupos' => $grupos,
            'canViewAll' => $canViewAll,
            'hasLinkedPersonal' => filled($user->personal_id),
        ]);
    }

    public function show(Request $request, string $id): View
    {
        $user = $this->requireAuthenticatedUser();
        $grupo = $this->service->getGrupo($user, $id);

        abort_if(!$grupo, 404, 'Grupo no encontrado o sin acceso');

        $data = AsistenciaGrupoResource::make($grupo)->resolve($request);
        $data['integrantes'] = collect($data['integrantes'] ?? [])
            ->filter(fn (array $item): bool => in_array(
                (string) ($item['estado_distribucion'] ?? GrupoTrabajoDetalle::ESTADO_DISTRIBUCION_ASIGNADO),
                GrupoTrabajoDetalle::ESTADOS_DISTRIBUCION_ACTIVOS,
                true,
            ))
            ->values()
            ->all();
        $attendanceReady = filled($grupo->supervisor_id)
            || filled($grupo->asistencia?->id)
            || filled($user->personal_id);

        return view('mi-asistencia.show', [
            'user' => $user,
            'grupo' => $data,
            'canViewAll' => PermissionMatrix::userCanDirect($user, 'mi_asistencia', 'ver_todas_asistencias'),
            'attendanceReady' => $attendanceReady,
            'canRegister' => $attendanceReady && $this->policy->canRegisterGrupo($user, $grupo),
            'canClose' => $attendanceReady && $this->policy->canCloseGrupo($user, $grupo),
        ]);
    }

    public function marcar(Request $request, string $id): JsonResponse|RedirectResponse
    {
        $user = $this->requireAuthenticatedUser();
        $payload = $request->validate([
            'grupo_trabajo_detalle_id' => ['required', 'string', 'size:36', 'exists:grupo_trabajo_detalle,id'],
            'estado' => ['required', 'string', 'in:PRESENTE,AUSENTE,TARDANZA,JUSTIFICADO'],
            'hora_marcado' => ['nullable', 'date_format:H:i'],
            'observaciones' => ['nullable', 'string', 'max:1000'],
        ]);
        $grupo = $this->service->getGrupo($user, $id);
        abort_if(!$grupo, 404, 'Grupo no encontrado o sin acceso');

        $result = $this->service->marcar($user, $grupo, $payload);

        return $this->attendanceResponse($request, $result, 'Asistencia actualizada.');
    }

    public function marcarTodos(Request $request, string $id): JsonResponse|RedirectResponse
    {
        $user = $this->requireAuthenticatedUser();
        $grupo = $this->service->getGrupo($user, $id);
        abort_if(!$grupo, 404, 'Grupo no encontrado o sin acceso');

        $detalleIds = $grupo->detalle
            ->filter(fn (GrupoTrabajoDetalle $detalle): bool => $detalle->isDistribucionActiva())
            ->pluck('id')
            ->values()
            ->all();
        $result = $this->service->marcarMasivo($user, $grupo, [
            'grupo_trabajo_detalle_ids' => $detalleIds,
            'estado' => 'PRESENTE',
            'hora_marcado' => now()->format('H:i'),
        ]);

        return $this->attendanceResponse($request, $result, 'Todos fueron marcados como presentes.');
    }

    public function cerrar(Request $request, string $id): JsonResponse|RedirectResponse
    {
        $user = $this->requireAuthenticatedUser();
        $grupo = $this->service->getGrupo($user, $id);
        abort_if(!$grupo, 404, 'Grupo no encontrado o sin acceso');

        $result = $this->service->cerrar($user, $grupo, [
            'actividad_realizada' => $request->string('actividad_realizada')->trim()->toString() ?: null,
            'reporte_suceso' => $request->string('reporte_suceso')->trim()->toString() ?: null,
        ]);

        return $this->attendanceResponse($request, $result, 'Asistencia cerrada correctamente.');
    }

    private function attendanceResponse(Request $request, array $result, string $message): JsonResponse|RedirectResponse
    {
        if (($result['ok'] ?? false) === false) {
            $status = ($result['forbidden'] ?? false) ? 403 : (int) ($result['status'] ?? 422);

            return $request->expectsJson()
                ? response()->json(['ok' => false, 'message' => $result['message'] ?? 'No se pudo actualizar la asistencia.'], $status)
                : back()->withErrors(['asistencia' => $result['message'] ?? 'No se pudo actualizar la asistencia.']);
        }

        return $request->expectsJson()
            ? response()->json(['ok' => true, 'message' => $message])
            : back()->with('success', $message);
    }
}
