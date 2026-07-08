<?php

namespace App\Support\Rbac;

use App\Models\Usuario;

class PermissionMatrix
{
    public static function normalize(mixed $rawPermissions): array
    {
        $matrix = PermissionCatalog::emptyMatrix();

        if (!is_array($rawPermissions)) {
            return $matrix;
        }

        if (isset($rawPermissions['matrix']) && is_array($rawPermissions['matrix'])) {
            $rawPermissions = $rawPermissions['matrix'];
        }

        if (self::isLegacyFlatList($rawPermissions)) {
            return self::normalizeLegacyList($rawPermissions, $matrix);
        }

        foreach ($rawPermissions as $module => $actions) {
            if (!isset($matrix[$module]) || !is_array($actions)) {
                continue;
            }

            foreach ($actions as $action => $allowed) {
                if (isset($matrix[$module][$action])) {
                    $matrix[$module][$action] = filter_var($allowed, FILTER_VALIDATE_BOOL);
                }
            }

            if (array_key_exists('dashboards', $matrix[$module])
                && !array_key_exists('dashboards', $actions)
                && (($matrix[$module]['ver'] ?? false) === true)
            ) {
                $matrix[$module]['dashboards'] = true;
            }

            if (self::hasOperationalPermission($matrix[$module])) {
                $matrix[$module]['ver'] = true;

                if (array_key_exists('dashboards', $matrix[$module])) {
                    $matrix[$module]['dashboards'] = true;
                }
            }
        }

        return $matrix;
    }

    public static function normalizeForRole(?string $roleName, mixed $rawPermissions): array
    {
        $matrix = self::normalize($rawPermissions);

        if (self::isPrivilegedRole($roleName) && self::isEmptyMatrix($matrix)) {
            return PermissionCatalog::fullAccessMatrix();
        }

        return $matrix;
    }

    public static function normalizeForRoles(array $roles): array
    {
        $matrices = [];
        $hasPrivileged = false;

        foreach ($roles as $role) {
            $roleName = is_array($role) ? ($role['nombre'] ?? null) : ($role->nombre ?? null);
            $permissions = is_array($role) ? ($role['permisos'] ?? []) : ($role->permisos ?? []);

            $normalized = self::normalizeForRole(is_string($roleName) ? $roleName : null, $permissions);
            $matrices[] = $normalized;

            if (self::isPrivilegedRole(is_string($roleName) ? $roleName : null)) {
                $hasPrivileged = true;
            }
        }

        if ($hasPrivileged) {
            return PermissionCatalog::fullAccessMatrix();
        }

        return self::mergeMatrices($matrices);
    }

    public static function effectivePermissions(?Usuario $usuario): array
    {
        if (!$usuario) {
            return PermissionCatalog::emptyMatrix();
        }

        $roles = [];
        if ($usuario->rol) {
            $roles[] = $usuario->rol;
        }

        if ($usuario->relationLoaded('rolesAdicionales')) {
            foreach ($usuario->rolesAdicionales as $rol) {
                $roles[] = $rol;
            }
        }

        if (empty($roles)) {
            return self::normalize(session('user.permissions', []));
        }

        return self::normalizeForRoles($roles);
    }

    public static function allows(mixed $rawPermissions, string $module, string $action = 'ver'): bool
    {
        $matrix = self::normalize($rawPermissions);

        if (self::matrixAllows($matrix, $module, $action)) {
            return true;
        }

        foreach (self::fallbackPermissionChecks($module, $action) as [$fallbackModule, $fallbackAction]) {
            if (self::matrixAllows($matrix, $fallbackModule, $fallbackAction)) {
                return true;
            }
        }

        return false;
    }

    public static function allowsAny(mixed $rawPermissions, string $module, array $actions): bool
    {
        foreach ($actions as $action) {
            if (self::allows($rawPermissions, $module, $action)) {
                return true;
            }
        }

        return false;
    }

    public static function userCan(?Usuario $usuario, string $module, string $action = 'ver'): bool
    {
        if (!$usuario) {
            return false;
        }

        return self::allows(self::effectivePermissions($usuario), $module, $action);
    }

    public static function userCanAny(?Usuario $usuario, string $module, array $actions): bool
    {
        if (!$usuario) {
            return false;
        }

        return self::allowsAny(self::effectivePermissions($usuario), $module, $actions);
    }

    private static function matrixAllows(array $matrix, string $module, string $action): bool
    {
        if (!isset($matrix[$module][$action])) {
            return false;
        }

        return $matrix[$module][$action] === true
            || ($action !== 'administrar' && ($matrix[$module]['administrar'] ?? false) === true);
    }

