# Habilitacion minera

Asignaciones trabajador-mina, requisitos, intentos, estados y cargas masivas con trazabilidad.

## Procesos

| Proceso | BPMN | Visual | Alcance |
|---|---|---|---|
| Asignar trabajador y calcular habilitacion | [BPMN](./asignacion-y-estado.bpmn) | [SVG](./asignacion-y-estado.svg) | Asignar mina, generar requisitos y calcular HABILITADO, EN_PROCESO, OBSERVADO o NO_HABILITADO, sin cambiar el estado laboral. |
| Programar y resolver examen | [BPMN](./ciclo-examen-intento.bpmn) | [SVG](./ciclo-examen-intento.svg) | Programar, completar un intento pendiente o registrar resultado; distinguir no asistencia de desaprobacion y recalcular la asignacion. |
| Carga masiva de cursos y EMO | [BPMN](./carga-masiva-cursos-emo.bpmn) | [SVG](./carga-masiva-cursos-emo.svg) | Previsualiza programaciones/resultados, bloquea observaciones, confirma con token e idempotencia y aplica solo a la mina declarada. |
| Importacion de master minero | [BPMN](./importacion-master-minero.bpmn) | [SVG](./importacion-master-minero.svg) | Previsualiza y confirma configuracion/asignaciones de trabajadores existentes sin sobrescribir datos laborales sensibles. |
| Sincronizar vigencias mineras | [BPMN](./sincronizar-vigencias.bpmn) | [SVG](./sincronizar-vigencias.svg) | Cargar requisitos activos y asignaciones de minas operativas. Por asignacion: transaccion, bloqueo y recalculo temporal. Guardar estado e historial solo si cambia la asignacion. Capturar error por asignacion y continuar con las restantes. Informar contadores; FAILURE si errores mayor que cero |

## Actores y datos

- SSOMA/RR.HH. autorizado
- Sistema
- Proceso automatico
- Archivo Excel

## Evidencia principal

- `app/Modules/Personal/Services/PersonalMinaHabilitacionService.php`
- `app/Models/PersonalMina.php`
- `tests/Feature/PersonalMinaHabilitacionBaseTest.php`
- `tests/Feature/PersonalMinaHabilitacionCorrectedFlowTest.php`
- `app/Modules/Personal/Services/PersonalMinaHabilitacionService.php::registerAttempt`
- `app/Modules/Personal/Services/PersonalMinaHabilitacionService.php::completeScheduledAttempt`
- `app/Models/PersonalMinaExamenIntento.php`
- `tests/Feature/PersonalMinaExamenesMinerosTest.php`
- `docs/HABILITACION_CARGA_MASIVA.md`
- `app/Modules/Personal/Services/PersonalMinaBulkPreviewService.php`
- `app/Modules/Personal/Services/PersonalCursoImportPlanner.php`
- `app/Modules/Personal/Services/PersonalCursoBulkService.php`
- `tests/Unit/PersonalMinaBulkPreviewTest.php`
- `tests/Unit/PersonalCursoImportPlannerTest.php`
- `app/Modules/Personal/Services/PersonalMinaExcelImportService.php`
- `app/Modules/Personal/Controllers/PersonalMinaHabilitacionController.php`
- `docs/BUSINESS_RULES_RRHH.md`
- `routes/console.php`
- `app/Console/Commands/HabilitacionMineraSyncTemporalStatusesCommand.php::handle`
- `app/Modules/Personal/Services/PersonalMinaHabilitacionService.php::syncTemporalStatuses`

## Duplicidades o inconsistencias

- Conviven importacion de master de configuracion y carga masiva de cursos/EMO; sus fuentes de verdad y efectos son distintos.
- El servicio principal concentra catalogo, requisitos, intentos, convalidacion y recalculo.
- Regla confirmada por usuario el 2026-09-27: reutilizar un examen entre minas requiere convalidacion confirmada por un usuario; no propagacion automatica. Las instrucciones generales anteriores deben reconciliarse en una tarea funcional independiente.

## Por confirmar

- Examenes criticos por mina y equivalencias exactas permitidas.
- Roles exactos que pueden convalidar o marcar NO_APLICA.
