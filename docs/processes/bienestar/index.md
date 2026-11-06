# Bienestar

Registro, edicion y anulacion independientes de bloqueos y restricciones del trabajador.

## Procesos

| Proceso | BPMN | Visual | Alcance |
|---|---|---|---|
| Registrar bloqueo de bienestar | [BPMN](./registrar-bloqueo.bpmn) | [SVG](./registrar-bloqueo.svg) | Valida datos, fecha, tipo y trabajador; guarda ACTIVO y solicita notificacion. |
| Editar bloqueo de bienestar | [BPMN](./editar-bloqueo.bpmn) | [SVG](./editar-bloqueo.svg) | Actualiza tipo, motivo, detalle y fechas y registra la decisión de edición. |
| Anular bloqueo de bienestar | [BPMN](./anular-bloqueo.bpmn) | [SVG](./anular-bloqueo.svg) | Cambia el estado a ANULADO, registra la decisión y solicita una notificación. |

La edición y la anulación requieren un motivo de decisión separado del motivo del bloqueo. El historial conserva el usuario que tomó la decisión, la fecha y los datos anteriores y nuevos. Los motivos de decisión y los datos históricos se cifran. En la cartilla del trabajador, el historial reciente se muestra a usuarios con permiso de editar o anular. Una anulación repetida conserva el evento original.

## Actores y datos

- Bienestar/RR.HH.
- Sistema

## Evidencia principal

- `routes/web.php`
- `app/Modules/Bienestar/Controllers/BienestarPageController.php`
- `app/Modules/Bienestar/Services/BienestarBloqueoService.php`
- `app/Modules/Notificaciones/Services/OperationalNotificationService.php`

## Duplicidades o inconsistencias

- Al registrar, si falta usuario valido en sesion se usa el primer Usuario de la tabla como actor; afecta trazabilidad.
- Al editar no se emite la notificacion que si se envia al registrar o anular.

## Por confirmar

- Catalogo y efecto operativo exacto de cada tipo de bloqueo sobre disponibilidad.
- Documentos obligatorios y roles autorizados por tipo.
