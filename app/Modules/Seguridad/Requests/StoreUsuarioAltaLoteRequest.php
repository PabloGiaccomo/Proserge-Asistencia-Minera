<?php

namespace App\Modules\Seguridad\Requests;

use App\Modules\Seguridad\Services\UsuarioAltaLoteService;
use Illuminate\Foundation\Http\FormRequest;

class StoreUsuarioAltaLoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return UsuarioAltaLoteService::canCreate($this->user());
    }

    protected function prepareForValidation(): void
    {
        $rows = $this->input('filas');
        if (! is_array($rows) || count($rows) > UsuarioAltaLoteService::MAX_ROWS) {
            return;
        }

        foreach ($rows as &$row) {
            if (! is_array($row)) {
                continue;
            }
            if (is_string($row['email'] ?? null)) {
                $row['email'] = mb_strtolower(trim($row['email']));
            }
            if (is_string($row['personal_id'] ?? null)) {
                $row['personal_id'] = strtolower($row['personal_id']);
            }
        }

        $this->merge(['filas' => $rows]);
    }

    public function rules(): array
    {
        return [
            'lote_id' => ['required', 'uuid'],
            'rol_id' => ['required', 'uuid'],
            'estado' => ['nullable', 'in:ACTIVO,INACTIVO'],
            'filas' => ['required', 'array', 'list', 'min:1', 'max:'.UsuarioAltaLoteService::MAX_ROWS],
            'filas.*' => ['required', 'array:personal_id,email'],
            'filas.*.personal_id' => ['required', 'uuid', 'distinct'],
            'filas.*.email' => ['required', 'email', 'max:191', 'distinct'],
        ];
    }

    public function messages(): array
    {
        return [
            'rol_id.required' => 'Elige el rol para los usuarios del lote.',
            'filas.required' => 'Agrega al menos un trabajador.',
            'filas.max' => 'Puedes crear hasta '.UsuarioAltaLoteService::MAX_ROWS.' usuarios por lote.',
            'filas.*.personal_id.distinct' => 'El trabajador está repetido en el lote.',
            'filas.*.email.required' => 'Ingresa el correo que usará para iniciar sesión.',
            'filas.*.email.email' => 'Ingresa un correo válido.',
            'filas.*.email.distinct' => 'Este correo está repetido en el lote.',
        ];
    }
}
