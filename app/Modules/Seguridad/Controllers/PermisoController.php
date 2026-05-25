<?php

namespace App\Modules\Seguridad\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Rbac\PermissionCatalog;
use App\Shared\Support\ApiResponse;

class PermisoController extends Controller
{
    public function index()
    {
        $moduleActions = PermissionCatalog::availableModuleActions();
        $permisos = collect(PermissionCatalog::availableModules())
            ->flatMap(fn (string $label, string $module) => collect($moduleActions[$module] ?? [])
                ->map(fn (string $action) => $module . '.' . $action))
            ->values();

        return ApiResponse::success(
            data: $permisos,
            message: 'Permisos obtenidos',
            code: 'PERMISOS_LIST_OK',
        );
    }
}
