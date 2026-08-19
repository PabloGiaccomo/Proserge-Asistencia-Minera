<?php

namespace App\Modules\Transporte\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTransportePasajeroRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'grupo_trabajo_detalle_id' => ['nullable', 'string', 'size:36', 'exists:grupo_trabajo_detalle,id'],
            'grupo_trabajo_detalle_ids' => ['nullable', 'array'],
            'grupo_trabajo_detalle_ids.*' => ['string', 'size:36', 'exists:grupo_trabajo_detalle,id'],
            'grupo_trabajo_id' => ['nullable', 'string', 'size:36', 'exists:grupo_trabajo,id'],
        ];
    }
}
