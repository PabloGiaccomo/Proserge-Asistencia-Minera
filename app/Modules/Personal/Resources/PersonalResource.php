<?php

namespace App\Modules\Personal\Resources;

use App\Modules\Personal\Support\PersonalNormalizer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PersonalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $mineStates = [];
        $mineNames = [];
        $telefono1 = $this->telefono_1 ?? null;
        $telefono2 = $this->telefono_2 ?? null;

        if (!$telefono1 && !empty($this->telefono)) {
            $phoneData = PersonalNormalizer::normalizePhonePayload($this->telefono);
            $telefono1 = $phoneData['telefono_1'];
            $telefono2 = $phoneData['telefono_2'];
        }

        foreach ($this->whenLoaded('minas', fn () => $this->minas, collect()) as $mina) {
            $mineNames[] = $mina->nombre;
            $mineStates[$mina->nombre] = PersonalNormalizer::mineStatusLabel($mina->pivot?->estado);
        }

        return [
            'id' => $this->id,
            'dni' => $this->dni,
            'nombre' => $this->nombre_completo,
            'nombre_completo' => $this->nombre_completo,
            'puesto' => $this->puesto,
            'ocupacion' => $this->ocupacion,
            'contrato' => $this->contrato,
            'tipo_contrato' => PersonalNormalizer::contractLabel($this->contrato),
            'supervisor' => (bool) $this->es_supervisor,
            'es_supervisor' => (bool) $this->es_supervisor,
            'fecha_ingreso' => optional($this->fecha_ingreso)->toDateString(),
            'telefono' => PersonalNormalizer::combinePhones($telefono1, $telefono2),
            'telefono_1' => $telefono1,
            'telefono_2' => $telefono2,
            'correo' => $this->correo,
            'estado' => strtoupper((string) $this->estado),
            'activo' => strtoupper((string) $this->estado) === 'ACTIVO',
            'estado_actual' => strtoupper((string) $this->estado) === 'ACTIVO' ? 'trabajando' : 'inactivo',
            'minas' => array_values($mineNames),
            'minas_estado' => $mineStates,
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
