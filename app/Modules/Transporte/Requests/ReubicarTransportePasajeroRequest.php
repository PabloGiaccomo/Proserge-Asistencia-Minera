<?php

namespace App\Modules\Transporte\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReubicarTransportePasajeroRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'destino_servicio_id' => ['required', 'string', 'size:36', 'exists:transporte_servicios,id'],
            'motivo' => ['required', 'string', 'max:1000'],
        ];
    }
}
