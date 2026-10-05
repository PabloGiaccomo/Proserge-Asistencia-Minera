# Personal

Ingreso, ficha, documentos, contratos, importacion y regularizacion del trabajador.

## Procesos

| Proceso | BPMN | Visual | Alcance |
|---|---|---|---|
| Ingreso publico de colaborador | [BPMN](./ingreso-publico.bpmn) | [SVG](./ingreso-publico.svg) | Recibe una ficha publica con clave diaria, la revisa y la acepta o marca como contrato no firmado. |
| Completar y revisar ficha temporal | [BPMN](./ficha-temporal-revision.bpmn) | [SVG](./ficha-temporal-revision.svg) | El trabajador abre un enlace ya activo, completa la ficha y RR.HH. aprueba u observa. Activar y enviar el enlace son operaciones anteriores independientes. |
| Gestion de documentos personales | [BPMN](./documentos-personales.bpmn) | [SVG](./documentos-personales.svg) | Carga, revisa y clasifica documentos privados sin confundir CARGADO con APROBADO. |
| Contrato, renovacion, reingreso y cierre | [BPMN](./ciclo-contractual.bpmn) | [SVG](./ciclo-contractual.svg) | Crea contratos independientes, activa solo con firmado vigente y conserva historicos inamovibles. |
| Importacion y regularizacion: mapa | [BPMN](./importacion-y-regularizacion.bpmn) | [SVG](./importacion-y-regularizacion.svg) | Son entradas distintas: Excel muta por lotes; registrar antiguo crea una persona; regularizar existente conserva su identidad. |
| Importar datos de Personal desde Excel | [BPMN](./importar-personal-excel.bpmn) | [SVG](./importar-personal-excel.svg) | Detecta formato Master General, contactos o datos personales y aplica cambios por lotes con resumen. No hay preview en el metodo inspeccionado. |
| Registrar trabajador antiguo | [BPMN](./registrar-personal-antiguo.bpmn) | [SVG](./registrar-personal-antiguo.svg) | Crea un trabajador historico nuevo despues de validar documento y descartar duplicados. |
| Regularizar trabajador existente | [BPMN](./regularizar-personal-existente.bpmn) | [SVG](./regularizar-personal-existente.svg) | Mantiene la identidad de Personal, sincroniza contrato si se solicita y devuelve advertencias y marca pendiente. |
| Activar enlace temporal de ficha | [BPMN](./activar-enlace-ficha.bpmn) | [SVG](./activar-enlace-ficha.svg) | Crea, renueva o reutiliza un enlace de ficha de Personal; no implica enviarlo por correo. |
| Enviar enlace de ficha por correo | [BPMN](./enviar-enlace-ficha.bpmn) | [SVG](./enviar-enlace-ficha.svg) | Envia un enlace activo a un correo valido y registra cuando se envio; una configuracion mailer log/array impide el envio. |

## Actores y datos

- Trabajador o postulante
- RR.HH.
- Sistema
- Correo Outlook

## Evidencia principal

- `routes/web.php`
- `app/Modules/Personal/Controllers/PublicPersonalIngresoController.php`
- `app/Modules/Personal/Controllers/PersonalIngresoController.php`
- `app/Modules/Personal/Services/PersonalIngresoService.php`
- `tests/Feature/PersonalIngresoPublicFlowTest.php`
- `app/Modules/Personal/Services/PersonalFichaService.php`
- `app/Modules/Personal/Controllers/PublicPersonalFichaController.php`
- `app/Modules/Personal/Controllers/PersonalFichaController.php`
- `tests/Feature/PersonalFichaObservedResubmitTest.php`
- `tests/Feature/PersonalFichaApproveContractDatesTest.php`
- `app/Modules/Personal/Controllers/PersonalDocumentoController.php`
- `app/Modules/Personal/Services/PersonalDocumentoDownloadService.php`
- `tests/Feature/PersonalDocumentoEstadoTest.php`
- `tests/Feature/PersonalDocumentoDownloadTest.php`
- `app/Modules/Personal/Services/PersonalContratoService.php`
- `app/Modules/Personal/Services/PersonalContratoDatoService.php`
- `app/Modules/Personal/Controllers/PersonalContratoController.php`
- `app/Models/PersonalContrato.php`
- `tests/Feature/PersonalContratoServiceTest.php`
- `tests/Feature/PersonalContratoRenewalTest.php`
- `tests/Feature/PersonalContratoNotRenewedClosureTest.php`
- `app/Modules/Personal/Services/ImportPersonalService.php`
- `app/Modules/Personal/Services/PersonalAntiguoService.php`
- `app/Modules/Personal/Controllers/PersonalController.php`
- `routes/api.php`
- `app/Modules/Personal/Services/PersonalService.php`

## Duplicidades o inconsistencias

- PersonalIngreso y PersonalFicha resuelven canales de captura similares con estados y controladores distintos.
- Existen endpoints de contrato firmado tanto en PersonalContratoDatoController como en PersonalContratoController.
- PersonalService, PersonalFichaService y PersonalContratoService comparten decisiones sobre el estado laboral.
- La carga de Personal por Excel aplica cambios por chunks sin preview ni transaccion global. La decision de negocio confirmada de bloquear archivos con errores no esta representada como implementada en ImportPersonalService.
- La carga de Personal por Excel aplica cambios por chunks sin preview ni transaccion global. No extender automaticamente a este importador la regla de bloqueo total acordada para cursos/EMO sin confirmacion de negocio.

## Por confirmar

- Momento formal en que un postulante pasa a trabajador en proceso.
- Documentos que bloquean el avance y roles que pueden ver remuneraciones.
