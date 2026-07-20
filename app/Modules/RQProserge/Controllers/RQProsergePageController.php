<?php

namespace App\Modules\RQProserge\Controllers;

use App\Http\Controllers\WebPageController;
use App\Models\PersonalMina;
use App\Models\RQProserge;
use App\Models\RQMinaDetalleCambio;
use App\Modules\Notificaciones\Services\NotificationService;
use App\Modules\RQProserge\Services\RQProsergeService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class RQProsergePageController extends WebPageController
{
    public function __construct(
        private readonly NotificationService $notificationService,
        private readonly RQProsergeService $service,
    ) {
    }

    public function index(): View
    {
        $usuario = $this->requireAuthenticatedUser();
        $items = $this->service->listOperationalForUser($usuario);
        $data = [
            'data' => $items->map(fn (RQProserge $rq): array => $this->toViewItem($rq))->values()->all(),
        ];

        return view('rq-proserge.index', compact('data'));
    }

    public function show(string $id): View
    {
        $usuario = $this->requireAuthenticatedUser();
        $rq = $this->service->findForUser($usuario, $id);
        $item = $rq ? $this->toViewItem($rq->loadMissing($this->viewRelations())) : null;
        $disponibles = [];

        return view('rq-proserge.show', compact('item', 'disponibles'));
    }

    public function create(): View
    {
        return view('rq-proserge.create');
    }

    public function edit(string $id): View
    {
        $usuario = $this->requireAuthenticatedUser();
        $rq = $this->service->findForUser($usuario, $id);
        $item = $rq ? $this->toViewItem($rq->loadMissing($this->viewRelations())) : null;

        return view('rq-proserge.edit', compact('item'));
    }

    public function store(Request $request)
    {
        return redirect()->route('rq-proserge.index')->with('success', 'RQ creado correctamente');
    }

    public function update(Request $request, string $id)
    {
        $estado = strtoupper((string) $request->input('estado', ''));
        $usuario = $this->requireAuthenticatedUser();
        $rq = $this->service->findForUser($usuario, $id);

        if (!$rq) {
            return redirect()->route('rq-proserge.index')->with('error', 'RQ Proserge no encontrado o sin acceso.');
        }

        if ($blocked = $this->service->modificationBlockedByFinishedParada($rq)) {
            return redirect()->route('rq-proserge.show', $id)->with('error', (string) $blocked['message']);
        }

        if ($estado === 'PARCIAL') {
            $this->notificationService->emit('rq_proserge_parcial', [
                'actor_user_id' => session('user.id'),
                'entity_type' => 'rq_proserge',
                'entity_id' => $id,
                'title' => 'RQ Proserge parcialmente atendido',
                'message' => sprintf('El RQ Proserge %s quedo en estado parcial.', $id),
                'dedupe_key' => 'rq_proserge_parcial:' . $id . ':' . now()->format('YmdHi'),
            ]);
        }

        if (in_array($estado, ['COMPLETADO', 'ATENDIDO'], true)) {
            $this->notificationService->emit('rq_proserge_completado', [
                'actor_user_id' => session('user.id'),
                'entity_type' => 'rq_proserge',
                'entity_id' => $id,
                'title' => 'RQ Proserge completado',
                'message' => sprintf('El RQ Proserge %s fue completado.', $id),
                'dedupe_key' => 'rq_proserge_completado:' . $id,
            ]);
        }

        return redirect()->route('rq-proserge.show', $id)->with('success', 'RQ actualizado correctamente');
    }

    public function buscarPersonal(Request $request): JsonResponse
    {
        $usuario = $this->requireAuthenticatedUser();
        $payload = $request->validate([
            'rq_id' => ['required', 'string', 'size:36'],
            'q' => ['required', 'string', 'min:2', 'max:120'],
            'fecha_inicio' => ['required', 'date'],
            'fecha_fin' => ['required', 'date', 'after_or_equal:fecha_inicio'],
        ]);

        $rq = $this->service->findForUser($usuario, $payload['rq_id']);
        if (!$rq) {
            return response()->json(['error' => 'RQ Proserge no encontrado o sin acceso.'], 404);
        }

        return response()->json([
            'items' => $this->service->searchAvailablePersonal(
                rq: $rq,
                search: (string) $payload['q'],
                fechaInicio: (string) $payload['fecha_inicio'],
                fechaFin: (string) $payload['fecha_fin'],
            ),
        ]);
    }

    public function asignar(Request $request, string $id): JsonResponse
    {
        $usuario = $this->requireAuthenticatedUser();
        $payload = $request->validate([
            'rq_mina_detalle_id' => ['required', 'string', 'size:36', 'exists:rq_mina_detalle,id'],
            'personal_id' => ['required', 'string', 'size:36', 'exists:personal,id'],
            'puesto_asignado' => ['required', 'string', 'max:191'],
            'fecha_inicio' => ['required', 'date'],
            'fecha_fin' => ['required', 'date', 'after_or_equal:fecha_inicio'],
            'comentario' => ['nullable', 'string', 'max:2000'],
            'ultimo_turno_referencia' => ['nullable', 'string', 'max:10'],
        ]);

        $rq = $this->service->findForUser($usuario, $id);
        if (!$rq) {
            return response()->json(['error' => 'RQ Proserge no encontrado o sin acceso.'], 404);
        }

        try {
            $result = $this->service->assignPersonal($usuario, $rq, $payload);
        } catch (Throwable $e) {
            return response()->json(['error' => 'Error tecnico al asignar personal.', 'detail' => $e->getMessage()], 500);
        }

        if (($result['ok'] ?? false) === false) {
            return response()->json([
                'error' => (string) ($result['message'] ?? 'No se pudo asignar personal.'),
                'code' => (string) ($result['code'] ?? 'RQ_PROSERGE_ASSIGN_FAILED'),
            ], 422);
        }

        return response()->json([
            'message' => 'Personal asignado correctamente.',
            'item' => $this->toViewItem($result['rq']->loadMissing($this->viewRelations())),
        ]);
    }

    public function desasignar(Request $request, string $id): JsonResponse
    {
        $usuario = $this->requireAuthenticatedUser();
        $payload = $request->validate([
            'rq_proserge_detalle_id' => ['required', 'string', 'size:36', 'exists:rq_proserge_detalle,id'],
        ]);

        $rq = $this->service->findForUser($usuario, $id);
        if (!$rq) {
            return response()->json(['error' => 'RQ Proserge no encontrado o sin acceso.'], 404);
        }

        $result = $this->service->unassignPersonal($usuario, $rq, (string) $payload['rq_proserge_detalle_id']);

        if (($result['ok'] ?? false) === false) {
            return response()->json([
                'error' => (string) ($result['message'] ?? 'No se pudo desasignar personal.'),
                'code' => (string) ($result['code'] ?? 'RQ_PROSERGE_UNASSIGN_FAILED'),
            ], 422);
        }

        return response()->json([
            'message' => 'Personal desasignado correctamente.',
            'item' => $this->toViewItem($result['rq']->loadMissing($this->viewRelations())),
        ]);
    }

    private function viewRelations(): array
    {
        return [
            'mina:id,nombre',
            'responsableRrhh:id,email',
            'rqMina:id,mina_id,destino_tipo,destino_id,destino_nombre,area,fecha_inicio,fecha_fin,estado,observaciones',
            'rqMina.detalle.rqMina:id,fecha_inicio,fecha_fin',
            'rqMina.detalle.asignaciones.personal:id,dni,nombre_completo,puesto',
            'rqMina.detalle.asignaciones.personal.minas:id,nombre',
            'rqMina.detalle.cambios',
            'cambiosRqMina',
        ];
    }

    private function toViewItem(RQProserge $rq): array
    {
        $rq->loadMissing($this->viewRelations());

        $rqMina = $rq->rqMina;
        $detalles = $rqMina?->detalle ?? collect();
        $cambios = $rq->cambiosRqMina ?? collect();

        $puestos = $detalles->map(function ($detalle) use ($rq): array {
            $asignaciones = $detalle->asignaciones ?? collect();
            $requeridos = (int) ($detalle->cantidad_total ?: $detalle->cantidad);
            $personalAsignado = $asignaciones->map(fn ($asignacion): array => [
                'id' => $asignacion->id,
                'personal_id' => $asignacion->personal_id,
                'nombre' => trim(($asignacion->personal?->nombre_completo ?? '-') . ($asignacion->personal?->dni ? ' (' . $asignacion->personal->dni . ')' : '')),
                'comentario' => $asignacion->comentario ?: $asignacion->puesto_asignado,
                'fecha_inicio' => $this->formatDate($asignacion->fecha_inicio),
                'fecha_fin' => $this->formatDate($asignacion->fecha_fin),
                'fecha_inicio_iso' => $this->formatIsoDate($asignacion->fecha_inicio),
                'fecha_fin_iso' => $this->formatIsoDate($asignacion->fecha_fin),
                'mina_estado' => $this->mineStatusForPersonal($asignacion->personal, (string) $rq->mina_id),
            ])->values()->all();

            $cambios = ($detalle->cambios ?? collect())
                ->where('estado', RQMinaDetalleCambio::ESTADO_PENDIENTE)
                ->map(fn (RQMinaDetalleCambio $cambio): array => [
                    'tipo' => $cambio->tipo,
                    'mensaje' => $cambio->mensaje,
                    'fecha' => $cambio->created_at?->format('Y-m-d H:i'),
                ])
                ->values()
                ->all();

            return [
                'id' => $detalle->id,
                'nombre' => $detalle->puesto,
                'requeridos' => $requeridos,
                'asignados' => $asignaciones->count(),
                'trabajador' => '',
                'comentario' => '',
                'disponibilidad' => [
                    'tipo' => 'pendiente',
                    'lineas' => ['Busca y selecciona un trabajador para validar su disponibilidad en esta mina y rango de fechas.'],
                ],
                'fecha_inicio' => $this->formatDate($detalle->rqMina?->fecha_inicio),
                'fecha_fin' => $this->formatDate($detalle->rqMina?->fecha_fin),
                'fecha_inicio_iso' => $this->formatIsoDate($detalle->rqMina?->fecha_inicio),
                'fecha_fin_iso' => $this->formatIsoDate($detalle->rqMina?->fecha_fin),
                'asignaciones' => array_map(
                    fn (array $row): string => trim(($row['nombre'] ?? '-') . ' - ' . ($row['comentario'] ?? '-')),
                    $personalAsignado
                ),
                'personal_asignado' => $personalAsignado,
                'cambios' => $cambios,
            ];
        })->values();

        $solicitado = $puestos->sum(fn (array $puesto): int => (int) ($puesto['requeridos'] ?? 0));
        $atendido = $puestos->sum(fn (array $puesto): int => (int) ($puesto['asignados'] ?? 0));
        $paradaFinalizada = $this->rqMinaFinalizada($rqMina?->fecha_fin);

        return [
            'id' => $rq->id,
            'rq_mina_id' => $rq->rq_mina_id,
            'mina' => $rq->mina?->nombre ?? $rqMina?->destino_nombre ?? '-',
            'area' => $rqMina?->area ?? '-',
            'destino_tipo' => $rqMina?->destino_tipo ?? 'MINA',
            'destino_nombre' => $rqMina?->destino_nombre ?? $rq->mina?->nombre ?? '-',
            'fecha_inicio' => $this->formatDate($rqMina?->fecha_inicio),
            'fecha_fin' => $this->formatDate($rqMina?->fecha_fin),
            'fecha_inicio_iso' => $this->formatIsoDate($rqMina?->fecha_inicio),
            'fecha_fin_iso' => $this->formatIsoDate($rqMina?->fecha_fin),
            'estado' => $rq->estado,
            'estado_cierre' => $paradaFinalizada || in_array($rq->estado, ['CERRADO', 'CANCELADO'], true) ? 'cerrado' : 'abierto',
            'finalizada' => $paradaFinalizada,
            'bloqueo_edicion_mensaje' => $paradaFinalizada
                ? 'La parada ya finalizo. Las asignaciones y el seguimiento quedaron bloqueados.'
                : null,
            'solicitado' => $solicitado,
            'atendido' => $atendido,
            'personal_solicitado' => $solicitado,
            'personal_asignado' => $atendido,
            'puestos' => $puestos->all(),
            'cambios_pendientes' => $cambios->where('estado', RQMinaDetalleCambio::ESTADO_PENDIENTE)->count(),
            'cambios' => $cambios
                ->where('estado', RQMinaDetalleCambio::ESTADO_PENDIENTE)
                ->take(10)
                ->map(fn (RQMinaDetalleCambio $cambio): array => [
                    'tipo' => $cambio->tipo,
                    'puesto' => $cambio->puesto,
                    'mensaje' => $cambio->mensaje,
                    'fecha' => $cambio->created_at?->format('Y-m-d H:i'),
                ])
                ->values()
                ->all(),
        ];
    }

    private function formatDate(mixed $date): string
    {
        if ($date instanceof CarbonInterface) {
            return $date->format('d/m/Y');
        }

        return $date ? (string) $date : '-';
    }

    private function mineStatusForPersonal($personal, string $minaId): array
    {
        $mine = collect($personal?->minas ?? [])
            ->first(fn ($mina): bool => (string) $mina->id === $minaId);

        $state = strtoupper((string) ($mine?->pivot?->estado_habilitacion ?: $mine?->pivot?->estado ?: PersonalMina::ESTADO_NO_HABILITADO));

        return match ($state) {
            PersonalMina::ESTADO_HABILITADO => [
                'estado' => PersonalMina::ESTADO_HABILITADO,
                'label' => 'Habilitado en mina',
                'class' => 'is-enabled',
            ],
            PersonalMina::ESTADO_EN_PROCESO => [
                'estado' => PersonalMina::ESTADO_EN_PROCESO,
                'label' => 'En proceso en mina',
                'class' => 'is-process',
            ],
            default => [
                'estado' => PersonalMina::ESTADO_NO_HABILITADO,
                'label' => 'No habilitado en mina',
                'class' => 'is-not-enabled',
            ],
        };
    }

    private function formatIsoDate(mixed $date): string
    {
        if ($date instanceof CarbonInterface) {
            return $date->toDateString();
        }

        return $date ? (string) $date : '';
    }

    private function rqMinaFinalizada(mixed $date): bool
    {
        $fin = $this->immutableDate($date);

        return $fin !== null && $fin->endOfDay()->lt(CarbonImmutable::now());
    }

    private function immutableDate(mixed $date): ?CarbonImmutable
    {
        if (!$date) {
            return null;
        }

        if ($date instanceof CarbonImmutable) {
            return $date->startOfDay();
        }

        if ($date instanceof CarbonInterface) {
            return CarbonImmutable::instance($date)->startOfDay();
        }

        return CarbonImmutable::parse((string) $date)->startOfDay();
    }
}
