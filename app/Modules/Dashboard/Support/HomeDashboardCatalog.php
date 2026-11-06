<?php

namespace App\Modules\Dashboard\Support;

use App\Support\Rbac\PermissionMatrix;

class HomeDashboardCatalog
{
    public static function all(): array
    {
        return [
            'personal' => ['title' => 'Personal', 'description' => 'Estado laboral y seguimiento del personal registrado.', 'tone' => '#19D3C5', 'route' => 'personal.index'],
            'mi_asistencia' => ['title' => 'Mi Asistencia', 'description' => 'Resumen de grupos, turnos y marcaciones dentro de tu alcance.', 'tone' => '#4F8CFF', 'route' => 'mi-asistencia.index'],
            'man_power' => ['title' => 'Man Power', 'description' => 'Grupos, cobertura y distribucion de personal por parada.', 'tone' => '#10B981', 'route' => 'man-power.index'],
            'rq_mina' => ['title' => 'RQ Mina', 'description' => 'Seguimiento de paradas y requerimientos por mina.', 'tone' => '#F59E0B', 'route' => 'rq-mina.index'],
            'rq_proserge' => ['title' => 'RQ Proserge', 'description' => 'Asignaciones y cobertura de los requerimientos de personal.', 'tone' => '#8B5CF6', 'route' => 'rq-proserge.index'],
            'bienestar' => ['title' => 'Bienestar', 'description' => 'Vacaciones, descansos medicos y bloqueos del personal.', 'tone' => '#EC4899', 'route' => 'bienestar.index'],
            'evaluaciones' => ['title' => 'Evaluaciones', 'description' => 'Indicadores de evaluaciones y desempeno dentro de tu alcance.', 'tone' => '#06B6D4', 'route' => null],
            'asistencias' => ['title' => 'Asistencias', 'description' => 'Seguimiento de grupos, marcaciones y ausencias.', 'tone' => '#0F766E', 'route' => 'asistencia.index'],
            'faltas' => ['title' => 'Faltas', 'description' => 'Incidencias y seguimiento de correcciones pendientes.', 'tone' => '#DC2626', 'route' => 'faltas.index'],
            'catalogos' => ['title' => 'Catalogos', 'description' => 'Estado de minas, talleres y oficinas registrados.', 'tone' => '#64748B', 'route' => 'catalogos.index'],
            'logistica' => ['title' => 'Logistica', 'description' => 'Cobertura, entregas y recursos de la operacion.', 'tone' => '#D97706', 'route' => 'logistica.index', 'permission' => 'ver_logistica_dashboard'],
        ];
    }

    public static function visible(array $permissions): array
    {
        $cards = [];
        foreach (self::all() as $module => $dashboard) {
            if (PermissionMatrix::allowsDirect($permissions, $module, $dashboard['permission'] ?? 'dashboards')) {
                $cards[] = [...$dashboard, 'module' => $module, 'url' => route('inicio.dashboard.'.$module)];
            }
        }

        return $cards;
    }
}
