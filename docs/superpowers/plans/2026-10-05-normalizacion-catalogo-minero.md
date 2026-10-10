# Organizacion del catalogo minero Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox syntax for tracking.

**Goal:** Dar una vista segura para organizar variantes de examenes y reconocerlas en Excel, portable al hosting por FTP.

**Architecture:** Asociar IDs existentes a una identidad principal sin mover intentos ni resultados. Un resolver compartido interpreta nombres y mina destino; un servicio transaccional administra asociaciones, aliases y auditoria. Blade presenta diagnostico, comparacion, preview y confirmacion.

**Tech Stack:** PHP 8.2+, Laravel 12, MySQL, Blade, JS nativo, Vite existente, PHPUnit 11.

**Spec:** `docs/superpowers/specs/2026-10-05-normalizacion-catalogo-minero-design.md` (aprobada).

## Global Constraints

- Solo afecta al catalogo y su uso en habilitacion minera; no modifica Personal, contratos, documentos ni estado laboral.
- No borrar registros antiguos, sus intentos ni precios.
- Buscar parecido solo sugiere; nunca guarda ni convalida por si solo.
- Las reglas diferentes por mina permanecen visibles.
- No exponer un endpoint web de migraciones ni ejecutar DDL al abrir la pantalla.
- No depender de rutas Windows, Python, Graphify, Node en produccion ni ejecutables de backup.
- FTP no actualiza MySQL. Instalar esquema por migracion o SQL revisado; no copiar la base local sobre produccion.
- Preservar cambios previos y ajenos. No aplicar migraciones operativas ni correcciones masivas sin respaldo y autorizacion especifica.

## Review Focus

1. Dos nombres del mismo grupo requeridos por una mina: rechazar resolucion ambigua, no elegir primero.
2. Alias global colisiona con otro grupo: rechazar; alias scoped solo afecta a su mina.
3. Cambia el catalogo despues del preview: confirmar rechaza y no escribe parcialmente.
4. Master intenta renombrar un principal usando un alias: conservar nombre administrado y reconocer alias.
5. Esquema ausente en hosting: aviso operativo sin 500; escrituras deshabilitadas y diagnostico antiguo sigue disponible.

## Mapa de archivos

- `database/migrations/2026_10_05_000600_create_mining_exam_catalog_identity_tables.php`: esquema incremental.
- `database/setup/mining_exam_catalog_identity.sql`: SQL equivalente para phpMyAdmin.
- `app/Models/ExamenMineroAsociacion.php`, `ExamenMineroAlias.php`, `ExamenMineroCatalogoCambio.php`: persistencia nueva.
- `app/Modules/Personal/Services/MiningExamIdentityResolver.php`: identidad, nombres y requisitos por mina.
- `app/Modules/Personal/Services/MiningExamCatalogOrganizationService.php`: preview, confirmacion y auditoria.
- `app/Modules/Personal/Services/MiningExamCatalogAuditService.php`: diagnostico ampliado.
- `app/Modules/Personal/Controllers/MiningExamCatalogPageController.php`: pantalla, preview y confirm.
- `resources/views/personal/habilitacion-minera/catalog-organization.blade.php`: vista dedicada.
- `resources/js/modules/mining-catalog-organization.js`: filtros y comparacion, sin reglas de negocio.
- `resources/css/modules/mining-catalog-organization.css`: estilos responsive importados al build.
- `routes/web.php`, `resources/views/personal/habilitacion-minera/index.blade.php`, `resources/js/app.js`, `resources/css/app.css`: integracion minima.
- Servicios de preview masivo, Master y habilitacion: consumo del resolver, sin refactor ajeno.
- Tests nuevos y existentes, docs de reglas/importacion/despliegue y build generado.

## Task 1: Identidad persistente y resolver

**Files:** migracion, SQL y modelos nuevos; `MiningExamIdentityResolver.php`; `tests/Feature/MiningExamIdentityResolverTest.php`.

**Interfaces:** `schemaReady(): bool`, `canonicalId(string $examId): string`, `groupIds(string $examId): array`, `resolve(string $name, ?string $mineId = null): array`. Resultado: `status` (matched/missing/ambiguous), `examen_id`, `principal_id`, `candidates`, `match_type` (exact/alias/group).

