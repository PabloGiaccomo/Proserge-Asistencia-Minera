<?php

namespace App\Modules\Transporte\Policies;

use App\Models\RQMina;
use App\Models\TransporteServicio;
use App\Models\Usuario;
use App\Support\Rbac\MinaScopeAccess;
use App\Support\Rbac\PermissionMatrix;

class TransportePolicy
{
    public function grants(Usuario $usuario, string $accion): bool
    {
        $permisos = match ($accion) {
            'ver' => ['ver'],
            'crear', 'copiar' => ['crear'],
            'editar', 'alcances' => ['editar', 'actualizar'],
            'agregar_pasajeros' => ['entregar', 'actualizar'],
            'retirar_pasajero' => ['recepcionar', 'actualizar'],
            'cambiar_estado' => ['actualizar', 'entregar', 'recepcionar'],
            default => [],
        };

        return $permisos !== []
            && PermissionMatrix::userCanDirectAny($usuario, 'transportes', [...$permisos, 'administrar']);
    }

    public function allows(Usuario $usuario, string $accion, TransporteServicio $servicio): bool
    {
        return $this->grants($usuario, $accion)
            && $servicio->rqMina !== null
            && MinaScopeAccess::allows($usuario, $servicio->rqMina->mina_id);
    }

    public function canCreate(Usuario $usuario, RQMina $parada): bool
    {
        return $this->grants($usuario, 'crear') && MinaScopeAccess::allows($usuario, $parada->mina_id);
    }
}
