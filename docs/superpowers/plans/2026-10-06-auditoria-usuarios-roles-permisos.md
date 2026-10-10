# Plan de auditoria de usuarios, roles y permisos

> **Para ejecucion:** usar project-continuation y architecture-review. Trabajar tarea por tarea; para correcciones aprobadas usar test-driven-development y verification-before-completion. Este documento no autoriza cambiar permisos ni codigo funcional.

**Objetivo:** comprobar que cada usuario puede realizar las acciones legitimamente autorizadas, no puede realizar las restantes y solo recibe los datos que le corresponden, en web y API.

**Arquitectura:** auditar el RBAC, autenticacion, policies y servicios existentes. Contrastar permiso configurado, permiso efectivo, resultado backend e interfaz. No crear un sistema paralelo de permisos ni inferir reglas nuevas por nombres de cargos.

**Stack:** Laravel 12, PHP 8.2+, MySQL, Blade/JS nativo, sesion web propia y tokens Bearer propios; PHPUnit y pruebas Browser existentes.

**Fuente de requisitos:** solicitud del usuario del 2026-10-06, AGENTS.md, docs/CODEX_CONTEXT.md, docs/ARCHITECTURE_MAP.md, docs/evaluaciones.md y reglas canonicas de cada modulo. Graphify sirve para navegar; el codigo actual y las decisiones operativas confirmadas prevalecen.

## Avance de ejecucion 2026-10-06

Informe: `docs/security/authorization-audit.md`.

- [x] Inventario tecnico de las 434 rutas, 31 modulos/262 acciones del catalogo y cuentas/roles locales mediante READ ONLY.
- [x] Decisiones confirmadas: GERENTE respeta configuracion; solo roles activos conceden permisos.
- [x] Reproduccion y correccion del primer bloque central/autenticacion/privilegios de Seguridad y Evaluaciones.
- [x] Regresion relevante: 127 pruebas pasan (774 assertions); baseline viejo registrado con 10 fallos previos.
- [x] Baseline WebPermissionMiddlewareTest reparado: 37 pruebas pasan, sin relajar controles de permiso.
- [x] Policies/listados operativos ya comprueban roles activos; GERENTE no recibe marcacion/cierre por nombre.
- [x] Verificacion focalizada del segundo bloque: 304 pruebas / 1845 assertions pasan.
- [x] Suite global ejecutada: 762 casos, 94 fallidos inventariados en docs/security/test-failure-inventory.md; no se declara verde.
- [x] Coherencia de administrar en listados de Asistencia/Faltas/ManPower corregida mediante cuatro regresiones RED/GREEN.
- [x] Fixtures de RQ reparadas; descubierta y corregida la doble codificacion documental. Bloque focalizado: 100 casos pasan.
- [x] Regresion global del tercer bloque: 768 casos, 709 pasan y 59 fallan; 35 fallos resueltos y 0 nuevos frente al inventario anterior.
- [ ] Matriz operacional completa por perfil y expectativas de rutas publicas/delegadas.
- [ ] Administracion de roles privilegiados: pendiente respuesta sobre asignacion de ADMIN/SUPERADMIN.
- [ ] Revision funcional de todos los modulos y helpers de privilegios restantes.
- [ ] Recorrido manual por perfil y cierre final.

Ruling: se adelantan correcciones centrales demostradas por tests porque afectan
todos los modulos; no se modifican configuraciones reales ni se resuelven reglas
ambiguas sin consultar. Se conserva el checkout existente para auditar sus
cambios pendientes en lugar de una copia que omita esas implementaciones.

## Limites y seguridad

- La ejecucion fue solicitada por el usuario. No cambiar cuentas reales ni cerrar la auditoria sin verificar toda su cobertura.
- Preservar cambios pendientes de Evaluaciones y Habilitacion minera.
- Inspeccion de cuentas reales solamente de lectura, en el ambiente identificado y autorizado. No enumerar contrasenas, hashes, tokens ni documentos personales en reportes.
- Pruebas automaticas solo contra `proserge_app_test`, con fixtures y DatabaseTransactions cuando corresponda. Verificar conexion antes de cada suite.
- Pruebas manuales sobre una copia aislada con cuentas de ensayo. No usar las cuentas reales para cambiar roles, revocar accesos o probar borrados.
- No ejecutar seeders, migraciones, importaciones ni `ensureBaseRoles` sobre produccion para preparar la auditoria.
- El alcance de acceso por mina NO es habilitacion minera. No imponer `mina.scope` a ese dominio de forma general.
- Un permiso no sustituye elegibilidad de negocio, propiedad, estado del registro o relaciones operativas exigidas por el modulo.
- No regenerar Graphify ni BPMN. No instalar dependencias para este plan.
- Diferenciar falta de permiso legitima, mala configuracion, defecto de codigo y regla de negocio por confirmar.

