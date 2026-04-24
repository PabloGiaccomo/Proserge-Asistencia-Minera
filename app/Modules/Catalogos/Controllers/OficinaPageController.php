<?php

namespace App\Modules\Catalogos\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Oficina;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class OficinaPageController extends Controller
{
    public function index(): View
    {
        $data = Oficina::query()
            ->orderBy('nombre')
            ->get()
            ->map(fn (Oficina $oficina) => $this->toViewItem($oficina))
            ->values()
            ->all();

        return view('catalogos.oficinas.index', compact('data'));
    }

    public function create(): View
    {
        $item = [
            'id' => null,
            'nombre' => '',
            'ubicacion' => '',
            'estado' => 'ACTIVO',
        ];

        return view('catalogos.oficinas.create', compact('item'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate($this->rules());

        $oficina = Oficina::query()->create([
            'id' => (string) Str::uuid(),
            'nombre' => trim((string) $validated['nombre']),
            'ubicacion' => trim((string) ($validated['ubicacion'] ?? '')) ?: null,
            'estado' => strtoupper((string) $validated['estado']),
        ]);

        return redirect()->route('catalogos.oficinas.show', $oficina->id)->with('success', 'Oficina creada correctamente.');
    }

    public function show(string $id): View
    {
        $oficina = Oficina::query()->find($id);
        abort_if(!$oficina, 404);

        $item = $this->toViewItem($oficina);

        return view('catalogos.oficinas.show', compact('item'));
    }

    public function edit(string $id): View
    {
        $oficina = Oficina::query()->find($id);
        abort_if(!$oficina, 404);

        $item = $this->toViewItem($oficina);

        return view('catalogos.oficinas.edit', compact('item'));
    }

    public function update(Request $request, string $id): RedirectResponse
    {
        $oficina = Oficina::query()->find($id);
        abort_if(!$oficina, 404);

        $validated = $request->validate($this->rules());

        $oficina->fill([
            'nombre' => trim((string) $validated['nombre']),
            'ubicacion' => trim((string) ($validated['ubicacion'] ?? '')) ?: null,
            'estado' => strtoupper((string) $validated['estado']),
        ]);
        $oficina->save();

        return redirect()->route('catalogos.oficinas.show', $oficina->id)->with('success', 'Oficina actualizada correctamente.');
    }

    public function destroy(string $id): RedirectResponse
    {
        $oficina = Oficina::query()->find($id);
        abort_if(!$oficina, 404);

        $oficina->delete();

        return redirect()->route('catalogos.oficinas.index')->with('success', 'Oficina eliminada correctamente.');
    }

    private function toViewItem(Oficina $oficina): array
    {
        $activo = strtoupper((string) $oficina->estado) === 'ACTIVO';

        return [
            'id' => $oficina->id,
            'nombre' => $oficina->nombre,
            'ubicacion' => $oficina->ubicacion ?: 'Por definir',
            'estado' => strtoupper((string) $oficina->estado),
            'activo' => $activo,
            'codigo' => $oficina->id,
            'descripcion' => $oficina->ubicacion ?: 'Sin descripción registrada',
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
