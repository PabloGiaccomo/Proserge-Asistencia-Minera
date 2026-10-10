# Habilitacion minera: registro de ejecucion

Plan: `2026-10-06-habilitacion-minera-revision-integral.md`.
Estado: en curso. No se han ejecutado importaciones, migraciones, push ni FTP.

## Decisiones tecnicas

- Ruling: trabajar en el checkout existente, conservando los cambios heredados.
  Motivo: las correcciones dependen de codigo aun sin confirmar. No crear un
  checkout limpio que omita ese trabajo. Riesgo: revisar cuidadosamente el diff
  para no atribuir ni revertir cambios ajenos.
- Ruling: no crear commits automaticos. El plan exige autorizacion explicita.
- Ruling: medir volumen y completar el inventario visual antes de aceptar las
  etapas de rendimiento y UX; no declarar terminado el baseline por ejecutar
  solamente pruebas funcionales.

## Avance comprobado

### Tarea 0: parcial

- Git status revisado; cambios previos conservados.
- Pruebas con APP_ENV=testing, DB_DATABASE=proserge_app_test y DB_URL vacio.
- Conexion efectiva verificada mediante DB::connection()->getDatabaseName():
  proserge_app_test. No se utilizo informacion real para fixtures.
- Baseline previo: 150 casos, 147 pasan y tres fallan con reloj America/Lima.
- Pendientes: reloj determinista, benchmark e inventario completo de botones.

### Tarea 1: identidad, implementada

- Resolver con opcion explicita para inactivos del Master; carga operativa sigue
  exigiendo activos. Alias confirmado conserva ID y nombre principal.
- Activos tienen prioridad sobre homonimos inactivos no relacionados.
- Claves del Master respetan limites de palabras: EMO A no equivale a EMOA.
- Archivos: MiningExamIdentityResolver, PersonalMinaExcelImportService y tests
  MiningExamIdentityResolverTest/MiningExamIdentityIntegrationTest.
- RED observado en tres regresiones originales y nuevos casos del resolver.
- GREEN: filtro MiningExam, 33 tests y 112 assertions.
- Interfaz: sin cambios. Comprobar preview de alias inactivo y nombres parecidos.

### Tarea 2: origen de cursos, implementacion principal

- Curso historico sin mina permanece intacto y no se aplica automaticamente.
- Sincronizacion directa lo rechaza; asignacion futura busca solo su propia mina.
- Planner no propone reflejar registros sin origen en todas las minas.
- Archivos: PersonalMinaHabilitacionService, PersonalCursoBulkService y
  PersonalMinaHabilitacionCorrectedFlowTest.
- RED: tres regresiones reproducidas. GREEN: seis tests, 41 assertions,
  incluyendo reutilizacion posterior en la misma mina.
- Pendiente: inventario solo lectura de cursos sin origen y reflejos existentes.
- Interfaz: sin cambios. Comprobar que asignar otra mina no habilita el curso.

### Tarea 3: correcciones principales verificadas, parcial

- RED: tres casos del Master reproducidos: fallo ocultado, nota sobrescrita y
  resultado antiguo sustituyendo uno posterior.
- Correccion implementada: lock del examen, numeracion fresca de intentos,
  propagacion de excepciones para rollback, conflicto de nota y orden historico.
- Elegibilidad comun antes de escribir y dentro del lock: asignacion, mina
  operativa, requisito y examen activos; requisito corresponde al examen/mina.
- NO_APLICA atomico con recalculo e historial. Observacion opcional conservada.
- RED: 16 contextos inactivos permitian escribir y un fallo de recalculo dejaba
  NO_APLICA guardado. GREEN: suite minera 176 tests, 1041 assertions.
- Archivos: servicios Habilitacion/ExcelImport y tests CorrectedFlow/IdentityIntegration.
- Pendientes: serializacion de asignacion/generacion, atomicidad de editar y
  desactivar asignacion, y revision ampliada de conflictos del Master.

### Tarea 4: renovacion y restricciones verificadas, parcial

