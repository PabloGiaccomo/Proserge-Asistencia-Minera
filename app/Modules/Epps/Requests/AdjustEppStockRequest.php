<?php

namespace App\Modules\Epps\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdjustEppStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'existencia_id' => ['required', 'uuid', 'exists:epp_existencias,id'],
            'delta' => ['required', 'integer', 'between:-1000000,1000000', Rule::notIn([0])],
            'motivo' => ['required', 'string', 'max:1000'],
        ];
    }
}