## Contexto ya verificado para preparar el plan

- `Usuario` usa rol principal `rol_id`, `rolesAdicionales` mediante `usuario_roles`, `personal_id` y `scopesMina` mediante `usuario_mina_scope`.
- `PermissionCatalog` declara modulos, acciones y roles base; `PermissionMatrix` normaliza permisos antiguos, deriva permisos, combina roles y contiene privilegios globales.
- Web usa `WebAuthenticate` y `EnsureWebPermission`; API usa `AuthenticateToken`, policies/servicios y `EnsureMinaScope` cuando corresponde.
- `effectivePermissions` incorpora roles adicionales cuando la relacion esta cargada: hay que comprobar todos sus llamadores, no solo el middleware que si los carga.
- `RoleManagementService::list/active/find` llama a `ensureBaseRoles`, que puede crear o actualizar roles. Una consulta a esas pantallas no debe considerarse inocua en datos reales.
- `MinaScopeAccess` y `EnsureMinaScope` declaran excepciones distintas por nombre de rol. Auditar contexto y reglas antes de decidir si corresponde corregirlo.
- Hay pruebas de permisos, roles adicionales y proteccion administrativa en `tests/Feature/WebPermissionMiddlewareTest.php`; reutilizarlas antes de crear archivos nuevos.
- Graphify consultado con `graphify query "usuarios roles permisos autenticacion PermissionMatrix Seguridad" --budget 1200`; no se regenero el mapa. Sus conclusiones se contrastaron con fuentes actuales.

Estos puntos son focos de revision, no un dictamen de que todos los accesos fallen.

## Tarea 1: Inventario y matriz esperada

**Leer:** routes/web.php, routes/api.php, bootstrap/app.php, app/Support/Rbac/PermissionCatalog.php, database/setup, migrations, seeders, modelos Usuario/Rol/AuthToken/UsuarioMinaScope y docs/MAPA_ARCHIVOS.md.

- [ ] Registrar ambiente, commit, cambios locales, esquema y volumen sin modificar datos.
- [ ] Inventariar todas las rutas de negocio web/API: metodo, nombre, accion, middleware, controlador, policy/servicio, alcance y respuesta esperada.
- [ ] Documentar excepciones publicas legitimas: login, health, fichas por enlaces y otros endpoints publicos reales. No marcar toda ruta publica como vulnerabilidad.
- [ ] Inventariar roles base y personalizados; usuarios activos/inactivos, roles principales/adicionales, cuentas sin Personal y scopes vacios/multiples.
- [ ] Construir matriz `perfil/usuario de ensayo x modulo x accion x alcance`: permitido, prohibido o por confirmar; anotar fuente de cada expectativa.
- [ ] Confirmar con el usuario las expectativas ambiguas. No dar por correcto un acceso solamente porque el codigo lo permite hoy.

**Entrega:** inventario de cobertura y matriz esperada. Datos personales identificables, si fueran necesarios, en `storage/app/private/security-audit/2026-10-06/`, sin versionar. Resumen redactado en `docs/security/authorization-audit.md` durante la ejecucion.

## Tarea 2: Motor central de permisos

**Leer/probar:** PermissionCatalog, PermissionMatrix, EnsureWebPermission, UsesAuthenticatedUser y WebPermissionMiddlewareTest.

- [ ] Comprobar permisos directos frente a fallback/derivados y formatos historicos, incluido `*`, permisos vacios, acciones desconocidas y checks con `|`.
- [ ] Comprobar union de rol principal y roles adicionales: asignar, retirar, duplicar y cambiar un rol; misma decision con modelo cargado, sesion y API.
- [ ] Verificar privilegios ADMIN/SUPERADMIN y permisos personalizados de GERENTE. No asumir que los tres tienen reglas identicas.
- [ ] Comparar etiquetas y checkboxes de configuracion con la accion real que autorizan.
- [ ] Revisar equivalencias Personal/submodulos/Habilitacion minera: detectar accesos heredados inesperados y denegaciones de acciones legitimamente habilitadas.
- [ ] Separar estado de rol/cuenta de sus permisos; definir como afectan roles inactivos y asignaciones obsoletas.

**Entrega:** tabla de permiso guardado vs efectivo, origen de cada concesion y diferencias reproducibles. No modificar la matriz todavia.

## Tarea 3: Autenticacion y vigencia del acceso

