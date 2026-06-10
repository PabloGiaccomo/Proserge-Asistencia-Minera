<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('personal')) {
            Schema::table('personal', function (Blueprint $table): void {
                if (!Schema::hasColumn('personal', 'origen_registro')) {
                    $table->string('origen_registro', 30)->default('NUEVO')->after('estado');
                }

                if (!Schema::hasColumn('personal', 'observacion_historica')) {
                    $table->text('observacion_historica')->nullable()->after('origen_registro');
                }

                if (!Schema::hasColumn('personal', 'registrado_como_antiguo_at')) {
                    $table->timestamp('registrado_como_antiguo_at')->nullable()->after('observacion_historica');
                }

                if (!Schema::hasColumn('personal', 'registrado_como_antiguo_by_usuario_id')) {
                    $table->uuid('registrado_como_antiguo_by_usuario_id')->nullable()->after('registrado_como_antiguo_at');
                }
            });
        }

        if (Schema::hasTable('personal_contratos')) {
            Schema::table('personal_contratos', function (Blueprint $table): void {
                if (!Schema::hasColumn('personal_contratos', 'origen_registro')) {
                    $table->string('origen_registro', 30)->default('NUEVO')->after('estado');
                }

                if (!Schema::hasColumn('personal_contratos', 'es_historico')) {
                    $table->boolean('es_historico')->default(false)->after('origen_registro');
                }

                if (!Schema::hasColumn('personal_contratos', 'archivo_pendiente_regularizacion')) {
                    $table->boolean('archivo_pendiente_regularizacion')->default(false)->after('es_historico');
                }

                if (!Schema::hasColumn('personal_contratos', 'observacion_historica')) {
                    $table->text('observacion_historica')->nullable()->after('archivo_pendiente_regularizacion');
                }

                if (!Schema::hasColumn('personal_contratos', 'tipo_contrato')) {
                    $table->string('tipo_contrato', 40)->nullable()->after('observacion_historica');
                }

                if (!Schema::hasColumn('personal_contratos', 'puesto')) {
                    $table->string('puesto', 191)->nullable()->after('tipo_contrato');
                }

                if (!Schema::hasColumn('personal_contratos', 'area')) {
                    $table->string('area', 191)->nullable()->after('puesto');
                }

                if (!Schema::hasColumn('personal_contratos', 'mina')) {
                    $table->string('mina', 191)->nullable()->after('area');
                }

                if (!Schema::hasColumn('personal_contratos', 'remuneracion')) {
                    $table->string('remuneracion', 120)->nullable()->after('mina');
                }

                if (!Schema::hasColumn('personal_contratos', 'costo_hora')) {
                    $table->string('costo_hora', 120)->nullable()->after('remuneracion');
                }

                if (!Schema::hasColumn('personal_contratos', 'es_supervisor')) {
                    $table->boolean('es_supervisor')->nullable()->after('costo_hora');
                }

                if (!Schema::hasColumn('personal_contratos', 'registrado_by_usuario_id')) {
                    $table->uuid('registrado_by_usuario_id')->nullable()->after('es_supervisor');
                }
            });

            Schema::table('personal_contratos', function (Blueprint $table): void {
                if (!$this->foreignKeyExists('personal_contratos', 'fk_personal_contrato_registrado_by')) {
                    $table->foreign('registrado_by_usuario_id', 'fk_personal_contrato_registrado_by')
                        ->references('id')
                        ->on('usuarios')
                        ->nullOnDelete();
                }
            });
        }

        if (Schema::hasTable('personal') && !$this->foreignKeyExists('personal', 'fk_personal_antiguo_registrado_by')) {
            Schema::table('personal', function (Blueprint $table): void {
                $table->foreign('registrado_como_antiguo_by_usuario_id', 'fk_personal_antiguo_registrado_by')
                    ->references('id')
                    ->on('usuarios')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('personal_contratos')) {
            Schema::table('personal_contratos', function (Blueprint $table): void {
                if ($this->foreignKeyExists('personal_contratos', 'fk_personal_contrato_registrado_by')) {
                    $table->dropForeign('fk_personal_contrato_registrado_by');
                }
            });

            Schema::table('personal_contratos', function (Blueprint $table): void {
                foreach ([
                    'registrado_by_usuario_id',
                    'es_supervisor',
                    'costo_hora',
                    'remuneracion',
                    'mina',
                    'area',
                    'puesto',
                    'tipo_contrato',
                    'observacion_historica',
                    'archivo_pendiente_regularizacion',
                    'es_historico',
                    'origen_registro',
                ] as $column) {
                    if (Schema::hasColumn('personal_contratos', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('personal')) {
            Schema::table('personal', function (Blueprint $table): void {
                if ($this->foreignKeyExists('personal', 'fk_personal_antiguo_registrado_by')) {
                    $table->dropForeign('fk_personal_antiguo_registrado_by');
                }
            });

            Schema::table('personal', function (Blueprint $table): void {
                foreach ([
                    'registrado_como_antiguo_by_usuario_id',
                    'registrado_como_antiguo_at',
                    'observacion_historica',
                    'origen_registro',
                ] as $column) {
                    if (Schema::hasColumn('personal', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }

    private function foreignKeyExists(string $table, string $constraint): bool
    {
        $database = Schema::getConnection()->getDatabaseName();

        return Schema::getConnection()
            ->table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', $database)
            ->where('TABLE_NAME', $table)
            ->where('CONSTRAINT_NAME', $constraint)
            ->exists();
    }
};
