<?php

namespace App\Modules\Asistencia\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MarcarAsistenciaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'personal_id' => ['required', 'string', 'size:36', 'exists:personal,id'],
            'estado' => ['required', 'string', 'in:PRESENTE,AUSENTE'],
            'hora_marcado' => ['nullable', 'date_format:H:i'],
            'observaciones' => ['nullable', 'string'],
        ];
    }
}
