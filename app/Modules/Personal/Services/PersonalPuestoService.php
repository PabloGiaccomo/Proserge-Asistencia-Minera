<?php

namespace App\Modules\Personal\Services;

use App\Models\Personal;
use App\Models\PersonalPuesto;
use App\Modules\Personal\Support\PersonalNormalizer;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PersonalPuestoService
{
    public function list(): Collection
    {
        return PersonalPuesto::query()
            ->withCount('trabajadores')
            ->orderBy('nombre')
            ->get();
    }

    public function create(array $payload): PersonalPuesto
    {
        $nombre = $this->normalizeName($payload['nombre'] ?? '');

        if ($nombre === '') {
            throw ValidationException::withMessages(['nombre' => 'El nombre del puesto es obligatorio.']);
        }

        return PersonalPuesto::query()->firstOrCreate(
            ['nombre' => $nombre],
            [
                'id' => (string) Str::uuid(),
                'funciones' => $this->normalizeFunctions($payload['funciones'] ?? null),
                'activo' => true,
            ]
        );
    }

    public function update(PersonalPuesto $puesto, array $payload): PersonalPuesto
    {
        $nombre = $this->normalizeName($payload['nombre'] ?? $puesto->nombre);

        if ($nombre === '') {
            throw ValidationException::withMessages(['nombre' => 'El nombre del puesto es obligatorio.']);
        }

        $duplicate = PersonalPuesto::query()
            ->where('nombre', $nombre)
            ->whereKeyNot($puesto->id)
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages(['nombre' => 'Ya existe un puesto con ese nombre.']);
        }

        return DB::transaction(function () use ($puesto, $payload, $nombre): PersonalPuesto {
            $puesto->forceFill([
                'nombre' => $nombre,
                'funciones' => $this->normalizeFunctions($payload['funciones'] ?? null),
                'activo' => filter_var($payload['activo'] ?? true, FILTER_VALIDATE_BOOLEAN),
            ])->save();

            if (Schema::hasColumn('personal', 'puesto_id')) {
                Personal::query()
                    ->where('puesto_id', $puesto->id)
                    ->update([
                        'puesto' => $puesto->nombre,
                        'updated_at' => now(),
                    ]);
            }

            return $puesto->refresh();
        });
    }

    public function delete(PersonalPuesto $puesto): void
    {
        if ($puesto->trabajadores()->exists()) {
            throw ValidationException::withMessages([
                'puesto' => 'No se puede eliminar este puesto porque tiene trabajadores asociados. Puedes desactivarlo o cambiar primero el puesto de esos trabajadores.',
            ]);
        }

        $puesto->delete();
    }

    public function resolveByName(?string $name): ?PersonalPuesto
    {
        if (!Schema::hasTable('personal_puestos')) {
            return null;
        }

        $nombre = $this->normalizeName($name);
        if ($nombre === '') {
            return null;
        }

        return PersonalPuesto::query()
            ->where('nombre', $nombre)
            ->where('activo', true)
            ->first();
    }

    private function normalizeName(mixed $value): string
    {
        return mb_substr(PersonalNormalizer::text($value), 0, 191);
    }

    private function normalizeFunctions(mixed $value): ?string
    {
        $text = PersonalNormalizer::text($value);

        return $text !== '' ? mb_substr($text, 0, 5000) : null;
    }
}
