<?php

namespace App\Support\Rbac;

use Illuminate\Support\Facades\Route;

class PermissionCatalog
{
    /** @var array<string, string> */
    private const MODULES = [
        'inicio' => 'Inicio',
        'perfil' => 'Perfil',
        'personal' => 'Personal',
        'rq_mina' => 'RQ Mina',
        'rq_proserge' => 'RQ Proserge',
        'notificaciones' => 'Notificaciones',
        'man_power' => 'Man Power',
        'herramientas' => 'Herramientas',
        'mi_asistencia' => 'Mi Asistencia',
        'bienestar' => 'Bienestar',
        'evaluaciones' => 'Evaluaciones',
        'asistencias' => 'Asistencias',
        'faltas' => 'Faltas',
        'catalogos' => 'Catalogos',
        'remoto' => 'Remoto',
        'epps' => 'EPPs',
        'minas' => 'Minas',
        'talleres' => 'Talleres',
        'oficinas' => 'Oficinas',
        'usuarios' => 'Usuarios',
        'roles' => 'Roles',
    ];

    /** @var array<int, string> */
    private const ACTIONS = [
        'ver',
        'dashboards',
        'crear',
        'editar',
        'actualizar',
        'eliminar',
        'exportar',
        'importar',
        'aprobar',
        'asignar',
        'cerrar',
        'administrar',
    ];

    public static function modules(): array
    {
        return self::MODULES;
    }

    public static function availableModules(array $exclude = []): array
    {
        $available = self::availableModuleActions($exclude);
        $labels = [];

        foreach (self::MODULES as $module => $label) {
            if (in_array($module, $exclude, true)) {
                continue;
            }
            if (isset($available[$module])) {
                $labels[$module] = $label;
            }
        }

        $extraModules = array_diff_key($available, $labels);
        if (!empty($extraModules)) {
            ksort($extraModules);
            foreach ($extraModules as $module => $_) {
                $labels[$module] = self::moduleLabel($module);
            }
        }

        return $labels;
    }

    public static function actions(): array
    {
        return self::ACTIONS;
    }

    public static function availableActions(array $exclude = []): array
    {
        $moduleActions = self::availableModuleActions($exclude);
        $actions = [];

        foreach ($moduleActions as $items) {
            $actions = array_merge($actions, $items);
        }

        return self::sortActions(array_values(array_unique($actions)));
    }

    public static function availableModuleActions(array $exclude = []): array
    {
        $available = [];

        foreach (Route::getRoutes() as $route) {
            foreach ($route->middleware() as $middleware) {
                if (!is_string($middleware) || !str_starts_with($middleware, 'web.permission:')) {
                    continue;
                }

                $payload = substr($middleware, strlen('web.permission:'));
                [$module, $action] = array_pad(array_map('trim', explode(',', $payload, 2)), 2, 'ver');

                if ($module === '' || in_array($module, $exclude, true)) {
                    continue;
                }

                $action = $action !== '' ? $action : 'ver';
                $available[$module][$action] = true;
            }
        }

        return collect($available)
            ->map(fn (array $actions) => self::sortActions(array_keys($actions)))
            ->all();
    }

    public static function actionLabel(string $action): string
    {
        return match ($action) {
            'dashboards' => 'Dashboards',
            default => ucfirst($action),
        };
    }

    public static function moduleLabel(string $module): string
    {
        return self::MODULES[$module] ?? $module;
    }

