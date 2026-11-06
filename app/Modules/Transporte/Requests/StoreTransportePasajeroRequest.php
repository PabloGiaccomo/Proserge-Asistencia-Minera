<?php

namespace App\Modules\Transporte\Requests;

class StoreTransportePasajeroRequest extends TransporteFormRequest
{
    protected const ACTION = 'agregar_pasajeros';
    protected const FORBIDDEN_CODE = 'TRANSPORTE_PASSENGER_FORBIDDEN';

    public function rules(): array
    {
        return [
            'grupo_trabajo_detalle_id' => ['nullable', 'string', 'size:36', 'exists:grupo_trabajo_detalle,id'],
            'grupo_trabajo_detalle_ids' => ['nullable', 'array'],
            'grupo_trabajo_detalle_ids.*' => ['string', 'size:36', 'exists:grupo_trabajo_detalle,id'],
            'grupo_trabajo_id' => ['nullable', 'string', 'size:36', 'exists:grupo_trabajo,id'],
        ];
    }
}
