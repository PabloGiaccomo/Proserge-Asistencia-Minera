<?php

namespace App\Modules\Personal\Support;

class PersonalIngresoPurpose
{
    public const ALTA = 'ALTA';

    public const ACTUALIZACION = 'ACTUALIZACION';

    public static function editableKeys(string $purpose): array
    {
        if ($purpose !== self::ACTUALIZACION) {
            return [];
        }

        return collect(PersonalFichaCatalog::sections())
            ->whereIn('key', ['datos_personales', 'nacimiento', 'contacto', 'domicilio', 'academicos', 'tallas'])
            ->flatMap(fn (array $section): array => array_column($section['fields'], 'key'))
            ->all();
    }

    public static function requestedKeys(array $requested): array
    {
        $keys = $requested ?: [...self::editableKeys(self::ACTUALIZACION), 'familiares'];
        $dependencies = [
            'estado_civil' => ['estado_civil_otro'],
            'nacionalidad' => ['nacionalidad_otra'],
            'pais_nacimiento' => ['pais_nacimiento_otro', 'departamento_nacimiento', 'provincia_nacimiento', 'distrito_nacimiento', 'lugar_nacimiento_extranjero'],
            'domicilio_tipo' => ['domicilio_pais_otro', 'domicilio_extranjero', 'domicilio_departamento', 'domicilio_provincia', 'domicilio_distrito', 'domicilio_direccion'],
        ];
        foreach ($dependencies as $key => $related) {
            if (in_array($key, $keys, true)) {
                $keys = [...$keys, ...$related];
            }
        }

        return array_values(array_unique($keys));
    }

    public static function requiredKeys(array $fields, array $requested): array
    {
        $keys = array_keys(array_filter(PersonalFichaCatalog::fields(), fn (array $field): bool => $field['required']));
        if (($fields['estado_civil'] ?? '') === 'Otro') {
            $keys[] = 'estado_civil_otro';
        }
        if (($fields['nacionalidad'] ?? '') === 'Otra') {
            $keys[] = 'nacionalidad_otra';
        }
        if (($fields['pais_nacimiento'] ?? '') === 'Otro') {
            $keys[] = 'pais_nacimiento_otro';
        } else {
            $keys = [...$keys, 'departamento_nacimiento', 'provincia_nacimiento', 'distrito_nacimiento'];
        }
        if (($fields['domicilio_tipo'] ?? '') === 'Extranjero') {
            $keys = [...array_diff($keys, ['domicilio_direccion']), 'domicilio_pais_otro', 'domicilio_extranjero'];
        } else {
            $keys = [...$keys, 'domicilio_departamento', 'domicilio_provincia', 'domicilio_distrito'];
        }

        return array_values(array_intersect(array_unique($keys), $requested));
    }
}
