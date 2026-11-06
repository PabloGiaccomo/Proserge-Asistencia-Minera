# Transporte: flujo y alcance Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Corregir R1, R4 y R6: autorizar cada accion de Transporte, hacer atomica la reubicacion y permitir crear, corregir y confirmar servicios PERSONAL desde la interfaz.

**Architecture:** Una politica de Transporte valida la accion y el alcance de mina antes de serializar o escribir; los servicios conservan la ultima comprobacion dentro de la transaccion. Las operaciones de pasajeros bloquean los servicios padres en orden estable y revalidan capacidad/contexto antes de modificar. La UI consume capacidades y faltantes calculados por backend y reutiliza los Requests existentes.

**Tech Stack:** PHP 8.2+, Laravel 12, MySQL, Blade, JavaScript nativo, CSS propio, Vite 7, PHPUnit 11 y Playwright ya disponible.

**Spec:** `docs/superpowers/specs/2026-11-04-correcciones-modulos-y-rrhh-masivo-design.md`.

## Global Constraints

- Estado: propuesta pendiente de aprobacion; este documento no autoriza implementacion, FTP, despliegue, commits ni dependencias nuevas.
- Preservar todos los cambios locales anteriores y los archivos privados byte por byte; no ejecutar tests en la base normal de 3306.
- Toda accion critica web debe pasar por `web.auth` y `web.permission:modulo,accion`.
- Toda escritura API debe requerir `auth.token`; aplicar `mina.scope` o filtro de servicio cuando el dominio sea dependiente de mina.
- Ocultar botones sin permiso no reemplaza la autorizacion backend.
- Ningun modulo de transporte cambia estado laboral ni contratos.
- MySQL de prueba aislado: host `127.0.0.1`, puerto `13376`, base `proserge_app_test`; verificar la conexion efectiva antes de abrir transacciones. No levantar ni modificar la instancia normal.
- No editar `vendor`, `node_modules` ni assets con hash a mano; construir assets mediante `npm run build` cuando corresponda.

## Review Focus

- Un UUID conocido de otra mina no permite modificar ni recibir unidad, conductor o pasajeros: matriz web/API en tarea 1.
- Alcances mixtos con una fila valida y otra invalida conservan todos los alcances y eventos previos: tarea 1.
- Destino lleno, sin alcance o sin permiso no retira al pasajero del origen: tarea 2.
- Una respuesta 422 o la perdida de red no vacia placa, conductor, capacidad ni contexto: tareas 3 y 4.
- Servicio FINALIZADO/ANULADO o plan archivado sigue consultable y no ofrece ni acepta una edicion ordinaria: tareas 1, 3 y 4.

---

## Evidencia y limites

El 04/11 se reejecuto `php storage/app/module-audit/2026-11-04/recursos-repro.php` con SQLite `:memory:` y fixtures sinteticas. R1 persiste: `syncAlcances` escribe sin permiso, borra alcances antes de validar y `changeEstado` acepta una mina fuera del scope. R4 persiste: una reubicacion devuelve `TRANSPORTE_PASSENGER_CONTEXT_INVALID` y deja el origen `REUBICADO`. R6 se confirmo por fuente: no hay `conductor_personal_id`, accion de edicion ni `old()` en `resources/views/transporte/planificacion.blade.php`, pese a existir Request y endpoint de edicion. Esto no demuestra concurrencia MySQL ni constituye una reparacion.

Leer antes de ejecutar: `AGENTS.md`, `docs/CODEX_CONTEXT.md`, `docs/transporte-operativo.md`, `docs/security/authorization-audit.md`, `app/Support/Rbac/MinaScopeAccess.php`, el informe transversal y la evidencia `storage/app/module-plan/2026-11-04/transporte-movil-verificacion.md`. El grafo disponible es del 25/09: sirve de orientacion; cada conclusion de este plan se verifico contra fuente actual.

## Mapa de archivos y contratos

