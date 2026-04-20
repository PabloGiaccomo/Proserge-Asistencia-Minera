<?php

namespace App\Modules\Seguridad\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Rol;
use App\Shared\Support\ApiResponse;

class PermisoController extends Controller
{
    public function index()
    {
        $permisos = Rol::query()
            ->pluck('permisos')
            ->filter(fn ($items) => is_array($items))
            ->flatten()
            ->filter(fn ($item) => is_string($item) && trim($item) !== '')
            ->map(fn (string $item) => trim($item))
            ->unique()
            ->sort()
            ->values();

        return ApiResponse::success(
            data: $permisos,
            message: 'Permisos obtenidos',
            code: 'PERMISOS_LIST_OK',
        );
    }
}
