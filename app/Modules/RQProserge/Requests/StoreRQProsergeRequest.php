<?php

namespace App\Modules\RQProserge\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreRQProsergeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'rq_mina_id' => ['required', 'string', 'size:36', 'exists:rq_mina,id'],
            'mina_id' => ['required', 'string', 'size:36', 'exists:minas,id'],
            'responsable_rrhh_id' => ['required', 'string', 'size:36', 'exists:usuarios,id'],
            'comentario_planner' => ['nullable', 'string'],
            'comentario_rrhh' => ['nullable', 'string'],
        ];
    }
}
