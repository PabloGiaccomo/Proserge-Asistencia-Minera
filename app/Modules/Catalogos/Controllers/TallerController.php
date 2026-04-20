<?php

namespace App\Modules\Catalogos\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Taller;
use App\Shared\Support\ApiResponse;
use Illuminate\Http\Request;

class TallerController extends Controller
{
    public function index(Request $request)
    {
        $estado = $request->string('estado')->toString();

        $query = Taller::query()->orderBy('nombre');

        if ($estado !== '') {
            $query->where('estado', strtoupper($estado));
        }

        return ApiResponse::success(
            data: $query->get(),
            message: 'Talleres obtenidos',
            code: 'TALLERES_LIST_OK',
        );
    }
}
