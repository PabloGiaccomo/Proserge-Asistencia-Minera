# Importacion historica unica Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Previsualizar y confirmar una sola vez asistencia y evaluaciones antiguas, por DNI, sin inventar mina/parada y sin importacion parcial.

**Architecture:** Un importador de solo lectura produce un manifiesto de conciliacion; un lote persistente y su hash controlan confirmacion transaccional. Asistencia historica vive en almacenamiento separado; evaluaciones validadas usan los modelos actuales con origen HISTORICA. La UI de Evaluaciones ofrece preview, reporte y confirmacion administrativa temporal.

**Tech Stack:** PHP 8.2+, Laravel 12, MySQL, PhpSpreadsheet 5, Blade, PHPUnit 11.

**Spec:** `docs/superpowers/specs/2026-10-05-evaluaciones-asistencia-historica-design.md`

## Resultado de ejecucion

Tareas 1-7 implementadas. Validacion final: 88 pruebas, 510 aserciones en
`proserge_app_test`, PHP lint, Pint del importador, compilacion Blade y controles
Playwright de revision/dashboard en escritorio y movil. No se importaron datos
reales ni se desplegaron cambios. Los totales de supervisor/residente se
preservan por decision posterior del usuario, no por las formulas actuales.

Limitacion respecto del plan inicial: el lector carga las hojas seleccionadas
en memoria, no usa streaming/chunks. Ensayar volumen y limites PHP en copia
aislada antes de habilitar la carga; no asumir que 20 MB limita la memoria
descomprimida. La unicidad se verifica por indices y reintentos secuenciales;
queda pendiente un ensayo de concurrencia real entre procesos.

## Global Constraints

- Ejecutar este plan despues de `2026-10-05-evaluacion-diaria-directa.md`; reutilizar su campo `origen`.
- Libros DATA 2025 son apoyo de identidad, nunca actualizaciones de Personal.
- Asistencia historica significa presencia efectiva, sin FK de mina/parada ni alimentacion de asistencia operativa/faltas.
- DNI unico de Personal es requisito; nombre es evidencia secundaria; nunca crear trabajador o evaluador ficticio.
- Cualquier bloqueante impide confirmar el archivo completo; no hay guardado parcial.
- Confirmadas, las evaluaciones historicas cuentan en promedios/dashboard sin enlace a asistencia cerrada.
- Solo `evaluaciones.administrar` puede previsualizar/confirmar; endpoint se cierra tras lote confirmado.
- No ejecutar importaciones sobre produccion como parte de pruebas; usar `proserge_app_test` y backup previo al despliegue.

## Review Focus

- DNI con cero inicial, celda numerica o notacion Excel: resolver sin alterar identidad o bloquear explicitamente (Task 2).
- ID interno rotulado DNI no existe en hoja PERSONAL: bloquear, no asociar por nombre (Task 2).
- Dos filas iguales DNI/fecha/notas con multiplicidad: no colapsar sin ID o resolucion de ocurrencia (Task 2).
- Archivo corregido entre preview y confirmacion o doble confirmacion simultanea: abortar/reutilizar lote sin duplicar (Task 4).
- Una fila tardia falla despues de otra valida: rollback de todo, incluidos promedios y estado del lote (Task 4).

---

### Task 1: Estructura historica y compatibilidad

**Files:**
- Create: `database/migrations/2026_10_05_000200_create_evaluaciones_historical_import_tables.php`
- Modify: `app/Models/EvaluacionDesempeno.php`
- Create: `app/Models/AsistenciaHistorica.php`
- Create: `app/Models/EvaluacionesImportLote.php`
- Test: `tests/Feature/EvaluacionesHistoricalImportTest.php`

**Interfaces:** `asistencia_historica` guarda UUID, `trabajador_id`, `fecha`, `presente`, `source_file`, `source_sheet`, `source_row`, `source_id`, `lote_id`; sin `mina_id`/`parada_id`. `evaluaciones_import_lotes` guarda UUID, hash, estado (`PREVIEW`, `CONFIRMADO`, `FALLIDO`), actor, manifest privado y conteos. Evaluacion diaria acepta mina/supervisor FK nulos solo para HISTORICA, y snapshot de fuente/lote.

- [ ] **Step 1: Write failing schema tests.** `test_historical_attendance_has_no_operational_foreign_keys`; `test_historical_evaluation_accepts_null_mine_and_supervisor`; `test_existing_attendance_origin_remains_valid`.
- [ ] **Step 2: Run `php artisan test --filter EvaluacionesHistoricalImportTest`; expect missing tables/columns.**
- [ ] **Step 3: Implement additive migration/models.** Inspeccionar `database/setup` y todas las migraciones de las tres tablas antes de decidir `change()`/FK; no modificar tablas operativas de asistencia; indice de lote/source_id que preserve ocurrencias legitimas.
- [ ] **Step 4: Re-run schema tests; expect PASS.**
- [ ] **Step 5: Commit only task files.** `git commit -m "feat: store historical attendance and import batches separately"`.

