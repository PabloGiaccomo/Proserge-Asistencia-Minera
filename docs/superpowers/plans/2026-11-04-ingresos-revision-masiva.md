# Plan de implementación de revisión masiva de Ingresos

> Para ejecutar las tareas usar `superpowers:subagent-driven-development` o `superpowers:executing-plans`. Cada casilla tiene un resultado comprobable.

**Objetivo.** Aceptar altas y actualizaciones en una sola bandeja con comparación, permisos y resultado por persona.

**Arquitectura.** El controlador de Ingresos usa los servicios de aceptación individual. Un lote persistido fija selección, revisión y resolución de cada fila. Cada fila ejecuta su propia transacción.

**Tecnología.** Laravel 12, MySQL, Blade y JavaScript nativo.

**Diseño.** [Personal e Ingresos](../specs/2026-11-04-correcciones-modulos-y-rrhh-masivo-design.md#personal-e-ingresos)

## Dependencias y casos de revisión

Requiere la protección PER-01/02 y la comparación `PersonalIngresoRevisionService::compare` del [plan Personal](2026-11-04-personal-actualizacion-segura.md). Las altas usan `PersonalIngresoService::accept` con los datos contractuales por fila que exige el flujo individual. Un contrato en preparación se entrega a la bandeja de firmados por su ID real. Se propone hasta 50 seleccionadas por confirmación y 10 por solicitud de ejecución. Revisar conflicto entre dos altas para el mismo documento, pérdida de permiso entre tandas, edición de ficha base, respuesta perdida y cambio de filtro con selección activa.

### Tarea 1. Construir una vista previa de la selección exacta

**Archivos.** Modificar `app/Modules/Personal/Controllers/PersonalIngresoController.php`, `resources/views/personal/fichas/ingresos.blade.php` y `routes/web.php`. Crear `app/Modules/Personal/Services/PersonalIngresoBatchReviewService.php`, `app/Models/PersonalIngresoLote.php`, `app/Models/PersonalIngresoLoteFila.php`, migración incremental `2026_11_04_000400_create_personal_ingreso_review_batches.php` y `tests/Feature/PersonalIngresoBatchReviewTest.php`.

- [ ] Probar IDs repetidos, fila ajena, estado observado, dos fichas para la misma identidad, campos sensibles sin capacidad y cambio de filtro. La vista previa deja la ficha, los contratos y habilitaciones intactos.
- [ ] Ejecutar `php artisan test --filter PersonalIngresoBatchReviewTest` en `proserge_app_test`. Verificar que falla por ausencia de lote y vista previa.
- [ ] Implementar `preview(Usuario $actor, array $ingresoIds): PersonalIngresoLote`. Fijar propietario, expiración propuesta de 2 horas, IDs explícitos, propósito, revisión, hash de comparación y preparación contractual confirmada por fila. Las filas incompletas, duplicadas o sin permiso muestran su motivo. Un alta que coincide con una persona existente queda para decisión individual sobre actualización, regularización o reingreso. Guardar datos de revisión sensibles cifrados y exponer sólo proyecciones autorizadas. Cada cambio de selección o preparación genera otra revisión de vista previa.
- [ ] Pasar el test y revisar que los controles de selección muestran el número exacto de filas. Confirmar sólo filas listas y visibles en el resumen, con selección acumulada entre páginas indicada de forma explícita.

### Tarea 2. Confirmar y reintentar filas individualmente

**Archivos.** Extender `PersonalIngresoBatchReviewService`, `PersonalIngresoService`, `PersonalIngresoController`, `routes/web.php` y el test de la tarea 1.

- [ ] Probar lote mixto de alta, actualización y fila inválida. Simular dos confirmaciones de la misma ficha, conflicto de revisión, cambio de rol y respuesta perdida. El reintento devuelve el resultado de la fila ya aplicada y no crea persona ni contrato duplicado.
- [ ] Ejecutar el test focalizado y verificar el fallo esperado en confirmación y recuperación.
- [ ] Implementar `confirm(Usuario $actor, string $loteId, string $revision, array $filaIds): array` y `results(Usuario $actor, string $loteId): array`. Limitar la tanda a 10 filas del conjunto revisado. Vincular una clave única a lote y fila. Revalidar permisos y estado dentro del servicio, con bloqueo de persona/ficha/ingreso y orden consistente con el plan Personal. Para altas sin persona, proteger unicidad de documento normalizado y recuperar la colisión como conflicto de la fila. Llamar `acceptUpdate` sólo para actualización. Persistir cambio y resultado de éxito juntos. Tras rollback, registrar fallo recuperable por separado.
- [ ] Pasar `PersonalIngresoBatchReviewTest`, `PersonalIngresoPublicFlowTest` y los tests de propuesta. Verificar que el enlace público no confirma y que cada actualización conserva contratos, estado laboral, minas e historial. La fila sensible requiere comparación y confirmación explícita de un revisor autorizado.

### Tarea 3. Completar el recorrido para RR. HH.

**Archivos.** Crear `resources/views/personal/fichas/partials/ingresos-batch-review.blade.php` y `tests/Browser/ingresos-batch-review.cjs`. Extender bandeja, resultados y documentación de Ingresos.

- [ ] Probar seleccionar → revisar → confirmar → consultar resultado → reintentar pendientes en 1440 y 390 px. Incluir pérdida de conexión después del primer bloque, revocación de permiso y cambio de revisión en una fila.
- [ ] Mostrar «Aceptar N revisadas», progreso y resultados por persona con siguiente acción. Al volver, restaurar filtros permitidos. Si se pierde respuesta, consultar el lote antes de reintentar. La vista general usa indicadores y datos mínimos. Una fila sensible abre comparación autorizada.
- [ ] Ejecutar prueba visual, `php artisan view:cache`, `git diff --check` y build sólo si se modifican fuentes compilables. Aceptación operativa con datos sintéticos. RR. HH. puede resolver varias fichas en la misma revisión y distingue qué quedó aplicado y qué necesita atención.
