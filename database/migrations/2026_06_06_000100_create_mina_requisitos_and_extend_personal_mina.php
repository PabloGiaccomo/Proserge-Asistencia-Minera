<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('minas') && !Schema::hasTable('mina_requisitos')) {
            Schema::create('mina_requisitos', function (Blueprint $table): void {
                $table->char('id', 36)->primary();
                $table->char('mina_id', 36);
                $table->string('nombre', 191);
                $table->string('tipo', 80)->nullable();
                $table->text('descripcion')->nullable();
                $table->boolean('obligatorio')->default(true);
                $table->boolean('critico')->default(false);
                $table->boolean('reprogramable')->default(true);
                $table->unsignedInteger('vigencia_dias')->nullable();
                $table->boolean('activo')->default(true);
                $table->unsignedInteger('orden')->default(0);
                $table->timestamps();

                $table->index(['mina_id', 'activo']);
                $table->index(['mina_id', 'orden']);
                $table->unique(['mina_id', 'nombre', 'activo'], 'uq_mina_requisito_nombre_activo');
                $table->foreign('mina_id')->references('id')->on('minas')->cascadeOnDelete();
            });
        }

        if (Schema::hasTable('personal_mina')) {
            Schema::table('personal_mina', function (Blueprint $table): void {
                if (!Schema::hasColumn('personal_mina', 'estado_habilitacion')) {
                    $table->string('estado_habilitacion', 40)->nullable()->after('estado');
                }
                if (!Schema::hasColumn('personal_mina', 'fecha_asignacion')) {
                    $table->date('fecha_asignacion')->nullable()->after('estado_habilitacion');
                }
                if (!Schema::hasColumn('personal_mina', 'fecha_inicio_proceso')) {
                    $table->date('fecha_inicio_proceso')->nullable()->after('fecha_asignacion');
                }
                if (!Schema::hasColumn('personal_mina', 'fecha_habilitacion')) {
                    $table->date('fecha_habilitacion')->nullable()->after('fecha_inicio_proceso');
                }
                if (!Schema::hasColumn('personal_mina', 'observacion')) {
                    $table->text('observacion')->nullable()->after('fecha_habilitacion');
                }
                if (!Schema::hasColumn('personal_mina', 'activo')) {
                    $table->boolean('activo')->default(true)->after('observacion');
                }
                if (!Schema::hasColumn('personal_mina', 'usuario_actualizacion_id')) {
                    $table->char('usuario_actualizacion_id', 36)->nullable()->after('activo');
                }
            });

            DB::table('personal_mina')
                ->whereNull('estado_habilitacion')
                ->update([
                    'estado_habilitacion' => DB::raw('estado'),
                    'fecha_asignacion' => DB::raw('DATE(created_at)'),
                    'fecha_inicio_proceso' => DB::raw('DATE(created_at)'),
                    'activo' => true,
                ]);
        }

        if (Schema::hasTable('personal_mina') && !Schema::hasTable('personal_mina_historial')) {
            Schema::create('personal_mina_historial', function (Blueprint $table): void {
                $table->char('id', 36)->primary();
                $table->char('personal_mina_id', 36);
                $table->string('estado_anterior', 40)->nullable();
                $table->string('estado_nuevo', 40)->nullable();
                $table->text('observacion')->nullable();
                $table->char('usuario_id', 36)->nullable();
                $table->timestamp('fecha_cambio')->nullable();
                $table->timestamps();

                $table->index('personal_mina_id');
                $table->index('usuario_id');
                $table->foreign('personal_mina_id')->references('id')->on('personal_mina')->cascadeOnDelete();
                $table->foreign('usuario_id')->references('id')->on('usuarios')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_mina_historial');
        Schema::dropIfExists('mina_requisitos');

        if (Schema::hasTable('personal_mina')) {
            Schema::table('personal_mina', function (Blueprint $table): void {
                foreach ([
                    'usuario_actualizacion_id',
                    'activo',
                    'observacion',
                    'fecha_habilitacion',
                    'fecha_inicio_proceso',
                    'fecha_asignacion',
                    'estado_habilitacion',
                ] as $column) {
                    if (Schema::hasColumn('personal_mina', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
