<?php

namespace App\Modules\RQProserge\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AssignPersonalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'rq_mina_detalle_id' => ['required', 'string', 'size:36', 'exists:rq_mina_detalle,id'],
            'personal_id' => ['required', 'string', 'size:36', 'exists:personal,id'],
            'puesto_asignado' => ['required', 'string', 'max:191'],
            'fecha_inicio' => ['required', 'date'],
            'fecha_fin' => ['required', 'date', 'after_or_equal:fecha_inicio'],
            'comentario' => ['nullable', 'string'],
            'ultimo_turno_referencia' => ['nullable', 'string', 'max:10'],
        ];
    }
}
