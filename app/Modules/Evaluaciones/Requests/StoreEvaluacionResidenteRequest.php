<?php

namespace App\Modules\Evaluaciones\Requests;

use App\Modules\Evaluaciones\Support\ResidentEvaluationTemplate;
use Illuminate\Foundation\Http\FormRequest;

class StoreEvaluacionResidenteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'fecha' => ['required', 'date'],
            'indicadores_kpi_items' => ['required', 'array', 'min:1'],
            'indicadores_kpi_items.*' => ['required', 'string', 'in:'.implode(',', array_keys(ResidentEvaluationTemplate::KPI_OPTIONS))],
            'costos_servicio_items' => ['required', 'array', 'min:1'],
            'costos_servicio_items.*' => ['required', 'string', 'in:'.implode(',', array_keys(ResidentEvaluationTemplate::COST_OPTIONS))],
            'eventos_seguridad_respuesta' => ['required', 'string', 'in:SI,NO'],
            'reportes_calidad_respuesta' => ['required', 'string', 'in:SI,NO'],
            'liderazgo_gestion_innovacion' => ['required', 'integer', 'between:1,4'],
            'residente_id' => ['required', 'string', 'size:36', 'exists:personal,id'],
            'comentarios' => ['required', 'string', 'max:3000'],
        ];
    }
}
