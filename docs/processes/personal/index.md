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

## Edición de ficha y solicitud de actualización

- En **Editar trabajador**, RR. HH. puede guardar los datos generales y cambiar las oficinas o talleres que existan en sus catálogos. Retirar una ubicación interna desactiva su asignación y registra quién hizo el cambio. Las minas se gestionan en **Habilitación minera**. La edición web y la API de Personal conservan las asignaciones si el cliente omite el campo de minas. Si lo envía, la API devuelve 422 y el editor muestra cómo gestionar la asignación.
- Editar y revisar datos bancarios, pensionarios, de quinta categoría y biométricos exige también el permiso directo **Ver datos sensibles** de Personal o Ingresos. Los cambios generales conservan los datos sensibles omitidos. Descargar la huella o los PDF de ficha requiere el permiso sensible además del permiso de descarga o exportación. El Excel de fichas conserva las columnas generales para quien sólo puede exportar.
- El enlace temporal anterior valida vencimiento y revocación antes de guardar el borrador o enviar. El comprobante posterior al envío está disponible hasta 24 horas y muestra únicamente el estado de recepción.
- Desde **Editar trabajador → Solicitar actualización**, RR. HH. puede generar, copiar y revocar un enlace individual de 72 horas para datos personales, nacimiento, contacto, domicilio, estudios, tallas y nuevos familiares. Comparte el enlace directamente con el trabajador. Los datos vigentes se muestran únicamente en la revisión interna. El borrador se guarda como propuesta cifrada y la bandeja lo muestra después del envío con **Actualización recibida** y **Completa para revisión**.
- En Ingresos, el permiso **Aprobar** permite aceptar u observar la revisión específica. Si la ficha cambió desde la emisión, solicita otra actualización después de revisar el conflicto. Aceptar aplica los campos propuestos y conserva el estado laboral, los contratos, las minas y los documentos de la persona. La observación conserva el envío y su motivo para auditoría.
- La comparación muestra los cambios generales y cada familiar propuesto. Los campos omitidos conservan su valor. Agregar familiares conserva las filas vigentes. Si un documento ya está registrado con los mismos datos, se informa la coincidencia y se evita duplicarlo. Si existen diferencias o una coincidencia de nombre, parentesco y fecha sin documento, RR. HH. debe revisarlo en la ficha interna antes de aceptar. La fecha de nacimiento de un familiar es opcional cuando se desconoce.
- Los adjuntos de actualización y la renovación del enlace dentro de la misma propuesta observada quedan para la siguiente entrega. Los enlaces de contacto y domicilio emitidos antes de esta ampliación mantienen sus campos y su revisión.

Antes de habilitar el flujo, aplicar las migraciones incrementales 2026_11_04_000200 y 2026_11_04_000300 sobre el esquema de Ingresos existente. Las altas históricas reciben propósito ALTA. Se requiere la clave de aplicación para recuperar las revisiones cifradas.

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
