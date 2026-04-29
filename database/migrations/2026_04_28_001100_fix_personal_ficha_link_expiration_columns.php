<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('personal_ficha_links')) {
            return;
        }

        DB::statement('ALTER TABLE personal_ficha_links MODIFY expires_at DATETIME NOT NULL');
        DB::statement('ALTER TABLE personal_ficha_links MODIFY read_until DATETIME NULL');
        DB::statement('ALTER TABLE personal_ficha_links MODIFY submitted_at DATETIME NULL');
        DB::statement('ALTER TABLE personal_ficha_links MODIFY disabled_at DATETIME NULL');
        DB::statement('ALTER TABLE personal_ficha_links MODIFY last_accessed_at DATETIME NULL');
    }

    public function down(): void
    {
        if (!Schema::hasTable('personal_ficha_links')) {
            return;
        }

        DB::statement('ALTER TABLE personal_ficha_links MODIFY expires_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
        DB::statement('ALTER TABLE personal_ficha_links MODIFY read_until TIMESTAMP NULL');
        DB::statement('ALTER TABLE personal_ficha_links MODIFY submitted_at TIMESTAMP NULL');
        DB::statement('ALTER TABLE personal_ficha_links MODIFY disabled_at TIMESTAMP NULL');
        DB::statement('ALTER TABLE personal_ficha_links MODIFY last_accessed_at TIMESTAMP NULL');
    }
};
