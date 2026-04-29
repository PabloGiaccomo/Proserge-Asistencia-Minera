<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('personal_ficha_links') || Schema::hasColumn('personal_ficha_links', 'token_encrypted')) {
            return;
        }

        Schema::table('personal_ficha_links', function (Blueprint $table): void {
            $table->text('token_encrypted')->nullable()->after('token_hash');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('personal_ficha_links') || !Schema::hasColumn('personal_ficha_links', 'token_encrypted')) {
            return;
        }

        Schema::table('personal_ficha_links', function (Blueprint $table): void {
            $table->dropColumn('token_encrypted');
        });
    }
};
