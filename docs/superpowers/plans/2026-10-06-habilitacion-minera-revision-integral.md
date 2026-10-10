# Habilitacion minera: plan de correccion integral

> Ejecutar tarea por tarea con project-continuation y executing-plans despues
> de la aprobacion del usuario. Este documento no autoriza importaciones,
> correcciones de datos existentes, migraciones ni despliegue en produccion.

**Goal:** Habilitaciones confiables, trazables y operables con mas datos, sin
alterar contratos/Personal ni perder resultados anteriores.

**Architecture:** conservar controller web, servicios actuales, Eloquent/MySQL,
Blade, AJAX nativo, PermissionMatrix y scheduler. Unificar reglas dentro de
servicios existentes. Reducir carga inicial y consultar detalles bajo demanda.
No reescritura, API nueva, tabla paralela de cursos ni dashboard adicional.

**Tech Stack:** Laravel 12, PHP 8.2, MySQL, PhpSpreadsheet 5, Blade, Vite,
JS/CSS actuales, PHPUnit 11 y pruebas Browser existentes.

**Spec/evidencia:** `../specs/2026-10-06-habilitacion-minera-auditoria.md`.
Contexto canonico: `docs/CODEX_CONTEXT.md`, `docs/HABILITACION_CARGA_MASIVA.md`.
Complementa planes del 2026-10-05: no repetir lo ya implementado.

Avance, evidencia y pendientes parciales:
`2026-10-06-habilitacion-minera-ejecucion.md`.

## Restricciones globales

- No modificar estado laboral, contratos, cargo, documentos o supervisor.
- No introducir scope de mina de otros modulos en habilitacion.
- Curso nuevo exige trabajador y mina identificados; legado sin mina es
  historial pendiente, no resultado global para propagar.
- Otra mina requiere permiso, compatibilidad y convalidacion confirmada.
- No fusionar IDs por similitud ni borrar resultados, precios o archivos.
- Maximo de intentos es referencia; mantener intentos adicionales y 3/2.
- Decision confirmada: desaprobar una renovacion anticipada no anula una
  aprobacion anterior vigente; sigue habilitando hasta su vencimiento.
- Carga cursos/EMO: todas las observaciones bloquean todo el archivo.
- Master es otro flujo: reemplazo global activo hoy confirmado. No convertirlo
  silenciosamente en carga incremental ni confundirlo con cursos/EMO.
- Preservar snapshots historicos. Cambios de configuracion futura no autorizan
  recalificar retroactivamente aprobaciones.
- Permiso backend en cada accion; UI nunca sustituye validacion servidor.
- Mantener nombres de rutas, formularios y contratos existentes salvo necesidad
  justificada. Nuevos endpoints solo si no hay equivalente para carga diferida.
- Ningun commit/push/FTP sin autorizacion; mantener cambios previos ajenos.

## Orden visual obligatorio de acciones

Ampliacion pedida por el usuario tras aprobar el plan. Abarca todas las pantallas,
tablas y modales de habilitacion minera, no un rediseno general de otros modulos.
Aplicar estos criterios en cada tarea de UI; su aceptacion integral es tarea 10.

### Jerarquia y ubicacion

1. Navegacion: pestanas y volver; no confundir un cambio de vista con guardar.
2. Operacion: una accion principal por contexto visible/formulario, no varias
   compitiendo. En tablas grandes no repetir botones primarios en cada celda.
3. Consulta: historial, programaciones y detalle con tratamiento secundario.
4. Configuracion: catalogo, requisitos, equivalencias y precios agrupados en
   el menu/modal administrativo actual; fuera del recorrido diario principal.
5. Acciones sensibles: desasignar, retirar requisito, desactivar catalogo y
   reemplazar Master separadas de guardar/continuar y con impacto confirmado.

Mantener orden/posicion predecible: accion principal y cancelar/volver en el mismo
lugar de formularios; consulta y acciones sensibles en lugares diferenciados.
Los botones no deben moverse al aparecer spinner, contador o error. En movil la
barra se adapta sin recortar etiquetas ni ocultar la accion principal.

### Nombres y comportamiento

