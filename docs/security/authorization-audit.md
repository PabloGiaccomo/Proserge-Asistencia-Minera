# Auditoria de usuarios, roles y permisos

Fecha: 2026-10-06 (America/Lima).
Plan: `docs/superpowers/plans/2026-10-06-auditoria-usuarios-roles-permisos.md`.
Estado: bloques central y de scope operativo ejecutados; auditoria integral NO cerrada.

## Correccion de checks de roles (2026-10-09)

Correccion posterior en Evaluaciones: `EvaluacionesPageService::build` ya no
agrega Dashboard por tener cualquier tipo de evaluacion visible. Exige el
permiso efectivo `evaluaciones.dashboards`. El controlador rechaza la entrada
`tipo=dashboard` sin ese permiso con 403, tambien para respuestas parciales.
Esto corrige un consumidor que ignoraba la matriz, no las asignaciones de
roles. Las pruebas ahora verifican la pantalla real y su acceso directo.

Decision confirmada: ADMIN/SUPERADMIN activos conservan acceso total; cualquier
otro rol, incluido GERENTE, permite conceder y retirar cada check por separado.

- El formulario con `permisos_present=1` reemplaza la matriz completa. Los
  checks no enviados se guardan como false, tambien al quitar todos. Una
  peticion antigua sin esa marca ni permisos conserva el comportamiento de
  preservar la matriz existente.
- Un false explicito no se reescribe por tener Ver, Administrar u otra accion.
  Dashboard deja de ser una consecuencia automatica de habilitar operaciones.
  El JavaScript no marca Ver ni deshabilita/desmarca otras acciones.
- Las compatibilidades de payloads historicos incompletos se limitan a claves
  ausentes: no invalidan un false explicito. Los fallbacks de rutas tampoco
  pueden revivir una accion desmarcada. Las alternativas declaradas en rutas
  (`editar|actualizar`, por ejemplo) siguen siendo alternativas autorizadas.
- Los permisos efectivos son la union de roles ACTIVO. Revocar en uno no quita
  un permiso concedido por otro. WebAuthenticate vuelve a cargar los roles en
  cada peticion: los cambios no requieren cerrar sesion ni invalidar tokens.
- ADMIN/SUPERADMIN siempre normalizan a acceso completo; los roles INACTIVO
  se excluyen antes de normalizar. No cambia su proteccion contra desactivacion
  o retirada de permisos administrativos.
- Una preferencia de notificaciones habilitada ya no concede Ver/Actualizar.
  Una preferencia deshabilitada sigue suspendiendo notificaciones de un rol
  normal; no impide el acceso administrativo de ADMIN/SUPERADMIN activos. El
  encabezado exige Ver y las acciones de lectura/archivo exigen Actualizar.
- Si la validacion del formulario falla despues de quitar todos los checks,
  el reintento mantiene lo enviado, sin restaurar los permisos almacenados.

Archivos de implementacion: PermissionMatrix, RolPageController,
EnsureWebPermission, AppServiceProvider, formulario de roles y encabezado.
Regresiones: `tests/Feature/RolePermissionCheckboxTest.php`; expectativas
historicas actualizadas en WebPermissionMiddlewareTest, RbacOperationalScopeTest
y UsuarioNotificationTest conforme a la decision de checks independientes.
Las fixtures generales de Habilitacion minera ahora declaran sus acciones del
modulo en vez de heredarlas de Personal. Los actores de pruebas de permisos
mantienen grants limitados. El control de programados dedicado acepta solo
`ver_programados`, sin exigir el `ver` general ni ampliar otras capacidades;
su controlador vuelve a comprobar ese permiso y fuerza la vista scheduled.
Las pestanas de Logistica reciben su contexto con su permiso especifico, sin
conceder `epps.ver` implicitamente. Entregas recibe tambien su workspace; los
servicios conservan el actor autenticado y los filtros de alcance por mina.
Una regresion recorre todas las pestanas con grants independientes.

