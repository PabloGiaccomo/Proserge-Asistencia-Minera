<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('rq_mina_field_options')) {
            Schema::create('rq_mina_field_options', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('field_key', 120);
                $table->text('value');
                $table->string('value_normalized', 191);
                $table->unsignedInteger('usage_count')->default(1);
                $table->uuid('created_by_usuario_id')->nullable();
                $table->timestamps();

                $table->unique(['field_key', 'value_normalized'], 'rq_mina_field_options_unique_value');
                $table->index(['field_key', 'usage_count'], 'rq_mina_field_options_field_usage');
                $table->foreign('created_by_usuario_id', 'rq_mina_field_options_usuario_fk')
                    ->references('id')
                    ->on('usuarios')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('rq_mina_field_options');
    }
};
