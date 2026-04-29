<?php

namespace App\Modules\Personal\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePersonalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'dni' => ['required', 'string', 'max:20', 'unique:personal,dni'],
            'tipo_documento' => ['nullable', 'string', 'max:40'],
            'numero_documento' => ['nullable', 'string', 'max:40'],
            'nombre_completo' => ['required', 'string', 'max:191'],
            'puesto' => ['required', 'string', 'max:120'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'telefono_1' => ['nullable', 'string', 'max:30'],
            'telefono_2' => ['nullable', 'string', 'max:30'],
            'correo' => ['nullable', 'email', 'max:191'],
            'contrato' => ['required', 'string', 'max:40'],
            'ocupacion' => ['nullable', 'string', 'max:120'],
            'es_supervisor' => ['nullable', 'boolean'],
            'fecha_ingreso' => ['nullable', 'date'],
            'estado' => ['nullable', 'string', 'max:40'],
            'minas' => ['nullable', 'array'],
            'minas.*.mina_id' => ['nullable', 'string', 'size:36', 'exists:minas,id'],
            'minas.*.mina_nombre' => ['nullable', 'string', 'max:191'],
            'minas.*.estado' => ['nullable', 'string', 'max:30'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'nombre_completo' => $this->input('nombre_completo', $this->input('nombre')),
            'contrato' => $this->input('contrato', $this->input('tipo_contrato')),
            'es_supervisor' => $this->input('es_supervisor', $this->input('supervisor')),
            'estado' => $this->input('estado', $this->input('activo')),
            'telefono_1' => $this->input('telefono_1', $this->input('telefono')),
            'tipo_documento' => $this->input('tipo_documento', 'DNI'),
            'numero_documento' => $this->input('numero_documento', $this->input('dni')),
        ]);
    }
}
