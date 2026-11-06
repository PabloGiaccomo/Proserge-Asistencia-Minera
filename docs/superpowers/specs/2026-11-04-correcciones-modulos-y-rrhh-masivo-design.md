# Diseño propuesto de correcciones y trabajo masivo de RR. HH.

Propuesta del 4 de noviembre de 2026. La auditoría aporta los 24 casos que deben cubrir los planes. [Auditoría transversal](../reports/2026-11-04-auditoria-modulos-transversal.md)

## Decisiones del usuario

- RR. HH. conserva la edición directa de Personal y puede generar un enlace para que un trabajador existente actualice su ficha.
- La ficha enviada aparece en Ingresos para revisión y aceptación de RR. HH. El flujo protege los datos sensibles.
- RR. HH. puede aceptar varios Ingresos juntos y cargar varios contratos firmados en una operación. Cada trabajador entrega su propio PDF firmado.
- Transporte debe permitir completar la operación desde la pantalla. Los grupos y formularios afectados deben poder usarse en móvil.

## Personal e Ingresos

**Personal → Solicitar actualización → trabajador completa y envía → Ingresos → RR. HH. revisa y acepta.** La actualización es una propuesta vinculada por ID a la persona existente. La ficha vigente se mantiene disponible hasta la aceptación. La bandeja separa tipo de solicitud, completitud y estado de revisión. Un envío listo muestra **«Actualización recibida · Completa para revisión»**. La aceptación muestra los cambios aplicados. Si otra persona editó los mismos datos entre comparación y confirmación, el sistema pide revisar de nuevo.

El enlace individual permite guardar borradores en el servidor. El secreto es aleatorio, se guarda por hash, se puede revocar y deja de permitir cambios al enviar. Se propone una vigencia inicial de 72 horas. RR. HH. copia y comparte el enlace por su canal habitual. Abrirlo ofrece los campos autorizados para esa solicitud y el mínimo contexto necesario. Una página de confirmación neutra permite comprobar el envío sin volver a mostrar la ficha.

La actualización general contempla contacto, domicilio, datos personales permitidos, estudios, tallas y familiares. La identidad documental queda ligada a la persona. Cargo, fechas laborales, cese, supervisor, contratos, minas y resultados de habilitación quedan a cargo de sus flujos respectivos. Se propone que solicitudes sensibles sean separadas, con verificación de identidad independiente del enlace antes de introducir datos y revisión por un rol autorizado. Los valores vigentes de banco, pensión, firma, huella y documentos privados permanecen fuera del enlace. Hasta definir ese segundo control, los cambios sensibles se tramitan en RR. HH. autenticado. Editar Personal aplica el mismo permiso al preparar el HTML, actualizar campos y servir archivos.

La selección masiva parte de filas explícitas con contador. La vista previa separa **altas nuevas**, **actualizaciones** y **casos por corregir**. Permite comparar varias fichas juntas y confirmar una revisión concreta por fila. Si una fila requiere atención sensible, completar datos o resolver un conflicto, aparece con el motivo y queda fuera de la ejecución hasta resolverlo. Una alta usa su flujo contractual. Una actualización aplica el parche de ficha sin preparar otro contrato. Los archivos aportados conservan su estado de revisión documental.

Se propone revisar hasta 50 filas por confirmación y ejecutar en tandas de 10. Se pueden procesar más selecciones consecutivas. Cada fila conserva su resultado e identificador de operación. Si una falla, las demás pueden terminar y RR. HH. reintenta las pendientes. Permiso, revisión y asociación se vuelven a comprobar al ejecutar. El resumen distingue aceptadas, observadas, omitidas, pendientes y con error.

## Contratos firmados

Una bandeja de pendientes permite seleccionar o arrastrar varios PDF individuales y ver el avance de cada carga. El nombre del archivo puede sugerir un trabajador. RR. HH. confirma el trabajador, el número de contrato y el periodo. Para archivos con nombres genéricos, puede buscar y asociar manualmente en la misma bandeja. La vista previa es privada, temporal y autorizada. Un PDF legible queda disponible para revisar. La decisión sobre la firma y el periodo la toma RR. HH. al verificar el documento.

