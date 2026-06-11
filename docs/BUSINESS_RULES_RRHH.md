# Reglas de negocio de RR.HH. y personal

## Ciclo laboral

Estados laborales detectados o usados:

- `PENDIENTE_COMPLETAR_FICHA`.
- `FICHA_ENVIADA`.
- `OBSERVADO`.
- `APROBADO`.
- `FALTA_CONTRATO`.
- `ACTIVO`.
- `CESADO`.
- `LINK_VENCIDO`.

Reglas:

- Registro inicial no debe dejar al trabajador `ACTIVO`.
- Enviar ficha deja la ficha en revision, no activa al trabajador.
- Observar ficha deja `OBSERVADO` y permite correccion.
- Corregir una ficha observada vuelve a `FICHA_ENVIADA`.
- Aprobar ficha lleva a `FALTA_CONTRATO`.
- Solo contrato firmado vigente puede llevar a `ACTIVO`.
- Cese no borra documentos ni contratos.
- Reactivacion/reingreso no debe activar sin contrato firmado vigente.
- Importaciones Excel deben tratar `ACTIVO` como intencion, no como verdad.

## Documentos

Estados documentales:

- `PENDIENTE`.
- `CARGADO`.
- `OBSERVADO`.
- `APROBADO`.
- `NO_APLICA`.

Documentos personales:

- CV documentado.
- Certiadulto o Certijoven.
- Foto.
- DNI.
- Partida de matrimonio si aplica.
- DNI hijos menores si aplica.
- DNI hijos mayores estudiantes si aplica.
- Constancia de estudios de hijos mayores si aplica.
- Recibo de luz o agua.
- Renta de quinta.
- Declaracion jurada de Vida Ley.

Reglas:

- Documento obligatorio aplicable sin archivo no esta completo.
- `NO_APLICA` no cuenta como pendiente.
- `OBSERVADO` sigue pendiente de correccion.
- `CARGADO` no equivale a `APROBADO`.
- Vida Ley tiene control digital y control de entrega fisica.
- Descarga masiva debe generar ZIP con carpeta por trabajador.

## Contratos

Estados contractuales:

- `PREPARACION`.
- `ACTIVO`.
- `CERRADO`.
- `CESADO`.
- `NO_RENOVADO`.
- `ANULADO`.

Reglas:

- Cada contrato es un registro independiente.
- Contratos cerrados, cesados, no renovados, anulados o historicos son
  inamovibles.
- El PDF firmado pertenece al contrato correspondiente, no al trabajador de
  forma generica.
- Contrato firmado antiguo no activa un contrato nuevo.
- Editar contrato vigente/preparacion no modifica historicos.
- Eliminar fisicamente contratos historicos no esta permitido; anular conserva
  registro.
- Al cerrar contrato se debe conservar snapshot, fechas, cargo, area, mina,
  remuneracion, costo hora, supervisor, tipo, archivo y estado.

## Renovaciones, no renovacion y reingreso

- Renovar siempre crea contrato nuevo en `PREPARACION`.
- La renovacion copia datos base del contrato anterior, pero edita solo el nuevo.
- Preparar renovacion futura no cambia a `FALTA_CONTRATO` si el trabajador
  sigue activo por contrato vigente firmado.
- Subir PDF del nuevo contrato lo asocia al nuevo contrato.
- Reingreso de cesado crea contrato nuevo en `PREPARACION`.
- Decision `NO_RENOVAR` no cesa automaticamente.
- Cerrar como `NO_RENOVADO` requiere decision final `NO_RENOVAR`.
- Si el contrato no llego a fecha fin, cierre anticipado requiere confirmacion y
  observacion.
- El trabajador queda `CESADO` solo si no tiene otro contrato vigente firmado.

## Personal antiguo

- No duplicar por DNI/documento.
- `origen_registro` debe distinguir `NUEVO`, `ANTIGUO`, `IMPORTADO`,
  `HISTORICO` o equivalentes existentes.
