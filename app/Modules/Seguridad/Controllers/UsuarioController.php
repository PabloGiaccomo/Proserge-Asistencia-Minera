<?php

namespace App\Modules\Seguridad\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Usuario;
use App\Shared\Support\ApiResponse;
use Illuminate\Http\Request;

class UsuarioController extends Controller
{
    public function index(Request $request)
    {
        $estado = strtoupper($request->string('estado')->toString());

        $query = Usuario::query()
            ->select(['id', 'email', 'rol_id', 'personal_id', 'created_at'])
            ->with(['rol:id,nombre,estado']);

        if ($estado !== '') {
            $query->whereHas('rol', function ($q) use ($estado): void {
                $q->where('estado', $estado);
            });
        }

        return ApiResponse::success(
            data: $query->orderBy('email')->get(),
            message: 'Usuarios obtenidos',
            code: 'USUARIOS_LIST_OK',
        );
    }
}
