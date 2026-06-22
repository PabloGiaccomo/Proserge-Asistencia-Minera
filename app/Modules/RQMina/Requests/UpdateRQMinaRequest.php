<?php

namespace App\Modules\RQMina\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRQMinaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'mina_id' => ['nullable', 'string', 'size:36', 'exists:minas,id'],
            'destino_tipo' => ['nullable', 'string', 'in:MINA,TALLER,OFICINA'],
            'destino_id' => ['nullable', 'string', 'size:36'],
            'area' => ['required', 'string', 'max:191'],
            'fecha_inicio' => ['required', 'date'],
            'fecha_fin' => ['required', 'date', 'after_or_equal:fecha_inicio'],
            'observaciones' => ['nullable', 'string'],
            'supervisor_id' => ['nullable', 'string', 'size:36', Rule::exists('personal', 'id')->where('es_supervisor', 1)],
            'supervisor_pets_id' => ['nullable', 'string', 'size:36', Rule::exists('personal', 'id')->where('es_supervisor', 1)],
            'detalle' => ['required_without:plan_operativo', 'array', 'min:1'],
            'detalle.*.puesto' => ['required', 'string', 'max:191'],
            'detalle.*.cantidad' => ['required', 'integer', 'min:1'],
            'transporte' => ['nullable', 'array'],
            'transporte.*.transporte' => ['required', 'string', 'max:191'],
            'transporte.*.cantidad' => ['required', 'integer', 'min:1'],
            'plan_operativo' => ['nullable', 'array'],
            'plan_operativo.*.area_operativa' => ['nullable', 'string', 'max:80'],
            'plan_operativo.*.modulo' => ['nullable', 'string', 'max:80'],
            'plan_operativo.*.nombre' => ['nullable', 'string', 'max:191'],
            'plan_operativo.*.observaciones' => ['nullable', 'string'],
            'plan_operativo.*.actividades' => ['nullable', 'array'],
            'plan_operativo.*.actividades.*.sait' => ['nullable', 'string', 'max:191'],
            'plan_operativo.*.actividades.*.sector' => ['nullable', 'string', 'max:191'],
            'plan_operativo.*.actividades.*.area' => ['nullable', 'string', 'max:191'],
            'plan_operativo.*.actividades.*.ait_trabajo' => ['nullable', 'string'],
            'plan_operativo.*.actividades.*.detalle_trabajos_relevantes' => ['nullable', 'string'],
            'plan_operativo.*.actividades.*.supervisor_campo_dia' => ['nullable', 'string', 'max:191'],
            'plan_operativo.*.actividades.*.supervisor_campo_noche' => ['nullable', 'string', 'max:191'],
            'plan_operativo.*.actividades.*.supervisor_seguridad_dia' => ['nullable', 'string', 'max:191'],
            'plan_operativo.*.actividades.*.supervisor_seguridad_noche' => ['nullable', 'string', 'max:191'],
            'plan_operativo.*.actividades.*.turnos' => ['nullable', 'array'],
            'plan_operativo.*.actividades.*.turnos.*.fecha' => ['nullable', 'date'],
            'plan_operativo.*.actividades.*.turnos.*.dia_label' => ['nullable', 'string', 'max:40'],
            'plan_operativo.*.actividades.*.turnos.*.turno_a' => ['nullable', 'string', 'max:191'],
            'plan_operativo.*.actividades.*.turnos.*.real_turno_a' => ['nullable', 'string', 'max:191'],
            'plan_operativo.*.actividades.*.turnos.*.turno_b' => ['nullable', 'string', 'max:191'],
            'plan_operativo.*.actividades.*.turnos.*.real_turno_b' => ['nullable', 'string', 'max:191'],
            'plan_operativo.*.actividades.*.turnos.*.real' => ['nullable', 'string', 'max:191'],
            'plan_operativo.*.transportes' => ['nullable', 'array'],
            'plan_operativo.*.transportes.*.alcance' => ['nullable', 'string', 'max:191'],
            'plan_operativo.*.transportes.*.unidad_carga' => ['nullable', 'string', 'max:191'],
            'plan_operativo.*.transportes.*.unidades_transporte' => ['nullable', 'string'],
            'plan_operativo.*.transportes.*.indicaciones' => ['nullable', 'string'],
        ];
    }
}
