<?php

namespace App\Modules\Evaluaciones\Requests;

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
            'destino_tipo' => ['required', 'string', 'in:MINA,TALLER,OFICINA'],
            'destino_id' => ['required', 'string', 'size:36'],
            'indicadores_kpi' => ['required', 'numeric', 'min:0', 'max:100'],
            'costos_servicio' => ['required', 'numeric', 'min:0', 'max:100'],
            'eventos_seguridad' => ['required', 'numeric', 'min:0', 'max:100'],
            'reportes_calidad' => ['required', 'numeric', 'min:0', 'max:100'],
            'liderazgo_gestion' => ['required', 'numeric', 'min:0', 'max:100'],
            'innovacion' => ['required', 'numeric', 'min:0', 'max:100'],
            'residente_id' => ['required', 'string', 'size:36', 'exists:personal,id'],
            'evaluador_id' => ['required', 'string', 'size:36', 'exists:personal,id'],
            'comentarios' => ['nullable', 'string'],
        ];
    }
}
