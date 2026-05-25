<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('notification_role_preferences')) {
            Schema::create('notification_role_preferences', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('rol_id');
                $table->uuid('notification_type_id');
                $table->boolean('is_enabled')->default(true);
                $table->timestamps();

                $table->unique(['rol_id', 'notification_type_id'], 'uq_notif_role_pref_role_type');
                $table->index('rol_id', 'idx_notif_role_pref_role');
                $table->index('notification_type_id', 'idx_notif_role_pref_type');

                $table->foreign('rol_id', 'fk_notif_role_pref_rol')
                    ->references('id')
                    ->on('roles')
                    ->cascadeOnDelete();
                $table->foreign('notification_type_id', 'fk_notif_role_pref_type')
                    ->references('id')
                    ->on('notification_types')
                    ->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_role_preferences');
    }
};