- Verbo + objeto para comandos: Programar examen, Registrar resultado,
  Confirmar convalidacion, Quitar requisito de esta mina. Evitar Guardar,
  Actualizar, Aplicar o Gestionar sin contexto suficiente.
- Un mismo comando conserva nombre, icono y comportamiento en tarjeta, matriz,
  lista y modal. Distinguir acciones de consulta, preparacion y escritura.
- Mostrar trabajador, mina y examen en el encabezado del detalle, no obligar
  a inferirlos de un boton generico o repetirlos en botones excesivamente largos.
- Solo ofrecer acciones compatibles con permiso y estado; si una accion no puede
  ejecutarse temporalmente, deshabilitarla y mostrar el motivo operativo puntual.
  Falta de permiso no se solventa con un boton que falla despues de abrirlo.
- Iconos existentes para comandos familiares; texto o icono+texto para operaciones
  de negocio que lo necesitan. No sustituir Convalidar por un simbolo ambiguo.
- No anadir parrafos/manuales explicando botones o apariencia como solucion de UX;
  usar etiquetas claras, contexto, estados y mensajes de dominio. Tooltip accesible
  para iconos poco familiares, sin depender exclusivamente de hover.
- Progreso en el boton de la accion enviada, bloqueo de doble envio, errores
  recuperables y valores conservados; mismo patron visual del proyecto.
- Confirmaciones nombran objeto, alcance y efecto real. No decir Eliminar examen
  si solo se retira de una mina o se conserva como historico.

### Matriz de acciones propuesta

| Contexto | Accion principal | Secundarias | Separadas/sensibles |
| --- | --- | --- | --- |
| Trabajador seleccionado, mina no asignada | Asignar a esta mina | Ver requisitos | Configuracion fuera de la tarjeta |
| Trabajador con mina asignada | Abrir examenes | Ver historial | Desasignar de esta mina |
| Examen pendiente sin programacion | Programar examen | Registrar resultado si ya fue realizado; convalidar si elegible | NO_APLICA en su accion diferenciada |
| Examen programado cuya fecha ya corresponde | Registrar resultado del programado | Ver programacion e historial | No crear otro intento para cerrar el mismo pendiente |
| Examen resuelto vigente | Ver resultado | Ver historial; renovar segun regla aprobada y elegibilidad | Sin botones de registro que inevitablemente fallan |
| Examen vencido o desaprobado | Programar nuevo intento | Registrar resultado ya realizado; convalidar si autorizado | Mostrar referencia de intentos sin bloquear adicionales |
| Convalidacion abierta | Confirmar convalidacion | Cancelar | Origen/destino/nota/restriccion visibles antes de confirmar |
| Carga masiva sin revisar | Revisar archivo | Volver | Configuracion dentro del acceso administrativo |
| Revision con observaciones | Revisar de nuevo tras correcciones | Descargar reporte si disponible; corregir desde fila | Aplicar cambios bloqueado con motivo |
| Revision valida con cambios | Confirmar carga | Revisar de nuevo; consultar filas | Una sola confirmacion final con cantidad y alcance |
| Configuracion por mina | Agregar requisito | Editar reglas/equivalencias | Quitar requisito de esta mina |
| Catalogo de examenes | Guardar examen en formulario abierto | Organizar nombres; ver precios/historial | Desactivar examen del catalogo |
| Importacion Master | Revisar Master y luego confirmar reemplazo | Ver impacto completo | Reemplazo global claramente distinto de carga de resultados |

Esta matriz ordena acciones existentes; no amplia elegibilidad ni autoriza
convalidacion/renovacion que el backend no permita. Cambios de reglas pendientes
siguen sujetos a las decisiones de negocio siguientes.

## Decisiones de negocio

1. CONFIRMADA: renovacion anticipada desaprobada conserva la aprobacion anterior
   hasta su vencimiento. Separar ultimo intento y aprobacion efectiva; no borrar
   la desaprobacion del historial ni extender la vigencia anterior.
2. Convalidar con destino NO_HABILITADO/OBSERVADO o con programacion pendiente:
   hoy se rechaza y exige EN_PROCESO. Definir recuperacion y destino del pendiente,
   sin retirar restricciones solo para hacer aparecer un boton.
