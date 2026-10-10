# Mapa de archivos y puntos de cambio

Esta guia responde la pregunta: "donde debo tocar para cambiar una funcion".
No enumera `vendor` ni archivos generados; agrupa todos los archivos propios por
responsabilidad.

## Entrada y configuracion

| Archivo/carpeta | Responsabilidad |
|---|---|
| `bootstrap/app.php` | Registra rutas, aliases de middleware y excepciones API |
| `routes/web.php` | Superficie web, nombres de ruta y permisos |
| `routes/api.php` | API v1 y autenticacion Bearer |
| `routes/console.php` | Scheduler |
| `config/*.php` | Base, mail, filesystem, cache, sesion y servicios |
| `.env` | Configuracion real por ambiente; nunca versionar |
| `.env.example` | Plantilla de ambiente; debe actualizarse |
| `composer.json` | Dependencias PHP y scripts |
| `package.json` | Dependencias frontend |
| `vite.config.js` | Entradas y compilacion Vite |
| `phpunit.xml` | Ambiente y base de pruebas |

## Capas compartidas

| Archivo/carpeta | Responsabilidad |
|---|---|
| `app/Models` | Persistencia y relaciones Eloquent |
| `app/Shared/Support/ApiResponse.php` | Contrato de respuesta API |
| `app/Shared/Services/DisponibilidadPersonalService.php` | Disponibilidad reutilizada al asignar personal |
| `app/Shared/Concerns/UsesAuthenticatedUser.php` | Resolucion comun de usuario API |
| `app/Support/Rbac/PermissionCatalog.php` | Pantallas, acciones y roles base |
| `app/Support/Rbac/PermissionMatrix.php` | Normaliza, combina y evalua permisos |
| `app/Providers/AppServiceProvider.php` | Blade `@allowed`, roles base y campana |

## Middleware

| Archivo | Uso |
|---|---|
| `AuthenticateToken.php` | Valida Bearer token API |
| `WebAuthenticate.php` | Valida sesion web y refresca permisos |
| `EnsureWebPermission.php` | Deniega acciones web sin permiso |
| `EnsureMinaScope.php` | Restringe API por mina cuando se aplica |

## Convencion de modulos

Para cualquier carpeta de `app/Modules/<Modulo>`:

- `Controllers/*PageController.php`: interfaz Blade.
- `Controllers/*Controller.php`: API.
- `Services/*.php`: negocio y transacciones.
- `Requests/*.php`: validacion.
- `Resources/*.php`: salida API.
- `Policies/*.php`: autorizacion de dominio.
- `Support/*.php`: utilitarios especificos.

## Personal y contratos

| Necesidad | Archivos principales |
|---|---|
| Listado, filtros y estados | `PersonalPageController`, `PersonalService`, `resources/views/personal/index.blade.php` |
| Crear/editar/cesar/reactivar | `PersonalPageController`, `PersonalService`, `PersonalContratoService` |
| Formulario publico | `PublicPersonalFichaController`, `PersonalFichaService`, `resources/views/ficha-public` |
| Temporales y links | `PersonalFichaController`, `PersonalFichaService`, `resources/views/personal/fichas` |
| Revision/aprobacion/observacion | `PersonalFichaController`, `PersonalFichaService` |
| Firma, huella y archivos | `PersonalFichaService`, `PersonalDocumentoController` |
| Documentos del trabajador | `PersonalDocumentoController`, `resources/views/personal/documentos` |
| Datos de contrato | `PersonalContratoDatoController`, `PersonalContratoDatoService` |
| Historial/snapshots | `PersonalContratoController`, `PersonalContratoService` |
| Formatos Excel | `PersonalContratoFormatoController`, `PersonalContratoFormatoService`, `resources/contract-templates` |
| Importar/exportar | `ImportPersonalService`, `ExportPersonalService`, `PersonalFichaExportService`, `PersonalFichaMacroExtractor` |
| PDF ficha | `PersonalFichaPdfService` |
| Citas EMO y clinicas | `EmoAppointmentService`, `EmoClinicService`, `PersonalEmoCita`, `ClinicaEmo`, `PersonalEmoCitaHistorial` |
| Categoria operativa de examen | `ExamOperationalCategoryService`, `ExamenMineroCategoriaHistorial` |
| Control y filtros compartidos | `PersonalMinaScheduledControlService`, `ScheduledControlFilters`, `ScheduledControlRow`, `PersonalMinaScheduledControlRequest` |
| Excel de asistencia | `PersonalMinaScheduledControlExcelService`, `PersonalMinaScheduledControlExportController`, `PersonalEmoExportAudit` |
| Interfaz de citas y asistencia | `resources/views/personal/habilitacion-minera/_scheduled-control.blade.php`, `_emo-dialogs.blade.php`, `resources/js/modules/mining-scheduled-control.js`, `resources/css/modules/mining-scheduled-control.css` |
| Correo de ficha | `OutlookMailService`, `PersonalFichaEmailTemplateService`, `storage/scripts/send-outlook-email.ps1` |
| Catalogos de ficha | `PersonalFichaCatalog`, `PersonalNormalizer`, `PersonalExportConfig` |

