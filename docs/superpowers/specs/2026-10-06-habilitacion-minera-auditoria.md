# Auditoria del flujo de habilitacion minera

Fecha: 2026-10-06. Estado: revision y propuesta; no implementacion.

## Alcance y evidencia

Revisado el arbol de trabajo actual, incluidos cambios locales no confirmados.
HEAD: `aaa7ff6`. Se consulto Graphify existente y se contrastaron conclusiones
con codigo, rutas, modelos, migraciones, Blade, JavaScript y pruebas.
Graphify es contexto estructural, no evidencia de comportamiento vigente.

No se modificaron PHP, JS, Blade, migraciones ni reglas funcionales. No se
importaron los Excel del usuario ni se ejecutaron procesos contra la base real.
Las pruebas se ejecutaron exclusivamente sobre `proserge_app_test`.

No se inspecciono el hosting, cron de produccion ni volumen de datos real. Las
afirmaciones de rendimiento son riesgos de consultas verificadas, no mediciones
de latencia en produccion. No se hizo recorrido visual en navegador en este paso.

## Flujo actual

1. Entrada web: `GET /personal/habilitacion-minera`, permiso
   `habilitacion_minera.ver`, `PersonalMinaHabilitacionController::index()`.
2. Seleccionar trabajador: consulta paginada a Personal; documento por prefijo,
   nombre/cargo/ocupacion por coincidencia contenida. AJAX conserva pestana.
3. Seleccionar/asignar mina operativa: `assignMine()` crea o reactiva
   `personal_mina`, genera examenes y recalcula. No activa contratos ni Personal.
4. Configurar catalogo y requisitos: `examenes_mineros`, `mina_requisitos`,
   precios y equivalencias. Agregar/quitar requisito marca trabajo pendiente en
   `mina_requisito_syncs`; scheduler procesa lotes.
5. Gestionar trabajador-mina: `personal_mina_examenes` conserva snapshots;
   `personal_mina_examen_intentos` conserva programaciones/resultados/archivos.
6. Registrar resultado o completar programado: bloqueo de examen, transaccion,
   numero de intento, precio aplicado y recalculo de habilitacion.
7. Convalidar: accion explicita existente; consulta candidatos y el POST valida
   trabajador, identidad/equivalencia, vigencia, nota y reglas del destino.
8. Carga de cursos/EMO: dos hojas, DNI y mina requeridos, preview privado de dos
   horas, categorias de observaciones y confirmacion atomica del archivo.
   `personal_curso_registros` puede preceder a la asignacion de la misma mina.
9. Master: flujo distinto; puede crear catalogo/asignaciones y reemplazar
   globalmente requisitos/catalogo activos tras confirmacion explicita.
10. Consulta: matriz, vencimientos, programados y dashboard. Scheduler diario
    recalcula vigencias; scheduler por minuto sincroniza requisitos.

No hay una API v1 paralela de este flujo en `routes/api.php`: su superficie
operativa actual es web. El scope de mina de otros modulos no debe incorporarse.

## Que conservar

- Separacion de habilitacion frente a estado laboral y contratos.
- Minas operativas mediante `Mina::activeOperational()`; oficinas y talleres
  se excluyen segun sus catalogos, no se deben borrar de otros modulos.
- Convalidacion confirmada y equivalencias dirigidas por requisito destino.
- Identidad/aliases confirmados sin fusionar IDs ni mover historiales.
- Intentos adicionales permitidos, conteo visible y snapshots de precios.
- Preview privado, token asociado a usuario, revalidacion y bloqueo de carga
  masiva completa si existen observaciones.
- Indices existentes: asignacion trabajador-mina, examen por asignacion,
  numero de intento por examen, vinculo intento-curso y curso-mina-fecha.
- Desactivacion de requisitos sin borrar resultados anteriores.
- AJAX selectivo, consultas de convalidacion bajo demanda y bloqueo visual de
  doble submit ya implementados; mejorar lo existente, no duplicarlo.
- La tabla de sincronizacion ausente ya tiene guardas: consulta segura y
  escritura rechazada con mensaje. No presentar el antiguo 500 como vigente.

## Hallazgos prioritarios

### H1. Historial sin mina puede propagarse automaticamente (alta)

`PersonalMinaHabilitacionService.php:925` filtra mina solo si el registro la
tiene. `syncCourseRecordsForAssignment()` incluye expresamente `mina_id NULL`
en la linea 1011. Por tanto, asignar a alguien con un curso antiguo sin origen
puede reflejarlo en varias minas sin una convalidacion confirmada.
`PersonalCursoBulkService::markPendingMineSync()` tambien contempla ese caso.

Contradice la aclaracion vigente en `docs/CODEX_CONTEXT.md`: conservar sin
reinterpretar el historial ambiguo. Corregir futuras propagaciones y revisar
impacto existente, sin eliminar aprobaciones en masa ni inventar origen.

### H2. Master puede actualizar estado sin guardar intento (alta)