3. Efecto de cambiar nota minima/vigencia sobre cursos ya generados: definir
   cambios solo futuros o nueva revision autorizada. No reescribir snapshots.
4. Master: confirmar si importar incluye configurar/asignar/registrar por
   diseno o debe exigir sus capacidades adicionales. Hoy solo exige importar.
5. Compatibilidad de DNI de siete digitos del Master: mantener solo si origen
   Excel y coincidencia unica lo justifican; divergencias requieren revision.

No bloquear las correcciones tecnicas independientes por estas decisiones.

## Orden y puertas de salida

| Etapa | Tareas | Salida |
| --- | --- | --- |
| Seguridad de datos | 0-4 | Sin propagacion sin mina, estados sin intento ni escritura historica accidental |
| Coherencia operativa | 5-6 | Estado/progreso/origen coherentes; tareas reanudables |
| Escala y analitica | 7-8 | Filtros completos y contadores explicados; carga inicial acotada |
| Uso diario | 9-10 | Correcciones desde preview, acciones claras y menos controles confusos |
| Entrega | 11 | Pruebas, revision, respaldo y despliegue controlado |

## Tarea 0. Establecer baseline reproducible

**Files:** tests Feature mineros existentes, `tests/Support/MiningCatalogFixtures.php`,
tests Browser mineros, esta auditoria; no cambiar servicios aun.

- [x] Confirmar git status y las decisiones vigentes; inventariar cambios locales.
- [x] Verificar conexion efectiva `proserge_app_test` antes de escribir fixtures.
- [ ] Congelar reloj en tests sensibles; probar UTC/Lima entre 19:00 y 00:00.
- [x] Reproducir los tres fallos H8 con datos sinteticos unicos, sin depender de
  catalogos preexistentes. Separar fallo de fixture de defecto real.
- [ ] Agregar caracterizacion para H1-H7 antes de modificar cada comportamiento.
- [ ] Medir queries, payload, memoria y tiempos con 1k/10k/50k trabajadores y
  historiales representativos en BD aislada. Guardar EXPLAIN sin DNI reales.
- [ ] Inventariar botones por pantalla/modal, nombre, permiso, estado habilitante,
  destino y efecto. Marcar duplicados, nombres ambiguos y acciones que fallan
  inevitablemente. Validar la matriz visual con el recorrido operativo real.

**Checks:** `php vendor/phpunit/phpunit/phpunit --filter 'PersonalMina|MiningExam'`.
Baseline observado: 150 casos; tres fallos persistentes con reloj Lima.
No ocultarlos ni relajar expectativas de identidad/seguridad para obtener verde.

## Tarea 1. Corregir identidad sin convalidacion automatica (H8)

**Files:** `MiningExamIdentityResolver.php`, `PersonalMinaExcelImportService.php`,
`PersonalMinaBulkPreviewService.php`; tests IdentityResolver/Integration y CatalogOrganization.

**Contract:** conservar `resolve(name, mineId, catalog)` y resultado
status/examen_id/principal_id/candidates/match_type. Si Master necesita resolver
inactivos confirmados para reactivar, agregar opcion explicita con default seguro;
no hacer que carga de cursos acepte cualquier inactivo.

- [x] Red: alias inactivo confirmado en Master; activo unico frente a inactivo
  no relacionado; EMO A y EMOA asociados a destinos distintos.
- [x] Usar claves del resolver en caches/indices del Master, sin compactar
  espacios de manera distinta en cada capa.
- [x] Resolver activos primero en carga operativa; preservar ambiguedad entre
  multiples activos/grupos y dos requisitos del mismo grupo en una mina.
- [x] Permitir reactivacion identificada en Master solo si el preview la muestra
  y el ID confirmado no cambia; no renombrar el principal con un alias.
- [x] Verificar grupos, alias scoped/globales y ausencia de esquemas auxiliares.

**Done:** tres regresiones corregidas y suites de identidad verdes; IDs,
historial, precios y permisos de convalidacion intactos.

