# Habilitacion minera: clinicas EMO y control de asistencia

Fecha: 2026-10-07

## Objetivo

Incorporar en habilitacion minera una cita EMO trazable por trabajador, fecha y
clinica; impedir que un trabajador quede programado el mismo dia en clinicas
diferentes; conservar el resultado medico exacto; y ofrecer un control por
rango de fechas exportable a Excel para asistencia de cursos y EMO.

El cambio no modifica contratos, estado laboral, fichas, documentos personales
ni reglas de habilitacion ajenas a los examenes explicitamente vinculados.

## Decisiones operativas

1. Una persona solo puede tener una cita EMO no anulada por fecha operativa.
2. La misma cita puede cubrir varias minas cuando coinciden persona, fecha y
   clinica, y todos los requisitos usan exactamente el mismo `examen_id`.
   Cada mina debe quedar vinculada explicitamente; no hay equivalencias,
   propagacion por similitud ni coincidencia silenciosa en v1.
3. Si ya existe una cita para otra clinica, el sistema bloquea el guardado y
   muestra fecha y clinica existentes.
4. Una cita completada no cambia de clinica ni resultado por edicion ordinaria.
   Una correccion posterior requiere una accion auditada, fuera del alcance de
   esta primera entrega. La cita puede anularse antes de completarse y reutilizar
   el registro para reprogramar, conservando su historial.
5. Los resultados EMO son `APTO`, `NO_APTO`, `APTO_CON_RESTRICCION` y
   `APTO_CON_OBSERVACION`.
6. `APTO`, `APTO_CON_RESTRICCION` y `APTO_CON_OBSERVACION` resuelven el examen
   como aprobado. `NO_APTO` lo resuelve como desaprobado. Restriccion y
   observacion requieren detalle obligatorio y se conservan de forma
   estructurada, no solo como prefijo de texto.
7. El rango del control se refiere a fecha de programacion, incluye ambos
   extremos y muestra pendientes, realizados, no asistidos y anulados.
8. El Excel contiene todas las filas que cumplen los filtros funcionales, no
   solo la pagina visible. Si supera 10 000 filas se rechaza sin truncar.
9. Los intentos historicos no reciben una clinica inventada. `lugar_snapshot`
   no se usa como evidencia de la clinica de una cita.

Estas decisiones son la interpretacion conservadora adoptada para completar el
plan. En particular, `APTO_CON_OBSERVACION` habilita pero conserva el detalle.
Si SSOMA cambia esa semantica, el Gate 0 de ejecucion debe actualizar Spec,
tests y mapeo antes de escribir codigo.

## Estado actual verificado

- `personal_mina_examen_intentos` guarda fechas y resultado, pero no clinica ni
  una identidad de cita compartida.
- La validacion actual impide otra programacion pendiente solo en el mismo
  examen del trabajador; no cruza minas.
- `examenes_mineros.lugar` y `personal_mina_examenes.lugar_snapshot` describen
  el catalogo/examen, no la cita realizada.
- `CursoResultado` convierte APTO y NO APTO a los resultados genericos y guarda
  la restriccion dentro de `observacion`.
- La bandeja de programados consulta un rango fijo, aplica `limit(500)` y luego
  filtra y pagina dentro de Blade. Una coincidencia puede quedar fuera antes de
  aplicar el filtro.
- Existe el permiso `habilitacion_minera.exportar`, pero no existe la ruta ni el
  servicio de exportacion de este control.
- Los archivos centrales de habilitacion tienen cambios locales en curso. La
  implementacion debe partir de ese estado y no revertirlo.

## Arquitectura propuesta

### 1. Clasificacion operativa del catalogo

Agregar `categoria_operativa` a `examenes_mineros` con los valores `CURSO`,
`EMO` y `OTRO`. El campo existente `tipo` continua como texto descriptivo y no
se usa para imponer reglas.

La migracion deja los registros existentes en `OTRO`. Antes de activar el flujo
se genera un inventario y un usuario autorizado confirma `CURSO` o `EMO`. No se
infiere por nombre ni por el texto libre `tipo`. La pantalla de catalogo permite
corregir la categoria con permiso `habilitacion_minera.configurar`, motivo
obligatorio y un historial separado con valor anterior/nuevo, actor y fecha.

### 2. Catalogo de clinicas

Crear `clinicas_emo`:

- UUID;
- nombre visible y nombre normalizado unico;
- estado activo;
- usuario de creacion/actualizacion;
- timestamps.

