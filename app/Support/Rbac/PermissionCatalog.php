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
        'personal_ingresos' => 'Ingresos de Personal',
        'personal_documentos' => 'Documentos de Personal',
        'personal_contratos' => 'Contratos laborales',
        'personal_vencimientos' => 'Vencimientos de contratos',
        'personal_puestos' => 'Puestos y funciones',
        'personal_lista_negra' => 'Lista negra',
        'habilitacion_minera' => 'Habilitacion minera',
        'rq_mina' => 'RQ Mina',
        'rq_proserge' => 'RQ Proserge',
        'notificaciones' => 'Notificaciones',
        'man_power' => 'Man Power',
        'herramientas' => 'Herramientas',
        'transportes' => 'Transportes',
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
        'descargar',
        'subir',
        'observar',
        'marcar_no_aplica',
        'regularizar',
        'renovar',
        'reingresar',
        'anular',
        'configurar',
        'registrar',
        'programar',
        'convalidar',
        'desasignar',
        'completar',
        'entregar',
        'recepcionar',
        'devolver',
        'sincronizar',
        'comunicar',
        'enviar',
        'duplicar',
        'corregir',
        'reabrir',
        'ver_motivo',
        'scope',
    ];

    /** @var array<string, array<int, string>> */
    private const MODULE_ACTIONS = [
        'inicio' => ['ver', 'dashboards'],
        'perfil' => ['ver', 'actualizar'],
        'notificaciones' => ['ver', 'actualizar'],
        'personal' => ['ver', 'dashboards', 'crear', 'editar', 'actualizar', 'eliminar', 'exportar', 'importar', 'aprobar'],
        'personal_ingresos' => ['ver', 'editar', 'actualizar', 'eliminar', 'aprobar', 'comunicar'],
        'personal_documentos' => ['ver', 'subir', 'descargar', 'aprobar', 'observar', 'marcar_no_aplica'],
        'personal_contratos' => ['ver', 'crear', 'editar', 'actualizar', 'eliminar', 'subir', 'descargar', 'regularizar', 'renovar', 'reingresar', 'anular', 'cerrar'],
        'personal_vencimientos' => ['ver', 'actualizar', 'renovar', 'cerrar', 'exportar'],
        'personal_puestos' => ['ver', 'crear', 'editar', 'actualizar', 'eliminar'],
        'personal_lista_negra' => ['ver', 'registrar', 'eliminar', 'ver_motivo'],
        'habilitacion_minera' => ['ver', 'crear', 'editar', 'actualizar', 'asignar', 'desasignar', 'configurar', 'registrar', 'programar', 'convalidar', 'importar', 'exportar'],
        'rq_mina' => ['ver', 'dashboards', 'crear', 'editar', 'actualizar', 'eliminar', 'enviar', 'duplicar', 'administrar'],
        'rq_proserge' => ['ver', 'dashboards', 'crear', 'editar', 'actualizar', 'asignar', 'administrar'],
        'man_power' => ['ver', 'dashboards', 'crear', 'editar', 'actualizar', 'asignar', 'administrar'],
        'herramientas' => ['ver', 'actualizar', 'importar', 'completar', 'enviar', 'entregar', 'recepcionar', 'administrar'],
        'transportes' => ['ver', 'crear', 'editar', 'actualizar', 'eliminar', 'entregar', 'recepcionar', 'administrar'],
        'epps' => ['ver', 'crear', 'editar', 'actualizar', 'eliminar', 'registrar', 'devolver', 'administrar'],
        'mi_asistencia' => ['ver', 'dashboards'],
        'bienestar' => ['ver', 'dashboards', 'crear', 'editar', 'actualizar', 'eliminar'],
        'evaluaciones' => ['ver', 'dashboards', 'crear', 'editar', 'actualizar', 'aprobar'],
        'asistencias' => ['ver', 'dashboards', 'crear', 'editar', 'actualizar', 'cerrar', 'reabrir'],
        'faltas' => ['ver', 'dashboards', 'editar', 'actualizar', 'eliminar', 'corregir', 'anular'],
        'catalogos' => ['ver', 'crear', 'editar', 'actualizar', 'eliminar', 'importar'],
        'remoto' => ['ver', 'actualizar'],
        'minas' => ['ver', 'crear', 'editar', 'actualizar', 'eliminar', 'administrar'],
        'talleres' => ['ver', 'crear', 'editar', 'actualizar', 'eliminar'],
        'oficinas' => ['ver', 'crear', 'editar', 'actualizar', 'eliminar'],
        'usuarios' => ['ver', 'crear', 'editar', 'actualizar', 'eliminar', 'administrar', 'scope'],
        'roles' => ['ver', 'crear', 'editar', 'actualizar', 'eliminar', 'administrar'],
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

        foreach (self::MODULE_ACTIONS as $module => $actions) {
            if (in_array($module, $exclude, true)) {
                continue;
            }

            foreach ($actions as $action) {
                $available[$module][$action] = true;
            }
        }

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
            'ver' => 'Ver',
            'dashboards' => 'Dashboard',
            'crear' => 'Crear',
            'editar' => 'Editar',
            'actualizar' => 'Actualizar',
            'eliminar' => 'Eliminar',
            'exportar' => 'Exportar',
            'importar' => 'Importar',
            'aprobar' => 'Aprobar',
            'asignar' => 'Asignar',
            'cerrar' => 'Cerrar',
            'administrar' => 'Administrar',
            'descargar' => 'Descargar',
            'subir' => 'Subir',
            'observar' => 'Observar',
            'marcar_no_aplica' => 'Marcar no aplica',
            'regularizar' => 'Regularizar',
            'renovar' => 'Renovar',
            'reingresar' => 'Reingresar',
            'anular' => 'Anular',
            'configurar' => 'Configurar',
            'registrar' => 'Registrar',
            'programar' => 'Programar',
            'convalidar' => 'Convalidar',
            'desasignar' => 'Desasignar',
            'completar' => 'Completar',
            'entregar' => 'Entregar',
            'recepcionar' => 'Recepcionar',
            'devolver' => 'Devolver',
            'sincronizar' => 'Sincronizar',
            'comunicar' => 'Comunicar',
            'enviar' => 'Enviar',
            'duplicar' => 'Duplicar',
            'corregir' => 'Corregir',
            'reabrir' => 'Reabrir',
            'ver_motivo' => 'Ver motivo',
            'scope' => 'Alcance',
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
                'descripcion' => 'Rol gerencial con acceso amplio editable por pantalla.',
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
                    'personal_documentos' => ['ver', 'descargar'],
                    'personal_contratos' => ['ver', 'descargar'],
                    'personal_vencimientos' => ['ver'],
                    'habilitacion_minera' => ['ver', 'actualizar', 'asignar', 'desasignar', 'registrar', 'programar', 'convalidar', 'importar', 'exportar'],
                    'rq_mina' => ['ver', 'dashboards', 'crear', 'editar', 'actualizar', 'enviar', 'duplicar'],
                    'man_power' => ['ver', 'dashboards', 'crear', 'editar', 'actualizar', 'asignar'],
                    'herramientas' => ['ver', 'actualizar', 'completar', 'enviar', 'entregar', 'recepcionar'],
                    'transportes' => ['ver', 'crear', 'editar', 'actualizar', 'entregar', 'recepcionar'],
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
                    'personal_ingresos' => ['ver', 'editar', 'actualizar', 'eliminar', 'aprobar', 'comunicar'],
                    'personal_documentos' => ['ver', 'subir', 'descargar', 'aprobar', 'observar', 'marcar_no_aplica'],
                    'personal_contratos' => ['ver', 'crear', 'editar', 'actualizar', 'eliminar', 'subir', 'descargar', 'regularizar', 'renovar', 'reingresar', 'anular', 'cerrar'],
                    'personal_vencimientos' => ['ver', 'actualizar', 'renovar', 'cerrar', 'exportar'],
                    'personal_puestos' => ['ver', 'crear', 'editar', 'actualizar', 'eliminar'],
                    'personal_lista_negra' => ['ver', 'registrar', 'eliminar', 'ver_motivo'],
                    'habilitacion_minera' => ['ver', 'actualizar', 'asignar', 'desasignar', 'configurar', 'registrar', 'programar', 'convalidar', 'importar', 'exportar'],
                    'usuarios' => ['ver', 'crear', 'editar', 'actualizar', 'administrar'],
                    'rq_proserge' => ['ver', 'dashboards', 'crear', 'editar', 'actualizar', 'asignar'],
                    'man_power' => ['ver', 'dashboards', 'crear', 'editar', 'actualizar', 'asignar'],
                    'herramientas' => ['ver', 'actualizar', 'importar', 'completar', 'enviar', 'entregar', 'recepcionar'],
                    'epps' => ['ver', 'crear', 'editar', 'actualizar', 'eliminar', 'registrar', 'devolver'],
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
                    'rq_mina' => ['ver', 'dashboards'],
                    'herramientas' => ['ver', 'actualizar', 'completar', 'entregar', 'recepcionar'],
                    'epps' => ['ver', 'registrar', 'devolver'],
                    'bienestar' => ['ver', 'dashboards'],
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
        $availableModuleActions = self::availableModuleActions();

        foreach (array_keys(self::MODULES) as $module) {
            $matrix[$module] = [];

            foreach (self::ACTIONS as $action) {
                $matrix[$module][$action] = false;
            }

            foreach ($availableModuleActions[$module] ?? [] as $action) {
                $matrix[$module][$action] = false;
            }
        }

        foreach ($availableModuleActions as $module => $actions) {
            if (isset($matrix[$module])) {
                continue;
            }

            $matrix[$module] = [];
            foreach (self::sortActions($actions) as $action) {
                $matrix[$module][$action] = false;
            }
        }

        return $matrix;
    }

    public static function fullAccessMatrix(): array
    {
        $matrix = self::emptyMatrix();

        foreach ($matrix as $module => $actions) {
            foreach (array_keys($actions) as $action) {
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
