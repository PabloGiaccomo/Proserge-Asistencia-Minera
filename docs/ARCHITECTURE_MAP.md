# Mapa de arquitectura actual

Este documento complementa `docs/GUIA_TECNICA_TRASPASO.md` y
`docs/MAPA_ARCHIVOS.md`.

## Entrada y capas transversales

- `routes/web.php`: superficie web principal. Casi todas las rutas protegidas
  usan `web.auth` y `web.permission:modulo,accion`.
- `routes/console.php`: scheduler para limpieza de notificaciones y recordatorio
  de herramientas por parada.
- `app/Http/Middleware/WebAuthenticate.php`: sesion web.
- `app/Http/Middleware/EnsureWebPermission.php`: permisos de pantalla.
- `app/Http/Middleware/AuthenticateToken.php`: API Bearer.
- `app/Http/Middleware/EnsureMinaScope.php`: scope por mina para APIs que lo
  usen.
- `app/Support/Rbac/PermissionCatalog.php`: modulos y acciones disponibles.
- `app/Support/Rbac/PermissionMatrix.php`: normalizacion, merge y evaluacion de
  permisos.

## Modulos principales

### Personal

Modelos:

- `Personal`, `PersonalFicha`, `PersonalFichaLink`,
  `PersonalFichaFamiliar`, `PersonalFichaArchivo`,
  `PersonalDocumentoEstado`, `PersonalContrato`,
  `PersonalContratoDato`, `PersonalMina`, `PersonalMinaHistorial`,
  `PersonalMinaExamen`, `PersonalMinaExamenIntento`.

Servicios:

- `PersonalService`: alta, edicion, estados, cese/reactivacion base.
- `PersonalFichaService`: ficha, links, documentos de ficha, revision,
  observacion, aprobacion, correo y regularizacion.
- `PersonalContratoService`: contrato laboral, activacion, cierre, anulacion,
  renovacion, reingreso, decision y snapshots.
- `PersonalContratoDatoService`: datos editables de contrato vigente.
- `PersonalContratoFormatoService`: formatos Excel de contrato.
- `PersonalDocumentoDownloadService`: ZIP documental por trabajador.
- `PersonalAntiguoService`: alta y regularizacion de personal antiguo.
- `ImportPersonalService` y `ExportPersonalService`: Excel de personal.
- `PersonalMinaHabilitacionService`: asignacion mina, requisitos, examenes,
  intentos, estados y convalidaciones.
- `PersonalMinaExcelImportService`: lectura de master Excel de habilitacion.
- `EmoAppointmentService`: agregado de cita EMO, enlaces por mina, resultado
  atomico, bloqueo por trabajador, revision y regularizacion del legado.
- `EmoClinicService` y `ExamOperationalCategoryService`: catalogos explicitos
  con permisos; categoria con motivo/historial, sin clasificacion por nombre.
- `PersonalMinaScheduledControlService`: union SQL de citas, cursos y legado;
  filtros compartidos y agrupacion de filas fisicas antes de paginar/exportar.
- `PersonalMinaScheduledControlExcelService`: XLSX autenticado de la misma
  consulta, textos seguros y auditoria minima en `PersonalEmoExportAudit`.
- `PersonalFichaMacroExtractor`, `PersonalFichaExportService`,
  `PersonalFichaPdfService`: extraccion, exportacion y ficha PDF.

Controladores:

- `PersonalPageController`: pantallas de personal.
- `PublicPersonalFichaController`: link publico de ficha.
- `PersonalFichaController`: temporales, importacion macro, revision y PDF.
- `PersonalDocumentoController`: documentos, estados, descargas.
- `PersonalContratoController`: contratos, vencimientos, renovacion, reingreso,
  no renovacion.
- `PersonalContratoDatoController`: datos de contrato y firmado.
- `PersonalContratoFormatoController`: formatos Excel de contrato.
- `PersonalMinaHabilitacionController`: habilitacion minera y master Excel.
- `PersonalMinaScheduledControlExportController`: descarga con comprobacion
  separada de `ver_programados` y `exportar`, delegando validacion/consulta.

Vistas principales:

- `resources/views/personal/index.blade.php`.
- `resources/views/ficha-public/show.blade.php`.
- `resources/views/personal/fichas/*`.
- `resources/views/personal/documentos/*`.
- `resources/views/personal/contratos/*`.
- `resources/views/personal/habilitacion-minera/index.blade.php`.
- `resources/views/personal/antiguo/*`.

