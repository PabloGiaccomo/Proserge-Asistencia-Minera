<?php

namespace App\Modules\Transporte\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RetireTransportePasajeroRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'motivo' => ['required', 'string', 'max:1000'],
        ];
    }
}
