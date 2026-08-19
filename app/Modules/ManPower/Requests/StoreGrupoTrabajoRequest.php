<?php

namespace App\Modules\ManPower\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreGrupoTrabajoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'rq_mina_id' => ['required', 'string', 'size:36', 'exists:rq_mina,id'],
            'rq_proserge_id' => ['nullable', 'string', 'size:36', 'exists:rq_proserge,id'],
            'rq_mina_plan_id' => ['nullable', 'string', 'size:36', 'exists:rq_mina_planes,id'],
            'rq_mina_actividad_grupo_id' => ['nullable', 'string', 'size:36', 'exists:rq_mina_actividad_grupos,id'],
            'actividad_ids' => ['nullable', 'array'],
            'actividad_ids.*' => ['string', 'size:36', 'exists:rq_mina_actividades,id'],
            'fecha' => ['required', 'date'],
            'turno' => ['required', 'string', 'in:DIA,NOCHE'],
            'supervisor_id' => ['nullable', 'string', 'size:36', 'exists:personal,id'],
            'servicio' => ['required', 'string', 'max:191'],
            'area' => ['required', 'string', 'max:191'],
            'paradero' => ['nullable', 'string', 'max:191'],
            'paradero_link' => ['nullable', 'string', 'max:500'],
            'horario_salida' => ['required', 'date_format:H:i'],
            'destino_tipo' => ['required', 'string', 'in:MINA,TALLER,OFICINA'],
            'destino_id' => ['required', 'string', 'size:36'],
            'observaciones' => ['nullable', 'string'],
            'observacion_planificacion' => ['nullable', 'string'],
            'justificacion_brecha' => ['nullable', 'string'],
            'estado' => ['nullable', 'string', 'in:BORRADOR,PROGRAMADO'],
            'rq_proserge_detalle_ids' => ['nullable', 'array'],
            'rq_proserge_detalle_ids.*' => ['string', 'size:36', 'exists:rq_proserge_detalle,id'],
            'personal_ids' => ['nullable', 'array'],
            'personal_ids.*' => ['string', 'size:36', 'exists:personal,id'],
        ];
    }
}
