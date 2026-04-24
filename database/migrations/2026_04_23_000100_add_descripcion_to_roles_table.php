<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('roles')) {
            return;
        }

        if (!Schema::hasColumn('roles', 'descripcion')) {
            Schema::table('roles', function (Blueprint $table): void {
                $table->string('descripcion', 255)->nullable()->after('nombre');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('roles') || !Schema::hasColumn('roles', 'descripcion')) {
            return;
        }

        Schema::table('roles', function (Blueprint $table): void {
            $table->dropColumn('descripcion');
        });
    }
};