### RQ Mina y herramientas

- Modelos: `RQMina`, `RQMinaDetalle`, `RQMinaTransporte`,
  `RQMinaActividadGrupo`, `RQMinaActividad`, `RQMinaActividadTurno`,
  `RQMinaActividadTransporte`, `RQMinaFieldOption`.
- Servicio: `RQMinaService`.
- Controladores: `RQMinaPageController`, `RQMinaController`.
- Vistas: `resources/views/rq-mina`.
- Herramientas por parada: `ParadaHerramientaService`,
  `ParadaHerramientaPageController`, vistas `resources/views/parada-herramientas`.

### Operaciones, asistencia y evaluaciones

- RQ Proserge: `RQProsergeService`, controladores y vistas `rq-proserge`.
- Man Power: `GrupoTrabajoService`, `ManPowerParadasService`, vistas
  `man-power`.
- Asistencia: `AsistenciaService`, `AsistenciaCierreService`, vistas
  `asistencia`.
- Faltas: `FaltasService`, `CorregirFaltaService`, vistas `faltas`.
- Evaluaciones: `EvaluacionDesempenoService`,
  `EvaluacionSupervisorService`, `PromedioDesempenoService`, vistas
  `evaluaciones`.

### Seguridad y notificaciones

- Operaciones EPP: `EppScopeService`, `EppOperationPolicy` y capacidades
  directas en `PermissionCatalog`/`PermissionMatrix`. Reglas de alcance y
  compatibilidad historica: `docs/EPP_AUTORIZACION.md`.
- Catalogo e inventario EPP: `EppCatalogService`, `EppInventoryService` y
  `EppInventoryPageController`; apertura y ajustes con revision/confirmacion,
  bloqueo de existencias y libro append-only. Flujo: `docs/EPP_INVENTARIO.md`.
  `EppInventoryConfirmationService` coordina consumo atomico del token y
  resultado persistido en `epp_inventario_confirmaciones` para reintentos.
- Firma de entregas EPP: `EppOperationConfirmationService` coordina archivo
  privado previo, bloqueos e idempotencia; `EppAcceptanceService` conserva el
  resumen canonico y verifica hashes. `EppSignaturePayload` valida y normaliza
  PNG/tinta; `EppCanonicalPayload` serializa tanto revision como evidencia.
  Contrato y recuperacion: `docs/EPP_INVENTARIO.md`; testigo:
  `docs/EPP_AUTORIZACION.md`.

- Usuarios, roles y permisos: `Usuario`, `Rol`, `UsuarioRol`,
  `UsuarioMinaScope`, `RoleManagementService`, vistas `seguridad`.
- Notificaciones: `NotificationService`, `NotificationInboxService`,
  `NotificationRecipientResolverService`, modelos `Notification*`.
- Preferencias de usuario y rol: `NotificationUserSetting`,
  `NotificationRolePreference`.

### Catalogos

- Minas, talleres, oficinas y paraderos viven en `app/Modules/Catalogos` y
  `resources/views/catalogos`.
- Minas tambien se conectan con `PersonalMina`, RQ Mina, Man Power y alcance de
  usuario.

## Migraciones sensibles

- `2026_04_28_000800_create_personal_fichas_tables.php`: base de fichas,
  links, archivos y familiares.
- `2026_06_01_000100_create_personal_contratos_table.php`: historial laboral.
- `2026_06_01_000200_add_grupo_trabajo_id_to_asistencia_encabezado.php`: tuvo
  conflicto de indice duplicado `uq_asistencia_grupo` en local; revisar antes
  de tocar.
- `2026_06_05_*`: documentos, contratos inamovibles, personal antiguo,
  regularizacion, renovaciones, decisiones y no renovacion.
- `2026_06_06_*`: requisitos/examenes mineros, intentos y refinamientos.

## Tests existentes

- Ciclo laboral: `PersonalLifecycleStateTest`,
  `ImportPersonalLifecycleStateTest`.
- Ficha publica: `PersonalFichaObservedResubmitTest`,
  `PersonalFichaApproveContractDatesTest`.
- Documentos: `PersonalDocumentoEstadoTest`,
  `PersonalDocumentoDownloadTest`.
