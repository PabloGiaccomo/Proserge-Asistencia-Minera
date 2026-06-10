# Preguntas pendientes antes de nuevas implementaciones

## Personal y ciclo laboral

- En que momento exacto un postulante deja de ser postulante y pasa a trabajador
  en proceso?
- Que estados deben ver RR.HH., operaciones y gerencia para el mismo trabajador?
- Que campos son informacion base permanente y cuales pertenecen al contrato?
- Que datos puede editar RR.HH. despues de aprobar ficha?

## Personal antiguo e historial

- Que tipos de historial antiguos se deben registrar ademas de contratos?
- Que eventos historicos necesitan fecha, responsable y documento de respaldo?
- Un trabajador antiguo cesado debe aparecer en listados operativos o solo en
  busqueda historica?
- Que informacion incompleta puede quedar pendiente sin bloquear procesos?
- Que nomenclatura usan actualmente para "antiguo", "historico",
  "regularizacion" y "reingreso"?

## Contratos

- Que tipos de contrato exactos usa la empresa y cuales son equivalentes?
- Cuando un contrato vence, quien decide si cerrar, renovar o esperar?
- Que campos de remuneracion son visibles para que roles?
- El periodo de prueba debe generar una evaluacion formal o solo alerta?
- Que documento firmado es obligatorio para cada tipo de contrato?

## Documentos

- Que documentos bloquean el proceso y cuales solo advierten?
- Vida Ley fisica: quien valida entrega y donde se registra evidencia?
- Documentos observados: deben notificar automaticamente o seguir manual?
- Que documentos historicos deben conservarse aunque ya no apliquen?

## Habilitacion minera

- El master Excel es fuente de verdad o solo apoyo de importacion?
- Si Excel contradice datos del sistema, quien gana?
- Que examenes son criticos por mina?
- Que examenes se pueden convalidar entre minas?
- El precio de examen aplica por fecha de programacion o fecha de realizacion?
- Que ocurre si un trabajador esta cesado pero aparece habilitado en el master?
- Que estados usa SSOMA realmente para "apto", "observado", "vencido" o
  "bloqueado"?

## RQ Mina, operaciones y herramientas

- El plan operativo semanal debe ser versionado por semana, por parada o por
  carga de Excel?
- Que columnas del Excel operativo son obligatorias?
- Transporte se controla como pedido, recurso, costo o solo observacion?
- Herramientas por parada: quien valida envio y cierre?

## Seguridad y areas

- Que areas pueden ver remuneraciones?
- Que areas pueden descargar documentos personales?
- Que roles pueden exportar informacion masiva?
- Que permisos deben separarse de `personal.actualizar` por riesgo?
- Que notificaciones deben poder ser denegadas por usuario?

## Importaciones y archivos historicos

- Un Excel debe crear trabajadores o solo actualizar existentes?
- Si falta DNI, se omite, se crea como pendiente o se pide correccion?
- Que formatos Excel/PDF son oficiales y cuales son copias informales?
- Que nombres de columnas historicos deben conservarse para adopcion?
- Que archivos deben pasar por preview obligatorio antes de confirmar?

## Archivado y almacenamiento

- Cuanto tiempo deben permanecer documentos activos en almacenamiento principal?
- Que documentos pueden moverse a almacenamiento secundario?
- Quien puede restaurar documentos archivados?
- Debe registrarse motivo para restaurar o descargar informacion historica?
- Se requiere hash/checksum para documentos sensibles?
- Hay politicas legales de retencion que deban cumplirse?

## Automatizacion

- Que alertas deben ser automaticas y cuales solo indicadores visuales?
- Que procesos nunca deben automatizarse sin confirmacion humana?
- Que cierres pueden ejecutarse por lote?
- Que reportes debe recibir gerencia y con que frecuencia?
