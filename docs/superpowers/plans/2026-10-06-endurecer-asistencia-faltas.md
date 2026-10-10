# Endurecimiento de Asistencia y Faltas - Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Evitar duplicados y sobrescrituras históricas en Asistencia/Faltas, completar la trazabilidad de reaperturas y hacer que la interfaz comunique correctamente que la hora de marcación ordinaria la determina el servidor.

**Architecture:** Las transiciones de cierre y reapertura se serializarán bloqueando `asistencia_encabezado` con `lockForUpdate()` y revalidando el estado dentro de la misma transacción. Las correcciones de faltas también bloquearán la falta y su detalle origen, aceptarán únicamente faltas `ACTIVA` y registrarán la trazabilidad completa en ambos registros. La interfaz web dejará de solicitar una hora que el backend descarta; la hora declarada seguirá disponible únicamente en la corrección histórica de una falta.

**Tech Stack:** PHP 8.2+, Laravel 12, Eloquent, MySQL/InnoDB, Blade, PHPUnit 11.

**Spec:** `docs/asistencia-ejecucion.md` y diseño acotado aprobado en esta conversación el 2026-10-06.

## Global Constraints

- Ejecutar todas las pruebas exclusivamente contra MySQL `proserge_app_test`; nunca contra datos reales.
- Preservar los cambios locales existentes, especialmente en `AsistenciaService.php`, `FaltasService.php`, policies, RBAC y `routes/web.php`.
- No modificar contratos históricos ni eliminar registros de faltas: las correcciones y anulaciones son transiciones auditables.
- No agregar una restricción única rígida sobre `faltas.asistencia_detalle_id`: una reapertura y un cierre posterior deben poder crear un nuevo registro histórico.
- No cambiar dependencias ni instalar Playwright como parte de este trabajo.
- No modificar `public/build/assets/*` manualmente; no se requiere `npm run build` si solo cambian Blade y PHP.
- No crear commits automáticos desde el checkout sucio. Revisar y aislar los hunks de esta tarea antes de cualquier commit solicitado posteriormente.
- Mantener las respuestas y códigos API existentes salvo los nuevos errores operativos explícitos descritos por este plan.

## Review Focus

- Dos cierres construidos desde instancias obsoletas del mismo encabezado deben producir una sola falta activa y el segundo debe recibir `ASISTENCIA_ALREADY_CLOSED`.
- Reabrir debe anular únicamente faltas `ACTIVA` generadas por esa asistencia, registrando al usuario actor sin alterar faltas manuales, corregidas o ya anuladas.
- Una falta `CORREGIDA` no debe aceptar una segunda corrección ni sobrescribir motivo, actor, fecha u hora anteriores.
- Una corrección válida debe actualizar de forma coherente `asistencia_detalle` y `faltas`, incluyendo actor, origen y timestamps.
- La marcación ordinaria debe persistir la hora del servidor aunque un cliente legado envíe `hora_marcado`; la interfaz no debe prometer edición de esa hora.

---

### Task 1: Fijar por pruebas los contratos de concurrencia e historial

**Files:**
- Modify: `tests/Feature/AsistenciaApiTest.php`
- Modify: `tests/Feature/FaltasApiTest.php`
- Modify: `tests/Feature/AsistenciaFaltasWebTest.php`

**Interfaces:**
- Consumes: endpoints actuales de cierre, reapertura, corrección y marcación; `AsistenciaCierreService::cerrar()` para reproducir una instancia obsoleta.
- Produces: regresiones ejecutables que definen la semántica esperada antes de tocar código productivo.

- [ ] **Step 1: Añadir la regresión de cierre con encabezado obsoleto**

En `AsistenciaApiTest`, crear `test_cierre_revalida_el_estado_bloqueado_y_no_duplica_faltas()`. Preparar un grupo con un ausente y obtener dos instancias Eloquent independientes del mismo `AsistenciaEncabezado`; cerrar con la primera y luego intentar cerrar con la instancia obsoleta. Afirmar:

- primera respuesta `ok=true`;
- segunda respuesta `ok=false` y código `ASISTENCIA_ALREADY_CLOSED`;
- existe exactamente una falta `ACTIVA` con `motivo=INASISTENCIA_ASISTENCIA` para el detalle;
- el encabezado permanece `CERRADO`.

- [ ] **Step 2: Añadir la regresión del actor de reapertura**

