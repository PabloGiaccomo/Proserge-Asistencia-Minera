<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('epp_inventario_confirmaciones')) {
            Schema::create('epp_inventario_confirmaciones', function (Blueprint $table): void {
                $table->char('id', 36)->primary(); // The preview token is also the idempotency key.
                $table->char('actor_usuario_id', 36)->index();
                $table->string('accion', 20);
                $table->json('payload_json');
                $table->dateTime('expires_at')->index();
                $table->dateTime('confirmado_at')->nullable();
                $table->json('resultado_json')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        // Consumed keys must survive rollback to prevent inventory replays.
    }
};
