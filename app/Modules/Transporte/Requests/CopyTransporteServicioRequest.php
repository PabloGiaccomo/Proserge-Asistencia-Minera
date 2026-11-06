<?php

namespace App\Modules\Transporte\Requests;

class CopyTransporteServicioRequest extends TransporteFormRequest
{
    protected const ACTION = 'copiar';
    protected const FORBIDDEN_CODE = 'TRANSPORTE_COPY_FORBIDDEN';

    public function rules(): array
    {
        return [
            'fecha' => ['required', 'date'],
            'turno' => ['required', 'string', 'in:A,B,DIA,NOCHE'],
            'copiar_placa' => ['nullable', 'boolean'],
            'copiar_conductor' => ['nullable', 'boolean'],
        ];
    }
}
