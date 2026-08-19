# Asistencia y ejecucion real

## Objetivo

La asistencia deja de depender solo de `grupo_trabajo_id + trabajador_id` y
empieza a conservar la distribucion exacta de Man Power mediante
`grupo_trabajo_detalle_id`.

Esto permite reconstruir que integrante asistio, que asignacion de RQ Proserge
cubrio, en que grupo diario estuvo, que puesto cubria y como impacta en la
ejecucion real del plan operativo.

## Relacion operativa

Flujo:

1. RQ Mina define parada, plan, grupos operativos, actividades y cantidades.
2. RQ Proserge asigna personal a requerimientos.
3. Man Power crea grupos diarios y distribuye integrantes.
4. Asistencia crea el padron desde `grupo_trabajo_detalle`.
5. La ejecucion real se calcula desde asistencia y actividades principales.

El padron valido sale exclusivamente de Man Power. Transporte no crea personal
de asistencia ni aumenta el real.

## Padron

Al abrir, marcar, cerrar o sincronizar asistencia, el sistema revisa los
integrantes activos del grupo:

- pertenecen al `grupo_trabajo`;
- tienen distribucion activa `ASIGNADO`;
- no estan retirados, reubicados ni cancelados;
- conservan snapshots de puesto, posicion y tipo.

Cada `asistencia_detalle` nuevo guarda:

- `grupo_trabajo_detalle_id`;
- `rq_proserge_detalle_id`;
- `trabajador_id`;
- `puesto_snapshot`;
- `posicion_asignacion_snapshot`;
- `tipo_asignacion_snapshot`;
- `estado_distribucion_snapshot`;
- usuario y fecha de marcacion.

Las asistencias historicas sin `grupo_trabajo_detalle_id` siguen visibles como
legacy y pueden vincularse con el comando de backfill si hay una unica
coincidencia segura.

## Estados

Estados validos:

- `PRESENTE`: cuenta como real.
- `TARDANZA`: cuenta como real y se contabiliza aparte.
- `AUSENTE`: no cuenta como real y puede generar falta al cierre.
- `JUSTIFICADO`: no cuenta como presencia y no genera falta.
- `NO_CORRESPONDE`: no cuenta como presencia ni genera falta.

`JUSTIFICADO` y `NO_CORRESPONDE` exigen motivo u observacion.

## Cierre y reapertura

El cierre:

- completa el padron faltante desde distribuciones activas;
- marca ausentes automaticos cuando corresponda;
- genera faltas solo por `AUSENTE`;
- no genera faltas por `PRESENTE`, `TARDANZA`, `JUSTIFICADO` ni
  `NO_CORRESPONDE`;
- recalcula la proyeccion de ejecucion.

La reapertura:

- cambia el encabezado a `REGISTRADO`;
- anula faltas activas generadas por esa asistencia;
- sincroniza el padron;
- recalcula la ejecucion.

## Actividad principal

Un integrante puede tener una actividad principal en
`grupo_trabajo_detalle_actividades`.

Reglas:

- maximo una actividad principal por integrante;
- la actividad debe pertenecer al grupo diario;
- si el grupo tiene una sola actividad, se puede asignar automaticamente;
- si tiene varias, no se infiere;
- un presente sin actividad cuenta para el grupo, pero no para una actividad.

## Ejecucion real

La proyeccion `parada_ejecucion_resumen` es recalculable y no reemplaza la
asistencia ni Man Power como fuente primaria.

Metricas:

- planificado;
- programado;
- presentes;
- tardanzas;
- ausentes;
- justificados;
- no corresponde;
- pendientes de marcacion;
- titulares, suplentes, adicionales y sin clasificar presentes;
- personal sin actividad;
- brechas plan/programado/real;
- porcentajes de programacion, asistencia y cumplimiento real.

Los porcentajes pueden superar 100% para evidenciar exceso operativo.

## REAL legacy

No se sobrescriben:

- `real`;
- `real_turno_a`;
- `real_turno_b`.

La ejecucion calculada se mantiene separada como proyeccion desde asistencia.
Una futura iteracion puede decidir como mostrar `REAL CALCULADO` frente a
`REAL MANUAL LEGACY` en el dashboard final.

## Comandos

Backfill historico seguro:

```bash
php artisan asistencia:backfill-distribuciones --dry-run
php artisan asistencia:backfill-distribuciones
```

Recalculo de ejecucion:

```bash
php artisan parada:recalcular-ejecucion --dry-run
php artisan parada:recalcular-ejecucion
php artisan parada:recalcular-ejecucion --rq-mina={id}
php artisan parada:recalcular-ejecucion --plan={id}
```

No ejecutar estos comandos sobre produccion sin backup, ventana operativa y
revision previa.

## API

Endpoints agregados:

- `GET /api/v1/asistencia/ejecucion`
- `POST /api/v1/asistencia/grupos/{grupoId}/sincronizar-padron`
- `POST /api/v1/asistencia/grupos/{grupoId}/integrantes/{detalleId}/actividad-principal`

Los endpoints existentes de marcacion siguen aceptando `personal_id` de forma
legacy, pero la forma recomendada es usar `asistencia_detalle_id` o
`grupo_trabajo_detalle_id`.

## Limitaciones pendientes

- Las vistas Blade de asistencia todavia funcionan como pantallas base y no
  consumen todo el nuevo resource.
- El dashboard final de parada no se construyo en esta iteracion.
- No se normalizaron los turnos de `rq_mina_actividad_turnos`.
- No se eliminaron columnas legacy.
- No se ejecuto backfill real.
# Mi Asistencia y responsables de Man Power

- La cuenta operativa se vincula al trabajador mediante `usuarios.personal_id`.
- El responsable de cada grupo se toma de `grupo_trabajo.supervisor_id`, asignado desde Man Power.
- Con acceso normal a `mi_asistencia`, un usuario solo puede listar, abrir y registrar los grupos donde ambos identificadores coinciden.
- La acción `mi_asistencia.ver_todas_asistencias` habilita la consulta global, respetando el alcance de minas del usuario. Esta acción por sí sola no concede edición de grupos ajenos.
- Los roles privilegiados o con permisos operativos de `asistencias` conservan sus capacidades de registro y cierre.
- Toda marcación real se guarda en `asistencia_encabezado` y `asistencia_detalle`, incluyendo usuario y fecha de registro; no se usa almacenamiento local del navegador.
