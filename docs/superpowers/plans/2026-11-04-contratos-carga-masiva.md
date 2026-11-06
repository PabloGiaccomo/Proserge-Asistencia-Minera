# Plan de implementación de carga masiva de contratos firmados

> Para ejecutar las tareas usar `superpowers:subagent-driven-development` o `superpowers:executing-plans`. Cada casilla tiene un resultado comprobable.

**Objetivo.** Corregir PER-03 y permitir que RR. HH. cargue en una sola bandeja los PDF individuales de varios trabajadores.

**Arquitectura.** La carga individual y masiva comparten una escritura contractual canónica. La bandeja conserva archivos temporales privados, asociaciones revisadas y resultados por PDF. El lote valida el destino antes de vincular cada archivo.

**Tecnología.** Laravel 12, MySQL, Blade, JavaScript nativo, disco privado y FPDI instalado. Validar compatibilidad del lector con los formatos reales en el piloto con PDFs sintéticos equivalentes.

**Diseño.** [Contratos firmados](../specs/2026-11-04-correcciones-modulos-y-rrhh-masivo-design.md#contratos-firmados)

## Entrada y revisión

El formato confirmado es un PDF por trabajador. `PersonalContratoController::bulkDecision` gestiona decisiones de vencimiento y sirve de referencia de UX para selección masiva. `uploadSignedFileForContract` cubre un contrato a la vez. Los casos de mayor riesgo son PDF asignado a otro periodo, persona con dos preparaciones, dos cargas simultáneas, preparación futura con contrato vigente y error después de copiar el archivo pero antes del commit.

### Tarea 1. PER-03 y guardado canónico por periodo

**Archivos.** Modificar `app/Modules/Personal/Services/PersonalContratoService.php`, `app/Modules/Personal/Services/PersonalContratoDatoService.php`, `app/Modules/Personal/Controllers/PersonalContratoController.php` y `app/Modules/Personal/Controllers/PersonalContratoDatoController.php`. Crear `app/Modules/Personal/Services/PersonalContratoSignedFileService.php`, `app/Modules/Personal/Support/SignedContractFile.php`, `app/Models/PersonalContratoFirmadoEvento.php`, migración `2026_11_04_000500_add_signed_contract_revision_and_events.php` y `tests/Feature/PersonalContratoSignedAttachmentTest.php`.

- [ ] Probar contrato cerrado firmado con datos legados y periodo nuevo sin archivo. El nuevo queda pendiente y la persona conserva su situación laboral legítima. Añadir periodo equivalente con vínculo verificable, preparado futuro con vigente actual, periodo vencido, segundo archivo para contrato ya firmado, error SQL y dos confirmaciones concurrentes.
- [ ] Ejecutar `php artisan test --filter 'PersonalContratoSignedAttachmentTest|PersonalContratoRenewalTest|PersonalAntiguoRegularizationTest'`. Esperar fallo en las regresiones nuevas.
- [ ] Acotar `syncLegacyContractForExisting` a reutilizar archivo sólo si pertenece al mismo contrato y periodo comprobado. Preservar fecha de firma original. Para otro periodo usar preparación y archivo propio. Validar vigencia con inicio y fin en fecha de Lima.
- [ ] Implementar `PersonalContratoSignedFileService::stage(UploadedFile $file, Usuario $actor, string $operationId): SignedContractFile` y `PersonalContratoService::attachStagedSignedFileForContract(Personal $personal, PersonalContrato $contract, SignedContractFile $file, Usuario $actor, string $expectedRevision, string $operationId): array`. Bloquear trabajador, contratos por ID y datos contractuales. Revalidar capacidades, periodo, pertenencia y ausencia de firmado. Guardar referencia, revisión y evento idempotente en la misma transacción. `uploadSignedFileForContract` y la entrada de Datos de contrato delegan en esa frontera. Si hay varias preparaciones, solicitar destino exacto.
- [ ] Conservar el temporal para reintento y limpiar sólo la copia nueva sin referencia tras fallo. Probar que archivo original y contrato vigente sobreviven. Un futuro firmado queda en preparación con su fecha y conserva el vigente. La tarea 4 completa su transición al llegar el inicio.
- [ ] Ejecutar suite focalizada y de contratos. Revisar con dos conexiones MySQL y almacenamiento falso. Validar que no se retire evidencia histórica.

### Tarea 2. Recibir varios PDFs y asociarlos

**Archivos.** Crear `app/Modules/Personal/Services/PersonalContratoSignedBatchService.php`, `app/Modules/Personal/Controllers/PersonalContratoSignedBatchController.php`, `app/Models/PersonalContratoFirmadoLote.php`, `app/Models/PersonalContratoFirmadoLoteArchivo.php`, migración `2026_11_04_000600_create_signed_contract_upload_batches.php` y `tests/Feature/PersonalContratoSignedBatchTest.php`. Modificar `routes/web.php`.

- [ ] Probar PDF falso, corrupto, cifrado, exceso de tamaño, nombre arbitrario, documento con cero inicial, documento extranjero y dos contratos posibles. Probar propiedad de lote, permiso de carga, autorización para vista previa y caducidad. Una vista previa deja intactos contrato y Personal.
- [ ] Ejecutar `php artisan test --filter PersonalContratoSignedBatchTest` y comprobar fallo por superficie aún ausente.
- [ ] Implementar `stageFiles(Usuario $actor, string $loteId, array $files): array`, `assign(Usuario $actor, string $loteId, string $fileId, string $contractId): array` y `preview(Usuario $actor, string $loteId): array`. Lote privado con propietario, revisión y TTL propuesto de 2 horas. Fila con ID de cliente, hash SHA-256, bytes, destino y periodo/revisión esperados. Validar tipo real y estructura, mantener bytes originales y nombres privados. El nombre sólo sugiere, cada asociación queda confirmada por RR. HH. El buscador devuelve preparaciones autorizadas paginadas. La escritura exige `personal.subir_contrato_firmado`. La visualización del PDF exige además `personal.descargar_documentos` y vínculo autorizado a lote o trabajador.
- [ ] Probar que otro actor, un lote caducado o una solicitud sin capacidad no permite leer archivos ni estado de contrato. El permiso de vista previa de temporales propios y la descarga de un firmado definitivo se verifican en sus respectivas rutas.

### Tarea 3. Confirmar y mostrar resultado por PDF

**Archivos.** Extender servicio, controlador y test del lote. Crear `resources/views/personal/contratos/carga-firmados.blade.php`, `resources/js/modules/contratos-carga-firmados.js`, `tests/Browser/contratos-carga-firmados.cjs`. Modificar `resources/js/app.js`, acceso desde contratos/vencimientos y documentación.

- [ ] Probar selección de 90 archivos con cargas divididas, interrupción tras una tanda, doble confirmación, archivo repetido con otro destino, contrato editado después de vista previa y permiso revocado. Un resultado parcial muestra cuáles quedan listos y permite reintentar sólo pendientes.
- [ ] Implementar `confirm(Usuario $actor, string $loteId, string $previewRevision, array $fileIds): array` y `results(Usuario $actor, string $loteId): array`. Fijar el manifiesto revisado con IDs, hash de bytes, contrato y revisión. Limitar hasta 20 archivos por solicitud, dividido también por bytes efectivos. Procesar por contrato con transacción propia. La clave de operación identifica el reintento. Un 409 exige revisar el destino actualizado. Registrar el resultado junto al evento contractual.
- [ ] Mostrar arrastrar/seleccionar, avance, sugerencia editable, vista previa privada y trabajador + periodo antes de confirmar. Mostrar «Firmado con inicio futuro» cuando corresponda. Registrar revisión y decisión humana, con errores por archivo y descarga sólo según permiso. Programar limpieza de temporales vencidos con verificación de referencias.
- [ ] Medir `upload_max_filesize`, `post_max_size`, `max_file_uploads`, tiempo, RAM y disco de cPanel con muestras autorizadas o sintéticas. Aplicar el mínimo efectivo del servidor y el límite individual de la ruta existente. Probar 413, 419, timeout y sesión recuperada. Ejecutar suite focalizada, prueba visual 1440/390, `view:cache`, build y `git diff --check`.

### Tarea 4. Completar la activación de los firmados con inicio futuro

**Archivos.** Extender `PersonalContratoService`, `PersonalContratoSignedBatchController`, `PersonalContratoSignedAttachmentTest`, `resources/views/personal/contratos/carga-firmados.blade.php` y `routes/web.php`.

- [ ] Probar carga futura con contrato vigente, activación antes del inicio, fecha de inicio en Lima, repetición, periodo vencido y solapamiento con otro contrato aún vigente. Sólo la preparación firmada y elegible en la fecha autorizada puede activarse. La nota de resultado distingue archivo adjunto de estado laboral.
- [ ] Ejecutar el test focalizado. La conciliación programada actual sólo cierra contratos vencidos. `activateNextContract` prepara un reingreso y no activa una preparación firmada existente.
- [ ] Como propuesta de ciclo laboral, incluir «Listos para activar» en la bandeja con selección, revisión y confirmación. Implementar `activatePreparedSignedContract(Personal $personal, PersonalContrato $contract, Usuario $actor, string $expectedRevision, string $operationId): PersonalContrato`. Reutilizar la transición extraída de la carga individual y su permiso contractual, con fechas, firma, solapamiento, actor y bloqueo de tarea 1. El lote confirma una vez cada contrato elegible. Los conflictos quedan en revisión y no cambian al trabajador.
- [ ] Pasar regresión de renovación y reingreso. Validar con RR. HH. el momento de esta transición al revisar el diseño y comprobar que se pueda resolver desde la bandeja sin volver a adjuntar el PDF.
