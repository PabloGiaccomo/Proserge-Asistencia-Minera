# Contexto general para Codex

Ultima auditoria: 2026-06-10.

## Naturaleza del sistema

El sistema Proserge no es solo RR.HH. Es una aplicacion corporativa interna que
conecta procesos de RR.HH., reclutamiento, operaciones, logistica, contabilidad,
administracion, costos, SSOMA, gerencia y futuras areas. El sistema ya opera
con informacion real y debe tratar cada cambio como potencialmente sensible.

Los usuarios incluyen personal de oficina, supervisores y trabajadores con poca
familiaridad con sistemas. La experiencia debe ser clara, rapida y cercana al
lenguaje que ya usan en Excel, reportes y formatos manuales.

## Stack actual

- Backend: Laravel 12, PHP 8.2.
- Base de datos: MySQL.
- Frontend: Blade renderizado por servidor, JavaScript nativo, CSS propio,
  Vite/Tailwind como build global.
- Archivos: disco privado `local` en `storage/app/private`.
- Excel: PhpSpreadsheet.
- PDF: Dompdf, FPDF, FPDI.
- Autenticacion web: sesion propia con `web.auth`.
- API: tokens Bearer propios con middleware `auth.token`.
- Permisos: matriz modulo/accion en `app/Support/Rbac`.

## Flujos centrales existentes

Personal es el modulo mas avanzado. Incluye:

- registro manual de trabajador;
- links temporales de ficha publica;
- carga de documentos, firma y huella;
- revision, observacion y aprobacion de ficha;
- estados documentales;
- descarga individual y masiva de documentos;
- datos de contrato y contrato firmado;
- contratos laborales con historial inamovible;
- personal antiguo nuevo y regularizacion de personal antiguo existente;
- renovacion individual y reingreso;
- vencimientos, decision de renovacion/no renovacion;
- cierre manual de no renovado y cese controlado;
- habilitacion minera base, examenes, intentos, convalidaciones e importacion
  desde master Excel.

Otros flujos conectados:

- RQ Mina: paradas, areas, plan operativo, transporte y autocompletados.
- Herramientas por parada: listas semanales por grupos.
- RQ Proserge: requerimientos de personal y asignacion.
- Man Power: grupos de trabajo por parada y destino.
- Asistencia y faltas: marcacion, cierre, reapertura y correcciones.
- Evaluaciones: desempeno, supervisor y promedios.
- Bienestar: bloqueos, gestacion, restricciones, descanso medico.
- Seguridad: usuarios, roles, permisos, scope por mina y notificaciones.

## Trabajadores antiguos y nuevos

Trabajadores antiguos:

- pueden ya existir o registrarse desde cero;
- pueden estar activos, cesados, con datos incompletos o pendiente de
  regularizacion;
- no deben duplicarse por DNI/documento;
- deben conservar documentos, contratos, motivos de cese, origen de registro e
  historial;
- deben poder ampliar su historial sin rehacer estructuras.

Trabajadores nuevos:

- pueden iniciar como postulantes o personal en proceso;
- completan ficha, documentos, revision y contrato;
- no deben quedar `ACTIVO` sin contrato firmado vigente;
- pueden iniciar habilitacion minera durante el proceso si la operacion lo
  permite, pero eso no debe activar laboralmente al trabajador.

## Reglas ya definidas

- Seguridad: GERENTE respeta los permisos configurados. Solo roles ACTIVO
  aportan permisos y privilegios de scope; los roles adicionales activos se
  combinan con el principal. ADMIN/SUPERADMIN activos conservan el mecanismo
  global. Un privilegio de scope no concede por si solo marcacion o cierre de
  asistencia. Auditoria en curso: `docs/security/authorization-audit.md`.
- Seguridad (2026-10-09): en la pantalla de roles cada check es independiente.
  Un formulario enviado reemplaza la matriz completa; los checks ausentes se
  guardan como false. Un false explicito no se convierte en Dashboard, Ver,
  otra accion de Administrar ni un permiso heredado entre modulos. Web recarga
  los roles en cada peticion; no exige cerrar sesion. Otro rol ACTIVO puede
  seguir concediendo el mismo permiso. Solo ADMIN/SUPERADMIN activos conservan
  acceso total. Las preferencias de notificaciones no conceden permisos de rol.
- Estado laboral y contrato son responsabilidades del modulo Personal/Contratos.
- Habilitacion minera solo gestiona trabajador-mina-examenes-intentos.
- Habilitacion minera no modifica cargo, supervisor, ficha, documentos
  personales, renovaciones, estado laboral ni contratos.
- El master Excel de habilitacion es apoyo de importacion con preview y
  confirmacion; no es fuente absoluta de verdad y por ahora no crea trabajadores
  nuevos automaticamente.
- Una vez confirmado por un usuario autorizado, el Master si reemplaza de forma
  completa la configuracion activa de examenes y requisitos por mina. Lo que no
  aparece en el archivo queda inactivo y fuera de nuevas habilitaciones. Los
  intentos, resultados y snapshots anteriores se conservan solo como historial
  interno para no romper auditoria ni relaciones de base de datos.
- En habilitacion minera se separa estado general de accion siguiente: tareas
  del Excel como "programar examen" deben quedar como `EN_PROCESO` mas accion
  pendiente, no como estado final.