En `AsistenciaApiTest`, ampliar el escenario de reapertura o crear `test_reapertura_anula_solo_faltas_activas_generadas_y_registra_actor()`. Crear una falta automática activa, una manual y una automática ya corregida. Reabrir mediante API y afirmar:

- la falta automática activa pasa a `ANULADA`;
- guarda `motivo_anulacion=Reapertura de asistencia`, `anulado_por_usuario_id` del usuario y `anulado_at` no nulo;
- la falta manual y la corregida conservan sus estados y datos;
- el encabezado pasa a `REGISTRADO`.

- [ ] **Step 3: Añadir la regresión de corrección inmutable y trazable**

En `FaltasApiTest`, crear dos pruebas:

1. `test_corregir_falta_registra_trazabilidad_en_falta_y_asistencia()` debe afirmar en `asistencia_detalle`: `PRESENTE`, hora declarada, `marcado_por_id`, `updated_by`, `marcado_at` y `origen_registro=CORRECCION_FALTA`; y en `faltas`: `CORREGIDA`, motivo, actor y fecha.
2. `test_no_permite_corregir_dos_veces_ni_sobrescribe_historial()` debe corregir una vez, capturar ambos registros, intentar una segunda corrección con valores diferentes y afirmar HTTP 422, código `FALTA_ALREADY_CORRECTED` y registros sin cambios.

- [ ] **Step 4: Cambiar la prueba web para exigir una interfaz honesta**

En `AsistenciaFaltasWebTest`, reemplazar la expectativa de `hora_marcado` en las vistas ordinarias por `assertDontSee('name="hora_marcado"', false)` para marcación individual y masiva. Mantener `assertSee` para `faltas.corregir`, donde la hora histórica sí es un dato operativo válido.

- [ ] **Step 5: Añadir la regresión de hora autoritativa del servidor**

En `AsistenciaApiTest`, controlar el reloj con `travelTo()`, enviar una hora distinta en la petición legacy y afirmar que `asistencia_detalle.hora_marcado` coincide con la hora local del servidor, no con el valor recibido.

- [ ] **Step 6: Ejecutar las pruebas nuevas y verificar RED**

Run:

```bash
php artisan test tests/Feature/AsistenciaApiTest.php tests/Feature/FaltasApiTest.php tests/Feature/AsistenciaFaltasWebTest.php
```

Expected: fallos específicos por falta de revalidación bajo bloqueo, ausencia de actor/origen, segunda corrección aceptada y campos de hora todavía visibles. Si una prueba pasa por comportamiento ya existente, ajustar únicamente el escenario para que reproduzca el defecto real; no debilitar las aserciones.

- [ ] **Step 7: Revisar el diff de pruebas**

Run:

```bash
git diff -- tests/Feature/AsistenciaApiTest.php tests/Feature/FaltasApiTest.php tests/Feature/AsistenciaFaltasWebTest.php
```

Expected: solo casos de regresión relacionados con este plan; ninguna fixture apunta fuera de `proserge_app_test`.

---

### Task 2: Serializar cierre y reapertura con trazabilidad completa

**Files:**
- Modify: `app/Modules/Asistencia/Services/AsistenciaCierreService.php:17-120`
- Modify: `app/Modules/Asistencia/Services/AsistenciaService.php:450-499`
- Test: `tests/Feature/AsistenciaApiTest.php`

**Interfaces:**
- Consumes: `Usuario`, `GrupoTrabajo` y el identificador del encabezado existente.
- Produces: `AsistenciaCierreService::cerrar(Usuario $usuario, GrupoTrabajo $grupo, AsistenciaEncabezado $encabezado, array $payload): array` idempotente bajo una instancia obsoleta; `AsistenciaCierreService::reabrir(Usuario $usuario, GrupoTrabajo $grupo, AsistenciaEncabezado $encabezado): array` con actor.

- [ ] **Step 1: Bloquear y revalidar el encabezado dentro de `cerrar()`**

Mover la decisión `CERRADO` al interior de `DB::transaction()`. Dentro de la transacción, recuperar el encabezado por su clave con `lockForUpdate()`, devolver `ASISTENCIA_NOT_FOUND` si desapareció y `ASISTENCIA_ALREADY_CLOSED` si ya está cerrado. Ejecutar `ensureDetalleCompleto()`, actualización del encabezado y generación de faltas utilizando exclusivamente la instancia bloqueada.

La comprobación de duplicado debe conservar compatibilidad legacy, pero ejecutarse bajo el bloqueo del mismo encabezado. No agregar índices únicos que impidan ciclos legítimos reapertura → nuevo cierre.

