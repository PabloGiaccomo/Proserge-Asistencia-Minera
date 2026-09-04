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
        $canManageAll = PermissionMatrix::userCanDirect($user, 'mi_asistencia', 'ver_todas_asistencias');
        $attendanceReady = $canManageAll
            || filled($grupo->supervisor_id)
            || filled($grupo->asistencia?->id)
            || filled($user->personal_id);

        return view('mi-asistencia.show', [
            'user' => $user,
            'grupo' => $data,
            'canViewAll' => $canManageAll,
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
            'registrar_llegada' => ['sometimes', 'boolean'],
        ]);
        $grupo = $this->service->getGrupo($user, $id);
        abort_if(!$grupo, 404, 'Grupo no encontrado o sin acceso');

        $result = $this->service->marcar($user, $grupo, $payload);

        return $this->attendanceResponse($request, $result, 'Asistencia actualizada.');
    }

    public function estado(Request $request, string $id): JsonResponse
    {
        $grupo = $this->service->getGrupo($this->requireAuthenticatedUser(), $id);
        abort_if(!$grupo, 404, 'Grupo no encontrado o sin acceso');
        $data = AsistenciaGrupoResource::make($grupo)->resolve($request);
        return response()->json([
            'horario' => $data['horario_marcacion'],
            'cerrado' => $grupo->asistencia?->estado === 'CERRADO',
            'integrantes' => collect($data['integrantes'])->map(fn (array $item): array => [
                'id' => $item['grupo_trabajo_detalle_id'],
                'estado' => $item['estado_asistencia'],
                'hora' => $item['hora_marcado'],
            ])->values(),
        ])->header('Cache-Control', 'no-store');
    }

    public function buscarDni(Request $request, string $id): JsonResponse
    {
        $user = $this->requireAuthenticatedUser();
        $payload = $request->validate([
            'codigo' => ['required', 'string', 'max:500'],
        ]);
        $grupo = $this->service->getGrupo($user, $id);

        abort_if(!$grupo, 404, 'Grupo no encontrado o sin acceso');
        abort_unless($this->policy->canRegisterGrupo($user, $grupo), 403, 'No tienes permiso para registrar esta asistencia');

        return response()->json($this->service->buscarPersonalPorCodigoDni($grupo, $payload['codigo']));
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

    public function mobile(Request $request): View
    {
        $user = $this->requireAuthenticatedUser();
        $fecha = $request->validate(['fecha' => ['nullable', 'date']])['fecha'] ?? now()->toDateString();
        $turno = now()->hour < 18 ? 'DIA' : 'NOCHE';

        $grupos = $this->service->listMiAsistencia($user, ['fecha' => $fecha, 'turno' => $turno]) ?? collect();
        $grupo = $grupos->first();

        if (!$grupo) {
            return view('mi-asistencia.mobile-empty', [
                'user' => $user,
                'fecha' => $fecha,
            ]);
        }

        $canRegister = $this->policy->canRegisterGrupo($user, (object) $grupo);

        $miAsistencia = null;
        if (filled($user->personal_id) && filled($grupo['grupo_id'])) {
            $miAsistencia = $this->service->getMiEstadoAsistencia($user, $grupo['grupo_id']);
        }

        return view('mi-asistencia.mobile', [
            'user' => $user,
            'grupo' => $grupo,
            'canRegister' => $canRegister,
            'miAsistencia' => $miAsistencia,
        ]);
    }

    public function marcarMobile(Request $request, string $id): JsonResponse
    {
        $user = $this->requireAuthenticatedUser();
        $grupo = $this->service->getGrupo($user, $id);
        abort_if(!$grupo, 404, 'Grupo no encontrado o sin acceso');

        $payload = $request->validate([
            'grupo_trabajo_detalle_id' => ['required', 'string', 'size:36', 'exists:grupo_trabajo_detalle,id'],
            'estado' => ['required', 'string', 'in:PRESENTE,AUSENTE,TARDANZA,JUSTIFICADO'],
            'hora_marcado' => ['nullable', 'date_format:H:i'],
            'observaciones' => ['nullable', 'string', 'max:1000'],
        ]);

        $result = $this->service->marcar($user, $grupo, $payload);

        if (($result['ok'] ?? false) === false) {
            return response()->json(['ok' => false, 'message' => $result['message'] ?? 'No se pudo marcar.'], 422);
        }

        return response()->json(['ok' => true, 'message' => 'Asistencia registrada.']);
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
            ? response()->json(['ok' => true, 'message' => $message, 'estado' => $result['estado'] ?? null, 'hora_marcado' => $result['hora_marcado'] ?? null])
            : back()->with('success', $message);
    }
}
