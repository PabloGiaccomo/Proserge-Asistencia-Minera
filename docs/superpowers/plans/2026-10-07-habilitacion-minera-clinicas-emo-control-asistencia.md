# Habilitacion minera: clinicas EMO y control de asistencia Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Crear citas EMO con clinica unica por trabajador y fecha, resultados medicos estructurados, control por rango de fechas y exportacion Excel fiel a los filtros.

**Architecture:** Una cita EMO compartida representa el evento fisico y se vincula explicitamente a los intentos de cada mina. Un servicio transaccional central impone conflictos y completa todos los enlaces; un servicio de consulta separado alimenta la pantalla y el exportador para que ambos usen exactamente los mismos filtros.

**Tech Stack:** PHP 8.2+, Laravel 12, Eloquent/MySQL, Blade, JavaScript nativo, Tailwind/CSS propio, PhpSpreadsheet 5, PHPUnit 11.

**Spec:** `docs/superpowers/specs/2026-10-07-habilitacion-minera-clinicas-emo-control-asistencia-design.md`

## Estado verificado al 2026-10-08

Tasks 0-8 implementadas y revisadas. Task 9: integracion, documentacion,
revision final y preparacion del despliegue terminadas; ensayo sobre copia
realista pendiente por indicacion del usuario. No se realizo despliegue.

La suite de esta entrega pasa (242 casos / 1727 aserciones); la suite general
conserva incidencias previas. Evidencia, limites y pasos pendientes en
`docs/superpowers/reports/2026-10-08-habilitacion-minera-emo-entrega.md`.
Los checklists originales describen el procedimiento; este estado y el informe
de entrega registran su ejecucion, sin acreditar el ensayo pendiente.

## Global Constraints

- Leer `AGENTS.md`, `docs/CODEX_CONTEXT.md`, el Spec y `docs/HABILITACION_CARGA_MASIVA.md` antes de editar.
- Partir del checkout actual; sus cambios no confirmados son intencionales. No restaurar, reemplazar ni reformatear archivos ajenos.
- No cambiar dependencias ni editar `vendor`, `node_modules` o assets con hash.
- Usar solamente `proserge_app_test`; comprobar la conexion efectiva antes de cualquier test que escriba datos.
- No inferir clinicas desde `lugar_snapshot` ni clasificar EMO por nombre del examen.
- No modificar contratos, estado laboral, fichas, documentos personales ni minas no enlazadas.
- Toda escritura coordinada usa transaccion y el orden de locks trabajador → cita → asignaciones → examenes/intentos.
- Las migraciones son aditivas e idempotentes y no eliminan historial en `down()`.
- Los textos de usuario permanecen en lenguaje operativo y ningun estado depende solo del color.
- Cada implementador modifica exclusivamente los archivos asignados. Los archivos compartidos se integran en tareas secuenciales.
- No crear commits salvo autorizacion expresa. Si se autoriza, usar un commit pequeno al terminar cada tarea aprobada.

## Review Focus

- Dos solicitudes concurrentes para distinta clinica, misma persona y fecha: una gana y la otra recibe error de dominio sin datos parciales; Task 3 lo prueba con dos conexiones.
- Historial pendiente sin clinica: no se completa ni se usa para asumir coincidencia; Task 3 prueba el bloqueo y la regularizacion.
- `APTO_CON_OBSERVACION`: aprueba pero exige detalle; la cita lo conserva como
  fuente estructurada, el intento conserva solo `APROBADO`, y convalidacion y
  Excel leen el valor exacto por la relacion; Tasks 2, 3 y 7 lo prueban.
- Filtro por mina: no muestra ni exporta minas ajenas aunque compartan cita; Tasks 6 y 7 lo prueban.
- Texto que comienza con `=`, `+`, `-` o `@`: el XLSX lo escribe como texto seguro; Task 7 lo prueba.

---

## Estrategia de subagentes

### DAG

```text
Task 0 baseline
  ├─ Task 1 esquema/modelos
  └─ Task 2 resultados EMO
       └─ [Task 1 + Task 2] → Task 3 citas y concurrencia
                              ├─ Task 5 carga masiva y Master
                              └─ Task 6 consulta y filtros
                                   └─ Task 4 flujo manual/controlador
                                        └─ Task 7 Excel
                                             └─ Task 8 UI responsive
                                                  └─ Task 9 integracion/docs
```

Tasks 1 y 2 pueden ejecutarse en paralelo y deben aprobarse antes de Task 3.
Tras Task 3, Tasks 5 y 6 pueden correr en paralelo. Task 4 espera la consulta de
Task 6 para integrar el controlador una sola vez. Tasks 4 y 7 modifican rutas y
se ejecutan en serie; Task 7 usa un controlador de exportacion separado. Task 8
solo posee Blade/JS/CSS y sus pruebas, por lo que consume las interfaces ya
integradas sin volver a tocar controlador o rutas. Puede preparar un analisis
visual sin editar, pero no implementar antes del gate de Task 7.

### Ciclo por tarea

1. Un implementador fresco lee solo Spec, plan, archivos asignados e interfaces previas.
2. Aplica TDD y entrega resumen de diff y evidencia RED/GREEN.
3. Un revisor fresco inspecciona requisitos, diff y pruebas sin editar.
4. El implementador original corrige mediante `followup_task`.
5. El coordinador ejecuta la verificacion focalizada y habilita el siguiente nodo.

No ejecutar en paralelo dos agentes que posean el mismo archivo. El servicio
principal, controlador, rutas, Blade, `app.js` y `app.css` tienen propietario
unico en cada momento.

---

### Task 0: Baseline seguro e inventario

**Files:**
- Read: `docs/superpowers/plans/2026-10-06-habilitacion-minera-ejecucion.md`
- Read: `git status`, diff de archivos mineros y tests existentes
- Create local evidence only: `storage/app/private/mining-emo-plan/` (no versionar)

