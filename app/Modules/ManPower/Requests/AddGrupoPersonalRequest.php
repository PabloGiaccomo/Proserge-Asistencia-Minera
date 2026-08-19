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
            'personal_id' => ['nullable', 'required_without:rq_proserge_detalle_id', 'string', 'size:36', 'exists:personal,id'],
            'rq_proserge_detalle_id' => ['nullable', 'required_without:personal_id', 'string', 'size:36', 'exists:rq_proserge_detalle,id'],
        ];
    }
}