- Contratos: `PersonalContratoServiceTest`,
  `PersonalContratoRenewalTest`,
  `PersonalContratoExpiryDecisionTest`,
  `PersonalContratoNotRenewedClosureTest`,
  `PersonalContratoFormatoServiceTest`.
- Personal antiguo: `PersonalAntiguoRegistrationTest`,
  `PersonalAntiguoRegularizationTest`.
- Habilitacion minera: `PersonalMinaHabilitacionBaseTest`,
  `PersonalMinaExamenesMinerosTest`,
  `PersonalMinaHabilitacionCorrectedFlowTest`.
- Operaciones: `RQMinaApiTest`, `RQProsergeApiTest`, `ManPowerApiTest`,
  `AsistenciaApiTest`, `FaltasApiTest`, `EvaluacionesApiTest`.
- Seguridad: `WebPermissionMiddlewareTest`, `UsuarioNotificationTest`.

## Archivos delicados

No tocar sin pruebas focalizadas:

- `PersonalFichaService.php`.
- `PersonalContratoService.php`.
- `PersonalMinaHabilitacionService.php`.
- `PersonalMinaExcelImportService.php`.
- `ImportPersonalService.php`.
- `PersonalService.php`.
- `PersonalResource.php`.
- `routes/web.php`.
- `PermissionCatalog.php` y `PermissionMatrix.php`.
- `resources/views/personal/index.blade.php`.
- `resources/views/personal/fichas/temporales.blade.php`.
- `resources/views/personal/habilitacion-minera/index.blade.php`.
- `resources/views/ficha-public/show.blade.php`.

## Areas de negocio

- RR.HH. y reclutamiento: Personal, fichas, documentos, contratos,
  renovaciones, personal antiguo.
- Operaciones: RQ Mina, Man Power, asistencia, habilitacion minera.
- Logistica: herramientas por parada, transporte, necesidades operativas.
- SSOMA: examenes, habilitacion, requisitos criticos, bloqueos.
- Contabilidad/costos: contratos, remuneraciones, costos hora, reportes.
- Gerencia: dashboards, decisiones, exportaciones y supervision transversal.
- Administracion: usuarios, roles, permisos, catalogos.

## Acciones y botones por modulo

No existe aun un inventario central de botones; las acciones estan distribuidas
en Blade. El patron real detectado es:

- Personal: crear trabajador, registrar antiguo, regularizar antiguo, editar,
  cesar, activar/reingresar, ver documentos, ver contratos, descargar formatos,
  exportar, importar, revisar ficha, aprobar, observar, reenviar observacion,
  activar link temporal, enviar correo, ampliar link, descargar documentos.
- Contratos: editar datos vigentes, subir contrato firmado, renovar, reingresar,
  registrar decision, preparar renovacion, cerrar no renovado, anular contrato
  de forma controlada.
- Habilitacion minera: asignar mina, cambiar estado, configurar examenes,
  agregar/quitar requisito, registrar intento, convalidar, marcar no aplica,
  recalcular, importar master Excel.
- RQ Mina: crear parada, editar, enviar, eliminar, plan operativo, importar
  plan, autocompletar opciones de campo, agregar transporte.
- Herramientas por parada: guardar lista, actualizar pedido, enviar lista,
  recordar supervisor.
- Man Power: crear grupo, agregar/quitar personal, ver parada/grupo.
- Asistencia: marcar, marcar masivo, cerrar, reabrir.
- Faltas: corregir, anular.
- Seguridad: crear/editar usuarios, cambiar estado, actualizar password,
  gestionar notificaciones, scope de mina, crear/duplicar/editar roles.

Riesgo UX: varias acciones criticas aun usan confirmaciones nativas o botones
pequenos en tablas. Futuras mejoras deben agrupar acciones por contexto y
pedir motivo en modales operativos cuando haya impacto historico.

## Tipos de archivos y procesamiento

