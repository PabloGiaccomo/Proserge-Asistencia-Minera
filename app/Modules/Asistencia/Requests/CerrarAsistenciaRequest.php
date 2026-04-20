<?php

namespace App\Modules\Asistencia\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CerrarAsistenciaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'actividad_realizada' => ['nullable', 'string'],
            'reporte_suceso' => ['nullable', 'string'],
        ];
    }
}