Verificacion focalizada: 92 casos / 2970 assertions, sin fallos ni errores.
Prueba de navegador `tests/Browser/role-permissions.cjs`: escritorio (1440) y
movil (390), checks independientes, seleccion/quitar todo, envio del formulario
y ausencia de desbordamiento horizontal. PHP lint, compilacion Blade y
`git diff --check` pasan. Pint detecta estilo pendiente en archivos que
ya contenian cambios de la auditoria; no se reformatearon cambios ajenos.
No cambian assets de Vite ni dependencias; no se ejecutan migraciones ni se
modifican usuarios o permisos reales durante la verificacion.
Suite global final: 1351 casos / 10913 assertions, 40 fallos y 1 error;
mismos 41 nombres fallidos del baseline previo, sin casos nuevos. Las 11
regresiones de checks pasan (2449 assertions). Detalle de archivos, limites
y todos los casos fallidos en
`docs/superpowers/reports/2026-10-09-role-permission-checkboxes.md`.

## Ambiente y proteccion

- Checkout actual, HEAD `aaa7ff6`, incluyendo cambios pendientes de Evaluaciones y Habilitacion minera; ninguno fue descartado.
- Inventario sobre la copia local `proserge_app_import_20260831_151029`, host 127.0.0.1, con transaccion MySQL READ ONLY y rollback.
- Pruebas exclusivamente en `proserge_app_test`, con DatabaseTransactions y guardia de nombre de base.
- No se cambiaron usuarios, asignaciones, roles o scopes reales. No se ejecutaron migraciones, seeders ni despliegue FTP.
- Inventario sin consultar valores de password/token_hash; reportes identificables en disco privado, sin versionar.
- Se audito el checkout existente porque contiene implementaciones sin commit que una nueva copia omitiria.

## Inventario

| Elemento | Cantidad |
| --- | ---: |
| Rutas registradas | 434 |
| Web / API | 331 / 103 |
| Modulos del catalogo / acciones por modulo | 31 / 262 |
| Roles almacenados | 14 |
| Roles base presentes / personalizados | 7 / 7 |
| Usuarios | 15 |
| Usuarios con roles adicionales | 8 |
| Usuarios sin Personal / sin scope de mina | 1 / 13 |

Todos los roles y cuentas observados estan ACTIVO. No tener Personal o scope
no demuestra error: depende del dominio y perfil. GET|HEAD cuenta como una
ruta registrada; el inventario incluye rutas del framework.

| Modulo de codigo | Web | API |
| --- | ---: | ---: |
| Auth | 3 | 2 |
| System | 0 | 2 |
| Catalogos | 26 | 8 |
| Personal | 115 | 5 |
| Seguridad | 23 | 6 |
| Evaluaciones | 21 | 13 |
| RQMina | 24 | 5 |
| RQProserge | 12 | 10 |
| ManPower | 18 | 15 |
| Asistencia | 15 | 9 |
| Faltas | 5 | 5 |
| Transporte | 7 | 9 |
| Dashboard | 0 | 9 |
| Notificaciones | 7 | 5 |
| Logistica | 3 | 0 |
| Epps | 16 | 0 |
| ParadaHerramientas | 11 | 0 |
| MiAsistencia | 9 | 0 |
| Bienestar | 8 | 0 |
| Perfil | 1 | 0 |
| Framework/publicas | 7 | 0 |

## Cobertura: inventariado no significa probado

- 314 rutas tienen middleware de permiso explicito; falta verificar todas las acciones sobre recursos y estados reales.
- 96 rutas autenticadas delegan autorizacion a controller/policy/service; no se clasifican automaticamente como vulnerables.
- 24 rutas sin sesion/token incluyen health, login/logout, assets, fichas publicas, catalogos y archivos firmados.
- GET/PUT `storage/{path}` validan firma en Laravel aunque no aparezca como middleware. Prueba con archivos sinteticos: lectura/escritura sin firma rechazada; no se accedio a documentos reales.
- Queda por confirmar la legitimidad de cinco endpoints de lectura de catalogos API sin autenticacion; no se agrego auth indiscriminadamente.
- La matriz de derechos operativos esperados por perfil sigue pendiente fuera de las dos decisiones resueltas abajo.

