# Procesos funcionales Proserge

Inventario BPMN derivado del codigo existente. Los diagramas describen comportamiento observado; no son una propuesta de proceso futuro. Toda ambiguedad se marca como **Por confirmar**.

- Modulos funcionales documentados: 11
- Procesos BPMN: 59
- Generado desde rutas, controladores, servicios, modelos, policies, middleware, pruebas y documentacion vigente.

## Indice

| Modulo o dominio | BPMN | Alcance |
|---|---:|---|
| [Acceso y seguridad](./seguridad-acceso/index.md) | 4 | Inicio de sesion web/API, cierre web y administracion de usuarios, roles y scope por mina. |
| [Personal](./personal/index.md) | 10 | Ingreso, ficha, documentos, contratos, importacion y regularizacion del trabajador. |
| [Habilitacion minera](./habilitacion-minera/index.md) | 5 | Asignaciones trabajador-mina, requisitos, intentos, estados y cargas masivas con trazabilidad. |
| [RQ Mina](./rq-mina/index.md) | 5 | Creacion, edicion y envio del requerimiento de mina, con planes operativos versionados. |
| [RQ Proserge](./rq-proserge/index.md) | 4 | Atencion de requerimientos recibidos mediante asignacion, retiro o reemplazo de personal. |
| [Man Power](./man-power/index.md) | 7 | Planificacion diaria de grupos, responsables, integrantes y destinos para una parada. |
| [Asistencia y faltas](./asistencia-faltas/index.md) | 5 | Marcacion, cierre, reapertura y tratamiento de ausencias; cada accion tiene inicio propio. |
| [Evaluaciones](./evaluaciones/index.md) | 3 | Evaluacion diaria conectada a asistencia y evaluaciones independientes de supervisores y residentes. |
| [Logistica operativa](./logistica-operativa/index.md) | 10 | Entregas EPP, listas de herramientas por parada y servicios de transporte. |
| [Bienestar](./bienestar/index.md) | 3 | Registro, edicion y anulacion independientes de bloqueos y restricciones del trabajador. |
| [Notificaciones](./notificaciones/index.md) | 3 | Emision de eventos, gestion de bandeja y purga programada de notificaciones. |

## Modulos de consulta o soporte sin BPMN propio

- Dashboard y los dashboards de modulo consultan y agregan datos; solo el recalculo de ejecucion de RQ Mina produce un resumen materializado y esta documentado dentro de RQ Mina.
- MiAsistencia ofrece consulta del estado propio y no inicia un ciclo transaccional independiente.
- Perfil y System son superficies de soporte tecnico sin proceso de negocio propio.
- Catalogos mantiene minas, talleres, oficinas y paraderos mediante CRUD administrativo; alimenta procesos, pero no presenta un ciclo funcional distinto al mantenimiento de maestros.
- Logistica es una fachada de consulta que integra EPP y transporte; las mutaciones reales se modelan en Logistica operativa.

## Inconsistencias transversales

- Coexisten adaptadores y metodos de compatibilidad en RQ Proserge, Asistencia y Evaluaciones; deben conservarse como entradas al mismo proceso, no como procesos de negocio nuevos.
- Personal mantiene dos canales de ingreso parcialmente solapados: PersonalIngreso publico y PersonalFicha por token.
- Las importaciones no comparten un contrato transversal de preview, confirmacion, idempotencia y resumen.
- Las reglas de estados se concentran en servicios extensos; los diagramas describen el comportamiento observado, no eliminan esa duplicidad.
- Revision en curso: no inferir fallas de autorizacion solo por diferencias de middleware entre API y web. Se requiere trazar cada endpoint.

## Convenciones

- Los carriles distinguen roles humanos, Sistema y procesos automaticos.
- Las validaciones y permisos aparecen antes de la mutacion protegida.
- Los finales de rechazo o error se representan de forma explicita.
- La evidencia se registra por proceso y, donde se ha trazado, por elemento. La cobertura individual sigue en revision.

## Estado de revision

Ver [correcciones y decisiones pendientes](./REVISION.md). La validacion tecnica no sustituye la aprobacion funcional.
