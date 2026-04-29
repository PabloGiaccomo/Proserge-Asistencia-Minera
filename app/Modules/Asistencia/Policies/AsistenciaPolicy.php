<?php

namespace App\Modules\Asistencia\Policies;

use App\Models\GrupoTrabajo;
use App\Models\Usuario;
use App\Models\UsuarioMinaScope;
use App\Support\Rbac\PermissionMatrix;

class AsistenciaPolicy
{
    public function manage(Usuario $usuario): bool
    {
        return PermissionMatrix::userCanAny($usuario, 'asistencias', ['crear', 'editar', 'actualizar', 'cerrar']);
    }

    public function canAccessMina(Usuario $usuario, string $minaId): bool
    {
        if ($this->isPrivileged($usuario)) {
            return true;
        }

        return UsuarioMinaScope::query()
            ->where('usuario_id', $usuario->id)
            ->where('mina_id', $minaId)
            ->exists();
    }

    public function manageGrupo(Usuario $usuario, GrupoTrabajo $grupo): bool
    {
        if (!$this->manage($usuario)) {
            return false;
        }

        $minaId = (string) optional($grupo->rqMina)->mina_id;

        return $minaId !== '' && $this->canAccessMina($usuario, $minaId);
    }

    private function isPrivileged(Usuario $usuario): bool
    {
        $rol = strtoupper((string) optional($usuario->rol)->nombre);

        return in_array($rol, ['ADMIN', 'GERENTE', 'SUPERADMIN'], true)
            || PermissionMatrix::userCan($usuario, 'asistencias', 'administrar');
    }
}