| Tipo | Uso actual | Validacion recomendada | Datos extraibles | No asumir | Almacenamiento |
|---|---|---|---|---|---|
| Excel de personal | Importacion/actualizacion de trabajadores | extension, columnas esperadas, preview, duplicados | DNI, nombres, telefonos, cargo, estado intencion | que `ACTIVO` sea verdad sin contrato firmado | guardar import temporal y resultado |
| Macro/ficha Excel | Generar links de ficha | plantilla esperada y mapeo | datos de ficha y contrato inicial | que datos incompletos sean finales | guardar original si es respaldo |
| Excel master habilitacion | Minas, examenes, intentos | hojas validas, limite de filas, preview | trabajador, mina, examen, resultado, fechas | que sea fuente de verdad sin confirmacion | procesar por lotes; guardar resumen |
| Excel plan operativo | RQ Mina futuro | formato aun pendiente | areas, SAIT, sectores, turnos, transporte | estructura unica permanente | guardar archivo y version si aplica |
| PDF documentos | documentos personales y contratos | mime, extension, tamano, antivirus si se incorpora | metadatos basicos; texto solo si se implementa OCR/parser | que el PDF este correcto solo por existir | disco privado; posible archivado secundario |
| Imagenes | foto, huella, documentos escaneados | mime imagen, tamano, resolucion minima | vista previa y metadatos | que la imagen sea legible sin revision | disco privado; compresion futura controlada |
| Archivos de examen | evidencias de intentos | mime permitido y tamano | fecha/costo solo si viene estructurado | resultado del examen sin registro manual | asociar a intento, no solo trabajador |

Cada importacion o carga masiva debe responder:

- que archivo fue leido;
- que hojas/columnas detecto;
- que registros creo, actualizo, omitio o fallo;
- si los datos son fuente de verdad o solo apoyo;
- que terminologia historica conviene conservar para adopcion.

## EPP firmado por lotes (2026-10-08)

Flujo vigente y despliegue: [EPP_FIRMA_OPERATIVA.md](./EPP_FIRMA_OPERATIVA.md).
`EppOperationPageController` prepara lotes; `EppOperationConfirmationService`
confirma firma/inventario/saldos; `EppCorrectionService` compensa sin borrar.
`EppKardexService`, `EppReceiptPdfService`, `EppQrCodeService` y
`EppEvidenceController` producen evidencia autenticada y verificable.
Comando: `epp:backfill-legacy-traceability [--dry-run]`.
Correccion operativa 2026-10-09: `EppDeliverySignatureService` agrega firma al
formulario habitual de Entregas y cambios, con catalogo de Identificacion de
items, sin exigir almacen ni apertura. Registrar/cerrar preparan una revision;
solo la firma confirma el movimiento. Origen `ENTREGAS_CAMBIOS` y almacen nulo
distinguen este flujo de las operaciones de inventario existentes. Editar/borrar
historial permanece protegido. Kardex y PDF incluyen evidencia privada.
Devolucion del catalogo: `EppCatalogReturnController` ->
`EppCatalogReturnService` (seleccion y snapshots de saldos) ->
`EppDeliverySignatureService` (aceptacion y descuento atomicos). Cada detalle
referencia su entrega origen sin reemplazar su firma. Solo saldo cero cierra
la entrega; no afecta inventario ni exige almacen.
El dashboard conserva `es_requerido_cobertura` y saldos retornables positivos.

La descarga operativa usa `EppKardexExcelService` y la plantilla SGC-FOR-59,
con filas compactadas por fecha, movimiento y lote firmado, sin perder unidades.
`EppKardexMovementService` comparte la lectura de movimientos con la pantalla;
`EppKardexTemplateColumns` unifica columnas y `EppKardexRowGrouper` distribuye
una unidad por columna y fila sin mezclar lotes. Las firmas se normalizan solo
en la copia exportada. Solo se dibuja la ultima firma valida del trabajador
relacionada con la hoja y el rango en el recuadro inferior, nunca en las filas.
La pantalla pagina en servidor despues de agrupar (20/50/100). El agrupador
indexa rangos de cantidad y materializa solo la ventana pedida; la lectura
de metadatos sigue siendo completa para contar, con origen `lazy(100)`.
La respuesta paginada omite entregas crudas y el listado tecnico de operaciones;
el exportador conserva su rango completo y sus controles de evidencia.
`EppDeliveryDeclaration` versiona el texto incluido en nuevos resumenes firmados.
`SearchTerms` en Shared centraliza las busquedas por palabras sin orden; no
reemplaza controles de permiso o alcance. El filtro operativo de mina en EPP
consulta paradas RQ solapadas con el intervalo de uso, no habilitacion minera.