**Interfaces:**
- Consumes: estado actual del checkout y base aislada.
- Produces: manifiesto de archivos sucios, base efectiva, tests baseline,
  inventario de catalogo y acta Gate 0 firmada por el responsable funcional.

- [ ] **Step 1: Confirmar que la base efectiva es de pruebas**

```powershell
$env:APP_ENV='testing'
$env:DB_DATABASE='proserge_app_test'
$env:DB_URL=''
php artisan tinker --execute="throw_unless(DB::connection()->getDatabaseName()==='proserge_app_test'); dump(DB::connection()->getDatabaseName());"
```

Expected: imprime `proserge_app_test`; abortar ante cualquier otro nombre.

- [ ] **Step 2: Capturar baseline sin modificar archivos**

Run:

```powershell
git status --short
git diff --check
php artisan test --filter "PersonalMina|MiningExam|PersonalCurso|WebPermission"
```

Expected: registrar exitos, fallos preexistentes y duracion; no corregir fallos fuera del alcance.

- [ ] **Step 3: Inventariar clasificacion sin mutar datos**

Obtener conteos de `examenes_mineros.tipo` en la base de prueba y registrar que
registros necesitan clasificacion manual. Todos migran inicialmente a `OTRO`.
No consultar ni exportar datos personales reales.

- [ ] **Step 4: Cerrar Gate 0 funcional**

Confirmar por escrito antes de implementar:

- `APTO_CON_OBSERVACION` habilita y exige detalle;
- una cita compartida acepta solo requisitos con el mismo `examen_id` exacto;
- una cita completada no acepta minas nuevas;
- el formato masivo oficial puede incorporar `CLINICA`;
- la fuente inicial de clinicas sera carga manual por usuario `configurar`.

Si alguna respuesta cambia, actualizar primero Spec y tests esperados.

- [ ] **Step 5: Gate del coordinador**

Confirmar propietarios de archivos, que no hay un subagente concurrente sobre
ellos y que el baseline queda disponible para comparar al final. Crear
`storage/app/private/mining-emo-plan/php-files.txt` copiando de forma explicita,
una ruta por linea, todos y solo los PHP declarados en **Files** de Tasks 1-8;
revisarlo de nuevo contra el diff antes del control final.

---

### Task 1: Esquema aditivo y modelos

**Files:**
- Create: `database/migrations/2026_10_07_000100_create_emo_appointments_and_clinics.php`
- Create: `app/Models/ClinicaEmo.php`
- Create: `app/Models/PersonalEmoCita.php`
- Create: `app/Models/PersonalEmoCitaHistorial.php`
- Create: `app/Models/PersonalEmoExportAudit.php`
- Create: `app/Models/ExamenMineroCategoriaHistorial.php`
- Modify: `app/Models/ExamenMinero.php`
- Modify: `app/Models/PersonalMinaExamenIntento.php`
- Modify: `app/Models/PersonalCursoRegistro.php`
- Create/Test: `tests/Feature/MiningEmoSchemaTest.php`

**Interfaces:**
- Produces: relaciones `PersonalEmoCita::clinica()`, `personal()`, `intentos()`, `cursos()`, `historial()`; `PersonalMinaExamenIntento::emoCita()`; `PersonalCursoRegistro::emoCita()`; `ExamenMinero::categoria_operativa` y `categoriaHistorial()`; auditorias `PersonalEmoExportAudit` y `ExamenMineroCategoriaHistorial`.
- Produces constants: categorias `CURSO`, `EMO`, `OTRO`; estados de cita `PROGRAMADA`, `COMPLETADA`, `ANULADA`.

- [ ] **Step 1: Escribir tests RED de esquema y casts**

Crear tests que prueben:

- tablas y columnas nuevas;
- UUID y relaciones;
- unicidad de `clinicas_emo.nombre_normalizado`;
- unicidad de cita por trabajador/fecha e indice adicional por fecha/estado;
- unico `personal_mina_examen_id + emo_cita_id`;
- auditoria minima de exportacion sin filas/resultados;
- historial de categoria con valor anterior/nuevo, actor, motivo y timestamp;
- campos nullable para historial;
- clasificacion inicial `OTRO` para todo registro existente.

- [ ] **Step 2: Verificar RED**

Run: `php artisan test tests/Feature/MiningEmoSchemaTest.php`

Expected: FAIL por tablas/modelos/columnas inexistentes.

- [ ] **Step 3: Crear migracion idempotente**

La migracion crea `clinicas_emo`, `personal_emo_citas`,
`personal_emo_cita_historial`, `personal_emo_export_audits`,
`examen_minero_categoria_historial`, agrega
`categoria_operativa` a examenes y `emo_cita_id` a intentos/cursos. Usar
`Schema::has*` y nombres de indices explicitos. Las FK nuevas son nullable donde
existe historial.

No copiar `lugar_snapshot`. El `down()` no elimina historia medica; documentar
que rollback operativo conserva estructura aditiva.

- [ ] **Step 4: Crear modelos y relaciones**

Añadir fillable/casts/relaciones sin cambiar contratos publicos existentes.
`clinica_nombre_snapshot`, fechas, resultado/detalle, actores, origen y estado
pertenecen a la cita. La auditoria de exportacion guarda actor, filtros JSON,
hash, cantidad agrupada y timestamp. El historial de categoria guarda examen,
valor anterior/nuevo, actor, motivo y timestamp; no contiene datos medicos.

- [ ] **Step 5: Aplicar migracion solo a la base aislada**

Repetir primero la guarda de Task 0 y ejecutar:

```powershell
php artisan migrate --path=database/migrations/2026_10_07_000100_create_emo_appointments_and_clinics.php --database=mysql --force
```