| Archivo | Accion | Responsabilidad |
|---|---|---|
| `app/Modules/Transporte/Policies/TransportePolicy.php` | Crear | Matriz accion/permiso y alcance por mina de origen/destino. |
| `app/Modules/Transporte/Services/TransportePlanningService.php` | Modificar | Frontera de servicio, validacion previa, transacciones, locks, faltantes y capacidades por servicio. |
| `app/Modules/Transporte/Controllers/TransporteController.php` | Modificar | Delegar autorizacion y mapear errores HTTP sin serializar registros denegados. |
| `app/Modules/Transporte/Controllers/TransportePageController.php` | Modificar | Contexto de formularios, errores 403 y recuperacion de entradas. |
| `app/Modules/Transporte/Requests/StoreTransporteServicioRequest.php` | Modificar | Validacion de conductor y contexto de formulario, conservando contrato API. |
| `app/Modules/Transporte/Requests/UpdateTransporteServicioRequest.php` | Modificar | Edicion parcial sin reescribir campos no enviados. |
| `routes/web.php`, `routes/api.php` | Modificar solo si lo requiere la matriz | Mantener autentificacion y acciones independientes en cada canal. |
| `resources/views/transporte/planificacion.blade.php` | Modificar | Alta/edicion, faltantes y acciones por capacidad real. |
| `resources/views/transporte/partials/servicio-form.blade.php` | Crear | Campos reutilizados por alta/edicion con identidad de formulario. |
| `resources/js/modules/transporte-planning.js` | Crear | Envio progresivo, estado pendiente y recuperacion sin persistir datos personales. |
| `resources/css/modules/transporte-planning.css`, `resources/js/app.js`, `resources/css/app.css` | Crear/Modificar | Extraer solo estilos ampliados e incorporar modulo segun patron existente. |
| `tests/Feature/TransporteApiTest.php` | Modificar | Autorizacion y rollback sobre esquemas existentes. |
| `tests/Feature/TransporteWebFlowTest.php` | Crear | Creacion/edicion/confirmacion, roles separados y old input. |
| `tests/Feature/TransporteConcurrencyTest.php` | Crear | Capacidad y reubicacion con dos conexiones/procesos aislados. |
| `tests/Browser/transporte-planning.cjs`, `tests/Browser/render-transporte-planning.php` | Crear | Fixtures sinteticas y escenarios 1440/390 con fallos HTTP/red. |
| `docs/transporte-operativo.md` | Modificar al implementar | Canonizar flujo, matriz de acciones y limites comprobados. |

No se propone migracion en este plan. El problema documental de Logistica R5 pertenece al plan de recursos/Logistica; aqui se exige que ninguna operacion de Transporte borre archivos ni referencias de requerimientos.

### Task 1: Autorizar cada accion y reemplazar alcances de forma atomica (R1)

**Files:** Crear `app/Modules/Transporte/Policies/TransportePolicy.php`; modificar `app/Modules/Transporte/Services/TransportePlanningService.php`, ambos controladores de Transporte y las rutas indicadas; test `tests/Feature/TransporteApiTest.php` y crear `tests/Feature/TransporteWebFlowTest.php`.

**Interfaces:**
- Produce `TransportePolicy::allows(Usuario $usuario, string $accion, TransporteServicio $servicio): bool` y `canCreate(Usuario $usuario, RQMina $parada): bool`.
- Acciones exactas: `editar` y `alcances` aceptan `editar|actualizar`; `agregar_pasajeros`, `entregar|actualizar`; `retirar_pasajero`, `recepcionar|actualizar`; `cambiar_estado`, `actualizar|entregar|recepcionar`; `copiar` y crear, `crear`. Reubicar exige retirar en origen Y agregar en destino. Aplicar excepciones administrativas vigentes de la matriz, sin convertir una accion ordinaria en otra.
- Consume `MinaScopeAccess::allows(Usuario $usuario, ?string $minaId): bool` para alcance y evalúa aparte la acción directa de `transportes`. Conserva los privilegios de alcance que el helper reconoce para roles activos.
- Conserva firmas publicas de `createServicio`, `updateServicio`, `addPasajeros`, `retirePasajero`, `reubicarPasajero`, `copyServicio` y `changeEstado`. Cambia `syncAlcances(Usuario $usuario, TransporteServicio $service, array $alcances): array` para devolver el contrato `ok/code/message/forbidden`; actualizar TODOS sus callers internos y el controlador API.
- Produce `capabilities` por servicio: `editar`, `confirmar`, `agregar_pasajeros`, `retirar_pasajero`, `copiar`, `reubicar`; el estado y contexto editable tambien condicionan cada flag. Un permiso de crear no concede editar y recepcionar no concede agregar.