## Decisiones confirmadas

1. GERENTE debe respetar sus permisos configurados, no recibir acceso total por nombre.
2. Solo roles activos aportan permisos; desactivar uno mantiene los permisos de otros roles activos.

## Hallazgos corregidos

### RBAC-01: GERENTE ignoraba permisos desmarcados (P1)

`PermissionMatrix::normalizeForRoles` concedia fullAccessMatrix incluso con
matriz vacia. Reproducido antes de corregir. Ahora GERENTE respeta configuracion;
ADMIN/SUPERADMIN activos conservan privilegios globales. No se desmarcaron datos
almacenados ni se eliminaron reglas de scope de otros dominios.

### RBAC-02: rol inactivo seguia concediendo acceso (P1)

La normalizacion no comprobaba estado y varias consultas omitian la columna.
Reproducido con rol adicional INACTIVO y tras desactivarlo durante una sesion.
Se filtran roles activos y se rehidratan proyecciones persistidas incompletas.
Las representaciones historicas no persistidas sin estado mantienen compatibilidad;
no reemplazan al usuario real cargado por autenticacion.

### RBAC-03: roles adicionales dependian del eager loading (P2)

`effectivePermissions` omitia la relacion si no estaba cargada. El mismo usuario
podia recibir decisiones distintas. Ahora los modelos persistidos cargan las
relaciones faltantes. Web usa el Usuario autenticado para las comprobaciones de
dominio, evitando reconstruir privilegios desde un snapshot sintetico.

### AUTH-01: cuenta desactivada conservaba acceso (P1)

Login web comprobaba estado; login API y sesiones/tokens existentes no lo hacian.
Reproducido antes de corregir. Ahora login API rechaza sin emitir token, web
invalida sesion y middleware API rechaza acceso de la cuenta INACTIVO. Tokens
expirados/revocados siguen rechazandose. No se borraron tokens reales; la politica
de reactivacion/revocacion permanente queda para la revision completa.

### RBAC-04: privilegios por nombre ignoraban rol inactivo (P1)

Filtrar la matriz no bastaba: SUPERADMIN inactivo seguia reconocido por nombre.
Corregido en AdminProtectionService, UsuarioPageController, MinaScopeAccess,
EnsureMinaScope y EvaluacionesPolicy mediante `activeRoleNames`. Una prueba
comprueba que no permite borrar una cuenta ni saltar scope pese a conservar
otro rol operativo. No se uniformaron las excepciones de scope ni se agregaron
restricciones a Habilitacion minera.

La segunda correccion aplica `activeRoleNames` a policies y servicios de
Asistencia, Faltas, ManPower, RQMina, RQProserge, Dashboard, Transporte y
ParadaHerramientas. Conserva las listas de roles privilegiados y las capacidades
administrar de cada dominio; RQProserge conserva la precedencia del scope
explicitamente configurado. Habilitacion minera no recibe restricciones nuevas.
Se reproducen casos negativos sin scope y positivos con mina asignada,
ademas de privilegios adicionales activos e inactivos. No es una verificacion
de todas las acciones de esos modulos.

### RBAC-05: GERENTE podia marcar/cerrar asistencias por nombre (P1)

`AsistenciaPolicy::canViewAllAttendances` usaba el privilegio de scope para
conceder tambien acceso operativo. Con GERENTE activo y matriz vacia permitia
entrar a marcacion/cierre. Reproducido antes de corregir: ahora exige las
capacidades existentes `asistencias.administrar` o
`mi_asistencia.ver_todas_asistencias`. ADMIN/SUPERADMIN activos las obtienen por
el mecanismo global. GERENTE con solo `asistencias.ver` puede consultar, pero
no obtiene registrar/cerrar automaticamente. La responsabilidad de grupo y
los permisos explicitos existentes mantienen su comportamiento.

