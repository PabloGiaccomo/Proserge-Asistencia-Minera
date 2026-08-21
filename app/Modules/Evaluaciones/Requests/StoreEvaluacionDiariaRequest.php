<?php

namespace App\Modules\Evaluaciones\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreEvaluacionDiariaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'asistencia_detalle_id' => ['required', 'string', 'size:36', 'exists:asistencia_detalle,id'],
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
