<?php

namespace App\Modules\Personal\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ImportPersonalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:xlsx,xls'],
        ];
    }
}