Expected: migracion aplicada en `proserge_app_test`. No ejecutar si la guarda no
confirma ese nombre.

- [ ] **Step 6: Verificar GREEN y variantes de migracion**

Run:

```powershell
php artisan test tests/Feature/MiningEmoSchemaTest.php
php -l database/migrations/2026_10_07_000100_create_emo_appointments_and_clinics.php
php -l app/Models/ClinicaEmo.php
php -l app/Models/PersonalEmoCita.php
php -l app/Models/PersonalEmoCitaHistorial.php
php -l app/Models/PersonalEmoExportAudit.php
php -l app/Models/ExamenMineroCategoriaHistorial.php
```

Expected: PASS. Probar al menos esquema limpio de prueba y esquema con columnas
ya presentes; nunca usar `migrate:fresh` como argumento de compatibilidad.

- [ ] **Step 7: Revision de esquema**

Revisor confirma tipos MySQL, longitudes, indices, FKs, retencion historica y
que la migracion no reinterpreta filas.

---

### Task 2: Resultado EMO estructurado

**Files:**
- Create: `app/Modules/Personal/Support/EmoOutcome.php`
- Modify: `app/Modules/Personal/Support/CursoResultado.php`
- Create/Test: `tests/Unit/EmoOutcomeTest.php`
- Create/Test: `tests/Unit/CursoResultadoCompatibilityTest.php`

**Interfaces:**
- Produces: `EmoOutcome::fromInput(mixed $value): self`.
- Produces: `EmoOutcome::genericResult(): string`, `requiresDetail(): bool`, `label(): string`.
- Produces: `CursoResultado::payload(array $payload, bool $isEmo = false): array`
  compatible con llamadas actuales; incluso para EMO devuelve el contrato
  persistible generico existente, no columnas estructuradas del intento.

- [ ] **Step 1: Escribir data provider RED**

Probar los cuatro valores y estas salidas:

| Entrada | Resultado generico | Detalle obligatorio |
| --- | --- | --- |
| `APTO` | `APROBADO` | no |
| `NO_APTO` / `NO APTO` | `DESAPROBADO` | no |
| `APTO_CON_RESTRICCION` | `APROBADO` | si |
| `APTO_CON_OBSERVACION` | `APROBADO` | si |

Probar normalizacion de espacios/guion bajo y rechazo de valores desconocidos.

- [ ] **Step 2: Verificar RED**

Run: `php artisan test tests/Unit/EmoOutcomeTest.php`

Expected: FAIL porque `EmoOutcome` no existe.

- [ ] **Step 3: Implementar value object/enum**

Centralizar etiquetas y mapeo. No definir `ENUM` MySQL. El detalle estructurado
no se obtiene leyendo prefijos de observacion.

- [ ] **Step 4: Mantener compatibilidad de CursoResultado**

Las llamadas no EMO conservan su comportamiento. Con `$isEmo=true`, devolver
`resultado` generico y `observacion` compatibles, aceptando las cuatro entradas;
exigir detalle para las dos variantes correspondientes. `EmoAppointmentService`
persiste el resultado exacto y detalle en la cita, que es la unica fuente
estructurada; no agregar claves/columnas EMO al intento.

- [ ] **Step 5: Verificar GREEN y regresion**

Run:

```powershell
php artisan test tests/Unit/EmoOutcomeTest.php
php artisan test tests/Unit/CursoResultadoCompatibilityTest.php
php artisan test tests/Feature/PersonalMinaHabilitacionCorrectedFlowTest.php --filter "restricted_aptitude|bulk_courses_preserve_restriction"
```

Expected: PASS.

---

### Task 3: Servicio transaccional de citas EMO

**Files:**
- Create: `app/Modules/Personal/Services/EmoAppointmentService.php`
- Create: `app/Modules/Personal/Support/ScheduleEmoData.php`
- Create: `app/Modules/Personal/Support/CompleteEmoData.php`
- Create: `app/Modules/Personal/Support/ReprogramEmoData.php`
- Create: `app/Modules/Personal/Support/EmoOrigin.php`
- Modify: `app/Modules/Personal/Services/PersonalMinaHabilitacionService.php`
- Create/Test: `tests/Feature/PersonalMinaEmoSchedulingTest.php`
- Modify/Test: `tests/Feature/MiningTransactionVisibilityTest.php`

**Interfaces:**
- Consumes: modelos de Task 1 y `EmoOutcome` de Task 2.
- Produces: `EmoOrigin` con valores exactos `MANUAL`, `CARGA_MASIVA` y
  `REGULARIZACION`; Master no es un origen de cita en v1.
- Produces: `ScheduleEmoData` con `scheduledAt:CarbonImmutable`,
  `clinicId:string`, `observation:?string`, `origin:EmoOrigin` y
  `sourceCourseRecordId:?string`.
- Produces: `CompleteEmoData` con `performedAt:CarbonImmutable`,
  `outcome:EmoOutcome`, `detail:?string`, `score:?decimal-string`,
  `manualExpiration:?CarbonImmutable`, `observation:?string`,
  `file:?UploadedFile`, `origin:EmoOrigin` y `sourceCourseRecordId:?string`.
- Produces: `ReprogramEmoData` con `scheduledAt:CarbonImmutable`,
  `clinicId:string`, `reason:string` y `origin:EmoOrigin`.
- Produces: `schedule(PersonalMinaExamen $exam, ScheduleEmoData $data, Usuario $actor): PersonalEmoCita`.
- Produces: `complete(PersonalEmoCita $appointment, CompleteEmoData $data, Usuario $actor): PersonalEmoCita`.
- Produces: `reprogram(PersonalEmoCita $appointment, ReprogramEmoData $data, Usuario $actor): PersonalEmoCita` solo para citas anuladas; registra valores anterior/nuevo y actor.
- Produces: `cancel(PersonalEmoCita $appointment, string $reason, Usuario $actor): PersonalEmoCita`.
- Produces: `regularizeLegacyAttempt(PersonalMinaExamenIntento $attempt, ClinicaEmo $clinic, Usuario $actor): PersonalEmoCita`.