- [ ] Escribir tests con DatabaseTransactions: nombres distintos sin asociacion no equivalen; singular/plural e izage/izaje asociados reconocen requisito correcto por mina; dos requisitos activos del grupo en una mina devuelven ambiguous. Alias scoped en otra mina no coincide. Alias global de otro grupo no gana silenciosamente. Esquema ausente devuelve schemaReady false y mantiene fallback exacto.
- [ ] Ejecutar `php artisan test --filter=MiningExamIdentityResolverTest`; verificar fallo por interfaz inexistente, no por conexion equivocada.
- [ ] Crear tres tablas: `examen_minero_asociaciones` con variante UUID unico, principal UUID, actor, motivo y fechas; `examen_minero_aliases` con UUID, examen destino, mina opcional, texto varchar191, clave sha256 normalizada y scope_key no nulo para indice unico clave/scope; `examen_minero_catalogo_cambios` con actor, accion, motivo, snapshots JSON y fechas. FKs compatibles con UUID existentes. No backfill inferido. SQL usa los mismos tipos/indices y comprobaciones previas documentadas; no INSERT de datos operativos.
- [ ] Implementar resolver con normalizacion Str::ascii/upper/squish y puntuacion consistente, sin quitar calificadores teorico/practico/virtual. Resolver exactos/aliases confirmados a grupos, despues requisito activo por mina. Sin grupo, conservar reglas existentes de nombre exacto. No usar distancia de texto para seleccionar ID.
- [ ] Aplicar migracion solo a `proserge_app_test`, comprobar aislamiento antes de ejecutar. Ejecutar tests del resolver y PHP lint, comparar SQL con migracion, verificar segunda ejecucion idempotente.
- [ ] Revisar diff del task y crear checkpoint solo con archivos/hunks de este cambio; nunca git add global sobre el workspace sucio.

## Task 2: Organizacion transaccional y diagnostico

**Files:** `MiningExamCatalogOrganizationService.php`, `MiningExamCatalogAuditService.php`, `tests/Feature/MiningExamCatalogOrganizationTest.php`.

**Interfaces:** `report(array $filters = []): array`; `preview(array $payload, Usuario $actor): array`; `confirm(array $preview, Usuario $actor): array`. Payload: accion asociar/renombrar/pendiente, principal_id, variante_ids, nombre opcional, aliases [{nombre, mina_id}], motivo. Preview incluye impacto, diferencias, snapshot y fingerprint.

- [ ] Escribir tests: asociar conserva IDs, cantidades, snapshots y requisitos; renombrar registra nombre anterior como alias; motivo obligatorio; alias duplicado otro grupo rechazado; ciclos y principal ya variante rechazados; principal con dependientes no puede convertirse en variante; configuraciones distintas no se sobrescriben; pendiente audita sin asociar; actor no puede confirmar preview ajeno; fingerprint cambiado revierte todo.
- [ ] Ejecutar `php artisan test --filter=MiningExamCatalogOrganizationTest` y comprobar rojo.
- [ ] Implementar cambios en transaccion, locks ordenados de examenes y asociaciones implicadas. Validar destino existente y no variante; no crear cadenas. Bloquear colisiones con aliases/nombres que pertenezcan a otros grupos en el mismo alcance. Auditoria before/after en la misma transaccion. Fingerprint incorpora asociaciones, aliases, reglas, requisitos y conteos relevantes; revalidar en confirm.
- [ ] Ampliar diagnostico con nombres de minas y diferencias completas: nota requerida/minima, vigencia, intentos, tipo, convalidacion, restricciones por mina y precios. Mantener contrato del endpoint antiguo; separar coincidencia textual de asociacion confirmada.
- [ ] Ejecutar tests focalizados y test existente `diagnostico_catalogo_sugiere_nombres_parecidos_sin_fusionar_ids`, lint y diff check; checkpoint revisado.

## Task 3: Vista operativa protegida

**Files:** controlador/vista/JS/CSS nuevos e integraciones del mapa; `tests/Feature/MiningExamCatalogPageTest.php`; `tests/Browser/mining-catalog-organization.cjs` y fixture `render-mining-catalog-organization.php`.

**Interfaces:** GET `/personal/habilitacion-minera/organizar-examenes` (`personal.habilitacion-minera.catalogo.index`); POST `/organizar-examenes/revisar` (`catalogo.preview`); POST `/organizar-examenes/confirmar` (`catalogo.confirm`). Todas bajo web.auth y web.permission:habilitacion_minera,configurar. Controlador usa servicio task2.

- [ ] Escribir Feature tests: viewer recibe 403 en las tres rutas; configurador ve Acciones > Organizar examenes; GET no escribe; preview no escribe asociaciones; confirm exige accepted y token propio vigente; falta esquema muestra aviso y no permite POST. Token en sesion tiene TTL 15 minutos y se consume tras confirm exitosa.
- [ ] Ejecutar `php artisan test --filter=MiningExamCatalogPageTest`; comprobar rojo.
- [ ] Implementar controlador delgado con validacion UUID/distinct, nombre max191, motivo 5..1000 caracteres, hasta100 variantes/aliases. Sesion guarda preview y actor; confirm no acepta fingerprint enviado como unica prueba. Manejar errores con lenguaje operativo, conservar entradas y rechazar token vencido.
- [ ] Implementar tabla responsive con busqueda, filtros activos/historicos/candidatos y contadores. Comparador selecciona principal, variantes y aliases por mina. Preview muestra diferencias, referencias preservadas y accion; permite marcar pendiente. No boton borrar, fusion fisica ni aprobar resultados. Alias y grupo visibles al volver a abrir; aviso de backup previo antes de confirmar.
- [ ] Extraer comportamiento al modulo JS y estilos al CSS nuevo importado. Enlaces Laravel, texto escapado, nada de localhost o rutas absolutas. Agregar entrada solo para configurador al menu existente.
- [ ] Ejecutar Feature tests, `php artisan view:cache`, `npm run build`, PHP lint y browser desktop1440/mobile390. Browser comprueba filtros, preview sin POST confirm, confirm explicita, errores, falta esquema, ausencia de overflow/errores JS y nombres con caracteres HTML escapados. Checkpoint revisado.

