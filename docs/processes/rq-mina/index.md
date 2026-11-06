# RQ Mina

Creacion, edicion y envio del requerimiento de mina, con planes operativos versionados.

## Procesos

| Proceso | BPMN | Visual | Alcance |
|---|---|---|---|
| Ciclo de RQ Mina | [BPMN](./crear-editar-enviar-requerimiento.bpmn) | [SVG](./crear-editar-enviar-requerimiento.svg) | El guardado web con cargos validos inicia atencion RR. HH. si el usuario tiene permiso de envio. Los diagramas conservan las operaciones de dominio y el envio explicito para integraciones API. |
| Gestionar plan operativo | [BPMN](./gestionar-plan-operativo.bpmn) | [SVG](./gestionar-plan-operativo.svg) | Crea, actualiza, duplica o archiva planes dentro del rango del requerimiento y alimenta Man Power, transporte y ejecucion. |
| Crear requerimiento de mina | [BPMN](./crear-rq-mina.bpmn) | [SVG](./crear-rq-mina.svg) | Crea la parada y el plan base; en web inicia RR. HH. si incluye cargos validos y tiene permiso de envio. El diagrama conserva el contrato API de creacion en BORRADOR. |
| Editar requerimiento de mina | [BPMN](./editar-rq-mina.bpmn) | [SVG](./editar-rq-mina.svg) | Edita un requerimiento existente; si ya fue enviado, sincroniza los cambios con RQ Proserge. |
| Enviar requerimiento a RR. HH. | [BPMN](./enviar-rq-mina.bpmn) | [SVG](./enviar-rq-mina.svg) | Marca ENVIADO y sincroniza RQ Proserge con bloqueo de parada y transaccion comun. La web integra esta operacion al guardado autorizado con cargos validos. |

## Actores y datos

- Solicitante de mina
- Sistema
- RQ Proserge

## Evidencia principal

- `app/Modules/RQMina/Services/RQMinaService.php`
- `app/Modules/RQMina/Policies/RQMinaPolicy.php`
- `routes/web.php`
- `routes/api.php`
- `app/Modules/RQMina/Services/RQMinaPlanService.php`
- `app/Modules/RQMina/Controllers/RQMinaPlanController.php`
- `docs/rq-mina-planes.md`
- `tests/Feature/RQMinaPlanManagementTest.php`
- `app/Modules/RQProserge/Services/RQProsergeService.php::syncFromRqMina`

## Duplicidades o inconsistencias

- RQMinaService y RQMinaPageController mantienen compatibilidad con payloads generales y planes nuevos.
- El formato oficial de importacion del plan operativo permanece pendiente.
- El guardado web y el envio explicito comparten las comprobaciones de permiso y cargos validos. Un fallo de sincronizacion revierte el registro del pedido.

## Por confirmar

- Versionado operativo esperado por semana, parada o archivo.
- La regla web acordada inicia la atencion con el primer guardado autorizado y cargos validos; no requiere una aprobacion posterior de envio. Una ampliacion de los roles autorizados requiere una decision aparte.
