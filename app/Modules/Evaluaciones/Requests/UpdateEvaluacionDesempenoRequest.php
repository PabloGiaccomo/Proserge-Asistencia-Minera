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
            'desempeno_trabajo' => ['required', 'integer', 'min:1', 'max:4'],
            'orden_limpieza' => ['required', 'integer', 'min:1', 'max:4'],
            'compromiso' => ['required', 'integer', 'min:1', 'max:4'],
            'respuesta_emocional' => ['required', 'integer', 'min:1', 'max:4'],
            'seguridad_trabajo' => ['required', 'integer', 'min:1', 'max:4'],
            'observaciones' => ['nullable', 'string'],
            'tuvo_incidencia' => ['nullable', 'boolean'],
            'descripcion_incidencia' => ['nullable', 'required_if:tuvo_incidencia,1,true', 'string', 'max:2000'],
        ];
    }
}
