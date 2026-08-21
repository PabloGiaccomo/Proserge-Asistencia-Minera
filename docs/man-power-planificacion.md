# Man Power: planificacion diaria trazable

## Cadena de origen

Man Power debe trabajar con esta cadena:

`RQ Mina -> Plan operativo -> Actividad SAIT -> RQ Proserge -> Asignacion RR.HH. -> Grupo diario Man Power -> Integrante`

El grupo diario no debe depender solo de textos ni de `personal_id`. Cada integrante nuevo queda vinculado al registro exacto de `rq_proserge_detalle.id` que RR.HH. asigno para cubrir el requerimiento.

## Grupo diario

Cada grupo de Man Power guarda:

- parada o requerimiento de mina;
- plan operativo;
- una actividad SAIT del plan, con su area y sector;
- grupo operativo padre de la actividad;
- fecha y turno;
- servicio, area, paradero, horario y unidad;
- responsable que tomara asistencia;
- snapshot de la actividad: SAIT, area, sector, modulo, supervisores y cantidad planificada;
- estado operativo: `BORRADOR`, `PROGRAMADO`, `CERRADO` o `CANCELADO`.

El dia por defecto es el siguiente dia operativo, porque los grupos normalmente se preparan de un dia para otro.

## Integrantes

Cada integrante guarda:

- trabajador;
- asignacion exacta de RQ Proserge (`rq_proserge_detalle_id`);
- snapshots de puesto, posicion, tipo de asignacion y habilitacion minera;
- estado de distribucion: `ASIGNADO`, `RETIRADO`, `REUBICADO` o `CANCELADO`;
- usuario y fecha de asignacion o retiro;
- motivo u observacion cuando corresponda.

Los retiros y reubicaciones son logicos. No se debe borrar historicamente una distribucion trazable.

## Disponibilidad

Un trabajador queda disponible para un grupo solo si:

- tiene una asignacion activa en RQ Proserge;
- la asignacion cubre la fecha seleccionada;
- pertenece a la parada seleccionada;
- no esta distribuido activamente en otro grupo del mismo dia y turno.

Si la persona ya fue distribuida, la pantalla muestra el grupo donde se encuentra.

## Referencia diaria

La cantidad planificada se toma de `Turno A / Dia` o `Turno B / Noche` del plan operativo de RQ Mina. Si la matriz fue registrada como semana recurrente (`Lun` a `Dom`) y no contiene fechas, Man Power resuelve el valor por el dia de la semana seleccionado.

La pantalla selecciona primero el plan operativo y despues una actividad `SAIT / punto`. El selector identifica cada actividad con su SAIT, area y sector. Todo el dashboard, la referencia diaria y los grupos mostrados quedan acotados a esa actividad.

Cada grupo diario pertenece a un unico SAIT. Aunque varias actividades compartan el mismo grupo operativo padre, no se mezclan dentro del mismo grupo de Man Power. Para el mismo dia y turno solo puede existir un grupo vigente por SAIT.

La cantidad es una referencia operativa. Man Power permite distribuir menos o mas trabajadores y muestra la diferencia sin bloquear la preparacion del grupo. Los campos `Real turno A`, `Real turno B` y `real` no se modifican desde Man Power.

La copia entre dias conserva por separado el SAIT de origen y el SAIT de destino. Puede pegarse la estructura de un SAIT sobre otro: se reemplazan solamente los grupos vigentes del SAIT destino, se vinculan los nuevos grupos a su actividad, area y sector, y los grupos de los demas SAIT permanecen intactos.

Desde la seleccion diaria tambien se puede repetir el SAIT al resto de la semana o al resto de la parada. El dia seleccionado es la plantilla, la copia empieza en la fecha siguiente y nunca reemplaza fechas anteriores al dia actual. Cada destino reemplaza solamente sus grupos vigentes del mismo plan y SAIT.

El dashboard tambien muestra los puestos del pedido de personal de RQ Mina, la cantidad solicitada, el back up, lo entregado por RR.HH. y cuantos trabajadores fueron distribuidos en cada turno.

El usuario puede cambiar la perspectiva del dashboard sin salir del plan y SAIT seleccionados. Los modos disponibles son `Resumen`, `Turnos`, `Cargos` y `Cobertura SAIT`; cada uno prioriza solamente la informacion necesaria para esa lectura operativa.

`Resumen` consolida todo el periodo del plan: jornadas requeridas, jornadas distribuidas, cobertura, dias con referencia, dias con grupos, grupos preparados y personal unico utilizado. No depende del dia de trabajo seleccionado. `Turnos`, `Cargos` y `Cobertura SAIT` mantienen la fecha porque son lecturas operativas diarias.

## Asistencia

Mi Asistencia sigue consumiendo `grupo_trabajo_id` y `personal_id`.

La compatibilidad agregada hace que solo cuenten integrantes con distribucion activa. Integrantes retirados o reubicados no deben aparecer como personal vigente para marcar asistencia.

## Compatibilidad historica

Los grupos antiguos sin plan operativo o sin grupo operativo se siguen mostrando como registros legacy. No se reclasifican automaticamente desde la UI.

El comando de backfill intenta vincular integrantes antiguos solo cuando encuentra una unica asignacion activa compatible con la misma parada, trabajador y fecha.

Comandos:

```bash
php artisan man-power:backfill-traceability --dry-run
php artisan man-power:backfill-traceability
```

Ejecutar primero con `--dry-run` y revisar omitidos antes de aplicar en una copia o ambiente controlado.

## No incluido en esta etapa

- Importacion Excel de Man Power.
- Notificaciones por cambios historicos.
- Auditoria formal de cambios.
- KPIs avanzados.
- Modificacion del calculo del 20 % de RQ Mina.
- Implementacion del 15 % de suplentes EECC.