## Task 4: Excel y convalidacion reconocen grupos

**Files:** `PersonalMinaBulkPreviewService.php`, `PersonalMinaExcelImportService.php`, `PersonalMinaHabilitacionService.php`; tests nuevos `tests/Feature/MiningExamIdentityIntegrationTest.php` y regresiones PersonalMina existentes.

**Interfaces:** consumir `resolve`, `groupIds` y `canonicalId` de task1, no implementar otra normalizacion. Ambos importadores reciben mina destino en matching y caches incluyen mina+nombre, no solo nombre.

- [ ] Tests: Excel singular en mina configurada plural y viceversa reconoce ID destino tras asociacion; reimportacion omite duplicado; nombre parecido no confirmado queda observado con candidatos; Master con alias no renombra principal ni crea duplicado; Master preview y confirm eligen mismo ID y rechazan cambio de grupo; dos requisitos del grupo en misma mina bloquean preview; historia sin mina sigue observada.
- [ ] Tests convalidacion: variante asociada aprobada y vigente del mismo trabajador aparece como origen, requiere confirmacion; otro trabajador/vencido bloqueado; destino exige nota rechaza origen null o bajo minimo; destino sin nota conserva null; flag convalidacion desactivado sigue bloqueando. Normalizar catalogo no cambia estados ni genera convalidaciones.
- [ ] Ejecutar `php artisan test --filter=MiningExamIdentityIntegrationTest`; comprobar rojo.
- [ ] Integrar resolver en preview y confirm de los dos formatos, manteniendo comportamiento legacy para examenes sin grupos. Candidatos no confirmados no provocan creacion automatica; examenes genuinamente nuevos conservan preview/confirmacion del Master. Reconocidos por alias conservan nombre principal administrado. Ajustar deteccion de requisitos reemplazados del Master para comparar grupos/IDs resueltos por mina y evitar desactivaciones por alias. Incorporar asociaciones/aliases al fingerprint del Master.
- [ ] En servicio de convalidacion unir groupIds a equivalentes autorizados existentes, tanto consulta como validacion bajo lock. Mantener reglas de mina, trabajador, nota, vigencia y snapshot. No propagar resultados por agrupar nombres.
- [ ] Ejecutar `php artisan test --filter='MiningExam|PersonalMina|PersonalCurso'`, incluyendo importadores unitarios. Registrar cada fallo y no corregir flujos ajenos. Lint y checkpoint revisado.

## Task 5: Despliegue FTP y verificacion final

**Files:** `docs/BUSINESS_RULES_RRHH.md`, `docs/HABILITACION_CARGA_MASIVA.md`, `docs/CODEX_CONTEXT.md`, `docs/OPERACION_DESPLIEGUE.md`, `docs/FTP_ORGANIZAR_EXAMENES.md`, build generado.

**Interfaces:** misma vista trabaja con base propia de cada ambiente; esquema por migracion o SQL task1, nunca endpoint DDL.

- [ ] Escribir instrucciones exactas: backup MySQL y storage privado, instalar solo nueva migracion (o SQL verificado en phpMyAdmin), subir lista de PHP/Blade/JS/CSS fuente y public/build completo, mantener .env y storage operativo. No subir public/hot, pruebas, backups, scripts locales ni base local. Limpiar caches con terminal cPanel cuando exista; si no existe, documentar invalidacion segura de caches generados, no borrar directorios completos.
- [ ] Actualizar reglas de identidad/alias y alcance. Explicar que SQL/migracion instala estructura pero las asociaciones se revisan en cada ambiente; ninguna reparacion automatica al subir FTP. No desplegar ni modificar produccion desde esta tarea.
- [ ] Verificar phpunit.xml apunta a proserge_app_test antes de suite. Ejecutar tests focalizados completos, PHP lint tocado, view:cache, npm build, browser, Pint sobre archivos nuevos y diff check. Intentar suite completa sin limite de Composer; guardar resumen y fallos ajenos sin exponer datos. Si supera tiempo razonable, indicar pendiente y motivo sin afirmar verde.
- [ ] Revisar diff solo archivos propios, verificar manifest y assets existen, permisos/rutas y esquema ausente. No staging de cambios previos/ajenos; checkpoint final de hunks propios.
- [ ] Entregar acceso a vista, pasos FTP/SQL, pruebas y limitaciones. No afirmar reparada la base operativa: la vista entrega herramienta; aplicar asociaciones requiere revisar y confirmar.

## Handoff

Orden: tareas1..5. Recomiendo ejecucion por el agente principal en este chat: resolver, organizacion e importadores comparten interfaces y es mas sencillo mantener la coherencia. Alternativa: tareas por subagentes con revisiones independientes. La seleccion y aprobacion de este plan preceden a cambios de implementacion.
