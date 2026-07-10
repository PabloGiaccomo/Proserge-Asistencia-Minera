<?php

namespace App\Modules\ParadaHerramientas\Controllers;

use App\Http\Controllers\WebPageController;
use App\Modules\ParadaHerramientas\Services\ParadaHerramientaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ParadaHerramientaPageController extends WebPageController
{
    public function __construct(private readonly ParadaHerramientaService $service)
    {
    }

    public function index(Request $request): RedirectResponse
    {
        $query = [
            'tab' => 'herramientas',
            'q' => trim((string) $request->query('q', '')),
            'estado_lista' => trim((string) $request->query('estado_lista', '')),
        ];

        $query = array_filter($query, static fn ($value): bool => $value !== '');

        return redirect()->route('logistica.index', $query);
    }

    public function show(string $rqMinaId): View|RedirectResponse
    {
        $usuario = $this->requireAuthenticatedUser();
        $rq = $this->service->findParadaForUser($usuario, $rqMinaId);

        if (!$rq) {
            return redirect()->route('logistica.index', ['tab' => 'herramientas'])->with('error', 'Parada no encontrada o sin permisos.');
        }

        $item = $this->service->toDetailView($rq, $usuario);

        return view('parada-herramientas.show', compact('item'));
    }

    public function confirmarPedido(Request $request, string $rqMinaId): View|RedirectResponse
    {
        $usuario = $this->requireAuthenticatedUser();
        $rq = $this->service->findParadaForUser($usuario, $rqMinaId);

        if (!$rq) {
            return redirect()->route('logistica.index', ['tab' => 'herramientas'])->with('error', 'Parada no encontrada o sin permisos.');
        }

        $item = $this->service->toDetailView($rq, $usuario);
        $modo = $request->query('modo') === 'recepcion' ? 'recepcion' : 'entrega';

        return view('parada-herramientas.pedido', compact('item', 'modo'));
    }

    public function save(Request $request, string $rqMinaId): RedirectResponse
    {
        $usuario = $this->requireAuthenticatedUser();
        $rq = $this->service->findParadaForUser($usuario, $rqMinaId);

        if (!$rq) {
            return redirect()->route('logistica.index', ['tab' => 'herramientas'])->with('error', 'Parada no encontrada o sin permisos.');
        }

        $result = $this->service->saveLista($usuario, $rq, [
            'observaciones' => $request->input('observaciones'),
            'comentario_cambio_previo' => $request->input('comentario_cambio_previo'),
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
            return redirect()->route('logistica.index', ['tab' => 'herramientas'])->with('error', 'Parada no encontrada o sin permisos.');
        }

        $result = $this->service->enviarLista($usuario, $rq);

        if (!($result['ok'] ?? false)) {
            return back()->with('error', $result['message'] ?? 'No se pudo enviar la lista.');
        }

        return redirect()
            ->route('herramientas-parada.show', $rqMinaId)
            ->with('success', $result['message'] ?? 'Lista enviada correctamente.');
    }

    public function recordarSupervisor(string $rqMinaId, string $grupoId): RedirectResponse
    {
        $usuario = $this->requireAuthenticatedUser();
        $rq = $this->service->findParadaForUser($usuario, $rqMinaId);

        if (!$rq) {
            return redirect()->route('logistica.index', ['tab' => 'herramientas'])->with('error', 'Parada no encontrada o sin permisos.');
        }

        $result = $this->service->enviarRecordatorioSupervisor($usuario, $rq, $grupoId);

        return back()->with(($result['ok'] ?? false) ? 'success' : 'error', $result['message'] ?? 'No se pudo enviar el correo.');
    }

    public function importarFormato(Request $request, string $rqMinaId, string $grupoId): RedirectResponse
    {
        $usuario = $this->requireAuthenticatedUser();
        $rq = $this->service->findParadaForUser($usuario, $rqMinaId);

        if (!$rq) {
            return redirect()->route('logistica.index', ['tab' => 'herramientas'])->with('error', 'Parada no encontrada o sin permisos.');
        }

        $request->validate([
            'archivo' => ['required', 'file', 'mimes:xlsx,xls,xlsm', 'max:20480'],
        ]);

        $result = $this->service->importarFormatoGrupo($usuario, $rq, $grupoId, $request->file('archivo'));

        return redirect()
            ->route('herramientas-parada.show', $rqMinaId)
            ->with(($result['ok'] ?? false) ? 'success' : 'error', $result['message'] ?? 'No se pudo importar el formato.');
    }

    public function importarCatalogo(Request $request): RedirectResponse
    {
        $usuario = $this->requireAuthenticatedUser();

        $request->validate([
            'archivo' => ['required', 'file', 'mimes:xlsx,xls,xlsm', 'max:20480'],
        ]);

        $result = $this->service->importarCatalogo($usuario, $request->file('archivo'));

        return redirect()
            ->route('logistica.index', ['tab' => 'herramientas'])
            ->with(($result['ok'] ?? false) ? 'success' : 'error', $result['message'] ?? 'No se pudo actualizar el catalogo.');
    }

    public function sugerenciasCatalogo(Request $request): JsonResponse
    {
        $this->requireAuthenticatedUser();

        return response()->json([
            'items' => $this->service->sugerirCatalogo(
                trim((string) $request->query('q', '')),
                trim((string) $request->query('categoria', '')),
                (int) $request->query('limit', 20)
            ),
        ]);
    }

    public function sugerenciasObservaciones(Request $request): JsonResponse
    {
        $this->requireAuthenticatedUser();

        return response()->json([
            'items' => $this->service->sugerirObservaciones(
                trim((string) $request->query('descripcion', '')),
                trim((string) $request->query('categoria', '')),
                (int) $request->query('limit', 10)
            ),
        ]);
    }

    public function updatePedido(Request $request, string $rqMinaId): RedirectResponse
    {
        $usuario = $this->requireAuthenticatedUser();
        $rq = $this->service->findParadaForUser($usuario, $rqMinaId);

        if (!$rq) {
            return redirect()->route('logistica.index', ['tab' => 'herramientas'])->with('error', 'Parada no encontrada o sin permisos.');
        }

        $result = $this->service->updatePedido($usuario, $rq, [
            'modo' => $request->input('modo'),
            'fecha_recepcion' => $request->input('fecha_recepcion'),
            'grupos' => $request->input('grupos', []),
        ]);

        if (!($result['ok'] ?? false)) {
            return back()->with('error', $result['message'] ?? 'No se pudo actualizar el pedido.')->withInput();
        }

        return back()->with('success', $result['message'] ?? 'Pedido actualizado correctamente.');
    }
}
