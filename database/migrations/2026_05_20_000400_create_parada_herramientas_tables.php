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
        if (!Schema::hasTable('parada_herramienta_listas')) {
            Schema::create('parada_herramienta_listas', function (Blueprint $table): void {
                $table->char('id', 36)->primary();
                $table->char('rq_mina_id', 36);
                $table->unsignedSmallInteger('anio_iso');
                $table->unsignedTinyInteger('semana_iso');
                $table->date('fecha_limite_envio');
                $table->string('estado', 20)->default('BORRADOR');
                $table->text('observaciones')->nullable();
                $table->timestamp('enviado_at')->nullable();
                $table->char('created_by_usuario_id', 36)->nullable();
                $table->char('updated_by_usuario_id', 36)->nullable();
                $table->timestamps();

                $table->unique('rq_mina_id', 'uq_parada_herramienta_lista_rq');
                $table->index(['anio_iso', 'semana_iso'], 'idx_parada_herr_lista_semana');
                $table->index(['estado', 'fecha_limite_envio'], 'idx_parada_herr_lista_estado_limite');

                $table->foreign('rq_mina_id')
                    ->references('id')
                    ->on('rq_mina')
                    ->cascadeOnDelete();
                $table->foreign('created_by_usuario_id')
                    ->references('id')
                    ->on('usuarios')
                    ->nullOnDelete();
                $table->foreign('updated_by_usuario_id')
                    ->references('id')
                    ->on('usuarios')
                    ->nullOnDelete();
            });
        }

        if (!Schema::hasTable('parada_herramienta_grupos')) {
            Schema::create('parada_herramienta_grupos', function (Blueprint $table): void {
                $table->char('id', 36)->primary();
                $table->char('lista_id', 36);
                $table->char('grupo_trabajo_id', 36)->nullable();
                $table->string('nombre', 191);
                $table->unsignedSmallInteger('orden')->default(1);
                $table->text('observaciones')->nullable();
                $table->timestamps();

                $table->index('lista_id', 'idx_parada_herr_grupos_lista');
                $table->index('grupo_trabajo_id', 'idx_parada_herr_grupos_trabajo');

                $table->foreign('lista_id')
                    ->references('id')
                    ->on('parada_herramienta_listas')
                    ->cascadeOnDelete();
                $table->foreign('grupo_trabajo_id')
                    ->references('id')
                    ->on('grupo_trabajo')
                    ->nullOnDelete();
            });
        }

        if (!Schema::hasTable('parada_herramienta_items')) {
            Schema::create('parada_herramienta_items', function (Blueprint $table): void {
                $table->char('id', 36)->primary();
                $table->char('grupo_id', 36);
                $table->string('tipo', 20)->default('BASE');
                $table->string('descripcion', 300);
                $table->unsignedInteger('cantidad_solicitada')->default(1);
                $table->text('observaciones')->nullable();
                $table->unsignedSmallInteger('orden')->default(1);
                $table->timestamps();

                $table->index(['grupo_id', 'tipo'], 'idx_parada_herr_items_grupo_tipo');

                $table->foreign('grupo_id')
                    ->references('id')
                    ->on('parada_herramienta_grupos')
                    ->cascadeOnDelete();
            });
        }

        if (Schema::hasTable('notification_types')) {
            DB::table('notification_types')->updateOrInsert(
                ['code' => 'lista_herramientas_por_vencer'],
                [
                    'id' => DB::table('notification_types')->where('code', 'lista_herramientas_por_vencer')->value('id') ?? (string) Str::uuid(),
                    'module' => 'man_power',
                    'category' => 'accion_requerida',
                    'default_priority' => 'high',
                    'required_permission_module' => 'man_power',
                    'required_permission_action' => 'ver',
                    'default_title' => 'Lista de herramientas por vencer',
                    'default_action_label' => 'Revisar lista',
                    'default_action_route' => '/herramientas-parada/{entity_id}',
                    'is_active' => true,
                    'created_at' => DB::table('notification_types')->where('code', 'lista_herramientas_por_vencer')->value('created_at') ?? now(),
                    'updated_at' => now(),
                ]
            );
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('notification_types')) {
            $typeIds = DB::table('notification_types')
                ->where('code', 'lista_herramientas_por_vencer')
                ->pluck('id');

            if ($typeIds->isNotEmpty()) {
                if (Schema::hasTable('notification_recipients') && Schema::hasTable('notification_events')) {
                    $eventIds = DB::table('notification_events')
                        ->whereIn('notification_type_id', $typeIds->all())
                        ->pluck('id');

                    if ($eventIds->isNotEmpty()) {
                        DB::table('notification_recipients')->whereIn('notification_event_id', $eventIds->all())->delete();
                        DB::table('notification_events')->whereIn('id', $eventIds->all())->delete();
                    }
                }

                if (Schema::hasTable('notification_preferences')) {
                    DB::table('notification_preferences')->whereIn('notification_type_id', $typeIds->all())->delete();
                }

                DB::table('notification_types')->whereIn('id', $typeIds->all())->delete();
            }
        }

        Schema::dropIfExists('parada_herramienta_items');
        Schema::dropIfExists('parada_herramienta_grupos');
        Schema::dropIfExists('parada_herramienta_listas');
    }
};