## Tarea 2. Cerrar propagacion de legado sin mina (H1)

**Files:** `PersonalMinaHabilitacionService.php`, `PersonalCursoBulkService.php`,
`PersonalCursoImportPlanner.php`, tests CorrectedFlow y BulkPreview.

- [x] Red: curso con mina NULL + dos minas que requieren el curso; asignar,
  sincronizar o importar nunca debe crear intentos en ninguna automaticamente.
- [x] Rechazar aplicacion operativa de registro sin mina con mensaje de revision;
  excluirlo de sincronizaciones automaticas y mantenerlo visible como historial.
- [x] Eliminar la rama que propone aplicarlo a todas las minas desde preview.
- [x] Mantener reutilizacion en asignacion posterior de la misma mina indicada.
- [x] Mantener posibilidad de convalidar origen valido a otra mina con confirmacion.
- [ ] Emitir inventario solo lectura de registros NULL y reflejos ya existentes;
  no anularlos, backfillearlos ni modificar estados reales automaticamente.

**Data guard:** `mina_id` nullable sigue siendo necesario para historial antiguo.
No aplicar NOT NULL destructivo ni inventar una mina predeterminada.

## Tarea 3. Integridad de escrituras y elegibilidad (H2/H3)

**Files:** `PersonalMinaHabilitacionService.php`, `PersonalMinaExcelImportService.php`,
controller actual; tests Base/ExamenesMineros/CorrectedFlow.

- [x] Red: intentar guardar/programar/completar/NO_APLICA con asignacion, mina,
  requisito o examen inactivo; acceso directo POST no escribe.
- [x] Reutilizar elegibilidad comun dentro del servicio, devolviendo errores
  operativos diferenciados; verificarla tambien dentro de locks de guardado.
- [x] Transaccion para NO_APLICA, estado de asignacion e historial; para actualizar
  y desactivar asignacion, preservar rollback y actor.
- [x] Serializar asignacion/generacion con padre existente y orden de locks
  consistente. Reusar indices `uq_personal_mina`, `uq_personal_mina_examen`,
  `uq_examen_intento_numero`; no duplicarlos con migraciones.
- [ ] Convertir conflictos esperados de unicidad a ValidationException de dominio;
  otras excepciones se reportan y propagan, nunca se encubren como exito.
- [x] En Master eliminar catch general que actualiza snapshot tras fallar intento;
  bloquear examen antes de asignar numero y recalcular desde datos confirmados.
- [ ] Reimportacion: nota/vencimiento/observacion diferentes se muestran como
  conflicto. Resultado antiguo no reemplaza el vigente sin decision explicita.
- [ ] Validar nota negativa/desbordada, fechas invalidas/futuras y archivo usando
  limites/tipos ya aceptados por el sistema; no permitir SQL por datos extremos.
- [ ] Probar fallo al guardar intento, recalculo e historial: rollback completo
  y eliminacion solo del archivo nuevo fallido; historicos intactos.
- [ ] Probar dos solicitudes de asignar/generar y dos cierres de la programacion
  con conexiones MySQL separadas si la infraestructura permite paralelismo.
  Una prueba secuencial no demuestra una carrera.

**Done:** no resultado sin evento auditable, no escritura historica inadvertida,
duplicados esperados sin 500 y archivos privados conservados.

## Tarea 4. Vigencias, renovacion y trazabilidad (H4/H9)

**Files:** service actual, `CursoResultado.php`, modelos examen/intento existentes,
tests ExamenesMineros/CorrectedFlow y Browser minero.

- [ ] Uniformar dia operativo con display_timezone/Lima en validaciones,
  vencimientos, calendario y formularios; almacenar timestamps sin cambiar
  globalmente el timezone de otros modulos.
- [x] Red: limites de medianoche UTC/Lima, vence hoy/manana, fecha futura real.
- [x] Red: convalidado vencido -> nuevo curso rendido; resultado actual deja de
  atribuirse a la convalidacion anterior y conserva su evidencia historica.
- [x] Red: APTO CON RESTRICCION con uso de lentes convalidado a otra mina conserva
  su restriccion; una observacion vacia no la elimina silenciosamente.
