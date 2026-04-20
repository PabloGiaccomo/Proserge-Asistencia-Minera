<?php

namespace App\Modules\Faltas\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AnularFaltaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'motivo_anulacion' => ['required', 'string', 'min:5'],
        ];
    }
}
