<?php

namespace App\Modules\RQMina\Controllers;

use App\Http\Controllers\WebPageController;
use App\Models\RQMina;
use App\Modules\RQMina\Services\ParadaDashboardExcelService;
use App\Modules\RQMina\Services\ParadaDashboardService;
use App\Modules\RQMina\Services\RQMinaService;
use App\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ParadaDashboardController extends WebPageController
{
    public function __construct(
        private readonly ParadaDashboardService $dashboardService,
        private readonly ParadaDashboardExcelService $excelService,
        private readonly RQMinaService $rqMinaService,
    ) {
    }

    public function show(Request $request, string $rqMina): View|RedirectResponse
    {
        $usuario = $this->requireAuthenticatedUser();
        $item = $this->findRqMina($usuario, $rqMina);

        if (!$item) {
            return redirect()->route('rq-mina.index')->with('error', 'RQ Mina no encontrado.');
        }

        $dashboard = $this->dashboardService->build($usuario, $item, $request->query());

        return view('rq-mina.dashboard', compact('dashboard'));
    }

    public function data(Request $request, string $rqMina): JsonResponse
    {
        $usuario = $this->requireAuthenticatedUser();
        $item = $this->findRqMina($usuario, $rqMina);

        abort_if(!$item, 404, 'RQ Mina no encontrado.');

        return response()->json([
            'ok' => true,
            'data' => $this->dashboardService->build($usuario, $item, $request->query()),
        ]);
    }

    public function recalculate(Request $request, string $rqMina): RedirectResponse
    {
        $usuario = $this->requireAuthenticatedUser();
        $item = $this->findRqMina($usuario, $rqMina);

        if (!$item) {
            return redirect()->route('rq-mina.index')->with('error', 'RQ Mina no encontrado.');
        }

        $planId = trim((string) $request->input('plan_id', '')) ?: null;
        $result = $this->dashboardService->recalculate($item, $planId);

        return redirect()
            ->route('rq-mina.dashboard', array_filter([
                'rqMina' => $item->id,
                'plan_id' => $planId,
                'fecha' => $request->input('fecha'),
                'turno' => $request->input('turno'),
            ]))
            ->with('success', 'Dashboard recalculado: '.(int) ($result['filas_actualizadas'] ?? 0).' fila(s) actualizada(s).');
    }

    public function export(Request $request, string $rqMina): StreamedResponse
    {
        $usuario = $this->requireAuthenticatedUser();
        $item = $this->findRqMina($usuario, $rqMina);

        abort_if(!$item, 404, 'RQ Mina no encontrado.');

        return $this->excelService->download(
            $this->dashboardService->build($usuario, $item, $request->query())
        );
    }

    public function print(Request $request, string $rqMina): View|RedirectResponse
    {
        $usuario = $this->requireAuthenticatedUser();
        $item = $this->findRqMina($usuario, $rqMina);

        if (!$item) {
            return redirect()->route('rq-mina.index')->with('error', 'RQ Mina no encontrado.');
        }

        $dashboard = $this->dashboardService->build($usuario, $item, $request->query());

        return view('rq-mina.dashboard-print', compact('dashboard'));
    }

    private function findRqMina(Usuario $usuario, string $id): ?RQMina
    {
        return $this->rqMinaService->findForUser($usuario, $id)?->loadMissing(['mina:id,nombre', 'planes']);
    }
}