- [x] Aplicar decision 1: ultimo intento desaprobado no reemplaza aprobacion aun
  vigente. Pruebas de nueva programacion, desaprobacion, aprobacion nueva y
  vencimiento de la anterior sin renovacion aprobada; mantener intentos 3/2.
- [x] Revisar soporte historico actual antes de limpiar metadatos: si no permite
  conservar renovaciones/convalidaciones anteriores, justificar ampliacion
  aditiva minima y su backfill inequivoco; no crear otro modelo de cursos.
- [ ] Verificar NO_ASISTIO/reprogramacion/anulado y conteo de ciclo frente a total
  historico; mostrar ambos cuando difieran, no reiniciar numeros persistidos.

**Done:** fechas coherentes, nueva realizacion no hereda origen falso, restricciones
visibles y ninguna renovacion destruye el resultado anterior.

## Tarea 5. Universo activo y calculo de estado (H5)

**Files:** service actual, `PersonalMinaDashboardService.php`, Blade index/partials,
modelos MinaRequisito/PersonalMinaExamen y tests CorrectedFlow/ExamenesMineros.

- [ ] Definir dentro del servicio examenes operativos para requisitos activos y
  evidencia historica separada, reutilizando relaciones existentes.
- [ ] Red: retirar requisito desaparece de progreso/acciones/alertas operativas,
  pero sigue consultable su historial y no borra intentos.
- [ ] Red: reactivar/recrear requisito del mismo examen no duplica curso ni deja
  NO_APLICA/convalidacion ligados solo al requisito retirado.
- [ ] Evaluar efecto de reglas nuevas segun decision 3; preview de impacto antes
  de cambios sobre pendientes, sin alterar snapshots de intentos anteriores.
- [ ] Preparar estado, avance, motivo y siguiente accion desde backend una vez,
  reutilizando servicios; retirar calculos duplicados Blade/JS de ese alcance.
- [ ] Separar calculo puro de persistencia: GET no hace save(); escritura/scheduler
  actualiza examen, asignacion e historial coherentemente en transaccion.
- [ ] Conservar EN_PROCESO por vencimiento/pendiente y NO_HABILITADO por
  desaprobacion que alcanza referencia; ambos deben mostrar siguiente accion.

**Done:** tarjeta, matriz, acciones y consultas coinciden para una misma
asignacion; historial retirado no aumenta faltantes ni porcentajes.

## Tarea 6. Sincronizacion observable y recuperable

**Files:** `MineRequirementSyncService.php`, comandos SyncRequirements/Temporal,
controller y estado de sincronizacion de la vista; `routes/console.php` solo
si ajustes justificados; tests CorrectedFlow.

- [ ] Verificar migraciones/esquema y guardas; no ejecutar DDL desde HTTP.
- [ ] Medir lote real y seleccionar limite por tiempo/memoria; orden de bloqueo
  compatible con registrar intento y editar requisitos.
- [ ] Probar reinicio, nueva configuracion durante sync, doble ejecucion y cursor
  UUID con inserciones concurrentes; ningun asignado queda omitido permanentemente.
- [ ] Reintentos con espera y diagnostico, utilizando estado existente; definir
  recuperacion si actor fue eliminado sin atribuirlo falsamente a otro usuario.
- [ ] Mostrar progreso/error/ultima actualizacion y accion de reintento autorizada
  en configuracion existente; refresco ligero bajo demanda, no polling global.
- [ ] Antes de poner en produccion, comprobar cron de Laravel y task de vigencias
  en copia realista. Alertar pendiente antiguo sin exponer excepciones SQL.

**Done:** no esperar toda la mina en un request; cambios reanudables, progreso
visible y aviso operativo si el scheduler no procesa.

## Tarea 7. Filtros completos y rendimiento medido (H6/H10)

**Files:** service y controller existentes, Blade index, JS relacionado;
tests CorrectedFlow y Browser. Migracion incremental solo si EXPLAIN lo justifica.

- [ ] Red: coincidencia de vencimiento despues de fila 300 y programado despues
  de 500; filtros mina/examen/persona/fecha/estado deben encontrarla.
