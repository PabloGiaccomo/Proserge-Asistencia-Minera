<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('personal_ingresos') || Schema::hasColumn('personal_ingresos', 'submission_uuid')) {
            return;
        }

        Schema::table('personal_ingresos', function (Blueprint $table): void {
            $table->string('submission_uuid', 64)
                ->nullable()
                ->unique('uq_personal_ingresos_submission_uuid')
                ->after('id');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('personal_ingresos') || !Schema::hasColumn('personal_ingresos', 'submission_uuid')) {
            return;
        }

        Schema::table('personal_ingresos', function (Blueprint $table): void {
            $table->dropUnique('uq_personal_ingresos_submission_uuid');
            $table->dropColumn('submission_uuid');
        });
    }
};
