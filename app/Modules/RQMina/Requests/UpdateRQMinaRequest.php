<?php

namespace App\Modules\RQMina\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRQMinaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'mina_id' => ['nullable', 'string', 'size:36', 'exists:minas,id'],
            'destino_tipo' => ['nullable', 'string', 'in:MINA,TALLER,OFICINA'],
            'destino_id' => ['nullable', 'string', 'size:36'],
            'area' => ['required', 'string', 'max:191'],
            'fecha_inicio' => ['required', 'date'],
            'fecha_fin' => ['required', 'date', 'after_or_equal:fecha_inicio'],
            'observaciones' => ['nullable', 'string'],
            'detalle' => ['required', 'array', 'min:1'],
            'detalle.*.puesto' => ['required', 'string', 'max:191'],
            'detalle.*.cantidad' => ['required', 'integer', 'min:1'],
            'transporte' => ['nullable', 'array'],
            'transporte.*.transporte' => ['required', 'string', 'max:191'],
            'transporte.*.cantidad' => ['required', 'integer', 'min:1'],
        ];
    }
}
