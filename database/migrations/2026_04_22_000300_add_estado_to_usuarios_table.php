<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('usuarios')) {
            return;
        }

        if (!Schema::hasColumn('usuarios', 'estado')) {
            Schema::table('usuarios', function (Blueprint $table): void {
                $table->string('estado', 20)->default('ACTIVO')->after('personal_id');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('usuarios') || !Schema::hasColumn('usuarios', 'estado')) {
            return;
        }

        Schema::table('usuarios', function (Blueprint $table): void {
            $table->dropColumn('estado');
        });
    }
};
