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
        return PermissionMatrix::userCanDirectAny($usuario, 'asistencias', ['ver', 'crear', 'editar', 'actualizar', 'registrar', 'cerrar', 'reabrir']);
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
        if ($this->isResponsibleFor($usuario, $grupo)
            && PermissionMatrix::userCanDirect($usuario, 'mi_asistencia', 'ver')) {
            return true;
        }

        if ($this->canViewAllAttendances($usuario)) {
            $minaId = (string) optional($grupo->rqMina)->mina_id;

            return $minaId !== '' && $this->canAccessMina($usuario, $minaId);
        }

        if (!$this->manage($usuario)) {
            return false;
        }

        $minaId = (string) optional($grupo->rqMina)->mina_id;

        return $minaId !== '' && $this->canAccessMina($usuario, $minaId);
    }

    public function canViewAllAttendances(Usuario $usuario): bool
    {
        return $this->isPrivileged($usuario)
            || PermissionMatrix::userCanDirect($usuario, 'mi_asistencia', 'ver_todas_asistencias');
    }

    public function canRegisterGrupo(Usuario $usuario, GrupoTrabajo $grupo): bool
    {
        if ($this->isResponsibleFor($usuario, $grupo)
            && PermissionMatrix::userCanDirect($usuario, 'mi_asistencia', 'ver')) {
            return true;
        }

        return PermissionMatrix::userCanDirect($usuario, 'asistencias', 'registrar')
            && $this->manageGrupo($usuario, $grupo);
    }

    public function canCloseGrupo(Usuario $usuario, GrupoTrabajo $grupo): bool
    {
        if ($this->isResponsibleFor($usuario, $grupo)
            && PermissionMatrix::userCanDirect($usuario, 'mi_asistencia', 'ver')) {
            return true;
        }

        return PermissionMatrix::userCanDirect($usuario, 'asistencias', 'cerrar')
            && $this->manageGrupo($usuario, $grupo);
    }

    public function isResponsibleFor(Usuario $usuario, GrupoTrabajo $grupo): bool
    {
        return filled($usuario->personal_id)
            && (string) $usuario->personal_id === (string) $grupo->supervisor_id;
    }

    private function isPrivileged(Usuario $usuario): bool
    {
        $rol = strtoupper((string) optional($usuario->rol)->nombre);

        return in_array($rol, ['ADMIN', 'GERENTE', 'SUPERADMIN'], true)
            || PermissionMatrix::userCanDirect($usuario, 'asistencias', 'administrar');
    }
}
