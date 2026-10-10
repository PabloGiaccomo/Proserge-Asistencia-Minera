<?php

namespace App\Modules\Dashboard\Policies;

use App\Models\Usuario;
use App\Support\Rbac\PermissionMatrix;

class DashboardPolicy
{
    public function view(Usuario $usuario): bool
    {
        return PermissionMatrix::userCanDirect($usuario, 'inicio', 'ver');
    }

    public function isPrivileged(Usuario $usuario): bool
    {
        return count(array_intersect(PermissionMatrix::activeRoleNames($usuario), ['ADMIN', 'GERENTE', 'SUPERADMIN'])) > 0
            || PermissionMatrix::userCanDirect($usuario, 'inicio', 'administrar');
    }
}