- [ ] **Step 1: Agregar tests que fallan.** Nombres: `test_every_transport_write_requires_action_permission_and_both_mine_scopes`, `test_invalid_scope_batch_preserves_all_previous_scopes_and_events`, `test_finished_cancelled_and_archived_transport_rejects_ordinary_mutations`. Data provider por cada endpoint API y su equivalente web, actor sin permiso, permiso sin scope, mina ajena y rol inactivo. Aserciones: 403 sin `servicio`/pasajeros; mismo snapshot completo de servicio, alcances, pasajeros y eventos antes/despues. Alcance invalido responde 422; ninguna fila parcial ni perdida. Repetir mediante servicio directo para impedir que una ruta futura omita la frontera.
- [ ] **Step 2: Ejecutar rojo.** `php artisan test --configuration=storage/app/module-plan/2026-11-04/phpunit-isolated.xml --filter='TransporteApiTest|TransporteWebFlowTest'`. Esperado: fallos por escritura sin scope/permiso, alcances perdidos o estado terminal editable. Crear la configuracion aislada durante implementacion a partir de `phpunit.xml`, con `force=true`, guardias de puerto/base y cache/sesion array; no ejecutar el comando si no existe el entorno aislado.
- [ ] **Step 3: Implementar la politica y frontera.** Autorizar antes de cargar relaciones/serializar. Dentro de la transaccion, recargar servicio, parada y plan y revalidar accion, scope y editabilidad. Validar TODAS las filas de alcances contra parada/plan/fecha/turno antes de borrar o insertar; reemplazo y evento comparten transaccion. El estado terminal es de consulta; corregir un finalizado requeriria un flujo auditado distinto, fuera de este plan. Evitar que PATCH o alta con `estado` salten el endpoint de transicion. Mantener errores API coherentes con `ApiResponse`; un forbidden web devuelve 403, no un 302 ambiguo con exito aparente.
- [ ] **Step 4: Ejecutar verde.** Repetir comando anterior; PASS con snapshots intactos en denegaciones y ninguna respuesta nominal ajena. Revisar rutas con `php artisan route:list --path=transporte -v` sin conexion a 3306.
- [ ] **Step 5: Revision de tarea.** `git diff --check`; revision independiente de matriz completa y callers de `syncAlcances`. No commit hasta autorizacion de ejecucion/integracion.

### Task 2: Reubicacion atomica y capacidad bajo bloqueo padre (R4)

**Archivos.** Modificar `app/Modules/Transporte/Services/TransportePlanningService.php`, la integración `retireActivePassengerForDetail` en `app/Modules/ManPower/Services/GrupoTrabajoService.php` y `tests/Feature/TransporteApiTest.php`. Crear `tests/Feature/TransporteConcurrencyTest.php`.

**Interfaces:**
- Consume politica/capabilities de tarea 1.
- Conserva `reubicarPasajero(Usuario $usuario, TransporteServicio $service, string $pasajeroId, string $destinoId, string $motivo): array` y errores `TRANSPORTE_RELOCATION_INVALID`, `TRANSPORTE_CAPACITY_EXCEEDED`, `TRANSPORTE_PASSENGER_CONTEXT_INVALID`.
- Orden compartido cuando se afectan distribuciones: GrupoTrabajo por UUID si el flujo lo bloquea → GrupoTrabajoDetalle por UUID → TransporteServicio por UUID → TransporteServicioPasajero por UUID. Man Power ya bloquea la distribución antes de retirar su pasajero. Resolver IDs antes de tomar locks, releer vínculos y abortar o reintentar si cambian. Cualquier acción que sólo bloquea el servicio evita tomar después un lock de distribución.
- El retiro interno desde Man Power revalida pertenencia y la autorización de distribución ya comprobada. Persiste su evento y propaga el error a la operación de origen si el retiro no se pudo completar. Los endpoints públicos siempre usan la política de Transporte. Produce ayudantes privados `lockServicios(array $ids): Collection` y `validatePassengerTransfer(Usuario $usuario, TransporteServicio $origen, TransporteServicio $destino, TransporteServicioPasajero $pasajero): array`; validan ambos scopes/permisos, fecha, turno, tramo, estado, alcance y asiento antes de retirar.

