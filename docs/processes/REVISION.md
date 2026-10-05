# Revision de BPMN

Actualizado: 2026-09-28. Estado: revision funcional en curso.

## Corregido en primera pasada

- Contratos: falta de firma vuelve a adjuntar sobre el mismo contrato; decision pendiente no provoca cierre. Documentadas condiciones de NO_RENOVADO.
- Examenes: salidas separadas para pendiente/programado, aprobado, desaprobado y no asistencia.
- Asistencia: el scheduler de ausencias no es requisito para cerrar. Reabrir vuelve a REGISTRADO y anula faltas automaticas activas vinculadas.
- Herramientas: enviar la lista no requiere esperar un recordatorio automatico.
- Planes: archivar termina en ARCHIVADO.
- Man Power: cancelar no termina como grupo listo para asistencia.
- Disposicion: separacion vertical de ramas que compartian columna/carril.
- Ingreso publico: ambas decisiones incorporan o vinculan Personal; no firmado no equivale a rechazo.
- Documentos: NO_APLICA tiene rama propia sin archivo, con rechazo de documentos obligatorios que no permiten esa excepcion.

## Automatizaciones independientes

No se eliminan funcionalidades al quitar pasos del flujo manual. Sus entradas
reales estan en routes/console.php:

- AsistenciaAusentesPorHorarioCommand: cada minuto, sin superposicion.
- ParadaHerramientasDeadlineReminderCommand: 07:30.
- HabilitacionMineraSyncTemporalStatusesCommand: 00:10.
- NotificationsCleanupExpiredCommand: 02:10.

Generados cuatro diagramas independientes con inicio temporizado, condiciones,
evidencia por tarea y limites de transaccion. Son resumenes de cada ejecucion;
los bucles y las excepciones internas se explican en las tareas, no se modelan
todavia como subprocesos expandidos.

## Decision operativa confirmada

El usuario confirmo el 2026-09-27 que reutilizar un examen entre minas requiere
convalidacion confirmada por un usuario. No debe propagarse automaticamente.
Esta decision sustituye la ambiguedad anterior; no cambia codigo funcional.
Queda pendiente reconciliar las instrucciones generales antiguas y verificar
todos los caminos funcionales en una tarea independiente.

## Trabajo pendiente

### Seguridad, notificaciones, asistencia y bienestar: revision del 2026-09-28

- Seguridad: login web, login API y cierre web son tres solicitudes distintas.
  El web limita intentos y exige estado ACTIVO; el API emite token Bearer de 12
  horas y, en el controlador inspeccionado, no verifica estado ni limita
  intentos. No existe ruta de logout/revocacion API. El cierre web no revoca
  tokens API.
- Notificaciones: emision y consumo de bandeja son independientes. La emision
  puede omitir el evento si faltan tablas, tipo activo, mensaje o destinatarios;
  si persiste, crea o reutiliza el evento y las copias por destinatario en
  transaccion. Leer, abrir y archivar no son pasos obligatorios posteriores a
  cada emision. La purga programada sigue en BPMN propio y conserva la politica
  confirmada de borrado definitivo 15 dias despues del vencimiento.
- Asistencia: marcar, cerrar y reabrir quedaron en tres BPMN. El cierre completa
  detalles ausentes y crea faltas vinculadas sin duplicarlas; la reapertura
  cambia CERRADO a REGISTRADO y anula faltas automaticas activas vinculadas.
  La reapertura no recibe actualmente un motivo operativo de entrada.
- Bienestar: registrar, editar y anular bloqueos quedaron separados. Al crear,
  el controlador puede usar el primer usuario de la tabla si no identifica al
  actor de sesion, con riesgo de trazabilidad. Editar no notifica; registrar y
  anular si solicitan notificacion. Anular no exige estado previo ACTIVO.

Total actual: 59 BPMN. Los once nuevos de estas familias validan y tienen SVG.
Los diagramas compuestos reemplazados se retiraron para evitar dos versiones
contradictorias del mismo flujo. Los riesgos descritos requieren tareas
funcionales independientes; esta revision solo modifica docs/processes/.

