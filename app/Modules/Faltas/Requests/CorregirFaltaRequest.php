<?php

namespace App\Modules\Faltas\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CorregirFaltaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'motivo_correccion' => ['required', 'string', 'min:5'],
            'hora_marcado' => ['nullable', 'date_format:H:i'],
        ];
    }
}
