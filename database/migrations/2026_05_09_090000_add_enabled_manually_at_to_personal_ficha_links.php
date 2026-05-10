<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('personal_ficha_links')) {
            return;
        }

        Schema::table('personal_ficha_links', function (Blueprint $table): void {
            if (!Schema::hasColumn('personal_ficha_links', 'enabled_manually_at')) {
                $table->timestamp('enabled_manually_at')->nullable()->after('disabled_at');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('personal_ficha_links') || !Schema::hasColumn('personal_ficha_links', 'enabled_manually_at')) {
            return;
        }

        Schema::table('personal_ficha_links', function (Blueprint $table): void {
            $table->dropColumn('enabled_manually_at');
        });
    }
};
