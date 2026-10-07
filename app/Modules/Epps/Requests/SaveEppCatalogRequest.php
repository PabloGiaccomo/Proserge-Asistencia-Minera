<?php

namespace App\Modules\Epps\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveEppCatalogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Direct permission is checked by the route and controller.
    }

    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:191'],
            'vida_util_dias' => ['required', 'integer', 'min:1', 'max:3650'],
            'requiere_talla' => ['nullable', 'boolean'], 'tallas' => ['nullable', 'string', 'max:1000'],
            'requiere_color' => ['nullable', 'boolean'], 'colores' => ['nullable', 'string', 'max:1000'],
            'otros_atributos' => ['nullable', 'array', 'max:20'],
            'otros_atributos.*.nombre' => ['nullable', 'string', 'max:100'],
            'otros_atributos.*.valores' => ['nullable', 'string', 'max:1000'],
            'categoria' => ['nullable', 'string', 'in:EPP,HERRAMIENTA,CONSUMIBLE'],
            'estado' => ['nullable', 'in:ACTIVO,INACTIVO'],
            'politica_devolucion' => ['required', 'in:RETORNABLE,CONSUMIBLE'],
            'politica_revisada' => ['accepted'],
            'control_inventario' => ['required', 'boolean'],
            'permite_entrega_repetida' => ['required', 'boolean'],
            'requiere_serie' => ['required', 'boolean'],
            'es_requerido_cobertura' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return ['politica_revisada.accepted' => 'Revisa y confirma la politica de devolucion antes de guardar.'];
    }
}
