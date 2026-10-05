<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('personal_curso_registros')) {
            return;
        }

        if (!Schema::hasColumn('personal_curso_registros', 'mina_id')) {
            Schema::table('personal_curso_registros', function (Blueprint $table): void {
                $table->uuid('mina_id')->nullable()->after('examen_id');
                $table->index('mina_id', 'idx_pcr_mina');
            });
        }

        $this->backfillUnambiguousMineOrigins();

        Schema::table('personal_curso_registros', function (Blueprint $table): void {
            if (Schema::hasIndex('personal_curso_registros', 'curso_programacion_unique')) {
                $table->dropUnique('curso_programacion_unique');
            }
            if (Schema::hasIndex('personal_curso_registros', 'curso_realizacion_unique')) {
                $table->dropUnique('curso_realizacion_unique');
            }
            if (!Schema::hasIndex('personal_curso_registros', 'curso_mina_programacion_unique')) {
                $table->unique(
                    ['personal_id', 'examen_id', 'mina_id', 'fecha_programacion'],
                    'curso_mina_programacion_unique',
                );
            }
            if (!Schema::hasIndex('personal_curso_registros', 'curso_mina_realizacion_unique')) {
                $table->unique(
                    ['personal_id', 'examen_id', 'mina_id', 'fecha_realizacion'],
                    'curso_mina_realizacion_unique',
                );
            }
        });
    }

    public function down(): void
    {
        // Mine origin is operational traceability and is intentionally retained.
    }

    private function backfillUnambiguousMineOrigins(): void
    {
        if (!Schema::hasTable('personal_mina_examen_intentos')
            || !Schema::hasColumn('personal_mina_examen_intentos', 'personal_curso_registro_id')) {
            return;
        }

        DB::table('personal_curso_registros')
            ->whereNull('mina_id')
            ->orderBy('id')
            ->chunkById(200, function ($records): void {
                foreach ($records as $record) {
                    $mineIds = DB::table('personal_mina_examen_intentos as attempt')
                        ->join('personal_mina_examenes as worker_exam', 'worker_exam.id', '=', 'attempt.personal_mina_examen_id')
                        ->join('personal_mina as assignment', 'assignment.id', '=', 'worker_exam.personal_mina_id')
                        ->where('attempt.personal_curso_registro_id', $record->id)
                        ->pluck('assignment.mina_id')
                        ->filter()
                        ->unique()
                        ->values();

                    if ($mineIds->count() === 1) {
                        DB::table('personal_curso_registros')
                            ->where('id', $record->id)
                            ->update(['mina_id' => $mineIds->first()]);
                    }
                }
            }, 'id');
    }
};