- Ruling: resolver la renovacion aprobada por el usuario como cambio independiente
  antes de terminar toda la concurrencia de tarea 3. Motivo: no altera permisos ni
  exige decisiones pendientes. Riesgo: no presentar esta etapa como terminada.
- Programacion/desaprobacion nueva conserva aprobacion vigente, fechas, nota y
  restriccion; el intento nuevo se conserva. Al vencer permite un nuevo intento.
- Misma regla compartida por registro manual, cierre programado, cursos y Master.
- Nueva realizacion limpia metadatos de convalidacion y primero archiva snapshot
  en personal_mina_historial, con actor. Se reutiliza la tabla existente.
- Convalidacion conserva restricciones de origen con o sin comentario adicional.
  Texto combinado demasiado largo se rechaza sin truncarlo ni guardar parcialmente.
- RED observado en renovacion manual/cursos/Master, origen falso y perdida de
  observacion de origen. GREEN: suite minera 183 tests, 1082 assertions.
- Archivos: servicios Habilitacion/ExcelImport y tests CorrectedFlow/IdentityIntegration.
- Interfaz: sin cambios en este lote. Pendientes: dia operativo UTC/Lima y
  conteos de ciclos, inventario legado y validacion visual de siguientes etapas.

### Revision independiente y correcciones

- Revision de solo lectura detecto dos regresiones. Se reprodujeron con pruebas
  fallidas antes de corregirlas; GREEN focalizado: dos tests, 15 assertions.
- Reincorporar un requisito conserva el examen, intentos y snapshots existentes,
  enlaza el requisito activo bajo lock y registra las referencias anterior y
  actual en el historial. No recalifica notas ni cambia vigencias historicas.
- Programar renovacion notifica desde el intento nuevo, no desde la aprobacion
  efectiva conservada. OperationalNotificationService acepta intento opcional;
  se conserva compatibilidad con llamadas anteriores de dos argumentos.
- Archivos adicionales: OperationalNotificationService.php. Regresiones agregadas
  en PersonalMinaHabilitacionCorrectedFlowTest.php.
- Ruling: adelantar solo la reconciliacion de referencia de tarea 5 para no dejar
  bloqueada la operacion al aplicar la guarda de inactivos. Motivo: regresion
  confirmada por revision. Riesgo: mantener snapshots antiguos, no reinterpretar
  reglas nuevas mientras la decision de negocio sobre configuracion siga pendiente.

## Continuacion 2026-10-07: integridad y dia operativo

- Editar y desactivar asignacion guardaban antes de registrar historial. RED:
  dos fallos de historial y uno de notificacion dejaban cambios parciales.
- Update/deactivate ahora recargan bajo lock y guardan cambio e historial en
  transaccion. Se conserva actor, estado previo y examenes. GREEN focalizado:
  cinco tests, 24 assertions. No se cambian reglas de habilitacion ni permisos.
- RED: vencimiento anticipado UTC/Lima, fecha de asignacion adelantada,
  cierre de programacion de manana y convalidacion fuera de rango falsamente.
  GREEN manual: cuatro tests, 16 assertions, con servidor UTC y reloj fijo.
- Master tambien reprodujo vencimiento anticipado; usa el mismo timezone
  operativo. Las columnas DATE se comparan como fechas del calendario local,
  no como instantes UTC. Los timestamps y timezone global no se modifican.
- Archivos: servicios PersonalMinaHabilitacionService/PersonalMinaExcelImportService
  y tests CorrectedFlow/MiningExamIdentityIntegrationTest. Sin cambios de UI.
- RED adicional: generacion parcial, cuatro escrituras de examen bloqueando
  primero al hijo, generacion sin lock y asignacion sin padre existente bloqueado.
  Asignar ahora serializa con Personal; generar/programar/registrar/completar/
  NO_APLICA bloquean la asignacion antes del examen. Convalidacion bloquea ambas
  asignaciones en orden de ID; Master usa tambien asignacion antes del examen.
