# Notificaciones

Emision de eventos, gestion de bandeja y purga programada de notificaciones.

## Procesos

| Proceso | BPMN | Visual | Alcance |
|---|---|---|---|
| Vencer y purgar notificaciones | [BPMN](./limpiar-notificaciones-vencidas.bpmn) | [SVG](./limpiar-notificaciones-vencidas.svg) | Resolver plazo de purga: 15 dias por defecto. UNREAD a EXPIRED si expires_at es anterior a ahora. Eliminar destinatarios de eventos vencidos hace mas del plazo. Eliminar eventos vencidos antiguos sin destinatarios. Informar marcados y eliminados; SUCCESS |
| Emitir notificacion operativa | [BPMN](./emitir-notificacion.bpmn) | [SVG](./emitir-notificacion.svg) | Un modulo emite un evento, resuelve destinatarios y guarda copias; el consumo posterior es independiente. |
| Gestionar bandeja personal | [BPMN](./gestionar-bandeja.bpmn) | [SVG](./gestionar-bandeja.svg) | El usuario autenticado consulta sus copias y decide leer, abrir accion, marcar todas o archivar. |

## Actores y datos

- Modulo emisor
- Sistema
- Usuario destinatario

## Evidencia principal

- `routes/console.php`
- `app/Console/Commands/NotificationsCleanupExpiredCommand.php::handle`
- `app/Modules/Notificaciones/Services/NotificationService.php`
- `app/Modules/Notificaciones/Services/NotificationRecipientResolverService.php`
- `routes/web.php`
- `routes/api.php`
- `app/Modules/Notificaciones/Controllers/NotificacionPageController.php`
- `app/Modules/Notificaciones/Controllers/NotificacionController.php`
- `app/Modules/Notificaciones/Services/NotificationInboxService.php`

## Duplicidades o inconsistencias

- La disponibilidad de tablas se comprueba dinamicamente y algunas emisiones se omiten si la infraestructura no esta lista.

## Por confirmar

- Catalogo de eventos que no pueden desactivarse por preferencia.
- Catalogo de eventos que no pueden desactivarse por preferencia.