- Personal antiguo puede quedar `ACTIVO` solo con contrato vigente firmado.
- Si falta contrato firmado vigente, usar `FALTA_CONTRATO` o pendiente de
  regularizacion.
- Contratos antiguos cerrados quedan historicos e inamovibles.
- Contrato antiguo sin archivo queda pendiente de regularizacion.
- Trabajador existente sin origen puede regularizarse sin duplicar.

## Habilitacion minera y examenes

Cadena funcional:

- Trabajador.
- Mina.
- Examenes o requisitos requeridos.
- Intentos.
- Resultado.
- Estado de habilitacion.

Estados de habilitacion:

- `EN_PROCESO`.
- `HABILITADO`.
- `NO_HABILITADO`.
- `OBSERVADO`.
- `FINALIZADO_POR_DESAPROBACION` solo si se requiere por compatibilidad
  historica. La prioridad funcional y visible ante agotamiento de intentos es
  `NO_HABILITADO`.

Estados de examen:

- `PENDIENTE`.
- `PROGRAMADO`.
- `APROBADO`.
- `DESAPROBADO`.
- `VIGENTE`.
- `POR_VENCER`.
- `VENCIDO`.
- `NO_APLICA`.
- `OBSERVADO`.
- `CONVALIDADO`.

Reglas:

- Habilitacion minera no cambia estado laboral ni contratos.
- Habilitacion minera tampoco cambia cargo, supervisor, ficha, documentos
  personales ni renovaciones.
- Un trabajador puede tener varias minas.
- Cada mina tiene requisitos/examenes configurables, no quemados en codigo.
- Cada examen del trabajador guarda snapshot de requisito/precio/vigencia.
- Intentos conservan historial; cambios de precio no modifican intentos previos.
- Un examen puede tener maximo 1 o 2 intentos; nunca debe permitirse un tercer
  intento.
- Si desaprueba el primer intento y tiene segundo intento disponible, el examen
  sigue `EN_PROCESO`, la mina sigue `EN_PROCESO` y se habilita registrar el
  segundo intento.
- Si desaprueba el ultimo intento o agota intentos, el examen queda
  `DESAPROBADO` o equivalente compatible, la mina queda `NO_HABILITADO` y no
  puede continuar para esa mina.
- Si un examen desaprobado sin intentos disponibles tambien es requerido por
  otra mina, esa otra mina debe mostrarse bloqueada/gris para ese trabajador,
  no permitir asignacion y mostrar motivo operativo.
- Examen critico desaprobado puede finalizar proceso de habilitacion; si esto
  ocurre, el estado visible principal debe priorizar `NO_HABILITADO`.
- Examen vencido puede dejar no habilitado, segun regla del servicio.
- `NO_APLICA` debe ser explicito, no exige observacion obligatoria y cuenta como
  requisito resuelto.
- Convalidacion debe conservar origen y no inventar datos.
- Convalidacion debe sugerirse, no aplicarse automaticamente.
- Convalidacion solo puede aplicarse si el examen permite convalidacion, existe
  examen equivalente aprobado o vigente en otra mina, el examen origen no esta
  vencido, no fue desaprobado sin intentos disponibles y el usuario confirma.
- No puede existir `HABILITADO` sin examenes configurados en la mina.
- No puede existir `HABILITADO` sin examenes generados para el trabajador-mina.
- No puede existir `HABILITADO` con examenes pendientes, vencidos,
  desaprobados, observados o sin resolver.

Estados, acciones pendientes y Excel:

- Textos del Excel como `PROGRAMAR EMO` o equivalentes no son estados finales.
  Deben interpretarse como `EN_PROCESO` mas accion pendiente: programar examen.
- Cualquier texto del Excel que represente tarea pendiente debe separarse en
  estado general y accion siguiente.
- El Excel master no es fuente absoluta de verdad.
- El Excel master debe actualizar solo trabajadores existentes. Si trae un DNI
  que no existe, se muestra en preview como trabajador no encontrado, no se crea
  automaticamente.
