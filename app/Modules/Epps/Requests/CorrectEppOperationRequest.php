<?php

namespace App\Modules\Epps\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CorrectEppOperationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'motivo' => ['required', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.detalle_origen_id' => ['required', 'uuid', 'exists:epp_operacion_detalles,id'],
            'lines.*.cantidad' => ['required', 'integer', 'min:1', 'max:1000000'],
        ];
    }
}
