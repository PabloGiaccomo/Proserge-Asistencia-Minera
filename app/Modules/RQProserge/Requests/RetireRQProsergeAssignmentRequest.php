<?php

namespace App\Modules\RQProserge\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RetireRQProsergeAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'motivo' => ['required', 'string', 'max:2000'],
        ];
    }
}
