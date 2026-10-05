# Evaluaciones y asistencia historica: diseno

Estado: especificacion para revision. No autoriza migraciones ni importaciones.

## Objetivo y limites

Incorporar una sola vez los libros historicos de asistencia y evaluaciones,
asociando personas por DNI y sin alterar el flujo operativo de paradas. Las
evaluaciones historicas confirmadas contaran inmediatamente en promedios y
dashboard. Ademas, permitir evaluaciones diarias nuevas directamente a
cualquier persona de Personal, sin asistencia ni parada, pero con mina elegida.
Conservar el ingreso diario actual desde asistencia cerrada.

No se actualizan Personal, contratos, minas, paradas ni asistencias operativas
desde los Excel. No se deducen faltas, tardanzas, cierres ni horas ausentes.
No se cambian las escalas ni las reglas de las evaluaciones de supervisor y
residente existentes.

## Estado actual comprobado

- `EvaluacionDesempenoService::createDaily` exige detalle de asistencia de
  encabezado cerrado, estado PRESENTE/TARDANZA, actor autorizado y no
  autoevaluacion. Guarda y recalcula `promedio_desempeno` en transaccion; la BD
  protege un registro por `asistencia_detalle_id` no nulo.
- `StoreEvaluacionDiariaRequest` valida los cinco criterios 1..4 y la
  descripcion de incidencia. El formulario actual vive en la vista de
  Evaluaciones. Se reutilizaran sus criterios y patrones visuales.
- `EvaluacionesPageService::dailyHistory` y
  `EvaluacionesDashboardService::authorizedRows` filtran por autor/mina; una
  evaluacion importada sin usuario autor ni mina FK no se mostraria sin una
  regla explicita por origen.
- El esquema actual exige `mina_id` y `supervisor_id` en evaluacion diaria;
  `asistencia_encabezado` exige mina/supervisor/hora. Forzar los Excel en esas
  tablas inventaria datos. Se requieren migraciones aditivas compatibles con
  datos existentes.

## Evidencia de las fuentes

- Consolidado: 5.373 filas no vacias (2023-11-20 a 2026-09-30), con DNI,
  cinco notas, mina textual y nombre de supervisor, pero sin IDs de
  asistencia ni DNI del evaluador. DNI + fecha no es unico.
- Control de asistencia: hojas PERSONAL, Asistencia Encabezado (382),
  Asistencia Detalle (2.532), EV DESEMPENO (1.993), EV SUPERVISORES (175)
  y EV RESIDENTES (1). Algunos campos rotulados DNI contienen IDs internos
  que solo la hoja PERSONAL puede traducir. Muchas referencias de evaluacion
  a detalle discrepan en trabajador o fecha; ninguna se enlaza por parecido.
- Los dos libros DATA de 2025 son snapshots auxiliares de identidad, no
  fuentes para reemplazar registros de Personal.
- Estos conteos son exploratorios. El preview debe recalcularlos sobre los
  archivos concretos y la BD del entorno destino, sin confiar en la BD local.

## Modelo y origen

1. Mantener la tabla y el servicio de evaluacion diaria existentes. Agregar
   un origen explicito para `ASISTENCIA`, `DIRECTA` e `HISTORICA`, y referencias
   auditables del lote/fila. Las filas antiguas enlazadas a asistencia quedan
   como `ASISTENCIA`; cualquier fila antigua sin enlace se clasifica tras
   inspeccion, nunca por un backfill ciego.
2. Para `HISTORICA`, permitir ausencia de mina FK, supervisor FK, autor usuario
   y detalle de asistencia; conservar sus textos originales como snapshot.
   Para `DIRECTA`, exigir mina FK y usuario autor autenticado; el supervisor
   personal FK es opcional si la cuenta no esta vinculada a Personal. Para
   `ASISTENCIA` se conservan todas las invariantes actuales.
3. Crear almacenamiento separado de asistencia historica, con trabajador de
   Personal resuelto por DNI, fecha, presencia efectiva, identificadores de
   origen y lote. No crear `asistencia_encabezado`/`asistencia_detalle`, ni FK
   a mina o parada para estas filas. El texto de mina del Excel, si lo hay,
   es solo contexto de fuente. El historial no alimenta asistencia operativa,
   faltas ni Man Power.
4. Mantener trazabilidad fuente-libro-hoja-fila-hash y una clave idempotente:
   ID original cuando sea fiable; de otro modo, huella canonica mas numero de
   ocurrencia revisado. No usar DNI+fecha como UNIQUE, pues existen multiples
   evaluaciones legitimas el mismo dia.

## Carga historica unica

El boton temporal estara en las Acciones existentes de Evaluaciones y usara
el patron actual de carga con preview. Requiere capacidad administrativa de
Evaluaciones en backend; tanto preview como confirmacion deben verificarla.
Una configuracion y un estado persistente de lote confirmado desactivaran
tambien los endpoints al finalizar la carga, no solo ocultaran el boton.
Se pueden reintentar previsualizaciones mientras nada haya sido confirmado.
Una correccion posterior a la confirmacion requerira un procedimiento
administrativo auditado, no reactivar silenciosamente la misma carga.