- [ ] **Step 1: Escribir tests RED de regla principal**

Casos:

- misma persona/fecha/clinica con el mismo `examen_id` en dos minas
  reutiliza una cita y crea dos enlaces;
- `examen_id` distinto en la misma cita se rechaza;
- misma persona/fecha y clinica distinta rechaza y no crea intento/cita parcial;
- mina no enlazada no recibe resultado;
- clinica inactiva y examen no EMO son rechazados por este servicio;
- `EmoOrigin` acepta solo `MANUAL`, `CARGA_MASIVA` y `REGULARIZACION`, y
  rechaza entradas desconocidas;
- cita completada no cambia clinica ni resultado;
- cita completada no acepta una mina vinculada tardíamente;
- cita anulada puede reprogramarse mediante operacion auditada;
- reprogramar a una fecha ya ocupada falla sin perder la cita original;
- cancelacion y reprogramacion concurrentes dejan una sola transicion valida,
  sin revivir una cita completada ni perder el historial;
- legado sin clinica bloquea otra programacion de ese dia hasta regularizar.

- [ ] **Step 2: Escribir tests RED de resultados y atomicidad**

Completar cada resultado y comprobar cita, intentos, estados de examen,
asignaciones, vigencia y detalle. Simular excepcion durante el segundo enlace y
comprobar rollback total.

Agregar regresiones para:

- archivo medico guardado y eliminado en rollback;
- snapshot de precio por fecha programada/realizada;
- nota y vencimiento manual por cada examen;
- configuraciones de vigencia divergentes entre minas;
- renovacion anticipada que conserva aprobacion vigente;
- notificacion de programacion con la cita nueva;
- actores distintos para programar y completar;
- convalidacion que copia resultado/detalle/cita/clinica en
  `contexto_convalidacion_json` sin perder restricciones.

- [ ] **Step 3: Escribir test RED de concurrencia real**

En `MiningTransactionVisibilityTest`, usar dos conexiones MySQL visibles entre
si. Dos transacciones intentan clinicas distintas para la misma persona/fecha.
Expected: una cita final, una clinica, un error operativo; limpiar solo UUID de
fixtures propios.

- [ ] **Step 4: Verificar RED**

Run:

```powershell
php artisan test tests/Feature/PersonalMinaEmoSchedulingTest.php
php artisan test tests/Feature/MiningTransactionVisibilityTest.php --filter emo
```

- [ ] **Step 5: Implementar servicio con orden de locks**

Bloquear trabajador antes de buscar/crear cita. Reutilizar cita solo si fecha,
clinica, categoria y examen son compatibles. Bloquear asignaciones/examenes por
UUID ordenado. Guardar eventos con antes/despues y actores separados.

- [ ] **Step 6: Delegar desde el servicio minero**

En `registerAttempt()` y `completeScheduledAttempt()`, detectar categoria EMO y
construir los DTOs sin perder archivo, precio, nota, vencimiento, observacion,
origen ni notificaciones. Mantener intacta la ruta legado/curso. No duplicar la
regla en el controlador.

- [ ] **Step 7: Verificar GREEN**

Run:

```powershell
php artisan test tests/Feature/PersonalMinaEmoSchedulingTest.php
php artisan test tests/Feature/MiningTransactionVisibilityTest.php --filter emo
php artisan test tests/Feature/PersonalMinaExamenesMinerosTest.php
```

- [ ] **Step 8: Revision backend gate**

Revisor inspecciona orden de locks, consultas bajo lock, idempotencia, rollback,
renovacion anticipada, notificaciones y ausencia de propagacion silenciosa.

---

### Task 4: Catalogo de clinicas y endpoints manuales

**Files:**
- Create: `app/Modules/Personal/Services/EmoClinicService.php`
- Create: `app/Modules/Personal/Requests/StoreClinicaEmoRequest.php`
- Create: `app/Modules/Personal/Requests/ScheduleEmoRequest.php`
- Create: `app/Modules/Personal/Requests/CompleteEmoAppointmentRequest.php`
- Create: `app/Modules/Personal/Requests/ReprogramEmoAppointmentRequest.php`
- Create: `app/Modules/Personal/Requests/UpdateExamOperationalCategoryRequest.php`
- Create: `app/Modules/Personal/Services/ExamOperationalCategoryService.php`
- Modify: `app/Modules/Personal/Controllers/PersonalMinaHabilitacionController.php`
- Modify: `routes/web.php`
- Modify/Test: `tests/Feature/WebPermissionMiddlewareTest.php`
- Create/Test: `tests/Feature/PersonalMinaEmoWebTest.php`

**Interfaces:**
- Consumes: `EmoAppointmentService` de Task 3 y consulta/paginador de Task 6.
- Produces: `EmoClinicService::create(array $data, Usuario $actor): ClinicaEmo`, `update(ClinicaEmo $clinic, array $data, Usuario $actor): ClinicaEmo` y `deactivate(ClinicaEmo $clinic, Usuario $actor): ClinicaEmo`.
- Produces: `ExamOperationalCategoryService::update(ExamenMinero $exam,
  string $category, string $reason, Usuario $actor): ExamenMinero`,
  transaccional y auditado en `examen_minero_categoria_historial`.
- Produces routes: `clinics.store`, `clinics.update`, `clinics.deactivate`,
  `exams.category`, `emo.schedule`, `emo.complete`, `emo.cancel`,
  `emo.reprogram`, `emo.regularize`; no reglas de dominio en controlador.

