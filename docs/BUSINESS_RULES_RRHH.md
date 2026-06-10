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

Estados de habilitacion:

- `EN_PROCESO`.
- `HABILITADO`.
- `NO_HABILITADO`.
- `OBSERVADO`.
- `FINALIZADO_POR_DESAPROBACION`.

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
- Un trabajador puede tener varias minas.
- Cada mina tiene requisitos/examenes configurables, no quemados en codigo.
- Cada examen del trabajador guarda snapshot de requisito/precio/vigencia.
- Intentos conservan historial; cambios de precio no modifican intentos previos.
- Examen critico desaprobado puede finalizar proceso de habilitacion.
- Examen vencido puede dejar no habilitado, segun regla del servicio.
- `NO_APLICA` debe ser explicito.
- Convalidacion debe conservar origen y no inventar datos.

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