- Un trabajador-mina solo puede ser `HABILITADO` cuando la mina tiene examenes
  configurados, estos fueron generados para el trabajador y todos quedaron
  resueltos, vigentes, convalidados o `NO_APLICA`.
- Al alcanzar o superar el maximo configurado con resultado desaprobado, debe
  mostrarse como `NO_HABILITADO` para esa mina, pero puede registrar nuevos
  intentos. El conteo conserva ambos valores, por ejemplo `3/2`.
- Convalidaciones son sugeridas por el sistema y confirmadas por el usuario; no
  se aplican automaticamente.
- Correccion minera 2026-10-06: curso historico sin mina no se propaga; alias
  confirmado del Master conserva identidad; fallo de intento revierte toda la
  importacion. Un duplicado no sobrescribe nota y un resultado anterior no
  sustituye uno posterior. Renovacion pendiente/desaprobada conserva aprobacion
  vigente hasta su vencimiento. Convalidacion conserva restricciones de origen;
  una realizacion nueva archiva la evidencia anterior antes de limpiar su origen.
  Ejecucion y pendientes:
  `docs/superpowers/plans/2026-10-06-habilitacion-minera-ejecucion.md`.
- Correccion minera 2026-10-07: editar/desactivar asignacion, generar examenes,
  sincronizar cursos y recalcular guardan coordinadamente con historial en
  transaccion. Las escrituras de examen bloquean primero su asignacion.
  Fechas operativas de habilitacion y Master usan app.display_timezone (Lima),
  sin cambiar timestamps ni timezone global. Una fecha DATE no se convierte
  a otro dia por UTC; vence hoy sigue vigente hasta terminar el dia operativo.
- Organizar examenes administra identidad principal, variantes y aliases
  confirmados globales o por mina, sin fusionar IDs ni mover historial. Excel
  resuelve el requisito de destino; ambiguedades y parecidos no confirmados se
  observan. Convalidar entre variantes sigue exigiendo permisos, vigencia, nota
  cuando corresponde y confirmacion. Despliegue: `docs/FTP_ORGANIZAR_EXAMENES.md`.
- La convalidacion admite el mismo examen o equivalentes autorizados por
  requisito de mina destino. Conserva la nota si existe; solo exige nota cuando
  el destino la requiere y verifica su minimo. Guarda snapshot de origen/destino.
- Los cursos y EMO del historial conservan el trabajador y la mina de origen.
  Se aplican directamente solo a esa mina, tambien si se asigna posteriormente.
  Aplicarlos en otra mina requiere convalidacion permitida y confirmada.
- Documentos tienen estado propio y pueden estar pendientes, cargados,
  observados, aprobados o no aplicar.
- Contratos antiguos y cerrados son solo lectura.
- Renovaciones nunca sobreescriben el contrato anterior.
- Excel historicos pueden servir de referencia, pero no siempre son fuente de
  verdad.
- Las importaciones deben decir que crearon, actualizaron, omitieron o fallaron.

## Citas EMO y control (2026-10-08)

`EmoAppointmentService` concentra programar, completar, anular, reprogramar y
regularizar citas, con bloqueo por trabajador y revision monotona. Una fecha
solo admite una cita no anulada; varias minas comparten cita exclusivamente con
el mismo `examen_id` y enlaces explicitos. El Master no crea citas.
`EmoOutcome` conserva los cuatro resultados medicos; restriccion/observacion
exigen detalle y aprueban. El intento conserva el resultado generico.

`PersonalMinaScheduledControlService`, `ScheduledControlFilters` y
`ScheduledControlRow` comparten la consulta entre pantalla y Excel. Filtros SQL
antes de paginar, sin recorte de 500 filas; exportacion completa de hasta
10 000 filas fisicas, independiente de pagina/limite. La clinica historica no
se inventa. Permisos directos: `configurar` para clinicas/categoria, `programar`
para programar/reprogramar/anular/regularizar, `registrar` para completar y
`ver_programados` mas `exportar` para descargar. Ver reglas en
`docs/BUSINESS_RULES_RRHH.md`, formatos en `docs/HABILITACION_CARGA_MASIVA.md`
y corte de produccion en `docs/OPERACION_DESPLIEGUE.md`.

## Adopcion y UX

La aplicacion debe ayudar a migrar desde Excel sin imponer lenguaje tecnico.
Tablas, filtros y reportes deben ser escaneables. Los formularios largos deben
dividirse o esconderse detras de acciones claras. Las pantallas deben indicar el
siguiente paso esperado: revisar ficha, falta contrato, subir firmado, decidir
renovacion, cerrar contrato, asignar mina, registrar examen, etc.

## Crecimiento y archivo historico

Se espera crecimiento en:

- documentos personales;
- contratos firmados;
- archivos de examenes;
- masters Excel;
- fichas y PDFs;
- historial laboral y de procesos;
- asistencia, faltas y evaluaciones.

Debe planearse separacion logica entre activo, historico y archivado. El
archivado debe mantener referencia en MySQL, permisos, auditoria, fecha de
movimiento, origen, responsable y mecanismo de recuperacion.

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
El dashboard conserva `es_requerido_cobertura` y saldos retornables positivos.