### Task 2: Parser y conciliacion sin escritura operativa

**Files:**
- Create: `app/Modules/Evaluaciones/Services/EvaluacionesHistoricalWorkbookReader.php`
- Create: `app/Modules/Evaluaciones/Services/EvaluacionesHistoricalReconciler.php`
- Test: `tests/Feature/EvaluacionesHistoricalImportTest.php`

**Interfaces:** `read(array $privatePaths): array` entrega filas tipadas con fuente/hoja/fila y valores originales; `reconcile(array $rows): array` entrega manifiesto con `proposed`, `unchanged`, `blocked` y motivos por fila, sin escrituras de dominio.

- [ ] **Step 1: Write failing fixture tests.** `test_control_internal_id_resolves_through_personal_sheet_to_unique_dni`; `test_numeric_dni_with_leading_zero_is_not_guessed`; `test_unknown_or_ambiguous_dni_blocks`; `test_duplicate_dni_date_is_not_collapsed`; `test_invalid_five_grades_or_date_blocks`; `test_name_disagreement_is_reported`.
- [ ] **Step 2: Run focused tests; expect missing reader/reconciler.**
- [ ] **Step 3: Implement streaming reader and reconciler.** Usar PhpSpreadsheet en modo lectura y paginas/lotes para volumen; `PersonalNormalizer` para DNI; ID original antes de huella canonica con ocurrencia; Control prevalece sobre Consolidado cuando concilian, diferencias quedan bloqueadas; snapshots DATA solo diagnostico.
- [ ] **Step 4: Re-run focused tests; expect PASS.**
- [ ] **Step 5: Commit only task files.** `git commit -m "feat: preview historical workbooks with DNI reconciliation"`.

### Task 3: Mapear evaluaciones de los tres tipos

**Files:**
- Create: `app/Modules/Evaluaciones/Services/EvaluacionesHistoricalMapper.php`
- Modify: `app/Modules/Evaluaciones/Services/EvaluacionDesempenoService.php` (solo metodo historico, sin relajar `createDaily`)
- Test: `tests/Feature/EvaluacionesHistoricalImportTest.php`

**Interfaces:** `map(array $manifest): array` produce escrituras propuestas por tipo y bloqueantes; diaria usa `HISTORICA`, cinco notas y total calculado; supervisor/residente usan sus plantillas y unicidad existentes.

- [ ] **Step 1: Write failing tests.** `test_daily_historical_without_attendance_can_be_mapped`; `test_supervisor_incompatible_template_blocks`; `test_resident_duplicate_period_blocks`; `test_unknown_evaluator_keeps_source_text_without_fake_user`.
- [ ] **Step 2: Run focused tests; expect missing mapper.**
- [ ] **Step 3: Implement mapper.** Reutilizar `SupervisorEvaluationTemplate`, `ResidentEvaluationTemplate` y calculos existentes; comprobar que las columnas Excel alcanzan para cada tipo; si no, bloquear el lote en lugar de fabricar valores.
- [ ] **Step 4: Re-run focused tests; expect PASS.**
- [ ] **Step 5: Commit only task files.** `git commit -m "feat: validate historical evaluation type mappings"`.

### Task 4: Confirmacion atomica e idempotente

**Files:**
- Create: `app/Modules/Evaluaciones/Services/EvaluacionesHistoricalImportService.php`
- Test: `tests/Feature/EvaluacionesHistoricalImportTest.php`

**Interfaces:** `preview(Usuario $actor, array $files): array` guarda solo lote/manifiesto privado; `confirm(Usuario $actor, string $loteId, string $expectedHash): array` confirma todas las filas o ninguna. Ambos verifican `evaluaciones.administrar` y flag de carga.

- [ ] **Step 1: Write failing tests.** `test_any_blocked_row_prevents_all_domain_writes`; `test_changed_hash_rejects_confirmation`; `test_duplicate_confirmation_and_parallel_attempts_create_one_set`; `test_late_insert_failure_rolls_back_attendance_evaluations_averages_and_lote`; `test_confirmed_batch_disables_further_uploads`.
- [ ] **Step 2: Run focused tests; expect missing service.**
- [ ] **Step 3: Implement service.** Lote con `lockForUpdate`, comparar hash/manifiesto y DB conciliada, insertar por chunks dentro de unidad transaccional definida, recalcular promedios de afectados, escribir reporte final; capturar violacion UNIQUE esperada como conflicto de dominio. Si el volumen impide una sola transaccion confiable, no habilitar confirmacion hasta disenar una estrategia atomica comprobable.
- [ ] **Step 4: Re-run tests; expect PASS.**
- [ ] **Step 5: Commit only task files.** `git commit -m "feat: confirm historical import atomically"`.