- [ ] Mover filtros/agregaciones y paginacion a SQL antes del limite; calendario
  y opciones se calculan del universo correcto, no de la pagina visible.
- [ ] Preservar agrupacion operativa y definir IDs de agrupacion; nunca juntar
  examenes distintos solo por nombre ni tomar grouping como convalidacion.
- [ ] Consultar detalles/intentos/historial al abrir gestion; no cargar todo el
  catalogo y precios dos veces al ingresar/buscar si no se usa esa accion.
- [ ] Medir LIKE de nombres/cargos, prefix DNI e indices existentes. Escapar
  comodines del usuario; evaluar token/prefijo/fulltext solo si lo exige volumen
  y la busqueda mantiene la semantica aprobada.
- [ ] Mantener debounce/cancelacion existentes; respuesta antigua no reemplaza
  ultima busqueda, pestana y filtros permanecen al cambiar pagina y al no haber datos.
- [ ] Repetir benchmark; objetivos orientativos en entorno equivalente:
  busqueda <1 s p95, entrada <2 s p95 y queries acotadas por pagina, ajustados
  tras baseline. No prometer tiempos antes de medir hosting.

**Done:** cero truncamiento oculto, carga acotada y evidencia comparativa.

## Tarea 8. Dashboard con unidades y filtros coherentes (H7)

**Files:** `PersonalMinaDashboardService.php`, controller dashboard,
`_dashboard.blade.php`, JS drilldown; tests CorrectedFlow.

- [ ] Red: persona en tres minas; una sin examenes y otra con aprobaciones;
  requisito retirado; asignacion NO_HABILITADO/OBSERVADO con faltantes.
- [ ] Definir etiquetas de personas unicas, habilitaciones trabajador-mina,
  examenes requeridos e intentos programados. No sumar esas unidades como iguales.
- [ ] Mantener grafico circular HABILITADO/EN_PROCESO pedido por usuario; expresar
  su subconjunto. No ocultar NO_HABILITADO/OBSERVADO en alertas operativas sin
  explicar el alcance. Cualquier ampliacion de grafico requiere aprobacion.
- [ ] SIN_EXAMENES: distinguir persona sin ningun resultado de asignacion sin
  examenes generados. Conservar ambos solo si utiles; etiquetas inequivocas.
- [ ] Filtros globales compartidos afectan todos los datos relacionados; filtro
  local de estado en faltantes queda claramente local y su drilldown lo hereda.
- [ ] Agrupar por identidad confirmada/ID segun contexto; los snapshots siguen
  disponibles para auditoria y no son la clave exclusiva de conteo.
- [ ] KPIs accionables: resultado pendiente de programado, vencidos, por vencer,
  convalidacion candidata (no concedida), requisito faltante y sync pendiente,
  reutilizando grafico/tabla actuales, sin mas paneles decorativos.

**Done:** cada contador se explica y reproduce con consulta/drilldown; filtros,
total, barras, circular y detalle tienen alcances declarados y consistentes.

## Tarea 9. Corregir desde la carga masiva sin perder revision

**Files:** bulk-preview Blade, `public/js/bulk-review.js`,
`public/css/bulk-review.css`, controller, servicios preview/planner/bulk,
catalog organization actual y tests BulkPreview/CorrectedFlow/Browser.

- [ ] Conservar categorias y reporte; anadir descarga de observaciones solo si
  no existe equivalente. Revalidar permiso de exportar/importar segun catalogo.
- [ ] Reusar modales/acciones actuales para requisito/examen/alias; conservar
  token y archivo privado. Los enlaces actuales en otra pestana son fallback,
  no afirmar que ya cumplen correccion dentro de la revision.
- [ ] Correccion de nombre parecido requiere seleccionar identidad y confirmar;
  nunca editar automaticamente la nota/mina/DNI del Excel o de Personal.
- [ ] Al corregir, replanificar archivo entero, invalidar token anterior y mostrar
  diferencias de conteos. Mantener bloqueo total mientras haya una observacion.
- [ ] Mensajes por fila con hoja/numero/DNI/nombre mina/examen y siguiente accion;
  buscar Personal por DNI/nombre y mostrar UUID solo como identificador interno.