- El Excel puede detectar minas, examenes, asignaciones de trabajadores
  existentes, estados, fechas, vencimientos, observaciones, notas, datos no
  mapeados y ayudar a configurar examenes por mina.
- El Excel no debe sobrescribir sin confirmacion cargo, contrato, estado
  laboral, supervisor, datos personales sensibles ni ocupacion interna del
  sistema.
- Los colores del Excel son ayuda visual, no fuente de verdad. El sistema debe
  calcular estados segun configuracion, examenes generados, intentos,
  resultados, vencimientos, convalidaciones, no aplica y desaprobaciones.

Precios:

- El precio del examen se toma por prioridad: fecha de registro del intento,
  fecha de programacion, fecha de realizacion y, si ninguna existe, fecha actual
  del sistema.
- Cada intento debe guardar `precio_aplicado`, `moneda_aplicada`,
  `fecha_precio_aplicado` y `fuente_precio`.
- Cambiar el precio del examen no modifica intentos anteriores.
- Si la empresa no paga el examen, puede no contabilizarse y no debe exigir
  precio.

Pantalla y acciones:

- La vista principal debe ser operativa y simple; no debe mostrar formularios
  administrativos abiertos desde el inicio.
- La vista principal se enfoca en trabajador -> mina -> examenes -> intentos ->
  estado.
- Todo lo administrativo debe estar dentro de Acciones: agregar examen, editar
  examen, configurar examenes por mina, importar Excel master, recalcular
  estados, historial de precios y revisar equivalencias.
- El boton "Cargar informacion actual" no debe existir. Si aparece, debe
  renombrarse a "Recalcular estados".
- "Recalcular estados" debe explicar y mostrar resumen de asignaciones
  revisadas, examenes generados, estados corregidos, minas sin examenes
  configurados, asignaciones que no pueden habilitarse y errores encontrados.
- No quemar nombres de minas, examenes ni personas en codigo, rutas, vistas,
  migraciones ni tests.

## Historial, antiguedad y trazabilidad

- Todo evento historico debe conservar fecha, usuario, origen y observacion
  cuando exista.
- Procesos historicos deben poder relacionarse con modulos futuros sin rehacer
  tablas.
- No usar campos editables vigentes como unica fuente de verdad historica.
- Preferir snapshots para cierres de contrato, examenes, habilitaciones y
  procesos de cese.

## Adopcion operativa

- Usar nombres conocidos por los usuarios cuando vienen de Excel/PDF historicos.
- Si un Excel es solo apoyo, no debe sobrescribir datos sin confirmacion.
- Si un Excel es fuente de verdad, documentar quien lo valida y que campos manda.
- Formularios administrativos deben estar detras de acciones claras.
- Las pantallas deben mostrar siguiente accion esperada.

## Archivado y recuperacion

- Ningun archivado debe perder trazabilidad.
- Documentos poco consultados pueden moverse a almacenamiento secundario si se
  mantiene puntero, hash/checksum, permisos y fecha de movimiento.
- Consultas operativas deben priorizar informacion activa; historica debe ser
  consultable con filtros.
- Definir politicas de retencion antes de automatizar archivado.

## Visibilidad por area

Informacion generalmente compartida:

- nombre del trabajador;
- DNI/documento cuando sea necesario para identificar;
- estado laboral resumido;
- cargo/puesto;
- mina/asignacion operativa;
- estado de habilitacion;
- disponibilidad operativa;
- siguiente accion esperada.

Informacion restringida por permisos:

- remuneraciones y costos;
- documentos personales;
- contratos firmados;
- motivos de cese sensibles;
- observaciones de ficha;
- descargas/exportaciones masivas;
- administracion de usuarios y roles;
- configuracion de examenes, precios y permisos.

Antes de exponer un dato a varias areas, confirmar si es dato operativo,
administrativo, sensible o historico.
