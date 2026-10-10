<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('epp_operaciones', function (Blueprint $table): void {
            $table->char('almacen_id', 36)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Signed catalog deliveries have no warehouse. Do not invent one on rollback.
    }
};
