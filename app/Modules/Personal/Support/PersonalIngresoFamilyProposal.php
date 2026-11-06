<?php

namespace App\Modules\Personal\Support;

use App\Models\PersonalFicha;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PersonalIngresoFamilyProposal
{
    private const FIELDS = [
        'nombres_apellidos', 'parentesco', 'fecha_nacimiento', 'tipo_documento', 'numero_documento',
        'telefono', 'vive_con_trabajador', 'estudia', 'contacto_emergencia',
    ];

    public static function validate(array $families): array
    {
        $validated = Validator::make(['familiares' => $families], [
            'familiares' => ['array', 'list', 'max:30'],
            'familiares.*' => ['array:key,'.implode(',', self::FIELDS)],
            'familiares.*.key' => ['required', 'uuid', 'distinct'],
            'familiares.*.nombres_apellidos' => ['required', 'string', 'max:191'],
            'familiares.*.parentesco' => ['required', 'string', 'max:80'],
            'familiares.*.fecha_nacimiento' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'familiares.*.tipo_documento' => ['nullable', 'string', Rule::in(array_keys(PersonalFichaCatalog::DOCUMENT_TYPES))],
            'familiares.*.numero_documento' => ['nullable', 'string', 'max:40'],
            'familiares.*.telefono' => ['nullable', 'string', 'max:30'],
            'familiares.*.vive_con_trabajador' => ['sometimes', 'boolean'],
            'familiares.*.estudia' => ['sometimes', 'boolean'],
            'familiares.*.contacto_emergencia' => ['sometimes', 'boolean'],
        ], [
            'familiares.*.nombres_apellidos.required' => 'Completa los nombres del familiar.',
            'familiares.*.parentesco.required' => 'Completa el parentesco del familiar.',
            'familiares.*.fecha_nacimiento.date_format' => 'Revisa la fecha de nacimiento del familiar.',
            'familiares.*.fecha_nacimiento.before_or_equal' => 'La fecha de nacimiento del familiar no puede ser futura.',
            'familiares.*.array' => 'El familiar incluye datos fuera de esta solicitud.',
        ])->validate()['familiares'];

        $documents = [];
        $withoutDocuments = [];
        $result = [];
        foreach ($validated as $index => $family) {
            $normalized = self::normalize($family);
            $document = $normalized['numero_documento'];
            if ($document !== '') {
                $identity = $normalized['tipo_documento'].'|'.$document;
                if (! PersonalNormalizer::isValidDocument($normalized['tipo_documento'], $document) || isset($documents[$identity])) {
                    throw ValidationException::withMessages(["familiares.$index.numero_documento" => 'Revisa el documento del familiar. Debe ser válido y aparecer una sola vez.']);
                }
                $documents[$identity] = true;
            } else {
                $identity = self::nameContext($normalized);
                if (isset($withoutDocuments[$identity])) {
                    throw ValidationException::withMessages(["familiares.$index.nombres_apellidos" => 'Este familiar aparece más de una vez en la propuesta.']);
                }
                $withoutDocuments[$identity] = true;
            }
            if ($normalized['telefono'] !== '' && ! preg_match('/^\+?[0-9][0-9\s().-]{5,29}$/D', $normalized['telefono'])) {
                throw ValidationException::withMessages(["familiares.$index.telefono" => 'Ingresa un número de teléfono válido.']);
            }
            $result[] = ['key' => $family['key'], ...$normalized];
        }

        return $result;
    }

    public static function current(?PersonalFicha $ficha, bool $lock = false): array
    {
        if (! $ficha) {
            return [];
        }
        if ($lock || ! $ficha->relationLoaded('familiares')) {
            $ficha->setRelation('familiares', $ficha->familiares()->orderBy('id')
                ->when($lock, fn ($query) => $query->lockForUpdate())->get());
        }

        return $ficha->familiares->sortBy('id')
            ->map(fn ($family): array => ['id' => $family->id, ...self::normalize($family->toArray())])->values()->all();
    }

    public static function compare(array $proposed, array $current): array
    {
        $changes = [];
        foreach ($proposed as $family) {
            $after = self::normalize($family);
            $matches = array_values(array_filter($current, fn (array $candidate): bool => $after['numero_documento'] !== ''
                && $after['numero_documento'] === $candidate['numero_documento']
                && $after['tipo_documento'] === $candidate['tipo_documento']));
            if ($after['numero_documento'] === '') {
                $matches = array_values(array_filter($current, fn (array $candidate): bool => self::nameContext($candidate) === self::nameContext($after)));
            }
            $same = $after['numero_documento'] !== '' && count($matches) === 1 && self::normalize($matches[0]) === $after;
            $changes[] = [
                'key' => $family['key'],
                'action' => $matches === [] ? 'add' : ($same ? 'existing' : 'conflict'),
                'id' => count($matches) === 1 ? $matches[0]['id'] : null,
                'before' => count($matches) === 1 ? self::normalize($matches[0]) : null,
                'after' => $after,
                'message' => $matches === [] ? 'Agregar familiar'
                    : ($same ? 'Ya registrado con los mismos datos' : 'Hay un familiar registrado que coincide. Revísalo en la ficha interna.'),
            ];
        }

        return $changes;
    }

    private static function normalize(array $family): array
    {
        $normalized = [];
        foreach (self::FIELDS as $key) {
            $value = $family[$key] ?? '';
            $normalized[$key] = in_array($key, ['vive_con_trabajador', 'estudia', 'contacto_emergencia'], true)
                ? filter_var($value, FILTER_VALIDATE_BOOLEAN)
                : PersonalNormalizer::text($value);
        }
        $normalized['numero_documento'] = PersonalNormalizer::documentNumber($normalized['numero_documento']);
        $normalized['tipo_documento'] = PersonalNormalizer::documentType($normalized['tipo_documento'] ?: 'DNI', $normalized['numero_documento']);
        $normalized['fecha_nacimiento'] = PersonalNormalizer::isoDate($normalized['fecha_nacimiento']) ?? '';

        return $normalized;
    }

    private static function nameContext(array $family): string
    {
        return json_encode([
            PersonalNormalizer::normalizeKey($family['nombres_apellidos']),
            PersonalNormalizer::normalizeKey($family['parentesco']),
            $family['fecha_nacimiento'],
        ], JSON_THROW_ON_ERROR);
    }
}
