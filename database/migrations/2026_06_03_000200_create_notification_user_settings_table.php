<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('notification_user_settings')) {
            return;
        }

        Schema::create('notification_user_settings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('usuario_id');
            $table->boolean('in_app_enabled')->default(true);
            $table->boolean('email_enabled')->default(false);
            $table->timestamp('muted_until')->nullable();
            $table->uuid('updated_by_usuario_id')->nullable();
            $table->timestamps();

            $table->unique('usuario_id', 'uq_notification_user_settings_usuario');
            $table->index(['in_app_enabled', 'muted_until'], 'idx_notification_user_settings_enabled');

            $table->foreign('usuario_id', 'fk_notification_user_settings_usuario')
                ->references('id')
                ->on('usuarios')
                ->cascadeOnDelete();

            $table->foreign('updated_by_usuario_id', 'fk_notification_user_settings_updated_by')
                ->references('id')
                ->on('usuarios')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_user_settings');
    }
};