### Personal: enlace temporal de ficha

- Activar enlace y enviarlo por correo tienen BPMN independientes. La
  activacion crea, renueva o reutiliza el link; el envio exige mailer real,
  correo valido y URL activa.
- Completar/revisar ficha empieza cuando el trabajador abre un link existente.
  `resolveToken` distingue invalid, disabled, expired, readonly y edit; solo
  edit permite continuar. Aprobar inhabilita el link y prepara el estado
  contractual pendiente; observar permite correccion cuando el link lo admite.

En esa fase habia 52 BPMN. Los tres diagramas de esta familia pasan el parser y
tienen SVG.

### Personal: importacion y regularizacion

- El mapa conjunto ahora enlaza tres BPMN independientes: importacion Excel,
  alta historica y regularizacion de una persona existente.
- `ImportPersonalService::import` detecta tres formatos y aplica cambios por
  lotes, sin previsualizacion ni transaccion unica del archivo en el metodo
  examinado. Sus filas omitidas se informan en un resumen.
- El alta antigua comprueba documento duplicado antes de crear Personal. La
  regularizacion existente bloquea la fila, puede sincronizar contrato y
  devuelve advertencias o deja `pendiente_regularizacion`.
- La regla de bloqueo total confirmada para carga de cursos/EMO no se extiende
  automaticamente al importador de Personal. Queda por confirmar.

En esa fase habia 50 BPMN. Los cuatro BPMN revisados de esta familia pasan el
parser y tienen SVG.

### Man Power: revision del 2026-09-28

- Crear grupo, reubicar integrante y cancelar grupos del dia tienen BPMN
  independientes. La creacion puede incluir integrantes iniciales, pero no
  fuerza una reubicacion o cancelacion posterior.
- Reubicacion exige misma parada/fecha, acceso a ambos grupos y ausencia de
  asistencia iniciada; conserva snapshots y retira pasajero activo asociado.
- Cancelacion por SAIT/fecha/plan rechaza el lote si algun grupo tiene
  asistencia. Si procede, retira integrantes y pasajeros activos antes de
  guardar CANCELADO dentro de la misma transaccion.
- Agregar integrante y copiar un grupo ya tienen BPMN propios. La copia omite
  integrantes inactivos, fuera de rango o con conflicto, y devuelve resumen.
- `copyDayGroups` utiliza `sobrescribir_destino=true` por defecto. Las copias
  por dia y rango tienen BPMN propios; la primera retira miembros y cancela
  grupos destino antes de copiar, dentro de una transaccion. La copia por
  rango llama la diaria por fecha y revierte el rango si alguna falla.

En esa fase habia 47 BPMN. Los siete diagramas de Man Power pasan el parser y
tienen SVG.

### RQ Mina y RQ Proserge: revision del 2026-09-28

- RQ Mina: crear BORRADOR, editar y enviar tienen BPMN propios. Editar un RQ
  ENVIADO vuelve a sincronizar el detalle dentro de la transaccion de edicion.
  El diagrama general queda como mapa de navegacion, no como secuencia forzosa.
- `RQMinaService::send` guarda ENVIADO antes de `syncFromRqMina` y no abre una
  transaccion comun: ante fallo de la segunda llamada puede quedar un estado
  parcial. El BPMN documenta el riesgo; el metodo no tiene una salida de error
  gestionada que se pueda dibujar como resultado normal.
- RQ Proserge: asignar, actualizar, retirar y reemplazar tienen BPMN propios.
  La sustitucion conserva referencia `reemplaza_a_id` y cambia el original a
  REEMPLAZADO dentro de una transaccion. La desasignacion legacy delega en
  retiro con motivo historico.

En esa fase habia 41 BPMN. Los diagramas nuevos de RQ Mina y RQ Proserge pasan el
parser y tienen SVG. Sigue pendiente revisar cada conector visual y completar
excepciones y datos de entrada/salida para el catalogo entero.

