<?php

namespace App\Modules\RQMina\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SendRQMinaRequest extends FormRequest
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
