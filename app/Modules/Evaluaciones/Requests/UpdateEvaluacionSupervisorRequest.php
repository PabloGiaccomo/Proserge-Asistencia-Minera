<?php

namespace App\Modules\Evaluaciones\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateEvaluacionSupervisorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'respuestas' => ['required', 'array'],
            'respuestas.*' => ['nullable', 'integer', 'min:1', 'max:5'],
            'comentarios_finales' => ['nullable', 'string'],
            'aspectos_positivos' => ['nullable', 'string'],
            'capacitaciones_recomendadas' => ['nullable', 'string'],
            'firma' => ['nullable', 'string'],
            'estado' => ['nullable', 'string', 'in:REGISTRADA,REVISADA,CERRADA'],
        ];
    }
}
