<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('examenes_mineros')) {
            Schema::table('examenes_mineros', function (Blueprint $table): void {
                if (!Schema::hasColumn('examenes_mineros', 'requiere_lugar')) {
                    $table->boolean('requiere_lugar')->default(false)->after('tipo');
                }
                if (!Schema::hasColumn('examenes_mineros', 'empresa_paga')) {
                    $table->boolean('empresa_paga')->default(false)->after('lugar');
                }
                if (!Schema::hasColumn('examenes_mineros', 'precio_desde')) {
                    $table->date('precio_desde')->nullable()->after('moneda');
                }
            });
        }

        if (!Schema::hasTable('examen_minero_precios')) {
            Schema::create('examen_minero_precios', function (Blueprint $table): void {
                $table->char('id', 36)->primary();
                $table->char('examen_id', 36);
                $table->decimal('precio', 12, 2);
                $table->string('moneda', 10)->default('PEN');
                $table->date('fecha_inicio');
                $table->date('fecha_fin')->nullable();
                $table->text('observacion')->nullable();
                $table->char('usuario_id', 36)->nullable();
                $table->timestamps();

                $table->index(['examen_id', 'fecha_inicio'], 'idx_examen_precio_vigencia');
                $table->foreign('examen_id')->references('id')->on('examenes_mineros')->cascadeOnDelete();
                $table->foreign('usuario_id')->references('id')->on('usuarios')->nullOnDelete();
            });
        }

        if (Schema::hasTable('personal_mina_examen_intentos')) {
            Schema::table('personal_mina_examen_intentos', function (Blueprint $table): void {
                if (!Schema::hasColumn('personal_mina_examen_intentos', 'precio_aplicado')) {
                    $table->decimal('precio_aplicado', 12, 2)->nullable()->after('nota');
                }
                if (!Schema::hasColumn('personal_mina_examen_intentos', 'moneda_aplicada')) {
                    $table->string('moneda_aplicada', 10)->nullable()->after('precio_aplicado');
                }
                if (!Schema::hasColumn('personal_mina_examen_intentos', 'fecha_precio_aplicado')) {
                    $table->date('fecha_precio_aplicado')->nullable()->after('moneda_aplicada');
                }
                if (!Schema::hasColumn('personal_mina_examen_intentos', 'fuente_precio')) {
                    $table->string('fuente_precio', 80)->nullable()->after('fecha_precio_aplicado');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('personal_mina_examen_intentos')) {
            Schema::table('personal_mina_examen_intentos', function (Blueprint $table): void {
                foreach (['fuente_precio', 'fecha_precio_aplicado', 'moneda_aplicada', 'precio_aplicado'] as $column) {
                    if (Schema::hasColumn('personal_mina_examen_intentos', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        Schema::dropIfExists('examen_minero_precios');

        if (Schema::hasTable('examenes_mineros')) {
            Schema::table('examenes_mineros', function (Blueprint $table): void {
                foreach (['precio_desde', 'empresa_paga', 'requiere_lugar'] as $column) {
                    if (Schema::hasColumn('examenes_mineros', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
