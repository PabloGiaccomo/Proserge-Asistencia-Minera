<?php

namespace App\Modules\Epps\Services;

use App\Models\EppAlmacen;
use App\Models\Personal;
use App\Models\PersonalMina;
use App\Models\Usuario;
use App\Support\Rbac\MinaScopeAccess;
use Illuminate\Auth\Access\AuthorizationException;

class EppScopeService
{
    public function assertWarehouseAccess(Usuario $usuario, EppAlmacen $almacen): void
    {
        if (! $this->canAccessWarehouse($usuario, $almacen)) {
            throw new AuthorizationException('No tienes acceso a este almacen EPP.');
        }
    }

    public function assertPersonalAccess(Usuario $usuario, Personal $personal): void
    {
        if (! $this->canAccessPersonal($usuario, $personal)) {
            throw new AuthorizationException('No tienes acceso a este trabajador.');
        }
    }

    public function canAccessWarehouse(Usuario $usuario, EppAlmacen $almacen): bool
    {
        return $usuario->estado === 'ACTIVO'
            && MinaScopeAccess::allows($usuario, $almacen->mina_id);
    }

    public function canAccessPersonal(Usuario $usuario, Personal $personal): bool
    {
        if ($usuario->estado !== 'ACTIVO') {
            return false;
        }
        if (MinaScopeAccess::isPrivileged($usuario)) {
            return true;
        }

        return PersonalMina::query()
            ->where('personal_id', $personal->id)
            ->where('activo', true)
            ->whereIn('mina_id', $usuario->scopesMina()->select('mina_id'))
            ->exists();
    }

    public function visibleWarehouseIds(Usuario $usuario): array
    {
        if ($usuario->estado !== 'ACTIVO') {
            return [];
        }

        $query = EppAlmacen::query();
        if (! MinaScopeAccess::isPrivileged($usuario)) {
            $query->whereIn('mina_id', $usuario->scopesMina()->select('mina_id'));
        }

        return $query->pluck('id')->all();
    }
}
