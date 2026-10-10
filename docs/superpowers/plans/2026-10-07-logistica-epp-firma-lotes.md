# Logistica EPP: Firma Movil, Lotes e Inventario Implementation Plan

> Precision aprobada 2026-10-09: Agregar otro EPP acumula articulos del mismo trabajador ANTES de firmar. El resumen debe mostrar todos los articulos y caracteristicas; una sola firma confirma todo el grupo. No se firma cada articulo por separado. Ver [correccion de firma unica](../reports/2026-10-09-epp-firma-unica-varios-articulos.md).

> Correccion de alcance 2026-10-09, confirmada por el usuario: conservar Entregas y cambios e Identificacion de items y agregar firma al formulario habitual, sin requisito de almacen ni botones globales. El inventario por lotes queda separado y no es requisito operativo. Ver `docs/EPP_FIRMA_OPERATIVA.md` y `docs/superpowers/reports/2026-10-09-epp-correccion-flujo-existente.md`. Las notas anteriores de exigir almacen/conteo para toda entrega quedan sustituidas por esta decision; Task 11 no queda aprobado para produccion.

> Actualizacion 2026-10-09: el usuario autorizo integrar el flujo en la aplicacion local. Codigo integrado, dependencias instaladas y cinco migraciones EPP ejecutadas con respaldo; 3 entregas conservadas como legado sin firma. Falta configurar almacen/conteo real. Ver [integracion local](../reports/2026-10-09-epp-integracion-local.md). Esto no sustituye el ensayo aislado ni la aprobacion de produccion de Task 11.

> Estado al 2026-10-08: Tasks 1-10 implementadas en `codex/epp-signed-operations-finish`; revision final corregida. Task 11 parcial: controles focalizados, navegador y build ejecutados; suite global/estilo global no estan en verde y falta ensayo sobre copia realista aislada. No aprobado para produccion. Ver [registro de verificacion](2026-10-07-logistica-epp-firma-lotes-verification.md). Los checks de Tasks 1-10 registran pasos ejecutados; las excepciones de verificacion transversal se detallan en ese registro.

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implementar entregas y devoluciones multiples de EPP con inventario real, firma movil sobre lo efectivamente procesado, saldos retornables y Kardex verificable.

**Architecture:** Introducir una cabecera inmutable de operacion con detalles solicitados/procesados/pendientes, un libro de inventario por almacen y variante, y una aceptacion privada firmada cuyo hash cubre el resumen mostrado. Mantener `epp_entregas` como saldo de responsabilidad durante la migracion y separar servicios de catalogo, inventario, operaciones, firma, saldos y reportes.

**Tech Stack:** PHP 8.2+, Laravel 12, Eloquent/MySQL, Blade, JavaScript nativo con Pointer Events, CSS propio/Tailwind 4, PhpSpreadsheet 5, Dompdf 3, `endroid/qr-code:^5.1`, PHPUnit 11 y Playwright cuando este disponible.

**Spec:** `docs/superpowers/specs/2026-10-07-logistica-epp-firma-lotes-design.md`

## Global Constraints

