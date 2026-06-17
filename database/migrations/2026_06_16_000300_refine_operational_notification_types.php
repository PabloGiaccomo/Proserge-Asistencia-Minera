<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('notification_types')) {
            return;
        }

        $now = now();

        $types = [
            [
                'code' => 'personal_ficha_completada',
                'module' => 'personal',
                'category' => 'accion_requerida',
                'default_priority' => 'high',
                'required_permission_module' => 'personal',
                'required_permission_action' => 'aprobar',
                'default_title' => 'Ficha de colaborador completada',
                'default_action_label' => 'Revisar ficha',
                'default_action_route' => '/personal/fichas/{entity_id}/revisar',
            ],
            [
                'code' => 'personal_ficha_aprobada_falta_contrato',
                'module' => 'personal',
                'category' => 'accion_requerida',
                'default_priority' => 'high',
                'required_permission_module' => 'personal',
                'required_permission_action' => 'actualizar',
                'default_title' => 'Ficha aprobada con contrato pendiente',
                'default_action_label' => 'Completar contrato',
                'default_action_route' => '/personal/{entity_id}/datos-contrato',
            ],
            [
                'code' => 'personal_contrato_firmado',
                'module' => 'personal',
                'category' => 'operacion',
                'default_priority' => 'medium',
                'required_permission_module' => 'personal',
                'required_permission_action' => 'actualizar',
                'default_title' => 'Contrato firmado cargado',
                'default_action_label' => 'Ver contratos',
                'default_action_route' => '/personal/{personal_route_id}/contratos',
            ],
            [
                'code' => 'contrato_decision_renovacion',
                'module' => 'personal',
                'category' => 'operacion',
                'default_priority' => 'high',
                'required_permission_module' => 'personal',
                'required_permission_action' => 'actualizar',
                'default_title' => 'Decision de renovacion registrada',
                'default_action_label' => 'Ver vencimientos',
                'default_action_route' => '/personal/contratos/vencimientos',
            ],
            [
                'code' => 'contrato_no_renovado_cerrado',
                'module' => 'personal',
                'category' => 'operacion',
                'default_priority' => 'high',
                'required_permission_module' => 'personal',
                'required_permission_action' => 'actualizar',
                'default_title' => 'Contrato cerrado como no renovado',
                'default_action_label' => 'Ver contratos',
                'default_action_route' => '/personal/{personal_id}/contratos',
            ],
            [
                'code' => 'habilitacion_mina_actualizada',
                'module' => 'personal',
                'category' => 'operacion',
                'default_priority' => 'medium',
                'required_permission_module' => 'personal',
                'required_permission_action' => 'actualizar',
                'default_title' => 'Habilitacion minera actualizada',
                'default_action_label' => 'Ver habilitacion',
                'default_action_route' => '/personal/habilitacion-minera',
            ],
            [
                'code' => 'habilitacion_mina_habilitado',
                'module' => 'personal',
                'category' => 'operacion',
                'default_priority' => 'medium',
                'required_permission_module' => 'personal',
                'required_permission_action' => 'actualizar',
                'default_title' => 'Trabajador habilitado en mina',
                'default_action_label' => 'Ver habilitacion',
                'default_action_route' => '/personal/habilitacion-minera',
            ],
            [
                'code' => 'habilitacion_mina_estado_critico',
                'module' => 'personal',
                'category' => 'advertencia',
                'default_priority' => 'high',
                'required_permission_module' => 'personal',
                'required_permission_action' => 'actualizar',
                'default_title' => 'Habilitacion minera requiere atencion',
                'default_action_label' => 'Ver habilitacion',
                'default_action_route' => '/personal/habilitacion-minera',
            ],
            [
                'code' => 'habilitacion_examen_programado',
                'module' => 'personal',
                'category' => 'operacion',
                'default_priority' => 'medium',
                'required_permission_module' => 'personal',
                'required_permission_action' => 'actualizar',
                'default_title' => 'Examen minero programado',
                'default_action_label' => 'Ver habilitacion',
                'default_action_route' => '/personal/habilitacion-minera',
            ],
            [
                'code' => 'bienestar_bloqueo_registrado',
                'module' => 'bienestar',
                'category' => 'advertencia',
                'default_priority' => 'high',
                'required_permission_module' => 'bienestar',
                'required_permission_action' => 'ver',
                'default_title' => 'Bloqueo de bienestar registrado',
                'default_action_label' => 'Ver bienestar',
                'default_action_route' => '/bienestar/{personal_id}',
            ],
            [
                'code' => 'bienestar_bloqueo_anulado',
                'module' => 'bienestar',
                'category' => 'operacion',
                'default_priority' => 'medium',
                'required_permission_module' => 'bienestar',
                'required_permission_action' => 'ver',
                'default_title' => 'Bloqueo de bienestar anulado',
                'default_action_label' => 'Ver bienestar',
                'default_action_route' => '/bienestar/{personal_id}',
            ],
            [
                'code' => 'rq_mina_enviado',
                'module' => 'rq_proserge',
                'category' => 'accion_requerida',
                'default_priority' => 'high',
                'required_permission_module' => 'rq_proserge',
                'required_permission_action' => 'asignar',
                'default_title' => 'RQ Mina enviado para atender',
                'default_action_label' => 'Ver RQ Proserge',
                'default_action_route' => '/rq-proserge',
            ],
            [
                'code' => 'rq_mina_pedido_modificado',
                'module' => 'rq_proserge',
                'category' => 'accion_requerida',
                'default_priority' => 'high',
                'required_permission_module' => 'rq_proserge',
                'required_permission_action' => 'asignar',
                'default_title' => 'Pedido de RQ Mina modificado',
                'default_action_label' => 'Revisar RQ Proserge',
                'default_action_route' => '/rq-proserge/{entity_id}',
            ],
            [
                'code' => 'rq_proserge_completado',
                'module' => 'rq_proserge',
                'category' => 'operacion',
                'default_priority' => 'medium',
                'required_permission_module' => 'rq_proserge',
                'required_permission_action' => 'ver',
                'default_title' => 'RQ Proserge completado',
                'default_action_label' => 'Ver RQ Proserge',
                'default_action_route' => '/rq-proserge/{entity_id}',
            ],
            [
                'code' => 'lista_herramientas_por_vencer',
                'module' => 'herramientas',
                'category' => 'accion_requerida',
                'default_priority' => 'high',
                'required_permission_module' => 'herramientas',
                'required_permission_action' => 'ver',
                'default_title' => 'Lista de herramientas por vencer',
                'default_action_label' => 'Ver herramientas',
                'default_action_route' => '/herramientas-parada/{entity_id}',
            ],
        ];

        foreach ($types as $type) {
            $existing = DB::table('notification_types')->where('code', $type['code'])->first();

            DB::table('notification_types')->updateOrInsert(
                ['code' => $type['code']],
                [
                    'id' => $existing->id ?? (string) Str::uuid(),
                    'module' => $type['module'],
                    'category' => $type['category'],
                    'default_priority' => $type['default_priority'],
                    'required_permission_module' => $type['required_permission_module'],
                    'required_permission_action' => $type['required_permission_action'],
                    'default_title' => $type['default_title'],
                    'default_action_label' => $type['default_action_label'],
                    'default_action_route' => $type['default_action_route'],
                    'is_active' => true,
                    'created_at' => $existing->created_at ?? $now,
                    'updated_at' => $now,
                ],
            );
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('notification_types')) {
            return;
        }

        DB::table('notification_types')
            ->whereIn('code', [
                'personal_ficha_aprobada_falta_contrato',
                'personal_contrato_firmado',
                'contrato_decision_renovacion',
                'contrato_no_renovado_cerrado',
                'habilitacion_mina_actualizada',
                'habilitacion_mina_habilitado',
                'habilitacion_mina_estado_critico',
                'habilitacion_examen_programado',
                'bienestar_bloqueo_registrado',
                'bienestar_bloqueo_anulado',
                'rq_mina_pedido_modificado',
            ])
            ->delete();
    }
};
