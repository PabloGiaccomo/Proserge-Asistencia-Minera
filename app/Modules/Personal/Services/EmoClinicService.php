<?php

namespace App\Modules\Personal\Services;

use App\Models\ClinicaEmo;
use App\Models\Usuario;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EmoClinicService
{
    public function create(array $data, Usuario $actor): ClinicaEmo
    {
        return $this->transaction(fn () => ClinicaEmo::create([
            ...$this->nameAttributes($data), 'activo' => true,
            'created_by_usuario_id' => $actor->id, 'updated_by_usuario_id' => $actor->id,
        ]));
    }

    public function update(ClinicaEmo $clinic, array $data, Usuario $actor): ClinicaEmo
    {
        return $this->transaction(function () use ($clinic, $data, $actor): ClinicaEmo {
            $locked = ClinicaEmo::whereKey($clinic->id)->lockForUpdate()->firstOrFail();
            $locked->fill([...$this->nameAttributes($data), 'updated_by_usuario_id' => $actor->id])->save();

            return $locked;
        });
    }

    public function deactivate(ClinicaEmo $clinic, Usuario $actor): ClinicaEmo
    {
        return $this->transaction(function () use ($clinic, $actor): ClinicaEmo {
            $locked = ClinicaEmo::whereKey($clinic->id)->lockForUpdate()->firstOrFail();
            $locked->fill(['activo' => false, 'updated_by_usuario_id' => $actor->id])->save();

            return $locked;
        });
    }

    private function nameAttributes(array $data): array
    {
        $name = preg_replace('/\s+/u', ' ', trim((string) ($data['nombre'] ?? '')));
        if ($name === '' || mb_strlen($name) > 191) {
            throw ValidationException::withMessages(['nombre' => 'Indique un nombre de clinica de hasta 191 caracteres.']);
        }

        return ['nombre' => $name, 'nombre_normalizado' => mb_strtoupper(Str::ascii($name))];
    }

    private function transaction(callable $operation): ClinicaEmo
    {
        try {
            return DB::transaction($operation);
        } catch (QueryException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) === 1062) {
                throw ValidationException::withMessages(['nombre' => 'Ya existe una clinica con ese nombre. Revise tambien las clinicas inactivas.']);
            }
            throw $exception;
        }
    }
}
