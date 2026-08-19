<?php

namespace App\Modules\Transporte\Requests;

use App\Models\TransporteServicio;
use Illuminate\Foundation\Http\FormRequest;

class StoreTransporteServicioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'rq_mina_id' => ['required', 'string', 'size:36', 'exists:rq_mina,id'],
            'rq_mina_plan_id' => ['nullable', 'string', 'size:36', 'exists:rq_mina_planes,id'],
            'tipo' => ['required', 'string', 'in:'.implode(',', TransporteServicio::tipos())],
            'fecha' => ['required', 'date'],
            'turno' => ['required', 'string', 'in:A,B,DIA,NOCHE'],
            'tramo' => ['nullable', 'string', 'in:'.implode(',', TransporteServicio::tramos())],
            'transportista' => ['nullable', 'string', 'max:191'],
            'tipo_vehiculo' => ['nullable', 'string', 'max:120'],
            'placa' => ['nullable', 'string', 'max:50'],
            'conductor_personal_id' => ['nullable', 'string', 'size:36', 'exists:personal,id'],
            'capacidad' => ['nullable', 'integer', 'min:0'],
            'hora_salida' => ['nullable', 'date_format:H:i'],
            'hora_retorno' => ['nullable', 'date_format:H:i'],
            'origen' => ['nullable', 'string', 'max:191'],
            'destino' => ['nullable', 'string', 'max:191'],
            'estado' => ['nullable', 'string', 'in:'.implode(',', TransporteServicio::estados())],
            'observaciones' => ['nullable', 'string', 'max:2000'],
            'alcances' => ['nullable', 'array'],
            'alcances.*.rq_mina_actividad_grupo_id' => ['nullable', 'string', 'size:36', 'exists:rq_mina_actividad_grupos,id'],
            'alcances.*.rq_mina_actividad_id' => ['nullable', 'string', 'size:36', 'exists:rq_mina_actividades,id'],
            'alcances.*.grupo_trabajo_id' => ['nullable', 'string', 'size:36', 'exists:grupo_trabajo,id'],
            'alcances.*.sait_snapshot' => ['nullable', 'string', 'max:191'],
        ];
    }
}
