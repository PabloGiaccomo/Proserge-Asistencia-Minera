<?php

namespace App\Modules\Faltas\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateFaltaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'motivo' => ['sometimes', 'string', 'max:40'],
            'descripcion' => ['nullable', 'string'],
            'observaciones' => ['nullable', 'string'],
        ];
    }
}
