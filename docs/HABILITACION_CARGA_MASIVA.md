# Carga masiva de cursos y EMO

## Integridad y renovacion (2026-10-06)

- Los cursos historicos sin mina de origen se conservan, pero no se reflejan
  automaticamente al asignar minas ni desde la revision de la carga. Debe
  revisarse su trazabilidad antes de utilizarlos.
- El Master conserva los IDs de alias confirmados, incluso cuando corresponde
  reactivar el examen; no mezcla identidades como `EMO A` y `EMOA`.
- Un fallo al guardar un intento del Master revierte toda la confirmacion.
  Un duplicado no sobrescribe la nota; una nota u observacion incompatible
  requiere revision. Un resultado anterior se incorpora al historial sin
  reemplazar un resultado posterior.
- Programar o desaprobar una renovacion anticipada conserva la aprobacion
  efectiva hasta su vencimiento, sin extenderla. El intento nuevo permanece
  en el historial. Despues del vencimiento se puede rendir otro intento.
- Registrar, completar programaciones y marcar NO_APLICA rechaza asignaciones,
  minas, requisitos o examenes inactivos. NO_APLICA y su recalculo se guardan
  en una transaccion; la observacion sigue siendo opcional.
- Una nueva realizacion deja de atribuirse a la convalidacion anterior. Antes
  de limpiar sus metadatos se conserva un snapshot en el historial de la
  asignacion. No se modifican los intentos ni archivos historicos.
- La convalidacion conserva las observaciones y restricciones del resultado
  origen, incluso si el usuario agrega un comentario. Si el texto combinado
  supera el limite, se rechaza sin truncar las restricciones.
- Al reincorporar un requisito retirado, la generacion reutiliza el examen del
  trabajador y lo enlaza al requisito activo, conservando intentos y snapshots.
  El cambio de referencia queda en el historial. El aviso de renovacion utiliza
  la fecha del intento nuevo aunque la aprobacion anterior siga vigente.
- Continuacion 2026-10-07: fechas de habilitacion y Master usan el dia operativo
  de app.display_timezone (Lima), sin modificar timestamps ni timezone global.
  Vence hoy sigue vigente durante ese dia; una programacion de manana no puede
  cerrarse hoy aunque UTC ya haya cambiado de fecha.
- Editar/desactivar asignacion, generar examenes, sincronizar cursos y recalcular
  guardan en transaccion con su historial. Escrituras de examen bloquean primero
  la asignacion; asignar serializa mediante el trabajador existente.
- `MiningTransactionVisibilityTest` cubre conexiones MySQL independientes;
  para citas EMO se agregaron conflicto concurrente y revision obsoleta.
  El rendimiento con volumen realista y las mejoras generales de dashboard
  siguen siendo verificaciones separadas de este control de asistencia.

## Correcciones desde la revision

La vista previa conserva por dos horas una copia privada de las filas leidas.
`Revisar de nuevo` recalcula el archivo entero contra el catalogo e historial
actuales, genera un token nuevo e invalida el anterior. Si el examen y la mina
se reconocen sin otros errores y solo falta el requisito activo, un usuario con
permisos `importar` y `configurar` puede confirmar ese requisito desde la fila;
despues se vuelve a revisar todo el archivo. La carga sigue bloqueada mientras
haya observaciones. El requisito se sincroniza por lotes mediante el scheduler.
Esto no crea examenes ni fusiona nombres parecidos automaticamente.

## Revision y confirmacion (2026-09-18)

- Vistas separadas: Cambios propuestos, Con observaciones, Sin cambios y Todas.
- Confirmacion explicita con token privado del usuario (vigencia de dos horas),
  permisos importar y programar/registrar segun cada cambio.
- Por seguridad, cualquier fila observada bloquea toda la confirmacion; no se
  aplican cargas parciales.
- Revalida referencias, catalogo e historial dentro de una transaccion y rechaza
  vistas previas obsoletas. El navegador no envia las filas a guardar.
- Guarda cursos independientes en personal_curso_registros, con autor, origen,
  fechas y snapshot del catalogo. No crea minas, trabajadores ni contratos.
- Cada fila indica una mina destino. Al confirmar, el registro se refleja solo
  en esa mina cuando el `examen_id` esta configurado como requisito activo. Si
  el examen aun no fue generado, genera solo ese requisito y recalcula la
  habilitacion sin alterar los demas requisitos.
- Cada intento minero reflejado conserva `personal_curso_registro_id` como
  origen. La operacion es idempotente: volver a cargar el mismo resultado no
  duplica intentos. La vista previa muestra las minas pendientes de actualizar.