Modelos: `Personal`, `PersonalMina`, `PersonalBloqueo`, `PersonalFicha`,
`PersonalFichaLink`, `PersonalFichaFamiliar`, `PersonalFichaArchivo`,
`PersonalContrato` y `PersonalContratoDato`.

## RQ Mina y plan operativo

| Necesidad | Archivos principales |
|---|---|
| Listar/crear/editar/enviar | `RQMinaPageController`, `RQMinaController`, `RQMinaService` |
| Validacion API | `StoreRQMinaRequest`, `UpdateRQMinaRequest`, `SendRQMinaRequest` |
| Respuesta API | `RQMinaResource` |
| Permisos de dominio | `RQMinaPolicy` |
| Vistas | `resources/views/rq-mina` |
| Estilos | `resources/css/modules/rq-mina.css` |

Modelos: `RQMina`, `RQMinaDetalle`, `RQMinaTransporte`,
`RQMinaActividadGrupo`, `RQMinaActividad`, `RQMinaActividadTurno`,
`RQMinaActividadTransporte` y `RQMinaFieldOption`.

## Herramientas por parada

| Necesidad | Archivos principales |
|---|---|
| Listado, detalle, guardar y enviar | `ParadaHerramientaPageController`, `ParadaHerramientaService` |
| Vistas | `resources/views/parada-herramientas` |
| Estilos | `resources/css/modules/parada-herramientas.css` |
| Alertas programadas | `ParadaHerramientasDeadlineReminderCommand` |

Modelos: `ParadaHerramientaLista`, `ParadaHerramientaGrupo`,
`ParadaHerramientaItem`.

## RQ Proserge, Man Power y asistencia

| Dominio | Controladores/servicios | Vistas |
|---|---|---|
| RQ Proserge | `RQProsergeController`, `RQProsergePageController`, `RQProsergeService` | `resources/views/rq-proserge` |
| Man Power | `ManPowerController`, `ManPowerPageController`, `ManPowerParadasService`, `GrupoTrabajoService` | `resources/views/man-power` |
| Asistencia | `AsistenciaController`, `AsistenciaPageController`, `AsistenciaService`, `AsistenciaCierreService` | `resources/views/asistencia` |
| Faltas | `FaltasController`, `FaltasPageController`, `FaltasService`, `CorregirFaltaService` | `resources/views/faltas` |

Modelos relacionados: `RQProserge`, `RQProsergeDetalle`, `GrupoTrabajo`,
`GrupoTrabajoDetalle`, `AsistenciaEncabezado`, `AsistenciaDetalle`, `Falta`.

## Seguridad y notificaciones

| Necesidad | Archivos principales |
|---|---|
| Usuarios | `UsuarioPageController`, `UsuarioController`, `Usuario` |
| Roles | `RolPageController`, `RolController`, `RoleManagementService`, `Rol` |
| Scope de minas | `UsuarioMinaScopeController`, `UsuarioMinaScope`, `EnsureMinaScope` |
| Catalogo/matriz de permisos | `PermissionCatalog`, `PermissionMatrix`, vistas `resources/views/seguridad` |
| Emitir notificacion | `NotificationService` |
| Resolver receptores | `NotificationRecipientResolverService` |
| Bandeja | `NotificationInboxService`, controladores de notificaciones |
| Campana | `AppServiceProvider`, `resources/views/partials/header.blade.php` |
| Limpieza | `NotificationsCleanupExpiredCommand` |

Modelos: `NotificationType`, `NotificationEvent`, `NotificationRecipient`,
`NotificationPreference`, `NotificationRolePreference`,
`NotificationUserSetting`.

## Catalogos, evaluaciones y bienestar

| Dominio | Ubicacion |
|---|---|
| Minas/talleres/oficinas/paraderos | `app/Modules/Catalogos`, `resources/views/catalogos` |
| Evaluaciones | `app/Modules/Evaluaciones`, `resources/views/evaluaciones` |
| Bienestar/bloqueos | `app/Modules/Bienestar`, `resources/views/bienestar` |
| Dashboard | `app/Modules/Dashboard`, `resources/views/dashboard` |
| Mi asistencia | `app/Modules/MiAsistencia`, `resources/views/mi-asistencia` |
| Perfil | `app/Modules/Perfil`, `resources/views/perfil` |

## Frontend global

