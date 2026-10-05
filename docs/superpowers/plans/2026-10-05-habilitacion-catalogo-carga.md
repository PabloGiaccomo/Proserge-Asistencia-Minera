# Habilitacion minera: catalogo y carga masiva

> Plan de implementacion. No ejecutar importaciones ni consolidaciones de datos sin una copia verificable de MySQL y archivos privados.

**Objetivo:** quitar bloqueos al configurar requisitos, permitir reconocer variantes de nombres con confirmacion humana y administrar mina/examen desde la revision de carga masiva sin alterar aprobaciones historicas.

**Arquitectura actual:** `examenes_mineros` define el catalogo; `mina_requisitos` lo exige por mina; `personal_mina_examenes`, sus intentos y `personal_curso_registros` conservan resultados asociados a `examen_id`. La carga masiva revisa antes de guardar y bloquea el archivo entero si alguna fila tiene observaciones.

**Fuente:** `docs/CODEX_CONTEXT.md`, `docs/BUSINESS_RULES_RRHH.md`, `docs/HABILITACION_CARGA_MASIVA.md` y rutas/servicios vigentes al 2026-10-05.

## Evidencia y limites

- `storeRequirement()` y `deactivateRequirement()` llaman a `syncMineAssignmentsForRequirementChange()`. Esta funcion recorre todas las asignaciones activas de la mina, genera examenes, recalcula estados y puede emitir notificaciones, todo antes de responder HTTP. Los logs locales del 2026-10-05 muestran dos errores por 60 segundos; hay que instrumentar la peticion exacta antes de atribuirle cada error.
- El boton `Quitar` usa `fetch` y espera JSON. Un timeout puede dejar el boton en estado pendiente y la BD en un estado parcialmente modificado. `storeRequirement()` tambien hace sincronizacion sincrona.
- `PersonalMinaBulkPreviewService::normalizedName()` solo elimina diferencias de tildes, espacios y mayusculas. Una letra adicional no coincide. Es correcto que no fusione automaticamente posibles examenes distintos.
- `bulk-preview.blade.php` ya tiene `Aplicar cambios`, deshabilitado si hay observaciones. `PersonalCursoBulkService::apply()` revalida dentro de transaccion y no admite cambios parciales. Esa regla se conserva.
- Los indices unicos y referencias por `examen_id` impiden tratar los duplicados como simples filas borrables. Las aprobaciones, precios, intentos y snapshots historicos deben permanecer legibles.
- El Master Excel puede volver a modificar el catalogo y la convalidacion. Cualquier correccion manual tiene que especificar como convivira con el proximo Master.

## Fase 1: operaciones de requisito sin timeout

**Avance al 2026-10-05:** el alta/baja web ya registra el requisito y una tarea durable por mina en una transaccion; baja de inmediato los `HABILITADO` a `EN_PROCESO` con historial, y el scheduler procesa lotes de 20 con cursor y reintento, hasta diez pasadas o 40 segundos por ejecucion. La configuracion muestra progreso. Faltan medicion con volumen realista, prueba de concurrencia y comprobacion del cron en cPanel antes del despliegue.

**Archivos probables:** `app/Modules/Personal/Services/PersonalMinaHabilitacionService.php`, `app/Modules/Personal/Controllers/PersonalMinaHabilitacionController.php`, `resources/views/personal/habilitacion-minera/index.blade.php`, pruebas en `tests/Feature/PersonalMinaHabilitacionCorrectedFlowTest.php`.

1. Medir en copia de datos realista el tiempo y numero de consultas de agregar/quitar requisito; identificar recalculo, generacion y notificaciones. Registrar solo IDs y duraciones, sin datos personales.
2. Probar primero que agregar/quitar afecta exclusivamente la mina y requisitos elegidos, responde en tiempo acotado con muchas asignaciones y no deja estados incoherentes si falla. Incluir doble clic y dos solicitudes concurrentes.
3. Separar el cambio de configuracion del recalculo masivo mediante el mecanismo fiable que ya use el proyecto (scheduler/cola, segun despliegue real). Guardar trabajo pendiente durable e idempotente; no depender de `setTimeout`, una peticion HTTP larga ni solo memoria. Si se usa scheduler, verificar que corre en cPanel. Publicar estado `Actualizando habilitaciones` y resultado/error/reintento; no mostrar exito final antes de completar.
4. Hacer atomicamente la desactivacion/configuracion y la marca de sincronizacion pendiente. No borrar `personal_mina_examenes` ni intentos. Recalcular en lotes, con notificaciones finales deduplicadas y reanudacion si el proceso se interrumpe.
5. Corregir el formulario AJAX para restaurar el boton y mostrar un error operativo en fallos/timeout; al reintentar, consultar estado real antes de repetir. Compilar Blade y probar desktop/mobile.

**Criterio de salida:** una mina con volumen comparable a produccion puede agregar/quitar un requisito sin timeout; los estados terminan consistentes y el operador conoce el progreso.

