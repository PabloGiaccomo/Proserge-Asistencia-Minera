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
            'fecha' => ['required', 'date'],
            'turno' => ['required', 'string', 'in:DIA,NOCHE'],
            'supervisor_id' => ['required', 'string', 'size:36', 'exists:personal,id'],
            'servicio' => ['required', 'string', 'max:191'],
            'area' => ['required', 'string', 'max:191'],
            'paradero' => ['nullable', 'string', 'max:191'],
            'paradero_link' => ['nullable', 'string', 'max:500'],
            'horario_salida' => ['required', 'date_format:H:i'],
            'destino_tipo' => ['required', 'string', 'in:MINA,TALLER,OFICINA'],
            'destino_id' => ['required', 'string', 'size:36'],
            'observaciones' => ['nullable', 'string'],
            'personal_ids' => ['nullable', 'array'],
            'personal_ids.*' => ['string', 'size:36', 'exists:personal,id'],
        ];
    }
}