`PersonalMinaExcelImportService::applyExamValues()` (1256) captura cualquier
Throwable al insertar un intento y aun asi ejecuta
`updateWorkerExamSnapshotFromImport()`. El usuario puede terminar con resultado
visible pero sin intento auditable. `storeImportedAttempt()` (1298) calcula
numero sin bloqueo explicito del examen, a diferencia del registro manual.

Ademas, `attemptAlreadyImported()` compara resultado y fechas, no nota ni
vencimiento, y el snapshot se actualiza incluso cuando se considera duplicado.
Una reimportacion con nota distinta o un resultado antiguo necesita tratarse
como conflicto, no como reemplazo silencioso del estado vigente.

### H3. Escrituras sobre examenes retirados y acciones no atomicas (alta)

`registerAttempt()` (1465), `completeScheduledAttempt()` (1579) y
`markExamNotApplicable()` (1700) no comprueban de forma equivalente que
asignacion, mina, requisito y catalogo sigan activos; convalidacion si lo hace.
Un POST directo con un ID historico puede seguir escribiendo sobre el examen.
`markExamNotApplicable()`, actualizar y desactivar asignacion guardan y luego
recalculan/registran historial sin una unidad transaccional comun.

El indice unico evita dos asignaciones iguales, pero `assignMine()` hace
comprobacion previa sin bloqueo ni conversion especifica del conflicto de BD;
la carrera puede terminar en error tecnico en lugar de mensaje operativo.

### H4. Renovacion conserva metadatos de convalidacion anterior (alta)

`applyAttemptToExam()` (2179) cambia resultado/fechas, pero no limpia
`es_convalidado`, origen ni contexto de la convalidacion anterior. Un examen
convalidado vencido y luego rendido directamente puede seguir atribuyendose al
origen antiguo al recalcular. La restriccion medica del origen tampoco se copia
en `convalidateExamLocked()`: la observacion destino solo toma el texto nuevo
del formulario. Preservar restricciones y trazabilidad por evento, sin confundir
el resultado vigente con el anterior.

Decision confirmada durante la auditoria: un intento nuevo desaprobado no quita
la aprobacion anterior aun vigente; esta sigue habilitando hasta su vencimiento.
Actualmente POR_VENCER admite nuevos intentos, mientras APROBADO/VIGENTE los
bloquean, y el nuevo resultado modifica el estado vigente. Separar ultimo intento
de aprobacion efectiva al implementar esta correccion.

### H5. Estados, progreso e historial usan universos diferentes (media)

`calculateAssignmentStatus()` (2259) compara requisitos activos; el resumen
de tarjetas (1443), progreso Blade (184) y varias consultas cuentan examenes
historicos retirados. `generateRequiredExams()` (877) omite un examen existente
por ID sin reengancharlo al requisito reactivado, que puede tener otro ID/reglas.
El calculo puede resolverlo por examen_id pero acciones como convalidar o
NO_APLICA conservan la referencia al requisito anterior.

Tambien `visualAssignmentStateFor()` (1397) llama a calculo que hace `save()`
de vigencias (2330). Una consulta GET puede modificar examen sin sincronizar
el estado persistido de la asignacion y su historial al mismo tiempo. Matriz y
tarjeta derivan estados; dashboard usa estados persistidos.

### H6. Filtros con resultados truncados antes de filtrar (media)

Vencimientos limita a 300 registros (281); programados a 500 (354).
Los filtros y paginacion se realizan despues en Blade (desde 264). Una
coincidencia fuera de ese recorte nunca aparece y los totales no representan
todo el universo. Se cargan ademas examenes/intentos de otras asignaciones
para construir datos de gestion que podrian consultarse al abrir el detalle.

Las agrupaciones usan nombre_snapshot, trabajador y fecha, no identidad del
examen: pueden juntar cursos distintos con el mismo texto o separar aliases.
Revisar unidad de agrupacion sin implicar convalidacion ni union automatica.

### H7. Indicadores pueden ocultar faltantes y rotular mal los conteos (media)

`PersonalMinaDashboardService` usa HABILITADO/EN_PROCESO para todo, no solo la
distribucion que el usuario pidio limitar a esos estados. Faltantes de relaciones
NO_HABILITADO/OBSERVADO quedan fuera. `COUNT(*)` cuenta trabajador-mina, pero
el grafico lo llama trabajadores; una persona en tres minas cuenta tres veces.

SIN_EXAMENES significa que existe una asignacion sin examenes generados, no
que la persona no tenga ningun examen: alguien con examenes en otra mina puede
contarse alli. Consultas de examenes tampoco separan requisitos retirados.
El filtro local de estado del grafico no es un filtro global (actualmente
`dashboardFilters.estado_examen` llega vacio); mantener esa distincion explicita.

### H8. Identidad del catalogo tiene tres regresiones reproducibles (alta)

