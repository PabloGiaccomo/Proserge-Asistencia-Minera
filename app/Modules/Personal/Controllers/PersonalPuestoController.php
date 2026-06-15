<?php

namespace App\Modules\Personal\Controllers;

use App\Http\Controllers\WebPageController;
use App\Models\PersonalPuesto;
use App\Modules\Personal\Services\PersonalPuestoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PersonalPuestoController extends WebPageController
{
    public function __construct(private readonly PersonalPuestoService $service)
    {
    }

    public function index(): View
    {
        return view('personal.puestos.index', [
            'puestos' => $this->service->list(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'nombre' => ['required', 'string', 'max:191'],
            'funciones' => ['nullable', 'string', 'max:5000'],
        ]);

        $this->service->create($validated);

        return redirect()
            ->route('personal.puestos.index')
            ->with('success', 'Puesto creado correctamente.');
    }

    public function update(Request $request, string $id): RedirectResponse
    {
        $puesto = PersonalPuesto::query()->findOrFail($id);
        $validated = $request->validate([
            'nombre' => ['required', 'string', 'max:191'],
            'funciones' => ['nullable', 'string', 'max:5000'],
            'activo' => ['nullable', 'boolean'],
        ]);

        $this->service->update($puesto, $validated);

        return redirect()
            ->route('personal.puestos.index')
            ->with('success', 'Puesto actualizado correctamente.');
    }

    public function destroy(string $id): RedirectResponse
    {
        $puesto = PersonalPuesto::query()->findOrFail($id);

        try {
            $this->service->delete($puesto);
        } catch (ValidationException $exception) {
            return redirect()
                ->route('personal.puestos.index')
                ->with('error', collect($exception->errors())->flatten()->first() ?: 'No se pudo eliminar el puesto.');
        }

        return redirect()
            ->route('personal.puestos.index')
            ->with('success', 'Puesto eliminado correctamente.');
    }
}
