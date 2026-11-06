<?php

namespace App\Modules\Personal\Support;

use App\Models\Usuario;
use App\Support\Rbac\PermissionMatrix;

class PersonalFichaAccess
{
    public static function permissionsFor(Usuario $actor, bool $currentRead = false): array
    {
        $actor = Usuario::query()->with([
            'rol' => fn ($query) => $query->when($currentRead, fn ($query) => $query->sharedLock()),
            'rolesAdicionales' => fn ($query) => $query->when($currentRead, fn ($query) => $query->sharedLock()),
        ])->when($currentRead, fn ($query) => $query->sharedLock())->find($actor->id);

        if (! $actor || strtoupper((string) $actor->estado) !== 'ACTIVO') {
            return [];
        }

        return PermissionMatrix::normalizeForRoles(array_filter([$actor->rol, ...$actor->rolesAdicionales]));
    }

    public static function canSensitive(Usuario $actor, bool $currentRead = false): bool
    {
        $permissions = self::permissionsFor($actor, $currentRead);

        return PermissionMatrix::allowsDirect($permissions, 'personal', 'ver_datos_sensibles')
            || PermissionMatrix::allowsDirect($permissions, 'personal_ingresos', 'ver_datos_sensibles');
    }

    public static function isSensitiveSection(array $section): bool
    {
        return in_array($section['key'] ?? '', ['bancarios', 'pensionario', 'quinta_categoria'], true);
    }

    public static function redact(array $data): array
    {
        return array_diff_key($data, self::sensitiveKeys(), array_flip(['firma_base64', 'huella', 'huella_path', 'costo_hora']));
    }

    public static function assertSensitiveChangesAllowed(
        Usuario $actor,
        array $current,
        array $incoming,
        bool $signatureChanged,
        bool $fingerprintChanged,
    ): void {
        if (self::canSensitive($actor)) {
            return;
        }

        abort_if($signatureChanged || $fingerprintChanged, 403, 'No tienes permiso para modificar los datos sensibles de la ficha.');

        foreach (array_intersect_key($incoming, self::sensitiveKeys()) as $key => $value) {
            abort_if(
                (! is_scalar($value) && $value !== null) || trim((string) $value) !== trim((string) ($current[$key] ?? '')),
                403,
                'No tienes permiso para modificar los datos sensibles de la ficha.',
            );
        }
    }

    public static function preserveOmitted(array $normalized, array $current, array $incoming): array
    {
        $unchanged = array_filter(
            self::onlySensitive($current),
            fn ($value, string $key): bool => ! array_key_exists($key, $incoming)
                || trim((string) $incoming[$key]) === trim((string) $value),
            ARRAY_FILTER_USE_BOTH,
        );

        return array_replace($normalized, $unchanged);
    }

    public static function onlySensitive(array $data): array
    {
        return array_intersect_key($data, self::sensitiveKeys());
    }

    private static function sensitiveKeys(): array
    {
        return collect(PersonalFichaCatalog::sections())
            ->filter(self::isSensitiveSection(...))
            ->flatMap(fn (array $section) => array_column($section['fields'], 'key'))
            ->flip()
            ->all();
    }
}