Al confirmar, cada PDF se guarda en el contrato exacto que lo espera. Un archivo duplicado, un destino ambiguo o ya firmado, un periodo cambiado y un archivo inválido tienen resultados independientes. El resultado distingue **Guardado**, **Firmado con inicio futuro**, **Requiere revisión** y **Error**. Una renovación futura conserva el contrato vigente. El firmado anterior permanece ligado a su periodo. Se propone que un PDF futuro quede adjunto en preparación y aparezca en «Listos para activar» al cumplir fecha y condiciones del contrato. RR. HH. puede confirmar varios elegibles desde la bandeja. La carga usa la regla contractual común a individual y masiva.

Se propone cargar hasta 20 archivos por solicitud y respetar los límites efectivos del servidor. El operador puede seleccionar más. Los archivos se dividen por cantidad y tamaño. Antes de habilitar se miden límites de cPanel y PHP, tiempos, espacio y tamaño de los PDFs de prueba. Los binarios se guardan en el disco privado. La confirmación y los resultados usan el lote, su propietario, revisión, vigencia y un identificador por archivo.

## Transporte y asistencia móvil

Cada acción de Transporte valida su permiso y la mina del servicio. Reubicar valida origen y destino, alcance y cupo en una operación. Si el destino falla, el pasajero conserva su asignación original. En la web, el servicio de personal se puede crear y corregir con conductor, placa, capacidad y alcance. Antes de confirmar se muestra lo que falta. Al corregir errores se conservan parada, plan, fecha, turno y lo escrito que sea seguro conservar.

Mi Asistencia ofrece un recorrido móvil por fecha, turno y grupo. Cuando hay varios, se elige el grupo de forma explícita. Un grupo sin responsable, cerrado, cancelado o de consulta muestra el motivo y las acciones disponibles. El modelo autorizado proporciona el ID de grupo, el detalle de asistencia y las capacidades a la vista. La capacidad de consultar todos los grupos amplía la lectura. Registrar y cerrar requieren sus permisos o la excepción vigente para el responsable real. Cierre, marcas, reapertura y corrección de falta comparten el orden de bloqueo GrupoTrabajo → AsistenciaEncabezado → AsistenciaDetalle → Falta.

Los formularios de Personal, intentos mineros, Transporte y Mi Asistencia deben mostrar el error donde se corrige. Deben mantener el grupo, actividad o fila y los valores recuperables. Una respuesta perdida se distingue de un error rechazado mediante consulta del resultado o reintento seguro. Los archivos que el navegador no pueda restituir se piden de nuevo de forma explícita. Las verificaciones incluyen 390 y 1440 píxeles, teclado, navegación fija, fechas de Lima, pantalla vacía, error de validación, sesión vencida, conexión lenta y doble toque.

## Correcciones complementarias

Logística filtra Servicios y sus opciones según mina autorizada y conserva evidencia ante errores de guardado. Herramientas separa la edición de la solicitud de la entrega y recepción. Man Power conserva el historial de retiros y respeta Duplicar como permiso propio. Las correcciones de faltas actualizan ejecución y las evaluaciones sin asistencia válida pasan a revisión por su evaluador original. El dashboard API aplica los permisos de origen. Perfil distingue acceso a minas de habilitación y muestra evaluaciones autorizadas o su indisponibilidad. Roles confirma el guardado con auditoría aunque se retrase un aviso. Bienestar guarda el autor y motivo de cada cambio. El login API limita intentos.

## Restricciones y entrega

- Laravel 12, PHP 8.2+, MySQL, Blade y JavaScript del repositorio. Reutilizar las dependencias instaladas.
- Recargar permisos en cada escritura, aplicar alcance canónico por mina y conservar contratos e historial.
- Disco privado para documentos. Minimizar datos en vistas previas, registros técnicos, notificaciones, URLs y respuestas. Cifrar propuestas y snapshots sensibles cuando se almacenen.
- Las migraciones son incrementales y se prueban desde el esquema histórico y actual. Los casos de prueba usan datos sintéticos y una base aislada.
- Confirmar el éxito tras persistir la operación. El error de un aviso o de una tarea secundaria se presenta por separado.
- Registrar actor, fecha, operación y resultado sin copiar documentos o datos sensibles a la bitácora general. Las pruebas de concurrencia usan dos conexiones y barreras controladas.
- Esta entrega documenta el diseño, las tareas y las pruebas de aceptación. [Índice de planes](../plans/2026-11-04-correcciones-modulos-indice.md)
