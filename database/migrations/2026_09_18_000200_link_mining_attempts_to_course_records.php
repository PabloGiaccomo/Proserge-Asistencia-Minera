<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('personal_mina_examen_intentos')
            || Schema::hasColumn('personal_mina_examen_intentos', 'personal_curso_registro_id')) {
            return;
        }

        Schema::table('personal_mina_examen_intentos', function (Blueprint $table): void {
            $table->uuid('personal_curso_registro_id')->nullable()->after('personal_mina_examen_id')->index();
            $table->unique(
                ['personal_mina_examen_id', 'personal_curso_registro_id'],
                'mina_intento_curso_registro_unique'
            );
        });
    }

    public function down(): void
    {
        // The source link is audit data and is intentionally retained.
    }
};
