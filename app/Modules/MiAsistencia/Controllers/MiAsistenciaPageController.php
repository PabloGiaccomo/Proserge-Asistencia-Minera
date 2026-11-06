<?php

namespace App\Modules\MiAsistencia\Controllers;

use App\Http\Controllers\WebPageController;
use App\Models\GrupoTrabajo;
use App\Models\GrupoTrabajoDetalle;
use App\Models\Usuario;
use App\Modules\Asistencia\Policies\AsistenciaPolicy;
use App\Modules\Asistencia\Resources\AsistenciaGrupoResource;
use App\Modules\Asistencia\Services\AsistenciaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        $canViewAll = $this->policy->canViewAllAttendances($user);

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
        $attendanceReady = $this->policy->canIdentifyAttendanceResponsible($user, $grupo);
        $attendanceOpen = $grupo->estado !== GrupoTrabajo::ESTADO_CANCELADO && $grupo->asistencia?->estado !== 'CERRADO';
        $returnTurn = $request->query('turno', $grupo->turno);
        $returnFilters = [
            'fecha' => $data['fecha'],
            'turno' => in_array($returnTurn, ['DIA', 'NOCHE'], true) ? $returnTurn : null,
        ];
        $returnUrl = $request->boolean('volver_movil')
            ? route('mi-asistencia.mobile', [...$returnFilters, 'grupo_id' => $grupo->id])
            : route('mi-asistencia.index', $returnFilters);

        return view('mi-asistencia.show', [
            'user' => $user,
            'grupo' => $data,
            'canViewAll' => $this->policy->canViewAllAttendances($user),
            'attendanceReady' => $attendanceReady,
            'canRegister' => $attendanceOpen && $attendanceReady && $this->policy->canRegisterGrupo($user, $grupo),
            'canClose' => $attendanceOpen && $attendanceReady && $this->policy->canCloseGrupo($user, $grupo),
            'returnUrl' => $returnUrl,
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
        $user = $this->requireAuthenticatedUser();
        $grupo = $this->service->getGrupo($user, $id);
        abort_if(!$grupo, 404, 'Grupo no encontrado o sin acceso');
        $data = AsistenciaGrupoResource::make($grupo)->resolve($request);

        if ($request->boolean('propio')) {
            $mobile = $this->mobileGroup($user, $grupo, $data);

            return response()->json([
                'propio' => $mobile['marca_propia'],
                'puede_marcar' => $mobile['puede_marcar'],
                'mensaje' => $mobile['mensaje'],
            ])->header('Cache-Control', 'no-store');
        }

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
        $filters = $request->validate([
            'fecha' => ['nullable', 'date_format:Y-m-d'],
            'turno' => ['nullable', 'in:DIA,NOCHE'],
            'grupo_id' => ['nullable', 'uuid'],
        ]);
        $fecha = $filters['fecha'] ?? now(config('app.display_timezone', 'America/Lima'))->toDateString();
        $turno = $filters['turno'] ?? '';
        $grupos = $this->service->listMiAsistencia($user, ['fecha' => $fecha, 'turno' => $turno]) ?? collect();
        $selectedId = $filters['grupo_id'] ?? ($grupos->count() === 1 ? $grupos->first()['id'] : null);
        $selected = $selectedId ? $this->service->getGrupo($user, $selectedId, refreshMarks: false) : null;
        $grupo = $selected && $selected->fecha?->toDateString() === $fecha
            && ($turno === '' || $selected->turno === $turno)
            && ($grupos->contains('id', $selectedId) || $selected->estado === GrupoTrabajo::ESTADO_CANCELADO)
            ? $this->mobileGroup($user, $selected, AsistenciaGrupoResource::make($selected)->resolve($request))
            : null;

        return view('mi-asistencia.mobile', [
            'user' => $user,
            'grupo' => $grupo,
            'grupos' => $grupos,
            'fecha' => $fecha,
            'turno' => $turno,
            'selectionUnavailable' => $selectedId !== null && !$grupo,
        ]);
    }

    public function marcarMobile(Request $request, string $id): JsonResponse
    {
        $user = $this->requireAuthenticatedUser();
        $grupo = $this->service->getGrupo($user, $id, refreshMarks: false);
        abort_if(!$grupo, 404, 'Grupo no encontrado o sin acceso');
        abort_unless($this->policy->canRegisterGrupo($user, $grupo), 403, 'No tienes permiso para registrar esta asistencia.');

        $payload = $request->validate([
            'grupo_trabajo_detalle_id' => ['required', 'uuid'],
            'estado' => ['required', 'in:PRESENTE'],
        ]);
        abort_unless($this->ownDetailId($user, $grupo) === $payload['grupo_trabajo_detalle_id'], 403, 'No figuras como integrante activo de este grupo.');

        $result = $this->service->marcar($user, $grupo, [...$payload, 'registrar_llegada' => true, 'solo_marca_propia' => true]);

        if (in_array($result['code'] ?? null, ['ASISTENCIA_ALREADY_CLOSED', 'ASISTENCIA_GROUP_CANCELLED', 'ASISTENCIA_PERSON_NOT_IN_GROUP'], true)) {
            $result['status'] = 409;
        }

        return $this->attendanceResponse($request, $result, 'Asistencia registrada.', forceJson: true);
    }

    private function ownDetailId(Usuario $user, GrupoTrabajo $grupo): ?string
    {
        if (blank($user->personal_id)) {
            return null;
        }

        return $grupo->detalle->first(fn (GrupoTrabajoDetalle $item): bool => $item->personal_id === $user->personal_id
            && $item->isDistribucionActiva())?->id;
    }

    private function mobileGroup(Usuario $user, GrupoTrabajo $grupo, array $data): array
    {
        $ownDetailId = $this->ownDetailId($user, $grupo);
        $ownMark = collect($data['integrantes'])->firstWhere('grupo_trabajo_detalle_id', $ownDetailId);
        $canRegister = $this->policy->canRegisterGrupo($user, $grupo);
        $status = match (true) {
            $grupo->estado === GrupoTrabajo::ESTADO_CANCELADO => 'Cancelado',
            $data['asistencia']['estado'] === 'CERRADO' => 'Cerrada',
            default => 'Abierta',
        };
        $message = match (true) {
            $status === 'Cancelado' => 'Grupo cancelado. Puedes consultar sus datos.',
            $status === 'Cerrada' => 'Asistencia cerrada. Puedes consultar tu registro.',
            blank($user->personal_id) => 'Solicita al administrador que vincule tu cuenta con un trabajador.',
            !$canRegister && blank($grupo->supervisor_id) => 'El grupo no tiene responsable asignado. Solicita la asignación en Man Power.',
            !$canRegister => 'Solo tienes permiso para consultar la asistencia de este grupo.',
            !$ownDetailId => 'No figuras como integrante activo de este grupo.',
            !$data['horario_marcacion']['limite'] => $data['horario_marcacion']['mensaje'],
            default => null,
        };

        return [
            'id' => $grupo->id,
            'mina' => $data['mina_nombre'] ?: $data['destino']['nombre'] ?: 'Sin unidad minera',
            'servicio' => $data['servicio'] ?: $grupo->nombre_snapshot ?: 'Grupo operativo',
            'turno' => $data['turno'],
            'horario' => substr((string) $data['horario_salida'], 0, 5),
            'estado' => $status,
            'total' => $data['asistencia']['metricas']['programado'],
            'puede_registrar' => $canRegister && $status === 'Abierta' && $this->policy->canIdentifyAttendanceResponsible($user, $grupo),
            'puede_marcar' => $message === null,
            'detalle_propio_id' => $ownDetailId,
            'marca_propia' => $ownMark ? ['id' => $ownDetailId, 'estado' => $ownMark['estado_asistencia'], 'hora' => $ownMark['hora_marcado']] : null,
            'sin_responsable' => blank($grupo->supervisor_id),
            'mensaje' => $message,
        ];
    }

    private function attendanceResponse(Request $request, array $result, string $message, bool $forceJson = false): JsonResponse|RedirectResponse
    {
        $asJson = $forceJson || $request->expectsJson();
        if (($result['ok'] ?? false) === false) {
            $status = ($result['forbidden'] ?? false) ? 403 : (int) ($result['status'] ?? 422);

            return $asJson
                ? response()->json(['ok' => false, 'message' => $result['message'] ?? 'No se pudo actualizar la asistencia.'], $status)
                : back()->withErrors(['asistencia' => $result['message'] ?? 'No se pudo actualizar la asistencia.']);
        }

        return $asJson
            ? response()->json(['ok' => true, 'message' => $message, 'estado' => $result['estado'] ?? null, 'hora_marcado' => $result['hora_marcado'] ?? null])
            : back()->with('success', $message);
    }
}
