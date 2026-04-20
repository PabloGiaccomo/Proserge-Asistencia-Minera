<?php

namespace App\Modules\ManPower\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AddGrupoPersonalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'personal_id' => ['required', 'string', 'size:36', 'exists:personal,id'],
        ];
    }
}