- [ ] Verificar permisos independientes configurar/crear/editar/importar/registrar;
  denegacion backend aun con form o token manipulado.
- [ ] Confirmacion doble y preview obsoleto no crean duplicados. Omitidos,
  creados/actualizados y errores se reportan sin confundir filas con personas.
- [ ] Medir confirmacion maxima de 3000 filas por hoja: no ampliar timeout como
  arreglo. Si excede capacidad HTTP, disenar ejecucion durable reutilizando
  mecanismos existentes y conservando politica atomica antes de implementarla.

**Done:** operador corrige catalogo/requisito sin perder el archivo; preview nunca
es una escritura silenciosa ni permite carga parcial.

## Tarea 10. Orden visual estricto y administracion comprensible

**Files:** `resources/views/personal/habilitacion-minera/index.blade.php`,
`bulk-preview.blade.php`, `catalog-organization.blade.php`, `_dashboard.blade.php`,
`_convalidation-rules.blade.php`; `resources/js/modules/mining-convalidation.js`,
`mining-catalog-organization.js`, `public/js/bulk-review.js` y CSS minero existente;
controller/service solo para disponibilidad/contexto de acciones; tests Feature
CorrectedFlow/CatalogPage y Browser mineros/bulk actuales; docs canonicos.

- [ ] Red: tests de acciones por contexto y permiso, no solo existencia de textos.
  Caracterizar programado vencido, pendiente, resuelto, sin origen convalidable,
  requisito retirado y archivo con observaciones.
- [ ] Agrupar menu administrativo en secciones de catalogo, requisitos por mina,
  importaciones y precios, manteniendo permisos y rutas actuales. Master no
  aparece como una variante indistinguible de cargar cursos/resultados.
- [ ] Revisar orden de pestanas segun recorrido: trabajador, matriz, programados,
  vencimientos y dashboard como consulta. Reordenar sin renombrar contratos/rutas
  y preservar vista activa, historial del navegador y filtros.
- [ ] Aplicar matriz visual a tarjeta y modal: programar sin confundir con aprobar,
  cerrar programado sin crear otro intento, convalidar con confirmacion explicita.
  Mismo orden/etiqueta para la misma accion en todos sus puntos de acceso.
- [ ] Definir una accion principal por formulario/contexto y secundarias sobrias;
  separar controles de seleccion/filtro de comandos. En tabla usar acciones
  compactas reconocibles, no una fila de botones con igual prominencia.
- [ ] Conservar encabezado trabajador/mina/examen durante gestion y confirmacion;
  cancelar/volver restaura el contexto sin perder busqueda o archivo de revision.
- [ ] Disenos para cargando, error, sin datos, sin permisos, duplicado y exito,
  con dimensiones estables y respuesta puntual. No mostrar exito antes del commit
  o antes de completar una sincronizacion diferida.
- [ ] Mostrar diferencias entre quitar de una mina, desactivar catalogo y organizar
  nombres. Confirmacion explica trabajadores/minas afectadas antes de guardar.
- [ ] Conservar baja logica de registros usados; alias/grupo no mueve historial.
  No ofrecer borrar examenes con referencias como limpieza automatica.
- [ ] Revisar borrado exclusivo de registros nunca usados solo si el usuario lo
  aprueba y con todas las referencias verificadas; no es prioridad de entrega.
- [ ] Quitar controles sin efecto real: revisar critico,
  desaprueba_finaliza_proceso, reprogramable y flags de reintentos antes de
  retirarlos de la UI; mantener compatibilidad de columnas/snapshots antiguos.
- [ ] Quitar modales/controles administrativos precargados del flujo principal,
  no la funcionalidad; preservar Acciones y permisos actuales.
- [ ] Sustituir errores tecnicos/acciones inutiles por motivo y siguiente paso,
  sin ocultar fallos. No depender solo de color.
- [ ] Playwright en escritorio y movil: orden de foco/tabulacion, etiquetas,
  botones no solapados, modales navegables por teclado, retorno de foco, errores
  junto al contexto y accion principal visible sin desplazamientos inesperados.
