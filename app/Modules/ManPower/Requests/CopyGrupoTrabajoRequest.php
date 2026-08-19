<?php

namespace App\Modules\ManPower\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CopyGrupoTrabajoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'fecha_destino' => ['required', 'date'],
            'turno_destino' => ['required', 'string', 'in:DIA,NOCHE'],
            'copiar_integrantes' => ['nullable', 'boolean'],
            'observaciones' => ['nullable', 'string'],
        ];
    }
}
