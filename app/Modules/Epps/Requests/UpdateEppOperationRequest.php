<?php

namespace App\Modules\Epps\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateEppOperationRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Direct permission and current mine/worker scope are enforced by the service policy.
        return true;
    }

    public function rules(): array
    {
        return [
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.epp_id' => ['required', 'uuid', 'exists:epp_registro,id'],
            'lines.*.epp_real_id' => ['nullable', 'uuid', 'exists:epp_registro,id'],
            'lines.*.existencia_id' => ['nullable', 'uuid', 'exists:epp_existencias,id'],
            'lines.*.entrega_origen_id' => ['nullable', 'uuid', 'exists:epp_entregas,id'],
            'lines.*.detalle_origen_id' => ['nullable', 'uuid', 'exists:epp_operacion_detalles,id'],
            'lines.*.cantidad_solicitada' => ['required', 'integer', 'min:1', 'max:1000000'],
            'lines.*.aceptar_parcial' => ['sometimes', 'boolean'],
            'lines.*.aceptar_sustitucion' => ['sometimes', 'boolean'],
            'lines.*.rechazar' => ['sometimes', 'boolean'],
            'lines.*.motivo' => ['nullable', 'string', 'max:1000'],
            'lines.*.talla' => ['nullable', 'string', 'max:80'],
            'lines.*.color' => ['nullable', 'string', 'max:120'],
            'lines.*.serie' => ['nullable', 'string', 'max:200'],
            'lines.*.atributos' => ['nullable', 'array', 'max:20'],
            'lines.*.atributos.*' => ['nullable', 'string', 'max:200'],
            'lines.*.condicion_devolucion' => ['nullable', 'in:BUENO,DESGASTADO,DANADO,INCOMPLETO,OTRO'],
            'lines.*.destino_inventario' => ['nullable', 'in:DISPONIBLE,CUARENTENA,BAJA'],
        ];
    }
}