- [ ] **Step 1: Agregar regresiones que fallan.** `test_failed_relocation_preserves_origin_passenger_and_events` con destino lleno, sin alcance, CARGA, turno/fecha/tramo incompatible, terminal, otra mina, permiso insuficiente y excepcion de insercion/evento. Aserciones: 422/403 segun causa, origen sigue `ASIGNADO` con actor/fecha/motivo originales y destino/eventos byte-equivalentes en JSON; exito cambia origen a `REUBICADO` y crea exactamente un pasajero/evento de destino, sin tocar Man Power.
- [ ] **Step 2: Ejecutar rojo.** Mismo comando aislado filtrando `TransporteApiTest` y el nombre anterior. Esperado: caso sin alcance deja origen REUBICADO en codigo actual.
- [ ] **Step 3: Implementar transaccion unica.** Releer capacidad y candidatos despues de adquirir locks. El callback solo llega al commit cuando retirada, insercion y ambos eventos terminan; una falla despues del primer cambio lanza excepcion de dominio y se traduce al resultado HTTP fuera de la transaccion. Un retorno `ok=false` tras escritura no confirma. Extraer ayudantes internos de escritura ya autorizada para no abrir ciclos recursivos de locks. No usar compensacion que fabrique otra asignacion para ocultar un retiro parcial.
- [ ] **Step 4: Agregar/ejecutar prueba determinista de dos conexiones.** `test_two_assignments_cannot_take_the_last_seat` y `test_opposite_relocations_use_stable_parent_order`: fixtures confirmadas exclusivamente en 13376; worker A toma lock padre, B intenta ocupar/reubicar y queda detenido mediante barrera IPC; A confirma, B relee y rechaza si no queda capacidad. Aserciones: ocupacion <= capacidad, ninguna persona activa duplicada por tramo, no retiro huerfano, eventos una vez. Timeout acotado y limpieza `finally` por UUID de fixture. No envolver fixtures compartidas en `DatabaseTransactions`, pues el segundo proceso no veria filas sin commit. Las pruebas ordinarias si usan ese trait. Ejecucion futura: `php artisan test --configuration=storage/app/module-plan/2026-11-04/phpunit-isolated.xml --filter=TransporteConcurrencyTest`. Estas pruebas NO se han ejecutado en esta planificacion.
- [ ] **Step 5: Validar integracion.** PASS de tests Transporte, `tests/Feature/ManPowerApiTest.php` y retiro automatico ya existente. Documentar como riesgo separado las carreras de placa/conductor entre servicios distintos: los locks por servicio no serializan altas de servicios diferentes; no afirmar que estan resueltas sin prueba dedicada y mecanismo global aprobado.

### Task 3: Alta, edicion y faltantes operativos para confirmar (R6)

**Files:** Modificar servicio, PageController y Requests de Transporte; crear parcial `servicio-form.blade.php`; modificar `planificacion.blade.php`; test `tests/Feature/TransporteWebFlowTest.php`.

**Interfaces:**
- `buildContext(Usuario $usuario, array $filters): array` agrega `conductores` (id/nombre de candidatos autorizados), `capabilities` y `faltantes_confirmacion` por servicio, sin cambiar claves actuales. Reutilizar consulta/selector de Personal existente tras localizarlo; no enviar la lista completa de documentos o datos personales al browser.
- Produce `confirmationRequirements(TransporteServicio $servicio): array` con codigos `placa`, `conductor`, `capacidad`, `alcance`, `sobreocupacion`; `validateStateChange` y la vista usan esa misma regla, sin duplicarla en JS.
- Parcial consume `$formKey` (`nuevo` o UUID), `$values`, `$conductores`, `$groups`, `$capabilities`, `$errors`; `form_key` identifica que formulario recibe old input. Nunca confiar en ese marcador para autorizar.

- [ ] **Step 1: Agregar test que falla.** `test_operator_can_create_edit_and_confirm_personal_service_from_web`: crear PERSONAL con conductor real del sistema, verificar snapshot del nombre, cambiar capacidad/placa/alcances por PATCH y confirmar; assert `CONFIRMADO`, eventos con actor real y zero cambios en contrato/estado laboral. `test_transport_actions_respect_independent_permissions`: lectores no reciben formularios POST, crear solo ve alta, editar solo ve edicion, entregar/recepcionar solo sus acciones. `test_confirmation_lists_all_missing_data_with_edit_action`: faltan conductor/capacidad/alcance; mostrar lista y siguiente accion, sin submit habilitado que prometa confirmacion. Backend sigue rechazando el POST forjado.
- [ ] **Step 2: Ejecutar rojo.** Comando aislado con `--filter=TransporteWebFlowTest`; esperado: faltan selector, accion Editar y lista de pendientes.
- [ ] **Step 3: Implementar campos reutilizados y accion Editar.** Mostrar conductor en alta y formulario de edicion accesible por fila/panel. Edicion contiene tipo/tramo/transportista/vehiculo/placa/conductor/capacidad/horas/origen/destino/observaciones/alcances; no pedir de nuevo campos ya almacenados. FINALIZADO/ANULADO y plan archivado solo consulta. `Confirmar` usa faltantes y capability propios; agregar pasajero no aparece para CARGA ni usa un `canUpdate` combinado para conceder todas las acciones. Preservar snapshots historicos y rechazar cambios de contexto con pasajeros incompatibles.
- [ ] **Step 4: Recuperar entradas.** Usar `old()` solo para `form_key` fallido; conservar contexto parada/plan/fecha/turno y conductor seleccionado en errores de Request y de negocio. Volver al mismo panel/servicio con foco en error. El resto de filas mantiene sus valores guardados. El retorno acepta solo rutas internas nombradas y filtros permitidos (`rq_mina_id`, `rq_mina_plan_id`, `fecha`, `turno`, `lista` con llaves del listado validadas); ignorar `return_url` externo, javascript y contexto ajeno. No guardar nombres/DNI/observaciones en localStorage/sessionStorage.
- [ ] **Step 5: Ejecutar verde y compilar.** Tests de tarea PASS; `php -l` en PHP tocado, `php artisan view:cache`, `npm run build` si se extrajeron/importaron assets; revisar que el build no incluya eliminaciones ajenas. No usar cache de configuracion que apunte a la base normal.

