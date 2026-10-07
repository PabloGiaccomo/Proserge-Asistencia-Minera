<?php

namespace App\Modules\Epps\Requests;

class StoreEppReturnRequest extends StoreEppOperationRequest
{
    public function rules(): array
    {
        return array_replace(parent::rules(), [
            'tipo' => ['required', 'in:DEVOLUCION'],
            'lines.*.entrega_origen_id' => ['required', 'uuid', 'exists:epp_entregas,id'],
            'lines.*.condicion_devolucion' => ['required', 'in:BUENO,DESGASTADO,DANADO,INCOMPLETO,OTRO'],
            'lines.*.destino_inventario' => ['required', 'in:DISPONIBLE,CUARENTENA,BAJA'],
        ]);
    }
}
