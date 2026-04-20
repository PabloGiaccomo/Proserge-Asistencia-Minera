<?php

namespace App\Modules\ManPower\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateGrupoTrabajoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
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
            'estado' => ['nullable', 'string', 'max:20'],
        ];
    }
}
