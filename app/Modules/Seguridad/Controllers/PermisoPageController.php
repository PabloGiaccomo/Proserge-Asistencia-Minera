<?php

namespace App\Modules\Seguridad\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Rbac\PermissionCatalog;

class PermisoPageController extends Controller
{
    public function index()
    {
        $data = collect(PermissionCatalog::modules())
            ->flatMap(function (string $label, string $module): array {
                return collect(PermissionCatalog::actions())
                    ->map(fn (string $action): array => [
                        'id' => $module . '.' . $action,
                        'nombre' => $action,
                        'descripcion' => 'Permite ' . $action . ' en el módulo ' . $label,
                        'modulo' => $label,
                    ])->all();
            })
            ->values()
            ->all();

        return view('seguridad.permisos.index', compact('data'));
    }
}
