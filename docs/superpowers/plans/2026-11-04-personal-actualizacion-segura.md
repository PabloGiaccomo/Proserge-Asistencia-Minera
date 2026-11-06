# Personal: actualización segura de ficha Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Corregir PER-01/02/04/05/06 y permitir que una actualización individual llegue a Ingresos como propuesta completa, aceptada únicamente por RR.HH. autorizado.

**Architecture:** Ampliar `PersonalIngreso` por propósito y revisión, conservando una propuesta separada de la ficha vigente. Reutilizar bandeja, catálogo, validación y disco privado; aislar emisión/consumo de enlaces, comparación y aplicación en servicios pequeños. La actualización nunca utiliza aceptación contractual ni sincronización minera.

**Tech Stack:** PHP 8.2+, Laravel 12, Eloquent/MySQL, Blade, JavaScript nativo, Vite, PHPUnit 11; sin dependencias nuevas.

**Diseño.** [Personal e Ingresos](../specs/2026-11-04-correcciones-modulos-y-rrhh-masivo-design.md#personal-e-ingresos)

## Global Constraints

- El alcance de esta entrega es la planificación. El despliegue se prepara por separado, tras pruebas de cada módulo.
- Trabajar sobre el checkout existente y preservar cambios ajenos; no asumir que el grafo del 25/09 representa el código actual.
- Ninguna actualización modifica estado laboral, contratos, minas, cargo, supervisor, cese, origen ni fecha de ingreso. Actualizar ficha no exige contrato nuevo.
- Ningún enlace expone banco, pensión, firma ni huella existentes; tampoco remuneración o términos contractuales.
- Enlace secreto, ligado a trabajador/propuesta/propósito, **72 horas**, revocable y consumible una vez al enviar; no DNI en URL ni envío automático.
- «Actualización recibida · Completa para revisión» describe recepción/completitud; no equivale a aprobación.
- Sólo disco `local` privado; conservar versiones y auditoría. No purga automática ni DELETE físico de habilitaciones.
- Tests exclusivamente MySQL aislado `proserge_app_test`, con `DatabaseTransactions` salvo ensayos concurrentes controlados. No `migrate:fresh` ni operaciones sobre datos reales.

## Review Focus

- Payload sin `minas` o con lista vacía: aceptación y edición nunca eliminan asignaciones, historial, examen, intentos ni archivos (Task 1).
- Un actor pierde permiso sensible después de abrir la pantalla: no recibe valores en nueva respuesta ni cambia sensibles por PUT manipulado (Task 2).
- Token vence/revoca entre GET y POST: ni borrador ni archivo ni envío pueden guardar; token consumido no sirve para leer ficha vigente (Tasks 3 y 6).
- Fallo después de copiar archivo o de cambiar estado documental: SQL y bytes anteriores siguen descargables; se limpia sólo la copia nueva sin referencia (Task 4).
- Dos revisores comparan la misma propuesta o cambia la ficha base: sólo una aplicación de la revisión reconocida, sin sobrescribir cambios intermedios (Task 7).

---

## Alcance contrastado y dependencias

PER-01 está en `PersonalService::update/syncMineRelations`, alcanzado por `PersonalIngresoService::persistIntoPersonal` y `PersonalFichaService::approve/updateManual`. PER-02 está en `PersonalPageController::edit/update` y `personal/edit.blade.php`; Ingresos/revisión tienen una protección sensible parcial que se reutiliza y completa. PER-04 está en `resolveToken` y los métodos de borrador. PER-05 está en `replaceFichaArchivo`, copia desde Ingresos y reemplazo de borrador. PER-06 está en `storeAttempt` y `renderPerformedForm` de Habilitación.

Ya existen `personal_ingresos`, archivos privados, `submission_uuid` único, ficha temporal, aprobación individual, estados documentales y `deactivateAssignment`. Propuesta por propósito, enlaces de actualización, comparación/versionado y aplicación aislada son capacidades nuevas. PER-03 corresponde al plan de Contratos; bloquear aceptación ampliada de altas hasta corregirlo. El plan de revisión masiva consume las interfaces de Tasks 5–7.

### Task 1: PER-01 — preservar asignaciones e historia al guardar ficha

**Files:**
- Modify: `app/Modules/Personal/Services/PersonalService.php` (`update`, `syncMineRelations`).
- Modify: `app/Modules/Personal/Services/PersonalFichaService.php` (`personalPayloadFromFicha`, `updateManual`, `approve`).
- Modify: `app/Modules/Personal/Services/PersonalIngresoService.php` (`personalPayloadFromData`).
- Modify: `app/Modules/Personal/Controllers/PersonalPageController.php`, `app/Modules/Personal/Requests/UpdatePersonalRequest.php`, `resources/views/personal/edit.blade.php` (retirar escritura minera del editor general).
- Test: `tests/Feature/PersonalIngresoPublicFlowTest.php`, `tests/Feature/PersonalFichaObservedResubmitTest.php`, `tests/Feature/PersonalFichaApproveContractDatesTest.php`.
- Create/Test: `tests/Feature/PersonalMinePreservationTest.php`.

**Interfaces:** Mantener `PersonalService::update(Personal $personal, array $payload): Personal`; ausencia de `minas` conserva relaciones. Una orden de cambiar minas sobre un trabajador existente devuelve validación 422 con «Gestiona las asignaciones desde Habilitación minera». Desactivar sigue usando la interfaz existente `PersonalMinaHabilitacionService::deactivateAssignment(PersonalMina $relation, Usuario $user, ?string $observation = null): PersonalMina`, con sus permisos de entrada; no se llama desde aceptar ficha.

- [ ] **Test red:** agregar `test_ingreso_accept_and_contract_not_signed_preserve_mining_history`, `test_temporal_approval_and_manual_update_preserve_mining_history`, `test_absent_and_explicit_empty_mines_never_delete_assignments`. Crear una cadena sintética asignación→historial→examen→intento→archivo; afirmar mismos UUID, resultados, precios snapshot, estado y bytes. Para orden explícita exigir 422 y cero cambios.
- [ ] **Run red:** `php artisan test --filter PersonalMinePreservationTest`; debe fallar porque actualmente se borran asignaciones o no se rechaza la orden vacía.
- [ ] **Code:** eliminar `minas => []` de payloads de ficha, no invocar sincronización al faltar la clave y quitar cualquier DELETE físico de asignaciones en `syncMineRelations`. Conservar creación inicial sin bajas; rechazar cambios mineros en edición general/API con mensaje operativo y enlace en editor a Habilitación. El API no ignora silenciosamente una orden explícita. Confirmar los dos callsites reales con `rg 'syncMineRelations\(' app`.
- [ ] **Verify:** `php artisan test --filter 'PersonalMinePreservationTest|PersonalIngresoPublicFlowTest|PersonalFichaApproveContractDatesTest|PersonalMinaHabilitacionCorrectedFlowTest'`; PASS, sin contratos o estados nuevos en casos de actualización.
- [ ] **Review:** inspeccionar diff y matrices de datos antes/después; revisar que la ruta de desactivación existente conserva actor/historial y que la ayuda de UI explique el cambio de punto de edición minera.

### Task 2: PER-02 — lectura y escritura sensibles coherentes

**Files:**
- Create: `app/Modules/Personal/Support/PersonalFichaAccess.php` (capacidad, claves y proyección segura).
- Modify: `app/Modules/Personal/Controllers/PersonalPageController.php`, `app/Modules/Personal/Controllers/PersonalFichaController.php`, `app/Modules/Personal/Controllers/PersonalIngresoController.php`.
- Modify: `app/Modules/Personal/Services/PersonalFichaService.php` (`updateManual`), `app/Modules/Personal/Services/PersonalIngresoService.php` (`updateIngreso`).
- Modify: `resources/views/personal/edit.blade.php`, `resources/views/personal/fichas/partials/ingreso-form-fields.blade.php`.
- Test: `tests/Feature/PersonalFichaSensitiveDataPermissionTest.php`, `tests/Feature/PersonalFichaObservedResubmitTest.php`.

**Interfaces:** `PersonalFichaAccess::permissionsFor(Usuario $actor): array` recarga roles activos usando `PermissionMatrix::normalizeForRoles`; `canSensitive(Usuario $actor): bool` admite los permisos directos sensibles ya existentes en Personal o Ingresos; `redact(array $data): array`; `assertSensitiveChangesAllowed(Usuario $actor, array $current, array $incoming, bool $signatureChanged, bool $fingerprintChanged): void`. Consumidores conservan firmas de servicios con actor; no aceptar un booleano enviado por cliente como autorización.

- [ ] **Test red:** GET `/personal/{id}/editar` sólo con editar/actualizar no contiene cuenta, CCI, CUSPP, remuneración, firma/huella ni sus URL en HTML/JSON/atributos. PUT que omite sensibles preserva valores; PUT que intenta añadir/cambiar/borrar cualquiera, firma o huella recibe 403 sin cambios. Repetir sin ficha previa, sensible vacío y permisos revocados desde el GET. Caso positivo exige edición + sensible; sensible solo nunca permite PUT. Extender los fixtures actuales, sin datos reales.
- [ ] **Run red:** `php artisan test --filter PersonalFichaSensitiveDataPermissionTest`; las nuevas pruebas deben reproducir exposición/escritura actual.
- [ ] **Code:** usar claves de catálogo `bancarios`/`pensionario` para identificar sensibles, además firma/huella; filtrar proyección antes de entregar a Blade y no cargar bytes sin capacidad. En backend preservar claves ausentes y rechazar cambios no autorizados ANTES de guardar archivos o normalizar campos ausentes a vacío. Aplicar la misma comprobación al editar Ingresos, que hoy preserva campos pero puede aceptar firma/huella. Mantener los permisos generales de cada ruta y los equivalentes sensibles existentes.
- [ ] **Verify:** `php artisan test --filter 'PersonalFichaSensitiveDataPermissionTest|PersonalFichaObservedResubmitTest|PersonalIngresoPublicFlowTest'`; PASS. Actualizar el test existente de reemplazo manual de firma/huella para declarar la capacidad necesaria.
- [ ] **Review:** buscar valores sintéticos de prueba en respuesta completa y validar descarga/PDF/revisión; no basta ocultar una sección por CSS. Verificar que errores y `old()` no reintroduzcan datos.

### Task 3: PER-04 — vigencia única en todos los accesos al token existente

**Files:**
- Modify: `app/Modules/Personal/Services/PersonalFichaService.php` (`resolveToken`, `submitFromWorker`, `storePublicDraftData`, `storePublicDraftArchivo`).
- Modify: `app/Modules/Personal/Controllers/PublicPersonalFichaController.php`, `resources/views/ficha-public/show.blade.php`.
- Test: `tests/Feature/PersonalFichaObservedResubmitTest.php`.

**Interfaces:** agregar método interno `assertPublicLinkEditable(PersonalFichaLink $link): void`, utilizado dentro de la transacción tras recargar/bloquear enlace y ficha. Separar expiración de edición de `read_until`; no convertir estado ACTIVO en permiso después de `expires_at` ni fiarse del resultado del GET.

- [ ] **Test red:** `test_expired_submitted_regularization_link_rejects_draft_data_and_file`, `test_revocation_between_resolution_and_write_prevents_public_save`, `test_public_edit_window_includes_expiration_instant_only`. Con reloj controlado: justo antes, exactamente y después del vencimiento; enviada/no enviada, revocada, sólo lectura y propósito incorrecto. Afirmar que datos/familiares/firma/bytes quedan iguales y que respuesta vencida no contiene datos de la ficha.
- [ ] **Run red:** `php artisan test --filter 'PersonalFichaObservedResubmitTest.*(expired|revocation|window)'`; esperado FAIL de las nuevas ramas.
- [ ] **Code:** revalidar identidad/estado/disabled/expires en cada mutación bajo bloqueo; condición permitida `now() <= expires_at`, vencida `now() > expires_at`. No escribir el archivo antes de la comprobación bajo bloqueo sin limpieza segura de Task 4. Mantener recibo de envío separado, sin datos personales vigentes. Eliminar al redirigir todo input sensible de la sesión pública.
- [ ] **Verify:** `php artisan test --filter 'PersonalFichaObservedResubmitTest|PublicQuickGuideViewTest'`; PASS sin reabrir enlaces consumidos o revocados.
- [ ] **Review:** comprobar GET y los tres POST públicos; añadir ensayo concurrente de revocar/enviar frente a guardar borrador en MySQL aislado, usando dos conexiones y barreras, no sleeps.

### Task 4: PER-05 — reemplazo documental recuperable con versión privada

**Files:**
- Create: `database/migrations/2026_11_04_000100_create_personal_archivo_versiones_table.php`, `app/Models/PersonalArchivoVersion.php`.
- Create: `app/Modules/Personal/Services/PersonalArchivoReplacementService.php`.
- Modify: `app/Modules/Personal/Services/PersonalFichaService.php` (`replaceFichaArchivo`, `storeFichaDocuments`, `syncManualHuella` y sus callers).
- Modify: `app/Modules/Personal/Services/PersonalIngresoService.php` (`replaceIngresoArchivo`, `replaceFichaArchivoFromStored`, `copyHuellaToFicha`, `copyArchivosToFicha`).
- Test: `tests/Feature/PersonalDocumentoEstadoTest.php`.
- Create/Test: `tests/Feature/PersonalArchivoReplacementTest.php`.

**Interfaces:** `PersonalArchivoReplacementService::replaceFichaFromStored(PersonalFicha $ficha, string $tipo, array $stored, ?Usuario $actor, bool $public): PersonalFichaArchivo` y `replaceIngresoFromStored(PersonalIngreso $ingreso, string $tipo, array $stored, ?Usuario $actor): PersonalIngresoArchivo`. `$stored={path:string,nombre_original:string,mime:?string,size:int}` siempre apunta a copia única privada ya validada. La llamada participa en la transacción exterior; limpieza de nuevos sin referencia se registra para rollback exterior, no después de un savepoint exitoso.

- [ ] **Test red:** inyectar excepción al INSERT de referencia, al guardar estado documental y al final de una transacción exterior después de un reemplazo exitoso; disco fake y SQL aislado deben conservar referencia/bytes previos y no dejar nuevo huérfano. En éxito, archivo actual descargable y versión anterior privada íntegra; probar huella y copia desde Ingresos, incluidos fallos de `Storage::copy` y resultado falso.
- [ ] **Run red:** `php artisan test --filter PersonalArchivoReplacementTest`; esperado FAIL por pérdida actual de bytes o copia no comprobada.
- [ ] **Code:** guardar nuevo con nombre único, comprobar escritura/copia, bloquear referencia actual, archivar metadata/ruta/hash/actor/fecha/origen/motivo antes de sustituirla, sin borrar el archivo anterior. Tabla nueva: UUID, `owner_type` FICHA/INGRESO, `owner_id`, `archivo_id`, `tipo`, `path`, `sha256`, `nombre_original`, `mime`, `size`, actor nullable, `origen`, `motivo`, created_at; índice owner/tipo/created_at y sin cascada que elimine historia. Sólo retirar nueva copia sin referencia tras rollback; cleanup fallido se registra con IDs y se puede reintentar, sin limpiar archivos de otra operación. Revisar `database/setup/008_personal_ficha_colaborador.sql` y migraciones de fichas/ingresos para compatibilidad, sin modificar el setup histórico.
- [ ] **Verify:** `php artisan test --filter 'PersonalArchivoReplacementTest|PersonalDocumentoEstadoTest|PersonalIngresoPublicFlowTest|PersonalFichaObservedResubmitTest'`; PASS. Añadir caso sin excepción con commit real controlado para verificar callbacks externos; el rollback automático de `DatabaseTransactions` no lo demuestra.
- [ ] **Review:** ninguna ruta directa expone versiones; descarga autenticada comprueba permiso, propietario y sensibilidad. No definir una política de borrado automático ni eliminar historia para resolver espacio.

### Task 5: propuesta de actualización versionada y requisitos por propósito

**Files:**
- Create: `database/migrations/2026_11_04_000200_add_update_purpose_to_personal_ingresos.php`.
- Create: `app/Models/PersonalIngresoRevision.php`, `app/Modules/Personal/Support/PersonalIngresoPurpose.php`.
- Modify: `app/Models/PersonalIngreso.php`, `app/Modules/Personal/Services/PersonalIngresoService.php` (consulta, etiquetas y distinción de propósito).
- Create: `app/Modules/Personal/Services/PersonalIngresoRevisionService.php` (propuesta, completitud, comparación; aplicación en Task 7).
- Create/Test: `tests/Feature/PersonalFichaUpdateProposalTest.php`, `tests/Feature/PersonalIngresoUpdateSchemaTest.php`.

**Interfaces:** `startUpdate(Personal $personal, Usuario $actor, string $purpose, array $requestedFields = []): PersonalIngreso`; `completeness(PersonalIngreso $ingreso): array{complete:bool,missing_fields:array,missing_documents:array,sensitive_pending:bool}`; `compare(string $ingresoId, Usuario $actor): array{ingreso_id:string,revision:int,base_hash:string,review_hash:string,changes:array,completeness:array,conflict:bool}`. Todas viven en `PersonalIngresoRevisionService`. `PersonalIngresoPurpose::editableKeys(string $purpose): array` define los campos permitidos para formulario, validación y aplicación.

- [ ] **Test red:** actualización sobre activo y cesado crea otra propuesta, no cambia ficha/trabajador/contratos/minas; `personal_existente_id` no se reasigna por DNI manipulado. Probar completitud general sin campos laborales/bancarios/pensionarios ni contrato nuevo y rechazo de toda clave sensible en la captura pública inicial. Vacío/omitido deben diferenciar «borrar»/«mantener». Migración desde esquema de Ingresos actual y variante histórica conserva filas, estados y UUID existentes.
- [ ] **Run red:** `php artisan test --filter 'PersonalFichaUpdateProposalTest|PersonalIngresoUpdateSchemaTest'`; esperado FAIL por interfaces/esquema nuevos ausentes.
- [ ] **Code:** agregar a `personal_ingresos`: `proposito` ALTA por defecto, `revision` unsigned=1, `base_hash` nullable char64, `completitud_json` nullable, `requested_fields_json` nullable; índices propósito/estado/submitted_at. Crear `personal_ingreso_revisiones` append-only: UUID, ingreso_id, revision, evento BORRADOR/ENVIADA/OBSERVADA/ACEPTADA, snapshot cifrado mediante cast `encrypted:array` sobre LONGTEXT, base_hash, review_hash, actor/origen/fecha; único ingreso/revision/evento. `BORRADOR` no aparece como recibido hasta enviar. Mantener filas antiguas ALTA sin reclasificarlas silenciosamente.
- [ ] **Code:** propósito ACTUALIZACION permite sólo secciones generales definidas en spec; campos requeridos son los `required` del catálogo dentro de esa allowlist y sus condiciones actuales, sin `lugar_nacimiento_extranjero` obligatorio. En esta entrega el enlace admite únicamente ACTUALIZACION general. Los datos sensibles se tramitan por RR. HH. autenticado. Un propósito público sensible queda diferido hasta contar con una verificación de identidad independiente del enlace. Documentos de identidad bloqueados y sin recuperación pública por DNI. El hash base cubre campos/archivos/familiares que se aplicarán; ausencia preserva, borrado explícito requiere comparación y validación.
- [ ] **Verify:** comandos de red pasan; probar cifrado de snapshots, exclusión sensible de proyecciones y que no se invoca `prepareIngressContract`. Revisión de esquema sin datos reales; no `migrate:fresh`.
- [ ] **Review:** revisar allowlist/requisitos con RR.HH. como decisión propuesta, no inferir cambios al contrato. Documentar semántica de estados y compatibilidad de enlaces anteriores.

### Task 6: emisión/captura individual con borrador aislado

**Files:**
- Create: `database/migrations/2026_11_04_000300_create_personal_ingreso_links_table.php`, `app/Models/PersonalIngresoLink.php`.
- Create: `app/Modules/Personal/Services/PersonalIngresoLinkService.php`, `app/Modules/Personal/Controllers/PersonalIngresoLinkController.php`.
- Modify: `app/Modules/Personal/Controllers/PublicPersonalIngresoController.php`, `app/Modules/Personal/Controllers/PersonalFichaController.php` (orientar regularización ya enviada al flujo nuevo), `routes/web.php`.
- Create: `resources/views/ficha-public/actualizacion.blade.php`, `resources/js/modules/personal-ficha-update.js`.
- Modify: `resources/views/personal/edit.blade.php`, `resources/views/personal/index.blade.php`, `resources/views/personal/fichas/partials/ingreso-form-fields.blade.php`, `resources/js/app.js`.
- Create/Test: `tests/Feature/PersonalIngresoUpdateLinkTest.php`, `tests/Browser/personal-update-link.cjs`.

**Interfaces:** `PersonalIngresoLinkService::issue(PersonalIngreso $ingreso, Usuario $actor): array{link:PersonalIngresoLink,url:string}`; `revoke(string $linkId, Usuario $actor): void`; `resolve(string $token): array{mode:string,link:?PersonalIngresoLink,data:array}`; `saveDraft(string $token, int $expectedRevision, array $fields, array $familiares, array $files): PersonalIngreso`; `submit(string $token, string $submissionUuid, int $expectedRevision): PersonalIngreso`. Los consumidores usan propósito, campos, requisitos y versiones de la tarea 5. La captura pública general rechaza firma, huella, banco y pensión. Los borradores y revisiones nuevos se guardan cifrados, sin proyectar valores sensibles en el listado.

- [ ] **Test red:** token aleatorio correcto vs incorrecto/vencido/revocado/consumido/otro propósito; no enumera por DNI. GET no contiene banco/pensión/firma/huella previos, términos contractuales ni URLs privadas; autosave y upload sólo cambian propuesta. Envío incompleto devuelve faltantes; completo devuelve etiqueta propuesta sin aprobación; doble submit igual devuelve mismo UUID, distinto contenido 409. Enlace revocado entre resolución y write no guarda. Observación conserva snapshot anterior y exige nueva revisión.
- [ ] **Run red:** `php artisan test --filter PersonalIngresoUpdateLinkTest`; esperado FAIL por rutas/servicios nuevos ausentes.
- [ ] **Code:** tabla links con UUID, ingreso_id, personal_id fijo, propósito, token_hash SHA-256 único, expires_at, revoked_at, submitted_at, actor emisor; guardar sólo hash del secreto de 256 bits o más. URL `/ficha-colaborador/actualizar#secreto`, sin identidad en la dirección. La vista inicial neutra canjea el fragmento por una sesión corta ligada al enlace mediante POST protegido, borra el fragmento del historial y habilita POST `/borrador`, `/archivos`, `/enviar` sobre la sesión. Métodos en `PublicPersonalIngresoController`, rate limit, CSRF, no-store y Referrer-Policy no-referrer. El canje limita intentos y descarta el secreto del cuerpo antes de cualquier log o flash. Un acceso preliminar por vista previa del enlace no lo consume. Rutas protegidas POST `/personal/{id}/solicitar-actualizacion` y `/personal/ingresos/links/{linkId}/revocar`, `web.auth` + edición Personal; no nuevas búsquedas públicas. Emitir desde fila/detalle por persona, modal con propósito/plazo y copiar; sin correo/WhatsApp automático.
- [ ] **Code:** revalidar enlace y revisión dentro de transacción, usar Task 4 para archivos de propuesta, invalidar escrituras después de enviar; respuesta posterior sólo recibo neutro. No precargar sensibles ni copiarlos del vigente al nuevo envío. Prefill mínimo por consentimiento explícito de RR.HH. al emitir, cero sensibles en browser storage/logs. Enlaces antiguos de regularización enviada/aprobada se revocan al pasar al flujo nuevo y se informa que deben regenerarse; iniciales/observadas no aprobadas mantienen recorrido protegido de Task 3.
- [ ] **Verify:** `php artisan test --filter 'PersonalIngresoUpdateLinkTest|PersonalFichaObservedResubmitTest|PersonalIngresoPublicFlowTest|PublicQuickGuideViewTest'`; `node tests/Browser/personal-update-link.cjs` usando fixtures sintéticos y Chrome/Playwright ya disponibles, 1440×1000 y 390×844. PASS: envío, autosave perdido, recuperación, expiración, archivos y texto operativo.
- [ ] **Review:** inspección de DOM/red local y logs para ausencia de secretos/datos previos; confirmar que obtener/enviar enlace no cambia Personal ni realiza mensajes externos.

### Task 7: comparación y aceptación de actualización por RR.HH.

**Files:**
- Modify: `app/Modules/Personal/Services/PersonalIngresoRevisionService.php`, `app/Modules/Personal/Services/PersonalIngresoService.php`.
- Modify: `app/Modules/Personal/Controllers/PersonalIngresoController.php`, `resources/views/personal/fichas/ingresos.blade.php`, `resources/views/personal/fichas/ingreso-review.blade.php`, `routes/web.php`.
- Create: `resources/views/personal/fichas/partials/update-comparison.blade.php`.
- Test: `tests/Feature/PersonalFichaUpdateProposalTest.php`, `tests/Feature/PersonalFichaSensitiveDataPermissionTest.php`.

**Interfaces:** producir `acceptUpdate(string $ingresoId, Usuario $actor, int $expectedRevision, string $expectedBaseHash, string $reviewHash): Personal` y `observeUpdate(string $ingresoId, Usuario $actor, int $expectedRevision, string $reason): PersonalIngreso` en `PersonalIngresoRevisionService`. Aceptación idempotente para mismo ingreso/revisión/hash; conflicto devuelve 409; autorización fallida 403. Preview masivo futuro consume `compare` y `acceptUpdate`, no `PersonalIngresoService::accept` para actualización.

- [ ] **Test red:** envía→compara→acepta actualiza sólo allowlist, preserva contrato/estado/minas/documentos omitidos y registra snapshot/actor/fecha/origen. No exige fecha contractual; token público no acepta. Sin aprobar 403; sin sensible ni lectura ni aplicación sensible; contenido sensible nuevo no puede pasar escondido como general. Comparación conjunta y aceptación usan la misma revisión/hash. Dos aceptaciones concurrentes devuelven el mismo resultado o conflicto, con un solo evento aplicado; edición de ficha entre preview y POST devuelve 409 sin sobrescritura.
- [ ] **Run red:** `php artisan test --filter PersonalFichaUpdateProposalTest`; las nuevas rutas/aplicación aún faltan.
- [ ] **Code:** POST `/personal/ingresos/{id}/aceptar-actualizacion` y `/observar-actualizacion` bajo `web.auth` + `personal_ingresos,aprobar`, GET revisión bajo ver. Separar controladores del alta contractual por propósito antes de validar contrato. Bloquear en orden trabajador→ficha→ingreso→archivo, recargar permisos, comparar revisión/hash, aplicar parche permitido sin `PersonalService::update` de reemplazo completo ni `syncFichaFromIngreso` que copia firma/huella indiscriminadamente. Guardar snapshot cifrado y aceptación en la misma transacción. Documentos nuevos quedan CARGADO pendientes de revisión documental, no APROBADO por aceptar contacto.
- [ ] **Verify:** `php artisan test --filter 'PersonalFichaUpdateProposalTest|PersonalFichaSensitiveDataPermissionTest|PersonalMinePreservationTest|PersonalIngresoPublicFlowTest|PersonalLifecycleStateTest'`; PASS. Dos conexiones MySQL con barreras prueban aceptación duplicada y conflicto base; todos los cambios se revierten/limpian en la base aislada.
- [ ] **Review:** UI muestra propósito, recibida/completa/revisión separados, comparación y siguiente acción; confirma que activo sigue activo y cesado sigue cesado sin crear/reabrir contrato. El listado y el detalle deben usar las mismas capacidades de `personal_ingresos` (hoy el listado consulta Personal y el detalle Ingresos).

### Task 8: PER-06 — conservar contexto al corregir intento minero

**Files:**
- Modify: `app/Modules/Personal/Controllers/PersonalMinaHabilitacionController.php` (`storeAttempt`, `completeScheduledAttempt`).
- Modify: `resources/views/personal/habilitacion-minera/index.blade.php` (`renderPerformedForm`, formulario programado, apertura/error bag).
- Create: `resources/js/modules/personal-mining-attempt-recovery.js`; Modify: `resources/js/app.js` sólo para cargar este comportamiento si se extrae el segmento ampliado.
- Create/Test: `tests/Feature/PersonalMinaAttemptRecoveryTest.php`, `tests/Browser/personal-mining-attempt-recovery.cjs`.

**Interfaces:** contexto de recuperación `mining_attempt_form={worker_id,assignment_id,exam_id,attempt_id:?string,action:performed|complete_scheduled,fields:{fecha_programacion,fecha_realizacion,fecha_vencimiento,resultado,nota,observacion}}`; no archivo ni datos médicos extra en almacenamiento persistente. Usar `requiere_nota_snapshot` y `nota_minima_snapshot` reales del examen, sin recalcular desde catálogo mutable.

- [ ] **Test red:** APROBADO sin nota requerida y upload inválido: mismo trabajador/mina/examen/acción abierto, fechas/resultado/observación/vencimiento conservados, error junto al campo y aviso «Vuelve a seleccionar el archivo». Corregir guarda un intento, no dos. Falla en un curso no abre modal EMO ni otro trabajador; sin permiso tras redirect no expone contexto ajeno.
- [ ] **Run red:** `php artisan test --filter PersonalMinaAttemptRecoveryTest`; `node tests/Browser/personal-mining-attempt-recovery.cjs`; esperado FAIL por contexto actual perdido.
- [ ] **Code:** validar request dentro del circuito que captura/retorna `withErrors` + input + contexto del propietario; cubrir también error bag previo al servicio. Al render, abrir sólo la asignación autorizada y escapar todos los valores; marcar nota requerida condicional a APROBADO/snapshot y mostrar mínimo. Nunca persistir File ni input médico en localStorage; reusar el patrón de recuperación EMO sin compartir su clave.
- [ ] **Verify:** mismos comandos y `php artisan test --filter 'PersonalMinaHabilitacionCorrectedFlowTest|PersonalMinaExamenesMinerosTest'`; PASS; navegador desktop/móvil sin solapamientos y botón de corrección accesible.
- [ ] **Review:** reglas de nota, máximo de intentos/3 de 2, NO_APLICA y curso/EMO permanecen en servicio, no se redefinen en JS.

### Task 9: verificación integrada, documentación y entrega revisable

**Files:** Modify: `docs/BUSINESS_RULES_RRHH.md`, `docs/processes/personal/index.md`, `docs/ARCHITECTURE_MAP.md`, `docs/MAPA_ARCHIVOS.md`, `docs/OPERACION_DESPLIEGUE.md`; actualizar BPMN afectados sólo después de confirmar el flujo final.

**Interfaces:** el plan de Ingresos masivos recibe `compare`/`acceptUpdate`/`PersonalFichaAccess`; el plan de Contratos recibe sólo altas con `personal_id`+`contrato_id`, nunca una actualización.

- [ ] **Verify:** confirmar conexión efectiva a `proserge_app_test` y servidor aislado antes de ejecutar. Ejecutar suites focalizadas anteriores y las de `PersonalAntiguoRegularizationTest`, `PersonalContratoRenewalTest`, `PersonalDocumentoDownloadTest` para detectar efectos cruzados; conservar salida PASS/fallo y restricciones de entorno. Los regresiones nuevas prueban el defecto, una suite verde previa no lo acredita.
- [ ] **Verify:** `php -l` para cada PHP tocado; `vendor/bin/pint --test` limitado a archivos del cambio; `php artisan view:cache`; `npm run build`; `git diff --check`. No editar assets con hash a mano; generar build requerido por cPanel sólo al implementar.
- [ ] **Review:** revisión desktop/móvil sintética con rol lector, editor no sensible, aprobador y aprobador sensible; probar volver, observar, nuevo enlace, timeout, rollback y conflicto. No registrar secretos/datos completos ni tomar capturas reales.
- [ ] **Code/docs:** sincronizar reglas y puntos de edición; documentar cambio de edición minera, protección sensible, estados/completitud, revocación de regularizaciones antiguas y conservación de versiones. Planificar migraciones incrementales sobre copia realista con backup de MySQL+private; no reparar datos históricos automáticamente.
- [ ] **Review/entrega:** revisión independiente de invariantes y seguridad de aplicación antes de cualquier commit/publicación; resumir archivos, pruebas ejecutadas/pendientes y riesgos. No ejecutar despliegue con esta planificación. Implementación recomendada por tareas con revisión fresca por tratar datos personales, archivos y concurrencia.

## Criterios de aceptación del plan

Cada referencia PER-01/02/04/05/06 tiene regresión y tarea propia; PER-03 está enlazada como dependencia contractual. Un trabajador existente recibe un enlace individual copiable, envía a Ingresos y mantiene su ficha/estado/contratos/minas hasta aceptar RR.HH. Los datos sensibles previos nunca atraviesan el enlace, el borrador es aislado y una revisión obsoleta no puede sobrescribir cambios más recientes. La planificación no incluye importación/reparación masiva de datos reales, renovación contractual ni mensajería.
