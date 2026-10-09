<?php

namespace App\Modules\Epps\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CorrectEppOperationRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_array($this->input('lines'))) {
            $this->merge(['lines' => array_values(array_filter($this->input('lines'), fn ($line) => ! is_array($line) || ! in_array($line['cantidad'] ?? null, [0, '0'], true)))]);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'submission_uuid' => ['required', 'uuid'],
            'motivo' => ['required', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.detalle_origen_id' => ['required', 'uuid', 'exists:epp_operacion_detalles,id'],
            'lines.*.cantidad' => ['required', 'integer', 'min:1', 'max:1000000'],
        ];
    }
}
