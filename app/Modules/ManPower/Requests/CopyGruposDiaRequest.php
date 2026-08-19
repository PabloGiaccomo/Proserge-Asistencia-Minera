<?php

namespace App\Modules\ManPower\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CopyGruposDiaRequest extends FormRequest
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
            'rq_mina_actividad_id' => ['nullable', 'uuid', 'exists:rq_mina_actividades,id'],
            'rq_mina_actividad_origen_id' => ['nullable', 'uuid', 'exists:rq_mina_actividades,id'],
            'rq_mina_actividad_destino_id' => ['nullable', 'uuid', 'exists:rq_mina_actividades,id'],
            'fecha_origen' => ['required', 'date'],
            'fecha_destino' => ['required', 'date', 'different:fecha_origen'],
            'copiar_integrantes' => ['nullable', 'boolean'],
            'sobrescribir_destino' => ['nullable', 'boolean'],
        ];
    }
}
