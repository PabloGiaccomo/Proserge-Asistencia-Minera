<?php

namespace App\Modules\RQMina\Requests;

use App\Models\RQMinaPlan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRQMinaPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:191'],
            'fecha_inicio' => ['required', 'date'],
            'fecha_fin' => ['required', 'date', 'after_or_equal:fecha_inicio'],
            'semana_referencia' => ['nullable', 'string', 'max:80'],
            'estado' => ['nullable', 'string', Rule::in([RQMinaPlan::ESTADO_BORRADOR, RQMinaPlan::ESTADO_VIGENTE])],
            'observaciones' => ['nullable', 'string'],
        ];
    }
}
