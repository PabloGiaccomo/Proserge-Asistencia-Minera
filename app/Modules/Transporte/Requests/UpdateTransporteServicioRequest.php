<?php

namespace App\Modules\Transporte\Requests;

class UpdateTransporteServicioRequest extends StoreTransporteServicioRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        $rules['rq_mina_id'] = ['sometimes', 'string', 'size:36', 'exists:rq_mina,id'];
        $rules['tipo'] = ['sometimes', ...array_slice($rules['tipo'], 1)];
        $rules['fecha'] = ['sometimes', 'date'];
        $rules['turno'] = ['sometimes', 'string', 'in:A,B,DIA,NOCHE'];

        return $rules;
    }
}
