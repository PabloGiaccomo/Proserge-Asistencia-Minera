<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('epp_registro')) {
            Schema::create('epp_registro', function (Blueprint $table): void {
                $table->char('id', 36)->primary();
                $table->string('codigo', 120)->unique();
                $table->string('nombre');
                $table->string('categoria', 30)->default('EPP');
                $table->string('unidad_minera')->nullable();
                $table->decimal('precio_unitario', 12, 2)->default(0);
                $table->decimal('precio_alquiler', 12, 2)->nullable();
                $table->string('proveedor')->nullable();
                $table->string('orden_compra', 120)->nullable();
                $table->string('facturacion', 120)->nullable();
                $table->integer('stock')->default(0);
                $table->unsignedInteger('vida_util_dias')->default(0);
                $table->string('estado', 20)->default('ACTIVO');
                $table->timestamps();
            });
        } elseif (!Schema::hasColumn('epp_registro', 'vida_util_dias')) {
            Schema::table('epp_registro', function (Blueprint $table): void {
                $table->unsignedInteger('vida_util_dias')->default(0)->after('stock');
            });
        }

        if (!Schema::hasTable('epp_entregas')) {
            Schema::create('epp_entregas', function (Blueprint $table): void {
                $table->char('id', 36)->primary();
                $table->char('personal_id', 36);
                $table->char('epp_id', 36);
                $table->unsignedInteger('cantidad')->default(1);
                $table->date('fecha_entrega');
                $table->date('fecha_vencimiento_calendario')->nullable();
                $table->unsignedInteger('vida_util_dias_snapshot')->default(0);
                $table->string('estado', 30)->default('ENTREGADO');
                $table->string('motivo_cambio', 120)->nullable();
                $table->text('observacion')->nullable();
                $table->date('devuelto_at')->nullable();
                $table->char('registrado_por_usuario_id', 36)->nullable();
                $table->char('cerrado_por_usuario_id', 36)->nullable();
                $table->timestamps();

                $table->index(['personal_id', 'estado'], 'idx_epp_entregas_personal_estado');
                $table->index(['epp_id', 'estado'], 'idx_epp_entregas_epp_estado');
                $table->index('fecha_entrega', 'idx_epp_entregas_fecha_entrega');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('epp_entregas');

        if (Schema::hasTable('epp_registro') && Schema::hasColumn('epp_registro', 'vida_util_dias')) {
            Schema::table('epp_registro', function (Blueprint $table): void {
                $table->dropColumn('vida_util_dias');
            });
        }
    }
};
