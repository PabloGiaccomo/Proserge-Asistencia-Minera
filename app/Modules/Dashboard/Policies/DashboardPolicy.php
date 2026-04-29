<?php

namespace App\Modules\Dashboard\Policies;

use App\Models\Usuario;
use App\Support\Rbac\PermissionMatrix;

class DashboardPolicy
{
    public function view(Usuario $usuario): bool
    {
        return PermissionMatrix::userCan($usuario, 'inicio', 'ver');
    }

    public function isPrivileged(Usuario $usuario): bool
    {
        $rol = strtoupper((string) optional($usuario->rol)->nombre);

        return in_array($rol, ['ADMIN', 'GERENTE', 'SUPERADMIN'], true)
            || PermissionMatrix::userCan($usuario, 'inicio', 'administrar');
    }
}