### TEST-01: baseline de permisos no era repetible

`WebPermissionMiddlewareTest` insertaba roles ADMIN/SUPERADMIN sin considerar
UNIQUE nombre; la mina de ensayo omitia unidad_minera y Bienestar buscaba un
bloqueo de julio en el mes actual. Se reutilizan los roles base dentro de
DatabaseTransactions, se aisla el unico administrador de ensayo y se fija la
fecha del escenario de Bienestar. Expectativas de texto y middleware de
Evaluaciones actualizadas contra el codigo actual, sin relajar denegaciones ni
cambiar rutas/permisos para acomodarlas. Las 37 pruebas pasan.

## Configuracion frente a permiso efectivo

Se observaron 310 celdas distintas antes y 61 despues: rol x modulo x accion,
no usuarios ni defectos individuales. Las restantes deben explicarse por
privilegios ADMIN/SUPERADMIN y `administrar`; no se corrigen automaticamente.

## Pruebas ejecutadas

- Nueva suite `RbacAccessAuditTest`: cubre decisiones confirmadas, roles adicionales cargados/no cargados, estado de cuenta, revocacion, privilegios especiales y storage sin firma.
- Regresion final: **127 pruebas pasan / 774 assertions**, incluyendo la nueva suite y suites de Evaluaciones web/API/historicos, evaluacion directa, datos sensibles de ficha y Dashboard API.
- Baseline completo `WebPermissionMiddlewareTest`: **27 pasan / 10 fallan** antes de estos cambios; el mismo conjunto falla despues. No se ocultaron fallos ni cambiaron permisos para acomodar tests.
- Seis fallos son fixtures que insertan ADMIN/SUPERADMIN existentes contra UNIQUE nombre; uno crea mina sin unidad_minera; otro espera middleware generico antiguo de Evaluaciones; dos son expectativas UI de scope/Bienestar por contrastar.
- PHP lint y diff check ejecutados. No se modifico Blade/CSS/JS ni se recompilaron assets.
- Segunda suite `RbacOperationalScopeTest`: 22 casos, policies de seis dominios, nueve listados/contextos y permisos operativos de Gerencia. RED antes de modificar; GREEN despues.
- Regresion focalizada final: **304 pruebas / 1845 assertions, 0 errores y 0 fallos**, ejecutada con `php vendor/phpunit/phpunit/phpunit --no-progress --no-output --filter ... --log-junit .../focused-final.xml`.
- Suite completa ejecutada: **762 casos / 4054 assertions; 668 sin fallo, 54 errores y 40 fallos**. Todos los casos terminaron; se detuvo solo el formateador Artisan/Collision que tardaba excesivamente comparando HTML. Resultado completo en full-suite.xml.
- Los **94 casos fallidos**, por suite y nombre, estan en `docs/security/test-failure-inventory.md`. No se atribuyen todos a defectos funcionales ni todos a pruebas obsoletas; hay fixtures UNIQUE, fechas futuras, permisos y expectativas de UI por reproducir.
- Lint PHP de los 17 archivos de codigo/pruebas de este bloque pasa; Pint del nuevo test y git diff --check pasan. No se recompilaron vistas/assets porque no se editaron.
- No se realizo recorrido manual autenticado de todos los perfiles. La auditoria completa sigue abierta.

## Artefactos privados

Ubicacion: `storage/app/private/security-audit/2026-10-06/`.

- `summary.json`, `routes.json`/`routes.csv`: inventario y referencias a fuentes.
- `catalog-actions.csv`: expectativas operativas por confirmar.
- `role-effective-permissions.csv`: comparacion configurado/efectivo.
- `accounts-private.json`: alias Uxx, identidad, roles y permisos; no publicar ni versionar.
- `after/`: segunda captura sin modificar registros reales.
- Logs RED/GREEN, baseline y `core-regression-final.log`.
- `operational-scope-red.log`, `operational-scope-green.log`, `operational-regression.log`, `web-permission-repaired.log`, `full-suite.xml`, `focused-final.xml`.

