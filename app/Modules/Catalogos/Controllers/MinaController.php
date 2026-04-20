<?php

namespace App\Modules\Catalogos\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Mina;
use App\Shared\Support\ApiResponse;
use Illuminate\Http\Request;

class MinaController extends Controller
{
    public function index(Request $request)
    {
        $estado = $request->string('estado')->toString();

        $query = Mina::query()->orderBy('nombre');

        if ($estado !== '') {
            $query->where('estado', strtoupper($estado));
        }

        return ApiResponse::success(
            data: $query->get(),
            message: 'Minas obtenidas',
            code: 'MINAS_LIST_OK',
        );
    }
}
