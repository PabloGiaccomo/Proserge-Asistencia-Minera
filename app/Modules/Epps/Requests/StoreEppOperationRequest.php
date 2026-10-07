<?php

namespace App\Modules\Epps\Requests;

class StoreEppOperationRequest extends UpdateEppOperationRequest
{
    public function rules(): array
    {
        return parent::rules() + [
            'personal_id' => ['required', 'uuid', 'exists:personal,id'],
            'almacen_id' => ['required', 'uuid', 'exists:epp_almacenes,id'],
            'tipo' => ['required', 'in:ENTREGA,DEVOLUCION'],
            'submission_uuid' => ['required', 'uuid'],
        ];
    }
}