- [ ] **Step 2: Bloquear y revalidar el encabezado dentro de `reabrir()`**

Cambiar la firma para recibir `Usuario $usuario`. Dentro de una transacción, recargar el encabezado con `lockForUpdate()` y comprobar `estado === CERRADO`. Al anular faltas, limitar por:

- `asistencia_encabezado_id` exacto;
- `motivo=INASISTENCIA_ASISTENCIA`;
- `estado=ACTIVA`.

Guardar además `anulado_por_usuario_id=$usuario->id`, manteniendo motivo, `anulado_at` y `updated_at`.

- [ ] **Step 3: Propagar el actor desde `AsistenciaService`**

Actualizar únicamente la llamada en `AsistenciaService::reabrir()` para invocar `cierreService->reabrir($usuario, $grupo, $encabezado)`. Preservar los cambios locales existentes en ese archivo y no reformatear secciones ajenas.

- [ ] **Step 4: Ejecutar las regresiones de Asistencia y verificar GREEN**

Run:

```bash
php artisan test tests/Feature/AsistenciaApiTest.php --filter='cierre|reabrir'
```

Expected: todas las pruebas de cierre/reapertura pasan; el segundo cierre obsoleto devuelve el error esperado y no duplica faltas.

- [ ] **Step 5: Ejecutar la suite completa del módulo**

Run:

```bash
php artisan test tests/Feature/AsistenciaApiTest.php tests/Feature/MiAsistenciaWebTest.php tests/Feature/MiAsistenciaDatabaseErrorTest.php
```

Expected: PASS sin regresiones de horario, padrón, scope ni identidad del registrador.

- [ ] **Step 6: Revisar el diff aislado**

Run:

```bash
git diff -- app/Modules/Asistencia/Services/AsistenciaCierreService.php app/Modules/Asistencia/Services/AsistenciaService.php tests/Feature/AsistenciaApiTest.php
```

Expected: el único cambio dentro del archivo ya modificado `AsistenciaService.php` es la propagación necesaria del actor, además de pruebas directamente relacionadas.

---

### Task 3: Hacer atómica e inmutable la corrección de faltas

**Files:**
- Modify: `app/Modules/Faltas/Services/CorregirFaltaService.php:12-58`
- Test: `tests/Feature/FaltasApiTest.php`

**Interfaces:**
- Consumes: `Falta $falta`, `Usuario $usuario`, `array $payload` con `motivo_correccion` y hora histórica opcional.
- Produces: resultado `['ok' => bool, ...]`; nuevo error `FALTA_ALREADY_CORRECTED`; trazabilidad completa sobre `AsistenciaDetalle`.

- [ ] **Step 1: Bloquear la falta y validar su estado dentro de la transacción**

En `CorregirFaltaService::corregir()`, abrir la transacción antes de decidir el estado y recargar la falta por ID con `lockForUpdate()`. Reglas:

- `ANULADA` → `FALTA_ANULADA`;
- `CORREGIDA` → `FALTA_ALREADY_CORRECTED`;
- cualquier estado distinto de `ACTIVA` → `FALTA_NOT_ACTIVE`;
- sin `asistencia_detalle_id` → conservar `FALTA_WITHOUT_ASISTENCIA`.

Devolver los errores desde la transacción sin escribir datos.

- [ ] **Step 2: Bloquear y actualizar el detalle origen**

Recuperar `AsistenciaDetalle` mediante `lockForUpdate()`. Si no existe, devolver `ASISTENCIA_DETALLE_NOT_FOUND`. En una corrección válida guardar:

- `estado=PRESENTE`;
- `hora_marcado` declarada o la hora actual del servidor;
- `marcado_por_id=$usuario->id`;
- `updated_by=$usuario->id`;
- `marcado_at=now()`;
- `origen_registro=CORRECCION_FALTA`;
- observación de auditoría que conserve el texto previo y referencie la falta.

No modificar `grupo_trabajo_detalle_id`, snapshots ni destino.

- [ ] **Step 3: Actualizar la falta usando la instancia bloqueada**

Guardar `CORREGIDA`, `motivo_correccion`, `corregido_por_usuario_id` y `corregido_at`. Retornar la instancia fresca en `falta`. No crear historial paralelo ni borrar la falta.

- [ ] **Step 4: Ejecutar la prueba RED/GREEN focalizada**

Run:

```bash
php artisan test tests/Feature/FaltasApiTest.php --filter='corregir'
```