**Leer/probar:** AuthController, LoginPageController, WebAuthenticate, AuthenticateToken, AuthToken y flujo de logout.

- [ ] Probar login correcto/incorrecto y cuentas activas/inactivas; cuentas sin rol o con Personal inexistente cuando el esquema lo admita.
- [ ] Probar sesiones sin usuario, usuario eliminado, datos antiguos y sesiones abiertas despues de desactivar cuenta o retirar permisos/roles/scope.
- [ ] Probar tokens ausentes, invalidos, expirados y revocados; logout y reutilizacion del token anterior.
- [ ] Comparar respuesta HTML, AJAX y API: no permitir acceso ni dejar pantallas cargando; no filtrar mensajes tecnicos o credenciales.
- [ ] Revisar proteccion CSRF, regeneracion de sesion y limitacion de intentos de login segun la implementacion existente.

**Entrega:** matriz de acceso antes/despues del cambio de cuenta, rol y sesion; evidencia de revocacion o acceso residual.

## Tarea 4: Administracion de usuarios y roles

**Leer/probar:** controllers de Seguridad, RoleManagementService, AdminProtectionService y sus vistas/rutas.

- [ ] Probar crear/editar/desactivar usuarios, asignar roles adicionales, vincular Personal y configurar scopes con y sin cada permiso.
- [ ] Probar peticiones directas que intenten asignarse un rol superior o modificar otra cuenta sin autorizacion.
- [ ] Revisar proteccion del ultimo administrador activo y privilegios exclusivos de SUPERADMIN, tambien con ADMIN como rol adicional.
- [ ] Revisar concurrencia al desactivar/quitar administradores y actualizaciones coordinadas de cuenta/roles/scopes.
- [ ] Comprobar que editar/listar un rol no restablezca inesperadamente permisos personalizados; ensayar `ensureBaseRoles` solo en la copia aislada.
- [ ] Revisar coherencia entre catalogo, permisos almacenados, seeders y esquema antiguo. No ejecutar DatabaseSeeder como reparacion automatica.

**Entrega:** casos de administracion permitidos/prohibidos, riesgo de escalamiento y condiciones de bloqueo administrativo.

## Tarea 5: Revision de TODOS los modulos

Recorrer todos los modulos y acciones presentes en el inventario, incluidos endpoints auxiliares, busquedas, modales y descargas. No limitarse a lo visible en el menu.

**Orden por impacto:**

1. Seguridad y accesos transversales.
2. Personal, fichas, documentos, contratos, renovaciones, datos sensibles y Habilitacion minera.
3. Evaluaciones, importacion historica, regularizacion y dashboards.
4. RQ Mina -> RQ Proserge -> Man Power -> Asistencia -> Faltas.
5. Logistica, EPP, transporte, herramientas y catalogos.
6. Bienestar, Mi Asistencia, Perfil, Notificaciones, Dashboard, System y cualquier otro modulo/endpoint detectado.

**Para cada accion:**

- [ ] Probar usuario autorizado, solo lectura, sin permiso, otro propietario y fuera de scope cuando el dominio lo requiera.
- [ ] Probar URL directa y llamadas GET/POST/PUT/PATCH/DELETE aplicables, no solo botones.
- [ ] Cambiar IDs de trabajador, mina, parada, grupo, archivo o lote para comprobar autorizacion sobre el recurso real y sus relaciones.
- [ ] Probar busquedas, contadores, graficos, exportaciones/importaciones y archivos privados; no deben revelar datos adicionales aunque la escritura este protegida.
- [ ] Verificar que denegaciones no escriben registros de negocio; distinguir actualizacion legitima de uso de token/logs de escrituras sensibles.
- [ ] En Evaluaciones conservar reglas actuales: diaria por asistencia o directa segun origen; supervisores por permisos definidos; residentes por capacidad explicita; historicos/regularizacion por permisos administrativos.
- [ ] En Habilitacion minera separar acceso al modulo de habilitacion de trabajadores y convalidacion. No reintroducir scope de mina como requisito ajeno al flujo.

**Entrega:** ficha por modulo con permitido/prohibido observado, evidencia, ruta y archivo responsable; cobertura completa y huecos pendientes.

## Tarea 6: Interfaz y uso real por perfil

**Leer/probar:** sidebar/layout y componentes existentes localizados con rg, vistas de Seguridad y pantallas operativas de cada modulo.