### Logistica: revision del 2026-09-28

- Entrega EPP termina al registrar ENTREGADO. Cierre/reemplazo ahora tiene BPMN
  separado y comienza desde una entrega existente.
- CAMBIADO abre formulario sin cerrar la entrega. Solo confirmar el reemplazo
  crea nueva entrega y cierra la anterior dentro de una transaccion.
- Crear transporte, asignar pasajeros y cambiar estado son procesos separados.
  CARGA no admite pasajeros; PERSONAL comprueba contexto y capacidad.
- No se inventa una maquina de estados secuencial: validateStateChange agrega
  requisitos para CONFIRMADO, pero no obliga a pasar por todos los estados.
- Los diagramas de retiro/reubicacion y copia de transporte siguen pendientes;
  ya no se presentan como una etapa obligatoria de crear un servicio.

Se añadieron despues los tres BPMN pendientes: retiro, reubicacion y copia.
La reubicacion revela un riesgo real: un error de negocio al agregar al destino
puede devolver `ok=false` tras marcar `REUBICADO` en origen. Como se devuelve
un array dentro de la transaccion externa, ese error no garantiza rollback.
El diagrama deja visible el resultado parcial; requiere una correccion funcional
en una tarea de implementacion.

En esta fase habia 35 BPMN. Los diez de logistica pasan el parser y cuentan con SVG.
La validacion sintactica no equivale a una aprobacion de todas las ramas de negocio.

### Evaluaciones: segunda revision

- Diaria: detallados actor vinculado, autoria de asistencia, cierre, presencia,
  autoevaluacion, validacion de cinco criterios e incidencia. Promedio se
  actualiza despues del commit, no dentro de la misma transaccion.
- Supervisores: documentado alcance real por mina/grupo y el hecho de que el
  control de duplicado solo se aplica cuando existe grupo de trabajo.
- Residentes: rechazo de permisos separado del rechazo por residente/mes
  duplicado; identidad desde cuenta autenticada y contexto sin mina/parada.
- Metodo legacy de supervisores no representado como endpoint activo: las
  rutas inspeccionadas utilizan el controlador y servicio dedicados.

Decision confirmada por usuario: cuando una asistencia reabierta se corrige a
AUSENTE, conservar la evaluacion para auditoria, dejarla pendiente de revision
y excluirla de indicadores. Es una regla acordada pendiente de implementacion,
no comportamiento existente. El usuario confirmo que solo el evaluador original
resuelve la revision, sin excepcion administrativa acordada. Se conserva el
historial y el motivo de la decision. Sigue pendiente definir cuando vuelve a
indicadores y como proceder si el evaluador original ya no tiene acceso.

### Notificaciones: decision confirmada

El usuario confirmo mantener el borrado definitivo 15 dias despues del
vencimiento. El scheduler actual no pasa opciones y usa ese valor por defecto.
No se confunde con 15 dias desde la creacion ni con archivado.

### Cargas masivas: decision confirmada

El usuario confirmo el 2026-09-27 que una carga con filas erroneas se bloquea
completa hasta corregir el archivo. No se permite confirmar solo las filas
validas. Esta regla mantiene la rama de rechazo del BPMN de carga masiva.
Debe distinguirse un error bloqueante de una advertencia informativa; esta
decision no convierte automaticamente toda observacion en error.

Esta revision incremental no equivale a aprobacion funcional de los 59 diagramas.
Continuan pendientes la separacion de procesos agrupados, mapas generales,
evidencia por elemento, excepciones completas, datos de entrada/salida y
revision visual de todos los conectores. El parser BPMN y la ausencia de
superposiciones no garantizan por si solos correccion de negocio.

La consulta inspirada en grill-me se usa para aclarar decisiones de negocio:
una preferencia operativa no se califica como correcta o incorrecta por diferir
del codigo. Se documenta la diferencia antes de proponer cambios funcionales.
