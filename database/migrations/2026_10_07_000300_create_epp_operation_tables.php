<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('epp_operaciones')) {
            Schema::create('epp_operaciones', function (Blueprint $table): void {
                $table->char('id', 36)->primary();
                $table->char('personal_id', 36);
                $table->char('almacen_id', 36);
                $table->char('mina_id', 36)->nullable()->index();
                $table->string('tipo', 20)->index();
                $table->string('estado', 30)->default('BORRADOR');
                $table->char('submission_uuid', 36)->unique('uq_epp_operacion_submission');
                $table->char('operacion_origen_id', 36)->nullable()->index();
                $table->char('registrado_por_usuario_id', 36)->nullable();
                $table->char('confirmado_por_usuario_id', 36)->nullable();
                $table->char('resumen_sha256', 64)->nullable();
                $table->json('resumen_json')->nullable();
                $table->unsignedInteger('version')->default(1);
                $table->dateTime('reserva_expires_at')->nullable();
                $table->dateTime('firmado_at', 6)->nullable();
                $table->text('motivo')->nullable();
                $table->text('observacion')->nullable();
                $table->timestamps();
                $table->index(['personal_id', 'estado'], 'idx_epp_operacion_personal_estado');
                $table->index(['almacen_id', 'estado'], 'idx_epp_operacion_almacen_estado');
                $table->index(['estado', 'created_at'], 'idx_epp_operacion_estado_fecha');
            });
        }

        if (! Schema::hasTable('epp_operacion_detalles')) {
            Schema::create('epp_operacion_detalles', function (Blueprint $table): void {
                $table->char('id', 36)->primary();
                $table->char('operacion_id', 36)->index();
                $table->char('epp_solicitado_id', 36);
                $table->char('epp_id', 36)->index();
                $table->char('existencia_id', 36)->nullable()->index();
                $table->char('entrega_origen_id', 36)->nullable()->index();
                $table->char('detalle_origen_id', 36)->nullable()->index();
                $table->unsignedInteger('cantidad_solicitada');
                $table->unsignedInteger('cantidad_procesada')->default(0);
                $table->unsignedInteger('cantidad_pendiente')->default(0);
                $table->string('estado', 20)->default('PENDIENTE')->index();
                $table->string('talla', 80)->nullable();
                $table->string('color', 120)->nullable();
                $table->json('atributos_json')->nullable();
                $table->string('variante_clave', 64)->nullable();
                $table->string('politica_devolucion_snapshot', 20);
                $table->boolean('control_inventario_snapshot')->default(true);
                $table->decimal('precio_unitario_snapshot', 12, 2)->nullable();
                $table->string('condicion_devolucion', 20)->nullable();
                $table->string('destino_inventario', 20)->nullable();
                $table->text('motivo')->nullable();
                $table->json('snapshot_json');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('epp_aceptaciones')) {
            Schema::create('epp_aceptaciones', function (Blueprint $table): void {
                $table->char('id', 36)->primary();
                $table->char('operacion_id', 36)->unique('uq_epp_aceptacion_operacion');
                $table->char('personal_id', 36)->index();
                $table->enum('tipo_firmante', ['TRABAJADOR', 'TESTIGO_AUTORIZADO']);
                $table->string('firmante_nombre')->nullable();
                $table->string('documento_snapshot', 40)->nullable();
                $table->char('testigo_usuario_id', 36)->nullable();
                $table->char('aprobador_usuario_id', 36)->nullable();
                $table->text('motivo_excepcion')->nullable();
                $table->char('operador_usuario_id', 36)->nullable();
                $table->string('firma_path', 500);
                $table->char('firma_sha256', 64);
                // LONGTEXT preserves canonical bytes; MySQL JSON rewrites whitespace/key order.
                $table->longText('contenido_json');
                $table->char('contenido_sha256', 64);
                $table->string('codigo_verificacion', 40)->unique('uq_epp_aceptacion_codigo');
                $table->char('submission_uuid', 36);
                $table->dateTime('firmado_at', 6)->index();
                $table->dateTime('firmado_at_utc', 6);
                $table->string('zona_horaria', 60)->default('America/Lima');
                $table->string('declaracion_version', 30);
                $table->string('user_agent', 500)->nullable();
                $table->char('sesion_sha256', 64)->nullable();
                $table->char('ip_sha256', 64)->nullable();
                $table->string('estado_integridad', 30)->default('PENDIENTE_VERIFICACION')->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('epp_operacion_eventos')) {
            Schema::create('epp_operacion_eventos', function (Blueprint $table): void {
                $table->char('id', 36)->primary();
                $table->char('operacion_id', 36)->index();
                $table->char('actor_usuario_id', 36)->nullable();
                $table->string('accion', 60)->index();
                $table->string('origen', 30);
                $table->text('motivo')->nullable();
                $table->json('snapshot_json')->nullable();
                $table->timestamp('created_at')->useCurrent()->index();
            });
        }
    }

    public function down(): void
    {
        // Preserve signed evidence and operational history on rollback.
    }
};
