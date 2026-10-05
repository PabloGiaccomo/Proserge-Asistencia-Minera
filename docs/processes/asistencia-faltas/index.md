# Asistencia y faltas

Marcacion, cierre, reapertura y tratamiento de ausencias; cada accion tiene inicio propio.

## Procesos

| Proceso | BPMN | Visual | Alcance |
|---|---|---|---|
| Corregir, justificar o anular falta | [BPMN](./gestionar-falta.bpmn) | [SVG](./gestionar-falta.svg) | Revisa una falta originada por asistencia y actualiza o corrige el registro relacionado con trazabilidad. |
| Marcar ausencias por horario | [BPMN](./ausencias-por-horario.bpmn) | [SVG](./ausencias-por-horario.svg) | Seleccionar grupos PROGRAMADO de hoy sin asistencia cerrada. Por grupo: comprobar horario vencido y jornada actual. En transaccion: bloquear y revalidar; marcar pendientes AUSENTE. Recalcular metricas si hubo cambios; reportar errores y continuar. Informar cantidades; devolver FAILURE si hubo errores |
| Marcar asistencia de grupo | [BPMN](./marcar-asistencia.bpmn) | [SVG](./marcar-asistencia.svg) | Registra una marca individual o masiva para un grupo permitido y no cerrado; recalcula metricas. |
| Cerrar asistencia de grupo | [BPMN](./cerrar-asistencia.bpmn) | [SVG](./cerrar-asistencia.svg) | Cierra el encabezado, completa detalles faltantes como AUSENTE y crea faltas automaticas sin duplicarlas. |
| Reabrir asistencia de grupo | [BPMN](./reabrir-asistencia.bpmn) | [SVG](./reabrir-asistencia.svg) | Desde CERRADO devuelve a REGISTRADO y anula faltas automaticas activas relacionadas. |

## Actores y datos

- Responsable de grupo
- Usuario autorizado
- Sistema
- Proceso automatico

## Evidencia principal

- `app/Modules/Faltas/Services/FaltasService.php`
- `app/Modules/Faltas/Services/CorregirFaltaService.php`
- `app/Modules/Faltas/Policies/FaltasPolicy.php`
- `tests/Feature/FaltasApiTest.php`
- `tests/Feature/AsistenciaFaltasWebTest.php`
- `routes/console.php`
- `app/Console/Commands/AsistenciaAusentesPorHorarioCommand.php::handle`
- `app/Modules/Asistencia/Services/AsistenciaService.php::marcarAusentesPorHorario`
- `routes/web.php`
- `app/Modules/Asistencia/Services/AsistenciaService.php`
- `app/Modules/Asistencia/Policies/AsistenciaPolicy.php`
- `app/Modules/Asistencia/Services/AsistenciaCierreService.php`

## Duplicidades o inconsistencias

- AsistenciaService expone metodos directos y wrappers por identificador/usuario.
- La correccion de faltas se distribuye entre FaltasService y CorregirFaltaService.

## Por confirmar

- Quien puede reabrir operativamente una asistencia y bajo que motivo obligatorio.