- Generacion es transaccional. Sincronizar un curso y recalcular directamente
  tambien requerian transaccion propia: dos pruebas RED mostraron aprobaciones,
  intentos o vigencias guardados tras fallar historial. GREEN: once tests y
  29 assertions, incluyendo orden SQL y segundo intento de asignacion.
- Suite intermedia: 243 tests y 1335 assertions, sin fallos. Pendientes de este
  lote: verificacion final. Suite siguiente: 254 tests y 1364 assertions pasan;
  repeticion con JUnit: 45.965 s. Otro run reporto una duracion anomala; no usar
  esos tiempos como benchmark de la interfaz ni atribuirlos al hosting.

### Revision independiente: lecturas y orden de locks

- Revision parcial de solo lectura detecto cinco riesgos bajo Repeatable Read:
  asignacion desactivada recuperada del snapshot en convalidacion; sincronizacion
  sustituyendo aprobacion reciente; generacion que no ve un examen confirmado;
  numero de intento consultado mediante MAX antiguo; Bulk bloqueando intento
  antes de asignacion. Se incluye el recalc que podia volver a EN_PROCESO con
  una aprobacion nueva confirmada desde otra conexion.
- RED real con dos conexiones MySQL entrelazadas: cinco tests reprodujeron dos
  fallos de estado y tres errores de unicidad. RED adicional de orden SQL en Bulk.
- Se reutilizan las asignaciones bloqueadas para autorizar, y las escrituras
  leen examenes/intentos con locking reads. La numeracion toma el ultimo intento
  actual bajo lock; generacion y sincronizacion no usan colecciones antiguas.
  Bulk bloquea asignaciones ordenadas antes de cursos e intentos.
- GREEN focalizado: seis tests, 19 assertions. Es cobertura de visibilidad de
  commits concurrentes, no prueba de carga ni de todos los interleavings posibles.
- Caso adicional RED: editar a HABILITADO rechazaba una aprobacion ya confirmada
  por otra conexion. Se verifica sobre examenes bloqueados y se devuelve el padre
  actual, no un fresh del snapshot anterior. GREEN: siete tests, 21 assertions.
- Archivo nuevo: tests/Feature/MiningTransactionVisibilityTest.php. Tambien se
  ajusta PersonalCursoBulkService. No se agregan tablas, rutas ni dependencias.
- Ruling: estos tests no usan DatabaseTransactions para crear fixtures, porque
  la segunda conexion necesita verlos confirmados. Se exige proserge_app_test,
  se abre una transaccion explicita para el snapshot y se revierte al terminar;
  luego se limpian exclusivamente UUID de fixtures propios y se purgan conexiones.
  Coste: mayor cuidado de limpieza; no ejecutar esta clase contra otra base.
- Pendiente: carreras simultaneas completas/doble cierre, locks de configuracion
  del catalogo durante importacion y verificacion de sincronizacion de tarea 6.

## Inventario visual inicial (aun sin modificar)

Fuente: index.blade.php, bulk-preview.blade.php, catalog-organization.blade.php,
_convalidation-rules.blade.php y controlador web del modulo.

| Contexto actual | Acciones | Hallazgo para etapas 9-10 |
| --- | --- | --- |
| Cabecera | Personal; Acciones | Navegacion y configuracion compiten visualmente |
| Acciones | Agregar/Editar examen; Configurar por mina; Organizar; carga masiva; Master; precios | Agrupar catalogo/requisitos/importaciones/precios; distinguir reemplazo Master |
| Pestanas | Trabajador; dashboard; matriz; vencimientos; programados | Mantener vista activa y ordenar segun recorrido aprobado |
| Mina de trabajador | Asignar, abrir examenes, desasignar | Separar accion sensible de operacion/consulta |
| Tarjeta de examen | Programar; ver programados; registrar realizado; convalidar; NO_APLICA; descargar | Se ofrecen botones aunque el panel luego los rechace; revisar disponibilidad y una accion principal |
| Programado | Registrar resultado; archivo | No crear intento nuevo para cerrar el pendiente existente |
| Configuracion | Agregar requisito; quitar; convalidacion/equivalencias | Nombrar alcance mina vs catalogo y confirmar impacto |
| Carga masiva | Revisar archivo; categorias; revisar de nuevo; aplicar; configurar/agregar/editar en otra pestana | Conservar archivo, corregir desde contexto y una confirmacion final |
| Organizar catalogo | Revisar impacto; confirmar y guardar | Preservar identidad, historial y confirmacion, sin fusion automatica |
| Reglas convalidacion | Cancelar; guardar reglas | Diferenciar configurar compatibilidad de confirmar un resultado concreto |

