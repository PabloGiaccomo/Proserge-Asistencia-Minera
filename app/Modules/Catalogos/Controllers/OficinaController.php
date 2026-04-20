<?php

namespace App\Modules\Catalogos\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Oficina;
use App\Shared\Support\ApiResponse;
use Illuminate\Http\Request;

class OficinaController extends Controller
{
    public function index(Request $request)
    {
        $estado = $request->string('estado')->toString();

        $query = Oficina::query()->orderBy('nombre');

        if ($estado !== '') {
            $query->where('estado', strtoupper($estado));
        }

        return ApiResponse::success(
            data: $query->get(),
            message: 'Oficinas obtenidas',
            code: 'OFICINAS_LIST_OK',
        );
    }
}
