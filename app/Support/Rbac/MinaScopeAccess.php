<?php

namespace App\Support\Rbac;

use App\Models\Usuario;
use App\Models\UsuarioMinaScope;

/**
 * Acceso por scope de mina.
 *
 * Regla de la casa: los roles ADMIN, GERENTE y SUPERADMIN operan sin
 * restriccion; cualquier otro usuario solo accede a las minas registradas en
 * Usuarios > Scope Mina. Es el mismo criterio que usan RQ Proserge y las
 * herramientas de parada, por lo que Logistica debe respetarlo tambien.
 */
class MinaScopeAccess
{
    public const ROLES_SIN_RESTRICCION = ['ADMIN', 'GERENTE', 'SUPERADMIN'];

    public static function isPrivileged(Usuario $usuario): bool
    {
        return count(array_intersect(PermissionMatrix::activeRoleNames($usuario), self::ROLES_SIN_RESTRICCION)) > 0;
    }

    public static function allows(Usuario $usuario, ?string $minaId): bool
    {
        if (self::isPrivileged($usuario)) {
            return true;
        }

        $minaId = trim((string) $minaId);
        if ($minaId === '') {
            return false;
        }

        return UsuarioMinaScope::query()
            ->where('usuario_id', $usuario->id)
            ->where('mina_id', $minaId)
            ->exists();
    }
}