- [ ] **Step 1: Escribir tests RED de validacion y permisos**

Probar 403/redirect para `configurar`, `programar`, `registrar` y que el backend
rechaza clinic IDs inactivos, fechas invalidas, detalle ausente y doble envio.
Probar tambien que la accion separada de clasificacion persiste una categoria
operativa valida y que un usuario con solo `editar`, sin `configurar`, recibe
403 aunque pueda editar otros campos del examen. Categoria y motivo son
obligatorios; probar que el historial conserva anterior/nuevo y actor.

- [ ] **Step 2: Verificar RED**

Run: `php artisan test tests/Feature/PersonalMinaEmoWebTest.php`

- [ ] **Step 3: Crear Form Requests y acciones delgadas**

Los Request validan forma; el servicio valida estado actual dentro de
transaccion. Los redirects conservan vista, filtros y modal relevante.
El `index()` recibe el paginator ya filtrado de Task 6; no reconstruye filtros ni
devuelve la coleccion limitada anterior.

- [ ] **Step 4: Registrar rutas con permisos**

Mantener `web.auth` del grupo. No agregar `mina.scope`. Actualizar inventario
exacto de middleware en `WebPermissionMiddlewareTest`.

Cuando una accion requiere dos permisos, registrar dos middleware separados y
repetir ambas comprobaciones en el controlador. No usar `accionA|accionB`, que
representa OR.

Matriz obligatoria, tanto en middleware como en autorizacion del controlador:

- crear/renombrar/desactivar clinica y clasificar examen: `configurar`;
- programar, reprogramar, anular cita pendiente y regularizar legado:
  `programar`;
- completar cita: `registrar`;
- ver control: `ver_programados`;
- exportar: `ver_programados` **y** `exportar`, como dos comprobaciones.

Agregar un caso 403 individual por cada accion y por cada permiso faltante.

Usar estos contratos:

- POST `/personal/habilitacion-minera/clinicas-emo`;
- POST `/personal/habilitacion-minera/clinicas-emo/{clinicId}`;
- POST `/personal/habilitacion-minera/clinicas-emo/{clinicId}/desactivar`;
- POST `/personal/habilitacion-minera/examenes/{examId}/categoria-operativa`,
  accion separada protegida exclusivamente por `configurar`;
- POST `/personal/habilitacion-minera/examenes-trabajador/{workerExamId}/programar-emo`;
- POST `/personal/habilitacion-minera/emo-citas/{appointmentId}/completar`;
- POST `/personal/habilitacion-minera/emo-citas/{appointmentId}/anular`;
- POST `/personal/habilitacion-minera/emo-citas/{appointmentId}/reprogramar`;
- POST `/personal/habilitacion-minera/intentos/{attemptId}/regularizar-clinica`.

- [ ] **Step 5: Verificar GREEN**

Run:

```powershell
php artisan test tests/Feature/PersonalMinaEmoWebTest.php
php artisan test tests/Feature/WebPermissionMiddlewareTest.php --filter habilitacion
php artisan route:list --name=personal.habilitacion-minera
```

---

### Task 5: Carga masiva y Master

**Files:**
- Modify: `app/Modules/Personal/Services/PersonalMinaBulkPreviewService.php`
- Modify: `app/Modules/Personal/Services/PersonalCursoImportPlanner.php`
- Modify: `app/Modules/Personal/Services/PersonalCursoBulkService.php`
- Modify: `app/Modules/Personal/Services/PersonalMinaExcelImportService.php`
- Modify/Test: `tests/Unit/PersonalMinaBulkPreviewTest.php`
- Create/Test: `tests/Feature/PersonalMinaEmoBulkTest.php`
- Modify/Test: `tests/Feature/MiningExamIdentityIntegrationTest.php`

**Interfaces:**
- Consumes: `EmoAppointmentService::schedule()` y `complete()`.
- Produces: filas preview con `clinic_id`, `clinic_name`, `appointment_action` y conflictos bloqueantes.

- [ ] **Step 1: Escribir tests RED del parser/preview**

Probar layout nuevo con `CLINICA`, layout antiguo de curso, EMO sin clinica,
clinica desconocida/inactiva, dos clinicas en el archivo y conflicto con cita
existente. Verificar que ninguna preview escribe datos.

Layouts v2 exactos:

- Programaciones: `DNI`, `APELLIDOS Y NOMBRES`, `CURSO/EMO`, `PROGRAMACION`,
  `CLINICA`, `MINA`.
- Resultados: `DNI`, `APELLIDOS Y NOMBRES`, `CURSO/EMO`, `PROGRAMACION`,
  `REALIZACION`, `RESULTADO`, `CLINICA`, `NOTA`, `VENCIMIENTO ANUAL`,
  `OBSERVACION`, `MINA`.

Un resultado EMO exige `PROGRAMACION` y `CLINICA` para identificar la cita; no
deduce programacion desde realizacion. El layout anterior sigue aceptado para
cursos.

- [ ] **Step 2: Escribir tests RED de confirmacion**

Probar misma clinica/dos minas, cambio ocurrido despues del preview,
reimportacion idempotente, resultado en cita compartida y rollback total.

- [ ] **Step 3: Verificar RED**

Run:

```powershell
php artisan test tests/Unit/PersonalMinaBulkPreviewTest.php --filter clinic
php artisan test tests/Feature/PersonalMinaEmoBulkTest.php
```

- [ ] **Step 4: Ampliar lector y planner**

Resolver clinica exacta y categoria explicita. Cursos no requieren clinica.
EMO programado/resultante si. No crear catalogos durante importacion.

- [ ] **Step 5: Integrar confirmacion mediante servicio central**

No crear citas/intentos EMO directamente. Revalidar dentro de transaccion y
mantener bloqueo total del archivo ante cualquier observacion.

