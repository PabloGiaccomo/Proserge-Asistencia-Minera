# Evaluaciones

Evaluacion diaria conectada a asistencia y evaluaciones independientes de supervisores y residentes.

## Procesos

| Proceso | BPMN | Visual | Alcance |
|---|---|---|---|
| Evaluacion diaria desde asistencia | [BPMN](./evaluacion-diaria.bpmn) | [SVG](./evaluacion-diaria.svg) | Solo evalua detalles cerrados PRESENTE/TARDANZA, evita autoevaluacion y duplicados por detalle. |
| Evaluacion de supervisor | [BPMN](./evaluacion-supervisor.bpmn) | [SVG](./evaluacion-supervisor.svg) | Un residente o administrador autorizado evalua a un supervisor dentro del scope de mina, sin requerir parada. |
| Evaluacion mensual de residente | [BPMN](./evaluacion-residente.bpmn) | [SVG](./evaluacion-residente.svg) | Gerencia o administrador autorizado evalua al residente por mes, sin mina ni parada y sin duplicar periodo. |

## Actores y datos

- Responsable de asistencia
- Residente
- Gerencia
- Administrador autorizado
- Sistema

## Evidencia principal

- `app/Modules/Evaluaciones/Services/EvaluacionDesempenoService.php::createDaily`
- `app/Modules/Evaluaciones/Policies/EvaluacionesPolicy.php`
- `app/Modules/Asistencia/Services/AsistenciaCierreService.php`
- `docs/evaluaciones.md`
- `tests/Feature/EvaluacionesWebTest.php`
- `app/Modules/Evaluaciones/Services/EvaluacionSupervisorService.php`
- `app/Modules/Evaluaciones/Services/EvaluationCandidateService.php`
- `tests/Feature/EvaluacionSupervisorApiTest.php`
- `app/Modules/Evaluaciones/Services/EvaluacionDesempenoService.php::createResidente`
- `app/Models/EvaluacionResidente.php`
- `tests/Unit/ResidentEvaluationTemplateTest.php`

## Duplicidades o inconsistencias

- Web y API comparten dominio, pero no expresan permisos con el mismo mecanismo.
- Existe createSupervisor legacy en EvaluacionDesempenoService, pero las rutas actuales inspeccionadas usan EvaluacionSupervisorController y el servicio dedicado. No se presenta el metodo legacy como endpoint activo.
- Regla confirmada 2026-09-27, pendiente de implementar: si una asistencia reabierta cambia a AUSENTE, conservar evaluacion, dejarla pendiente de revision y excluirla de indicadores. AsistenciaCierreService::reabrir no realiza este tratamiento; el BPMN actual no lo presenta como implementado.
- Responsable confirmado por usuario: solo el evaluador original resuelve la revision de la evaluacion afectada; no se acordo excepcion administrativa. Regla pendiente de implementacion.

## Por confirmar

- Mapeo definitivo de cargos/roles organizacionales a Residentes y Gerencia.