Expected: PASS; la segunda corrección retorna 422 mediante el controlador y la primera deja trazabilidad consistente.

- [ ] **Step 5: Ejecutar toda la suite de Faltas**

Run:

```bash
php artisan test tests/Feature/FaltasApiTest.php
```

Expected: 0 fallos; se mantienen filtros por destino, scope, anulación y vínculo con asistencia.

---

### Task 4: Alinear formularios y contratos de entrada con la hora del servidor

**Files:**
- Modify: `resources/views/asistencia/marcar.blade.php:34-37`
- Modify: `resources/views/asistencia/masivo.blade.php:18`
- Modify: `app/Modules/Asistencia/Controllers/AsistenciaPageController.php:87-166`
- Test: `tests/Feature/AsistenciaFaltasWebTest.php`
- Test: `tests/Feature/AsistenciaApiTest.php`

**Interfaces:**
- Consumes: hora local calculada por `AsistenciaHorarioService`.
- Produces: formularios ordinarios sin campo editable de hora; endpoints legacy que siguen tolerando un `hora_marcado` recibido pero nunca lo usan como fuente de verdad.

- [ ] **Step 1: Retirar la hora editable de las dos vistas ordinarias**

Eliminar los inputs `marked-time` y `bulk-time`. Añadir texto operativo breve junto al estado: “La hora de marcación se registra automáticamente con la hora del servidor.” No quitar la hora de `resources/views/faltas/corregir.blade.php`.

- [ ] **Step 2: Retirar la validación web de un campo que ya no se presenta**

Eliminar `hora_marcado` de `AsistenciaPageController::markRules()` y de las reglas de `marcarMasivoPost()`. No cambiar en esta tarea las Form Requests de API: conservar la compatibilidad de clientes existentes y la prueba que demuestra que el servidor reemplaza el valor recibido.

- [ ] **Step 3: Ejecutar pruebas web y de hora autoritativa**

Run:

```bash
php artisan test tests/Feature/AsistenciaFaltasWebTest.php tests/Feature/AsistenciaApiTest.php --filter='formulario|hora|marcado_individual|marcado_masivo'
```

Expected: PASS; los formularios no contienen el input engañoso, la corrección de falta sí lo conserva y el backend persiste la hora controlada.

- [ ] **Step 4: Compilar las vistas Blade**

Run:

```bash
php artisan view:cache
```

Expected: `Blade templates cached successfully.`

- [ ] **Step 5: Revisión visual proporcional**

Si Playwright ya está disponible, ejecutar:

```bash
node tests/Browser/attendance-dashboards.cjs
```

Si no está instalado, no instalarlo: revisar mediante las respuestas Feature y registrar la validación visual como pendiente. Confirmar especialmente que el retiro del campo no deja un hueco o una etiqueta huérfana en desktop y mobile.

---

### Task 5: Sincronizar documentación operativa

**Files:**
- Modify: `docs/asistencia-ejecucion.md:71-133`
- Optionally modify: `docs/TECH_DEBT.md` solo si ya enumera alguno de estos defectos.

**Interfaces:**
- Consumes: comportamiento verificado por Tasks 2-4.
- Produces: documentación canónica coherente con cierre, reapertura, corrección e interfaz.

- [ ] **Step 1: Documentar serialización e idempotencia del cierre**

Añadir que cierre y reapertura bloquean el encabezado y revalidan su estado, evitando duplicados por solicitudes simultáneas u obsoletas. Explicar que no se usa unicidad permanente en `faltas` porque cada ciclo de cierre posterior a una reapertura conserva un evento histórico distinto.

- [ ] **Step 2: Documentar trazabilidad de reapertura y corrección**

Indicar que la reapertura guarda al usuario que anula faltas automáticas activas y que una falta corregida no admite una segunda corrección. Documentar `CORRECCION_FALTA` como origen del cambio en el detalle de asistencia.

- [ ] **Step 3: Aclarar la semántica de la hora**

Especificar que marcación individual/masiva usa siempre la hora del servidor y no muestra un campo editable; la hora ingresada solo existe en la regularización histórica de una falta.

- [ ] **Step 4: Verificar coherencia documental**

Run:

```bash
rg -n "hora del servidor|CORRECCION_FALTA|reapertura|segunda correccion|duplic" docs/asistencia-ejecucion.md
```

Expected: cada regla nueva aparece una vez en su sección correspondiente y no contradice los estados vigentes.

---

