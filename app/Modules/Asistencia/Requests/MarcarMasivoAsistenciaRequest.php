<?php

namespace App\Modules\Asistencia\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MarcarMasivoAsistenciaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'asistencia_detalle_ids' => ['nullable', 'array', 'min:1'],
            'asistencia_detalle_ids.*' => ['string', 'size:36', 'exists:asistencia_detalle,id'],
            'grupo_trabajo_detalle_ids' => ['nullable', 'array', 'min:1'],
            'grupo_trabajo_detalle_ids.*' => ['string', 'size:36', 'exists:grupo_trabajo_detalle,id'],
            'personal_ids' => ['nullable', 'array', 'min:1'],
            'personal_ids.*' => ['string', 'size:36', 'exists:personal,id'],
            'estado' => ['required', 'string', 'in:PRESENTE,AUSENTE,TARDANZA,JUSTIFICADO,NO_CORRESPONDE'],
            'hora_marcado' => ['nullable', 'date_format:H:i'],
            'observacion' => ['nullable', 'string'],
            'observaciones' => ['nullable', 'string'],
            'motivo' => ['nullable', 'string'],
            'motivo_estado' => ['nullable', 'string'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if (!$this->filled('asistencia_detalle_ids') && !$this->filled('grupo_trabajo_detalle_ids') && !$this->filled('personal_ids')) {
                $validator->errors()->add('personal_ids', 'Selecciona al menos un detalle o trabajador.');
            }
        });
    }
}
