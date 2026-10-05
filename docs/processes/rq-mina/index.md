# RQ Mina

Creacion, edicion y envio del requerimiento de mina, con planes operativos versionados.

## Procesos

| Proceso | BPMN | Visual | Alcance |
|---|---|---|---|
| Ciclo de RQ Mina | [BPMN](./crear-editar-enviar-requerimiento.bpmn) | [SVG](./crear-editar-enviar-requerimiento.svg) | Mapa breve: crear BORRADOR, editar y enviar son operaciones con permisos y momentos distintos. Consultar los BPMN de detalle. |
| Gestionar plan operativo | [BPMN](./gestionar-plan-operativo.bpmn) | [SVG](./gestionar-plan-operativo.svg) | Crea, actualiza, duplica o archiva planes dentro del rango del requerimiento y alimenta Man Power, transporte y ejecucion. |
| Crear requerimiento de mina | [BPMN](./crear-rq-mina.bpmn) | [SVG](./crear-rq-mina.svg) | El solicitante crea un BORRADOR con puestos, transporte y plan base. |
| Editar requerimiento de mina | [BPMN](./editar-rq-mina.bpmn) | [SVG](./editar-rq-mina.svg) | Edita un requerimiento existente; si ya fue enviado, sincroniza los cambios con RQ Proserge. |
| Enviar requerimiento a RR. HH. | [BPMN](./enviar-rq-mina.bpmn) | [SVG](./enviar-rq-mina.svg) | Marca ENVIADO y sincroniza RQ Proserge; actualmente el metodo no usa una transaccion que cubra ambas escrituras. |

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
- RQMinaService::send guarda ENVIADO antes de syncFromRqMina y no abre transaccion comun; fallo de sincronizacion puede dejar estado parcial.

## Por confirmar

- Versionado operativo esperado por semana, parada o archivo.
- Quien aprueba formalmente el requerimiento antes de enviarlo.
