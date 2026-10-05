# Carga masiva de cursos y EMO

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
  exige el mismo trabajador, el mismo examen, vigencia, permiso de convalidar y
  que tanto el catalogo como el requisito de destino permitan convalidar. El
  scope de mina de otros modulos no restringe esta accion.
- En el detalle existente de examenes del trabajador, `Convalidar` muestra bajo
  demanda los resultados elegibles con mina origen, fecha y vencimiento. El
  usuario elige uno y confirma; la carga masiva no convalida automaticamente.
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
- Encabezados obligatorios: programaciones usa `DNI`, `APELLIDOS Y NOMBRES`,
  `CURSO/EMO`, `PROGRAMACION`, `MINA`; resultados agrega `MINA` despues de
  `OBSERVACION`.
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

## Pendientes de integracion

1. Confirmar politica de cargas parciales cuando existan observaciones.
2. Mantener validacion del mismo examen de catalogo y vigencia al vincularlo a
   los requisitos de una mina, sin coincidencias aproximadas silenciosas.
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
