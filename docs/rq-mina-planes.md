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

## Guardado y recuperacion del editor web

El editor carga los datos guardados en el sistema. Cuando hay una copia
pendiente del mismo usuario y plan, ofrece recuperarla o descartarla. La
recuperacion exige que la copia parta de los mismos datos que muestra el
servidor. Las copias anteriores sin identificacion de usuario quedan fuera
de la recuperacion. Tras un guardado confirmado se limpia la copia enviada.

Ante un error, el formulario recupera tambien los datos completos enviados
para la misma parada y plan desde la sesion, aunque el almacenamiento local
del navegador falle. Conserva la revision enviada y marca esos datos como
pendientes. Una copia recuperada no adopta una revision mas reciente del
servidor: un conflicto exige revisar los cambios antes de volver a guardar.

La grilla genera una fila por fecha del plan seleccionado, desde el inicio
hasta el fin, ambos incluidos. Al crearse, el plan inicial toma las fechas de
la parada. Los planes con un intervalo propio muestran su rango guardado.
La fecha se muestra con su día, por ejemplo `7/11 sáb`. Cada cantidad nueva
o modificada se guarda en esa fecha, incluso si el plan abarca varios sábados.

Las cantidades semanales anteriores sirven de referencia cuando la fecha no
tiene un turno propio. El editor conserva esos registros de origen y prioriza
los turnos ya fechados. Las celdas de ejecución se muestran en su fecha
registrada. Las filas nuevas vacías sirven de guía en la pantalla y sólo se
guardan cuando se edita la programación de esa fecha.
Los registros anteriores fuera del intervalo o con fechas repetidas se
conservan. La grilla editable muestra una fila por fecha del intervalo.

El programa se envía en un campo JSON para que las filas por fecha no agoten
el límite de campos del formulario PHP. El servidor conserva la recuperación
de datos y rechaza envíos incompletos o ilegibles.

Al crear o editar los datos generales, la fecha de fin debe ser igual o
posterior a la de inicio. El formulario conserva los campos enviados y
muestra los errores para permitir su correccion. Para acortar una parada,
sus planes guardados deben caber en el nuevo rango.

## Pedido de personal y atencion de RR. HH.

El primer guardado web con un cargo y una cantidad positiva registra el pedido
en RQ Proserge dentro de la misma transaccion. El usuario necesita permiso de
guardado y el permiso vigente `rq_mina,enviar` para iniciar la atencion. Con
permiso de guardado solamente, el pedido queda en preparacion. Guardar sin
cargos conserva la parada sin generar un puesto de personal ficticio.

El estado interno `ENVIADO` se presenta como pedido registrado en RR. HH. El
envio explicito sigue disponible para integraciones API y requiere cargos reales.
Los usuarios que tienen permiso de envio y consulta, sin acceso al guardado del
plan, pueden revisar y registrar el pedido ya guardado desde el detalle.
El editor web verifica la revision de la parada, el pedido y el plan antes de
escribir. Si otra sesion guardo cambios, conserva la version del sistema y deja
la copia local pendiente de revision.

La reduccion de cantidad cuenta solo asignaciones activas y registra el retiro
del excedente sin borrar su historial. Si el retiro afectaria una distribucion
activa o una asignacion con asistencia, se rechaza el cambio. Un cargo con
historial requiere revision con RR. HH. antes de retirarlo del pedido.

La pantalla mantiene el contexto y el selector del plan, con las acciones
administrativas en una seccion desplegable. Guardar queda visible antes del
editor y al final del formulario. Crear, duplicar, editar datos o archivar un
plan requiere guardar primero los cambios pendientes del programa. El titulo
del programa muestra el rango de semanas del plan seleccionado.
El programa distingue el area operativa del grupo y el area de cada actividad;
las acciones para agregar y quitar usan siempre el nombre grupo / frente.

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

## Datos de logistica y estructura del transporte

Guardar el plan **actualiza cada fila en su lugar** cuando el editor reenvia su `id`
(hidden por grupo, actividad y transporte). Un id que no pertenece al plan se
ignora y la fila se crea nueva, de modo que un formulario no puede reasignar filas
de otra parada. Actualizar en su lugar conserva la trazabilidad que Logistica ya
registro: `rq_mina_actividad_transporte_eventos` sigue apuntando al mismo
`transporte_id` y la carpeta de documentos no cambia de dueno.

Si el payload **no** trae ids (payload legacy o cliente antiguo), el guardado
recupera el grupo por nombre unico y la actividad por SAIT unico. Un transporte
solo recupera su identidad si la firma `alcance + unidad de carga` es unica
dentro del mismo grupo. Asi conserva la misma fila, sus eventos y su atencion.
Una coincidencia ambigua no permite sustituir una fila con evidencia.

Los documentos se entregan al modelo como una lista de metadatos con `path`;
Eloquent serializa esa lista una sola vez mediante el cast `array`. El rescate
del payload legacy tambien acepta las listas JSON que una version anterior
codifico dos veces. Dos guardados consecutivos sin ids conservan las mismas
rutas y metadatos: no se mueven ni borran los archivos privados. Esta correccion
no ejecuta una reparacion masiva de registros existentes.

El editor impide quitar un transporte con atencion y explica que su retiro debe
revisarse con Logistica. El backend rechaza tambien la omision directa de filas
con placas, documentos, recepcion o eventos, y el retiro de grupos o actividades
vinculados a Man Power, ejecucion o transporte. Toda la operacion se revierte
para conservar las filas y su historial.

Logistica bloquea primero la parada y relee el transporte dentro de la misma
transaccion antes de actualizarlo. El editor del plan usa el mismo orden de
bloqueo, evitando guardar contra una fila retirada o auditar un estado anterior
obsoleto.

El editor del plan registra ademas la estructura que consumen Logistica y Transporte operativo: `tipo_transporte` (`PERSONAL`/`CARGA`), `turno` (`A`, `B` o vacio = ambos turnos) y `cantidad_unidades_requeridas` / `capacidad_requerida`. Un requerimiento sin estructura se sigue mostrando como `TRANSPORTE LEGACY SIN ESTRUCTURA COMPLETA`.

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

## Validacion local de las correcciones del 2026-11-04

- RQ Mina: 92 pruebas PHP, 531 aserciones, incluidas transaccion RR. HH.,
  conflictos de revision, permisos, recuperacion, fechas e historial logistico.
- Integraciones RQ Proserge, Man Power, Transporte, Logistica y herramientas:
  111 pruebas PHP, 647 aserciones.
- Editor: 21 pruebas Node de calendario, copia local y administracion de planes.
- Navegador Chrome: 55 comprobaciones con vistas Blade actuales y datos
  sinteticos, en 1440 x 900 y 390 x 900, sin errores JavaScript ni desbordamiento
  horizontal del documento. Las tablas amplias conservan desplazamiento interno.
- Sintaxis PHP, Pint de los tests y archivos nuevos, `view:cache` y
  `git diff --check` correctos.

Las pruebas MySQL usaron una instancia MariaDB aislada en 127.0.0.1:13376 con
la base `proserge_app_test`; las pruebas reducidas usan SQLite en memoria.
El navegador no envio escrituras HTTP ni uso datos reales. No sustituye una
prueba tactil ni la comprobacion operativa en el servidor desplegado.

Las correcciones quedan en local, sin despliegue FTP ni migraciones nuevas.
Los estilos y scripts de esta entrega viven en Blade; no se modificaron fuentes
compilables de Vite y no se requirio reconstruir assets por estas correcciones.