- [ ] **Step 6: Corregir semantica del Master**

El Master nunca crea ni completa citas en v1. Conserva `PROGRAMAR EMO` como
accion pendiente y no interpreta `SEDE`, `LUGAR` o `CLINICA` como una cita. Los
fixtures deben probar que tampoco modifica `lugar_snapshot` por esta razon.

Separar lugar del catalogo de clinica de cita. En Master, `PROGRAMAR EMO` queda
siempre como accion pendiente, tenga o no fecha. La regla fecha+clinica activa
aplica exclusivamente al formato masivo v2 y su confirmacion mediante el
servicio central.

- [ ] **Step 7: Verificar GREEN y regresion**

Run:

```powershell
php artisan test tests/Unit/PersonalMinaBulkPreviewTest.php
php artisan test tests/Feature/PersonalMinaEmoBulkTest.php
php artisan test tests/Feature/MiningExamIdentityIntegrationTest.php
```

---

### Task 6: Consulta SQL y filtros compartidos

**Files:**
- Create: `app/Modules/Personal/Support/ScheduledControlFilters.php`
- Create: `app/Modules/Personal/Support/ScheduledControlRow.php`
- Create: `app/Modules/Personal/Requests/PersonalMinaScheduledControlRequest.php`
- Create: `app/Modules/Personal/Services/PersonalMinaScheduledControlService.php`
- Create/Test: `tests/Feature/PersonalMinaScheduledControlQueryTest.php`

**Interfaces:**
- Produces: `ScheduledControlFilters::fromValidated(array $data, CarbonImmutable $today): self`.
- Produces: `paginate(ScheduledControlFilters $filters, int $perPage): LengthAwarePaginator`.
- Produces: `allForExport(ScheduledControlFilters $filters, int $maxRows = 10000): Collection`.
- Produces: `filterOptions(ScheduledControlFilters $filters): array`.
- Request keys: `scheduled_worker:string?`, `scheduled_from:Y-m-d`,
  `scheduled_to:Y-m-d`, `scheduled_category:CURSO|EMO?`,
  `scheduled_exam_id:uuid?`, `scheduled_mine_id:uuid?`,
  `scheduled_clinic_id:uuid?`,
  `scheduled_attendance_state:PROGRAMADO|ASISTIO|NO_ASISTIO|ANULADO?`,
  `scheduled_limit:25|50|100` and `scheduled_page:int>=1`.
- DTO types: normalized `?string` text/IDs, `CarbonImmutable` dates, nullable
  category/attendance enums, and integer limit/page.
- Produces immutable `ScheduledControlRow` fields exactly as defined in the
  Spec; Blade and Excel consume the same row object.
- Row identity: EMO = appointment ID; course with record =
  `personal_curso_registro.id`; legacy EMO =
  `legacy-emo-attempt:{attemptId}`; legacy course = attempt ID.
- `filterOptions()` returns visible categories, exams, mines, attendance states
  and active clinics, retaining an inactive clinic when it is already selected.

- [ ] **Step 1: Escribir tests RED de fechas**

Rango inclusivo, default Lima hoy−30/hoy+60, invertido, mayor de 366 dias y
fechas invalidas.

- [ ] **Step 2: Escribir tests RED de consulta**

Crear mas de 500 registros y probar busqueda, categoria, examen ID, mina ID,
clinica ID, estado, `scheduled_limit`, `scheduled_page`, paginacion y query
string. Probar la precedencia de estados (`ANULADO`, `NO_ASISTIO`, `ASISTIO`,
`PROGRAMADO`) en cursos y EMO. Filtro mina solo agrega minas coincidentes. Cita
compartida aparece una vez. Verificar las cuatro identidades de fila y que las
opciones no pierdan una clinica inactiva ya seleccionada.

Incluir EMO historico sin cita: clinica nula, etiqueta `Historico sin clinica`,
sin agrupacion accidental y con referencia suficiente para regularizar.

- [ ] **Step 3: Verificar RED**

Run: `php artisan test tests/Feature/PersonalMinaScheduledControlQueryTest.php`

- [ ] **Step 4: Implementar DTO, Request y query**

Aplicar filtros en SQL antes de paginar. No cargar toda la historia ni filtrar
en Blade. Para cursos deduplicar por origen existente; para EMO usar la cita.

- [ ] **Step 5: Implementar limite de exportacion**

Consultar `maxRows + 1`; si excede, lanzar ValidationException con mensaje para
reducir el rango. No truncar silenciosamente.

- [ ] **Step 6: Verificar GREEN y revisar SQL**

Run:

```powershell
php artisan test tests/Feature/PersonalMinaScheduledControlQueryTest.php
php artisan test tests/Feature/PersonalMinaHabilitacionCorrectedFlowTest.php --filter programados
```

Revisor confirma indices utilizados y ausencia de N+1.

---

### Task 7: Exportacion Excel del control

**Files:**
- Create: `app/Modules/Personal/Services/PersonalMinaScheduledControlExcelService.php`
- Create: `app/Modules/Personal/Controllers/PersonalMinaScheduledControlExportController.php`
- Create/Test: `tests/Feature/PersonalMinaScheduledControlExportTest.php`
- Integrate after Agent G, permanent owner Agent F: `routes/web.php`
- Integrate/Test after Agent G, permanent owner Agent F: `tests/Feature/WebPermissionMiddlewareTest.php`

**Interfaces:**
- Consumes: `ScheduledControlFilters` y `PersonalMinaScheduledControlService::allForExport()`.
- Produces: `download(ScheduledControlFilters $filters, Usuario $actor): StreamedResponse`.
- Produces route GET `personal.habilitacion-minera.scheduled.export`.
- `allForExport()` ignora `scheduled_limit` y `scheduled_page`; ambos quedan
  fuera de la hoja `Filtros` y del hash funcional de auditoria.