## Fase 2: convalidacion explicita por trabajador y mina

**Estado al 2026-10-05:** implementada y cubierta por pruebas Feature. El modal existente ofrece accion explicita, consulta candidatos bajo demanda y exige confirmacion. La escritura revalida y bloquea el destino en transaccion; no une examenes con distinto ID.

**Archivos probables:** `resources/views/personal/habilitacion-minera/index.blade.php`, `PersonalMinaHabilitacionController`, `PersonalMinaHabilitacionService`, rutas web y `PersonalMinaExamenesMinerosTest`.

1. Reutilizar el modal existente `Examenes de [trabajador] en [mina]`. En cada examen destino pendiente y permitido, mostrar boton `Convalidar` solo a usuarios con `habilitacion_minera,convalidar`. La ruta POST ya existe, pero la interfaz actual solo define su URL y no ofrece la accion.
2. Al abrir el panel, consultar bajo demanda los origenes elegibles del mismo trabajador: **mismo `examen_id`**, otra asignacion, resultado aprobado y vigente, mina origen permitida por el requisito destino, ventana de convalidacion vigente y asignacion destino `EN_PROCESO`. No cargar historiales de todos los trabajadores en el HTML inicial.
3. Mostrar por candidato mina origen, fecha de realizacion, vencimiento y resultado. El usuario selecciona uno y confirma expresamente `Convalidar examen`; ninguna sugerencia o importacion lo aplica sola. Si no hay origen, mostrar por que no es posible y la siguiente accion (registrar/rendir, corregir catalogo o esperar permiso), sin boton operativo engañoso.
4. Reutilizar `PersonalMinaHabilitacionService::convalidateExam()` para guardar `examen_origen_convalidado_id`, mina/fecha/usuario y recalcular el destino. La consulta de opciones es ayuda de UX: el POST vuelve a validar permiso y todas las reglas, rechaza estado cambiado/origen vencido/convalidacion repetida y no depende de `scope de mina` de otros modulos.
5. Pruebas red/green: boton visible/oculto por permiso, origen valido, sin candidatos, examen diferente o solo parecido por nombre, origen vencido, otro trabajador, mina excluida, doble envio y acceso POST directo. Comprobar que un resultado aprobado existente no cambia de estado por mostrar el panel.

**Criterio de salida:** un operador autorizado puede ver, elegir y confirmar un origen valido sin escribir IDs, y despues ve el examen destino `CONVALIDADO` con su trazabilidad. La accion no aparece para un examen no convalidable.

## Fase 3: diagnostico y conciliacion de examenes

**Avance al 2026-10-05:** se agrego un diagnostico de solo lectura bajo demanda en el modal de configuracion. Compara nombres normalizados o con hasta dos ediciones de diferencia, muestra diferencias basicas de reglas y conteos de requisitos, examenes de trabajadores, intentos, cursos globales y precios por `examen_id`. Solo sugiere; no fusiona, desactiva ni reescribe historial. La conciliacion aprobada, alias persistentes y colisiones siguen pendientes de decision operativa y pruebas con datos reales.

**Archivos probables:** `PersonalMinaBulkPreviewService`, `PersonalMinaExcelImportService`, modelos de examen/requisito, una migracion aditiva solo si se necesita persistir alias o consolidaciones, y pruebas de importacion.

1. Generar reporte solo lectura, por `examen_id`: nombre, tipo, vigencia, nota minima, reintentos, precios, minas/requisitos, trabajadores, aprobaciones vigentes, intentos, cursos globales y referencias de convalidacion. Clasificar variantes exactas, variantes normalizadas y nombres parecidos como *sugerencias*, nunca equivalencias automaticas.
2. Definir un examen canonico por cada grupo confirmado por responsable de habilitacion. Mostrar efectos previstos y colisiones por mina y trabajador. Si difieren reglas (EMO/curso, vigencia, nota, criticidad), bloquear la fusion hasta decision explicita.
3. Para la carga futura, mantener `examen_id` canonico y un alias aprobado de nombre del Excel. Una coincidencia aproximada solo presenta candidatos: el operador selecciona y confirma uno. Cero o multiples candidatos mantienen la fila observada y bloquean toda la confirmacion.
4. Para duplicados con historial, ofrecer `Retirar del catalogo` en vez de borrado fisico. Antes de redirigir requisitos activos hacia un canonico, simular colisiones con el indice unico `personal_mina_id + examen_id`; preservar aprobaciones vigentes, intentos, snapshots, precios y origen de convalidacion. Ejecutar solo grupos sin conflicto en transaccion auditada; grupos conflictivos quedan pendientes de revision.
5. Recalcular solo asignaciones afectadas mediante la fase 1. Revalidar vigencia/resultados; nunca marcar una mina `HABILITADO` por mera similitud de texto ni convalidar entre minas automaticamente. El Master posterior debe respetar alias/decisiones aprobadas o advertir y bloquear la sustitucion incompatible.

