<?php

namespace App\Modules\Catalogos\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Mina;
use App\Modules\Catalogos\Services\MinaCatalogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MinaPageController extends Controller
{
    public function __construct(private readonly MinaCatalogService $service)
    {
    }

    public function index(Request $request): View
    {
        $data = $this->service
            ->list($request->only(['estado', 'search']))
            ->map(fn (Mina $mina) => $this->toViewItem($mina))
            ->values()
            ->all();

        return view('catalogos.minas.index', compact('data'));
    }

    public function create(): View
    {
        $item = [
            'id' => null,
            'nombre' => '',
            'unidad_minera' => '',
            'ubicacion' => '',
            'link_ubicacion' => '',
            'color' => '',
            'estado' => 'ACTIVO',
            'paraderos' => [],
        ];

        return view('catalogos.minas.create', compact('item'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate($this->rules());

        $this->service->create($this->buildPayload($validated));

        return redirect()->route('catalogos.minas.index')->with('success', 'Mina creada correctamente.');
    }

    public function show(string $id): View
    {
        $mina = $this->service->find($id);
        if (!$mina) {
            abort(404);
        }

        $item = $this->toViewItem($mina);

        return view('catalogos.minas.show', compact('item'));
    }

    public function edit(string $id): View
    {
        $mina = $this->service->find($id);
        if (!$mina) {
            abort(404);
        }

        $item = [
            ...$this->toViewItem($mina),
            'unidad_minera' => $mina->unidad_minera,
            'link_ubicacion' => $mina->link_ubicacion,
            'color' => $mina->color,
            'paraderos' => $mina->paraderos->map(function ($paradero): array {
                return [
                    'id' => $paradero->id,
                    'nombre' => $paradero->nombre,
                    'ubicacion' => $paradero->ubicacion,
                    'link_ubicacion' => $paradero->link_ubicacion,
                    'estado' => strtoupper((string) $paradero->estado),
                ];
            })->values()->all(),
        ];

        return view('catalogos.minas.edit', compact('item'));
    }

    public function update(Request $request, string $id): RedirectResponse
    {
        $mina = $this->service->find($id);
        if (!$mina) {
            abort(404);
        }

        $validated = $request->validate($this->rules());

        $this->service->update($mina, $this->buildPayload($validated));

        return redirect()->route('catalogos.minas.show', $id)->with('success', 'Mina actualizada correctamente.');
    }

    public function inactivate(string $id): RedirectResponse
    {
        $mina = $this->service->find($id);
        if (!$mina) {
            abort(404);
        }

        $this->service->inactivate($mina);

        return redirect()->route('catalogos.minas.index')->with('success', 'Mina inactivada correctamente.');
    }

    private function toViewItem(Mina $mina): array
    {
        $isActive = strtoupper((string) $mina->estado) === 'ACTIVO';

        return [
            'id' => $mina->id,
            'nombre' => $mina->nombre,
            'unidad_minera' => $mina->unidad_minera,
            'ubicacion' => $mina->ubicacion ?: 'Por definir',
            'link_ubicacion' => $mina->link_ubicacion,
            'color' => $mina->color,
            'estado' => strtoupper((string) $mina->estado),
            'activo' => $isActive,
            'codigo' => $mina->unidad_minera ?: $mina->nombre,
            'descripcion' => $mina->link_ubicacion ?: '-',
            'paraderos' => $mina->relationLoaded('paraderos')
                ? $mina->paraderos->map(fn ($paradero) => [
                    'id' => $paradero->id,
                    'nombre' => $paradero->nombre,
                    'ubicacion' => $paradero->ubicacion,
                    'estado' => strtoupper((string) $paradero->estado),
                ])->values()->all()
                : [],
        ];
    }

    private function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:191'],
            'unidad_minera' => ['nullable', 'string', 'max:191'],
            'ubicacion' => ['nullable', 'string', 'max:191'],
            'link_ubicacion' => ['nullable', 'string', 'max:500'],
            'color' => ['nullable', 'string', 'max:30'],
            'estado' => ['required', 'string', 'max:20'],
            'paraderos' => ['nullable', 'array'],
            'paraderos.*.id' => ['nullable', 'string', 'size:36'],
            'paraderos.*.nombre' => ['nullable', 'string', 'max:191'],
            'paraderos.*.ubicacion' => ['nullable', 'string', 'max:191'],
            'paraderos.*.link_ubicacion' => ['nullable', 'string', 'max:500'],
            'paraderos.*.estado' => ['nullable', 'string', 'max:20'],
        ];
    }

    private function buildPayload(array $validated): array
    {
        $paraderos = collect($validated['paraderos'] ?? [])
            ->filter(fn ($item) => is_array($item))
            ->values()
            ->all();

        return [
            'nombre' => $validated['nombre'],
            'unidad_minera' => $validated['unidad_minera'] ?: $validated['nombre'],
            'ubicacion' => $validated['ubicacion'] ?? null,
            'link_ubicacion' => $validated['link_ubicacion'] ?? null,
            'color' => $validated['color'] ?? null,
            'estado' => $validated['estado'] ?? 'ACTIVO',
            'paraderos' => $paraderos,
        ];
    }
}