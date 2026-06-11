# Guia UX/UI operativa

## Principios

- La interfaz debe hablar como el usuario, no como el codigo.
- Priorizar comprension sobre densidad tecnica.
- Evitar pantallas con formularios administrativos abiertos desde el inicio.
- Mostrar acciones frecuentes primero y configuraciones dentro de botones.
- Reducir pasos para tareas diarias.
- Usar tablas y filtros similares a Excel cuando ayuden.
- Mostrar claramente que falta, que esta observado y cual es el siguiente paso.
- Evitar botones que no aportan valor operativo.
- No usar nombres de personas en textos del sistema para flujos genericos.

## Tablas

- Deben tener filtros claros, persistencia de estado cuando aplique y paginacion.
- Grandes listados deben permitir elegir cantidad visible.
- Usar indicador visual por columna filtrada.
- Mantener acciones agrupadas para no saturar.
- Evitar scroll horizontal dificil; si existe, debe sincronizarse bien y permitir
  arrastre en zona blanca cuando el usuario lo necesita.
- Mostrar contadores: total, mostrados, pendientes, observados.

## Formularios

- Dividir en secciones operativas.
- Ocultar campos condicionales que no aplican.
- No pedir datos que el sistema ya conoce.
- Validaciones deben decir que falta en lenguaje simple.
- Si hay carga de archivos desde celular, considerar conexiones lentas y
  navegadores moviles.
- Guardar borradores cuando la ficha es larga.

## Botones y acciones

- El texto debe indicar resultado: "Aprobar ficha", "Subir contrato firmado",
  "Preparar renovacion", "Cerrar contrato / registrar cese".
- Acciones destructivas o historicas deben pedir motivo.
- Si una accion recalcula, importa o sincroniza, debe mostrar resumen.
- Botones administrativos deben ir en menu de acciones o modal, no como
  formulario expuesto.
- Ocultar acciones sin permiso.

## Estados visuales

Usar colores consistentes:

- Pendiente/en proceso: amarillo o neutro.
- Correcto/aprobado/habilitado: verde.
- Observado/advertencia: naranja.
- Bloqueado/vencido/desaprobado: rojo.
- Historico/cerrado/no aplica: gris.

Los colores deben ir acompanados de texto; no depender solo del color.

## Usuarios con poca experiencia tecnologica

- Evitar conceptos como "snapshot", "id", "endpoint", "payload" en pantalla.
- Preferir "Historial", "Contrato anterior", "Documento observado",
  "Falta contrato firmado", "Listo para revisar".
- Dar mensajes cortos y accionables.
- Si una pantalla se parece a Excel, mantener columnas y filtros predecibles.

## Vistas por area

- RR.HH.: ciclo laboral, fichas, documentos, contratos, renovaciones.
- Reclutamiento: postulantes, ficha, documentos, seguimiento.
- Operaciones: disponibilidad, RQ Mina, Man Power, asistencia.
- SSOMA: habilitacion, examenes, bloqueos, observaciones.
- Logistica: herramientas por parada, transporte, pedidos.
- Contabilidad/costos: contratos, remuneraciones, costos y exportaciones.
- Gerencia: indicadores, excepciones y decisiones.

La misma informacion no siempre debe verse igual para todas las areas.

## Habilitacion minera

- La vista principal debe ser operativa: trabajador, mina, examenes, intentos,
  resultado y estado.
- No mostrar formularios administrativos abiertos al entrar. Deben vivir dentro
  de Acciones o modales: agregar examen, editar examen, configurar examenes por
  mina, importar Excel master, recalcular estados, historial de precios y revisar
  equivalencias.
- Separar estado general de accion siguiente. Ejemplo: "programar examen" se
  muestra como tarea pendiente, mientras la habilitacion sigue `EN_PROCESO`.
- Cuando se agotan intentos, mostrar `NO_HABILITADO` como estado visible de la
  mina, con motivo claro. No usar `OBSERVADO` para ese caso.
- Minas bloqueadas por desaprobacion de un examen requerido deben verse grises o
  bloqueadas, con motivo legible.
- `NO_APLICA` debe ser una accion directa, sin obligar observacion. La
  observacion puede quedar disponible como opcional.
- Convalidacion debe verse como sugerencia que el usuario confirma, no como
  cambio silencioso.
- Importar Excel master siempre debe usar preview, carga visible y confirmacion.
  Si hay trabajadores no encontrados, mostrarlos como pendientes de registro
  manual, no como creados automaticamente.
- "Recalcular estados" debe explicar que revisara asignaciones, generara
  examenes faltantes, corregira estados y mostrara resumen.
- No usar nombres propios de personas, minas o examenes como reglas fijas en
  textos genericos del sistema.

## Historial y archivado

- Mostrar historial como timeline o lista cronologica, no como tabla tecnica
  interminable.
- Diferenciar datos vigentes de historicos.
- El archivo archivado debe verse como recuperable, no como perdido.
- Filtros historicos deben estar disponibles sin saturar la vista operativa.

## Pantallas actualmente pesadas

Priorizar refactor visual gradual en:

- `resources/views/personal/index.blade.php`.
- `resources/views/personal/fichas/temporales.blade.php`.
- `resources/views/personal/habilitacion-minera/index.blade.php`.
- `resources/views/ficha-public/show.blade.php`.
- `resources/views/personal/edit.blade.php`.
- `resources/views/rq-mina/index.blade.php`.
- `resources/views/personal/import.blade.php`.
- `resources/views/personal/export.blade.php`.
