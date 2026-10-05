# Plan: evaluaciones y asistencia historica

> Reemplazado por la especificacion
> `docs/superpowers/specs/2026-10-05-evaluaciones-asistencia-historica-design.md`.
> Este documento conserva el inventario exploratorio de fuentes, pero sus
> propuestas de vincular asistencia historica a mina/parada o exigir mina para
> esa asistencia no deben implementarse. Tampoco autoriza ejecutar una carga.

Estado: propuesta. No ejecutar importaciones ni migraciones en produccion hasta
probar el preview, aprobar el mapeo y disponer de respaldos.

## Fuentes inspeccionadas (solo lectura)

- `Consolidado_Evaluaciones_Con_Supervisor.xlsx`: 5.373 filas de evaluacion
  diaria, 2023-11-20 a 2026-09-30. Incluye DNI del trabajador, cinco notas,
  fecha, mina y nombre del supervisor, pero no ID de asistencia ni DNI del
  evaluador. Hay 161 DNI y 195 fechas no interpretables; 5.227 filas tienen
  cinco notas validas. DNI + fecha no es unico: 504 claves repetidas.
- `Proserge Control Asistencia.xlsx`: 861 personas, 382 encabezados y 2.532
  detalles de asistencia con ID; 1.993 evaluaciones diarias, 175 de supervisor
  y una de residente. Algunas columnas llamadas DNI contienen IDs internos:
  resolverlas con la hoja PERSONAL antes de buscar el DNI en el sistema.
  Entre las evaluaciones diarias, 1.957 trabajadores y 1.811 evaluadores
  se resuelven en la hoja PERSONAL. Hay 1.991 referencias a detalle, pero
  solo 367 cumplen simultaneamente mismo DNI y fecha del encabezado.
  Hay 149 referencias con distinto DNI del trabajador y 49 sin DNI resoluble;
  1.515 fechas de evaluacion difieren de la fecha del encabezado. La fecha
  de marcado del detalle coincide en 1.751 casos: investigar antes de usar
  esas relaciones como prueba de asistencia cerrada.
- Entre ambos libros hay 1.422 claves DNI + fecha comunes. Con las cinco
  notas, 1.693 claves exactas cubren 1.933 filas de cada libro, pero 173
  claves tienen multiplicidad. No deduplicar automaticamente por DNI + fecha.
- `DATA AL 30.04.2025 - copia.xlsx` y `DATA AL 31.12.2025 (1).xlsx` son
  snapshots de Personal, ceses y tareos, no fuentes directas de evaluaciones.
  Usarlos solo para diagnosticar identidades historicas; no sobrescribir
  Personal ni contratos actuales.
- La base local inspeccionada tiene 950 personas, un encabezado y seis detalles
  de asistencia, cero evaluaciones diarias, una de supervisor y cero de
  residente. Esto NO describe necesariamente produccion.

## Reglas de conciliacion

1. Identidad del evaluado: normalizar DNI con `PersonalNormalizer`, buscar
   coincidencia unica en `personal.dni`. Nombre y cargo son comprobaciones
   secundarias y se reportan si discrepan. No crear personas automaticamente.
2. En el libro de control, traducir primero el ID exportado mediante PERSONAL
   a DNI; un ID desconocido o un DNI ambiguo queda observado.
3. Identidad del evaluador: resolver por DNI cuando exista. El nombre del
   supervisor del consolidado no autoriza un vinculo automatico ambiguo. Si
   falta DNI, conservar el nombre original como snapshot de fuente y marcar
   actor no vinculado; nunca atribuir la evaluacion a un usuario actual.
4. Fuente prioritaria para registros coincidentes: libro de control, porque
   aporta ID de evaluacion y posible referencia de asistencia. Consolidado
   complementa solo filas no conciliadas. Coincidencias multiples, diferencias
   de nota, fecha, mina o supervisor se presentan para decision humana.
5. El registro actual del sistema tiene prioridad: no reemplazar, borrar ni
   editar evaluaciones ya registradas. La importacion debe ser idempotente por
   fuente + ID original o huella canonica revisada; preservar archivo, hoja,
   fila, hash del lote y resoluciones aprobadas.
6. Cinco criterios enteros 1..4; recalcular total (sobre 20) y contrastarlo
   con el total del Excel. Fechas invalidas/futuras, DNI no resuelto, minas
   ambiguas y notas fuera de escala bloquean la fila. No cambiar fecha ni
   mina para hacer coincidir una asistencia.
