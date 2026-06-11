# Instrucciones permanentes para Codex

Este repositorio pertenece a un sistema corporativo real de Proserge. Maneja
datos sensibles de trabajadores, contratos, documentos, minas, habilitaciones,
asistencia, requerimientos, herramientas, usuarios, roles y notificaciones.

Antes de implementar cualquier cambio:

1. Leer este archivo.
2. Leer `docs/CODEX_CONTEXT.md`.
3. Leer la regla o mapa relacionado al modulo en `docs/`.
4. Revisar codigo existente con `rg` antes de asumir que algo no existe.
5. Reutilizar servicios, modelos, recursos y componentes existentes.
6. No duplicar logica de negocio en controladores, Blade o JavaScript.
7. Si hay ambiguedad operativa, preguntar antes de programar.
8. Antes de tocar archivos, explicar brevemente que se va a cambiar y por que.
9. Ejecutar pruebas o indicar claramente por que no se pudieron ejecutar.

Reglas de negocio que no se deben romper:

- Nadie debe quedar `ACTIVO` sin contrato firmado vigente.
- Un contrato historico, cerrado, cesado, no renovado o anulado es inamovible.
- Una renovacion crea un contrato nuevo y no modifica el anterior.
- Preparar una renovacion futura no debe sacar de `ACTIVO` al trabajador que aun
  tiene contrato vigente firmado.
- La habilitacion minera no cambia estado laboral ni contratos.
- Habilitacion minera sigue la cadena trabajador -> mina -> requisitos/examenes
  -> intentos -> resultado -> estado de habilitacion.
- En habilitacion minera, textos de Excel como `PROGRAMAR EMO` no son estados
  finales: se separan en estado general (`EN_PROCESO`) y accion pendiente.
- Ninguna mina puede quedar `HABILITADO` sin examenes configurados, generados y
  resueltos para el trabajador-mina.
- Los examenes mineros permiten maximo 1 o 2 intentos; nunca crear ni permitir
  tercer intento.
- Si el trabajador agota intentos o desaprueba el ultimo intento requerido,
  mostrar funcionalmente `NO_HABILITADO` para esa mina.
- `NO_APLICA` cuenta como requisito resuelto y no exige observacion obligatoria.
- La convalidacion de examenes debe ser sugerida y confirmada por usuario, no
  aplicada automaticamente.
- El master Excel de habilitacion es apoyo operativo, no fuente absoluta de
  verdad; no debe crear trabajadores nuevos automaticamente ni sobrescribir
  cargo, contrato, estado laboral, supervisor o datos sensibles sin confirmacion.
- Precios de intentos mineros deben guardarse como snapshot y no cambiar cuando
  se modifica el precio del examen.
- Documentos personales usan estados `PENDIENTE`, `CARGADO`, `OBSERVADO`,
  `APROBADO`, `NO_APLICA`.
- La descarga documental masiva debe mantener carpetas por trabajador.
- El personal antiguo debe registrarse o regularizarse sin duplicarse por DNI o
  documento.
- No quemar nombres de personas, minas, examenes, responsables o reglas
  circunstanciales en codigo.
- No borrar fisicamente datos historicos si el proceso exige trazabilidad.
- No cambiar estados laborales desde modulos que no son responsables de ciclo
  laboral.
- No modificar contratos desde habilitacion minera.

Lineamientos de arquitectura:

- Controladores delgados; reglas y transacciones en servicios.
- Modelos para relaciones y constantes de estado, no para flujos complejos.
- `Resources` o view models para preparar datos de pantallas complejas.
- Blade debe renderizar, no decidir reglas extensas de negocio.
- Importadores deben reportar creados, actualizados, omitidos, errores y
  advertencias.
- Toda accion critica debe pasar por permisos `web.permission`.
- Revisar impacto en usuarios con poca experiencia tecnologica.
- Preferir pantallas operativas claras antes que formularios tecnicos visibles.
- Agrupar formularios administrativos dentro de botones, modales o acciones.
- Usar lenguaje operativo conocido por RR.HH., operaciones, logistica,
  contabilidad, SSOMA, gerencia y administracion.
- Evaluar formatos Excel/PDF historicos antes de disenar nuevos flujos.

Historial, archivado y crecimiento:

- Proteger trazabilidad, fecha, origen, responsable y contexto cuando exista.
- Separar informacion activa, historica y archivada solo con estrategia clara.
- Ningun archivado debe comprometer recuperacion, auditoria o integridad.
- Documentos historicos pueden moverse a almacenamiento secundario solo si el
  sistema mantiene referencia, permisos y recuperacion controlada.
- Antes de cambios masivos, evaluar indices, volumen de datos, tiempo de
  respuesta y espacio en disco.

Antes de cerrar una tarea:

- Resumir que se cambio.
- Listar archivos tocados.
- Listar pruebas ejecutadas.
- Indicar pruebas pendientes o riesgos manuales.
- Para frontend, validar que no se rompa la pantalla afectada y que el texto sea
  entendible para usuarios operativos.
