<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('notification_types')) {
            Schema::create('notification_types', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('code', 80)->unique();
                $table->string('module', 50);
                $table->string('category', 30)->default('operacion');
                $table->string('default_priority', 20)->default('medium');
                $table->string('required_permission_module', 50)->nullable();
                $table->string('required_permission_action', 30)->nullable();
                $table->string('default_title', 191);
                $table->string('default_action_label', 80)->nullable();
                $table->string('default_action_route', 191)->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->index(['module', 'is_active'], 'idx_notification_types_module_active');
            });
        }

        if (!Schema::hasTable('notification_events')) {
            Schema::create('notification_events', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('notification_type_id');
                $table->uuid('actor_usuario_id')->nullable();
                $table->uuid('mina_id')->nullable();
                $table->string('module', 50);
                $table->string('priority', 20)->default('medium');
                $table->string('title', 191);
                $table->string('message', 500);
                $table->string('action_label', 80)->nullable();
                $table->string('action_route', 191)->nullable();
                $table->string('entity_type', 80)->nullable();
                $table->string('entity_id', 80)->nullable();
                $table->json('payload')->nullable();
                $table->string('dedupe_key', 191)->nullable();
                $table->timestamp('occurred_at')->useCurrent();
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();

                $table->index(['module', 'priority'], 'idx_notification_events_module_priority');
                $table->index(['mina_id', 'occurred_at'], 'idx_notification_events_mina_occurred');
                $table->index('expires_at', 'idx_notification_events_expires');
                $table->unique('dedupe_key', 'uq_notification_events_dedupe');

                $table->foreign('notification_type_id', 'fk_notification_events_type')
                    ->references('id')
                    ->on('notification_types');
                $table->foreign('actor_usuario_id', 'fk_notification_events_actor')
                    ->references('id')
                    ->on('usuarios')
                    ->nullOnDelete();
                $table->foreign('mina_id', 'fk_notification_events_mina')
                    ->references('id')
                    ->on('minas')
                    ->nullOnDelete();
            });
        }

        if (!Schema::hasTable('notification_recipients')) {
            Schema::create('notification_recipients', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('notification_event_id');
                $table->uuid('usuario_id');
                $table->string('status', 20)->default('UNREAD');
                $table->timestamp('delivered_at')->nullable();
                $table->timestamp('read_at')->nullable();
                $table->timestamp('archived_at')->nullable();
                $table->timestamp('actioned_at')->nullable();
                $table->timestamps();

                $table->unique(['notification_event_id', 'usuario_id'], 'uq_notification_recipients_event_user');
                $table->index(['usuario_id', 'status', 'created_at'], 'idx_notification_recipients_user_status');

                $table->foreign('notification_event_id', 'fk_notification_recipients_event')
                    ->references('id')
                    ->on('notification_events')
                    ->cascadeOnDelete();
                $table->foreign('usuario_id', 'fk_notification_recipients_usuario')
                    ->references('id')
                    ->on('usuarios')
                    ->cascadeOnDelete();
            });
        }

        if (!Schema::hasTable('notification_preferences')) {
            Schema::create('notification_preferences', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('usuario_id');
                $table->uuid('notification_type_id');
                $table->boolean('in_app_enabled')->default(true);
                $table->boolean('email_enabled')->default(false);
                $table->string('minimum_priority', 20)->default('low');
                $table->timestamp('mute_until')->nullable();
                $table->timestamps();

                $table->unique(['usuario_id', 'notification_type_id'], 'uq_notification_preferences_user_type');

                $table->foreign('usuario_id', 'fk_notification_preferences_usuario')
                    ->references('id')
                    ->on('usuarios')
                    ->cascadeOnDelete();
                $table->foreign('notification_type_id', 'fk_notification_preferences_type')
                    ->references('id')
                    ->on('notification_types')
                    ->cascadeOnDelete();
            });
        }

        $now = now();

        $types = [
            ['code' => 'rq_mina_enviado', 'module' => 'rq_mina', 'category' => 'operacion', 'default_priority' => 'high', 'required_permission_module' => 'rq_mina', 'required_permission_action' => 'ver', 'default_title' => 'RQ Mina enviado', 'default_action_label' => 'Ver RQ Mina', 'default_action_route' => '/rq-mina/{entity_id}'],
            ['code' => 'rq_proserge_parcial', 'module' => 'rq_proserge', 'category' => 'operacion', 'default_priority' => 'high', 'required_permission_module' => 'rq_proserge', 'required_permission_action' => 'editar', 'default_title' => 'RQ Proserge parcialmente atendido', 'default_action_label' => 'Ver RQ Proserge', 'default_action_route' => '/rq-proserge/{entity_id}'],
            ['code' => 'rq_proserge_completado', 'module' => 'rq_proserge', 'category' => 'operacion', 'default_priority' => 'medium', 'required_permission_module' => 'rq_proserge', 'required_permission_action' => 'ver', 'default_title' => 'RQ Proserge completado', 'default_action_label' => 'Ver RQ Proserge', 'default_action_route' => '/rq-proserge/{entity_id}'],
            ['code' => 'grupo_dia_pendiente', 'module' => 'man_power', 'category' => 'operacion', 'default_priority' => 'high', 'required_permission_module' => 'man_power', 'required_permission_action' => 'crear', 'default_title' => 'Grupo de dia pendiente', 'default_action_label' => 'Crear grupo', 'default_action_route' => '/man-power/grupos/crear'],
            ['code' => 'grupo_noche_pendiente', 'module' => 'man_power', 'category' => 'operacion', 'default_priority' => 'high', 'required_permission_module' => 'man_power', 'required_permission_action' => 'crear', 'default_title' => 'Grupo de noche pendiente', 'default_action_label' => 'Crear grupo', 'default_action_route' => '/man-power/grupos/crear'],
            ['code' => 'grupo_sin_supervisor', 'module' => 'man_power', 'category' => 'advertencia', 'default_priority' => 'high', 'required_permission_module' => 'man_power', 'required_permission_action' => 'editar', 'default_title' => 'Grupo sin supervisor', 'default_action_label' => 'Revisar grupo', 'default_action_route' => '/man-power/grupos/{entity_id}'],
            ['code' => 'grupo_sin_cerrar', 'module' => 'man_power', 'category' => 'advertencia', 'default_priority' => 'high', 'required_permission_module' => 'man_power', 'required_permission_action' => 'cerrar', 'default_title' => 'Grupo sin cerrar', 'default_action_label' => 'Cerrar asistencia', 'default_action_route' => '/asistencia/grupos/{entity_id}'],
            ['code' => 'asistencia_pendiente_marcar', 'module' => 'asistencias', 'category' => 'accion_requerida', 'default_priority' => 'high', 'required_permission_module' => 'asistencias', 'required_permission_action' => 'crear', 'default_title' => 'Asistencia pendiente de marcar', 'default_action_label' => 'Marcar asistencia', 'default_action_route' => '/asistencia/grupos/{entity_id}/marcar'],
            ['code' => 'asistencia_pendiente_cerrar', 'module' => 'asistencias', 'category' => 'accion_requerida', 'default_priority' => 'high', 'required_permission_module' => 'asistencias', 'required_permission_action' => 'cerrar', 'default_title' => 'Asistencia pendiente de cerrar', 'default_action_label' => 'Cerrar asistencia', 'default_action_route' => '/asistencia/grupos/{entity_id}'],
            ['code' => 'faltas_generadas', 'module' => 'faltas', 'category' => 'advertencia', 'default_priority' => 'medium', 'required_permission_module' => 'faltas', 'required_permission_action' => 'ver', 'default_title' => 'Se generaron faltas', 'default_action_label' => 'Ver faltas', 'default_action_route' => '/faltas'],
            ['code' => 'import_personal_error', 'module' => 'personal', 'category' => 'sistema', 'default_priority' => 'critical', 'required_permission_module' => 'personal', 'required_permission_action' => 'importar', 'default_title' => 'Error en importacion de personal', 'default_action_label' => 'Revisar importacion', 'default_action_route' => '/personal/importar'],
            ['code' => 'personal_datos_incompletos', 'module' => 'personal', 'category' => 'advertencia', 'default_priority' => 'medium', 'required_permission_module' => 'personal', 'required_permission_action' => 'editar', 'default_title' => 'Personal con datos incompletos', 'default_action_label' => 'Ver personal', 'default_action_route' => '/personal'],
            ['code' => 'personal_bloqueado_bienestar', 'module' => 'bienestar', 'category' => 'operacion', 'default_priority' => 'high', 'required_permission_module' => 'bienestar', 'required_permission_action' => 'ver', 'default_title' => 'Personal bloqueado por bienestar', 'default_action_label' => 'Ver bienestar', 'default_action_route' => '/bienestar/{entity_id}'],
            ['code' => 'personal_no_disponible', 'module' => 'personal', 'category' => 'operacion', 'default_priority' => 'high', 'required_permission_module' => 'personal', 'required_permission_action' => 'ver', 'default_title' => 'Personal no disponible para parada', 'default_action_label' => 'Ver personal', 'default_action_route' => '/personal'],
            ['code' => 'rol_modificado', 'module' => 'roles', 'category' => 'seguridad', 'default_priority' => 'critical', 'required_permission_module' => 'roles', 'required_permission_action' => 'administrar', 'default_title' => 'Rol modificado', 'default_action_label' => 'Ver rol', 'default_action_route' => '/seguridad/roles/{entity_id}'],
            ['code' => 'permisos_modificados', 'module' => 'roles', 'category' => 'seguridad', 'default_priority' => 'critical', 'required_permission_module' => 'roles', 'required_permission_action' => 'administrar', 'default_title' => 'Permisos modificados', 'default_action_label' => 'Ver roles', 'default_action_route' => '/seguridad/roles'],
            ['code' => 'usuario_creado', 'module' => 'usuarios', 'category' => 'seguridad', 'default_priority' => 'high', 'required_permission_module' => 'usuarios', 'required_permission_action' => 'crear', 'default_title' => 'Nuevo usuario creado', 'default_action_label' => 'Ver usuario', 'default_action_route' => '/usuarios/{entity_id}'],
            ['code' => 'usuario_desactivado', 'module' => 'usuarios', 'category' => 'seguridad', 'default_priority' => 'high', 'required_permission_module' => 'usuarios', 'required_permission_action' => 'administrar', 'default_title' => 'Usuario desactivado', 'default_action_label' => 'Ver usuario', 'default_action_route' => '/usuarios/{entity_id}'],
        ];

        foreach ($types as $type) {
            DB::table('notification_types')->updateOrInsert(
                ['code' => $type['code']],
                [
                    'id' => DB::table('notification_types')->where('code', $type['code'])->value('id') ?? (string) Str::uuid(),
                    'module' => $type['module'],
                    'category' => $type['category'],
                    'default_priority' => $type['default_priority'],
                    'required_permission_module' => $type['required_permission_module'],
                    'required_permission_action' => $type['required_permission_action'],
                    'default_title' => $type['default_title'],
                    'default_action_label' => $type['default_action_label'],
                    'default_action_route' => $type['default_action_route'],
                    'is_active' => true,
                    'updated_at' => $now,
                    'created_at' => DB::table('notification_types')->where('code', $type['code'])->value('created_at') ?? $now,
                ]
            );
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('notification_preferences')) {
            Schema::drop('notification_preferences');
        }

        if (Schema::hasTable('notification_recipients')) {
            Schema::drop('notification_recipients');
        }

        if (Schema::hasTable('notification_events')) {
            Schema::drop('notification_events');
        }

        if (Schema::hasTable('notification_types')) {
            Schema::drop('notification_types');
        }
    }
};
