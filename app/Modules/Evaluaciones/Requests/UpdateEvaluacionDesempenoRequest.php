<?php

namespace App\Modules\Evaluaciones\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateEvaluacionDesempenoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'desempeno_trabajo' => ['required', 'integer', 'min:0', 'max:20'],
            'orden_limpieza' => ['required', 'integer', 'min:0', 'max:20'],
            'compromiso' => ['required', 'integer', 'min:0', 'max:20'],
            'respuesta_emocional' => ['required', 'integer', 'min:0', 'max:20'],
            'seguridad_trabajo' => ['required', 'integer', 'min:0', 'max:20'],
            'observaciones' => ['nullable', 'string'],
            'tuvo_incidencia' => ['nullable', 'boolean'],
            'descripcion_incidencia' => ['nullable', 'string'],
        ];
    }
}
