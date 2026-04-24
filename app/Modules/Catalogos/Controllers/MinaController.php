<?php

namespace App\Modules\Catalogos\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Mina;
use App\Modules\Catalogos\Services\MinaCatalogService;
use App\Shared\Support\ApiResponse;
use Illuminate\Http\Request;

class MinaController extends Controller
{
    public function __construct(private readonly MinaCatalogService $service)
    {
    }

    public function index(Request $request)
    {
        $data = $this->service
            ->list($request->only(['estado', 'search']))
            ->map(fn (Mina $mina) => $this->toApiItem($mina))
            ->values();

        return ApiResponse::success(
            data: $data,
            message: 'Minas obtenidas',
            code: 'MINAS_LIST_OK',
        );
    }

    public function show(string $id)
    {
        $mina = $this->service->find($id);

        if (!$mina) {
            return ApiResponse::error(
                message: 'Mina no encontrada',
                code: 'MINA_NOT_FOUND',
                status: 404,
            );
        }

        return ApiResponse::success(
            data: $this->toApiItem($mina),
            message: 'Mina obtenida',
            code: 'MINA_SHOW_OK',
        );
    }

    public function store(Request $request)
    {
        $validated = $request->validate($this->rules());

        $mina = $this->service->create($this->buildPayload($validated));

        return ApiResponse::success(
            data: $this->toApiItem($mina),
            message: 'Mina creada correctamente',
            code: 'MINA_CREATE_OK',
            status: 201,
        );
    }

    public function update(Request $request, string $id)
    {
        $mina = $this->service->find($id);
        if (!$mina) {
            return ApiResponse::error(
                message: 'Mina no encontrada',
                code: 'MINA_NOT_FOUND',
                status: 404,
            );
        }

        $validated = $request->validate($this->rules());
        $updated = $this->service->update($mina, $this->buildPayload($validated));

        return ApiResponse::success(
            data: $this->toApiItem($updated),
            message: 'Mina actualizada correctamente',
            code: 'MINA_UPDATE_OK',
        );
    }

    public function inactivate(string $id)
    {
        $mina = $this->service->find($id);
        if (!$mina) {
            return ApiResponse::error(
                message: 'Mina no encontrada',
                code: 'MINA_NOT_FOUND',
                status: 404,
            );
        }

        $inactivated = $this->service->inactivate($mina);

        return ApiResponse::success(
            data: $this->toApiItem($inactivated),
            message: 'Mina inactivada correctamente',
            code: 'MINA_INACTIVATE_OK',
        );
    }

    private function toApiItem(Mina $mina): array
    {
        $isActive = strtoupper((string) $mina->estado) === 'ACTIVO';

        return [
            'id' => $mina->id,
            'nombre' => $mina->nombre,
            'unidad_minera' => $mina->unidad_minera,
            'ubicacion' => $mina->ubicacion,
            'link_ubicacion' => $mina->link_ubicacion,
            'color' => $mina->color,
            'estado' => strtoupper((string) $mina->estado),
            'activo' => $isActive,
            'codigo' => $mina->unidad_minera ?: $mina->nombre,
            'paraderos' => $mina->relationLoaded('paraderos')
                ? $mina->paraderos->map(fn ($item) => [
                    'id' => $item->id,
                    'nombre' => $item->nombre,
                    'ubicacion' => $item->ubicacion,
                    'link_ubicacion' => $item->link_ubicacion,
                    'estado' => strtoupper((string) $item->estado),
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
        return [
            'nombre' => $validated['nombre'],
            'unidad_minera' => $validated['unidad_minera'] ?: $validated['nombre'],
            'ubicacion' => $validated['ubicacion'] ?? null,
            'link_ubicacion' => $validated['link_ubicacion'] ?? null,
            'color' => $validated['color'] ?? null,
            'estado' => $validated['estado'],
            'paraderos' => array_values(array_filter($validated['paraderos'] ?? [], fn ($item) => is_array($item))),
        ];
    }
}
