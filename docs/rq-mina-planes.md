# RQ Mina: planes operativos

## Alcance

La parada sigue representada por `rq_mina`, pero ahora puede tener varios
planes operativos en `rq_mina_planes`. Cada plan agrupa su propia estructura de
areas, actividades, turnos y transportes.

```text
rq_mina
-> rq_mina_planes
-> rq_mina_actividad_grupos
-> rq_mina_actividades
-> rq_mina_actividad_turnos
-> rq_mina_actividad_transportes
```

`rq_mina_actividad_grupos.rq_mina_id` se mantiene por compatibilidad historica.
La relacion principal para planes nuevos es
`rq_mina_actividad_grupos.rq_mina_plan_id`.

## Seleccion de plan

La pantalla de plan operativo acepta `plan_id` por query string:

```text
/rq-mina/{id}/plan?plan_id={rq_mina_plan_id}
```

Si no se envia `plan_id`, el sistema resuelve `PLAN-001`. Si `PLAN-001` esta
archivado, busca el primer plan no archivado. Si se envia un `plan_id` que no
pertenece a la parada, se rechaza y se vuelve al plan valido.

El guardado de plan operativo incluye `plan_id` como campo oculto para que el
backend reemplace solo los grupos del plan seleccionado.

## Plan inicial y compatibilidad legacy

`RQMinaPlanService::ensureDefaultPlan()` crea o retorna el plan inicial:

- codigo: `PLAN-001`;
- nombre: `Plan operativo inicial`;
- version: `1`;
- fechas iguales al rango de la parada;
- semana ISO calculada desde el rango;
- estado inicial: `BORRADOR`.

El metodo es idempotente. Los grupos historicos con
`rq_mina_plan_id = null` pueden mostrarse temporalmente junto con `PLAN-001`.
Los planes posteriores, como `PLAN-002`, nunca muestran grupos legacy null.

El payload antiguo, sin `plan_id`, sigue funcionando y opera sobre el plan
inicial resuelto por compatibilidad.

## Creacion y edicion

Los planes se crean dentro del rango de la parada. El codigo se genera de forma
correlativa por parada: `PLAN-001`, `PLAN-002`, `PLAN-003`, etc. Dos paradas
distintas pueden tener su propio `PLAN-001`.

La edicion permite cambiar nombre, fechas, semana de referencia, estado
operativo (`BORRADOR` o `VIGENTE`) y observaciones. Si se reduce el rango, el
servicio valida que no existan turnos ni transportes fuera de las nuevas fechas.

## Duplicacion

`RQMinaPlanService::duplicatePlan()` copia la estructura operativa del plan
origen a un plan nuevo. Copia:

- grupos;
- actividades;
- supervisores;
- SAIT y AIT;
- turnos y cantidades planificadas;
- transporte requerido.

No copia ejecucion ni cierre operativo:

- `real`;
- `real_turno_a`;
- `real_turno_b`;
- placas asignadas;
- comentarios de cambio;
- incidencias;
- fechas o estados de recepcion;
- eventos de transporte;
- documentos;
- asignaciones de RQ Proserge;
- Man Power;
- asistencia;
- faltas.

Las fechas se desplazan con la diferencia entre la fecha inicial del plan origen
y la fecha inicial del plan nuevo. Si cualquier turno o transporte copiado queda
fuera del rango nuevo, se rechaza toda la duplicacion dentro de una transaccion.

## Archivo y modo consulta

Un plan archivado queda en modo consulta. Sus datos historicos siguen visibles,
pero no se permite:

- guardar cambios;
- importar;
- agregar o quitar grupos;
- agregar o quitar actividades;
- modificar transportes.

El backend tambien bloquea modificaciones sobre planes archivados. No se puede
archivar el unico plan no archivado de una parada. Tampoco se puede archivar
`PLAN-001` mientras existan grupos historicos sin `rq_mina_plan_id`.

## Backfill

El comando:

```bash
php artisan rq-mina:backfill-planes
```

crea el plan inicial para paradas que tienen grupos operativos y vincula los
grupos legacy al plan inicial.

Antes de tocar datos reales debe usarse:

```bash
php artisan rq-mina:backfill-planes --dry-run
```

Con `--dry-run` no inserta ni actualiza registros. El comando procesa por
chunks, usa transacciones por parada y puede ejecutarse varias veces sin
duplicar planes ni mover grupos ya asociados a otro plan.

## Importacion

La seleccion del plan esta preparada en la pantalla de importacion, y el enlace
conserva `plan_id`.

Estado real: la seleccion del plan esta preparada, pero el motor de importacion
del plan operativo todavia no existe. La pantalla debe mantenerse como funcion
no disponible hasta analizar el formato Excel completo e implementar un parser
con pruebas de aislamiento entre planes.

## Limitaciones pendientes

- No se implemento el importador Excel del plan operativo.
- No se modifico Man Power.
- No se modifico RQ Proserge.
- No se modifico Asistencia.
- No se agrego auditoria historica de cambios de plan.
- No se agregaron notificaciones por cambios de plan.
- No se implementaron KPI transversales.
- No se elimino `rq_mina_id` de `rq_mina_actividad_grupos` por compatibilidad.