7. Las evaluaciones historicas validadas pueden contar en promedio y dashboard
   aunque no tengan asistencia enlazable (decision del usuario). Distinguir
   origen HISTORICO_IMPORTADO de OPERATIVO y ofrecer filtro por origen.
   Una fila observada/no confirmada nunca cuenta.
8. Los importados sin autor de cuenta deben ser visibles conforme a permisos
   de consulta historica, sin pasar a ser propiedad de un usuario arbitrario.
   Revisar `EvaluacionesPolicy`, `EvaluacionesDashboardService` y
   `PromedioDesempenoService` con el mismo universo de filas validas.

## Etapas de implementacion

### 1. Preflight y preview

- Reutilizar permisos y patron de importacion con vista previa ya presentes
  en el proyecto. Restringir lectura, revision y confirmacion en backend.
- Mostrar totales por fuente/tipo, nuevos, coincidentes, sin cambios,
  observados y conflictos. Buscar por DNI, fecha, mina y motivo; exportar
  reporte de errores sin datos innecesarios.
- Cruce con la BD real del entorno antes de confirmar: DNI, minas, IDs de
  evaluacion/attendance, duplicados internos y registros preexistentes.
- No guardar cambios operativos durante el preview. Invalidar el preview si
  cambian el archivo, catalogos o registros que sustentaron el cruce.

### 2. Asistencia historica (subplan independiente)

- Importar primero a un staging auditable los 382 encabezados y 2.532 detalles
  del libro de control; mapear IDs antiguos a UUID nuevos sin reutilizar IDs
  de ocho caracteres como claves canonicas.
- Regla confirmada por el usuario: una fila de `ASISTENCIA DETALLE` significa
  asistencia efectiva. Validar padre, trabajador por DNI, fecha y mina antes
  de registrar `PRESENTE`. Si los datos no concilian, dejar la fila observada
  en staging. No inferir `TARDANZA`, hora individual ni estado `CERRADA` del
  encabezado: esos datos no constan en el archivo. No alimentar
  ausencias/faltas ni Man Power actual a partir de este historico.
- Solo pasar una asistencia a tablas operativas si el significado de la fila
  y el estado historico se confirman y pasan sus invariantes. Conservar
  relaciones historicas rechazadas para auditoria, no como FK operativa.

### 3. Evaluacion diaria historica

- Introducir una via de importacion historica separada de `createDaily`:
  excepcion controlada a la obligatoriedad de asistencia cerrada, nunca una
  relajacion del formulario operativo. Mantener cinco criterios y calculo.
- Revisar migracion incremental para origen, referencia del lote, ID antiguo,
  nombre del evaluador de fuente y posible evaluador no vinculado; no usar un
  supervisor o usuario ficticio para satisfacer columnas `NOT NULL`.
- Enlazar a `asistencia_detalle_id` solo cuando ID, DNI del trabajador, fecha
  del encabezado y elegibilidad sean coherentes. Si no, conservar evaluacion
  historica sin ese enlace y hacer visible la discrepancia.
- Recalcular promedio transaccionalmente tras confirmar; dashboard e
  historiales deben incluir solo filas importadas validas, con filtro por
  origen y fechas reales. No duplicar filas del consolidado y el control.

### 4. Supervisor y residente

- Mapear 175 evaluaciones de supervisor y una de residente a sus plantillas
  actuales. Revisar puntuacion y respuestas antes de importar; no traducir
  campos incompatibles por suposicion.
- Resolver evaluador/evaluado por DNI. Para residente, respetar unicidad
  `residente_id + periodo_mes`; cualquier conflicto con el mes existente
  requiere revision, sin sobrescritura automatica.

### 5. Verificacion y despliegue

- Pruebas con copias anonimizadas: DNI desconocido/duplicado, actor sin DNI,
  asistencia con fecha o trabajador distinto, duplicados entre libros,
  cinco notas invalidas, mismo archivo repetido, carrera de confirmacion,
  rollback y permiso directo a endpoint.
- Conciliar conteos antes/despues, promedios y dashboard por origen/fecha.
  Probar con copia realista de produccion. Backup de MySQL y archivos privados
  antes de migrar. Importar por lotes con confirmacion explicita y reporte
  persistente de aplicados/omitidos/conflictos.

## Pendiente antes de implementar la asistencia canonica

Confirmar como representar un encabezado historico sin evidencia de cierre y
como guardar detalles sin hora individual, respetando el esquema existente.
Esto no impide importar evaluaciones historicas validadas sin vinculo de
asistencia. La fila de detalle significa presencia, pero las discrepancias de
fecha o trabajador siguen requiriendo revision.
