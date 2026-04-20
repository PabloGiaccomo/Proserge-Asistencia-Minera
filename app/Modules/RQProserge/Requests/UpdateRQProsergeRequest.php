<?php

namespace App\Modules\RQProserge\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRQProsergeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'estado' => ['nullable', 'string', 'max:30'],
            'comentario_planner' => ['nullable', 'string'],
            'comentario_rrhh' => ['nullable', 'string'],
        ];
    }
}