- [ ] Comparar menu, tabs, botones, modales y formularios con la autorizacion backend efectiva.
- [ ] Probar cada perfil de la matriz, incluido rol adicional; comprobar que acciones permitidas son encontrables y funcionales.
- [ ] Verificar mensajes operativos para falta de acceso, sesion vencida o restriccion de negocio; no confundir estos casos.
- [ ] Ensayar cambios de permisos con una pagina ya abierta: el backend debe rechazar la accion retirada, aunque quede un boton antiguo visible.
- [ ] Revisar desktop/mobile y navegacion despues de una denegacion, sin crear otra interfaz ni sustituir el diseno actual.

**Entrega:** checklist manual por perfil y discrepancias UI/backend. Una UI correcta no valida por si sola la seguridad.

## Tarea 7: Pruebas y correcciones aprobadas

**Reutilizar:** WebPermissionMiddlewareTest, PersonalFichaSensitiveDataPermissionTest, EvaluacionesWebTest, EvaluacionesApiTest, SupervisorHistoricalScopeTest, DashboardApiTest y suites existentes de los demas modulos.

- [ ] Ejecutar baseline del RBAC en base de pruebas y separar fallos previos de los nuevos.
- [ ] Agregar primero una prueba reproducible por cada fallo confirmado que vaya a corregirse; verificar su fallo antes de tocar codigo.
- [ ] Priorizar P0/P1: escalamiento, cuentas desactivadas con acceso, acciones sin autorizacion y exposicion de datos privados. Despues corregir denegaciones indebidas, inconsistencias y UX.
- [ ] Presentar evidencia y correccion minima antes de modificar reglas de concesion ambiguas. No ampliar permisos para hacer que un test pase.
- [ ] Mantener convenciones de PermissionMatrix, middleware, policies y servicios; no introducir otro RBAC ni refactors generales.
- [ ] Despues de cada correccion ejecutar prueba focalizada, suite relevante y regresiones de roles adicionales, permisos historicos y administradores.
- [ ] Verificar php -l, git diff --check, Blade y Browser cuando aplique; build solo si cambian assets compilables.

**Inicio de baseline propuesto, tras verificar conexion aislada:**

```powershell
$env:APP_ENV='testing'
$env:DB_DATABASE='proserge_app_test'
$env:DB_URL=''
php artisan test --filter WebPermissionMiddlewareTest
php artisan test --filter 'PersonalFichaSensitiveDataPermissionTest|EvaluacionesWebTest|EvaluacionesApiTest|SupervisorHistoricalScopeTest|DashboardApiTest'
```

No son todos los tests finales: las suites de cada modulo se incorporan conforme al inventario. No ejecutar suites concurrentes contra la misma base.

## Tarea 8: Cierre y despliegue controlado

- [ ] Entregar matriz esperada vs observada, resumen por usuario/perfil y estado de cada modulo.
- [ ] Reportar hallazgos con gravedad, reproduccion, impacto, archivo/ruta, prueba y estado: confirmado, corregido, verificado o por confirmar.
- [ ] Diferenciar corregir codigo, corregir configuracion de una cuenta y resolver una decision de negocio. No aplicar reasignaciones masivas sin aprobacion.
- [ ] Mantener trazabilidad de permisos anteriores/nuevos cuando se apruebe una correccion de configuracion.
- [ ] Ensayar despliegue con backup y cuentas de ensayo; no tocar produccion ni FTP automaticamente.
- [ ] Dar lista de comprobaciones manuales y riesgos residuales; no declarar que todo funciona si quedan modulos/acciones sin probar.

## Criterios de aceptacion

- Cada ruta de negocio y accion del catalogo tiene una expectativa y un resultado comprobado, o un pendiente explicito.
- Cada perfil real y cada combinacion relevante de roles adicionales tiene casos positivos y negativos; no basta probar ADMIN.
- Sin permiso, propietario valido o alcance exigido no se leen ni modifican recursos restringidos.
- Las acciones permitidas funcionan y su interfaz no contradice el backend.
- Desactivar o revocar acceso no deja una via alternativa web/API sin evaluar.
- No se pierden administradores ni se amplian privilegios por accidente.
- Ninguna prueba modifica informacion real. No se publican datos personales ni secretos en el reporte.

## Decisiones que requieren confirmar durante la matriz

- Que cargos/perfiles deben poder ejecutar cada accion: el rol almacenado hoy no demuestra legitimidad operacional.
- Alcance de Gerencia y de roles adicionales en cada dominio, incluidas diferencias entre web y API.
- Como deben afectar roles inactivos a un usuario que conserva otros roles activos.
- Si las equivalencias historicas de permisos siguen siendo necesarias o deben retirarse gradualmente.

No estan resueltas por este plan ni autorizan modificar reglas en silencio.
