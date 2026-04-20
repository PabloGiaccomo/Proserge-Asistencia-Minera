<?php

namespace App\Modules\Evaluaciones\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreEvaluacionDesempenoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'grupo_trabajo_id' => ['required', 'string', 'size:36', 'exists:grupo_trabajo,id'],
            'trabajador_id' => ['required', 'string', 'size:36', 'exists:personal,id'],
            'semana_parada' => ['nullable', 'integer', 'min:1', 'max:60'],
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
