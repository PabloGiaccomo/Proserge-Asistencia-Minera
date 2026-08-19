<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('epp_entregas')) {
            Schema::create('epp_entregas', function (Blueprint $table): void {
                $table->char('id', 36)->primary();
                $table->char('personal_id', 36);
                $table->char('epp_id', 36);
                $table->unsignedInteger('cantidad')->default(1);
                $table->string('talla', 80)->nullable();
                $table->string('color', 120)->nullable();
                $table->json('atributos_json')->nullable();
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

            return;
        }

        Schema::table('epp_entregas', function (Blueprint $table): void {
            if (! Schema::hasColumn('epp_entregas', 'talla')) {
                $table->string('talla', 80)->nullable()->after('cantidad');
            }

            if (! Schema::hasColumn('epp_entregas', 'color')) {
                $table->string('color', 120)->nullable()->after('talla');
            }

            if (! Schema::hasColumn('epp_entregas', 'atributos_json')) {
                $table->json('atributos_json')->nullable()->after('color');
            }
        });
    }

    public function down(): void
    {
        // Repair migration: do not drop operational EPP history on rollback.
    }
};