Las clinicas se crean, renombran o desactivan desde Acciones de habilitacion
minera con permiso `configurar`. No se eliminan fisicamente y no se queman
nombres como Pulso o Natclar en codigo o migraciones.

### 3. Cita EMO compartida

Crear `personal_emo_citas` como agregado independiente:

- trabajador;
- clinica y snapshot del nombre;
- fecha de programacion y fecha de realizacion;
- estado `PROGRAMADA`, `COMPLETADA` o `ANULADA`;
- resultado EMO estructurado y detalle;
- origen (`MANUAL`, `CARGA_MASIVA`, `REGULARIZACION`);
- usuario que programa, completa y actualiza;
- timestamps.

Crear `personal_emo_cita_historial` con evento, valores anterior/nuevo en JSON,
actor, origen y fecha. Registra creacion, vinculacion de mina, reprogramacion,
anulacion y completado.

La cita tiene restriccion unica `(personal_id, fecha_programacion)`. Una cita
anulada se reactiva/reprograma sobre la misma identidad, conservando sus eventos,
en lugar de insertar otra fila que eluda la regla diaria.

Agregar `emo_cita_id` nullable a `personal_mina_examen_intentos` y a
`personal_curso_registros`. Los registros historicos permanecen nulos. En los
intentos, una restriccion unica por examen/cita impide duplicar el mismo enlace.

La cita es la unidad fisica del control de asistencia. Los intentos continúan
siendo la unidad de habilitacion por mina. Completar una cita actualiza, dentro
de una sola transaccion, solamente los intentos que ya estan vinculados.
Cada examen calcula con sus propios snapshots la vigencia, nota y estado; si
falla uno, se revierte el completado entero. Una cita completada no acepta
nuevas minas: un resultado para otra mina requiere convalidacion explicita o
una nueva cita en otra fecha.

### 4. Invariantes y concurrencia

`EmoAppointmentService` concentra crear/reutilizar, vincular, completar,
reprogramar y anular. El registro manual y la carga masiva deben llamarlo y no
pueden reimplementar la validacion. Master queda expresamente excluido en v1:
solo produce una accion pendiente y nunca invoca operaciones de cita.

Orden de bloqueo:

1. trabajador;
2. citas del trabajador para la fecha;
3. asignaciones vinculadas, ordenadas por UUID;
4. examenes e intentos, ordenados por UUID.

El bloqueo del trabajador y la restriccion unica serializan la primera cita
aunque aun no exista una fila. Antes de escribir se vuelve a comprobar:

- trabajador, mina, asignacion, requisito y examen activos;
- categoria `EMO`;
- clinica activa;
- ausencia de otra cita no anulada en distinta clinica;
- igualdad exacta del `examen_id` de cada intento vinculado.

Se agrega indice de busqueda por trabajador, fecha y estado. La regla se prueba
tambien con dos conexiones MySQL entrelazadas. Los errores de unicidad o carrera
se convierten a un mensaje operativo, sin escritura parcial.

### 5. Resultados EMO

Crear `EmoOutcome` como enum PHP o value object con:

- valores permitidos;
- etiqueta de usuario;
- resultado generico (`APROBADO` o `DESAPROBADO`);
- regla de detalle obligatorio.

La cita es la unica fuente del valor medico exacto y su detalle. Los intentos
vinculados conservan solamente el resultado generico `APROBADO` o
`DESAPROBADO` para no romper estados, vigencias, renovaciones ni
convalidaciones actuales; acceden al resultado exacto mediante `emo_cita_id`.
No se agregan columnas `resultado_emo` ni `detalle_resultado_emo` al intento.
La convalidacion y el historial copian tambien el resultado EMO y su detalle
cuando exista.

Los registros antiguos con el prefijo `APTO CON RESTRICCION:` siguen legibles,
pero no se reescriben automaticamente. Un inventario separado puede proponer su
regularizacion futura.

Al convalidar, el destino copia resultado EMO y detalle a
`contexto_convalidacion_json`, junto con cita, clinica, examen y mina origen. La
observacion visible conserva el texto operativo, pero no es la unica fuente
estructurada.

### 6. Carga masiva y Master

El formato masivo admite la columna `CLINICA` en programaciones y resultados.

- Para cursos es opcional y se ignora.
- Para EMO con fecha de programacion es obligatoria.
- Se resuelve exactamente contra clinicas activas; no crea clinicas ni acepta
  parecidos de forma silenciosa.
- Dos filas de la misma persona y fecha en clinicas distintas son observacion
  bloqueante para todo el archivo.
