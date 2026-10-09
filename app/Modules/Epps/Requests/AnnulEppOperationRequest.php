<?php

namespace App\Modules\Epps\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AnnulEppOperationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['submission_uuid' => ['required', 'uuid'], 'motivo' => ['required', 'string', 'max:1000']];
    }
}
