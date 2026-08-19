<?php

namespace App\Modules\Transporte\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CopyTransporteServicioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

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
