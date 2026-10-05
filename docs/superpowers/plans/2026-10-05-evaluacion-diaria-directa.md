# Evaluacion diaria directa Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Registrar evaluaciones diarias nuevas de cualquier persona de Personal, con mina, sin asistencia/parada y sin alterar la evaluacion desde asistencia.

**Architecture:** Una ruta y Request nuevos llaman a un metodo especifico de `EvaluacionDesempenoService`. El modelo compartido registra el origen y un token de envio unico; el servicio conserva notas, permiso y promedio en una transaccion. La vista existente ofrece el segundo modo de entrada.

**Tech Stack:** PHP 8.2+, Laravel 12, MySQL, Blade, PHPUnit 11.

**Spec:** `docs/superpowers/specs/2026-10-05-evaluaciones-asistencia-historica-design.md`

## Global Constraints

- No modificar `createDaily` ni relajar su regla de asistencia cerrada.
- Cinco criterios enteros 1..4; descripcion obligatoria para incidencia.
- Cualquier Personal puede ser evaluado; mina activa obligatoria; no autoevaluacion.
- Requiere `evaluaciones.evaluar_desempeno`, incluso si la cuenta no tiene Personal; la excepcion al scope de mina aplica solo a DIRECTA.
- Varias evaluaciones del mismo trabajador/fecha/mina son validas; un mismo token no se guarda dos veces.
- Ejecutar pruebas solo contra `proserge_app_test`; preservar datos y cambios no relacionados.

## Review Focus

- Usuario sin `personal_id` pero con permiso: guarda con autor usuario y `supervisor_id = null` (Task 2).
- Mina inexistente/inactiva o solicitud a ruta directa sin permiso: 422/403 sin insercion (Tasks 2-3).
- Dos envios distintos mismo dia frente a doble clic con el mismo token: dos filas frente a una (Task 2).
- Error de validacion de una nota conserva todas las otras notas y seleccion (Task 3).
- `createDaily` por asistencia mantiene unicidad por detalle y propietario original (Task 4).

---

### Task 1: Persistencia de origen e idempotencia

**Files:**
- Create: `database/migrations/2026_10_05_000100_add_origen_and_submission_key_to_evaluacion_desempeno.php`
- Modify: `app/Models/EvaluacionDesempeno.php`
- Test: `tests/Feature/EvaluacionDiariaDirectaTest.php`

**Interfaces:** Produce `origen` nullable para legado no clasificado (`ASISTENCIA`, `DIRECTA`, `HISTORICA` para filas verificadas), `submission_key` nullable y UNIQUE, y `supervisor_id` nullable para cuentas sin Personal; la futura carga historica podra usar el mismo `origen`.

- [ ] **Step 1: Write failing migration test.** `test_existing_daily_rows_keep_attendance_origin_and_submission_key_is_unique`: crear fila antigua, migrar en base aislada y afirmar origen ASISTENCIA; insertar dos claves iguales y afirmar rechazo MySQL 1062; verificar que dos `NULL` siguen permitidos.
- [ ] **Step 2: Run test to verify failure.** `php artisan test --filter EvaluacionDiariaDirectaTest` en `proserge_app_test`; esperado: falta columna.
- [ ] **Step 3: Implement migration/model.** Inspeccionar esquema real `database/setup` y migraciones antes de alterar; backfill ASISTENCIA solo donde `asistencia_detalle_id IS NOT NULL`, dejar `origen = NULL` en legado sin enlace hasta revision explicita; UNIQUE en `submission_key`; no eliminar FK de supervisor.
- [ ] **Step 4: Re-run test; expect PASS.**
- [ ] **Step 5: Commit only these files.** `git commit -m "feat: track daily evaluation origin and submission key"`.

### Task 2: Guardado directo y permisos

**Files:**
- Create: `app/Modules/Evaluaciones/Requests/StoreEvaluacionDirectaRequest.php`
- Modify: `app/Modules/Evaluaciones/Services/EvaluacionDesempenoService.php`
- Modify: `app/Modules/Evaluaciones/Policies/EvaluacionesPolicy.php` (solo si se necesita un metodo explicito para DIRECTA)
- Test: `tests/Feature/EvaluacionDiariaDirectaTest.php`

**Interfaces:** Produce `EvaluacionDesempenoService::createDirect(Usuario $usuario, array $payload): array`, con resultado `['ok' => true, 'item' => EvaluacionDesempeno]` o el error de dominio existente. Payload: `trabajador_id`, `mina_id`, `fecha`, `submission_key`, cinco notas, incidencia y observaciones.

