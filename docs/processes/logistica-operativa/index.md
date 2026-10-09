# Logistica operativa

Entregas EPP, listas de herramientas por parada y servicios de transporte.

## Procesos

| Proceso | BPMN | Visual | Alcance |
|---|---|---|---|
| Entrega/devolucion multiple con firma | [BPMN](./entrega-devolucion-epp.bpmn) | BPMN vigente | Revision, reserva, firma y confirmacion atomica; rechazo sin movimiento. |
| Lista de herramientas por parada | [BPMN](./herramientas-por-parada.bpmn) | [SVG](./herramientas-por-parada.svg) | Preparar y enviar lista de herramientas. Los recordatorios se ejecutan independientemente por scheduler. |
| Crear servicio de transporte | [BPMN](./planificar-transporte.bpmn) | [SVG](./planificar-transporte.svg) | Crea servicio PERSONAL o CARGA y alcances. Pasajeros y cambios de estado son operaciones posteriores independientes. |
| Recordar vencimiento de herramientas | [BPMN](./recordatorio-herramientas.bpmn) | [SVG](./recordatorio-herramientas.svg) | Comprobar tablas; si faltan devolver cero. Seleccionar RQ con inicio en nueve dias y lista no enviada. Por RQ: emitir solo si limite vence en dos dias. Emitir a jefes de planeamiento con clave de deduplicacion. Informar alertas emitidas; devolver SUCCESS |
| Corregir o anular EPP firmado | [BPMN](./cerrar-reemplazar-epp.bpmn) | BPMN vigente | Motivo, permiso directo y compensacion enlazada; original inmutable. |
| Asignar pasajeros a transporte | [BPMN](./asignar-pasajeros.bpmn) | [SVG](./asignar-pasajeros.svg) | Solo servicios PERSONAL. Valida candidatos y capacidad antes de crear asignaciones. |
| Cambiar estado de transporte | [BPMN](./cambiar-estado-transporte.bpmn) | [SVG](./cambiar-estado-transporte.svg) | Documenta la validacion existente; no impone una secuencia estricta de estados que el servicio no aplica. |
| Retirar pasajero de transporte | [BPMN](./retirar-pasajero.bpmn) | [SVG](./retirar-pasajero.svg) | Retiro de una asignacion activa con motivo y evento; tambien puede ser invocado desde Man Power. |
| Reubicar pasajero entre servicios | [BPMN](./reubicar-pasajero.bpmn) | [SVG](./reubicar-pasajero.svg) | Retira de origen y agrega a destino PERSONAL de la misma fecha y turno. Riesgo de retiro parcial ante errores de negocio posteriores. |
| Copiar servicio de transporte | [BPMN](./copiar-servicio-transporte.bpmn) | [SVG](./copiar-servicio-transporte.svg) | Crea un nuevo BORRADOR desde otro servicio, con fecha y turno elegidos; copia placa y conductor solo si se solicita. |

## Actores y datos

- Logistica
- Supervisor
- Planificador de transporte
- Sistema
- Proceso automatico

## Evidencia principal

- `app/Modules/Epps/Services/EppService.php`
- `app/Modules/Epps/Controllers/EppPageController.php`
- `routes/web.php`
- `app/Modules/ParadaHerramientas/Services/ParadaHerramientaService.php`
- `app/Console/Commands/ParadaHerramientasDeadlineReminderCommand.php`
- `tests/Feature/ParadaHerramientaServiceTest.php`
- `app/Modules/Transporte/Services/TransportePlanningService.php`
- `app/Models/TransporteServicio.php`
- `routes/console.php`
- `app/Console/Commands/ParadaHerramientasDeadlineReminderCommand.php::handle`
- `app/Modules/ParadaHerramientas/Services/ParadaHerramientaService.php::emitDeadlineReminders`
- `app/Models/TransporteServicioPasajero.php`

## Duplicidades o inconsistencias

- LogisticaPageController integra paneles, pero las escrituras pertenecen a Epps, Transporte y ParadaHerramientas.
- Reubicacion de pasajero: si el alta en destino devuelve ok=false despues de marcar REUBICADO en origen, la transaccion externa puede confirmar el retiro sin nueva asignacion; revisar tratamiento atomico de errores de negocio.

## Por confirmar

- Quien valida el envio y cierre de herramientas por parada.

## EPP por lotes: contratos vigentes

La entrega individual y el cierre/reemplazo individual fueron retirados de web (409).
Los BPMN y SVG EPP documentan lotes firmados y compensaciones; fueron
validados y renderizados desde la implementacion vigente.
Guia: [EPP firma operativa](../../EPP_FIRMA_OPERATIVA.md).

| Elemento del flujo | Evidencia confirmada |
|---|---|
| Preparar lote y reservar | EppOperationPageController::store/storeReturn; EppOperationService::prepareForSignature |
| Revisar procesados/pendientes | EppOperationService::reviewPayload; operations/review.blade.php |
| Firmar y confirmar atomico | EppOperationConfirmationService::confirm; EppAcceptanceService::capture |
| Desacuerdo/negativa | EppOperationService::reject; libera reservas, no entrega |
| Devolucion y cuarentena | EppBalanceService; EppOperationService; EppOperationConfirmationService |
| Compensacion sin borrar | EppCorrectionService::correct/annul |
| Verificar evidencia | EppEvidenceController::verify; EppAcceptanceService::verify |

Actores: operador, trabajador, testigo autorizado y aprobador excepcional.
Una devolucion parte de saldo retornable positivo; consumibles no se retornan.
El reemplazo requiere devolucion/correccion y una nueva entrega independiente.
