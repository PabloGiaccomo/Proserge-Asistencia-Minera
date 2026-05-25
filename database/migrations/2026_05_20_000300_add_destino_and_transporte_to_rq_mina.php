<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rq_mina', function (Blueprint $table): void {
            if (!Schema::hasColumn('rq_mina', 'destino_tipo')) {
                $table->string('destino_tipo', 20)->nullable()->after('mina_id')->index();
            }

            if (!Schema::hasColumn('rq_mina', 'destino_id')) {
                $table->char('destino_id', 36)->nullable()->after('destino_tipo')->index();
            }

            if (!Schema::hasColumn('rq_mina', 'destino_nombre')) {
                $table->string('destino_nombre', 191)->nullable()->after('destino_id');
            }
        });

        DB::statement("
            UPDATE rq_mina rm
            LEFT JOIN minas m ON m.id = rm.mina_id
            SET
                rm.destino_tipo = COALESCE(rm.destino_tipo, 'MINA'),
                rm.destino_id = COALESCE(rm.destino_id, rm.mina_id),
                rm.destino_nombre = COALESCE(rm.destino_nombre, m.nombre)
            WHERE rm.destino_tipo IS NULL
               OR rm.destino_id IS NULL
               OR rm.destino_nombre IS NULL
        ");

        if (!Schema::hasTable('rq_mina_transporte_detalle')) {
            Schema::create('rq_mina_transporte_detalle', function (Blueprint $table): void {
                $table->char('id', 36)->primary();
                $table->char('rq_mina_id', 36);
                $table->string('transporte', 191);
                $table->integer('cantidad');
                $table->timestamps();

                $table->index('rq_mina_id');
                $table->foreign('rq_mina_id')
                    ->references('id')
                    ->on('rq_mina')
                    ->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('rq_mina_transporte_detalle');

        Schema::table('rq_mina', function (Blueprint $table): void {
            if (Schema::hasColumn('rq_mina', 'destino_nombre')) {
                $table->dropColumn('destino_nombre');
            }

            if (Schema::hasColumn('rq_mina', 'destino_id')) {
                $table->dropIndex(['destino_id']);
                $table->dropColumn('destino_id');
            }

            if (Schema::hasColumn('rq_mina', 'destino_tipo')) {
                $table->dropIndex(['destino_tipo']);
                $table->dropColumn('destino_tipo');
            }
        });
    }
};