- Una asignacion minera creada despues reutiliza solo el historial dirigido a
  esa mina. Los registros historicos anteriores a esta regla, sin mina
  identificable, se conservan y no se reinterpretan silenciosamente.
- Aplicar un resultado a otra mina es una convalidacion separada y confirmada:
  exige el mismo trabajador, el mismo examen o un equivalente autorizado en el
  requisito destino, vigencia, permiso de convalidar y
  que tanto el catalogo como el requisito de destino permitan convalidar. El
  scope de mina de otros modulos no restringe esta accion.
- En el detalle existente de examenes del trabajador, `Convalidar` muestra bajo
  demanda los resultados elegibles con examen y mina origen, nota o sin nota,
  fecha y vencimiento. Muestra tambien examen y mina destino. El usuario elige
  uno y confirma; la carga masiva no convalida automaticamente.
- Las equivalencias se configuran por requisito de mina en Acciones > Configurar
  examenes por mina > Convalidacion y equivalencias. No son coincidencias de
  texto ni equivalencias automaticas en ambos sentidos. La nota se copia si
  existe; solo se exige cuando el examen destino la requiere, validando su minimo.
  Se conserva snapshot de origen, destino y nota al confirmar.
- Une programacion y resultado compatibles y completa pendientes existentes;
  el permiso funcional de habilitacion minera rige tambien al completar
  pendientes, sin aplicar scope de mina de otros modulos.
- Reimportaciones equivalentes se omiten y resultados diferentes no sobrescriben
  los existentes. El maximo de intentos es una referencia operativa: no bloquea
  intentos adicionales y se muestra el total proyectado, por ejemplo `3/2`.
- APTO CON RESTRICCION requiere observacion. Se guarda como APROBADO con prefijo
  APTO CON RESTRICCION en la observacion, conservando el detalle. Disponible
  tambien en registro manual y cierre de programados. No habilita una mina que
  tenga otros requisitos pendientes ni evita validar nota minima o vencimiento.
- Migraciones aditivas: 2026_09_18_000100_create_personal_curso_registros.php y
  2026_09_18_000200_link_mining_attempts_to_course_records.php. No eliminan ni
  reemplazan historial existente; el segundo cambio solo agrega el vinculo de
  origen a los intentos mineros.
- `2026_09_21_000200_add_mina_to_personal_curso_registros.php` agrega la mina de
  origen/destino. Solo completa registros antiguos cuando su trazabilidad apunta
  inequivocamente a una mina; los ambiguos permanecen sin mina para revision.

## Revision integral del flujo (2026-09-21)

- La busqueda de trabajadores y los filtros de cada pestana usan respuestas
  AJAX selectivas: el servidor solo consulta y renderiza el panel visible. La
  busqueda por documento usa prefijo y la busqueda por nombre, puesto u
  ocupacion se ejecuta mientras se escribe, sin pantalla de carga completa.
- Los formularios operativos bloquean el doble envio y muestran progreso en el
  boton. El overlay completo queda reservado para revisar o confirmar archivos
  Excel, que son operaciones largas.
- El estado HABILITADO compara todos los requisitos obligatorios activos contra
  sus examenes generados. Si falta generar siquiera uno, permanece EN_PROCESO;
  aprobar otro requisito no puede ocultar ese faltante.
- `habilitacion-minera:sincronizar-vigencias` se ejecuta diariamente a las
  00:10, actualiza POR_VENCER/VENCIDO y recalcula la asignacion. Los cambios
  automaticos dejan historial con usuario de sistema nulo y no modifican
  contratos, estado laboral ni datos personales. Requiere que el scheduler de
  Laravel este activo en el servidor.
- La validacion de conflictos de la carga masiva agrupa previamente por
  trabajador y examen, evitando recorrer nuevamente todas las filas por cada
  grupo.
- La migracion aditiva
  `2026_09_21_000100_add_habilitacion_mining_query_indexes.php` agrega indices
  para asignaciones activas, estados/vencimientos, intentos, requisitos y
  cursos globales. No borra ni transforma registros existentes.

## Lectura del archivo

- Acciones > Carga de habilitacion masiva, permiso habilitacion_minera.importar.
- XLSX de hasta 10 MB, dos hojas en orden: programaciones y resultados.
- Formato v2 de programaciones, en este orden: `DNI`, `APELLIDOS Y NOMBRES`,
  `CURSO/EMO`, `PROGRAMACION`, `CLINICA`, `MINA`.
- Formato v2 de resultados: `DNI`, `APELLIDOS Y NOMBRES`, `CURSO/EMO`,
  `PROGRAMACION`, `REALIZACION`, `RESULTADO`, `CLINICA`, `NOTA`,
  `VENCIMIENTO ANUAL`, `OBSERVACION`, `MINA`.
