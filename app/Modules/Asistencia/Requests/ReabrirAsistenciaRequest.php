<?php

namespace App\Modules\Asistencia\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReabrirAsistenciaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [];
    }
}