| Archivo | Responsabilidad |
|---|---|
| `resources/views/layouts/app.blade.php` | HTML base y carga Vite |
| `resources/views/partials/sidebar.blade.php` | Menu y acceso por permisos |
| `resources/views/partials/header.blade.php` | Cabecera, usuario y notificaciones |
| `resources/css/app.css` | Tokens, componentes y layout global |
| `resources/css/modules/*.css` | Estilos de dominios complejos |
| `resources/js/app.js` | Sidebar, menus, modales, filtros, paginacion |
| `public/build` | Resultado generado por Vite |
| `public/img`, `public/favicon.ico` | Marca publica |

## Base de datos

- `database/setup/001_initial_schema.sql`: esquema inicial mas amplio.
- `database/setup/002...015`: extensiones historicas para instalaciones manuales.
- `database/migrations`: cambios incrementales aplicados con Artisan.
- `database/seeders`: datos sembrados por Laravel, si se amplian.

Antes de modificar una tabla, buscar su nombre tanto en `database/setup` como
en `database/migrations` y en todos los modelos/servicios:

```bash
rg "nombre_tabla" app database tests
```

## Pruebas

| Flujo | Prueba principal |
|---|---|
| RQ Mina | `tests/Feature/RQMinaApiTest.php` |
| RQ Proserge | `tests/Feature/RQProsergeApiTest.php` |
| Man Power | `tests/Feature/ManPowerApiTest.php` |
| Asistencia | `tests/Feature/AsistenciaApiTest.php` |
| Faltas | `tests/Feature/FaltasApiTest.php` |
| Evaluaciones | `tests/Feature/EvaluacionesApiTest.php`, `EvaluacionSupervisorApiTest.php` |
| Herramientas | `tests/Feature/ParadaHerramientaServiceTest.php` |
| Contratos | `tests/Feature/PersonalContratoServiceTest.php`, `PersonalContratoFormatoServiceTest.php` |
| Ficha | `tests/Feature/PersonalFichaApproveContractDatesTest.php`, `PersonalFichaObservedResubmitTest.php` |
| Permisos/notificaciones | `tests/Feature/WebPermissionMiddlewareTest.php`, `UsuarioNotificationTest.php` |

## Recetas de localizacion rapida

Para agregar una accion nueva a una pantalla:

1. Ruta y middleware en `routes/web.php`.
2. Accion disponible en `PermissionCatalog`.
3. Boton protegido con `@allowed`.
4. Metodo de controlador.
5. Regla de negocio en servicio.
6. Prueba Feature.

Para agregar una notificacion:

1. Crear/actualizar tipo en la base.
2. Emitir desde el servicio/controlador con contexto.
3. Definir modulo/accion requerida y `mine_id` cuando corresponda.
4. Probar destinatarios, preferencias y deduplicacion.

Para agregar un documento:

1. Guardar en disco `local` privado.
2. Registrar metadata en tabla.
3. Servirlo por controlador autenticado y autorizado.
4. Añadir eliminacion/retencion y prueba.

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
`EppCatalogReturnController` y `EppCatalogReturnService` permiten seleccionar
saldos por trabajador en `/epps/devoluciones`, con cantidades parciales y una
firma compartida. `epps/catalog-return.blade.php` reutiliza `epp-operation.js`;
la confirmacion conserva la evidencia de origen y cierra solo saldos agotados.
El dashboard conserva `es_requerido_cobertura` y saldos retornables positivos.

`EppKardexExcelService` conserva la plantilla `resources/epp-templates/SGC-FOR-59.xlsx`
y exporta codigos N/C/I/P/D y firma valida sobre fondo blanco.
La firma se dibuja solo una vez en el recuadro inferior de la hoja, usando la
ultima firma valida relacionada dentro del rango; las filas quedan sin firma.
`EppKardexMovementService`, `EppKardexTemplateColumns` y `EppKardexRowGrouper`
comparten movimientos, columnas y agrupacion entre pantalla y Excel: una unidad
por columna/fila, agrupada por fecha, movimiento y lote, sin mezclar firmas.
`EppPageController::kardexPersonal` valida `page` y `per_page` (20/50/100);
`EppService::buildKardexMatrix` pagina las filas agrupadas en el servidor.
El pie de la tabla Kardex en `logistica/index.blade.php` consulta cada pagina
sin recarga completa, descarta respuestas obsoletas y permite reintentar.
Se omite el bloque tecnico sin borrar operaciones, evidencia o constancias.
`EppDeliveryDeclaration` centraliza el texto/version congelados antes de firmar
nuevas entregas. `app/Shared/Support/SearchTerms.php` unifica busquedas por
palabras sin orden en EPP y Logistica. `EppService::pageData` filtra la mina por
paradas RQ solapadas, conservando el alcance autorizado del usuario.
