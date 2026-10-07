<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Text identifiers without foreign constraints support historical SQL installations.
        if (! Schema::hasTable('epp_almacenes')) {
            Schema::create('epp_almacenes', function (Blueprint $table): void {
                $table->char('id', 36)->primary();
                $table->string('nombre');
                $table->char('mina_id', 36)->nullable()->index();
                $table->string('estado', 20)->default('ACTIVO')->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('epp_existencias')) {
            Schema::create('epp_existencias', function (Blueprint $table): void {
                $table->char('id', 36)->primary();
                $table->char('almacen_id', 36);
                $table->char('epp_id', 36)->index();
                $table->string('variante_clave', 64);
                $table->json('atributos_json')->nullable();
                $table->unsignedInteger('cantidad_disponible')->default(0);
                $table->unsignedInteger('cantidad_reservada')->default(0);
                $table->unsignedInteger('cantidad_cuarentena')->default(0);
                $table->dateTime('conciliado_at')->nullable();
                $table->timestamps();
                $table->unique(['almacen_id', 'epp_id', 'variante_clave'], 'uq_epp_existencia_variante');
            });
        }

        if (! Schema::hasTable('epp_reservas')) {
            Schema::create('epp_reservas', function (Blueprint $table): void {
                $table->char('id', 36)->primary();
                $table->char('existencia_id', 36)->index();
                $table->char('operacion_id', 36)->index();
                $table->char('operacion_detalle_id', 36)->index();
                $table->unsignedInteger('cantidad');
                $table->string('estado', 20)->default('ACTIVA');
                $table->dateTime('expires_at');
                $table->dateTime('liberada_at')->nullable();
                $table->dateTime('consumida_at')->nullable();
                $table->timestamps();
                $table->index(['estado', 'expires_at'], 'idx_epp_reserva_expiracion');
            });
        }

        if (! Schema::hasTable('epp_inventario_movimientos')) {
            Schema::create('epp_inventario_movimientos', function (Blueprint $table): void {
                $table->char('id', 36)->primary();
                $table->char('existencia_id', 36)->index();
                $table->char('almacen_id', 36)->index();
                $table->char('epp_id', 36)->index();
                $table->char('operacion_id', 36)->nullable()->index();
                $table->char('operacion_detalle_id', 36)->nullable()->index();
                $table->string('tipo', 30)->index();
                $table->unsignedInteger('cantidad');
                $table->unsignedInteger('saldo_anterior');
                $table->unsignedInteger('saldo_posterior');
                $table->string('destino', 20)->default('DISPONIBLE');
                $table->char('actor_usuario_id', 36)->nullable();
                $table->string('origen', 30)->default('WEB');
                $table->text('motivo');
                $table->json('snapshot_json')->nullable();
                $table->timestamp('created_at')->useCurrent()->index();
            });
        }
    }

    public function down(): void
    {
        // Inventory and its audit ledger must survive application rollbacks.
    }
};
