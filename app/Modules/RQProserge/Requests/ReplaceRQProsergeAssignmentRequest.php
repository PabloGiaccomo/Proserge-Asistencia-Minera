<?php

namespace App\Modules\RQProserge\Requests;

use App\Models\RQProsergeDetalle;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReplaceRQProsergeAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'personal_id' => ['required', 'string', 'size:36', 'exists:personal,id'],
            'fecha_inicio' => ['nullable', 'date'],
            'fecha_fin' => ['nullable', 'date', 'after_or_equal:fecha_inicio'],
            'posicion_asignacion' => ['nullable', 'string', Rule::in([RQProsergeDetalle::POSICION_TITULAR, RQProsergeDetalle::POSICION_SUPLENTE])],
            'tipo_asignacion' => ['nullable', 'string', Rule::in([RQProsergeDetalle::TIPO_REGULAR, RQProsergeDetalle::TIPO_ADICIONAL])],
            'comentario' => ['nullable', 'string', 'max:2000'],
            'ultimo_turno_referencia' => ['nullable', 'string', 'max:10'],
            'motivo' => ['required', 'string', 'max:2000'],
        ];
    }
}
