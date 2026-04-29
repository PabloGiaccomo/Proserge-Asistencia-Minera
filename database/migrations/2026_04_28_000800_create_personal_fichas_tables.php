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
        Schema::table('personal', function (Blueprint $table): void {
            if (!Schema::hasColumn('personal', 'tipo_documento')) {
                $table->string('tipo_documento', 40)->nullable()->after('dni');
            }

            if (!Schema::hasColumn('personal', 'numero_documento')) {
                $table->string('numero_documento', 40)->nullable()->after('tipo_documento');
            }
        });

        DB::statement("ALTER TABLE personal MODIFY estado VARCHAR(40) NOT NULL DEFAULT 'ACTIVO'");

        DB::table('personal')
            ->whereNull('tipo_documento')
            ->update(['tipo_documento' => 'DNI']);

        DB::table('personal')
            ->whereNull('numero_documento')
            ->update(['numero_documento' => DB::raw('dni')]);

        if (!Schema::hasTable('personal_fichas')) {
            Schema::create('personal_fichas', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('personal_id');
                $table->string('estado', 40)->default('PENDIENTE_COMPLETAR_FICHA');
                $table->string('tipo_documento', 40);
                $table->string('numero_documento', 40);
                $table->string('macro_tipo_contrato', 80)->nullable();
                $table->string('macro_original_nombre', 191)->nullable();
                $table->string('macro_original_path', 500)->nullable();
                $table->json('datos_detectados_json')->nullable();
                $table->json('datos_json')->nullable();
                $table->json('campos_verificacion_json')->nullable();
                $table->json('advertencias_json')->nullable();
                $table->longText('firma_base64')->nullable();
                $table->string('huella_path', 500)->nullable();
                $table->uuid('created_by_usuario_id')->nullable();
                $table->timestamp('submitted_at')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->uuid('approved_by_usuario_id')->nullable();
                $table->timestamp('observed_at')->nullable();
                $table->timestamp('rejected_at')->nullable();
                $table->text('observaciones_revision')->nullable();
                $table->timestamps();

                $table->index(['personal_id', 'estado'], 'idx_personal_fichas_personal_estado');
                $table->index(['tipo_documento', 'numero_documento'], 'idx_personal_fichas_documento');
                $table->index('estado', 'idx_personal_fichas_estado');

                $table->foreign('personal_id', 'fk_personal_fichas_personal')
                    ->references('id')
                    ->on('personal')
                    ->cascadeOnDelete();
                $table->foreign('created_by_usuario_id', 'fk_personal_fichas_creador')
                    ->references('id')
                    ->on('usuarios')
                    ->nullOnDelete();
                $table->foreign('approved_by_usuario_id', 'fk_personal_fichas_aprobador')
                    ->references('id')
                    ->on('usuarios')
                    ->nullOnDelete();
            });
        }

        if (!Schema::hasTable('personal_ficha_links')) {
            Schema::create('personal_ficha_links', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('personal_ficha_id');
                $table->char('token_hash', 64)->unique();
                $table->string('estado', 30)->default('ACTIVO');
                $table->timestamp('expires_at');
                $table->timestamp('read_until')->nullable();
                $table->timestamp('submitted_at')->nullable();
                $table->timestamp('disabled_at')->nullable();
                $table->timestamp('last_accessed_at')->nullable();
                $table->timestamps();

                $table->index(['personal_ficha_id', 'estado'], 'idx_ficha_links_ficha_estado');
                $table->index('expires_at', 'idx_ficha_links_expires');
                $table->index('read_until', 'idx_ficha_links_read_until');

                $table->foreign('personal_ficha_id', 'fk_ficha_links_ficha')
                    ->references('id')
                    ->on('personal_fichas')
                    ->cascadeOnDelete();
            });
        }

        if (!Schema::hasTable('personal_ficha_familiares')) {
            Schema::create('personal_ficha_familiares', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('personal_ficha_id');
                $table->string('nombres_apellidos', 191);
                $table->string('parentesco', 80)->nullable();
                $table->string('tipo_documento', 40)->nullable();
                $table->string('numero_documento', 40)->nullable();
                $table->string('telefono', 30)->nullable();
                $table->boolean('vive_con_trabajador')->default(false);
                $table->boolean('contacto_emergencia')->default(false);
                $table->timestamps();

                $table->index('personal_ficha_id', 'idx_ficha_familiares_ficha');

                $table->foreign('personal_ficha_id', 'fk_ficha_familiares_ficha')
                    ->references('id')
                    ->on('personal_fichas')
                    ->cascadeOnDelete();
            });
        }

        if (!Schema::hasTable('personal_ficha_archivos')) {
            Schema::create('personal_ficha_archivos', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('personal_ficha_id');
                $table->string('tipo', 40);
                $table->string('nombre_original', 191)->nullable();
                $table->string('path', 500);
                $table->string('mime', 120)->nullable();
                $table->unsignedBigInteger('size')->nullable();
                $table->uuid('uploaded_by_usuario_id')->nullable();
                $table->boolean('uploaded_by_public')->default(false);
                $table->timestamps();

                $table->index(['personal_ficha_id', 'tipo'], 'idx_ficha_archivos_tipo');

                $table->foreign('personal_ficha_id', 'fk_ficha_archivos_ficha')
                    ->references('id')
                    ->on('personal_fichas')
                    ->cascadeOnDelete();
                $table->foreign('uploaded_by_usuario_id', 'fk_ficha_archivos_usuario')
                    ->references('id')
                    ->on('usuarios')
                    ->nullOnDelete();
            });
        }

        if (Schema::hasTable('notification_types')) {
            DB::table('notification_types')->updateOrInsert(
                ['code' => 'personal_ficha_completada'],
                [
                    'id' => DB::table('notification_types')->where('code', 'personal_ficha_completada')->value('id') ?? (string) Str::uuid(),
                    'module' => 'personal',
                    'category' => 'accion_requerida',
                    'default_priority' => 'high',
                    'required_permission_module' => 'personal',
                    'required_permission_action' => 'editar',
                    'default_title' => 'Ficha de colaborador completada',
                    'default_action_label' => 'Revisar ficha',
                    'default_action_route' => '/personal/fichas/{entity_id}/revisar',
                    'is_active' => true,
                    'created_at' => DB::table('notification_types')->where('code', 'personal_ficha_completada')->value('created_at') ?? now(),
                    'updated_at' => now(),
                ],
            );
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('personal_ficha_archivos')) {
            Schema::drop('personal_ficha_archivos');
        }

        if (Schema::hasTable('personal_ficha_familiares')) {
            Schema::drop('personal_ficha_familiares');
        }

        if (Schema::hasTable('personal_ficha_links')) {
            Schema::drop('personal_ficha_links');
        }

        if (Schema::hasTable('personal_fichas')) {
            Schema::drop('personal_fichas');
        }

        Schema::table('personal', function (Blueprint $table): void {
            if (Schema::hasColumn('personal', 'numero_documento')) {
                $table->dropColumn('numero_documento');
            }

            if (Schema::hasColumn('personal', 'tipo_documento')) {
                $table->dropColumn('tipo_documento');
            }
        });
    }
};
