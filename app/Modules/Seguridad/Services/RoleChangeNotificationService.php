<?php

namespace App\Modules\Seguridad\Services;

use App\Models\RolCambioEvento;
use App\Modules\Notificaciones\Services\NotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RoleChangeNotificationService
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function deliver(string $id): void
    {
        try {
            DB::transaction(function () use ($id): void {
                $event = RolCambioEvento::query()->lockForUpdate()->findOrFail($id);
                if ($event->notificaciones_estado !== 'PENDIENTE') {
                    return;
                }
                $types = $event->permisos_cambiados ? ['rol_modificado', 'permisos_modificados'] : ['rol_modificado'];
                $results = [];
                foreach ($types as $type) {
                    $notification = $this->notifications->emit($type, [
                        'actor_user_id' => $event->actor_usuario_id,
                        'entity_type' => 'rol', 'entity_id' => $event->rol_id,
                        'title' => $type === 'rol_modificado' ? 'Rol actualizado' : 'Permisos de rol modificados',
                        'message' => 'Se actualizó el rol '.$event->despues_json['nombre'].'.',
                        'priority' => 'critical', 'category' => 'seguridad',
                        'dedupe_key' => $type.':cambio:'.$event->id,
                    ]);
                    $results[$type] = $notification ? 'EMITIDO' : 'SIN_DESTINATARIOS';
                }
                $event->forceFill([
                    'notificaciones_estado' => in_array('EMITIDO', $results, true) ? 'EMITIDO' : 'SIN_DESTINATARIOS',
                    'avisos_json' => $results, 'intentos' => $event->intentos + 1, 'proximo_intento_at' => null,
                ])->save();
            });
        } catch (\Throwable $exception) {
            Log::warning('seguridad.rol_aviso_pendiente', ['evento_id' => $id, 'exception' => $exception::class]);
            try {
                RolCambioEvento::query()->whereKey($id)->where('notificaciones_estado', 'PENDIENTE')->update([
                    'intentos' => DB::raw('intentos + 1'), 'proximo_intento_at' => now()->addMinutes(5),
                ]);
            } catch (\Throwable) {
                // The committed event remains pending for the next scheduled run.
            }
        }
    }
}
