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
- Un examen vencido deja la asignacion en `EN_PROCESO` para regularizacion; no
  usa `NO_HABILITADO` mientras el flujo permite continuar actualizando examenes.
- `NO_HABILITADO` se reserva para una desaprobacion que alcanzo o supero el
  maximo configurado de intentos. Los intentos adicionales siguen permitidos.

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
- Un examen conserva un maximo configurado de 1 o 2 intentos como referencia,
  pero se permiten intentos adicionales y se contabilizan como `3/2`, `4/2`, etc.
- Si desaprueba el primer intento y tiene segundo intento disponible, el examen
  sigue `EN_PROCESO`, la mina sigue `EN_PROCESO` y se habilita registrar el
  segundo intento.
- Si alcanza o supera el maximo configurado con resultado desaprobado, el examen
  queda `DESAPROBADO` y la mina `NO_HABILITADO`, pero puede registrar nuevos
  intentos y asignarse a otras minas.
- Al registrar intentos manuales, el numero se asigna dentro de la transaccion
  con bloqueo del examen. El indice unico evita numeros duplicados. Si falla el
  guardado, se revierten los cambios de BD y se elimina el archivo recien subido.
- Examen critico desaprobado puede finalizar proceso de habilitacion; si esto
  ocurre, el estado visible principal debe priorizar `NO_HABILITADO`.
- Examen vencido puede dejar no habilitado, segun regla del servicio.
- Un aprobado manual necesita fecha real de realizacion. Ningun resultado
  registrado puede tener fecha de realizacion futura, tampoco al completar una
  programacion. Tras vencer un aprobado, el trabajador puede rendir de nuevo;
  el intento anterior permanece en el historial.
- `NO_APLICA` debe ser explicito, no exige observacion obligatoria y cuenta como
  requisito resuelto.
- `NO_APLICA` cierra el ciclo operativo actual, pero no impide programar el
  examen mas adelante. Al marcarlo, las programaciones pendientes del requisito
  se conservan como `ANULADO`; una cita EMO compartida sigue activa para las
  otras minas. Programar no consume intento: el contador del ciclo permanece en
  cero hasta `NO_ASISTIO` o `DESAPROBADO`, que pasa a ser el intento operativo 1.
- La numeracion fisica de intentos es historica y monotona. El contador visible
  corresponde al ciclo operativo: queda en cero con una aprobacion vigente o
  `NO_APLICA`, y empieza un ciclo nuevo en cero al vencer la aprobacion.
- Convalidacion debe conservar origen y no inventar datos.
- Convalidacion debe sugerirse, no aplicarse automaticamente.
- La convalidacion acepta el mismo examen o un examen distinto autorizado como
  equivalente en el requisito de la mina destino. Las equivalencias son
  direccionales y no se deducen por parecido de nombres ni por transitividad.
- No todos los examenes tienen nota. Se conserva la nota original cuando existe;
  si el destino exige nota, el origen debe tenerla y alcanzar el minimo del
  snapshot destino. Si no la exige, un resultado aprobado sin nota es valido.
  No se convierte entre escalas: configurar equivalencias de nota solo cuando
  las escalas son compatibles.
- Antes de confirmar se muestran trabajador, examen y mina de origen, nota o
  sin nota, vigencia, examen y mina destino. La escritura web exige confirmacion
  explicita. Se guarda contexto de origen/destino como snapshot junto al actor,
  fecha y referencias originales; cambiar equivalencias no altera lo convalidado.
- Acciones > Configurar examenes por mina > Convalidacion y equivalencias permite
  modificar reglas de un requisito existente con permiso `configurar`.
  `Aceptar resultados de otras minas` se guarda como una regla independiente.
- El Master propone habilitar convalidacion para examenes compartidos por mas de
  una mina y deshabilitarla cuando dejan de estar compartidos. La vista previa
  enumera ambos grupos y los cambios de permiso. Esto no convalida resultados
  de trabajadores: cada convalidacion sigue requiriendo confirmacion posterior.
- Convalidacion solo puede aplicarse si el examen permite convalidacion, existe
  examen equivalente aprobado o vigente en otra mina, el examen origen no esta
  vencido ni desaprobado y el usuario confirma.
- El usuario debe tener permiso para convalidar; el scope de mina de otros
  modulos no restringe esta accion de habilitacion minera.
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
- La importacion del Master reemplaza la configuracion activa de examenes y
  requisitos de todas las minas, no solo de la mina que se esta visualizando.
  Los ausentes pueden desactivarse, pero conservan su historial. La vista previa
  muestra estas desactivaciones y requiere confirmacion explicita. Si el
  catalogo cambia entre la vista previa y la confirmacion, se rechaza la carga
  completa y se debe analizar nuevamente el archivo.
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

## Citas EMO, clinicas y control de asistencia

- Una persona tiene una sola cita EMO no anulada por fecha operativa. Otra
  clinica el mismo dia se rechaza sin escritura parcial.
