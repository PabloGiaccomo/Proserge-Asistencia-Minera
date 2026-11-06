<?php

namespace App\Modules\Transporte\Requests;

use App\Models\TransporteServicio;
class ChangeTransporteEstadoRequest extends TransporteFormRequest
{
    protected const ACTION = 'cambiar_estado';
    protected const FORBIDDEN_CODE = 'TRANSPORTE_STATE_FORBIDDEN';

    public function rules(): array
    {
        return [
            'estado' => ['required', 'string', 'in:'.implode(',', TransporteServicio::estados())],
            'observacion' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