- Los formatos anteriores siguen validos para cursos. Un EMO sin formato v2,
  fecha de programacion o clinica activa queda observado y bloquea el archivo.
- Maximo 3000 filas por hoja y 30 columnas.
- Cruce de Personal por documento; no crea trabajadores.
- Cruce exacto de examenes activos, normalizando solo tildes, espacios y
  mayusculas. Inexistentes, inactivos y ambiguos se reportan, no se crean.
- El nombre del Excel se compara con Personal y se muestran ambos sin editarlo.
- DNI de ocho digitos: nunca completar documentos cortos automaticamente.
- Revision de notas numericas no negativas, fechas invalidas o futuras de
  realizacion, vencimiento anterior a realizacion, duplicados en ambas filas,
  multiples notas para el mismo intento y programaciones ambiguas. Una nota
  antigua no se rechaza por existir una programacion posterior.
- Cruce exacto de mina operativa y validacion de que el curso o EMO sea un
  requisito activo de esa mina. Minas desconocidas o combinaciones no
  configuradas quedan observadas y no se guardan.
- Resumen y filtro local por DNI, examen, mina, identidad y datos del archivo.
  Los conteos son filas afectadas, no personas o examenes unicos.
- Revision visual con accesos por categoria, buscador inmediato, filtro de hoja,
  paginacion y detalles desplegables. Los filtros permanecen disponibles sin
  coincidencias. CSS y JavaScript propios en public/css y public/js/bulk-review.
- Prueba visual reproducible: tests/Browser/bulk-review.cjs (Playwright y Chrome),
  con datos ficticios generados por render-bulk-review.php, sin acceso a la BD.
- Fechas Excel o textos dd/mm/aaaa e ISO. Solo se evalua la formula local
  de vencimiento D[fila]+365 del formato; otras formulas requieren revision.
- La revision no modifica catalogos, asignaciones, intentos, contratos ni
  estados laborales. La escritura solo ocurre en el endpoint de confirmacion.

## Organizacion del catalogo (2026-10-05)

Acciones > Organizar examenes permite confirmar variantes del mismo curso y
aliases globales o por mina. Ambos formatos Excel usan esa identidad para elegir
el requisito activo de la mina destino, no necesariamente el ID cuyo nombre
aparece en el archivo. Dos requisitos activos del mismo grupo se observan como
ambiguos. Parecidos sin confirmar solo se sugieren: el Master requiere revisar
el catalogo antes de crear otro curso similar. Los aliases no renombraran el
principal administrado ni sobrescribiran sus reglas de convalidacion.

El historial sin mina sigue observado; no se inventa un origen ni se convalida al
agrupar. Resultados repetidos conservan la comprobacion de duplicados por
trabajador, examen destino, mina y fecha. Instalacion y operacion:
`docs/FTP_ORGANIZAR_EXAMENES.md`.

## Citas EMO y clinicas (2026-10-08)

La categoria operativa del examen (`CURSO`, `EMO`, `OTRO`) determina la regla;
no se deduce de su nombre ni del campo descriptivo `tipo`. La clinica se
resuelve por nombre normalizado exacto entre las activas, sin crear catalogos.
Para cursos, `CLINICA` se ignora. En resultados EMO, `REALIZACION` no reemplaza
la fecha `PROGRAMACION` que identifica la cita.

Dos filas de una persona el mismo dia en clinicas distintas bloquean todo el
archivo. Una cita puede cubrir varias minas incluidas explicitamente solo si
coinciden trabajador, fecha, clinica y `examen_id` exacto. Los alias no permiten
compartir una cita entre examenes distintos. La confirmacion vuelve a comprobar
catalogos, enlaces y revision de cita; cualquier fallo revierte toda la carga.
Reimportar informacion identica omite duplicados sin sobrescribir resultados.
La fila de resultado exige que su mina ya este enlazada a la cita o tenga una
programacion explicita compatible en el mismo archivo; no basta programar otra
mina. Un EMO historico pendiente el mismo dia bloquea la vista previa y exige
regularizacion. Una nota opcional no utilizada por el snapshot se compara como
nula al comprobar duplicados; no convierte una reimportacion identica en cambio.

Los resultados son `APTO`, `NO_APTO`, `APTO_CON_RESTRICCION` y
`APTO_CON_OBSERVACION`; los dos ultimos requieren detalle. El valor medico
exacto queda en la cita y los intentos conservan aprobado/desaprobado.
El normalizador historico tambien reconoce singular/plural, tildes y guiones
bajos de restriccion/observacion antes de aplicar reglas genericas de texto.
`NO_APTO` sigue siendo desaprobado.

