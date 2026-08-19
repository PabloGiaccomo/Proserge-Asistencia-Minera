<?php

namespace App\Modules\Transporte\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTransporteAlcanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'alcances' => ['required', 'array', 'min:1'],
            'alcances.*.rq_mina_actividad_grupo_id' => ['nullable', 'string', 'size:36', 'exists:rq_mina_actividad_grupos,id'],
            'alcances.*.rq_mina_actividad_id' => ['nullable', 'string', 'size:36', 'exists:rq_mina_actividades,id'],
            'alcances.*.grupo_trabajo_id' => ['nullable', 'string', 'size:36', 'exists:grupo_trabajo,id'],
            'alcances.*.sait_snapshot' => ['nullable', 'string', 'max:191'],
        ];
    }
}