No se ha validado todavia cada estado, foco, permiso ni tamano movil en navegador.

## Evidencia 2026-10-06

Verificacion ampliada: filtro PersonalMina|MiningExam|PersonalCurso|
RbacAccessAudit|RbacOperationalScope, 229 tests y 1269 assertions, sin fallos,
con APP_TIMEZONE=America/Lima. Sintaxis de siete PHP y git diff --check correctos.
Tras corregir los dos hallazgos, filtro ampliado tambien con
OperationalNotificationService: 233 tests, 1292 assertions, sin fallos. Sintaxis
del servicio de notificaciones tambien verificada. La segunda revision de solo
lectura confirma resueltos ambos hallazgos, sin regresiones concretas detectadas.
Pint --test detecta estilos pendientes en seis archivos con cambios heredados
y actuales; no se aplico reformateo general. No se declara lint completo verde.
No se ejecutaron build ni Browser porque este lote no modifica Blade, JS o CSS.
La suite global, concurrencia real con dos conexiones y reloj UTC/Lima siguen
pendientes; no confundir esta verificacion focalizada con entrega integral.

JUnit local privado: storage/app/private/security-audit/2026-10-06/.
No versionar reportes con datos sensibles ni salidas generadas.
No se regeneraron BPMN ni Graphify.

## Evidencia final del lote 2026-10-07

- Suite focalizada ampliada: 261 tests, 1385 assertions, sin fallos, 48.643 s.
  Filtro PersonalMina|MiningExam|MiningTransactionVisibility|PersonalCurso|
  RbacAccessAudit|RbacOperationalScope|OperationalNotificationService.
  APP_ENV=testing, DB_DATABASE=proserge_app_test, DB_URL vacio,
  APP_TIMEZONE=America/Lima. Los casos de medianoche fuerzan servidor UTC.
- 28 casos nuevos respecto al cierre anterior. Seis usan dos conexiones MySQL
  reales con transacciones entrelazadas y commits externos al snapshot; no se
  afirma cobertura de stress paralelo, doble cierre o toda carrera posible.
- Sintaxis de seis PHP y git diff --check correctos. Warning CRLF heredado en
  ManPower fuera del alcance; sin cambios en ese archivo por este lote.
- Pint pasa para MiningTransactionVisibilityTest. Pint focalizado completo
  sigue detectando estilos en cinco archivos con cambios heredados y actuales;
  no se aplico un reformateo general. No declarar lint completo aprobado.
- JUnit privado: mining-plan-2026-10-07-final.xml en la carpeta de evidencia
  existente. Reportes y fixtures de pruebas no se versionan ni despliegan.
- Sin Blade/JS/CSS modificados: no aplica build/view:cache/Browser a este lote.
  No se cambio interfaz ni se declara terminada la mejora visual aprobada.
- Pendientes de negocio existentes no se reinterpretaron. Proximo alcance:
  conflictos de vencimiento/resultado del Master y validacion de limites,
  antes de completar universo activo, sincronizacion, filtros, dashboard y UX.
- Recorrido manual: vence hoy conserva habilitacion durante el dia de Lima;
  programacion de manana no permite cerrar hoy; desasignar conserva intentos;
  una carga repetida no crea un segundo examen y respeta resultados recientes.
- Sin cambios de esquema, importaciones reales, migraciones, push o FTP.
  No se modificaron roles ni formulas de evaluacion; BPMN/Graphify intactos.