El preview no escribe evaluaciones ni asistencias definitivas. Normaliza DNI,
resuelve una sola persona en `personal.dni` y compara nombres como alerta, no
como identidad principal. No crea personas. Traduce IDs internos del libro de
control usando su hoja PERSONAL. El supervisor del consolidado sin DNI queda
como texto de fuente, no se atribuye a una cuenta. Cruza por ID de origen y
evidencia de persona/fecha/notas; ante ambiguedad o diferencia entre libros,
marca conflicto. Registros existentes prevalecen: nunca se sobreescriben.

La pantalla muestra resumen por fuente/tipo y filas propuestas, sin cambios,
observadas o en conflicto; permite buscar DNI/fila/motivo y descargar reporte
de errores. DNI desconocido o ambiguo, fecha invalida, notas fuera de 1..4,
relacion de origen incoherente, duplicado no resuelto y conflicto de unicidad
bloquean el archivo entero. No hay importacion parcial: la confirmacion se
habilita solo con cero bloqueantes y aprobacion explicita del resumen. Un
cambio de archivo, catalogo o datos conciliados invalida el preview.

La confirmacion usa lote con hash y bloqueo transaccional para impedir doble
clic/carreras, conserva reporte persistente de filas y recalcula indicadores
de los trabajadores afectados de forma consistente. Si falla cualquier fila,
no se confirma ninguna; una repeticion del mismo lote no crea duplicados.
Archivos y reportes con datos personales permanecen en disco privado, con
acceso autorizado; el despliegue debe definir su retencion y borrado seguro.

Las evaluaciones de supervisor y residente presentes en el libro de control
entran al mismo preview pero por mapeadores independientes: se comprueban sus
plantillas, puntuaciones, actores y unicidad mensual antes de confirmar. Un
campo imposible de mapear bloquea la carga; no se fuerza una equivalencia.

Decision confirmada el 2026-10-05: conservar el total original de supervisor y
residente aunque difiera de la formula vigente, con marca de calculo historico
y respuestas originales para auditoria. No bloquear solo por esa diferencia
ni modificar las formulas nuevas. Componentes de puntaje historico que no
consten en la fuente se mantienen desconocidos; no se fabrican valores para
hacer coincidir un desglose nuevo con una nota antigua.

## Evaluacion diaria directa permanente

Agregar una entrada discreta en la pantalla actual de Evaluaciones, usando el
formulario de cinco criterios y mensajes existentes. Seleccionar persona de
Personal por DNI/nombre y una mina activa; la fecha y las notas se validan en
backend. No requiere asistencia, parada ni pertenecer al cargo tecnico. Toda
cuenta con `evaluaciones.evaluar_desempeno` puede registrar, aun sin vinculo a
Personal. Si tiene Personal vinculado, no puede evaluarse a si misma. La
excepcion de alcance de mina se limita a esta ruta directa; no relaja la
autorizacion del flujo por asistencia.

Se permiten varias evaluaciones para la misma persona, fecha y mina. Cada
formulario lleva un token de envio de un solo uso: reintentar/doble clic
reproduce el resultado o devuelve un duplicado de ese envio, sin duplicar la
fila. Un envio nuevo deliberado puede crear otra evaluacion. Servicio y BD
protegen esa clave. El calculo, descripcion de incidencia, mensajes de error
y actualizacion de promedio reutilizan las reglas actuales. Tras guardar,
redirigir al historial y mostrar la evaluacion nueva; no dejar abierto un
formulario reenviable.

## Lectura, permisos e indicadores

El historial y dashboard usan un mismo universo de evaluaciones validadas,
incluyendo `ASISTENCIA`, `DIRECTA` e `HISTORICA`; filtran coherentemente por
fecha, tipo y origen. Las historicas sin mina FK aparecen como `Sin mina
vinculada`, nunca bajo una mina inventada. Los filtros por mina no las incluyen
salvo la opcion explicita `Sin mina vinculada`.

El autor de una directa ve sus propias filas segun las reglas actuales; la
consulta de historicas sin autor se reserva a quien tenga el permiso existente
de ver todas las evaluaciones. Los permisos de lectura existentes siguen
aplicando. No atribuir historicas a un usuario actual para que aparezcan.
Promedio y KPIs cuentan solo filas importadas confirmadas; preview y filas
observadas no cuentan. La asistencia historica puede verse como historial
separado, pero no altera indicadores operativos de asistencia o faltas.

## Verificacion y despliegue

- Pruebas focalizadas para conciliacion DNI/ID interno, ambiguedades entre
  libros, fecha/nota invalidas, duplicados legitimos, archivo bloqueado por
  una observacion, reintento y carrera de confirmacion, rollback completo,
  permisos y endpoint directo con boton oculto.
- Pruebas de directa con cualquier Personal, mina obligatoria, actor sin
  Personal, autoevaluacion impedida, dos evaluaciones deliberadas mismo dia y
  doble envio del mismo token.
- Regresion del flujo por asistencia cerrada, unicidad por detalle, promedios,
  filtros del dashboard y evaluaciones de supervisor/residente ya existentes.
- Ensayo sobre copia anonimizada y realista de produccion, con conteos antes y
  despues por origen y reconciliacion del lote. Backup de MySQL y archivos
  privados antes de migrar. Desplegar migraciones aditivas y codigo primero;
  habilitar el boton solo durante la ventana controlada de importacion. No
  ejecutar la carga automaticamente durante el despliegue.
