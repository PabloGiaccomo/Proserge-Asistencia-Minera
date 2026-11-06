<?php

namespace App\Modules\Personal\Services;

use App\Models\Mina;
use App\Models\Oficina;
use App\Models\Personal;
use App\Models\PersonalMina;
use App\Models\Taller;
use App\Models\Usuario;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PersonalAdministrativeLocationService
{
    public function __construct(private readonly PersonalMinaHabilitacionService $habilitacion) {}

    public function catalogs(): array
    {
        return [
            'catalogOficinas' => Oficina::query()->orderBy('nombre')->pluck('nombre')->all(),
            'catalogTalleres' => Taller::query()->orderBy('nombre')->pluck('nombre')->all(),
        ];
    }

    public function sync(Personal $personal, array $names, Usuario $actor): void
    {
        $catalogs = $this->catalogs();
        $catalogNames = array_unique([...$catalogs['catalogOficinas'], ...$catalogs['catalogTalleres']]);
        $names = array_unique($names);

        if (array_diff($names, $catalogNames) !== []) {
            throw ValidationException::withMessages([
                'ubicaciones_internas' => 'Selecciona solo oficinas o talleres del catálogo.',
            ]);
        }

        // Serialize creation of the compatibility row for each selected catalog location.
        Oficina::query()->whereIn('nombre', $names)->orderBy('id')->lockForUpdate()->get(['id']);
        Taller::query()->whereIn('nombre', $names)->orderBy('id')->lockForUpdate()->get(['id']);

        $locationIds = collect($names)->map(fn (string $name): string => Mina::query()->firstOrCreate(
            ['nombre' => $name],
            ['id' => (string) Str::uuid(), 'unidad_minera' => $name, 'ubicacion' => 'Por definir', 'estado' => 'ACTIVO'],
        )->id);

        $current = $personal->relacionesMina()
            ->whereHas('mina', fn ($query) => $query->whereIn('nombre', $catalogNames))
            ->whereNotIn('mina_id', Mina::query()->operationalCatalog()->select('minas.id'))
            ->lockForUpdate()->get()->keyBy('mina_id');

        foreach ($current as $location) {
            if ($location->activo !== false && ! $locationIds->contains($location->mina_id)) {
                $this->habilitacion->deactivateAssignment($location, $actor, 'Ubicación interna retirada desde Personal.');
            }
        }

        foreach ($locationIds as $locationId) {
            $location = $current->get($locationId);
            if ($location && $location->activo !== false) {
                continue;
            }

            $previous = $location?->estadoHabilitacionActual();
            $location ??= new PersonalMina([
                'id' => (string) Str::uuid(),
                'personal_id' => $personal->id,
                'mina_id' => $locationId,
                'estado' => PersonalMina::ESTADO_HABILITADO,
            ]);
            $location->forceFill(['activo' => true, 'usuario_actualizacion_id' => $actor->id])->save();
            $location->historial()->create([
                'id' => (string) Str::uuid(),
                'estado_anterior' => $previous,
                'estado_nuevo' => $location->estadoHabilitacionActual(),
                'observacion' => 'Ubicación interna asignada desde Personal.',
                'usuario_id' => $actor->id,
                'fecha_cambio' => now(),
            ]);
        }
    }
}