- Misma persona, fecha y clinica puede reutilizar la cita para las minas
  explicitamente incluidas solo si comparten exactamente el mismo `examen_id`.
- La confirmacion revalida catalogo, cita y conflicto dentro de la transaccion.
- El formato anterior continua siendo valido para cursos. Una fila EMO del
  formato anterior queda observada por falta de clinica.

En esta entrega el Master nunca crea citas ni convierte `CLINICA`, `SEDE` o
`LUGAR` en una cita. `PROGRAMAR EMO`, con o sin fecha, continua como accion
pendiente `EN_PROCESO`; la cita se registra manualmente o mediante el formato
masivo versionado con columna `CLINICA`.

### 7. Consulta de control

Extraer la lectura a `PersonalMinaScheduledControlService`. Recibe un DTO
`ScheduledControlFilters` producido por un Form Request compartido entre la
pantalla y el Excel.

Filtros:

- texto de trabajador (nombre, documento o puesto);
- fecha desde/hasta;
- categoria Curso/EMO;
- examen por ID;
- mina por ID;
- clinica por ID;
- estado de asistencia/resultado.

El contrato HTTP usa exclusivamente estos parametros: `scheduled_worker`
(string, maximo 100), `scheduled_from` y `scheduled_to` (fecha `Y-m-d`),
`scheduled_category` (`CURSO|EMO`), `scheduled_exam_id`, `scheduled_mine_id` y
`scheduled_clinic_id` (UUID nullable), `scheduled_attendance_state`
(`PROGRAMADO|ASISTIO|NO_ASISTIO|ANULADO`), `scheduled_limit`
(`25|50|100`) y `scheduled_page` (entero positivo). El DTO conserva esos
valores ya normalizados; las fechas son `CarbonImmutable`, los IDs y textos son
`?string`, el estado/categoria son enums nullable y limite/pagina son `int`.

El rango por defecto es hoy−30 a hoy+60 en `app.display_timezone`, con maximo
366 dias. Los filtros se aplican en SQL antes de paginar. Se elimina el recorte
de 500 filas.

La identidad de fila es: EMO con cita por `personal_emo_cita.id`; EMO legado
sin cita por `legacy-emo-attempt:{personal_mina_examen_intento.id}`; curso con
registro por `personal_curso_registro.id`; curso legado sin registro por intento individual.
Para una cita EMO compartida agrega las minas e intentos vinculados. El estado
de asistencia es `PROGRAMADO` si sigue pendiente, `ASISTIO` si tiene realizacion
y un resultado distinto de no asistencia, `NO_ASISTIO` para ese resultado y
`ANULADO` cuando corresponde. El filtro de mina reduce tambien las minas
agregadas que se muestran y exportan.

`ScheduledControlRow` es inmutable y contiene: `rowKey:string`,
`sourceType:CURSO|EMO`, `scheduledDate:CarbonImmutable`,
`clinicId:?string`, `clinicName:?string`, `workerId:string`,
`workerDocument:string`, `workerName:string`, `positionName:?string`,
`examId:string`, `examName:string`, `mineIds:list<string>`,
`mineNames:list<string>`, `attemptLabels:list<string>`,
`attendanceState:PROGRAMADO|ASISTIO|NO_ASISTIO|ANULADO`,
`emoOutcome:?EmoOutcome`, `resultDetail:?string`,
`performedAt:?CarbonImmutable` y `operationalState:string`. Para cursos, un
resultado registrado distinto de `NO_ASISTIO` mapea a `ASISTIO`; `NO_ASISTIO`
mapea a su estado homonimo; anulacion prevalece; sin realizacion ni resultado
queda `PROGRAMADO`. Para EMO se aplica la misma precedencia usando la cita.
El EMO legado admite clinica nula, se presenta como `Historico sin clinica`, no
se agrupa con citas modernas y ofrece la accion auditada de regularizacion si
sigue pendiente.

`filterOptions()` devuelve solo catalogos visibles en el alcance de la consulta:
categorias, examenes, minas, clinicas activas mas la clinica inactiva ya
seleccionada, y estados de asistencia. Los filtros de texto, fecha y estado no
reducen los catalogos; categoria/examen/mina/clinica si conservan la opcion
seleccionada para evitar que el formulario pierda contexto.

### 8. Excel de asistencia

`PersonalMinaScheduledControlExcelService` reutiliza exactamente el servicio y
DTO de consulta. Genera:

