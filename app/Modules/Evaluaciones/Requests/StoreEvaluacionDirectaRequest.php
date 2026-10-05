<?php

namespace App\Modules\Evaluaciones\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreEvaluacionDirectaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'trabajador_id' => ['required', 'uuid', 'exists:personal,id'],
            'mina_id' => ['required', 'uuid', 'exists:minas,id'],
            'fecha' => ['required', 'date_format:Y-m-d'],
            'submission_key' => ['required', 'uuid'],
            'desempeno_trabajo' => ['required', 'integer', 'between:1,4'],
            'orden_limpieza' => ['required', 'integer', 'between:1,4'],
            'seguridad_trabajo' => ['required', 'integer', 'between:1,4'],
            'compromiso' => ['required', 'integer', 'between:1,4'],
            'respuesta_emocional' => ['required', 'integer', 'between:1,4'],
            'tuvo_incidencia' => ['nullable', 'boolean'],
            'descripcion_incidencia' => ['nullable', 'required_if:tuvo_incidencia,1,true', 'string', 'max:2000'],
            'observaciones' => ['nullable', 'string', 'max:3000'],
        ];
    }
}