**Criterio de salida:** `ESPACIO CONFINADO` y `ESPACIOS CONFINADOS` pueden proponerse como equivalentes, pero solo una aprobacion explicita permite usar el mismo catalogo. Las habilitaciones e historiales previos siguen consultables.

## Fase 4: resolver observaciones desde carga masiva

**Avance al 2026-10-05:** `Revisar de nuevo` reconstruye todas las filas del origen privado, renueva el token y descarta el anterior. Una fila cuyo unico problema es un requisito faltante con mina y examen exactos ofrece configurarlo con confirmacion y permisos `importar` + `configurar`; la vista se recalcula completa. Siguen pendientes alias aprobados, creacion controlada de examenes verdaderamente nuevos y correcciones de mina no reconocida dentro de esta pantalla.

**Avance al 2026-10-05:** la confirmacion de cursos ya no depende del scope de mina de otros modulos; conserva los permisos `importar`, `programar` y `registrar`. La correccion de catalogo desde esta vista y la conciliacion de alias siguen pendientes.

**Archivos probables:** `resources/views/personal/habilitacion-minera/bulk-preview.blade.php`, `public/js/bulk-review.js`, `public/css/bulk-review.css`, `PersonalMinaHabilitacionController`, `PersonalMinaBulkPreviewService`, pruebas Feature/Browser.

1. En cada observacion de examen o mina, mostrar acciones contextuales con permisos `habilitacion_minera,configurar`: elegir alias/canonico, crear examen realmente nuevo o configurar ese examen como requisito de la mina. Reutilizar los formularios/servicios actuales en modal o panel lateral de la misma pagina; no mezclar la configuracion con `Aplicar cambios`.
2. Antes de guardar una accion, mostrar mina, examen, trabajadores/filas afectados, consecuencias y posible conflicto. El backend valida permiso, mina operativa, identidad unica e idempotencia; deja auditoria de actor y momento.
3. Tras cada correccion, invalidar el token de revision anterior y recalcular **toda** la vista previa desde el archivo privado temporal del mismo usuario. No reusar filas calculadas con el catalogo anterior. Conservar el archivo solo el tiempo necesario y limpiarlo al confirmar/vencer. Si una fila corresponde a un examen ya aprobado en otra mina, ofrecer acceso al panel de convalidacion de la fase 2, pero nunca convalidar implicitamente al importar.
4. Mantener `Aplicar cambios` deshabilitado hasta que haya cero observaciones, confirmacion expresa y fingerprint vigente. Separar visualmente `Guardar configuracion` de `Confirmar carga` para evitar aplicaciones accidentales.
5. Cubrir permisos, alias ambiguos, mina no configurada, cambio concurrente del catalogo, archivo vencido, doble clic, importacion repetida y fallo de BD. Verificar la pagina sin recarga completa para acciones pequenas.

**Criterio de salida:** el usuario resuelve la configuracion sin salir de la revision, vuelve a ver todas las filas recalculadas y solo entonces confirma la carga completa.

## Despliegue de hoy: puerta de seguridad

1. Congelar cambios ajenos a este modulo y comparar codigo, migraciones, `public/build`/assets y schema de produccion. La rama de trabajo actual contiene cambios de muchos modulos; no subirla entera por FTP.
2. Hacer backup verificable de MySQL y `storage/app/private`; probar migraciones aditivas y la carga con copia anonima/realista. No ejecutar `migrate:fresh`, SQL de borrado ni consolidacion automatica.
3. Solo habilitar `Confirmar carga` si el archivo muestra cero observaciones, catalogo/minas revisados, permisos correctos, tiempos dentro del limite y pruebas de regresion aprobadas. Confirmar primero un archivo pequeno y comparar conteos/habilitaciones antes de la carga completa.
4. Si la fase 1 o las equivalencias todavia fallan, permitir solo revision del Excel y reportes; aplazar la escritura masiva. Un aumento de `max_execution_time` no es solucion de integridad.

## Verificacion minima por fase

- `php artisan test --filter PersonalMinaHabilitacionCorrectedFlowTest`
- `php artisan test --filter PersonalMinaExamenesMinerosTest`
- `php artisan test --filter PersonalMinaBulkPreviewTest`
- `php artisan view:cache`, `php -l` en PHP editado, `git diff --check`.
- Prueba manual con mina grande, Excel con nombre exacto/parecido/ambiguo, examen aprobado historico, duplicado con intentos y dos usuarios con permisos distintos.

**Decisiones operativas por confirmar:** quien autoriza equivalencias/fusiones, si el Master es fuente de verdad tambien para alias aprobados, y si el scheduler/cola de produccion puede procesar trabajos durables de sincronizacion. Ninguna de ellas debe resolverse por semejanza automatica de nombres.
