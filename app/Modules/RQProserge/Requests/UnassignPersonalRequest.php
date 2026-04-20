<?php

namespace App\Modules\RQProserge\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UnassignPersonalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'rq_proserge_detalle_id' => ['required', 'string', 'size:36', 'exists:rq_proserge_detalle,id'],
        ];
    }
}
