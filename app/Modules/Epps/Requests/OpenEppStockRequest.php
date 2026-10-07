<?php

namespace App\Modules\Epps\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OpenEppStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'almacen_id' => ['required', 'uuid', 'exists:epp_almacenes,id'],
            'motivo' => ['required', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.epp_id' => ['required', 'uuid', 'exists:epp_registro,id'],
            'lines.*.cantidad' => ['required', 'integer', 'min:0', 'max:1000000'],
            'lines.*.talla' => ['nullable', 'string', 'max:80'],
            'lines.*.color' => ['nullable', 'string', 'max:120'],
            'lines.*.serie' => ['nullable', 'string', 'max:200'],
            'lines.*.atributos' => ['nullable', 'array', 'max:20'],
            'lines.*.atributos.*' => ['nullable', 'string', 'max:200'],
        ];
    }
}
