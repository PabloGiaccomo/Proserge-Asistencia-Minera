<?php

namespace App\Modules\Catalogos\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Taller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class TallerPageController extends Controller
{
    public function index(): View
    {
        $data = Taller::query()
            ->orderBy('nombre')
            ->get()
            ->map(fn (Taller $taller) => $this->toViewItem($taller))
            ->values()
            ->all();

        return view('catalogos.talleres.index', compact('data'));
    }

    public function create(): View
    {
        $item = [
            'id' => null,
            'nombre' => '',
            'ubicacion' => '',
            'estado' => 'ACTIVO',
        ];

        return view('catalogos.talleres.create', compact('item'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate($this->rules());

        $taller = Taller::query()->create([
            'id' => (string) Str::uuid(),
            'nombre' => trim((string) $validated['nombre']),
            'ubicacion' => trim((string) ($validated['ubicacion'] ?? '')) ?: null,
            'estado' => strtoupper((string) $validated['estado']),
        ]);

        return redirect()->route('catalogos.talleres.show', $taller->id)->with('success', 'Taller creado correctamente.');
    }

    public function show(string $id): View
    {
        $taller = Taller::query()->find($id);
        abort_if(!$taller, 404);

        $item = $this->toViewItem($taller);

        return view('catalogos.talleres.show', compact('item'));
    }

    public function edit(string $id): View
    {
        $taller = Taller::query()->find($id);
        abort_if(!$taller, 404);

        $item = $this->toViewItem($taller);

        return view('catalogos.talleres.edit', compact('item'));
    }

    public function update(Request $request, string $id): RedirectResponse
    {
        $taller = Taller::query()->find($id);
        abort_if(!$taller, 404);

        $validated = $request->validate($this->rules());

        $taller->fill([
            'nombre' => trim((string) $validated['nombre']),
            'ubicacion' => trim((string) ($validated['ubicacion'] ?? '')) ?: null,
            'estado' => strtoupper((string) $validated['estado']),
        ]);
        $taller->save();

        return redirect()->route('catalogos.talleres.show', $taller->id)->with('success', 'Taller actualizado correctamente.');
    }

    public function destroy(string $id): RedirectResponse
    {
        $taller = Taller::query()->find($id);
        abort_if(!$taller, 404);

        $taller->delete();

        return redirect()->route('catalogos.talleres.index')->with('success', 'Taller eliminado correctamente.');
    }

    private function toViewItem(Taller $taller): array
    {
        $activo = strtoupper((string) $taller->estado) === 'ACTIVO';

        return [
            'id' => $taller->id,
            'nombre' => $taller->nombre,
            'ubicacion' => $taller->ubicacion ?: 'Por definir',
            'estado' => strtoupper((string) $taller->estado),
            'activo' => $activo,
            'codigo' => $taller->id,
            'descripcion' => $taller->ubicacion ?: 'Sin descripción registrada',
        ];
    }

    private function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:191'],
            'ubicacion' => ['nullable', 'string', 'max:191'],
            'estado' => ['required', 'string', 'max:20'],
        ];
    }
}
