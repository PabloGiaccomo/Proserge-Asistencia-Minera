<?php

namespace App\Modules\Dashboard\Policies;

use App\Models\Usuario;
use App\Support\Rbac\MinaScopeAccess;
use App\Support\Rbac\PermissionMatrix;

class DashboardPolicy
{
    public function view(Usuario $usuario): bool
    {
        return PermissionMatrix::userCanDirect($usuario, 'inicio', 'ver');
    }

    public function isPrivileged(Usuario $usuario): bool
    {
        return MinaScopeAccess::isPrivileged($usuario);
    }

    public function canViewSection(Usuario $usuario, string $section): bool
    {
        $module = match ($section) {
            'rqMina' => 'rq_mina',
            'rqProserge' => 'rq_proserge',
            'manPower' => 'man_power',
            'asistencia' => 'asistencias',
            'faltas', 'evaluaciones' => $section,
            default => null,
        };

        return $module !== null && $this->view($usuario)
            && PermissionMatrix::userCanDirect($usuario, $module, 'dashboards');
    }
}