Generador: `.codex-tmp/authorization-inventory.php`, restringido a las dos bases
locales identificadas. No se creo una pantalla, ruta o comando Artisan de auditoria.

## Siguiente bloque

1. Confirmar quien puede asignar ADMIN/SUPERADMIN; pregunta enviada al usuario, sin modificar ese limite en silencio.
2. Completar administracion de cuentas/roles; baseline WebPermissionMiddlewareTest reparado, quedan otras suites por clasificar.
3. Revisar autorizacion delegada, recursos ajenos, filtros, importaciones y descargas de Personal/Habilitacion minera.
4. Revisar audiencias de notificaciones y helpers de rol no cubiertos, con pruebas focalizadas; no confundir seleccion de destinatarios con autorizacion.
5. Recorrido manual en copia aislada, cobertura completa y matriz operativa confirmada antes de cerrar.

## Comprobaciones manuales

En cuentas de ensayo; nunca desactivar el unico administrador real:

1. Desmarcar una accion de GERENTE: rechazo por URL directa y otras acciones conservadas.
2. Dar un rol adicional de lectura y desactivarlo: pierde ese acceso sin reingresar, conserva el otro rol activo.
3. Desactivar una cuenta de ensayo con sesion abierta: siguiente solicitud pide autenticar.
4. Verificar ADMIN/SUPERADMIN activos y botones de usuarios acordes al backend.

## Archivos funcionales modificados

- app/Support/Rbac/PermissionMatrix.php
- app/Support/Rbac/MinaScopeAccess.php
- app/Http/Middleware/WebAuthenticate.php
- app/Http/Middleware/EnsureWebPermission.php
- app/Http/Middleware/AuthenticateToken.php
- app/Http/Middleware/EnsureMinaScope.php
- app/Modules/Auth/Controllers/AuthController.php
- app/Modules/Auth/Controllers/LoginPageController.php
- app/Shared/Concerns/UsesAuthenticatedUser.php
- app/Modules/Seguridad/Services/AdminProtectionService.php
- app/Modules/Seguridad/Controllers/UsuarioPageController.php
- app/Modules/Evaluaciones/Policies/EvaluacionesPolicy.php

Ademas: nueva suite de auditoria, informe y avance del plan. No se tocaron rutas
ni migraciones para estas correcciones.

## Archivos del segundo bloque

- app/Modules/Asistencia/Policies/AsistenciaPolicy.php
- app/Modules/Asistencia/Services/AsistenciaService.php
- app/Modules/Asistencia/Services/AsistenciaDashboardService.php
- app/Modules/Faltas/Policies/FaltaPolicy.php
- app/Modules/Faltas/Services/FaltasService.php
- app/Modules/ManPower/Policies/ManPowerPolicy.php
- app/Modules/ManPower/Services/GrupoTrabajoService.php
- app/Modules/ManPower/Services/ManPowerParadasService.php
- app/Modules/ManPower/Services/ManPowerPlanningService.php
- app/Modules/RQMina/Policies/RQMinaPolicy.php
- app/Modules/RQMina/Services/RQMinaService.php
- app/Modules/RQProserge/Policies/RQProsergePolicy.php
- app/Modules/Dashboard/Policies/DashboardPolicy.php
- app/Modules/Transporte/Services/TransportePlanningService.php
- app/Modules/ParadaHerramientas/Services/ParadaHerramientaService.php
- tests/Feature/RbacOperationalScopeTest.php (nuevo)
- tests/Feature/WebPermissionMiddlewareTest.php

No se cambio Blade, CSS, JS, esquema ni datos reales. Los botones que consumen
AsistenciaPolicy reciben la misma decision del backend; recorrido manual aun
pendiente. Comprobar en copia aislada GERENTE de solo lectura, revocacion de un
rol adicional privilegiado y acceso conservado a las minas asignadas al rol
operativo activo.