- [ ] **Step 1: Escribir tests RED del contenido XLSX**

Abrir el workbook generado con PhpSpreadsheet y comprobar encabezados, filas de
todas las paginas, filtros, minas, resultado EMO, detalle, documento con tipo
explicito string, hoja `Filtros` y nombre del archivo. Probar textos con espacios
iniciales antes de `=`, `+`, `-` y `@`, sin alterar su representacion visible.

- [ ] **Step 2: Escribir tests RED de seguridad**

Probar 403 si falta `ver_programados` o `exportar`; ambos permisos permiten.
La ruta declara dos middleware separados y el controlador comprueba ambos
permisos nuevamente. Probar exactamente 10 000 filas agrupadas (descarga) y
10 001 (rechazo sin truncado). Verificar que una exportacion exitosa persiste
actor, timestamp, filtros normalizados/hash y cantidad agrupada; un rechazo no
registra exito ni datos medicos completos.

- [ ] **Step 3: Verificar RED**

Run: `php artisan test tests/Feature/PersonalMinaScheduledControlExportTest.php`

- [ ] **Step 4: Implementar workbook y descarga**

Usar writer Xlsx a stream, estilos moderados, autofiltro, encabezado fijo y
anchos legibles. Aplicar un neutralizador comun antes de `setCellValueExplicit`
para todo texto no confiable y forzar documento como `DataType::TYPE_STRING`.
No escribir temporales en `public` ni registrar datos medicos.

- [ ] **Step 5: Integrar endpoint**

Validar con el mismo Request/DTO que la pantalla. Encadenar por separado
`web.permission:habilitacion_minera,ver_programados` y
`web.permission:habilitacion_minera,exportar`; no usar la sintaxis OR `a|b`.
El controlador invocable vuelve a autorizar ambos permisos, delega al servicio
y se mantiene delgado. Agent G entrega servicio/controlador y sus tests; luego
el propietario permanente Agent F integra ruta e inventario RBAC en un turno
secuencial. Agent G no edita esos dos archivos.

- [ ] **Step 6: Verificar GREEN**

Run:

```powershell
php artisan test tests/Feature/PersonalMinaScheduledControlExportTest.php
php artisan test tests/Feature/WebPermissionMiddlewareTest.php --filter habilitacion
```

---

### Task 8: Interfaz operativa y responsive

**Files:**
- Modify: `resources/views/personal/habilitacion-minera/index.blade.php`
- Create: `resources/js/modules/mining-scheduled-control.js`
- Modify: `resources/js/app.js` solo para importar el modulo
- Create: `resources/css/modules/mining-scheduled-control.css`
- Modify: `resources/css/app.css` solo para importar estilos
- Create/Test: `tests/Browser/render-mining-emo-scheduling.php`
- Create/Test: `tests/Browser/mining-emo-scheduling.cjs`
- Create/Test: `tests/Feature/PersonalMinaEmoViewTest.php`

**Interfaces:**
- Consumes sin modificar: paginador/opciones ya entregados por Task 4, rutas
  Tasks 4/7 y catalogo de clinicas.
- Produces: formularios de cita, filtros, tabla desktop, tarjetas mobile y enlace Excel con query actual.

- [ ] **Step 1: Escribir assertions RED de Blade**

Probar clinica obligatoria solo para EMO, filtros desde/hasta con IDs, contador,
boton condicionado por permisos, query preservado y mensaje de conflicto.

- [ ] **Step 2: Crear fixture visual y test Browser**

Escenarios: desktop 1440 px, mobile 390 px, filtros abiertos, cita compartida,
resultado restringido, historico sin clinica y estado vacio. Assert sin overflow
horizontal del viewport y controles accesibles.

- [ ] **Step 3: Reemplazar filtrado/paginacion en Blade**

Blade solo presenta el paginador del servicio; eliminar el recorte y filtros en
memoria. Cambiar titulo a `Control de asistencia de cursos y EMO`.

- [ ] **Step 4: Implementar formularios y feedback**

El select de clinica aparece para EMO y conserva seleccion ante error. Mostrar
fecha/clinica existente al bloquear. No depender solo del color.

- [ ] **Step 5: Implementar responsive y JS modular**

Tabla en desktop, tarjetas con `data-label` o lista equivalente en mobile.
Filtros apilados y exportacion ancho completo. El enlace de descarga no debe ser
interceptado por AJAX.

- [ ] **Step 6: Verificar vistas y build**

Run:

```powershell
php artisan test tests/Feature/PersonalMinaEmoWebTest.php
php artisan test tests/Feature/PersonalMinaEmoViewTest.php
php artisan view:cache
npm run build
node tests/Browser/mining-emo-scheduling.cjs
```

Expected: PASS. Si Playwright/Chrome no esta disponible, documentarlo como
pendiente; no instalar dependencias sin autorizacion. Revisar manifest/assets
generados y no atribuir eliminaciones previas a esta tarea.

---

### Task 9: Integracion, documentacion y despliegue

**Files:**
- Modify: `docs/CODEX_CONTEXT.md`
- Modify: `docs/BUSINESS_RULES_RRHH.md`
- Modify: `docs/HABILITACION_CARGA_MASIVA.md`
- Modify: `docs/OPERACION_DESPLIEGUE.md`
- Modify: `docs/ARCHITECTURE_MAP.md`
- Modify: `docs/MAPA_ARCHIVOS.md`
- Review only: todos los archivos de Tasks 1-8

**Interfaces:**
- Consumes: comportamiento integrado y evidencia de todas las tareas.
- Produces: documentacion canonica, runbook de despliegue y entrega verificable.

- [x] **Step 1: Ejecutar suite focalizada integrada**

