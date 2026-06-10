<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('personal_contratos')) {
            return;
        }

        Schema::table('personal_contratos', function (Blueprint $table): void {
            if (!Schema::hasColumn('personal_contratos', 'signed_at')) {
                $table->timestamp('signed_at')->nullable()->after('cerrado_by_usuario_id');
            }
            if (!Schema::hasColumn('personal_contratos', 'signed_by_usuario_id')) {
                $table->uuid('signed_by_usuario_id')->nullable()->after('signed_at');
            }
            if (!Schema::hasColumn('personal_contratos', 'signed_contract_path')) {
                $table->string('signed_contract_path', 500)->nullable()->after('signed_by_usuario_id');
            }
            if (!Schema::hasColumn('personal_contratos', 'signed_contract_original_name')) {
                $table->string('signed_contract_original_name', 191)->nullable()->after('signed_contract_path');
            }
            if (!Schema::hasColumn('personal_contratos', 'signed_contract_mime')) {
                $table->string('signed_contract_mime', 120)->nullable()->after('signed_contract_original_name');
            }
            if (!Schema::hasColumn('personal_contratos', 'signed_contract_size')) {
                $table->unsignedBigInteger('signed_contract_size')->nullable()->after('signed_contract_mime');
            }
            if (!Schema::hasColumn('personal_contratos', 'anulado_at')) {
                $table->timestamp('anulado_at')->nullable()->after('signed_contract_size');
            }
            if (!Schema::hasColumn('personal_contratos', 'anulado_by_usuario_id')) {
                $table->uuid('anulado_by_usuario_id')->nullable()->after('anulado_at');
            }
            if (!Schema::hasColumn('personal_contratos', 'motivo_anulacion')) {
                $table->text('motivo_anulacion')->nullable()->after('anulado_by_usuario_id');
            }
        });

        Schema::table('personal_contratos', function (Blueprint $table): void {
            if (!$this->foreignKeyExists('personal_contratos', 'fk_personal_contrato_signed_by')) {
                $table->foreign('signed_by_usuario_id', 'fk_personal_contrato_signed_by')
                    ->references('id')
                    ->on('usuarios')
                    ->nullOnDelete();
            }

            if (!$this->foreignKeyExists('personal_contratos', 'fk_personal_contrato_anulado_by')) {
                $table->foreign('anulado_by_usuario_id', 'fk_personal_contrato_anulado_by')
                    ->references('id')
                    ->on('usuarios')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('personal_contratos')) {
            return;
        }

        Schema::table('personal_contratos', function (Blueprint $table): void {
            if ($this->foreignKeyExists('personal_contratos', 'fk_personal_contrato_signed_by')) {
                $table->dropForeign('fk_personal_contrato_signed_by');
            }
            if ($this->foreignKeyExists('personal_contratos', 'fk_personal_contrato_anulado_by')) {
                $table->dropForeign('fk_personal_contrato_anulado_by');
            }
        });

        Schema::table('personal_contratos', function (Blueprint $table): void {
            foreach ([
                'motivo_anulacion',
                'anulado_by_usuario_id',
                'anulado_at',
                'signed_contract_size',
                'signed_contract_mime',
                'signed_contract_original_name',
                'signed_contract_path',
                'signed_by_usuario_id',
                'signed_at',
            ] as $column) {
                if (Schema::hasColumn('personal_contratos', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
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
