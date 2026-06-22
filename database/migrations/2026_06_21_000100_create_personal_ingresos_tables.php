<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('personal') && !Schema::hasColumn('personal', 'pendiente_contrato_firmado')) {
            Schema::table('personal', function (Blueprint $table): void {
                $table->boolean('pendiente_contrato_firmado')->default(false)->after('pendiente_regularizacion');
            });
        }

        if (!Schema::hasTable('personal_ingreso_claves')) {
            Schema::create('personal_ingreso_claves', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->date('fecha')->unique();
                $table->string('clave_hash');
                $table->text('clave_encrypted');
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('personal_ingresos')) {
            Schema::create('personal_ingresos', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('estado', 50)->default('FICHA_RECIBIDA')->index();
                $table->string('tipo_documento', 40)->default('DNI');
                $table->string('numero_documento', 40)->index();
                $table->uuid('personal_existente_id')->nullable()->index();
                $table->uuid('personal_creado_id')->nullable()->index();
                $table->json('datos_json')->nullable();
                $table->json('familiares_json')->nullable();
                $table->longText('firma_base64')->nullable();
                $table->string('huella_path')->nullable();
                $table->text('observaciones_revision')->nullable();
                $table->timestamp('submitted_at')->nullable()->index();
                $table->timestamp('reviewed_at')->nullable();
                $table->uuid('reviewed_by_usuario_id')->nullable()->index();
                $table->timestamps();

                $table->foreign('personal_existente_id')->references('id')->on('personal')->nullOnDelete();
                $table->foreign('personal_creado_id')->references('id')->on('personal')->nullOnDelete();
                $table->foreign('reviewed_by_usuario_id')->references('id')->on('usuarios')->nullOnDelete();
            });
        }

        if (!Schema::hasTable('personal_ingreso_archivos')) {
            Schema::create('personal_ingreso_archivos', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('personal_ingreso_id')->index();
                $table->string('tipo', 80)->index();
                $table->string('nombre_original');
                $table->string('path');
                $table->string('mime')->nullable();
                $table->unsignedBigInteger('size')->default(0);
                $table->timestamps();

                $table->foreign('personal_ingreso_id')->references('id')->on('personal_ingresos')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_ingreso_archivos');
        Schema::dropIfExists('personal_ingresos');
        Schema::dropIfExists('personal_ingreso_claves');

        if (Schema::hasTable('personal') && Schema::hasColumn('personal', 'pendiente_contrato_firmado')) {
            Schema::table('personal', function (Blueprint $table): void {
                $table->dropColumn('pendiente_contrato_firmado');
            });
        }
    }
};
