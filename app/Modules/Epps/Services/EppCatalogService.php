<?php

namespace App\Modules\Epps\Services;

use App\Models\EppRegistro;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class EppCatalogService
{
    public function save(EppRegistro $epp, array $payload): EppRegistro
    {
        $nombre = $this->label((string) ($payload['nombre'] ?? $epp->nombre ?? ''));
        if ($nombre === '') {
            throw new InvalidArgumentException('Indica el nombre del EPP.');
        }
        $codigo = Str::of($nombre)->ascii()->slug('_')->upper()->limit(110, '')->toString();
        if ($codigo === '') {
            throw new InvalidArgumentException('El nombre debe permitir identificar el EPP.');
        }

        return DB::transaction(function () use ($epp, $payload, $nombre, $codigo): EppRegistro {
            $epp = $epp->exists
                ? EppRegistro::query()->lockForUpdate()->findOrFail($epp->id)
                : EppRegistro::query()->where('codigo', $codigo)->lockForUpdate()->first() ?? $epp;
            if (EppRegistro::query()->where('codigo', $codigo)->when($epp->exists, fn ($q) => $q->where('id', '!=', $epp->id))->exists()) {
                throw new InvalidArgumentException('Ya existe otro EPP con ese nombre.');
            }
            $policy = $this->label((string) ($payload['politica_devolucion'] ?? $epp->politica_devolucion ?? EppRegistro::POLITICA_RETORNABLE));
            if (! in_array($policy, [EppRegistro::POLITICA_RETORNABLE, EppRegistro::POLITICA_CONSUMIBLE], true)) {
                throw new InvalidArgumentException('Selecciona si el EPP es retornable o consumible.');
            }
            $requiresSize = (bool) ($payload['requiere_talla'] ?? false);
            $requiresColor = (bool) ($payload['requiere_color'] ?? false);
            $state = $this->label((string) ($payload['estado'] ?? EppRegistro::ESTADO_ACTIVO));
            $data = [
                'nombre' => $nombre, 'codigo' => $codigo, 'categoria' => 'EPP',
                'vida_util_dias' => max(1, (int) ($payload['vida_util_dias'] ?? 1)),
                'requiere_talla' => $requiresSize, 'tallas' => $requiresSize ? $this->options($payload['tallas'] ?? '') : null,
                'requiere_color' => $requiresColor, 'colores' => $requiresColor ? $this->options($payload['colores'] ?? '') : null,
                'otros_atributos' => $this->otherAttributes($payload['otros_atributos'] ?? []),
                'estado' => in_array($state, ['ACTIVO', 'INACTIVO'], true) ? $state : 'ACTIVO',
                'politica_devolucion' => $policy,
            ];
            foreach (['control_inventario' => true, 'permite_entrega_repetida' => false, 'requiere_serie' => false, 'es_requerido_cobertura' => true] as $field => $default) {
                $data[$field] = (bool) ($payload[$field] ?? $epp->{$field} ?? $default);
            }
            if (! $epp->exists) {
                $data['id'] = $epp->id ?: (string) Str::uuid();
                $data['stock'] = 0;
            }
            $epp->fill($data)->save();

            return $epp->refresh();
        });
    }

    private function label(string $value): string
    {
        return preg_replace('/\s+/', ' ', mb_strtoupper(trim($value), 'UTF-8')) ?: '';
    }

    private function options(array|string $value): array
    {
        $values = is_array($value) ? $value : (preg_split('/[\r\n,;]+/', $value) ?: []);

        return array_values(array_unique(array_filter(array_map(fn ($v) => $this->label((string) $v), $values))));
    }

    private function otherAttributes(array $attributes): ?array
    {
        $result = [];
        foreach ($attributes as $attribute) {
            $name = $this->label((string) ($attribute['nombre'] ?? ''));
            $values = $this->options($attribute['valores'] ?? '');
            if ($name !== '' && $values !== []) {
                $result[$name] = array_values(array_unique(array_merge($result[$name] ?? [], $values)));
            }
        }

        return $result === [] ? null : array_map(fn ($name, $values) => ['nombre' => $name, 'valores' => $values], array_keys($result), array_values($result));
    }
}
