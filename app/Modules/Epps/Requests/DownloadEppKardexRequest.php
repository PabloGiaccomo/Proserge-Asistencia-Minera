<?php

namespace App\Modules\Epps\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DownloadEppKardexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $previous = now('America/Lima')->startOfMonth()->subMonth();
        $this->mergeIfMissing(['fecha_desde' => $previous->toDateString(), 'fecha_hasta' => $previous->endOfMonth()->toDateString()]);
    }

    public function rules(): array
    {
        return ['personal_id' => ['required', 'uuid', 'exists:personal,id'], 'fecha_desde' => ['required', 'date_format:Y-m-d'], 'fecha_hasta' => ['required', 'date_format:Y-m-d', 'after_or_equal:fecha_desde']];
    }
}
