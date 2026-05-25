<?php

namespace App\Modules\ParadaHerramientas\Controllers;

use App\Http\Controllers\WebPageController;
use App\Modules\ParadaHerramientas\Services\ParadaHerramientaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ParadaHerramientaPageController extends WebPageController
{
    public function __construct(private readonly ParadaHerramientaService $service)
    {
    }

    public function index(Request $request): View
    {
        $usuario = $this->requireAuthenticatedUser();
        $this->service->emitDeadlineReminders();

        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'estado_lista' => trim((string) $request->query('estado_lista', '')),
        ];

        $items = $this->service->listParadas($usuario, $filters)->all();
        $deadlineAlerts = collect($items)
            ->filter(function (array $item): bool {
                $days = (int) ($item['dias_para_limite'] ?? 999);
                $status = strtoupper((string) ($item['estado_lista'] ?? ''));

                return $status !== 'ENVIADO' && $days >= 0 && $days <= 7;
            })
            ->sortBy([
                ['dias_para_limite', 'asc'],
                ['fecha_limite_envio', 'asc'],
            ])
            ->take(5)
            ->values()
            ->all();

        return view('parada-herramientas.index', compact('items', 'filters', 'deadlineAlerts'));
    }

    public function show(string $rqMinaId): View|RedirectResponse
    {
        $usuario = $this->requireAuthenticatedUser();
        $rq = $this->service->findParadaForUser($usuario, $rqMinaId);

        if (!$rq) {
            return redirect()->route('herramientas-parada.index')->with('error', 'Parada no encontrada o sin permisos.');
        }

        $item = $this->service->toDetailView($rq, $usuario);

        return view('parada-herramientas.show', compact('item'));
    }

    public function save(Request $request, string $rqMinaId): RedirectResponse
    {
        $usuario = $this->requireAuthenticatedUser();
        $rq = $this->service->findParadaForUser($usuario, $rqMinaId);

        if (!$rq) {
            return redirect()->route('herramientas-parada.index')->with('error', 'Parada no encontrada o sin permisos.');
        }

        $result = $this->service->saveLista($usuario, $rq, [
            'observaciones' => $request->input('observaciones'),
            'grupos' => $request->input('grupos', []),
        ]);

        if (!($result['ok'] ?? false)) {
            return back()->with('error', $result['message'] ?? 'No se pudo guardar la lista.')->withInput();
        }

        return redirect()
            ->route('herramientas-parada.show', $rqMinaId)
            ->with('success', $result['message'] ?? 'Lista guardada correctamente.');
    }

    public function enviar(string $rqMinaId): RedirectResponse
    {
        $usuario = $this->requireAuthenticatedUser();
        $rq = $this->service->findParadaForUser($usuario, $rqMinaId);

        if (!$rq) {
            return redirect()->route('herramientas-parada.index')->with('error', 'Parada no encontrada o sin permisos.');
        }

        $result = $this->service->enviarLista($usuario, $rq);

        if (!($result['ok'] ?? false)) {
            return back()->with('error', $result['message'] ?? 'No se pudo enviar la lista.');
        }

        return redirect()
            ->route('herramientas-parada.show', $rqMinaId)
            ->with('success', $result['message'] ?? 'Lista enviada correctamente.');
    }

    public function updatePedido(Request $request, string $rqMinaId): RedirectResponse
    {
        $usuario = $this->requireAuthenticatedUser();
        $rq = $this->service->findParadaForUser($usuario, $rqMinaId);

        if (!$rq) {
            return redirect()->route('herramientas-parada.index')->with('error', 'Parada no encontrada o sin permisos.');
        }

        $result = $this->service->updatePedido($usuario, $rq, [
            'grupos' => $request->input('grupos', []),
        ]);

        if (!($result['ok'] ?? false)) {
            return back()->with('error', $result['message'] ?? 'No se pudo actualizar el pedido.')->withInput();
        }

        return redirect()
            ->route('herramientas-parada.show', $rqMinaId)
            ->with('success', $result['message'] ?? 'Pedido actualizado correctamente.');
    }
}