- hoja `Control` con fecha programada, tipo, clinica, trabajador, documento como
  texto, cargo, curso/EMO, minas filtradas, intentos, asistencia, resultado EMO,
  detalle, fecha realizada y estado operativo;
- hoja `Filtros` con los filtros aplicados, actor y fecha/hora de generacion.

Las celdas de texto cuyo primer caracter no blanco es `=`, `+`, `-` o `@` se
neutralizan para evitar formulas inyectadas, conservando el valor visible, y
los documentos se escriben con tipo explicito string. La descarga exige dos comprobaciones
independientes: `habilitacion_minera.ver_programados` y
`habilitacion_minera.exportar`.

Cada descarga guarda auditoria minima en `personal_emo_export_audits`: actor,
timestamp, filtros normalizados/hash y cantidad agrupada. No guarda filas ni
resultados medicos completos.

El limite es de 10 000 filas fisicas despues de agrupar. Se consulta una fila
adicional: 10 000 se exportan y 10 001 se rechazan sin generar un archivo
truncado ni una auditoria de exito.

La exportacion ignora `scheduled_limit` y `scheduled_page`: esos dos parametros
no alteran sus filas, la hoja `Filtros` ni el hash funcional de auditoria.

### 9. Interfaz

La pestaña pasa a llamarse `Control de asistencia de cursos y EMO`.

- Formulario superior con busqueda, rango, tipo, curso/EMO, mina, clinica y
  estado.
- Contador total y boton `Exportar resultados` con el mismo query string.
- Programar un EMO muestra clinica obligatoria; programar un curso no.
- El conflicto muestra la cita existente y no borra lo ingresado.
- Desktop usa tabla; mobile usa tarjetas sin depender solo del color.
- La clinica se muestra junto a fecha y tipo; las minas se mantienen agregadas.

El comportamiento nuevo se extrae a un modulo JavaScript y estilos CSS propios,
con imports minimos desde `resources/js/app.js` y `resources/css/app.css`.

## Compatibilidad y migracion

- Migraciones aditivas e idempotentes frente a esquemas historicos.
- Ninguna migracion elimina o reinterpreta intentos, archivos u observaciones.
- Programaciones EMO pendientes sin cita aparecen como `Historico sin clinica`
  y deben regularizarse antes de cerrarse o crear otra cita ese dia.
- El rollback operativo vuelve al codigo anterior conservando tablas/columnas;
  los `down()` no borran historial medico.
- Antes de produccion se mide volumen e impacto de indices sobre una copia
  realista, con backup de MySQL y `storage/app/private`.

## Seguridad y auditoria

- Todas las rutas permanecen bajo `web.auth` y permisos del modulo.
- Configurar clinicas/categorias exige `configurar`; programar exige
  `programar`; reprogramar, anular una programacion pendiente y regularizar un
  intento historico exigen `programar`; completar exige `registrar`; exportar
  exige `ver_programados` y `exportar`. Cada controlador repite la autorizacion
  de su ruta; las acciones de dos permisos comprueban ambos por separado.
- El Excel es informacion sensible: no se guarda en `public`, no se registra en
  logs y se transmite como descarga autenticada.
- Auditoria separa programador, registrador de resultado, origen y cambios.
- No se incorpora `mina.scope` de otros modulos al flujo actual.

## Criterios de aceptacion

1. Pulso/24/Marcobre seguido de Natclar/24/Boroo para la misma persona es
   rechazado, sin escritura parcial.
2. Pulso/24/Marcobre seguido de Pulso/24/Boroo reutiliza una cita y deja dos
   enlaces explicitos.
3. Completar la cita registra uno de los cuatro resultados y actualiza solo las
   minas enlazadas.
4. Restriccion y observacion exigen y conservan detalle.
5. Los filtros de rango funcionan por SQL sobre mas de 500 registros y la
   paginacion conserva el query.
6. El Excel contiene todas y solo las filas filtradas, conserva documentos con
   cero inicial y no permite formulas inyectadas.
7. Usuarios sin ambos permisos de exportacion reciben 403 y no ven el boton.
8. El flujo masivo no puede eludir clinica, conflicto ni atomicidad.
9. Historial anterior permanece sin clinica inventada y sin cambios de resultado.
10. La interfaz se entiende y opera en desktop y mobile.

## Fuera de alcance

- Integracion con agendas o APIs de clinicas.
- Cupos, horarios, sedes secundarias o costos por clinica.
- Correccion administrativa de resultados EMO ya completados.
- Propagacion automatica a minas no vinculadas.
- Reescritura masiva del historial medico anterior.
