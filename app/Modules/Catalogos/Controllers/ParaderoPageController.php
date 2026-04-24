<?php

namespace App\Modules\Catalogos\Controllers;

use App\Http\Controllers\Controller;
use App\Models\MinaParadero;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ParaderoPageController extends Controller
{
    public function index(Request $request): View
    {
        $estado = strtoupper(trim((string) $request->query('estado', '')));
        $search = trim((string) $request->query('search', ''));

        $query = MinaParadero::query()->with('mina:id,nombre')->orderBy('nombre');

        if (in_array($estado, ['ACTIVO', 'INACTIVO'], true)) {
            $query->where('estado', $estado);
        }

        if ($search !== '') {
            $needle = '%' . mb_strtolower($search) . '%';
            $query->where(function ($sub) use ($needle): void {
                $sub->whereRaw('LOWER(nombre) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(ubicacion) LIKE ?', [$needle]);
            });
        }

        $data = $query->get()->map(function (MinaParadero $item): array {
            return [
                'id' => $item->id,
                'nombre' => $item->nombre,
                'ubicacion' => $item->ubicacion,
                'estado' => strtoupper((string) $item->estado),
                'activo' => strtoupper((string) $item->estado) === 'ACTIVO',
                'mina' => $item->mina?->nombre,
            ];
        })->values()->all();

        return view('catalogos.paraderos.index', compact('data'));
    }

    public function show(string $id): View
    {
        $paradero = MinaParadero::query()->with('mina:id,nombre')->find($id);
        if (!$paradero) {
            abort(404);
        }

        $item = [
            'id' => $paradero->id,
            'nombre' => $paradero->nombre,
            'ubicacion' => $paradero->ubicacion,
            'estado' => strtoupper((string) $paradero->estado),
            'activo' => strtoupper((string) $paradero->estado) === 'ACTIVO',
            'mina' => $paradero->mina?->nombre,
        ];

        return view('catalogos.paraderos.show', compact('item'));
    }
}