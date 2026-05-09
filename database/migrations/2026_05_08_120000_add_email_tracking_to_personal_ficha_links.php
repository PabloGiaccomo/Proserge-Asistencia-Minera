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
            if (!Schema::hasColumn('personal_ficha_links', 'emailed_at')) {
                $table->timestamp('emailed_at')->nullable()->after('last_accessed_at');
            }

            if (!Schema::hasColumn('personal_ficha_links', 'emailed_to')) {
                $table->string('emailed_to', 191)->nullable()->after('emailed_at');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('personal_ficha_links')) {
            return;
        }

        Schema::table('personal_ficha_links', function (Blueprint $table): void {
            if (Schema::hasColumn('personal_ficha_links', 'emailed_to')) {
                $table->dropColumn('emailed_to');
            }

            if (Schema::hasColumn('personal_ficha_links', 'emailed_at')) {
                $table->dropColumn('emailed_at');
            }
        });
    }
};