### Task 5: UI temporal, reporte y cierre backend

**Files:**
- Create: `app/Modules/Evaluaciones/Controllers/EvaluacionesHistoricalImportPageController.php`
- Modify: `routes/web.php`
- Modify: `resources/views/evaluaciones/index.blade.php`
- Create: `resources/views/evaluaciones/historical-import.blade.php`
- Test: `tests/Feature/EvaluacionesWebTest.php`

**Interfaces:** GET `evaluaciones.historico.importar`, POST `.preview`, POST `.confirmar`; middleware `web.auth` y `web.permission:evaluaciones,administrar`; flag de configuracion y lote confirmado bloquean incluso URL directa.

- [ ] **Step 1: Write failing web tests.** `test_admin_sees_import_action_only_while_open`; `test_non_admin_cannot_preview_or_confirm_by_url`; `test_blocked_preview_shows_searchable_error_report_and_disables_confirm`; `test_success_hides_button_and_returns_counts`; `test_source_file_is_private_and_report_requires_admin`.
- [ ] **Step 2: Run focused tests; expect missing routes/views.**
- [ ] **Step 3: Implement routes, controller, flag and Blade.** Reutilizar patrones de Acciones/import-preview existentes; almacenar archivos privados, validar extension/tamano, mostrar conteos y motivo por fila, descargar reporte autorizado; no exponer rutas de storage ni datos personales a logs.
- [ ] **Step 4: Run tests and `php artisan view:cache`; expect PASS.**
- [ ] **Step 5: Commit only task files.** `git commit -m "feat: add one-time historical import review UI"`.

### Task 6: Lectura e indicadores coherentes

**Files:**
- Modify: `app/Modules/Evaluaciones/Services/EvaluacionesPageService.php`
- Modify: `app/Modules/Evaluaciones/Services/EvaluacionesDashboardService.php`
- Modify: `app/Modules/Evaluaciones/Services/EvaluacionDesempenoService.php`
- Modify: `resources/views/evaluaciones/partials/daily-history.blade.php`
- Test: `tests/Feature/EvaluacionesWebTest.php`
- Test: `tests/Feature/EvaluacionesApiTest.php`

**Interfaces:** Historial y dashboard muestran `HISTORICA` confirmada sin mina como `Sin mina vinculada` solo a lectores autorizados para ver todas; autores de DIRECTA conservan su vista. Filtro de origen aplica al mismo universo que KPIs y resultados.

- [ ] **Step 1: Write failing tests.** `test_imported_without_author_visible_only_to_view_all`; `test_no_mine_history_is_not_counted_in_named_mine_filter`; `test_confirmed_history_updates_average_and_dashboard_once`; `test_preview_rows_do_not_appear_in_metrics`.
- [ ] **Step 2: Run focused tests; expect history/dashboard mismatch.**
- [ ] **Step 3: Implement origin-aware query and common filter semantics.** Evitar exponer historial sin autor a lectores de solo propias; no atribuir mina por texto; no aplicar `canAccessDestino` de MINA a historica sin destino.
- [ ] **Step 4: Run focused tests, PHP lint, view cache and `git diff --check`; expect no errors.**
- [ ] **Step 5: Commit only task files.** `git commit -m "feat: include validated historical evaluations in analytics"`.

### Task 7: Ensayo de despliegue sin importar produccion

**Files:**
- Modify: `docs/evaluaciones.md`
- Test: `tests/Feature/EvaluacionesHistoricalImportTest.php`

**Interfaces:** Procedimiento de backup, migracion, preview, conciliacion de conteos, confirmacion manual, cierre del flag y rollback operativo documentado.

- [ ] **Step 1: Add regression test for a second upload after confirmed batch.** Afirmar 403/409 y cero nuevas filas.
- [ ] **Step 2: Run test; expect PASS from Task 4.**
- [ ] **Step 3: Document preflight, comandos y criterios de conteo; nunca automatizar `confirm` en deploy.**
- [ ] **Step 4: Run focused suite on isolated DB, `php artisan view:cache`, `npm run build` only if compiled assets changed, `git diff --check`; report results and unrun browser checks.**
- [ ] **Step 5: Commit only task files.** `git commit -m "docs: document guarded historical import rollout"`.