    public static function baseRoleDefinitions(): array
    {
        $full = self::fullAccessMatrix();

        return [
            [
                'nombre' => 'ADMIN',
                'descripcion' => 'Administrador general con acceso total al sistema.',
                'estado' => 'ACTIVO',
                'permisos' => $full,
            ],
            [
                'nombre' => 'GERENTE',
                'descripcion' => 'Rol gerencial con acceso amplio editable desde la matriz.',
                'estado' => 'ACTIVO',
                'permisos' => $full,
            ],
            [
                'nombre' => 'OPERACIONES',
                'descripcion' => 'Opera Personal, RQ Mina y Man Power.',
                'estado' => 'ACTIVO',
                'permisos' => self::matrixFromSelections([
                    'inicio' => ['ver'],
                    'perfil' => ['ver', 'actualizar'],
                    'personal' => ['ver', 'dashboards', 'editar', 'actualizar', 'exportar'],
                    'rq_mina' => ['ver', 'dashboards', 'crear', 'editar', 'actualizar'],
                    'man_power' => ['ver', 'dashboards', 'crear', 'editar', 'actualizar', 'asignar'],
                ]),
            ],
            [
                'nombre' => 'RRHH',
                'descripcion' => 'Gestiona personal, usuarios y procesos RRHH.',
                'estado' => 'ACTIVO',
                'permisos' => self::matrixFromSelections([
                    'inicio' => ['ver'],
                    'perfil' => ['ver', 'actualizar'],
                    'personal' => ['ver', 'dashboards', 'crear', 'editar', 'actualizar', 'exportar', 'importar'],
                    'usuarios' => ['ver', 'crear', 'editar', 'actualizar', 'administrar'],
                    'rq_proserge' => ['ver', 'dashboards', 'crear', 'editar', 'actualizar', 'asignar'],
                    'man_power' => ['ver', 'dashboards', 'crear', 'editar', 'actualizar', 'asignar'],
                    'bienestar' => ['ver', 'dashboards', 'crear', 'editar', 'actualizar'],
                    'asistencias' => ['ver', 'dashboards', 'editar', 'actualizar', 'cerrar'],
                ]),
            ],
            [
                'nombre' => 'SUPERVISOR',
                'descripcion' => 'Acceso operativo limitado a su supervisión.',
                'estado' => 'ACTIVO',
                'permisos' => self::matrixFromSelections([
                    'inicio' => ['ver'],
                    'perfil' => ['ver', 'actualizar'],
                    'mi_asistencia' => ['ver', 'dashboards'],
                    'asistencias' => ['ver', 'dashboards', 'crear', 'editar', 'actualizar', 'cerrar'],
                    'evaluaciones' => ['ver', 'dashboards', 'crear', 'editar', 'actualizar'],
                    'personal' => ['ver', 'dashboards'],
                ]),
            ],
            [
                'nombre' => 'USUARIO',
                'descripcion' => 'Acceso básico a módulos personales.',
                'estado' => 'ACTIVO',
                'permisos' => self::matrixFromSelections([
                    'inicio' => ['ver'],
                    'perfil' => ['ver', 'actualizar'],
                    'mi_asistencia' => ['ver', 'dashboards'],
                ]),
            ],
        ];
    }

    public static function emptyMatrix(): array
    {
        $matrix = [];

        foreach (array_keys(self::MODULES) as $module) {
            $matrix[$module] = [];

            foreach (self::ACTIONS as $action) {
                $matrix[$module][$action] = false;
            }
        }

        return $matrix;
    }

    public static function fullAccessMatrix(): array
    {
        $matrix = self::emptyMatrix();

        foreach (array_keys(self::MODULES) as $module) {
            foreach (self::ACTIONS as $action) {
                $matrix[$module][$action] = true;
            }
        }

        return $matrix;
    }

    public static function matrixFromSelections(array $selected): array
    {
        $matrix = self::emptyMatrix();

        foreach ($selected as $module => $actions) {
            foreach ((array) $actions as $action) {
                if (isset($matrix[$module][$action])) {
                    $matrix[$module][$action] = true;
                }
            }
        }

        return $matrix;
    }

    private static function sortActions(array $actions): array
    {
        $base = self::ACTIONS;
        $sorted = [];

        foreach ($base as $action) {
            if (in_array($action, $actions, true)) {
                $sorted[] = $action;
            }
        }

        $extras = array_values(array_diff($actions, $base));
        if (!empty($extras)) {
            sort($extras);
            $sorted = array_merge($sorted, $extras);
        }

        return $sorted;
    }
}
