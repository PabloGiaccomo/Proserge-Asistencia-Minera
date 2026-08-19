# Dashboard KPI de paradas

## Objetivo

El dashboard consolida la lectura operativa de una parada desde RQ Mina hasta
ejecucion y transporte. Es una pantalla de seguimiento; entrar a verla no
modifica datos historicos ni recalcula automaticamente informacion real.

Ruta principal:

- `GET /rq-mina/{rqMina}/dashboard`

Rutas auxiliares:

- `GET /rq-mina/{rqMina}/dashboard/data`
- `POST /rq-mina/{rqMina}/dashboard/recalcular`
- `GET /rq-mina/{rqMina}/dashboard/exportar-excel`
- `GET /rq-mina/{rqMina}/dashboard/imprimir`

## Fuentes reutilizadas

- `RQProsergeCoverageService`: cobertura de titulares, respaldo, adicionales y
  sin clasificar.
- `ManPowerPlanningService`: grupos distribuidos, brechas y excesos por fecha y
  turno.
- `TransportePlanningService`: requerimientos, servicios, pasajeros y brechas de
  transporte.
- `ParadaExecutionMetricsService`: resumen materializado de asistencia y
  ejecucion.

## Indicadores

- **RQ Mina**: suma `rq_mina_detalle.cantidad_total`. El backup 20% no se
  interpreta como personal efectivo, solo como indicador independiente.
- **RQ Proserge**: porcentaje total calculado por `RQProsergeCoverageService`.
- **Man Power**: distribuidos activos contra requeridos del plan operativo para
  la fecha y turno filtrados.
- **Ejecucion**: presentes contra planificado desde `parada_ejecucion_resumen`.
  Si la asistencia esta abierta, el dato se muestra como preliminar.
- **Transporte**: pasajeros asignados contra personal distribuido.
- **Datos cerrados**: filas de ejecucion con asistencia cerrada contra filas
  disponibles.

## Recalculo

El boton `Recalcular ejecucion` llama a `ParadaExecutionMetricsService` con
filtro de `rq_mina_id` y `rq_mina_plan_id`. No hace backfill masivo, no cambia
RQ Mina, no cambia RQ Proserge, no cambia contratos y no modifica datos
historicos cerrados fuera del resumen materializado.

## Reglas que protege

- No sobreescribe valores `REAL` antiguos.
- No aplica cambios automaticos sobre habilitaciones, contratos o estados
  laborales.
- No borra ni archiva datos.
- No convierte el backup 20% ni el 15% EECC en asignacion obligatoria.
- Si hay asistencia abierta, avisa que los indicadores reales no son
  definitivos.

## Archivos principales

- `app/Modules/RQMina/Controllers/ParadaDashboardController.php`
- `app/Modules/RQMina/Services/ParadaDashboardService.php`
- `app/Modules/RQMina/Services/ParadaDashboardExcelService.php`
- `app/Modules/RQMina/Support/ParadaKpiDefinition.php`
- `resources/views/rq-mina/dashboard.blade.php`
- `resources/views/rq-mina/dashboard-print.blade.php`