`MiningExamIdentityResolver::resolve()` (45) considera activos e inactivos
antes de determinar ambiguedad y exige activo incluso para el uso del Master
que ofrece reactivar un alias confirmado. El Master ademas mantiene claves
compactas sin espacios en `examIndexKey()` (1729), que no equivalen a la
normalizacion del resolver. Fallan pruebas de alias inactivo, activo unico
junto a historial inactivo y aliases `EMO A` frente a `EMOA`.

No resolver esto con parecido aproximado ni eligiendo el primer candidato.

### H9. Fecha operativa mezcla UTC y Lima (media, reproducida)

La mayoria de comparaciones usa `Carbon::today()` en zona de aplicacion;
`validateRealizationDate()` (2492) usa display_timezone/Lima. Entre 19:00 y
medianoche de Lima, el dia UTC siguiente puede rechazarse como futuro.
Pruebas que crean fecha con today() lo reproducen. No eliminar la prohibicion
de fechas futuras: uniformar el dia operativo y cubrir sus limites.

### H10. Riesgos operativos y mantenimiento (mejoras)

- La entrada completa carga catalogo activo, catalogo completo con precios e
  historial de precios aunque el usuario solo vaya a buscar un trabajador
  (`index()`, 223). Nombres usan LIKE '%texto%'; requiere medicion/EXPLAIN.
- Master reemplaza globalmente catalogo y sincroniza todas las asignaciones
  afectadas en una transaccion HTTP de hasta 900 segundos (188). Es un alcance
  confirmado en contexto, pero exige aviso de impacto y pruebas con volumen.
- `MineRequirementSyncService` reintenta ERROR sin backoff y depende de que el
  usuario original siga existiendo. Estado/progreso no se refresca en AJAX;
  el cron real no se verifico. No introducir otro sistema de colas sin necesidad.
- Carga masiva ya tiene botones de configuracion/agregar/editar, pero abren
  otra pestana (`bulk-preview.blade.php:12`), no correccion dentro del preview.
- `bestWorkerForDocument()` del Master (1640) escoge primero si no encuentra
  exacto, y `cleanDocument()` completa siete digitos; carga de cursos rechaza
  cortos/ambiguos. Diferencia deliberada antigua o deuda: documentar y exigir
  identidad inequivoca, no cambiar DNI maestro ni resolver UUID como DNI.
- Documentacion conserva pendientes resueltos y reglas globales antiguas.
  Limpiar esas contradicciones despues de aprobar reglas definitivas.

## Verificacion

Comando: PHPUnit directo, filtro `PersonalMina|MiningExam`, BD aislada.

| Configuracion | Casos | Aserciones | Pasan | Errores | Fallos |
| --- | ---: | ---: | ---: | ---: | ---: |
| Entorno actual | 150 | 863 | 129 | 18 | 3 |
| APP_TIMEZONE y APP_DISPLAY_TIMEZONE America/Lima | 150 | 947 | 147 | 0 | 3 |

El segundo ensayo cambia solo variables del proceso, no .env ni codigo.
Los 18 errores del primero fueron rechazo de fecha futura. Los tres fallos
persistentes son H8. H1-H7 incluyen hallazgos de lectura aun sin nuevas pruebas
de reproduccion; no afirmar que toda la suite valida esos escenarios.

Reportes privados: `storage/app/private/security-audit/2026-10-06/mining-review.xml`
y `mining-review-lima.xml`. No incluyen archivos del usuario.

## Recomendacion

Correccion incremental sobre servicios/pantallas actuales. Primero H1-H4/H8 y
fecha operativa; despues coherencia del universo activo, filtros completos y
rendimiento; finalmente UX, indicadores y limpieza. No reescribir el modulo ni
borrar duplicados historicos. Plan asociado:
`../plans/2026-10-06-habilitacion-minera-revision-integral.md`.

## Ampliacion solicitada: orden visual de acciones

El usuario aprueba el plan y pide hacer explicita la reorganizacion de todos
los botones de habilitacion minera: hoy cuesta distinguir para que sirve cada
accion y cual corresponde al trabajo actual. No es solo un cambio de colores.

Evidencia actual: el menu Acciones mezcla agregar/editar catalogo, requisitos por
mina, organizar nombres, carga de cursos, Master y precios. El detalle del examen
muestra programar, ver programados y registrar realizado con peso similar; el
registro de resultado de un programado aparece dentro de otro panel. La carga
masiva distribuye configuracion, revision y confirmacion en bloques separados.

La ampliacion conserva pantallas, componentes y estilos actuales, pero establece
una jerarquia transversal del modulo: navegacion; accion principal del contexto;
consultas secundarias; configuracion; acciones sensibles. Los verbos deben
distinguir programar un examen de registrar su resultado, quitar un requisito de
una mina de desactivar el catalogo, y cargar resultados de reemplazar el Master.

No se implemento interfaz en esta revision. El plan incorpora inventario de
botones, matriz contextual, orden/posiciones, permisos, estados, confirmaciones
y aceptacion visual en escritorio y movil. Las nuevas reglas visuales se aplican
durante cada tarea que toque una pantalla, no solo al final del proyecto.