- [ ] Probar con un operador del area: identificar sin ayuda donde asignar,
  programar, registrar resultado, convalidar, retirar un requisito y confirmar
  una carga. Registrar confusiones y corregirlas antes de aceptar esta etapa.
- [ ] Sincronizar docs y retirar pendientes resueltos/propagacion global antigua;
  BPMN solo al final si usuario autoriza su actualizacion.

**Done:** cada pantalla tiene accion principal reconocible y orden consistente;
el operador distingue consulta, registro y configuracion sin asistencia tecnica.
No se pierde funcionalidad, auditoria ni control backend al simplificar botones.

## Tarea 11. Verificacion y despliegue

- [ ] Revisar diff acotado y invariantes; todos los cambios de este plan llevan
  regresion automatizada, excepto tareas puramente documentales.
- [ ] Pruebas mineras completas, matriz RBAC de acciones y regresiones cercanas
  Personal/Contratos/RQ/ManPower que consumen habilitacion.
- [ ] `php -l` PHP tocado; Pint focalizado; `git diff --check`.
- [ ] `php artisan view:cache` si Blade; `npm run build` si assets compilables.
  No editar assets con hash; no confundir public/js sin Vite con assets Vite.
- [ ] Browser existente: buscar/asignar/programar/resultado/NO_APLICA/convalidar,
  quitar/reactivar, filtros sin coincidencias, bulk corregir/revisar/confirmar;
  escritorio y movil, requests fallidos, doble clic y dos pestanas.
- [ ] Reportar fallos globales ajenos y confirmar cero regresiones introducidas,
  sin declarar suite completa verde si aun hay fallos.
- [ ] Inventariar migraciones faltantes y SQL idempotente necesario; probar
  upgrade sobre esquema antiguo/copia realista. No migrate:fresh.
- [ ] Respaldo MySQL + privado, aprobacion de operador, assets compilados y cron.
  Validar APP_DEBUG=false, lectura y permisos tras despliegue.
- [ ] Rollback de codigo versionado y compatibilidad de esquema aditivo; no
  revertir eliminando historial. Reparacion de datos existentes con preview,
  cantidades y autorizacion separada.
- [ ] Cerrar cada tarea informando hallazgo, archivos, backend/UI, reglas, pruebas,
  resultados, decisiones pendientes y recorrido manual de aceptacion.

## Checklist manual final

1. Curso para mina A se guarda antes de asignar; al asignar A se reutiliza.
2. Mina B no se habilita con ese curso hasta convalidacion permitida y confirmada.
3. Curso antiguo sin mina no se propaga; se presenta para revision.
4. Quitar requisito conserva archivos/intentos; no cuenta como pendiente operativo.
5. Tres intentos con referencia dos muestran 3/2 y permiten seguir registrando.
6. Vencido permite nueva realizacion; sin origen falso ni perdida de restricciones.
   Renovacion anticipada desaprobada conserva aprobacion previa solo hasta que venza.
7. Error en una fila bloquea archivo completo; doble confirmar no duplica.
8. Busqueda/filtros encuentran datos fuera de primeros 300/500 y conservan vista.
9. Contadores/drilldown coinciden y distinguen personas, minas y examenes.
10. Usuario sin permiso no escribe por URL directa; scope de otros modulos no
    altera quien puede gestionar esta habilitacion.
11. Un operador reconoce la accion principal y distingue programar, registrar y
    convalidar; las acciones administrativas/sensibles no compiten con ellas.
12. Cancelar, guardar con error y volver de un modal mantienen contexto; mismos
    nombres/orden en matriz, tarjetas, carga y formularios, escritorio y movil.

## Auto-revision del plan

Cubiertos: origen/mina, identidad, permisos, unicidad, rollback, archivos,
convalidaciones, restricciones, fechas, requisitos, sincronizacion, filtros,
rendimiento, indicadores, carga masiva, Master, UX, historico y despliegue.
Tareas no aprobadas de negocio estan marcadas, no incorporadas como fuente de
verdad. Decision 1 ya confirmada; no se estima tiempo total hasta baseline y
decisiones 2-5.
