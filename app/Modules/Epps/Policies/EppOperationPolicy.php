<?php

namespace App\Modules\Epps\Policies;

use App\Models\EppAlmacen;
use App\Models\EppOperacion;
use App\Models\Personal;
use App\Models\Usuario;
use App\Modules\Epps\Services\EppScopeService;
use App\Support\Rbac\PermissionMatrix;

class EppOperationPolicy
{
    public function __construct(private readonly EppScopeService $scope) {}

    public function viewAny(Usuario $usuario): bool
    {
        return $this->can($usuario, 'ver_operaciones');
    }

    public function view(Usuario $usuario, EppOperacion $operacion): bool
    {
        return $this->canAccessOperation($usuario, $operacion, 'ver_operaciones');
    }

    public function prepareDelivery(Usuario $usuario, EppAlmacen $almacen, Personal $personal): bool
    {
        return $this->canPrepare($usuario, $almacen, $personal, 'preparar_entrega');
    }

    public function confirmDelivery(Usuario $usuario, EppOperacion $operacion): bool
    {
        return $this->canAccessOperation($usuario, $operacion, 'confirmar_entrega');
    }

    public function prepareReturn(Usuario $usuario, EppAlmacen $almacen, Personal $personal): bool
    {
        return $this->canPrepare($usuario, $almacen, $personal, 'preparar_devolucion');
    }

    public function confirmReturn(Usuario $usuario, EppOperacion $operacion): bool
    {
        return $this->canAccessOperation($usuario, $operacion, 'confirmar_devolucion');
    }

    public function viewEvidence(Usuario $usuario, EppOperacion $operacion): bool
    {
        return $this->canAccessOperation($usuario, $operacion, 'ver_evidencia');
    }

    public function downloadReceipt(Usuario $usuario, EppOperacion $operacion): bool
    {
        return $this->canAccessOperation($usuario, $operacion, 'descargar_constancia');
    }

    public function manageInventory(Usuario $usuario, EppAlmacen $almacen): bool
    {
        return $this->can($usuario, 'gestionar_inventario') && $this->scope->canAccessWarehouse($usuario, $almacen);
    }

    public function adjustInventory(Usuario $usuario, EppAlmacen $almacen): bool
    {
        return $this->can($usuario, 'ajustar_inventario') && $this->scope->canAccessWarehouse($usuario, $almacen);
    }

    public function correct(Usuario $usuario, EppOperacion $operacion): bool
    {
        return $this->canAccessOperation($usuario, $operacion, 'corregir');
    }

    public function cancel(Usuario $usuario, EppOperacion $operacion): bool
    {
        return $this->canAccessOperation($usuario, $operacion, 'anular');
    }

    public function approveSignatureException(Usuario $usuario, EppOperacion $operacion): bool
    {
        return $this->canAccessOperation($usuario, $operacion, 'aprobar_excepcion_firma');
    }

    private function can(Usuario $usuario, string $action): bool
    {
        return $usuario->estado === 'ACTIVO' && PermissionMatrix::userCanDirect($usuario, 'epps', $action);
    }

    private function canPrepare(Usuario $usuario, EppAlmacen $almacen, Personal $personal, string $action): bool
    {
        return $this->can($usuario, $action)
            && $this->scope->canAccessWarehouse($usuario, $almacen)
            && $this->scope->canAccessPersonal($usuario, $personal);
    }

    private function canAccessOperation(Usuario $usuario, EppOperacion $operacion, string $action): bool
    {
        if ($operacion->isCatalogWorkflow() && ! in_array($action, ['ver_operaciones', 'confirmar_entrega', 'confirmar_devolucion', 'ver_evidencia', 'descargar_constancia'], true)) {
            return false;
        }

        return $this->can($usuario, $action)
            && $operacion->personal !== null
            && ($operacion->isCatalogWorkflow() || ($operacion->almacen !== null && $this->scope->canAccessWarehouse($usuario, $operacion->almacen)))
            && $this->scope->canAccessPersonal($usuario, $operacion->personal);
    }
}
