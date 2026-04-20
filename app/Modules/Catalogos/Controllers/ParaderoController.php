<?php

namespace App\Modules\Catalogos\Controllers;

use App\Http\Controllers\Controller;
use App\Models\MinaParadero;
use App\Shared\Support\ApiResponse;
use Illuminate\Http\Request;

class ParaderoController extends Controller
{
    public function index(Request $request)
    {
        $minaId = $request->string('mina_id')->toString();
        $estado = $request->string('estado')->toString();

        $query = MinaParadero::query()
            ->with('mina:id,nombre')
            ->orderBy('nombre');

        if ($minaId !== '') {
            $query->where('mina_id', $minaId);
        }

        if ($estado !== '') {
            $query->where('estado', strtoupper($estado));
        }

        return ApiResponse::success(
            data: $query->get(),
            message: 'Paraderos obtenidos',
            code: 'PARADEROS_LIST_OK',
        );
    }
}