    private static function fallbackPermissionChecks(string $module, string $action): array
    {
        $checks = [];

        foreach (self::fallbackActionsFor($action) as $fallbackAction) {
            if ($fallbackAction !== $action) {
                $checks[] = [$module, $fallbackAction];
            }
        }

        $parentModule = self::parentModuleFor($module);
        if ($parentModule !== null) {
            $checks[] = [$parentModule, $action];

            foreach (self::fallbackActionsFor($action) as $fallbackAction) {
                $checks[] = [$parentModule, $fallbackAction];
            }
        }

        return collect($checks)
            ->unique(fn (array $check) => $check[0] . '.' . $check[1])
            ->values()
            ->all();
    }

    private static function parentModuleFor(string $module): ?string
    {
        return match ($module) {
            'personal_ingresos',
            'personal_documentos',
            'personal_contratos',
            'personal_vencimientos',
            'personal_puestos',
            'personal_lista_negra',
            'habilitacion_minera' => 'personal',
            'transportes' => 'rq_mina',
            default => null,
        };
    }

    private static function fallbackActionsFor(string $action): array
    {
        return match ($action) {
            'ver', 'dashboards', 'descargar' => ['ver'],
            'ver_detalle',
            'ver_ingresos',
            'ver_ficha',
            'ver_documentos',
            'ver_contratos',
            'ver_motivo',
            'ver_datos_sensibles',
            'editar_datos_contrato',
            'exportar_excel',
            'importar_master_general',
            'activar_trabajador',
            'cesar_trabajador',
            'descargar_documentos',
            'descargar_formato_contrato',
            'subir_contrato_firmado',
            'gestionar_lista_negra',
            'gestionar_puestos' => [$action],
            'crear',
            'editar',
            'actualizar',
            'registrar',
            'programar',
            'completar',
            'entregar',
            'recepcionar',
            'devolver',
            'sincronizar',
            'comunicar',
            'desasignar',
            'convalidar',
            'subir',
            'enviar',
            'duplicar',
            'corregir',
            'reabrir',
            'scope' => [$action, 'actualizar', 'editar'],
            'renovar',
            'regularizar',
            'reingresar',
            'anular',
            'cerrar',
            'marcar_no_aplica',
            'observar',
            'aprobar' => [$action, 'actualizar', 'aprobar'],
            'administrar' => ['administrar'],
            default => [$action, 'actualizar'],
        };
    }

    private static function mergeMatrices(array $matrices): array
    {
        $merged = PermissionCatalog::emptyMatrix();

        foreach ($matrices as $matrix) {
            $normalized = self::normalize($matrix);
            foreach ($merged as $module => $actions) {
                foreach ($actions as $action => $value) {
                    if (($normalized[$module][$action] ?? false) === true) {
                        $merged[$module][$action] = true;
                    }
                }
            }
        }

        return $merged;
    }

    private static function isLegacyFlatList(array $rawPermissions): bool
    {
        return array_is_list($rawPermissions);
    }

    private static function normalizeLegacyList(array $rawPermissions, array $matrix): array
    {
        foreach ($rawPermissions as $permission) {
            if (!is_string($permission) || trim($permission) === '') {
                continue;
            }

            $permission = trim($permission);
            if ($permission === '*') {
                return PermissionCatalog::fullAccessMatrix();
            }

            [$module, $legacyAction] = array_pad(explode('.', $permission, 2), 2, null);

            $module = self::normalizeModule((string) $module);
            if (!isset($matrix[$module]) || $legacyAction === null) {
                continue;
            }

            foreach (self::mapLegacyAction($legacyAction) as $mappedAction) {
                if (isset($matrix[$module][$mappedAction])) {
                    $matrix[$module][$mappedAction] = true;
                }
            }
        }

        return $matrix;
    }

    private static function normalizeModule(string $module): string
    {
        return match ($module) {
            'rq_mina' => 'rq_mina',
            'rq_proserge' => 'rq_proserge',
            default => $module,
        };
    }

    private static function mapLegacyAction(string $legacyAction): array
    {
        return match ($legacyAction) {
            'read' => ['ver'],
            'write' => ['crear', 'editar', 'actualizar', 'asignar'],
            'manage', 'admin' => ['administrar'],
            default => [],
        };
    }

    private static function isEmptyMatrix(array $matrix): bool
    {
        foreach ($matrix as $actions) {
            foreach ($actions as $allowed) {
                if ($allowed === true) {
                    return false;
                }
            }
        }

        return true;
    }

    private static function hasOperationalPermission(array $actions): bool
    {
        foreach ($actions as $action => $allowed) {
            if ($action !== 'ver' && $action !== 'dashboards' && $allowed === true) {
                return true;
            }
        }

        return false;
    }

    private static function isPrivilegedRole(?string $roleName): bool
    {
        return in_array(strtoupper(trim((string) $roleName)), ['ADMIN', 'GERENTE', 'SUPERADMIN'], true);
    }
}