- [ ] **Step 1: Write failing tests.** `test_direct_requires_permission_active_mine_and_valid_personal`; `test_direct_account_without_personal_can_evaluate_but_linked_user_cannot_self_evaluate`; `test_two_intentional_submissions_same_day_succeed_but_reused_key_does_not`; `test_average_rolls_back_with_failed_insert`.
- [ ] **Step 2: Run test; expect missing method/request failures.** `php artisan test --filter EvaluacionDiariaDirectaTest`.
- [ ] **Step 3: Implement Request and `createDirect`.** Aplicar `canEvaluateType`, verificar Personal y mina activa en servicio, no llamar `canAccessDestino` para esta ruta, calcular total con la funcion actual, guardar `destino_tipo = MINA`, `destino_id = mina_id`, `origen = DIRECTA`, autor usuario, token y promedio en una transaccion con bloqueo de Persona; convertir 1062 del token en error de dominio sin SQL.
- [ ] **Step 4: Re-run tests; expect PASS.**
- [ ] **Step 5: Commit only these files.** `git commit -m "feat: save direct daily evaluations safely"`.

### Task 3: Ruta y formulario en la pantalla existente

**Files:**
- Modify: `routes/web.php`
- Modify: `app/Modules/Evaluaciones/Controllers/EvaluacionDesempenoPageController.php`
- Modify: `app/Modules/Evaluaciones/Services/EvaluacionesPageService.php`
- Modify: `resources/views/evaluaciones/index.blade.php`
- Test: `tests/Feature/EvaluacionesWebTest.php`

**Interfaces:** POST `evaluaciones.desempeno.directa.store` bajo `web.permission:evaluaciones,evaluar_desempeno`; controlador `storeDirect(StoreEvaluacionDirectaRequest $request): RedirectResponse`.

- [ ] **Step 1: Write failing web tests.** `test_direct_form_uses_all_active_mines_for_authorized_user`; `test_invalid_grade_keeps_selection_and_old_input`; `test_direct_endpoint_rejects_unpermitted_user`; `test_success_redirects_to_daily_history_with_standard_flash`.
- [ ] **Step 2: Run tests; expect missing route/form failures.** `php artisan test --filter EvaluacionesWebTest`.
- [ ] **Step 3: Add route/controller/Blade mode.** Reutilizar selectores, cinco controles y mensajes existentes; incluir token UUID por formulario; selector de persona paginado/buscable conforme patron existente, endpoint con permiso de desempeno (el actual `searchPersonal` solo permite supervisor/residente); minas activas sin filtrar por scope solo para modo directo; deshabilitar submit mientras envia.
- [ ] **Step 4: Re-run tests and compile view.** `php artisan test --filter EvaluacionesWebTest`; `php artisan view:cache`; esperar PASS y exit 0.
- [ ] **Step 5: Commit only these files.** `git commit -m "feat: expose direct daily evaluation in existing view"`.

### Task 4: Historial y regresion de asistencia

**Files:**
- Modify: `app/Modules/Evaluaciones/Services/EvaluacionesPageService.php`
- Modify: `app/Modules/Evaluaciones/Services/EvaluacionDesempenoService.php` (solo si `find/list` oculta DIRECTA)
- Modify: `docs/evaluaciones.md`
- Test: `tests/Feature/EvaluacionesWebTest.php`
- Test: `tests/Feature/EvaluacionesApiTest.php`

**Interfaces:** El autor ve DIRECTA sin depender de scope minero; el flujo ASISTENCIA conserva sus restricciones y `asistencia_detalle_id` unico.

- [ ] **Step 1: Write failing regressions.** `test_direct_author_sees_new_row_without_mine_scope`; `test_attendance_daily_still_requires_closed_attendance_and_one_row_per_detail`.
- [ ] **Step 2: Run focused tests; expect direct visibility failure.**
- [ ] **Step 3: Limit origin-aware visibility changes to DIRECTA; do not widen ASISTENCIA.** Verificar que promedio y dashboard toman el mismo registro una vez; documentar el modo directo y la diferencia con asistencia.
- [ ] **Step 4: Run focused tests, `php -l` on touched PHP, `php artisan view:cache`, `git diff --check`; expect no errors.**
- [ ] **Step 5: Commit only task files.** `git commit -m "test: preserve attendance evaluation rules beside direct flow"`.