### Task 4: Recuperacion ante fallos y validacion mobile/desktop

**Files:** Crear modulo JS/CSS y renderer/browser indicados; modificar imports de `app.js`/`app.css` y vista de Transporte; actualizar `docs/transporte-operativo.md` al implementar.

**Interfaces:**
- `initTransportePlanning(root: HTMLElement): void` mejora formularios nativos; conserva funcionamiento POST/PATCH sin JavaScript. Un request pendiente pertenece a un unico `form_key`; solo su respuesta valida puede cerrar/restablecer ese formulario.
- Respuesta con resultado incierto (corte de red/500 despues de commit) conserva inputs y ofrece consultar estado del servicio antes de reenviar; no hace retries automaticos de escrituras ni muestra exito por `response.ok` sin contrato valido.

- [ ] **Step 1: Agregar browser specs.** `transporte-planning.cjs` usa fixtures sinteticas e intercepta HTML/JSON y assets locales, sin servidor de negocio cuando baste. Escenarios: crear/editar/confirmar, seleccion conductor con teclado, dos formularios simultaneos, 401/403/419/422/500, HTML de login con status 200, JSON truncado, offline y doble clic. Aserciones: un envio pendiente, inputs/contexto/alcances conservados, error legible con foco, ningun exito falso ni repeticion automatica. Sin JS, POST fallido conserva old y el formulario sigue usable.
- [ ] **Step 2: Ejecutar rojo.** `node tests/Browser/transporte-planning.cjs`; esperado fallo de ausencia de controles/recuperacion antes del cambio. Resolver Playwright/Chrome ya instalados; si faltan, registrar bloqueo sin instalar dependencia.
- [ ] **Step 3: Implementar mejora progresiva.** Estado pendiente por formulario, desbloqueo en `finally`, mensajes inline accesibles y controles legibles. Si no hay fetch disponible, seguir envio nativo y PRG. Capturar datos antes del request y nunca resetear por un error. Una fecha usa `type=date` con valor ISO `2026-11-04`, etiqueta visible y presentacion humana es-PE; no enviar `04/11/2026` al contrato ISO.
- [ ] **Step 4: Ejecutar verde en 1440x1000 y 390x844.** Con datos poblados y textos largos: no overflow horizontal global, tabla desplazable interna, teclado/foco visibles, scroll hasta ultimo control y boton sin taparse por navegacion fija/teclado virtual; revisar capturas completas. Filtros y volver conservan parada/plan/fecha/turno. Ejecutar tambien con zoom 200% y reduccion de movimiento; cero errores JS. La automatizacion de viewport no sustituye un dispositivo fisico: registrar el limite.
- [ ] **Step 5: Cierre verificable.** Ejecutar tests focalizados y suite relevante Transporte/ManPower/RQMina, `git diff --check`, lint PHP, compilacion Blade/build y revisar diff solo propio. Documentar comandos, conteos, capturas y pendientes, incluyendo concurrencia no ejecutada. Revision independiente antes de cualquier integracion autorizada; no FTP ni despliegue en esta tarea de plan.

## Dependencias y aceptacion

Orden: tarea 1 -> tarea 2; tarea 3 consume capacidades/faltantes de tarea 1; tarea 4 consume formulario de tarea 3. Puede revisarse la UI en paralelo cuando esos contratos esten fijados, sin compartir ediciones del servicio. Coordinar retiro de Man Power y locks con su plan antes de ejecutar tarea 2.

Aceptacion: todas las mutaciones deniegan permiso/scope/contexto incorrectos; un error conserva filas y eventos; la reubicacion conserva un transporte valido o no modifica nada; un operador autorizado completa PERSONAL desde web con conductor y edicion; formularios conservan entradas/contexto y no registran exito incierto; roles independientes y vistas 390/1440 pasan. Esta propuesta no declara aprobada ninguna de esas correcciones.
