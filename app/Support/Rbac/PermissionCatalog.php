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
        'vencimientos' => 'Vencimientos',
        'personal_vencimientos' => 'Vencimientos de contratos',
        'personal_puestos' => 'Puestos y funciones',
        'personal_lista_negra' => 'Lista negra',
        'habilitacion_minera' => 'Habilitacion minera',
        'rq_mina' => 'RQ Mina',
        'rq_proserge' => 'RQ Proserge',
        'notificaciones' => 'Notificaciones',
        'man_power' => 'Man Power',
        'logistica' => 'Logistica',
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
        'ver_detalle',
        'ver_ingresos',
        'ver_ficha',
        'ver_documentos',
        'ver_contratos',
        'ver_matriz',
        'ver_vencimientos',
        'ver_programados',
        'ver_historial_precios',
        'ver_logistica_dashboard',
        'ver_logistica_entregas',
        'ver_logistica_vencimientos',
        'ver_logistica_herramientas',
        'ver_logistica_servicios',
        'ver_logistica_identificacion',
        'ver_logistica_costos',
        'ver_logistica_kardex',
        'ver_logistica_cesados',
        'dashboards',
        'crear',
        'editar',
        'editar_ficha',
        'editar_datos_contrato',
        'actualizar',
        'eliminar',
        'exportar',
        'exportar_excel',
        'importar',
        'importar_master_general',
        'aprobar',
        'rechazar',
        'asignar',
        'activar_trabajador',
        'activar',
        'cesar_trabajador',
        'desactivar',
        'cerrar',
        'administrar',
        'descargar',
        'descargar_documentos',
        'descargar_formato_contrato',
        'subir',
        'subir_documentos',
        'subir_contrato_firmado',
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
        'ver_datos_sensibles',
        'gestionar_lista_negra',
        'gestionar_puestos',
        'scope',
    ];

    /** @var array<int, string> */
    private const HIDDEN_MODULES = [
        'personal_vencimientos',
        'epps',
    ];

    /** @var array<string, array<int, string>> */
    private const MODULE_ACTIONS = [
        'inicio' => ['ver', 'dashboards'],
        'perfil' => ['ver', 'actualizar'],
        'notificaciones' => ['ver', 'actualizar'],
        'personal' => [
            'ver',
            'ver_detalle',
            'ver_ingresos',
            'ver_ficha',
            'ver_documentos',
            'ver_contratos',
            'dashboards',
            'crear',
            'editar',
            'editar_ficha',
            'editar_datos_contrato',
            'actualizar',
            'eliminar',
            'exportar',
            'exportar_excel',
            'importar',
            'importar_master_general',
            'enviar',
            'aprobar',
            'activar_trabajador',
            'cesar_trabajador',
            'descargar_documentos',
            'descargar_formato_contrato',
            'subir_documentos',
            'subir_contrato_firmado',
            'renovar',
            'reingresar',
            'ver_motivo',
            'ver_datos_sensibles',
            'gestionar_lista_negra',
            'gestionar_puestos',
        ],
        'personal_ingresos' => ['ver', 'editar', 'actualizar', 'eliminar', 'aprobar', 'comunicar'],
        'personal_documentos' => ['ver', 'subir', 'descargar', 'aprobar', 'observar', 'marcar_no_aplica'],
        'personal_contratos' => ['ver', 'crear', 'editar', 'actualizar', 'eliminar', 'subir', 'descargar', 'regularizar', 'renovar', 'reingresar', 'anular', 'cerrar'],
        'vencimientos' => ['ver', 'actualizar', 'exportar', 'registrar', 'renovar', 'cerrar'],
        'personal_vencimientos' => ['ver', 'actualizar', 'renovar', 'cerrar', 'exportar'],
        'personal_puestos' => ['ver', 'crear', 'editar', 'actualizar', 'eliminar'],
        'personal_lista_negra' => ['ver', 'registrar', 'eliminar', 'ver_motivo'],
        'habilitacion_minera' => [
            'ver',
            'ver_matriz',
            'ver_vencimientos',
            'ver_programados',
            'ver_historial_precios',
            'crear',
            'editar',
            'actualizar',
            'asignar',
            'desasignar',
            'configurar',
            'registrar',
            'programar',
            'convalidar',
            'importar',
            'exportar',
        ],
        'rq_mina' => ['ver', 'dashboards', 'crear', 'editar', 'actualizar', 'eliminar', 'exportar', 'importar', 'enviar', 'configurar', 'duplicar', 'administrar'],
        'rq_proserge' => ['ver', 'dashboards', 'crear', 'editar', 'actualizar', 'asignar', 'administrar'],
        'man_power' => ['ver', 'dashboards', 'crear', 'editar', 'actualizar', 'asignar', 'administrar'],
        'logistica' => [
            'ver',
            'ver_logistica_dashboard',
            'ver_logistica_entregas',
            'ver_logistica_vencimientos',
            'ver_logistica_herramientas',
            'ver_logistica_servicios',
            'ver_logistica_identificacion',
            'ver_logistica_costos',
            'ver_logistica_kardex',
            'ver_logistica_cesados',
            'dashboards',
            'crear',
            'editar',
            'actualizar',
            'eliminar',
            'exportar',
            'importar',
            'registrar',
            'configurar',
            'asignar',
            'enviar',
            'entregar',
            'recepcionar',
            'devolver',
            'administrar',
        ],
        'herramientas' => ['ver', 'actualizar', 'importar', 'registrar', 'completar', 'enviar', 'entregar', 'recepcionar', 'administrar'],
        'transportes' => ['ver', 'crear', 'editar', 'actualizar', 'eliminar', 'entregar', 'recepcionar', 'administrar'],
        'epps' => ['ver', 'crear', 'editar', 'actualizar', 'eliminar', 'registrar', 'configurar', 'devolver', 'administrar'],
        'mi_asistencia' => ['ver', 'dashboards'],
        'bienestar' => ['ver', 'dashboards', 'crear', 'editar', 'actualizar', 'eliminar', 'anular'],
        'evaluaciones' => ['ver', 'dashboards', 'crear', 'editar', 'actualizar', 'aprobar'],
        'asistencias' => ['ver', 'dashboards', 'crear', 'editar', 'actualizar', 'registrar', 'cerrar', 'reabrir', 'exportar'],
        'faltas' => ['ver', 'dashboards', 'editar', 'actualizar', 'eliminar', 'corregir', 'anular'],
        'catalogos' => ['ver', 'crear', 'editar', 'actualizar', 'eliminar', 'importar'],
        'remoto' => ['ver', 'actualizar'],
        'minas' => ['ver', 'crear', 'editar', 'actualizar', 'eliminar', 'desactivar', 'administrar'],
        'talleres' => ['ver', 'crear', 'editar', 'actualizar', 'eliminar'],
        'oficinas' => ['ver', 'crear', 'editar', 'actualizar', 'eliminar'],
        'usuarios' => ['ver', 'crear', 'editar', 'actualizar', 'eliminar', 'activar', 'desactivar', 'asignar', 'configurar', 'scope', 'administrar'],
        'roles' => ['ver', 'crear', 'editar', 'actualizar', 'eliminar', 'activar', 'desactivar', 'duplicar', 'administrar'],
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
            if (in_array($module, self::HIDDEN_MODULES, true)) {
                continue;
            }
            if (isset($available[$module])) {
                $labels[$module] = $label;
            }
        }

        $extraModules = array_diff_key($available, $labels);
        foreach (self::HIDDEN_MODULES as $hiddenModule) {
            unset($extraModules[$hiddenModule]);
        }
        if (!empty($extraModules)) {
            ksort($extraModules);
            foreach ($extraModules as $module => $_) {
                $labels[$module] = self::moduleLabel($module);
            }
        }

        return $labels;
    }

    /**
     * @return array<string, string>
     */
    public static function logisticsTabActions(): array
    {
        return [
            'dashboard' => 'ver_logistica_dashboard',
            'entregas' => 'ver_logistica_entregas',
            'vencimientos' => 'ver_logistica_vencimientos',
            'herramientas' => 'ver_logistica_herramientas',
            'servicios' => 'ver_logistica_servicios',
            'identificacion' => 'ver_logistica_identificacion',
            'costos' => 'ver_logistica_costos',
            'kardex' => 'ver_logistica_kardex',
            'cesados' => 'ver_logistica_cesados',
        ];
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

                $actions = $action !== '' ? array_map('trim', explode('|', $action)) : ['ver'];
                foreach ($actions as $routeAction) {
                    if ($routeAction !== '') {
                        $available[$module][$routeAction] = true;
                    }
                }
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
            'ver_detalle' => 'Ver detalle',
            'ver_ingresos' => 'Ver ingresos',
            'ver_ficha' => 'Ver ficha',
            'ver_documentos' => 'Ver documentos',
            'ver_contratos' => 'Ver contratos',
            'ver_matriz' => 'Ver matriz operativa',
            'ver_vencimientos' => 'Ver proximos vencimientos',
            'ver_programados' => 'Ver examenes programados',
            'ver_historial_precios' => 'Ver historial de precios',
            'ver_logistica_dashboard' => 'Ver Dashboard de Logistica',
            'ver_logistica_entregas' => 'Ver entregas y cambios de EPP',
            'ver_logistica_vencimientos' => 'Ver vencimientos de EPP',
            'ver_logistica_herramientas' => 'Ver herramientas',
            'ver_logistica_servicios' => 'Ver servicios y alquileres',
            'ver_logistica_identificacion' => 'Ver identificacion de items',
            'ver_logistica_costos' => 'Ver costos y facturacion',
            'ver_logistica_kardex' => 'Ver Kardex',
            'ver_logistica_cesados' => 'Ver cesados por entregar',
            'dashboards' => 'Dashboard',
            'crear' => 'Crear',
            'editar' => 'Editar',
            'editar_ficha' => 'Editar ficha',
            'editar_datos_contrato' => 'Editar datos de contrato',
            'actualizar' => 'Actualizar',
            'eliminar' => 'Eliminar',
            'exportar' => 'Exportar',
            'exportar_excel' => 'Exportar Excel',
            'importar' => 'Importar',
            'importar_master_general' => 'Importar Master General',
            'aprobar' => 'Aprobar',
            'rechazar' => 'Rechazar',
            'asignar' => 'Asignar',
            'activar_trabajador' => 'Activar trabajador',
            'activar' => 'Activar',
            'cesar_trabajador' => 'Cesar trabajador',
            'desactivar' => 'Desactivar',
            'cerrar' => 'Cerrar',
            'administrar' => 'Administrar',
            'descargar' => 'Descargar',
            'descargar_documentos' => 'Descargar documentos',
            'descargar_formato_contrato' => 'Descargar formato de contrato',
            'subir' => 'Subir',
            'subir_documentos' => 'Subir documentos',
            'subir_contrato_firmado' => 'Subir contrato firmado',
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
            'ver_datos_sensibles' => 'Ver datos sensibles',
            'gestionar_lista_negra' => 'Gestionar lista negra',
            'gestionar_puestos' => 'Gestionar puestos',
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
        $allLogisticsTabs = array_values(self::logisticsTabActions());

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
                    'personal' => [
                        'ver',
                        'ver_detalle',
                        'ver_ficha',
                        'ver_documentos',
                        'ver_contratos',
                        'dashboards',
                        'editar',
                        'editar_ficha',
                        'editar_datos_contrato',
                        'actualizar',
                        'exportar_excel',
                        'descargar_documentos',
                        'descargar_formato_contrato',
                        'subir_documentos',
                        'subir_contrato_firmado',
                        'renovar',
                        'reingresar',
                        'ver_motivo',
                    ],
                    'personal_documentos' => ['ver', 'descargar'],
                    'personal_contratos' => ['ver', 'descargar'],
                    'vencimientos' => ['ver'],
                    'personal_vencimientos' => ['ver'],
                    'habilitacion_minera' => ['ver', 'actualizar', 'asignar', 'desasignar', 'registrar', 'programar', 'convalidar', 'importar', 'exportar'],
                    'rq_mina' => ['ver', 'dashboards', 'crear', 'editar', 'actualizar', 'importar', 'enviar', 'configurar', 'duplicar'],
                    'man_power' => ['ver', 'dashboards', 'crear', 'editar', 'actualizar', 'asignar'],
                    'logistica' => array_merge(['ver'], $allLogisticsTabs, ['actualizar', 'enviar', 'entregar', 'recepcionar']),
                    'herramientas' => ['ver', 'actualizar', 'importar', 'registrar', 'completar', 'enviar', 'entregar', 'recepcionar'],
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
                    'personal' => [
                        'ver',
                        'ver_detalle',
                        'ver_ingresos',
                        'ver_ficha',
                        'ver_documentos',
                        'ver_contratos',
                        'dashboards',
                        'crear',
                        'editar',
                        'editar_ficha',
                        'editar_datos_contrato',
                        'actualizar',
                        'eliminar',
                        'exportar',
                        'exportar_excel',
                        'importar',
                        'importar_master_general',
                        'enviar',
                        'aprobar',
                        'activar_trabajador',
                        'cesar_trabajador',
                        'descargar_documentos',
                        'descargar_formato_contrato',
                        'subir_documentos',
                        'subir_contrato_firmado',
                        'renovar',
                        'reingresar',
                        'ver_motivo',
                        'ver_datos_sensibles',
                        'gestionar_lista_negra',
                        'gestionar_puestos',
                    ],
                    'personal_ingresos' => ['ver', 'editar', 'actualizar', 'eliminar', 'aprobar', 'comunicar'],
                    'personal_documentos' => ['ver', 'subir', 'descargar', 'aprobar', 'observar', 'marcar_no_aplica'],
                    'personal_contratos' => ['ver', 'crear', 'editar', 'actualizar', 'eliminar', 'subir', 'descargar', 'regularizar', 'renovar', 'reingresar', 'anular', 'cerrar'],
                    'vencimientos' => ['ver', 'actualizar', 'registrar', 'renovar', 'cerrar', 'exportar'],
                    'personal_vencimientos' => ['ver', 'actualizar', 'renovar', 'cerrar', 'exportar'],
                    'personal_puestos' => ['ver', 'crear', 'editar', 'actualizar', 'eliminar'],
                    'personal_lista_negra' => ['ver', 'registrar', 'eliminar', 'ver_motivo'],
                    'habilitacion_minera' => ['ver', 'actualizar', 'asignar', 'desasignar', 'configurar', 'registrar', 'programar', 'convalidar', 'importar', 'exportar'],
                    'usuarios' => ['ver', 'crear', 'editar', 'actualizar', 'activar', 'desactivar', 'asignar', 'configurar', 'scope', 'administrar'],
                    'rq_proserge' => ['ver', 'dashboards', 'crear', 'editar', 'actualizar', 'asignar'],
                    'man_power' => ['ver', 'dashboards', 'crear', 'editar', 'actualizar', 'asignar'],
                    'logistica' => array_merge(['ver'], $allLogisticsTabs, ['crear', 'editar', 'actualizar', 'eliminar', 'importar', 'registrar', 'enviar', 'entregar', 'recepcionar', 'devolver']),
                    'herramientas' => ['ver', 'actualizar', 'importar', 'registrar', 'completar', 'enviar', 'entregar', 'recepcionar'],
                    'epps' => ['ver', 'crear', 'editar', 'actualizar', 'eliminar', 'registrar', 'configurar', 'devolver'],
                    'bienestar' => ['ver', 'dashboards', 'crear', 'editar', 'actualizar', 'anular'],
                    'asistencias' => ['ver', 'dashboards', 'editar', 'actualizar', 'registrar', 'cerrar'],
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
                    'asistencias' => ['ver', 'dashboards', 'crear', 'editar', 'actualizar', 'registrar', 'cerrar'],
                    'evaluaciones' => ['ver', 'dashboards', 'crear', 'editar', 'actualizar'],
                    'personal' => ['ver', 'ver_detalle', 'ver_documentos', 'ver_contratos', 'dashboards'],
                    'rq_mina' => ['ver', 'dashboards'],
                    'logistica' => array_merge(['ver'], $allLogisticsTabs, ['registrar', 'devolver']),
                    'herramientas' => ['ver', 'actualizar', 'registrar', 'completar', 'entregar', 'recepcionar'],
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
