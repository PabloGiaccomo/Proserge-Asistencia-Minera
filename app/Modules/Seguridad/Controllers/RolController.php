<?php

namespace App\Modules\Seguridad\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Rol;
use App\Shared\Support\ApiResponse;
use Illuminate\Http\Request;

class RolController extends Controller
{
    public function index(Request $request)
    {
        $estado = strtoupper($request->string('estado')->toString());

        $query = Rol::query()->orderBy('nombre');

        if ($estado !== '') {
            $query->where('estado', $estado);
        }

        return ApiResponse::success(
            data: $query->get(['id', 'nombre', 'permisos', 'estado']),
            message: 'Roles obtenidos',
            code: 'ROLES_LIST_OK',
        );
    }
}
