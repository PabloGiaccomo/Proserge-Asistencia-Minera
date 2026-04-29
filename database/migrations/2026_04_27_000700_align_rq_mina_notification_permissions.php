<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('notification_types')) {
            return;
        }

        DB::table('notification_types')
            ->where('code', 'rq_mina_enviado')
            ->update([
                'module' => 'rq_mina',
                'required_permission_module' => 'rq_proserge',
                'required_permission_action' => 'asignar',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        if (!Schema::hasTable('notification_types')) {
            return;
        }

        DB::table('notification_types')
            ->where('code', 'rq_mina_enviado')
            ->update([
                'required_permission_module' => 'rq_mina',
                'required_permission_action' => 'ver',
                'updated_at' => now(),
            ]);
    }
};