### Task 6: Verificación integral y entrega

**Files:**
- Verify only: todos los archivos anteriores y módulos integrados cercanos.

**Interfaces:**
- Consumes: implementación y pruebas de Tasks 1-5.
- Produces: evidencia reproducible de que el flujo quedó estable y los cambios ajenos permanecen intactos.

- [ ] **Step 1: Ejecutar lint PHP en todo archivo PHP tocado**

Run:

```bash
php -l app/Modules/Asistencia/Services/AsistenciaCierreService.php
php -l app/Modules/Asistencia/Services/AsistenciaService.php
php -l app/Modules/Asistencia/Controllers/AsistenciaPageController.php
php -l app/Modules/Faltas/Services/CorregirFaltaService.php
php -l tests/Feature/AsistenciaApiTest.php
php -l tests/Feature/FaltasApiTest.php
php -l tests/Feature/AsistenciaFaltasWebTest.php
```

Expected: `No syntax errors detected` para cada archivo.

- [ ] **Step 2: Ejecutar Pint en modo comprobación sobre el PHP tocado**

Run:

```bash
vendor/bin/pint --test app/Modules/Asistencia/Services/AsistenciaCierreService.php app/Modules/Asistencia/Services/AsistenciaService.php app/Modules/Asistencia/Controllers/AsistenciaPageController.php app/Modules/Faltas/Services/CorregirFaltaService.php tests/Feature/AsistenciaApiTest.php tests/Feature/FaltasApiTest.php tests/Feature/AsistenciaFaltasWebTest.php
```

Expected: PASS. Si Pint propone cambios, aplicar solo formato a los hunks de esta tarea para no reformatear modificaciones locales ajenas.

- [ ] **Step 3: Ejecutar la regresión funcional completa del flujo**

Run:

```bash
php artisan test tests/Feature/AsistenciaApiTest.php tests/Feature/FaltasApiTest.php tests/Feature/AsistenciaFaltasWebTest.php tests/Feature/MiAsistenciaWebTest.php tests/Feature/MiAsistenciaDatabaseErrorTest.php tests/Feature/AttendanceSchemaRepairTest.php tests/Feature/RbacOperationalScopeTest.php
node --test tests/JavaScript/attendance-camera.test.mjs tests/JavaScript/attendance-schedule.test.mjs
```

Expected: 0 errores y 0 fallos. Informar por nombre cualquier fallo preexistente o no relacionado; no ocultarlo.

- [ ] **Step 4: Verificar rutas, scheduler y vistas**

Run:

```bash
php artisan route:list --path=asistencia -v
php artisan route:list --path=faltas -v
php artisan schedule:list
php artisan view:cache
```

Expected: las escrituras API mantienen `auth.token`, las rutas web mantienen `web.auth` y permisos por acción, y `asistencia:ausentes-por-horario` continúa cada minuto.

- [ ] **Step 5: Ejecutar controles de diff**

Run:

```bash
git diff --check
git status --short
git diff -- app/Modules/Asistencia/Services/AsistenciaCierreService.php app/Modules/Asistencia/Services/AsistenciaService.php app/Modules/Asistencia/Controllers/AsistenciaPageController.php app/Modules/Faltas/Services/CorregirFaltaService.php resources/views/asistencia/marcar.blade.php resources/views/asistencia/masivo.blade.php tests/Feature/AsistenciaApiTest.php tests/Feature/FaltasApiTest.php tests/Feature/AsistenciaFaltasWebTest.php docs/asistencia-ejecucion.md
```

Expected: sin whitespace inválido; ningún archivo generado, dependencia, dato real o cambio ajeno agregado por esta tarea.

- [ ] **Step 6: Revisión manual de invariantes**

Confirmar en el diff y resultados:

- solo Asistencia/Faltas modifica sus estados;
- todas las escrituras coordinadas son transaccionales;
- reapertura conserva el historial y actor;
- corrección no puede sobrescribirse;
- el scope de mina y permisos de registrar/cerrar/reabrir/corregir/anular siguen vigentes;
- no se expone información sensible en respuestas o logs.

- [ ] **Step 7: Preparar el informe de entrega**

Resumir archivos modificados, pruebas con conteos, pruebas no ejecutadas y razón, riesgo residual de concurrencia probado mediante instancia obsoleta, validación visual pendiente si falta Playwright y ausencia de pasos de migración/despliegue de esquema.

No crear commit ni desplegar hasta que el usuario revise el diff final del checkout compartido.