- Una cita compartida exige el mismo `examen_id` exacto y enlaces explicitos
  por mina. Una cita completada no admite enlaces nuevos ni cambios ordinarios
  de clinica o resultado. La anulacion/reprogramacion conserva historial.
- La cita guarda el resultado medico exacto. `APTO`, `APTO_CON_RESTRICCION` y
  `APTO_CON_OBSERVACION` aprueban; `NO_APTO` desaprueba. Restriccion y observacion
  requieren detalle. Los intentos mantienen el resultado generico y sus
  snapshots propios de nota/vigencia. Completar revierte todo si falla un enlace.
- Los textos historicos singular/plural, con o sin tilde o guion bajo, de
  `APTO CON RESTRICCION(ES)` y `APTO CON OBSERVACION(ES)` son habilitantes. El
  detalle se muestra en tooltip; si el legado solo trae la etiqueta se informa
  que el detalle no fue registrado, sin inventarlo ni degradarlo a `OBSERVADO`.
- Las categorias operativas y clinicas se configuran explicitamente; no se
  infieren de nombres, del tipo descriptivo ni de lugares historicos. Cambiar
  categoria requiere motivo y auditoria. Desactivar una clinica conserva citas.
- Excepcion de reparacion de despliegue 2026-10-10: solo el nombre normalizado
  exacto `EXAMEN MEDICO` pasa de `OTRO` a `EMO`, tambien en catalogos historicos,
  con auditoria de sistema. Categorias explicitas distintas y nombres parecidos
  se conservan. El backfill corrige `OBSERVADO/APROBADO` solo con ultimo intento
  no anulado aprobado y prefijo medico inequivoco de restriccion/observacion.
  Conserva detalle registrado y recalcula vigencia en Lima y habilitacion.
- La migracion repara `NO_APLICA` con pendientes heredados: quedan `ANULADO`,
  se conservan numeros/archivos/historia y se fija un limite de ciclo fisico
  en `max+1`. Repetir no mueve el limite ni duplica auditoria. Programar desde
  `NO_APLICA` tambien sanea pendientes dentro de la transaccion, sin depender
  del backfill; las otras minas de una cita compartida conservan sus enlaces.
- Los EMO historicos sin cita siguen sin clinica inventada. Una programacion
  pendiente se regulariza con permiso `programar`, conservando su trazabilidad.
- La revision de cita rechaza formularios obsoletos, incluso tras anular y
  reactivar con los mismos valores. El conflicto no se resuelve sobrescribiendo.
- Para cambiar fecha o clinica, primero anular la cita y luego reprogramarla
  con motivo y revision vigente. Una cita completada queda en consulta.
- El cierre antiguo de intentos rechaza EMO; el resultado se registra desde
  la cita en Ver programados. El legado pendiente requiere regularizar clinica.
- Habilitacion minera conserva la separacion de contratos y estado laboral.
- Consulta y Excel comparten filtros SQL por fecha programada inclusiva,
  trabajador, categoria, examen, mina, clinica y asistencia. Rango por defecto:
  hoy menos 30 a hoy mas 60 en `app.display_timezone`; maximo 366 dias.
- Una cita compartida es una fila fisica. El filtro de mina reduce tambien las
  minas visibles/exportadas. El legado no se agrupa por coincidencia de texto.
- La descarga exige `ver_programados` y `exportar` por separado; hasta 10 000
  filas agrupadas, sin truncado. Auditoria guarda actor, fecha, filtros/hash y
  cantidad; no guarda los resultados medicos completos.

## Historial, antiguedad y trazabilidad

- Todo evento historico debe conservar fecha, usuario, origen y observacion
  cuando exista.
- Procesos historicos deben poder relacionarse con modulos futuros sin rehacer
  tablas.
- No usar campos editables vigentes como unica fuente de verdad historica.
- Preferir snapshots para cierres de contrato, examenes, habilitaciones y
  procesos de cese.

## Identidad administrada de examenes mineros

- Variantes del mismo curso pueden asociarse a un principal con preview,
  confirmacion, motivo y auditoria. No se borran IDs ni se mueven resultados.
- Aliases confirmados reconocen nombres de Excel, globales o por mina.
  Coincidencias aproximadas solo sugieren revision; no aprueban ni agrupan.
- Excel usa el requisito activo del grupo en la mina destino. Mas de un
  requisito activo del grupo implica ambiguedad, nunca elegir el primero.
- Nota, vigencia, precio, intentos y convalidacion de cada variante/mina se
  conservan. El Master no reemplaza reglas administradas de convalidacion ni
  el nombre principal usando un alias.
- La convalidacion admite variantes confirmadas como origen, pero exige mismo
  trabajador, aprobacion vigente, reglas de mina y nota minima si el destino la
  requiere. Siempre la confirma el usuario. Agrupar no convalida en silencio.
- La estructura y los datos de organizacion son propios de cada ambiente;
  FTP no copia MySQL. Ver `docs/FTP_ORGANIZAR_EXAMENES.md`.

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
