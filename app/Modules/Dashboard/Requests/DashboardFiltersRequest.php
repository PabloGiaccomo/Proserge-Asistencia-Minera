<?php

namespace App\Modules\Dashboard\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DashboardFiltersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'fecha_desde' => ['nullable', 'date'],
            'fecha_hasta' => ['nullable', 'date', 'after_or_equal:fecha_desde'],
            'destino_tipo' => ['nullable', 'string', 'in:MINA,TALLER,OFICINA'],
            'destino_id' => ['nullable', 'string', 'size:36'],
            'mina_id' => ['nullable', 'string', 'size:36'],
            'supervisor_id' => ['nullable', 'string', 'size:36'],
            'trabajador_id' => ['nullable', 'string', 'size:36'],
            'estado' => ['nullable', 'string', 'max:30'],
        ];
    }
}
