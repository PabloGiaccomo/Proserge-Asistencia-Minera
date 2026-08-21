<?php

namespace App\Modules\ManPower\Controllers;

use App\Models\GrupoTrabajo;
use App\Models\RQMinaDetalle;
use App\Http\Controllers\WebPageController;
use App\Modules\ManPower\Requests\AddGrupoPersonalRequest;
use App\Modules\ManPower\Requests\CancelGruposDiaRequest;
use App\Modules\ManPower\Requests\CopyGruposDiaRequest;
use App\Modules\ManPower\Requests\CopyGruposRangoRequest;
use App\Modules\ManPower\Requests\CopyGrupoTrabajoRequest;
use App\Modules\ManPower\Requests\RetireGrupoPersonalRequest;
use App\Modules\ManPower\Requests\ReubicarGrupoPersonalRequest;
use App\Modules\ManPower\Requests\RemoveGrupoPersonalRequest;
use App\Modules\ManPower\Requests\StoreGrupoTrabajoRequest;
use App\Modules\ManPower\Requests\UpdateGrupoTrabajoRequest;
use App\Modules\ManPower\Services\GrupoTrabajoService;
use App\Modules\ManPower\Services\ManPowerParadasService;
use App\Modules\ManPower\Services\ManPowerPlanningService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ManPowerPageController extends WebPageController
{
    public function __construct(
        private readonly ManPowerParadasService $paradasService,
        private readonly GrupoTrabajoService $grupoService,
        private readonly ManPowerPlanningService $planningService,
    ) {
    }

    public function index(Request $request): View
    {
        return $this->paradas($request);
    }

    public function paradas(Request $request): View
    {
        $usuario = $this->requireAuthenticatedUser();
        $data = $this->paradasService->listForUser($usuario, [
            'mina_id' => $request->query('mina_id'),
            'unidad_minera' => $request->query('unidad_minera'),
            'estado' => $request->query('estado'),
            'fecha' => $request->query('fecha', now()->addDay()->toDateString()),
            'q' => $request->query('q'),
        ]);

        $unidades = $this->paradasService->listUnidades($usuario);

        return view('man-power.paradas', compact('data', 'unidades'));
    }

    public function paradaDetalle(Request $request, string $rqMinaId): View
    {
        $request->query->set('rq_mina_id', $rqMinaId);

        return $this->grupos($request);
    }

    public function grupos(Request $request): View
    {
        $usuario = $this->requireAuthenticatedUser();
        $filters = [
            'rq_mina_id' => $request->query('rq_mina_id') ?: $request->query('parada_id'),
            'plan_id' => $request->query('plan_id'),
            'actividad_id' => $request->query('actividad_id'),
            'fecha' => $request->query('fecha', now()->addDay()->toDateString()),
            'turno' => $request->query('turno', 'DIA'),
            'q' => trim((string) $request->query('q', '')),
        ];
        $requestedTurn = strtoupper((string) $filters['turno']) === 'NOCHE' ? 'NOCHE' : 'DIA';
        $filters['turno'] = $requestedTurn;
        $dashboardMode = in_array($request->query('dashboard_modo'), ['resumen', 'turnos', 'cargos', 'cobertura'], true)
            ? (string) $request->query('dashboard_modo')
            : 'resumen';
        $context = $this->planningService->buildContext($usuario, $filters);
        $resolved = $context['selected'] ?? [];
        $contextsByTurn = [$requestedTurn => $context];

        foreach (['DIA', 'NOCHE'] as $turno) {
            if ($turno === $requestedTurn) {
                continue;
            }

            $contextsByTurn[$turno] = $this->planningService->buildContext($usuario, array_merge($filters, [
                'rq_mina_id' => $resolved['rq_mina_id'] ?? $filters['rq_mina_id'],
                'plan_id' => $resolved['plan_id'] ?? $filters['plan_id'],
                'actividad_id' => $resolved['actividad_id'] ?? $filters['actividad_id'],
                'fecha' => $resolved['fecha'] ?? $filters['fecha'],
                'turno' => $turno,
            ]));
        }

        $periodSummary = $dashboardMode === 'resumen'
            ? $this->planningService->buildPeriodSummary($usuario, [
                'rq_mina_id' => $resolved['rq_mina_id'] ?? $filters['rq_mina_id'],
                'plan_id' => $resolved['plan_id'] ?? $filters['plan_id'],
                'actividad_id' => $resolved['actividad_id'] ?? $filters['actividad_id'],
                'fecha' => $resolved['fecha'] ?? $filters['fecha'],
            ])
            : [];

        return view('man-power.workspace', [
            'context' => $context,
            'contextsByTurn' => $contextsByTurn,
            'activeView' => in_array($request->query('vista'), ['dashboard', 'seleccion', 'grupos'], true)
                ? $request->query('vista')
                : 'dashboard',
            'dashboardMode' => $dashboardMode,
            'periodSummary' => $periodSummary,
            'fecha' => $context['selected']['fecha'] ?? now()->addDay()->toDateString(),
            'turno' => $context['selected']['turno'] ?? 'DIA',
            'search' => trim((string) $request->query('q', '')),
            'paradas' => $context['paradas'] ?? [],
            'selectedRqMinaId' => $context['selected']['rq_mina_id'] ?? '',
            'selectedPlanId' => $context['selected']['plan_id'] ?? '',
            'selectedActivityId' => $context['selected']['actividad_id'] ?? '',
            'parada' => $context['rq_mina'] ?? null,
            'grupos' => $context['grupos_man_power'] ?? [],
            'selectedPersonalIds' => collect($context['distribuidos'] ?? [])->pluck('personal_id')->unique()->values()->all(),
        ]);
    }

    public function updateCargoSharing(Request $request, string $detalleId)
    {
        $usuario = $this->requireAuthenticatedUser();
        $payload = $request->validate(['compartible' => ['required', 'boolean']]);
        $detalle = RQMinaDetalle::query()->with('rqMina:id,mina_id')->find($detalleId);

        if (!$detalle || !$detalle->rqMina || !$this->planningService->canAccessMina($usuario, (string) $detalle->rqMina->mina_id)) {
            abort(404);
        }

        $detalle->forceFill(['compartible_man_power' => (bool) $payload['compartible']])->save();

        return back()->with('success', (bool) $payload['compartible']
            ? 'El cargo ahora puede compartirse entre grupos.'
            : 'El cargo quedo limitado a un solo grupo por turno.');
    }

    public function grupoDetalle(string $id): View
    {
        $usuario = $this->requireAuthenticatedUser();
        $grupo = $this->grupoService->findForUser($usuario, $id);

        return view('man-power.grupo-detalle', compact('grupo'));
    }

    public function crearGrupo(Request $request): View
    {
        return $this->grupos($request);
    }

    public function storeGrupo(StoreGrupoTrabajoRequest $request)
    {
        $usuario = $this->requireAuthenticatedUser();
        $result = $this->grupoService->createGrupo($usuario, $request->validated());

        if (($result['ok'] ?? false) === false) {
            return back()
                ->withInput()
                ->withErrors(['grupo' => (string) ($result['message'] ?? 'No se pudo crear el grupo')]);
        }

        return redirect()
            ->route('man-power.grupos', [
                'rq_mina_id' => $request->validated('rq_mina_id'),
                'plan_id' => $request->validated('rq_mina_plan_id'),
                'actividad_id' => collect($request->validated('actividad_ids', []))->first(),
                'fecha' => $request->validated('fecha'),
                'turno' => $request->validated('turno'),
                'vista' => 'seleccion',
            ])
            ->with('success', 'Grupo creado correctamente');
    }

    public function updateGrupo(UpdateGrupoTrabajoRequest $request, string $id)
    {
        $usuario = $this->requireAuthenticatedUser();
        $grupo = GrupoTrabajo::query()->with(['rqMina'])->find($id);

        if (!$grupo) {
            if ($request->expectsJson()) {
                return response()->json(['ok' => false, 'message' => 'Grupo no encontrado'], 404);
            }

            return back()->withErrors(['grupo' => 'Grupo no encontrado']);
        }

        $result = $this->grupoService->updateGrupo($usuario, $grupo, $request->validated());

        if (($result['ok'] ?? false) === false) {
            return back()
                ->withInput()
                ->withErrors(['grupo' => (string) ($result['message'] ?? 'No se pudo actualizar el grupo')]);
        }

        return redirect()->route('man-power.grupo-detalle', $id)->with('success', 'Grupo actualizado correctamente');
    }

    public function agregarPersonal(AddGrupoPersonalRequest $request, string $id)
    {
        $usuario = $this->requireAuthenticatedUser();
        $grupo = GrupoTrabajo::query()->with(['rqMina'])->find($id);

        if (!$grupo) {
            if ($request->expectsJson()) {
                return response()->json([
                    'ok' => false,
                    'message' => 'Grupo no encontrado',
                    'code' => 'MANPOWER_GRUPO_NOT_FOUND',
                ], 404);
            }

            return back()->withErrors(['grupo' => 'Grupo no encontrado']);
        }

        $payload = $request->validated();
        $result = $this->grupoService->addPersonal(
            $usuario,
            $grupo,
            $payload['personal_id'] ?? null,
            $payload['rq_proserge_detalle_id'] ?? null,
        );

        if (($result['ok'] ?? false) === false) {
            $status = ($result['forbidden'] ?? false) ? 403 : 422;
            $message = (string) ($result['message'] ?? 'No se pudo agregar personal al grupo');

            if ($request->expectsJson()) {
                return response()->json([
                    'ok' => false,
                    'message' => $message,
                    'code' => (string) ($result['code'] ?? 'MANPOWER_ADD_PERSON_FAILED'),
                ], $status);
            }

            return back()->withErrors(['grupo' => $message]);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'message' => 'Personal agregado al grupo',
            ]);
        }

        return back()->with('success', 'Personal agregado al grupo');
    }

    public function quitarPersonal(RemoveGrupoPersonalRequest $request, string $id)
    {
        $usuario = $this->requireAuthenticatedUser();
        $result = $this->grupoService->quitarPersonal($usuario, $id, $request->validated('personal_id'));

        if (($result['success'] ?? false) === false) {
            return back()->withErrors(['grupo' => (string) ($result['message'] ?? 'No se pudo quitar personal')]);
        }

        return back()->with('success', 'Personal removido correctamente');
    }

    public function retirarPersonal(RetireGrupoPersonalRequest $request, string $id, string $detalleId)
    {
        $usuario = $this->requireAuthenticatedUser();
        $grupo = GrupoTrabajo::query()->with(['rqMina'])->find($id);

        if (!$grupo) {
            if ($request->expectsJson()) {
                return response()->json(['ok' => false, 'message' => 'Grupo no encontrado'], 404);
            }

            return back()->withErrors(['grupo' => 'Grupo no encontrado']);
        }

        $result = $this->grupoService->retireDetalle($usuario, $grupo, $detalleId, $request->validated('motivo'));

        if (($result['ok'] ?? false) === false) {
            $message = (string) ($result['message'] ?? 'No se pudo retirar integrante');

            if ($request->expectsJson()) {
                return response()->json(['ok' => false, 'message' => $message], 422);
            }

            return back()->withErrors(['grupo' => $message]);
        }

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'message' => 'Integrante retirado correctamente']);
        }

        return back()->with('success', 'Integrante retirado correctamente');
    }

    public function asignarResponsable(Request $request, string $id, string $detalleId)
    {
        $usuario = $this->requireAuthenticatedUser();
        $grupo = GrupoTrabajo::query()->with(['rqMina'])->find($id);

        if (!$grupo) {
            return $request->expectsJson()
                ? response()->json(['ok' => false, 'message' => 'Grupo no encontrado'], 404)
                : back()->withErrors(['grupo' => 'Grupo no encontrado']);
        }

        $result = $this->grupoService->setResponsable($usuario, $grupo, $detalleId);

        if (($result['ok'] ?? false) === false) {
            $status = ($result['forbidden'] ?? false) ? 403 : 422;
            $message = (string) ($result['message'] ?? 'No se pudo cambiar el responsable');

            return $request->expectsJson()
                ? response()->json(['ok' => false, 'message' => $message, 'code' => $result['code'] ?? null], $status)
                : back()->withErrors(['grupo' => $message]);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'message' => 'Responsable actualizado',
                'responsable_id' => $result['responsable_id'],
            ]);
        }

        return back()->with('success', 'Responsable actualizado');
    }

    public function reubicarPersonal(ReubicarGrupoPersonalRequest $request, string $id, string $detalleId)
    {
        $usuario = $this->requireAuthenticatedUser();
        $grupo = GrupoTrabajo::query()->with(['rqMina'])->find($id);

        if (!$grupo) {
            if ($request->expectsJson()) {
                return response()->json(['ok' => false, 'message' => 'Grupo no encontrado'], 404);
            }

            return back()->withErrors(['grupo' => 'Grupo no encontrado']);
        }

        $payload = $request->validated();
        $result = $this->grupoService->reubicarDetalle($usuario, $grupo, $detalleId, $payload['grupo_destino_id'], $payload['motivo']);

        if (($result['ok'] ?? false) === false) {
            $message = (string) ($result['message'] ?? 'No se pudo reubicar integrante');

            if ($request->expectsJson()) {
                return response()->json(['ok' => false, 'message' => $message], 422);
            }

            return back()->withErrors(['grupo' => $message]);
        }

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'message' => 'Integrante reubicado correctamente']);
        }

        return back()->with('success', 'Integrante reubicado correctamente');
    }

    public function copiarGrupo(CopyGrupoTrabajoRequest $request, string $id)
    {
        $usuario = $this->requireAuthenticatedUser();
        $grupo = GrupoTrabajo::query()->with(['rqMina'])->find($id);

        if (!$grupo) {
            return back()->withErrors(['grupo' => 'Grupo no encontrado']);
        }

        $result = $this->grupoService->copyGrupo($usuario, $grupo, $request->validated());

        if (($result['ok'] ?? false) === false) {
            return back()->withErrors(['grupo' => (string) ($result['message'] ?? 'No se pudo copiar grupo')]);
        }

        return redirect()->route('man-power.grupos', [
            'rq_mina_id' => $grupo->rq_mina_id,
            'plan_id' => $grupo->rq_mina_plan_id,
            'actividad_id' => $grupo->actividades()->value('rq_mina_actividades.id'),
            'fecha' => $request->validated('fecha_destino'),
            'turno' => $request->validated('turno_destino'),
            'vista' => 'grupos',
        ])->with('success', 'Grupo copiado correctamente');
    }

    public function copiarGruposDia(CopyGruposDiaRequest $request)
    {
        $usuario = $this->requireAuthenticatedUser();
        $payload = $request->validated();
        $result = $this->grupoService->copyDayGroups($usuario, $payload);

        if (($result['ok'] ?? false) === false) {
            return back()->withErrors(['grupo' => (string) ($result['message'] ?? 'No se pudieron copiar los grupos')]);
        }

        $message = sprintf(
            '%d grupo(s) copiado(s), %d grupo(s) anterior(es) reemplazado(s), %d integrante(s) copiado(s) y %d omitido(s).',
            $result['grupos_copiados'] ?? 0,
            $result['grupos_reemplazados'] ?? 0,
            $result['integrantes_copiados'] ?? 0,
            $result['integrantes_omitidos'] ?? 0,
        );

        return redirect()->route('man-power.grupos', [
            'rq_mina_id' => $payload['rq_mina_id'],
            'plan_id' => $payload['rq_mina_plan_id'] ?? null,
            'actividad_id' => $payload['rq_mina_actividad_destino_id'] ?? $payload['rq_mina_actividad_id'] ?? null,
            'fecha' => $payload['fecha_destino'],
            'vista' => 'seleccion',
        ])->with('success', $message);
    }

    public function copiarGruposRango(CopyGruposRangoRequest $request)
    {
        $usuario = $this->requireAuthenticatedUser();
        $payload = $request->validated();
        $result = $this->grupoService->copyDayGroupsToRange($usuario, $payload);

        if (($result['ok'] ?? false) === false) {
            return back()->withErrors(['grupo' => (string) ($result['message'] ?? 'No se pudieron copiar los grupos')]);
        }

        $scope = $payload['alcance'] === 'SEMANA' ? 'la semana' : 'la parada';
        $message = sprintf(
            'Grupos copiados al resto de %s: %d dia(s), %d grupo(s) y %d integrante(s).',
            $scope,
            $result['dias_copiados'] ?? 0,
            $result['grupos_copiados'] ?? 0,
            $result['integrantes_copiados'] ?? 0,
        );

        return redirect()->route('man-power.grupos', [
            'rq_mina_id' => $payload['rq_mina_id'],
            'plan_id' => $payload['rq_mina_plan_id'] ?? null,
            'actividad_id' => $payload['rq_mina_actividad_id'],
            'fecha' => $payload['fecha_origen'],
            'vista' => 'seleccion',
        ])->with('success', $message);
    }

    public function cancelarGruposDia(CancelGruposDiaRequest $request)
    {
        $usuario = $this->requireAuthenticatedUser();
        $payload = $request->validated();
        $result = $this->grupoService->cancelDayGroups($usuario, $payload);

        if (($result['ok'] ?? false) === false) {
            return back()->withErrors(['grupo' => (string) ($result['message'] ?? 'No se pudieron eliminar los grupos del dia')]);
        }

        return redirect()->route('man-power.grupos', [
            'rq_mina_id' => $payload['rq_mina_id'],
            'plan_id' => $payload['rq_mina_plan_id'] ?? null,
            'actividad_id' => $payload['rq_mina_actividad_id'],
            'fecha' => $payload['fecha'],
            'vista' => 'seleccion',
        ])->with('success', sprintf(
            '%d grupo(s) eliminado(s) de la seleccion y %d integrante(s) liberado(s).',
            $result['grupos_cancelados'] ?? 0,
            $result['integrantes_retirados'] ?? 0,
        ));
    }
}
