# RQ Proserge

Atencion de requerimientos recibidos mediante asignacion, retiro o reemplazo de personal.

## Procesos

| Proceso | BPMN | Visual | Alcance |
|---|---|---|---|
| Asignar personal a RQ Proserge | [BPMN](./atender-requerimiento-personal.bpmn) | [SVG](./atender-requerimiento-personal.svg) | Busca trabajador disponible para un puesto y crea una asignacion activa con cobertura recalculada. |
| Actualizar asignacion de RQ Proserge | [BPMN](./actualizar-asignacion.bpmn) | [SVG](./actualizar-asignacion.svg) | Modifica fechas, posicion, tipo y comentario de una asignacion activa. Recalcula cobertura y estado. |
| Retirar asignacion de RQ Proserge | [BPMN](./retirar-asignacion.bpmn) | [SVG](./retirar-asignacion.svg) | Retira una asignacion activa sin borrar su historial; requiere motivo y actualiza cobertura. |
| Reemplazar asignacion de RQ Proserge | [BPMN](./reemplazar-asignacion.bpmn) | [SVG](./reemplazar-asignacion.svg) | En una transaccion marca original REEMPLAZADO y crea nueva ASIGNADO con referencia al original. |

## Actores y datos

- RR.HH./Operaciones
- Sistema

## Evidencia principal

- `app/Modules/RQProserge/Services/RQProsergeService.php`
- `app/Modules/RQProserge/Policies/RQProsergePolicy.php`
- `app/Modules/RQProserge/Services/RQProsergeCoverageService.php`
- `routes/web.php`
- `routes/api.php`

## Duplicidades o inconsistencias

- Las rutas legacy asignar/desasignar conviven con las operaciones auditables; unassignPersonal delega en retireAssignment y registra motivo heredado.

## Por confirmar

- Criterio organizacional final para declarar una atencion completa.
