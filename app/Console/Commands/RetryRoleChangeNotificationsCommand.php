<?php

namespace App\Console\Commands;

use App\Models\RolCambioEvento;
use App\Modules\Seguridad\Services\RoleChangeNotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class RetryRoleChangeNotificationsCommand extends Command
{
    protected $signature = 'roles:retry-change-notifications';

    protected $description = 'Reintenta avisos pendientes de cambios de rol con auditoría';

    public function handle(RoleChangeNotificationService $notifications): int
    {
        if (! Schema::hasTable('rol_cambio_eventos')) {
            return self::SUCCESS;
        }
        $events = RolCambioEvento::query()->where('notificaciones_estado', 'PENDIENTE')->where('intentos', '<', 10)
            ->where(fn ($query) => $query->whereNull('proximo_intento_at')->orWhere('proximo_intento_at', '<=', now()))
            ->orderBy('created_at')->limit(100)->get(['id']);
        foreach ($events as $event) {
            $notifications->deliver($event->id);
        }

        return self::SUCCESS;
    }
}
