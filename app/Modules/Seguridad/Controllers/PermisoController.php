<?php

namespace App\Modules\Seguridad\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Rbac\PermissionCatalog;
use App\Shared\Support\ApiResponse;

class PermisoController extends Controller
{
    public function index()
    {
        $permisos = collect(PermissionCatalog::modules())
            ->flatMap(fn (string $label, string $module) => collect(PermissionCatalog::actions())
                ->map(fn (string $action) => $module . '.' . $action))
            ->values();

        return ApiResponse::success(
            data: $permisos,
            message: 'Permisos obtenidos',
            code: 'PERMISOS_LIST_OK',
        );
    }
}
