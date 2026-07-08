<?php

namespace App\Modules\Epps\Controllers;

use App\Http\Controllers\WebPageController;
use App\Modules\Epps\Services\EppService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class EppPageController extends WebPageController
{
    public function __construct(private readonly EppService $service)
    {
    }

    public function index(Request $request): RedirectResponse
    {
        $this->requireAuthenticatedUser();

        $query = array_filter([
            'tab' => 'entregas',
            'q' => $request->query('q'),
            'estado' => $request->query('estado'),
            'per_page' => $request->query('per_page'),
        ], static fn ($value): bool => $value !== null && $value !== '');

        return redirect()->to(url('/logistica').'?'.http_build_query($query));
    }

    public function buscarPersonal(Request $request): JsonResponse
    {
        $this->requireAuthenticatedUser();

        return response()->json([
            'items' => $this->service->searchPersonal((string) $request->query('q', '')),
        ]);
    }

    public function ultimaEntrega(Request $request): JsonResponse
    {
        $this->requireAuthenticatedUser();

        return response()->json([
            'data' => $this->service->lastDeliverySummary(
                (string) $request->query('personal_id', ''),
                (string) $request->query('epp_id', '')
            ),
        ]);
    }

    public function storeCatalog(Request $request): RedirectResponse
    {
        $this->requireAuthenticatedUser();

        try {
            $this->service->storeCatalog($request->validate($this->catalogRules()));
        } catch (InvalidArgumentException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'EPP guardado correctamente.');
    }

    public function updateCatalog(Request $request, string $id): RedirectResponse
    {
        $this->requireAuthenticatedUser();

        try {
            $this->service->updateCatalog($id, $request->validate($this->catalogRules()));
        } catch (InvalidArgumentException $exception) {
            return back()
                ->withInput($request->input() + ['catalog_edit_id' => $id])
                ->with('error', $exception->getMessage());
        }

        return back()->with('success', 'EPP actualizado correctamente.');
    }

    public function storeEntrega(Request $request): RedirectResponse
    {
        $usuario = $this->requireAuthenticatedUser();

        $this->service->deliver($request->validate([
            'personal_id' => ['required', 'string', 'exists:personal,id'],
            'epp_id' => ['required', 'string', 'exists:epp_registro,id'],
            'cantidad' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'fecha_entrega' => ['required', 'date'],
            'observacion' => ['nullable', 'string', 'max:1000'],
        ]), $usuario);

        return back()->with('success', 'Entrega de EPP registrada correctamente.');
    }

    public function closeEntrega(Request $request, string $id): RedirectResponse
    {
        $usuario = $this->requireAuthenticatedUser();

        $this->service->closeEntrega($id, $request->validate([
            'estado' => ['required', 'string', 'in:CAMBIADO,DEVUELTO'],
            'devuelto_at' => ['required', 'date'],
            'motivo_cambio' => ['nullable', 'string', 'max:120'],
            'observacion' => ['nullable', 'string', 'max:1000'],
        ]), $usuario);

        return back()->with('success', 'Movimiento de EPP cerrado correctamente.');
    }

    private function catalogRules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:191'],
            'vida_util_dias' => ['required', 'integer', 'min:1', 'max:3650'],
            'requiere_talla' => ['nullable', 'boolean'],
            'tallas' => ['nullable', 'string', 'max:1000'],
            'requiere_color' => ['nullable', 'boolean'],
            'colores' => ['nullable', 'string', 'max:1000'],
            'estado' => ['nullable', 'string', 'max:20'],
            'catalog_edit_id' => ['nullable', 'string'],
        ];
    }
}