- Conservar UUID de texto y compatibilidad con esquemas historicos; las migraciones son aditivas e idempotentes.
- Probar solo contra `proserge_app_test` con `DatabaseTransactions`, nunca contra datos reales.
- La firma cubre cantidades procesadas y JSON canonico; todo cambio exige firmar nuevamente.
- Firmados y legado son inmutables; correccion/anulacion siempre es compensatoria.
- Consumibles conservan historial, pero no saldo retornable ni pendiente de cese.
- Firma, PDF y evidencia viven en disco privado `local`.
- Toda lectura/escritura valida permiso directo y alcance de almacen/mina.
- No hay confirmacion offline en v1; un corte deja borrador o recupera por `submission_uuid`.
- `epp_registro.stock` no es apertura confiable; mostrar `STOCK_NO_CONCILIADO` hasta contar/importar.
- Usar `endroid/qr-code:^5.1`; su [metadata oficial 5.1.0](https://github.com/endroid/qr-code/blob/5.1.0/composer.json) exige PHP `^8.1`, compatible con PHP 8.2, y generar SVG sin depender de GD.
- No depender solo del color y mantener lenguaje operativo en espanol.
- Antes de cada commit revisar `git status` y agregar solo los archivos exactos de la tarea; nunca incluir cambios ajenos del arbol de trabajo.

## Review Focus

- Dos operadores reservan el ultimo stock: solo uno confirma y nunca queda stock negativo; Task 4.
- Reintento tras perder la respuesta HTTP: el mismo UUID no duplica entrega ni inventario; Task 5.
- Cambio despues de dibujar: cliente borra firma y servidor rechaza revision obsoleta; Tasks 5-6.
- Dos devoluciones compiten por el mismo saldo: no se devuelve mas de lo entregado; Task 7.
- Firma/PDF ausente o alterado: mostrar `EVIDENCIA_INVALIDA`; Task 9.

---

### Task 1: Persistencia y modelos del dominio firmado

**Files:**
- Create: `database/migrations/2026_10_07_000100_add_control_policy_to_epp_registro.php`
- Create: `database/migrations/2026_10_07_000200_create_epp_inventory_tables.php`
- Create: `database/migrations/2026_10_07_000300_create_epp_operation_tables.php`
- Create: `database/migrations/2026_10_07_000400_add_signed_operation_fields_to_epp_entregas.php`
- Create: `app/Models/EppAlmacen.php`, `EppExistencia.php`, `EppReserva.php`, `EppInventarioMovimiento.php`
- Create: `app/Models/EppOperacion.php`, `EppOperacionDetalle.php`, `EppAceptacion.php`, `EppOperacionEvento.php`
- Modify: `app/Models/EppRegistro.php`, `app/Models/EppEntrega.php`
- Test: `tests/Feature/EppSignedSchemaTest.php`

**Interfaces:**
- Consumes: tablas actuales EPP, personal, usuarios y minas.
- Produces: modelos, constantes, casts y relaciones que consumen Tasks 2-10.

- [x] **Step 1: Write the failing schema test**

```php
public function test_schema_supports_partial_quantities_and_private_evidence(): void
{
    $this->assertTrue(Schema::hasColumns('epp_operacion_detalles', [
        'cantidad_solicitada', 'cantidad_procesada', 'cantidad_pendiente',
        'politica_devolucion_snapshot', 'snapshot_json',
    ]));
    $this->assertTrue(Schema::hasColumns('epp_aceptaciones', [
        'firma_path', 'firma_sha256', 'contenido_json', 'contenido_sha256', 'codigo_verificacion',
    ]));
}
```

- [x] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter EppSignedSchemaTest`

Expected: FAIL because tables/columns are absent.

- [x] **Step 3: Implement the additive schema**

Create warehouse, existence, reservation, immutable inventory ledger, operation, detail, acceptance and event tables. Index state/date/worker/warehouse/EPP; unique existence by warehouse-item-variant, operation by `submission_uuid`, acceptance by operation and verification code. Add catalog policy/control/repetition/series flags plus `es_requerido_cobertura`. Add origin operation/detail, original quantity, return balance, policy snapshot, legacy and integrity fields to `epp_entregas`. Acceptance stores `tipo_firmante` and permits `TRABAJADOR` or `TESTIGO_AUTORIZADO`; both require a real signature image.

- [x] **Step 4: Add typed models and relations**

Use string UUIDs. Define operation types `ENTREGA`, `DEVOLUCION`, `CORRECCION`, `ANULACION`; all spec states; inventory movement types; and `EppOperacion::detalles()/aceptacion()/eventos()/entregas()` plus delivery origin relations.

- [x] **Step 5: Verify**

Run: `php artisan test --filter EppSignedSchemaTest` and `php -l` on every touched PHP file.

Expected: PASS/no syntax errors.

- [x] **Step 6: Commit**

```bash
git add database/migrations/2026_10_07_* app/Models/Epp*.php tests/Feature/EppSignedSchemaTest.php
git commit -m "feat(epp): add signed operation data model"
```

### Task 2: Permisos directos y alcance por almacen/mina

**Files:**
- Create: `app/Modules/Epps/Services/EppScopeService.php`
- Create: `app/Modules/Epps/Policies/EppOperationPolicy.php`
- Modify: `app/Support/Rbac/PermissionCatalog.php`, `app/Support/Rbac/PermissionMatrix.php`
- Test: `tests/Feature/EppAuthorizationTest.php`, `tests/Feature/WebPermissionMiddlewareTest.php`

**Interfaces:**
- Consumes: `MinaScopeAccess::allows(Usuario, ?string): bool`.
- Produces: `assertWarehouseAccess(Usuario, EppAlmacen): void`, `assertPersonalAccess(Usuario, Personal): void`, `visibleWarehouseIds(Usuario): array` and policy gates.

- [x] **Step 1: Write failing authorization tests**

```php
public function test_tab_visibility_does_not_grant_epp_writes(): void
{
    $this->withSession($this->sessionWith(['logistica' => ['ver', 'ver_logistica_entregas']]))
        ->post('/epps/operaciones', [])->assertForbidden();
}

public function test_user_cannot_use_warehouse_outside_scope(): void
{
    $this->expectException(AuthorizationException::class);
    app(EppScopeService::class)->assertWarehouseAccess($this->user, $this->otherMineWarehouse);
}
```

- [x] **Step 2: Run and verify failures**

Run: `php artisan test --filter EppAuthorizationTest`

Expected: FAIL.

- [x] **Step 3: Add exact capabilities**

Add `ver_operaciones`, `preparar_entrega`, `confirmar_entrega`, `preparar_devolucion`, `confirmar_devolucion`, `ver_evidencia`, `descargar_constancia`, `gestionar_inventario`, `ajustar_inventario`, `corregir`, `anular`, `aprobar_excepcion_firma`. Remove the derived rule that turns tab visibility into all EPP writes; preserve explicit legacy grants through a documented normalization only.

- [x] **Step 4: Implement scope/policy**

ADMIN/GERENTE/SUPERADMIN activos see all. Others require warehouse mine in `usuario_mina_scope` and at least one active worker-mine relation in scope. Mine-less warehouses are privileged-only.

- [x] **Step 5: Verify and commit**

Run: `php artisan test --filter 'EppAuthorizationTest|WebPermissionMiddlewareTest'`

Expected: PASS.

```bash
git add app/Modules/Epps/Services/EppScopeService.php app/Modules/Epps/Policies/EppOperationPolicy.php app/Support/Rbac tests/Feature/EppAuthorizationTest.php tests/Feature/WebPermissionMiddlewareTest.php
git commit -m "feat(epp): enforce operation permissions and mine scope"
```

### Task 3: Politicas de catalogo y apertura de inventario

**Files:**
- Create: `app/Modules/Epps/Requests/SaveEppCatalogRequest.php`, `OpenEppStockRequest.php`, `AdjustEppStockRequest.php`
- Create: `app/Modules/Epps/Services/EppCatalogService.php`, `EppInventoryService.php`
- Create: `app/Modules/Epps/Controllers/EppInventoryPageController.php`
- Create: `resources/views/epps/inventory/index.blade.php`
- Modify: `app/Modules/Epps/Controllers/EppPageController.php`, `app/Modules/Epps/Services/EppService.php`
- Modify: `resources/views/logistica/index.blade.php`, `routes/web.php`
- Test: `tests/Feature/EppInventoryServiceTest.php`, `tests/Feature/EppCatalogTest.php`

**Interfaces:**
- Produces: `EppCatalogService::save(EppRegistro $epp, array $payload): EppRegistro`; `EppInventoryService::variantKey(array $attributes): string`; `available(EppAlmacen $warehouse, EppRegistro $epp, array $attributes): ?int`; `openStock(EppAlmacen $warehouse, array $lines, Usuario $actor, string $reason): Collection`; `adjust(EppAlmacen $warehouse, EppRegistro $epp, array $attributes, int $delta, Usuario $actor, string $reason): EppInventarioMovimiento`.

- [x] **Step 1: Write failing policy/inventory tests**

```php
public function test_consumable_has_no_return_obligation(): void
{
    $epp = $catalog->save(new EppRegistro(), [
        'nombre' => 'MASCARILLA N95', 'politica_devolucion' => 'CONSUMIBLE',
        'control_inventario' => true, 'permite_entrega_repetida' => true,
    ]);
    $this->assertSame('CONSUMIBLE', $epp->politica_devolucion);
}

public function test_opening_creates_ledger_and_reconciles_variant(): void
{
    $moves = $inventory->openStock($warehouse, [['epp_id' => $epp->id, 'cantidad' => 8, 'talla' => 'M']], $user, 'Conteo inicial');
    $this->assertSame(8, $inventory->available($warehouse, $epp, ['talla' => 'M']));
    $this->assertSame('APERTURA', $moves->first()->tipo);
}
```

- [x] **Step 2: Run and verify failures**

Run: `php artisan test --filter 'EppInventoryServiceTest|EppCatalogTest'`

Expected: FAIL.

- [x] **Step 3: Implement catalog and inventory services**

Default unspecified/existing items to `RETORNABLE`; require explicit review in UI. Canonicalize variant keys with sorted uppercase attributes. Every quantity change locks existence, appends ledger with before/after and actor, then updates existence. `available()` returns null until `conciliado_at`.

- [x] **Step 4: Add warehouse/opening/adjustment UI**

Use preview and explicit confirmation, reason required, result counts and direct permissions. Never copy old `stock` as opening.

- [x] **Step 5: Verify and commit**

Run: `php artisan test --filter 'EppInventoryServiceTest|EppCatalogTest|EppAuthorizationTest'`

Run: `php artisan view:cache`

Expected: PASS.

```bash
git add app/Modules/Epps resources/views/epps/inventory resources/views/logistica/index.blade.php routes/web.php tests/Feature/EppInventoryServiceTest.php tests/Feature/EppCatalogTest.php
git commit -m "feat(epp): manage return policy and warehouse inventory"
```

### Task 4: Borradores, parciales y reservas concurrentes

**Files:**
- Create: `app/Modules/Epps/Requests/StoreEppOperationRequest.php`, `UpdateEppOperationRequest.php`
- Create: `app/Modules/Epps/Services/EppOperationService.php`
- Modify: `app/Modules/Epps/Services/EppInventoryService.php`, `routes/console.php`
- Test: `tests/Feature/EppOperationServiceTest.php`

**Interfaces:**
- Produces: `createDraft(Usuario, Personal, EppAlmacen, string $type, string $submissionUuid): EppOperacion`; `syncDetails(EppOperacion, array, Usuario): EppOperacion`; `prepareForSignature(EppOperacion, Usuario): EppOperacion`; `reject(EppOperacion, Usuario, string): EppOperacion`; `expireReservations(CarbonInterface): int`; `reviewPayload(EppOperacion): array`.

- [x] **Step 1: Write failing partial/concurrency tests**

```php
public function test_ten_requested_with_five_available_prepares_only_five(): void
{
    $operation = $service->syncDetails($draft, [[
        'epp_id' => $epp->id, 'cantidad_solicitada' => 10, 'aceptar_parcial' => true,
    ]], $user);
    $detail = $service->prepareForSignature($operation, $user)->detalles->first();
    $this->assertSame(5, $detail->cantidad_procesada);
    $this->assertSame(5, $detail->cantidad_pendiente);
}
```

Add `test_zero_stock_never_reaches_signature`, `test_rejecting_all_lines_releases_reservations`, `test_substitution_preserves_requested_and_actual_snapshots`, `test_active_returnable_requires_reason`, `test_consumable_can_repeat`, `test_uncontrolled_item_skips_reservation`, `test_expired_reservation_releases_once` and `test_only_one_operator_reserves_last_unit`, asserting the exact state, quantities and ledger row count in each case.

- [x] **Step 2: Run and verify failures**

Run: `php artisan test --filter EppOperationServiceTest`

Expected: FAIL.

- [x] **Step 3: Implement editable drafts and locked preparation**

Only `BORRADOR` is editable. Recompute processed/pending from locked server stock, store requested vs actual substitution, reject cross-worker/warehouse references, reserve for ten minutes and emit events. A product with `control_inventario=false` still appears in the signed batch but does not reserve or mutate existence. Rejecting all lines sets `RECHAZADO` and releases reservations. Release expired reservations exactly once via lazy read plus scheduled cleanup.

- [x] **Step 4: Verify and commit**

Run: `php artisan test --filter EppOperationServiceTest`

Expected: PASS; exactly one concurrent winner and no negative stock.

```bash
git add app/Modules/Epps/Requests app/Modules/Epps/Services/EppOperationService.php app/Modules/Epps/Services/EppInventoryService.php routes/console.php tests/Feature/EppOperationServiceTest.php
git commit -m "feat(epp): prepare partial batches with stock reservations"
```

### Task 5: Firma privada, contenido canonico e idempotencia

**Files:**
- Create: `app/Modules/Epps/Requests/SignEppOperationRequest.php`
- Create: `app/Modules/Epps/Support/EppSignaturePayload.php`
- Create: `app/Modules/Epps/Services/EppAcceptanceService.php`, `EppOperationConfirmationService.php`
- Modify: `app/Modules/Epps/Services/EppInventoryService.php`, `app/Models/EppEntrega.php`
- Test: `tests/Feature/EppAcceptanceServiceTest.php`

**Interfaces:**
- Produces: `canonicalPayload(EppOperacion): array`; `capture(EppOperacion, EppSignaturePayload, Usuario): EppAceptacion`; `verify(EppAceptacion): array{valid:bool,reasons:array}`; `EppOperationConfirmationService::confirm(EppOperacion, EppSignaturePayload, Usuario): EppOperacion`; `confirmWithWitness(EppOperacion, EppSignaturePayload, Usuario $approver): EppOperacion`.

- [x] **Step 1: Write failing signature/idempotency tests**

```php
public function test_signature_covers_only_processed_quantity(): void
{
    $confirmed = $service->confirm($partial, $this->validSignature(), $user);
    $this->assertSame(5, data_get($confirmed->aceptacion->contenido_json, 'details.0.processed_quantity'));
    $this->assertSame(5, $confirmed->entregas()->first()->cantidad);
}

public function test_retry_does_not_duplicate_effects(): void
{
    $service->confirm($operation, $signature, $user);
    $service->confirm($operation->fresh(), $signature, $user);
    $this->assertDatabaseCount('epp_aceptaciones', 1);
}
```

Add tests named `test_stale_review_hash_is_rejected`, `test_invalid_or_oversized_png_is_rejected`, `test_worker_mismatch_is_rejected`, `test_expired_reservation_cannot_confirm`, `test_failed_transaction_removes_signature_file`, `test_altered_file_hash_is_invalid`, `test_acceptance_stores_server_and_utc_times`, `test_signer_snapshot_is_immutable` and `test_witness_requires_exception_permission`, asserting no inventory/delivery mutation on every rejection.

- [x] **Step 2: Run and verify failures**

Run: `php artisan test --filter EppAcceptanceServiceTest`

Expected: FAIL.

- [x] **Step 3: Implement canonical payload and private signature**

Use fixed key order and server values. Validate PNG Data URL, max 1 MiB, dimensions 300-2400 px and non-empty ink metadata; save under `epp/firmas/{operation_id}/`; persist SHA-256, server time in application timezone and UTC, session/browser summary and hashed IP, never Base64. `TRABAJADOR` is normal; `TESTIGO_AUTORIZADO` requires witness identity, reason and a separate approver with the direct exception permission. A refusal is never converted into a witness exception.

- [x] **Step 4: Implement atomic confirmation**

Validate and write the final private signature file before the database transaction, then lock operation/details/reservations/existences and verify review hash. Convert reservations to outbound ledger; create return balance only for `RETORNABLE`, `CONSUMIDO` history with zero balance otherwise; save acceptance/event; set `FIRMADO` or `FIRMADO_PARCIAL`. Delete the file on rollback; if post-commit integrity later fails, report `EVIDENCIA_INVALIDA` rather than hiding it.

- [x] **Step 5: Verify and commit**

Run: `php artisan test --filter 'EppAcceptanceServiceTest|EppOperationServiceTest'`

Expected: PASS; retries leave one effect.

```bash
git add app/Modules/Epps/Requests/SignEppOperationRequest.php app/Modules/Epps/Support app/Modules/Epps/Services/EppAcceptanceService.php app/Modules/Epps/Services/EppOperationConfirmationService.php app/Modules/Epps/Services/EppInventoryService.php app/Models/EppEntrega.php tests/Feature/EppAcceptanceServiceTest.php
git commit -m "feat(epp): confirm batches with private signed evidence"
```

### Task 6: Pantalla movil de entrega multiple

**Files:**
- Create: `app/Modules/Epps/Controllers/EppOperationPageController.php`
- Create: `resources/views/epps/operations/form.blade.php`, `review.blade.php`, `show.blade.php`
- Create: `resources/js/modules/signature-pad.js`, `resources/js/modules/epp-operation.js`
- Create: `resources/css/modules/epp-operations.css`
- Modify: `resources/js/app.js`, `resources/css/app.css`
- Modify: `resources/views/logistica/index.blade.php`, `resources/views/epps/partials/workspace.blade.php`
- Modify: `resources/views/ficha-public/show.blade.php`, `resources/views/personal/fichas/partials/ingreso-form-fields.blade.php`
- Modify: `routes/web.php`
- Create: `tests/JavaScript/epp-signature-pad.test.mjs`, `tests/Browser/epp-signed-delivery.cjs`
- Modify: `tests/Feature/LogisticaEppIntegrationTest.php`

**Interfaces:**
- Consumes: Tasks 4-5.
- Produces: routes `epps.operations.create/store/edit/update/review/sign/reject/show`; JS `SignaturePadController.clear()`, `hasInk()`, `toPayload()` and event `signature:changed`.

- [x] **Step 1: Write failing HTTP/JS tests**

```php
public function test_mobile_review_shows_processed_and_pending_before_signature(): void
{
    $this->withSession($session)->get(route('epps.operations.review', $operation))
        ->assertOk()->assertSee('Recibiras ahora: 5')
        ->assertSee('Quedara pendiente: 5')
        ->assertSee('Confirmo haber recibido unicamente los articulos mostrados');
}
```

JS tests cover pointer draw, clear, resize/orientation preservation, empty rejection and automatic clear after line changes. Feature tests cover full disagreement, worker refusal without delivery, and the witness screen hidden unless the operator has `aprobar_excepcion_firma`.

- [x] **Step 2: Run and verify failures**

Run: `php artisan test --filter LogisticaEppIntegrationTest`

Run: `node --test tests/JavaScript/epp-signature-pad.test.mjs`

Expected: FAIL.

- [x] **Step 3: Implement thin controller/routes and mobile views**

Authorize every action. Never trust browser quantities on sign. Use item cards, large quantity controls, textual availability, separate processed/pending/substitution blocks, worker review mode without administrative controls, disagreement action and full-width signature. The witness exception identifies witness/reason/approver and explicitly states that the worker did not sign; it is unavailable for simple refusal.

- [x] **Step 4: Extract shared signature module**

Bind with `data-signature-pad`; use Pointer Events, `devicePixelRatio`, `touch-action:none`, ink bounds/point count and resize preservation. Adapt both Personal signature surfaces without changing request field names.

- [x] **Step 5: Verify frontend**

Run: `php artisan test --filter 'LogisticaEppIntegrationTest|PublicQuickGuideViewTest|EppAcceptanceServiceTest'`

Run: `node --test tests/JavaScript/epp-signature-pad.test.mjs tests/JavaScript/epp-mini-kardex.test.mjs`

Run: `php artisan view:cache` and `npm run build`.

Expected: PASS/build.

- [x] **Step 6: Verify browser when available**

Run: `node tests/Browser/epp-signed-delivery.cjs`

Expected: PASS at 320, 375, 390, 430 and desktop widths, no page overflow or console errors. Record exact limitation if Playwright is unavailable.

- [x] **Step 7: Commit**

```bash
git add app/Modules/Epps/Controllers/EppOperationPageController.php resources/views/epps resources/views/logistica/index.blade.php resources/views/ficha-public/show.blade.php resources/views/personal/fichas/partials/ingreso-form-fields.blade.php resources/js resources/css routes/web.php tests/Feature/LogisticaEppIntegrationTest.php tests/JavaScript/epp-signature-pad.test.mjs tests/Browser/epp-signed-delivery.cjs public/build
git commit -m "feat(epp): add mobile multi-item signed delivery"
```

### Task 7: Saldos y devolucion multiple firmada

**Files:**
- Create: `app/Modules/Epps/Services/EppBalanceService.php`
- Create: `app/Modules/Epps/Requests/StoreEppReturnRequest.php`
- Create: `resources/views/epps/operations/return-form.blade.php`
- Modify: `app/Modules/Epps/Services/EppOperationService.php`, `EppOperationConfirmationService.php`, `EppInventoryService.php`
- Modify: `app/Modules/Epps/Controllers/EppOperationPageController.php`
- Modify: `resources/js/modules/epp-operation.js`, `resources/css/modules/epp-operations.css`, `routes/web.php`
- Create: `tests/Feature/EppReturnServiceTest.php`
- Modify: `tests/Browser/epp-signed-delivery.cjs`

**Interfaces:**
- Produces: `forWorker(Personal, Usuario): Collection`; `applyReturn(EppOperacionDetalle, Usuario): EppEntrega`; `markLoss(EppEntrega, int, string, Usuario): EppOperacion`; routes `epps.operations.returns.create/store`.

- [x] **Step 1: Write failing return tests**

```php
public function test_returning_two_of_five_leaves_three(): void
{
    $delivery = $service->applyReturn($this->signedReturnDetail(quantity: 2), $user);
    $this->assertSame(3, $delivery->cantidad_pendiente_devolucion);
    $this->assertSame('ENTREGADO', $delivery->estado);
}

public function test_consumables_never_appear_as_returnable(): void
{
    $this->assertFalse($service->forWorker($worker, $user)->contains('id', $consumable->id));
}
```

Add `test_full_return_closes_balance`, `test_return_above_balance_is_rejected`, `test_concurrent_returns_cannot_exceed_balance`, `test_good_return_reenters_available_stock`, `test_damaged_return_enters_quarantine`, `test_loss_does_not_add_stock` and `test_ceased_worker_shows_only_positive_returnable_balances`, asserting balance and ledger totals.

- [x] **Step 2: Run and verify failures**

Run: `php artisan test --filter EppReturnServiceTest`

Expected: FAIL.

- [x] **Step 3: Implement locked balances and dispositions**

Lock source delivery. Full return closes; partial remains active. `BUENO` returns to available, `DESGASTADO`/`DANADO`/`INCOMPLETO` goes to quarantine/baja according to detail. Loss never adds physical stock.

- [x] **Step 4: Implement return UI/signature**

Show only positive returnable balances, allow multiple quantities, preview remaining balance and use declaration `RETURN_V1`: "Confirmo haber devuelto".

- [x] **Step 5: Verify and commit**

Run: `php artisan test --filter 'EppReturnServiceTest|LogisticaDashboardServiceTest|LogisticaEppIntegrationTest'`

Run: `node tests/Browser/epp-signed-delivery.cjs`

Expected: PASS; competing returns cannot create negative balance.

```bash
git add app/Modules/Epps resources/views/epps/operations resources/js/modules/epp-operation.js resources/css/modules/epp-operations.css routes/web.php tests/Feature/EppReturnServiceTest.php tests/Browser/epp-signed-delivery.cjs
git commit -m "feat(epp): add signed partial and bulk returns"
```

### Task 8: Legado, correcciones, anulaciones y no borrado

**Files:**
- Create: `app/Console/Commands/EppLegacyBackfillCommand.php`
- Create: `app/Modules/Epps/Requests/CorrectEppOperationRequest.php`, `AnnulEppOperationRequest.php`
- Create: `app/Modules/Epps/Services/EppCorrectionService.php`
- Modify: `app/Modules/Epps/Services/EppService.php`
- Modify: `app/Modules/Epps/Controllers/EppPageController.php`, `EppOperationPageController.php`
- Modify: `resources/views/epps/partials/workspace.blade.php`, `routes/web.php`
- Create: `tests/Feature/EppCorrectionTest.php`, `tests/Feature/EppLegacyBackfillTest.php`

**Interfaces:**
- Produces: `correct(EppOperacion, array, Usuario, string): EppOperacion`; `annul(EppOperacion, Usuario, string): EppOperacion`; command `epp:backfill-legacy-traceability {--dry-run}`.

- [x] **Step 1: Write failing immutability/backfill tests**

```php
public function test_signed_delivery_cannot_be_updated_or_deleted(): void
{
    $this->withSession($session)->put(route('epps.entregas.update', $signed), [])->assertStatus(409);
    $this->withSession($session)->post(route('epps.entregas.destroy', $signed))->assertStatus(409);
}

public function test_backfill_marks_legacy_without_inventing_signature(): void
{
    $this->artisan('epp:backfill-legacy-traceability')->assertSuccessful();
    $this->assertTrue($legacy->fresh()->es_legacy_sin_firma);
    $this->assertDatabaseCount('epp_aceptaciones', 0);
}
```

- [x] **Step 2: Run and verify failures**

Run: `php artisan test --filter 'EppCorrectionTest|EppLegacyBackfillTest'`

Expected: FAIL.

- [x] **Step 3: Implement idempotent dry-run backfill**

Report scanned/updated/skipped/errors. Mark current records legacy; positive return balance only for `ENTREGADO`; `RETORNABLE` snapshot; no acceptance, opening or synthetic operation.

- [x] **Step 4: Implement compensating corrections/anulments**

Require direct permission and reason. Keep original untouched, reverse inventory/saldo with new ledger entries and link operations/events.

- [x] **Step 5: Retire physical edit/delete UI**

Only drafts can be discarded. Signed/legacy show correction/anulment. Old mutation endpoints return controlled 409 for protected records.

- [x] **Step 6: Verify and commit**

Run: `php artisan test --filter 'EppCorrectionTest|EppLegacyBackfillTest|EppCatalogTest|LogisticaEppIntegrationTest'`

Expected: PASS.

```bash
git add app/Console/Commands/EppLegacyBackfillCommand.php app/Modules/Epps resources/views/epps/partials/workspace.blade.php routes/web.php tests/Feature/EppCorrectionTest.php tests/Feature/EppLegacyBackfillTest.php
git commit -m "feat(epp): preserve signed history with corrections"
```

### Task 9: Kardex verificable, PDF, Excel y QR interno

**Files:**
- Modify: `composer.json`, `composer.lock`
- Create: `app/Modules/Epps/Services/EppKardexService.php`, `EppReceiptPdfService.php`, `EppQrCodeService.php`
- Create: `app/Modules/Epps/Controllers/EppEvidenceController.php`
- Create: `resources/views/epps/pdf/operation-receipt.blade.php`, `resources/views/epps/operations/verify.blade.php`
- Modify: `app/Modules/Epps/Services/EppService.php`, `resources/views/logistica/index.blade.php`, `routes/web.php`
- Create: `tests/Feature/EppKardexEvidenceTest.php`
- Modify: `tests/Feature/EppCatalogTest.php`

**Interfaces:**
- Produces: `EppKardexService::forWorker(Personal $worker, Usuario $viewer): array`; `downloadExcel(Personal $worker, Usuario $viewer): StreamedResponse`; `EppReceiptPdfService::output(EppOperacion $operation): string`; `download(EppOperacion $operation, Usuario $viewer): Response`; `EppQrCodeService::svg(string $verificationUrl): string`; authenticated evidence routes.

- [x] **Step 1: Write failing evidence/report tests**

```php
public function test_kardex_distinguishes_signed_and_legacy(): void
{
    $data = $service->forWorker($worker, $viewer);
    $this->assertSame(['FIRMADO_VALIDO', 'LEGACY_SIN_FIRMA'], array_column($data['operations'], 'evidence_state'));
}

public function test_tampered_signature_is_invalid(): void
{
    Storage::disk('local')->put($acceptance->firma_path, 'altered');
    $this->assertSame('EVIDENCIA_INVALIDA', $service->forWorker($worker, $viewer)['operations'][0]['evidence_state']);
}
```

Add `test_pdf_contains_canonical_details_signature_and_code`, `test_excel_contains_verification_columns`, `test_evidence_download_enforces_scope`, `test_corrections_link_to_original` and `test_qr_contains_only_internal_verification_url`, asserting exact labels/IDs and response status.

- [x] **Step 2: Verify/install QR dependency**

Run: `composer require endroid/qr-code:^5.1 --dry-run`

Expected: resolves on PHP 8.2 without replacing Laravel 12 or PhpSpreadsheet 5. Stop and report if incompatible; never force.

Run: `composer require endroid/qr-code:^5.1`

Expected: lock files change and SVG writer works without GD.

- [x] **Step 3: Run report test and verify failure**

Run: `php artisan test --filter EppKardexEvidenceTest`

Expected: FAIL.

- [x] **Step 4: Implement mixed Kardex and integrity verification**

Group signed rows by operation and legacy individually. Recompute hashes on view/download. Return only `FIRMADO_VALIDO`, `EVIDENCIA_INVALIDA`, `LEGACY_SIN_FIRMA`. Audit evidence access without logging sensitive payloads.

- [x] **Step 5: Implement PDF/Excel/QR**

PDF contains canonical details, processed/pending, declaration, signature, code, abbreviated hashes, corrections and QR. Excel contains operation ID, evidence state, signed time and code, stating that server verification is authoritative.

- [x] **Step 6: Verify and commit**

Run: `php artisan test --filter 'EppKardexEvidenceTest|EppCatalogTest|LogisticaEppIntegrationTest|EppAuthorizationTest'`

Expected: PASS including missing/tampered files.

```bash
git add composer.json composer.lock app/Modules/Epps resources/views/epps/pdf resources/views/epps/operations/verify.blade.php resources/views/logistica/index.blade.php routes/web.php tests/Feature/EppKardexEvidenceTest.php tests/Feature/EppCatalogTest.php
git commit -m "feat(epp): generate verifiable kardex and receipts"
```

### Task 10: Dashboard, retiro del flujo individual y documentacion

**Files:**
- Modify: `app/Modules/Logistica/Services/LogisticaDashboardService.php`
- Modify: `app/Modules/Epps/Services/EppService.php`, `app/Modules/Epps/Controllers/EppPageController.php`
- Modify: `resources/views/epps/partials/workspace.blade.php`, `resources/views/logistica/partials/dashboard.blade.php`, `resources/views/logistica/index.blade.php`
- Modify: `docs/ARCHITECTURE_MAP.md`, `docs/MAPA_ARCHIVOS.md`, `docs/CODEX_CONTEXT.md`
- Modify: `docs/processes/logistica-operativa/index.md`, `entrega-devolucion-epp.bpmn`, `cerrar-reemplazar-epp.bpmn`
- Create: `docs/EPP_FIRMA_OPERATIVA.md`
- Modify: `tests/Feature/LogisticaDashboardServiceTest.php`, `tests/Feature/LogisticaEppIntegrationTest.php`

**Interfaces:**
- Produces: una entrada productiva por lotes, dashboard desde stock/saldos y documentacion canonica.

- [x] **Step 1: Write failing dashboard/retirement tests**

```php
public function test_consumables_are_not_ceased_return_pending(): void
{
    $data = app(LogisticaDashboardService::class)->pageData([]);
    $this->assertSame(0, data_get($data, 'ceasedSummary.pendientes'));
}

public function test_individual_button_is_replaced_by_batch_flow(): void
{
    $this->withSession($session)->get(route('logistica.index', ['tab' => 'entregas']))
        ->assertSee('Nueva entrega multiple')->assertDontSee('Registrar y agregar otra');
}
```

Add `test_unconciliated_stock_is_not_reported_as_zero`, `test_dashboard_sums_partial_pending_quantities`, `test_dashboard_uses_positive_return_balances`, `test_dashboard_separates_signed_and_legacy_counts` and `test_coverage_uses_catalog_requirement_flag`, asserting exact metric values.

- [x] **Step 2: Run and verify failures**

Run: `php artisan test --filter 'LogisticaDashboardServiceTest|LogisticaEppIntegrationTest'`

Expected: FAIL.

- [x] **Step 3: Switch dashboard/read paths**

Use reconciled existence for stock and active balances for responsibility; exclude consumables from returns/cese; preserve pending quantities; show `STOCK_NO_CONCILIADO`. Introduce/migrate an explicit catalog requirement flag before removing the fixed list.

- [x] **Step 4: Retire individual production entry**

Remove normal navigation/buttons, retain read compatibility and safe GET redirects, and reject legacy writes after rollout unless explicitly retained for rollback.

- [x] **Step 5: Synchronize docs/BPMN**

Document roles, partial/no-stock/refusal/witness, returns, corrections, verification, opening, backup and deployment; reference exact implemented routes/services.

- [x] **Step 6: Verify and commit**

Run: `php artisan test --filter 'Epp|Logistica'`

Run: `vendor/bin/pint --test app/Modules/Epps app/Models database/migrations tests/Feature`

Run: `php artisan view:cache`, `npm run build`, `php artisan route:list --path=epps`, and `git diff --check`.

Expected: PASS; every write route has auth and direct permission.

```bash
git add app/Modules/Logistica app/Modules/Epps resources/views docs tests/Feature/LogisticaDashboardServiceTest.php tests/Feature/LogisticaEppIntegrationTest.php public/build
git commit -m "feat(logistica): complete signed EPP workflow"
```

### Task 11: Verificacion transversal y evidencia de despliegue

**Files:**
- Create: `docs/superpowers/plans/2026-10-07-logistica-epp-firma-lotes-verification.md`
- Modify only when a verification failure proves a defect: files owned by Tasks 1-10.

**Interfaces:**
- Consumes: completed implementation.
- Produces: reproducible verification record and explicit unavailable checks.

- [ ] **Step 1: Run focused suites on isolated MySQL**

Run: `php artisan test --filter 'Epp|Logistica|WebPermissionMiddlewareTest|PublicQuickGuideViewTest'`

Expected: PASS with zero failures/errors.

- [ ] **Step 2: Run static/build checks**

```bash
vendor/bin/pint --test
php artisan view:cache
npm run build
node --test tests/JavaScript/epp-signature-pad.test.mjs tests/JavaScript/epp-mini-kardex.test.mjs
git diff --check
```

Expected: every command exits 0.

- [x] **Step 3: Run browser/mobile verification**

Run: `node tests/Browser/epp-signed-delivery.cjs`

Expected: PASS for complete/partial delivery, disagreement, return, rotation and mobile widths. If unavailable, record exact error and complete manual browser checklist.

- [ ] **Step 4: Rehearse deployment on a realistic isolated copy**

Back up MySQL/private storage; migrate; run `epp:backfill-legacy-traceability --dry-run`, then real backfill; create warehouse/opening; test partial delivery/return/PDF verification and rollback. Never use production.

- [x] **Step 5: Inspect security and final diff**

Confirm no unrelated files, `.env`, real signatures, private documents, dumps, backups or temporaries are staged. Confirm `local` storage, authorized downloads and no sensitive logs.

- [x] **Step 6: Write and commit verification record**

Record commands, timestamps, results, backfill totals, browser widths, risks and production order.

```bash
git add docs/superpowers/plans/2026-10-07-logistica-epp-firma-lotes-verification.md
git commit -m "docs: record signed EPP release verification"
```