## Tercer bloque: coherencia operativa y baseline RQ

### RBAC-06: administrar no coincidia entre policy y listados (P2)

Policies de Asistencia, Faltas y ManPower ya concedian el privilegio de alcance
mediante administrar. AsistenciaService, FaltasService, ManPowerParadasService
y ManPowerPlanningService comprobaban solo nombres de roles. Cuatro casos RED
demostraron que un administrador personalizado podia acceder a una mina segun
la policy, pero no verla en el listado. Los cuatro servicios ahora respetan la
capacidad explicita del mismo dominio. No se cambia la configuracion de roles.
Al retirar administrar, los listados vuelven a aplicar el scope existente.

### TEST-02: fixtures de RQ impedian probar 35 escenarios

RQMinaApiTest y RQMinaPlanManagementTest insertaban ADMIN ya presente contra
UNIQUE nombre. Se reutiliza el rol base dentro de DatabaseTransactions y se
usan nombres unicos para planners/lectores de ensayo. Se conserva el formato
historico read/write de esas fixtures. Ahora se verifican los permisos de
lectura/escritura, scope, aislamiento de planes, archivo, duplicacion y rollback
que antes no llegaban a ejecutarse.

### DATA-01: documentos logisticos se codificaban dos veces (P1)

Una vez reparadas las fixtures aparecio otro defecto real: al guardar un plan
sin IDs, RQMinaService pasaba un JSON string al cast array de documentos.
El campo terminaba como string JSON anidado, y el siguiente rescate podia
descartarlo. La fixture antigua tambien tenia una estructura documental que
no generaba el sistema; ahora usa la lista real con path y verifica metadatos.

La normalizacion devuelve array/null al modelo y acepta las listas antiguas
codificadas dos veces en el rescate. Pruebas RED/GREEN comprueban el guardado,
dos guardados sin IDs y recuperacion del formato historico. No se modifican
archivos, rutas de descarga ni registros reales; no se ejecuta migracion de
datos. Documentacion canonica: docs/rq-mina-planes.md.

### Archivos y verificacion del tercer bloque

- app/Modules/Asistencia/Services/AsistenciaService.php
- app/Modules/Faltas/Services/FaltasService.php
- app/Modules/ManPower/Services/ManPowerParadasService.php
- app/Modules/ManPower/Services/ManPowerPlanningService.php
- app/Modules/RQMina/Services/RQMinaService.php
- tests/Feature/RbacOperationalScopeTest.php: cuatro casos adicionales.
- tests/Feature/RQMinaApiTest.php: fixtures aisladas.
- tests/Feature/RQMinaPlanManagementTest.php: fixtures y dos regresiones documentales.
- docs/rq-mina-planes.md, este informe, inventario y avance del plan.

Prueba focalizada: 100 casos / 690 assertions, 0 errores y 0 fallos. PHP lint
de los ocho archivos de codigo/pruebas pasa; Pint del test de scope y
git diff --check pasan. No se recompilan vistas/assets porque no cambiaron.

Suite global ejecutada con PHPUnit directo y sin formateador de consola:
768 casos / 4316 assertions, 709 pasan, 19 errores y 40 fallos (59 casos
pendientes). Exit 1, esperado por los fallos. Comparacion exacta por clase/nombre
contra el inventario anterior: 35 resueltos, 0 nuevos. La suite global sigue
roja. Inventario actualizado en docs/security/test-failure-inventory.md.
XML: full-suite-third-block.xml; la captura anterior se conserva.

Interfaz: no se cambia Blade/JS/CSS; se corrigen datos preparados por servicios.
Manual pendiente: rol personalizado con administrar ve las minas autorizadas;
al retirarlo, solo ve su scope. Guardar un plan de ensayo y comprobar que los
documentos logisticos siguen descargables tras otro guardado. La decision de
quien asigna ADMIN/SUPERADMIN sigue sin resolverse.
