<?php

namespace App\Modules\ManPower\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReubicarGrupoPersonalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'grupo_destino_id' => ['required', 'string', 'size:36', 'exists:grupo_trabajo,id'],
            'motivo' => ['required', 'string', 'max:1000'],
        ];
    }
}
