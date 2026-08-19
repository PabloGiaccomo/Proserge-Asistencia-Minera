<?php

namespace App\Modules\RQProserge\Requests;

use App\Models\RQProsergeDetalle;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRQProsergeAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'posicion_asignacion' => ['required', 'string', Rule::in([RQProsergeDetalle::POSICION_TITULAR, RQProsergeDetalle::POSICION_SUPLENTE])],
            'tipo_asignacion' => ['required', 'string', Rule::in([RQProsergeDetalle::TIPO_REGULAR, RQProsergeDetalle::TIPO_ADICIONAL])],
            'fecha_inicio' => ['nullable', 'date'],
            'fecha_fin' => ['nullable', 'date', 'after_or_equal:fecha_inicio'],
            'comentario' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