La reparacion de datos legacy no se ejecuta al consultar pantallas. El comando
`php artisan habilitacion-minera:repair-legacy-emo` es dry-run por defecto y solo
lista casos inequivocos. La escritura requiere `--apply`, `--actor=UUID` y
`--motivo="..."`; registra actor, motivo y valores antes/despues en el historial.
Debe ejecutarse primero sin `--apply`, revisar los candidatos y contar con backup.

El Master v1 nunca crea ni completa citas. `PROGRAMAR EMO`, incluso con fecha,
queda como accion pendiente en `EN_PROCESO`. `SEDE`, `LUGAR` y `CLINICA` no
crean una reserva ni alteran `lugar_snapshot` por ese texto. Para programar se
usa la accion manual o el formato masivo v2. El historial sin clinica se
conserva y debe regularizarse explicitamente antes de cerrar un EMO pendiente.
Si ya existe una aprobacion vigente, el Master conserva el resultado, fechas,
nota, lugar y contexto actuales; la solicitud de programar sigue indicada en
la vista previa y no sustituye la aprobacion por pendiente.

## Pendientes de integracion

1. Mantener el bloqueo del archivo completo cuando existan observaciones,
   conforme a la decision confirmada; no ofrecer importacion parcial.
2. Mantener validacion exacta de catalogo y vigencia en cargas masivas. Las
   equivalencias entre examenes distintos requieren configuracion y convalidacion
   explicita posterior, sin coincidencias aproximadas silenciosas.
3. Identificar explicitamente ciclos de renovacion del curso global para los
   reportes, sin impedir el registro de intentos adicionales.
4. Verificar despliegue y migraciones aditivas en hosting antes de usar confirmacion.

## Verificacion inicial

## Regla aclarada por el usuario (2026-09-21)

El historial conserva el curso del trabajador y tambien la mina para la cual se
registro. Puede cargarse antes de asignar al trabajador, pero se aplicara
automaticamente solo cuando sea asignado a esa misma mina. Reutilizarlo en una
mina diferente debe pasar por convalidacion explicita; nunca se propaga en masa.

PersonalCursoImportPlanner realiza solo el cruce de vista previa por
trabajador + examen + mina + fecha, sin consumir intentos de otras minas.
Une programacion y nota compatibles del Excel, identifica pendientes antiguos,
mantiene separadas programaciones posteriores y reporta coincidencias ambiguas.
No sobrescribe resultados ni modifica intentos. Las coincidencias de resultados
finalizados todavia requieren comprobar vencimiento y observacion antes de
considerarlos duplicados definitivos en el futuro guardado.

PersonalMinaBulkPreviewTest cubre referencias, identidad, duplicados, fechas,
notas, formulas, estructura, filtros renderizados y escape de contenido HTML.
Adjunto revisado: seis programaciones y seis resultados, fechas de vencimiento
calculadas correctamente. Blade compilado y ruta registrada.

## Archivos de esta implementacion

- app/Models/PersonalCursoRegistro.php
- app/Modules/Personal/Support/CursoResultado.php
- app/Modules/Personal/Services/PersonalCursoBulkService.php
- app/Modules/Personal/Services/PersonalCursoImportPlanner.php
- app/Modules/Personal/Services/PersonalMinaBulkPreviewService.php
- app/Modules/Personal/Services/PersonalMinaHabilitacionService.php
- app/Modules/Personal/Controllers/PersonalMinaHabilitacionController.php
- database/migrations/2026_09_18_000100_create_personal_curso_registros.php
- database/migrations/2026_09_18_000200_link_mining_attempts_to_course_records.php
- routes/web.php
- resources/views/personal/habilitacion-minera/bulk-preview.blade.php
- public/js/bulk-review.js
- public/css/bulk-review.css
- tests/Unit/PersonalMinaBulkPreviewTest.php
- tests/Feature/PersonalMinaHabilitacionCorrectedFlowTest.php
- tests/Browser/render-bulk-review.php
- tests/Browser/bulk-review.cjs
- docs/HABILITACION_CARGA_MASIVA.md

Validacion de intentos adicionales: pruebas de registro manual, carga masiva,
recuperacion posterior mediante aprobacion y Playwright en 1440 y 390 px.
Suite funcional del modulo: 80 pruebas y 610 aserciones aprobadas; incluye
asignacion, requisitos, recálculo temporal, intentos adicionales,
vencimientos, permisos, master Excel, carga masiva, aplicacion exacta en la mina
indicada y filtros AJAX. No se importo el archivo del usuario ni se desplego a FTP
como parte de esta revision.