```powershell
php artisan test tests/Feature/MiningEmoSchemaTest.php
php artisan test tests/Unit/EmoOutcomeTest.php
php artisan test tests/Feature/PersonalMinaEmoSchedulingTest.php
php artisan test tests/Feature/PersonalMinaEmoWebTest.php
php artisan test tests/Feature/PersonalMinaEmoViewTest.php
php artisan test tests/Feature/PersonalMinaEmoBulkTest.php
php artisan test tests/Feature/PersonalMinaScheduledControlQueryTest.php
php artisan test tests/Feature/PersonalMinaScheduledControlExportTest.php
php artisan test --filter "PersonalMina|MiningExam|MiningTransactionVisibility|PersonalCurso|WebPermission"
```

Expected: todos PASS en `proserge_app_test`.

- [x] **Step 2: Ejecutar controles de calidad**

```powershell
$taskPhpFiles = Get-Content storage/app/private/mining-emo-plan/php-files.txt |
    Where-Object { $_ -and (Test-Path -LiteralPath $_) }
vendor/bin/pint --test @taskPhpFiles
$taskPhpFiles | ForEach-Object {
    php -l $_
    if ($LASTEXITCODE -ne 0) { throw "php -l fallo para $_" }
}
php artisan route:list --name=personal.habilitacion-minera
php artisan view:cache
npm run build
git diff --check
```

El coordinador crea `php-files.txt` en Task 0 copiando, una ruta por linea, solo
los PHP declarados en las secciones **Files** de Tasks 1-8; antes de ejecutar
reconcilia manualmente la lista con el diff de la tarea. El archivo queda como
evidencia privada y no se versiona. No derivar la lista del worktree sucio, no
usar un glob y no aplicar Pint general sobre cambios heredados.

- [x] **Step 3: Actualizar documentacion**

Documentar reglas, campos Excel, permisos, limites, transicion del legado,
auditoria, servicios nuevos y proceso operativo. No afirmar pruebas no
ejecutadas.

- [ ] **Step 4: Ensayar migracion sobre copia realista**

Medir filas, duracion de alter/index, locks y espacio. Verificar programaciones
historicas sin clinica, resultados y archivos antes/despues. No usar produccion.

- [x] **Step 5: Preparar runbook de produccion**

Orden:

1. backup MySQL y `storage/app/private`;
2. activar mantenimiento, salvo que el ensayo confirme por escrito una
   estrategia expand-contract compatible con ambas versiones;
3. desplegar y ejecutar primero la migracion aditiva compatible con
   `php artisan migrate --force`;
4. desplegar codigo y assets compilados de la misma version;
5. ejecutar `php artisan optimize:clear` y reconstruir solo los caches usados
   por produccion;
6. con las acciones ya desplegadas, registrar clinicas oficiales y clasificar
   catalogos EMO requeridos por el corte, sin inferir nombres o lugares;
7. ejecutar en staging el caso de dos clinicas/dos minas con fixtures sin datos
   personales reales; en produccion hacer un smoke no mutante o usar solo un
   fixture expresamente autorizado, con transaccion y reconciliacion auditada;
8. probar filtro y Excel con usuario autorizado/no autorizado;
9. salir de mantenimiento y monitorear errores, tiempos y auditoria;
10. si falla, volver al codigo anterior preservando las tablas aditivas y
    documentar la reconciliacion de cualquier cita creada.

- [x] **Step 6: Revision final independiente**

Un revisor fresco compara Spec, plan y diff completo. Prioriza integridad,
permisos, concurrencia, fuga de datos, formulas Excel, queries, mobile y cambios
ajenos. Cualquier hallazgo vuelve al implementador propietario.

- [x] **Step 7: Informe de cierre**

Resumir archivos, pruebas, pendientes, riesgo manual y despliegue. Revisar
`git diff` y separar claramente cambios de esta entrega de cambios heredados.

## Matriz de propiedad para ejecucion

| Area | Implementador | Archivos exclusivos principales | Revisor |
| --- | --- | --- | --- |
| Esquema/modelos | Agent A | migracion y modelos EMO | Reviewer A |
| Resultados | Agent B | `EmoOutcome` y compatibilidad de resultados | Reviewer B |
| Citas/integridad | Agent C | `EmoAppointmentService` y servicio minero | Reviewer C |
| Bulk/Master | Agent D | cuatro servicios de importacion | Reviewer D |
| Query | Agent E | DTO/Request/query nuevos | Reviewer E |
| Web/RBAC | Agent F | controlador central, `routes/web.php`, `WebPermissionMiddlewareTest`; vuelve a integrar la ruta Excel tras Agent G | Reviewer F |
| Excel | Agent G | exportador, controlador dedicado y test propio; no toca rutas/RBAC | Reviewer G |
| UI | Agent H | Blade, JS, CSS y `PersonalMinaEmoViewTest`; no modifica controlador/rutas/tests de Agent F | Reviewer H |
| Integracion | Coordinador | docs, verificacion, reconciliacion de diff | Reviewer final |

Con cuatro slots disponibles, mantener uno para el coordinador y hasta tres
agentes. Priorizar paralelismo solo donde la tabla anterior no comparte archivos.

## Condiciones para detenerse y pedir decision

Detener la implementacion, sin asumir, si se descubre que:

- `APTO_CON_OBSERVACION` no debe habilitar segun SSOMA;
- una misma cita no puede cubrir varias minas aunque clinica y fecha coincidan;
- se requiere horario o sede, porque altera la clave de conflicto;
- la lista oficial de clinicas debe venir de otro sistema;
- una correccion de resultado completado debe formar parte de esta entrega;
- los formatos Excel oficiales no pueden agregar `CLINICA`.

En cualquier otro caso, aplicar las decisiones conservadoras del Spec y dejar
trazabilidad en la documentacion.
