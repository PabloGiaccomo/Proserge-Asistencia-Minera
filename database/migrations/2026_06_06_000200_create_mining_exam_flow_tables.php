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
        if (!Schema::hasTable('examenes_mineros')) {
            Schema::create('examenes_mineros', function (Blueprint $table): void {
                $table->char('id', 36)->primary();
                $table->string('nombre', 191);
                $table->text('descripcion')->nullable();
                $table->string('tipo', 80)->nullable();
                $table->string('lugar', 191)->nullable();
                $table->decimal('precio', 12, 2)->nullable();
                $table->string('moneda', 10)->nullable();
                $table->boolean('tiene_vigencia')->default(false);
                $table->unsignedInteger('vigencia_dias')->nullable();
                $table->boolean('permite_reintento')->default(true);
                $table->unsignedTinyInteger('max_intentos')->default(2);
                $table->boolean('critico')->default(false);
                $table->boolean('desaprueba_finaliza_proceso')->default(false);
                $table->boolean('requiere_nota')->default(false);
                $table->decimal('nota_minima', 8, 2)->nullable();
                $table->boolean('solo_resultado')->default(true);
                $table->boolean('permite_convalidacion')->default(false);
                $table->text('observacion')->nullable();
                $table->boolean('activo')->default(true);
                $table->unsignedInteger('orden')->default(0);
                $table->char('created_by_usuario_id', 36)->nullable();
                $table->char('updated_by_usuario_id', 36)->nullable();
                $table->timestamps();

                $table->unique(['nombre', 'activo'], 'uq_examen_minero_nombre_activo');
                $table->index(['activo', 'orden']);
                $table->foreign('created_by_usuario_id')->references('id')->on('usuarios')->nullOnDelete();
                $table->foreign('updated_by_usuario_id')->references('id')->on('usuarios')->nullOnDelete();
            });
        }

        if (Schema::hasTable('mina_requisitos')) {
            Schema::table('mina_requisitos', function (Blueprint $table): void {
                if (!Schema::hasColumn('mina_requisitos', 'examen_id')) {
                    $table->char('examen_id', 36)->nullable()->after('mina_id');
                }
                if (!Schema::hasColumn('mina_requisitos', 'permite_no_aplica')) {
                    $table->boolean('permite_no_aplica')->default(true)->after('orden');
                }
                if (!Schema::hasColumn('mina_requisitos', 'permite_convalidacion_mina')) {
                    $table->boolean('permite_convalidacion_mina')->default(false)->after('permite_no_aplica');
                }
                if (!Schema::hasColumn('mina_requisitos', 'fecha_inicio_convalidacion')) {
                    $table->date('fecha_inicio_convalidacion')->nullable()->after('permite_convalidacion_mina');
                }
                if (!Schema::hasColumn('mina_requisitos', 'fecha_fin_convalidacion')) {
                    $table->date('fecha_fin_convalidacion')->nullable()->after('fecha_inicio_convalidacion');
                }
                if (!Schema::hasColumn('mina_requisitos', 'convalidar_desde_otras_minas')) {
                    $table->boolean('convalidar_desde_otras_minas')->default(false)->after('fecha_fin_convalidacion');
                }
                if (!Schema::hasColumn('mina_requisitos', 'minas_origen_convalidacion_json')) {
                    $table->json('minas_origen_convalidacion_json')->nullable()->after('convalidar_desde_otras_minas');
                }
                if (!Schema::hasColumn('mina_requisitos', 'vigencia_dias_override')) {
                    $table->unsignedInteger('vigencia_dias_override')->nullable()->after('minas_origen_convalidacion_json');
                }
                if (!Schema::hasColumn('mina_requisitos', 'observacion_mina')) {
                    $table->text('observacion_mina')->nullable()->after('vigencia_dias_override');
                }
            });

            if (Schema::hasTable('examenes_mineros')) {
                $this->backfillExamCatalogFromRequirements();
            }
        }

        if (!Schema::hasTable('personal_mina_examenes')) {
            Schema::create('personal_mina_examenes', function (Blueprint $table): void {
                $table->char('id', 36)->primary();
                $table->char('personal_mina_id', 36);
                $table->char('mina_requisito_id', 36)->nullable();
                $table->char('examen_id', 36);
                $table->string('nombre_snapshot', 191);
                $table->string('lugar_snapshot', 191)->nullable();
                $table->decimal('precio_snapshot', 12, 2)->nullable();
                $table->boolean('tiene_vigencia_snapshot')->default(false);
                $table->unsignedInteger('vigencia_dias_snapshot')->nullable();
                $table->boolean('obligatorio_snapshot')->default(true);
                $table->boolean('critico_snapshot')->default(false);
                $table->boolean('permite_reintento_snapshot')->default(true);
                $table->unsignedTinyInteger('max_intentos_snapshot')->default(2);
                $table->boolean('requiere_nota_snapshot')->default(false);
                $table->decimal('nota_minima_snapshot', 8, 2)->nullable();
                $table->string('estado', 40)->default('PENDIENTE');
                $table->string('resultado', 40)->nullable();
                $table->decimal('nota_obtenida', 8, 2)->nullable();
                $table->date('fecha_programacion')->nullable();
                $table->date('fecha_realizacion')->nullable();
                $table->date('fecha_vencimiento')->nullable();
                $table->boolean('es_convalidado')->default(false);
                $table->char('examen_origen_convalidado_id', 36)->nullable();
                $table->char('mina_origen_convalidacion_id', 36)->nullable();
                $table->date('fecha_aprobacion_origen')->nullable();
                $table->timestamp('fecha_convalidacion')->nullable();
                $table->char('usuario_convalidacion_id', 36)->nullable();
                $table->text('observacion')->nullable();
                $table->char('usuario_actualizacion_id', 36)->nullable();
                $table->timestamp('fecha_actualizacion')->nullable();
                $table->timestamps();

                $table->unique(['personal_mina_id', 'examen_id'], 'uq_personal_mina_examen');
                $table->index(['personal_mina_id', 'estado']);
                $table->index('examen_id');
                $table->foreign('personal_mina_id')->references('id')->on('personal_mina')->cascadeOnDelete();
                $table->foreign('mina_requisito_id')->references('id')->on('mina_requisitos')->nullOnDelete();
                $table->foreign('examen_id')->references('id')->on('examenes_mineros');
                $table->foreign('examen_origen_convalidado_id')->references('id')->on('personal_mina_examenes')->nullOnDelete();
                $table->foreign('mina_origen_convalidacion_id')->references('id')->on('minas')->nullOnDelete();
                $table->foreign('usuario_convalidacion_id')->references('id')->on('usuarios')->nullOnDelete();
                $table->foreign('usuario_actualizacion_id')->references('id')->on('usuarios')->nullOnDelete();
            });
        }

        if (!Schema::hasTable('personal_mina_examen_intentos')) {
            Schema::create('personal_mina_examen_intentos', function (Blueprint $table): void {
                $table->char('id', 36)->primary();
                $table->char('personal_mina_examen_id', 36);
                $table->unsignedTinyInteger('numero_intento');
                $table->date('fecha_programacion')->nullable();
                $table->date('fecha_realizacion')->nullable();
                $table->string('resultado', 40)->default('PENDIENTE');
                $table->decimal('nota', 8, 2)->nullable();
                $table->string('archivo_path', 500)->nullable();
                $table->string('archivo_nombre_original', 191)->nullable();
                $table->string('archivo_mime', 120)->nullable();
                $table->unsignedBigInteger('archivo_size')->nullable();
                $table->text('observacion')->nullable();
                $table->char('usuario_registro_id', 36)->nullable();
                $table->timestamps();

                $table->unique(['personal_mina_examen_id', 'numero_intento'], 'uq_examen_intento_numero');
                $table->foreign('personal_mina_examen_id')->references('id')->on('personal_mina_examenes')->cascadeOnDelete();
                $table->foreign('usuario_registro_id')->references('id')->on('usuarios')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_mina_examen_intentos');
        Schema::dropIfExists('personal_mina_examenes');

        if (Schema::hasTable('mina_requisitos')) {
            Schema::table('mina_requisitos', function (Blueprint $table): void {
                foreach ([
                    'observacion_mina',
                    'vigencia_dias_override',
                    'minas_origen_convalidacion_json',
                    'convalidar_desde_otras_minas',
                    'fecha_fin_convalidacion',
                    'fecha_inicio_convalidacion',
                    'permite_convalidacion_mina',
                    'permite_no_aplica',
                    'examen_id',
                ] as $column) {
                    if (Schema::hasColumn('mina_requisitos', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        Schema::dropIfExists('examenes_mineros');
    }

    private function backfillExamCatalogFromRequirements(): void
    {
        $requirements = DB::table('mina_requisitos')
            ->whereNull('examen_id')
            ->get();

        foreach ($requirements as $requirement) {
            $name = trim((string) $requirement->nombre);
            if ($name === '') {
                continue;
            }

            $existing = DB::table('examenes_mineros')
                ->whereRaw('LOWER(TRIM(nombre)) = ?', [mb_strtolower($name)])
                ->where('activo', true)
                ->first();

            $examId = $existing?->id ?: (string) Str::uuid();

            if (!$existing) {
                DB::table('examenes_mineros')->insert([
                    'id' => $examId,
                    'nombre' => $name,
                    'descripcion' => $requirement->descripcion,
                    'tipo' => $requirement->tipo,
                    'tiene_vigencia' => $requirement->vigencia_dias !== null,
                    'vigencia_dias' => $requirement->vigencia_dias,
                    'permite_reintento' => (bool) $requirement->reprogramable,
                    'max_intentos' => (bool) $requirement->reprogramable ? 2 : 1,
                    'critico' => (bool) $requirement->critico,
                    'desaprueba_finaliza_proceso' => (bool) $requirement->critico && !(bool) $requirement->reprogramable,
                    'activo' => true,
                    'orden' => (int) ($requirement->orden ?? 0),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('mina_requisitos')
                ->where('id', $requirement->id)
                ->update([
                    'examen_id' => $examId,
                    'permite_convalidacion_mina' => false,
                    'permite_no_aplica' => true,
                    'updated_at' => now(),
                ]);
        }
    }
};
