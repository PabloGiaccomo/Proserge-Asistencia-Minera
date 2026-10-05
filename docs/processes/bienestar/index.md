# Bienestar

Registro, edicion y anulacion independientes de bloqueos y restricciones del trabajador.

## Procesos

| Proceso | BPMN | Visual | Alcance |
|---|---|---|---|
| Registrar bloqueo de bienestar | [BPMN](./registrar-bloqueo.bpmn) | [SVG](./registrar-bloqueo.svg) | Valida datos, fecha, tipo y trabajador; guarda ACTIVO y solicita notificacion. |
| Editar bloqueo de bienestar | [BPMN](./editar-bloqueo.bpmn) | [SVG](./editar-bloqueo.svg) | Actualiza tipo, motivo, detalle y fechas de un bloqueo existente; no cambia estado ni emite notificacion. |
| Anular bloqueo de bienestar | [BPMN](./anular-bloqueo.bpmn) | [SVG](./anular-bloqueo.svg) | Cambia el estado del bloqueo existente a ANULADO y solicita notificacion. |

## Actores y datos

- Bienestar/RR.HH.
- Sistema

## Evidencia principal

- `routes/web.php`
- `app/Modules/Bienestar/Controllers/BienestarPageController.php`
- `app/Modules/Notificaciones/Services/OperationalNotificationService.php`

## Duplicidades o inconsistencias

- El flujo esta concentrado en BienestarPageController, sin servicio, request o policy de dominio dedicado.
- Al registrar, si falta usuario valido en sesion se usa el primer Usuario de la tabla como actor; afecta trazabilidad.
- Al editar no se emite la notificacion que si se envia al registrar o anular.

## Por confirmar

- Catalogo y efecto operativo exacto de cada tipo de bloqueo sobre disponibilidad.
- Documentos obligatorios y roles autorizados por tipo.
