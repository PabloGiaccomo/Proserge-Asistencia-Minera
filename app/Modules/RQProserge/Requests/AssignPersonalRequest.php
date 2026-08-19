<?php

namespace App\Modules\RQProserge\Requests;

use App\Models\RQProsergeDetalle;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'posicion_asignacion' => ['nullable', 'string', Rule::in([RQProsergeDetalle::POSICION_TITULAR, RQProsergeDetalle::POSICION_SUPLENTE])],
            'tipo_asignacion' => ['nullable', 'string', Rule::in([RQProsergeDetalle::TIPO_REGULAR, RQProsergeDetalle::TIPO_ADICIONAL])],
            'comentario' => ['nullable', 'string'],
            'ultimo_turno_referencia' => ['nullable', 'string', 'max:10'],
        ];
    }
}
