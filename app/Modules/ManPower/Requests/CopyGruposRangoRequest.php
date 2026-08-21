<?php

namespace App\Modules\ManPower\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CopyGruposRangoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'rq_mina_id' => ['required', 'uuid', 'exists:rq_mina,id'],
            'rq_mina_plan_id' => ['nullable', 'uuid', 'exists:rq_mina_planes,id'],
            'rq_mina_actividad_id' => ['required', 'uuid', 'exists:rq_mina_actividades,id'],
            'fecha_origen' => ['required', 'date'],
            'alcance' => ['required', Rule::in(['SEMANA', 'PARADA'])],
            'copiar_integrantes' => ['nullable', 'boolean'],
            'sobrescribir_destino' => ['nullable', 'boolean'],
        ];
    }
}
