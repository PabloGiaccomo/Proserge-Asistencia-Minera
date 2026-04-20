<?php

namespace App\Modules\Evaluaciones\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreEvaluacionSupervisorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'evaluador_id' => ['required', 'string', 'size:36', 'exists:personal,id'],
            'evaluado_id' => ['required', 'string', 'size:36', 'exists:personal,id'],
            'fecha' => ['required', 'date'],
            'mina_id' => ['nullable', 'string', 'size:36', 'exists:minas,id'],
            'grupo_trabajo_id' => ['nullable', 'string', 'size:36', 'exists:grupo_trabajo,id'],
            'asistencia_encabezado_id' => ['nullable', 'string', 'size:36', 'exists:asistencia_encabezado,id'],
            'destino_tipo' => ['required', 'string', 'in:MINA,TALLER,OFICINA'],
            'destino_id' => ['required', 'string', 'size:36'],
            'respuestas' => ['required', 'array'],
            'respuestas.*' => ['nullable', 'integer', 'min:1', 'max:5'],
            'comentarios_finales' => ['nullable', 'string'],
            'aspectos_positivos' => ['nullable', 'string'],
            'capacitaciones_recomendadas' => ['nullable', 'string'],
            'firma' => ['nullable', 'string'],
        ];
    }
}
