<?php

namespace App\Modules\Transporte\Requests;

class RetireTransportePasajeroRequest extends TransporteFormRequest
{
    protected const ACTION = 'retirar_pasajero';
    protected const FORBIDDEN_CODE = 'TRANSPORTE_PASSENGER_RETIRE_FORBIDDEN';

    public function rules(): array
    {
        return [
            'motivo' => ['required', 'string', 'max:1000'],
        ];
    }
}
