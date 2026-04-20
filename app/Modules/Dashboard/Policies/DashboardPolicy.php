<?php

namespace App\Modules\Dashboard\Policies;

use App\Models\Usuario;

class DashboardPolicy
{
    public function view(Usuario $usuario): bool
    {
        $rol = strtoupper((string) optional($usuario->rol)->nombre);

        return in_array($rol, ['PLANNER', 'RRHH', 'SUPERVISOR', 'ADMIN', 'GERENTE', 'SUPERADMIN'], true);
    }

    public function isPrivileged(Usuario $usuario): bool
    {
        $rol = strtoupper((string) optional($usuario->rol)->nombre);

        return in_array($rol, ['ADMIN', 'GERENTE', 'SUPERADMIN'], true);
    }
}
