<?php

namespace App\Modules\Transporte\Requests;

use App\Models\TransporteServicio;
use Illuminate\Foundation\Http\FormRequest;

class ChangeTransporteEstadoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'estado' => ['required', 'string', 'in:'.implode(',', TransporteServicio::estados())],
            'observacion' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
