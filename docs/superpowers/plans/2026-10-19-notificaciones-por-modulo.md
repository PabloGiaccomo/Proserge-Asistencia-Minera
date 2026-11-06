# Notificaciones: plan integral por modulos

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans para ejecutar este plan tarea por tarea despues de su aprobacion. No ejecutar todos los bloques en una sola entrega.

**Goal:** Que cada usuario reciba solo los avisos pertinentes y autorizados, pueda entenderlos y actuar, y Administracion pueda explicar tanto las entregas como las exclusiones.

**Architecture:** Corregir y ampliar el modulo existente, no reemplazarlo. Conservar `NotificationService`, `NotificationRecipientResolverService`, `NotificationInboxService`, `OperationalNotificationService`, las seis tablas actuales y las superficies Web/API. Integrar los productores en el servicio que confirma cada operacion, reutilizando policies y relaciones del dominio.

**Tech Stack:** Laravel 12, PHP 8.2+, MySQL, Blade, JavaScript nativo, scheduler y PHPUnit 11. Sin dependencias nuevas ni servicios externos obligatorios.

**Spec:** Diseno, contratos y matriz funcional incluidos en este documento. Implementacion autorizada el 21 de octubre; avance por tarea indicado abajo. Las tareas pendientes siguen siendo propuestas, no funcionalidad entregada.

**Fecha:** 2026-10-19, America/Lima.

## 1. Alcance y estado de esta revision

- Se revisaron codigo local, rutas, catalogos, migraciones, permisos, bandeja, cron y pruebas existentes. Graphify se uso como mapa inicial; su reporte del 25 de septiembre no sustituye la lectura del codigo actual.
- No se inspeccionaron usuarios, asignaciones, preferencias ni cron efectivos de produccion. Que una migracion declare un tipo no demuestra que este activo en el hosting.
- Base de pruebas ejecutada: `OperationalNotificationServiceTest`, `UsuarioNotificationTest` y `WebPermissionMiddlewareTest`: **45 pruebas, 400 assertions, sin fallos** sobre `proserge_app_test`.
- Esas pruebas no cubren todos los riesgos descritos aqui. No se presenta la suite verde como certificacion integral del sistema de avisos.
- Hay cambios locales previos en Personal, Evaluaciones, RQ Mina y Man Power. Este trabajo de planificacion no los modifica, revierte, confirma ni despliega.
- En la revision inicial del 19 de octubre solo se creo este documento. El avance de implementacion del 21 de octubre se registra por tarea y en `docs/superpowers/reports/2026-10-21-notificaciones-entrega-1.md`. Sin cambios de produccion, Git remoto o FTP.

## Review Focus

- Audiencia explicita vacia y roles adicionales inactivos: nunca convierten una entrega dirigida en difusion general; pruebas de tarea 1.
- Permiso o relacion revocados entre emision, render y clic: no exponen contenido ni habilitan la accion antigua; pruebas de tareas 1, 2 y 10.
- Dos emisores concurrentes, dedupe vencido y nueva revision legitima: una copia por ocurrencia, sin perder la siguiente operacion; pruebas de tarea 4.
- Negocio confirmado seguido de caida del emisor/procesador: reintento durable sin repetir el negocio ni entregar un rollback; pruebas de tarea 4.
- Persona compartida entre minas, datos medicos y cambio de responsable: mostrar solo contexto autorizado, sin imponer scope a Habilitacion ni filtrar por nombre del cargo; pruebas de tareas 5, 6 y 9.

## 2. Reglas confirmadas y restricciones globales

- GERENTE respeta permisos configurados. Solo ADMIN/SUPERADMIN con rol ACTIVO conservan el privilegio global existente; no crear un bypass paralelo para notificaciones.
- Solo cuentan roles ACTIVO, principales y adicionales. Una preferencia habilitada nunca concede acceso a un modulo, dato o accion.
- **Decision del usuario del 19 de octubre:** tambien las alertas criticas de seguridad respetan preferencias y silenciamiento. No introducir avisos obligatorios que ignoren esa decision.
- Separar privilegio administrativo para consultar/configurar del deseo personal de recibir avisos: acceso de ADMIN no significa suscripcion forzada a todos los eventos.
- Conservar borrado definitivo a los **15 dias despues de expires_at**, aprobado previamente. No convertir este plan en archivado indefinido de mensajes o payloads.
- El scope de mina solo aplica cuando lo exige el dominio de destino. Habilitacion minera no hereda automaticamente `usuario_mina_scope` por incluir una mina en el mensaje.
- Una notificacion no modifica contratos, estado laboral, habilitaciones, asistencia, evaluaciones ni inventario.
- No emitir por abrir una pantalla, buscar, previsualizar un Excel o intentar una operacion que falla. Emitir por una transicion confirmada o una condicion operativa verificada.
- Leer, abrir un enlace y resolver el pendiente son hechos distintos. No anunciar una tarea como resuelta por haber abierto el aviso.
- Conservar nombres de rutas, IDs, historial, estados de lectura y preferencias existentes, salvo incompatibilidad documentada y aprobada.
- Canal inicial: notificaciones internas. `email_enabled` existe, pero el circuito central revisado no entrega correos. No prometer WhatsApp, SMS, push o email como implementados. El correo operativo de herramientas existente es otro flujo y debe revisarse para no duplicar comunicaciones.
- Nunca volcar DNI completos, salarios, diagnosticos, documentos, credenciales o payloads completos en campana, diagnosticos generales o logs.

## 3. Funcionamiento real actual

```text
Controller o Service del modulo
  -> NotificationService::emit(typeCode, context)
  -> buscar tipo activo en notification_types
  -> NotificationRecipientResolverService::resolve(type, context)
  -> usuarios activos + roles/preferencias/permisos/scope
  -> transaccion: notification_events + notification_recipients
  -> campana / polling / API
  -> leer, abrir accion o archivar
  -> vencimiento y limpieza programada
```

**Piezas que se conservan:**

| Responsabilidad | Implementacion existente |
|---|---|
| Emision y deduplicacion | `app/Modules/Notificaciones/Services/NotificationService.php` |
| Audiencias y preferencias | `app/Modules/Notificaciones/Services/NotificationRecipientResolverService.php` |
| Consultas y estados de lectura | `app/Modules/Notificaciones/Services/NotificationInboxService.php` |
| Mensajes del negocio | `app/Modules/Notificaciones/Services/OperationalNotificationService.php` |
| Web y polling | `app/Modules/Notificaciones/Controllers/NotificacionPageController.php` |
| API | `app/Modules/Notificaciones/Controllers/NotificacionController.php` |
| Campana y carga inicial | `resources/views/partials/header.blade.php`, `app/Providers/AppServiceProvider.php` |
| Configuracion | `RoleManagementService`, `UsuarioPageController`, vistas de Roles y Usuarios |
| Autorizacion | `PermissionMatrix`, `PermissionCatalog`, `EnsureWebPermission`, policies del dominio |
| Persistencia | `NotificationType`, `NotificationEvent`, `NotificationRecipient`, `NotificationPreference`, `NotificationRolePreference`, `NotificationUserSetting` |
| Limpieza | `NotificationsCleanupExpiredCommand`, `routes/console.php` |

**Garantias que ya existen y no deben duplicarse:**

- Tipo con `code` unico; evento con `dedupe_key` unico; destinatario unico por evento y usuario.
- Estado por destinatario, no por evento compartido.
- Busqueda de destinatario por `id + usuario_id` al leer/archivar: ya evita modificar la copia de otro usuario.
- Web distingue `notificaciones.ver` de `notificaciones.actualizar` en rutas de lectura y escritura explicitas.
- Preferencias por usuario, por tipo y por rol, prioridad minima y silenciamiento ya tienen modelo.
- Los tipos se instalan/refinan en migraciones; no se encontro un seeder dedicado que sea una fuente unica de todos los eventos.
- El vencimiento por defecto actual es 30 dias para baja, 60 para media/alta y 90 para critica o categoria seguridad. Este plazo no equivale al vencimiento del contrato, examen u operacion que origino el aviso.

## 4. Hallazgos y prioridad

| Prioridad | Evidencia actual | Trabajo requerido |
|---|---|---|
| P0 | El resolver devuelve acceso global a ADMIN/GERENTE/SUPERADMIN por prioridad alta/critica o categoria seguridad/sistema antes de validar el permiso del dominio. Sus helpers propios de nombres/IDs no filtran roles inactivos. | Reutilizar permisos/roles efectivos existentes; eliminar la excepcion de GERENTE y la influencia de roles inactivos. |
| P0 | `target_user_ids` solo restringe cuando la lista no esta vacia. Con lista explicita vacia y `require_permission=false` puede ampliarse la audiencia. Herramientas y RQ completado usan esa combinacion. | Lista explicita vacia significa cero destinatarios, nunca difusion general. |
| P0 | API de notificaciones usa `auth.token`, comprueba propietario, pero no valida permisos de Notificaciones ni la configuracion general del usuario como Web. | Misma autorizacion de lectura/escritura/configuracion en ambas superficies. |
| P0 | `NotificationInboxService::baseQuery` filtra propietario y expiracion, pero no reevalua acceso al dominio ni scope actual. La seleccion inicial tampoco exige siempre `notificaciones.ver`. | Evitar entregas inutilizables y exposicion posterior a revocacion de permisos o cambio de asignacion. |
| P1 | `openAction` es GET con permiso Ver, pero modifica READ/ACTIONED. | Separar navegacion de mutaciones; Ver por si solo no concede Actualizar. |
| P1 | `resolveActionRoute` usa titulo/label con la palabra ficha para redirigir. Un aviso de ficha aprobada con contrato pendiente puede terminar en revision de ficha. | Resolver por tipo, entidad y ruta declarada; comprobar permisos y existencia del destino. |
| P1 | `usuario_eliminado` se emite, pero no se encontro su alta en las migraciones revisadas. Hay tipos de Man Power, Asistencia y Faltas declarados sin llamada de emision encontrada. | Conciliar catalogo contra productores y rutas; distinguir configurado de conectado. |
| P1 | Contratos y Habilitacion siguen anunciando permisos `personal.actualizar` en tipos, aunque existen `personal_contratos` y `habilitacion_minera`. | Alinear audiencia y enlaces con las capacidades y modulos reales. |
| P1 | RQ Proserge emite desde servicio al completar cobertura y tambien desde PageController segun estado enviado por formulario, sin persistir esa transicion en ese metodo. | Fuente unica: cambio real confirmado, no intencion del request ni consulta. |
| P1 | `OperationalNotificationService` captura errores y solo registra log. EMO usa `afterCommit`, pero no tiene reintento durable de la notificacion. | Evitar perdida permanente tras confirmar el negocio; no confundir afterCommit con entrega garantizada. |
| P1 | Emision consulta dedupe antes de insertar, con UNIQUE ya existente pero sin traduccion especifica de carrera. Reutiliza evento vencido si coincide su clave. | Idempotencia concurrente y claves por ocurrencia/revision, no solo minuto o fecha. |
| P1 | `/notificaciones` redirige al inicio y abre campana; la vista de bandeja con filtros existe, pero no se renderiza desde ese index. | Decidir y habilitar un recorrido util sin inventar otro dashboard. |
| P2 | Polling cada 5 segundos por pestana, render completo y log de cada consulta; resolver carga todos los usuarios y roles parciales. | Medir carga, reducir consultas y ruido sin eliminar actualizacion operativa. |
| P2 | No existe una explicacion administrativa central por usuario de por que un evento llega o se descarta. | Vista previa segura y diagnostico reutilizando el resolver, no una segunda matriz de reglas. |

Estas son observaciones del codigo local y riesgos derivados. Los casos sin prueba especifica deben reproducirse en la primera tarea que los corrige; no se afirma que ya hayan ocurrido sobre datos reales.

## 5. Diseno propuesto de permisos y destinatarios

### 5.1 Tres decisiones distintas

1. **Recibir:** usuario ACTIVO, roles efectivos ACTIVO, tipo habilitado, audiencia explicita, permisos del dominio y preferencias. Aplicar scope unicamente cuando corresponda al dominio.
2. **Consultar:** copia propia y autorizacion vigente para su contenido. La revocacion afecta tambien listado, campana, conteo, filtros y API, no solo el boton Abrir.
3. **Actuar:** comprobar la ruta/policy real del destino; recibir o leer no concede aprobar, cerrar, corregir, firmar, convalidar ni asignar.

Conservar `notificaciones.ver` y `notificaciones.actualizar`. Configuracion de otro usuario sigue bajo `usuarios.configurar`; preferencias de rol siguen bajo los permisos de guardar el rol existentes. No crear una capacidad administrativa generica para eludirlos.

No cambiar automaticamente las suscripciones de otros roles ni ampliar permisos durante una migracion. La union de roles se calcula solo entre roles activos; una suscripcion activa no concede el permiso que otro rol tiene revocado.

### 5.2 Precedencia propuesta

El envio se rechaza si falla cualquiera de estas condiciones aplicables: usuario/rol habilitado, permiso de lectura de Notificaciones, permiso del dominio, relacion/alcance, preferencia de rol, preferencia general o por tipo, silencio, prioridad minima y tipo activo. Tambien las criticas respetan esa precedencia.

Para multiples roles activos conservar inicialmente la regla de preferencias existente: una preferencia habilitada entre ellos permite suscripcion; si existen preferencias explicitas y todas estan deshabilitadas, no entregar. Ninguna de las dos situaciones supera la autorizacion efectiva del dominio.

No reenviar automaticamente mensajes historicos cuando se concede un permiso o se activa una preferencia. El historial se conserva hasta su purga; no se borra para simular una revocacion.

### 5.3 Explicacion de entrega

Agregar al resolver existente una consulta de diagnostico sin efectos secundarios, conceptualmente `explain(NotificationType $type, array $context = []): array`. Debe usar exactamente las mismas decisiones que `resolve`, no reimplementarlas.

Resultado por usuario autorizado a ser inspeccionado: Recibiria / No recibiria, tipo, prioridad, dominio, motivo y accion disponible. Motivos propuestos: `USER_INACTIVE`, `NO_ACTIVE_ROLE`, `NOTIFICATIONS_PERMISSION_DENIED`, `DOMAIN_PERMISSION_DENIED`, `OUT_OF_SCOPE`, `ROLE_TYPE_DISABLED`, `USER_DISABLED`, `USER_TYPE_DISABLED`, `MUTED`, `BELOW_MIN_PRIORITY`, `ACTOR_EXCLUDED`, `TYPE_INACTIVE`, `EMPTY_TARGETS`, `DESTINATION_UNAVAILABLE`.

Reutilizar Usuarios y Roles para mostrar la configuracion efectiva y el origen de cada permiso/preferencia. Una previsualizacion no crea evento, destinatarios ni mensajes de prueba y no debe revelar usuarios ajenos al administrador autorizado.

## 6. Matriz funcional por modulo

Las audiencias siguientes son propuestas basadas en responsabilidades y permisos, no nuevos privilegios por nombre de rol. Confirmar responsables operativos concretos antes de activar eventos nuevos.

| Modulo / proceso | Situacion encontrada | Avisos a corregir o incorporar | Destinatario propuesto y siguiente accion |
|---|---|---|---|
| Seguridad: usuarios, roles, permisos y Auth | Existen altas/desactivacion de usuario y cambios de rol/permisos; falta alta encontrada para `usuario_eliminado`. | Acciones confirmadas, resumen minimo del cambio y enlace seguro; no enviar contrasenas, tokens ni intentos de login masivos como avisos normales. | Quienes tengan permisos de seguridad pertinentes y suscripcion. Eliminado: abrir lista o auditoria autorizada, no ficha inexistente. |
| Personal: ingresos, fichas y documentos | Hay ficha completada, ficha aprobada sin contrato, importacion con errores y datos incompletos; cobertura no uniforme entre ingreso publico, ficha y documentos. | Revisar lo recibido, observado o corregido y pendiente documental real. Importaciones: resumen de lote, no una alerta por celda. | RRHH con la capacidad concreta para revisar/observar/regularizar. Abrir trabajador o lote exacto. |
| Personal: contratos y vencimientos | Hay firmado, decision y cierre de no renovacion. Permisos del tipo aun apuntan a Personal general. | Contrato pendiente, firmado, proximo a vencer, decision pendiente y cierre confirmado; preservar historial inamovible. | `personal_contratos` y accion correspondiente. Informacion de consulta separada de autorizacion para renovar/cerrar. |
| Habilitacion minera: cursos, examenes y EMO | Hay programacion y cambios de habilitacion. Reprogramacion, anulacion, vencimientos y convalidaciones requieren cobertura especifica. | Programado/reprogramado/anulado, resultado disponible sin detalle medico, por vencer/vencido y convalidacion por revisar. Estado siempre calculado por el servicio actual. | Usuarios habilitados de `habilitacion_minera`, segun programar/registrar/convalidar o ver programados. Mina como contexto, sin imponer scope minero ajeno al dominio. |
| Bienestar / informacion sensible de SSOMA | Hay bloqueo registrado y anulado; existen ademas tipos antiguos sin productor conectado. | Disponibilidad modificada y periodo afectado, sin diagnostico o motivo restringido para quien no pueda verlo. Revisar retorno/vencimiento solo si el dominio lo confirma. | Bienestar autorizado; operaciones recibe solo disponibilidad necesaria. No difundir datos medicos por ser alerta alta. |
| RQ Mina | Envio conectado en Web/API; reglas duplicadas de contexto. | Envio y cambios confirmados con destino, area, fechas y pedido identificables. No avisar dos veces por Web y API. | Audiencia de atencion RRHH/planeamiento con permisos de RQ Proserge y alcance real del requerimiento. |
| RQ Proserge | Cobertura completa desde servicio y avisos manuales desde controlador. | Pendiente/parcial/completo y cambios que afectan cobertura, derivados de datos persistidos. | Responsable RRHH y creador del requerimiento solo cuando tienen acceso permitido; sin creador no ampliar a todos. |
| Man Power | Tipos de grupo dia/noche pendiente y grupo sin supervisor, sin emision encontrada. | Grupos pendientes, sin responsable o afectados por cambio de plan. Evitar duplicar grupo sin cerrar y asistencia sin cerrar. | Responsables del plan/grupo y operadores autorizados. Recurrencia por fecha/turno/plan, con plazo por confirmar. |
| Asistencia y Mi Asistencia | Tipos pendientes de marcar/cerrar sin productor encontrado. | Pendiente de marcar/cerrar, reapertura y correccion relevante, respetando PRESENTE/TARDANZA/AUSENTE y responsable real. | Marcador/cerrador autorizado para ese grupo. El trabajador solo recibe su informacion cuando tenga usuario vinculado y permiso. |
| Faltas | `faltas_generadas` declarado, sin emision encontrada. | Resumen por cierre y correccion/anulacion confirmada, sin avisar por cada marca aun editable. | Quienes puedan consultar/gestionar faltas; corregir requiere su permiso propio. |
| Evaluaciones | No se encontro emision central conectada. | Evaluacion realmente pendiente, regularizacion requerida y revision necesaria tras corregir asistencia. Resumen de importacion historica, no aviso por fila. | Evaluador autorizado por reglas actuales: diaria por elegibilidad vigente, supervisor por rol/permiso, residente por capacidad de Gerencia. No ampliar acceso a notas de terceros. |
| Logistica | Sin emision central encontrada en su servicio/controlador. | Pendientes de sus procesos y resumen de recursos, no duplicar automaticamente el evento de EPP, transporte o herramientas que ya origino el aviso. | Operador autorizado a la pestana/operacion concreta. Ver Logistica no concede firmar ni corregir EPP. |
| EPP | Sin emision central encontrada en servicios de operaciones/firma/inventario. | Entrega/devolucion pendiente de firma, confirmacion, correccion/anulacion y excepcion relevante. Stock bajo solo si existe umbral operativo confirmado. | Titular firmante con vinculacion valida y operadores con capacidades EPP. Reserva o borrador no equivale a entrega confirmada. |
| Transporte | No se encontro emision central conectada. | Servicio sin recurso/responsable requerido y cambio confirmado de fecha, turno o asignacion que afecte una salida. | Coordinador y responsables reales autorizados; no localizar conductores por coincidencia de nombre. |
| Herramientas por parada | Recordatorio dos dias antes del limite, calculado para RQ que inicia nueve dias despues. Audiencia por nombres de roles y fallback. | Corregir audiencias, enlace y contexto; usar plazo efectivo de cada lista, no asumir siempre ese desfase. No recordar lista ya enviada/cancelada. | Responsables con permiso `herramientas` y relacion vigente con el proceso, sin fallback por nombre JEFE/GERENTE. |
| Catalogos, Dashboard, Perfil y System | Catalogos puede desvincular mina de eventos; no se encontro un circuito central de avisos propios. | Catalogos solo avisa cambios con impacto operativo confirmado. Dashboard consume el mismo universo autorizado. Perfil/Mi Asistencia no generan copias globales. System diagnostica fallos del mecanismo. | Administracion o responsables afectados por la relacion real. No crear una notificacion por cada consulta, grafico o cambio cosmetico. |

### 6.1 Ficha obligatoria de cada tipo de aviso

Antes de activar cada fila, registrar: codigo estable, productor, transicion/condicion real, identificador de ocurrencia, dominio propietario, destinatarios, lectura/accion y permisos exactos, alcance, prioridad, titulo/mensaje, destino, plazo operativo, repeticion, dedupe, causa de resolucion, expiracion y politica de preferencias.

Etiqueta de cobertura: **Conectado**, **Solo catalogado**, **Falta catalogo**, **Propuesto** o **Retirado de nuevas emisiones**. No borrar eventos historicos al retirar un tipo.

Mensajes: que ocurrio, a quien o que operacion afecta, fecha/contexto y siguiente accion concreta. No usar el UUID como mensaje principal.

## 7. Tareas de implementacion por entregas

Cada tarea incluye su ciclo: prueba roja especifica, cambio minimo, prueba verde, regresion del dominio y revision del diff. Finalizar una tarea no autoriza iniciar las que tengan decisiones de negocio pendientes. Commits separados por entrega; ningun push/FTP se ejecuta durante la preparacion de este plan.

### Tarea 1. Seguridad de destinatarios y paridad Web/API — P0

**Archivos:** resolver, ambos controladores de Notificaciones, `NotificationInboxService`, `routes/api.php`, middleware existente y `PermissionMatrix` solo si es imprescindible reutilizarlo correctamente. **Pruebas:** ampliar `UsuarioNotificationTest` y `OperationalNotificationServiceTest`; crear un test focalizado de bandeja/API si falta equivalente.

- [x] Reproducir GERENTE sin permiso, rol global INACTIVO, roles adicionales mixtos, `target_user_ids=[]`, usuario desactivado y usuario sin `notificaciones.ver`.
- [x] Corregir usando `PermissionMatrix::effectivePermissions/activeRoleNames`; mantener `resolve(NotificationType $type, array $context = []): Collection`.
- [x] Comprobar permiso y preferencias en API sin sustituir propietario ni `auth.token`. `ver` no autoriza archivar/marcar todo.
- [x] Revocar acceso entre emision y lectura: mismo resultado seguro en contador, campana, API y acceso directo. Cargar roles completos una vez por consulta de bandeja, no una consulta por mensaje.
- [x] Demostrar que alertas criticas tambien respetan mute/preferencias y que preferencias no otorgan acceso a datos.
- [x] Verde: pruebas focalizadas y `WebPermissionMiddlewareTest`, `RolePermissionCheckboxTest`, `RbacOperationalScopeTest`. Regresion conjunta con emisores, Herramientas y Logistica: 471 pruebas / 5425 assertions al 21 de octubre; detalle en informe de entrega 1.

**Aceptacion:** no hay destinatarios por rol inactivo, lista vacia o bypass GERENTE; tampoco contenido antiguo visible tras revocacion.

### Tarea 2. Catalogo, enlaces y permisos reales — P1

Avance 21 de octubre: corregidos ocho tipos y el enlace conocido de ficha
aprobada/contrato pendiente, junto con checks, silencios y capacidades del poll.
Detalle: `docs/superpowers/reports/2026-10-21-notificaciones-permisos-corregidos.md`.
No se declara terminada esta tarea: quedan inventario del hosting, tipos
huerfanos, validacion general de destinos y las demas heuristicas legacy.

**Archivos:** `NotificationService`, `NotificacionPageController`, `OperationalNotificationService`, `PermissionCatalog`, migraciones incrementales y pruebas. No editar migraciones ya ejecutadas para reparar produccion.

- [ ] Inventariar tipos instalados en entorno autorizado y contrastar con llamadas, rutas y capacidades; detectar tipos huerfanos, inexistentes e inactivos.
- [ ] Registrar `usuario_eliminado` si se mantiene el evento; su accion no puede apuntar a un usuario eliminado.
- [ ] Probar ficha aprobada con contrato pendiente: abrir contratos, no revision de ficha. Eliminar heuristicas basadas en palabras del titulo sin romper los tipos historicos validos.
- [ ] Alinear tipos de contratos y habilitacion con `personal_contratos` / `habilitacion_minera`; conservar IDs, preferencias e `is_active` al actualizar metadatos.
- [ ] Probar entidad eliminada, mina desvinculada, parametro faltante y URL externa: destino controlado y sin redireccion abierta.
- [x] GET de Abrir solo navega. Si hay actualizacion de lectura, usar las acciones POST autorizadas existentes; ACTIONED no se interpreta como tarea del negocio completada. Adelantado en tarea 1 para cerrar la escritura con permiso Ver; sin redisenar enlaces.

**Aceptacion:** todos los tipos activos tienen productor y destino valido, o se muestran como no conectados y no se ofrecen como funcionalidad operativa.

### Tarea 3. Diagnostico de que llega a cada usuario — P1

**Archivos:** `NotificationRecipientResolverService`, `UsuarioPageController`, `RoleManagementService`, vistas existentes de Usuarios/Roles, rutas protegidas y pruebas de preferencias.

- [ ] Probar una misma configuracion mediante `resolve` y `explain`: decision y motivo deben coincidir para cada filtro.
- [ ] Incorporar vista previa sin envio en configuracion existente de usuario, protegida por `usuarios.configurar`; consultar rol bajo su autorizacion existente.
- [ ] Mostrar efectivo de roles activos, tipos habilitados, prioridad minima, silencio y motivo de exclusion; checks no enviados deben persistir deshabilitados donde el formulario representa la seleccion completa.
- [ ] Distinguir evento no generado, tipo no conectado, cero destinatarios, descarte por preferencias, descartado por autorizacion, vencido y entregado.
- [ ] No depender de un destinatario guardado para probar configuracion; una simulacion no crea eventos ni modifica contadores.
- [ ] Registrar metadatos minimos de diagnostico, acceso restringido y retencion acotada; no conservar payload completo despues de la purga para esquivar la politica de 15 dias.

**Aceptacion:** Administracion puede explicar por que un usuario recibe o no un tipo sin activar permisos nuevos ni enviar mensajes reales.

### Tarea 4. Integridad, deduplicacion y entrega fiable — P1

**Archivos:** `NotificationService`, `OperationalNotificationService`, productores, `routes/console.php` y `config/queue.php` si corresponde. **Esquema:** solo ampliaciones justificadas por la durabilidad; revisar `jobs/failed_jobs` existentes antes de crear otra infraestructura.

- [ ] Probar dos emisiones simultaneas: un evento por ocurrencia y una copia por usuario, ambas solicitudes con resultado controlado. Reutilizar los UNIQUE existentes.
- [ ] Claves por identidad de operacion/revision y destinatario cuando corresponda. Reintentar no reabre ni duplica un aviso; nueva revision legitima no reutiliza un evento vencido.
- [ ] Probar rollback del negocio, fallo al entregar despues del commit y caida/reinicio del procesador. No avisar un negocio revertido ni perder definitivamente uno confirmado.
- [ ] Diseno recomendado para el tramo durable: registrar despacho pendiente en la misma transaccion MySQL del negocio y procesarlo con bloqueo, reintentos y resultado persistido. Reutilizar la cola database existente solo si garantiza esa unidad transaccional y el operador del hosting puede procesarla; si no, una outbox minima de Notificaciones con scheduler. Aprobar esta decision tecnica antes de crear tablas.
- [ ] `DB::afterCommit` puede disparar trabajo, pero no sustituye persistencia durable. No asumir que hay worker permanente o Redis en cPanel.
- [ ] Procesar en lotes con limite, sin solapamiento, espera entre fallos y tope visible; un fallo definitivo no genera otro aviso de fallo en bucle.
- [ ] Revalidar permiso al entregar y el estado de la operacion para no enviar recordatorios resueltos. Conservar la identidad original, no recalcular una clave nueva en cada reintento.

**Aceptacion:** no hay mensajes fantasma, duplicados por carreras ni perdida silenciosa; el estado pendiente/error puede diagnosticarse sin SQL publico.

### Tarea 5. Personal, contratos, habilitacion/EMO y Bienestar — P1

**Archivos:** servicios existentes `PersonalFichaService`, `PersonalIngresoService`, `PersonalContratoService`, `PersonalMinaHabilitacionService`, `EmoAppointmentService`, `PersonalCursoBulkService`, `BienestarPageController` y `OperationalNotificationService`.

- [ ] Conectar cada evento de la matriz despues de la transicion real. Mantener los flujos publico, interno e importado sin emisiones duplicadas.
- [ ] Pruebas: ficha corregida, contrato firmado futuro/vigente, decision de renovacion, vencimiento Lima, EMO compartido entre minas, reprogramacion/anulacion y convalidacion confirmada.
- [ ] Probar que solo configurar o previsualizar un Excel no notifica; confirmar lote produce resumen y no cientos de copias por filas repetidas.
- [ ] No alertar como deshabilitado a quien conserva una aprobacion vigente. Omitir requisitos desactivados y cancelar el pendiente cuando el servicio ya lo resuelve.
- [ ] Separar disponibilidad de informacion medica; permisos de Bienestar/SSOMA no se deducen de prioridad ni de ser jefe.
- [ ] Usar las ventanas de aviso acordadas. No fijar dias de contrato/examen antes de aprobarlos.

**Aceptacion:** cada aviso abre el trabajador, contrato, programacion o lote correcto y respeta la separacion de estos dominios.

### Tarea 6. RQ Mina y RQ Proserge — P1

**Archivos:** controladores Web/API y servicios de RQ Mina/RQ Proserge; `OperationalNotificationService`. **Tests:** `RQMinaApiTest`, `RQProsergeApiTest` y los de Notificaciones.

- [ ] Probar envio por Web/API, cambio real de pedido, cobertura parcial/completa, creador ausente y acceso revocado.
- [ ] Centralizar los disparadores en el punto que confirma el negocio; quitar avisos basados solo en `estado` solicitado y no persistido.
- [ ] No usar consultar/recalcular una pantalla como causa de un nuevo aviso.
- [ ] Audiencia por responsable y alcance del destino real, incluyendo oficina/taller cuando corresponda; no inventar mina si no la tiene.
- [ ] Probar que un RQ completo deja de recordar cobertura pendiente y que modificar pedido genera nueva ocurrencia solo si cambia la cobertura relevante.

**Aceptacion:** un envio/cambio/completado real produce como maximo la comunicacion aprobada, con cero difusion general si falta destinatario.

### Tarea 7. Man Power, Asistencia, Faltas y Mi Asistencia — P1/P2

**Archivos:** `GrupoTrabajoService`, `ManPowerPlanningService`, `AsistenciaService`, `AsistenciaHorarioService`, `AsistenciaCierreService`, `FaltasService`, `CorregirFaltaService`, scheduler y servicios de Notificaciones.

- [ ] Activar o retirar de nuevas configuraciones los tipos solo catalogados, tras verificar la condicion que les corresponde.
- [ ] Usar responsable, fecha, turno y plan vigentes; grupo cancelado o parada terminada no conserva un recordatorio activo sin justificacion.
- [ ] Unificar asistencia sin cerrar y grupo sin cerrar para que un mismo pendiente no genere dos alertas equivalentes.
- [ ] Probar cierre, reapertura, cambio AUSENTE/PRESENTE/TARDANZA y anulacion de falta; el aviso anterior no puede seguir dando instrucciones incorrectas.
- [ ] Resumir faltas por cierre; no notificar cada modificacion antes de cerrar.
- [ ] Mi Asistencia solo recibe datos propios si existe vinculacion `Usuario.personal_id` y permiso; no crear cuentas por importar trabajadores.

**Aceptacion:** el aviso identifica quien debe actuar, sobre que turno/grupo y hasta cuando; los pendientes resueltos no se repiten.

### Tarea 8. Evaluaciones — P2

**Archivos:** `EvaluationCandidateService`, `EvaluacionDesempenoService`, `EvaluacionSupervisorService`, `EvaluacionesHistoricalImportService`, policies y servicios de Notificaciones.

- [ ] Derivar candidatos de los servicios y reglas actuales, no de los BPMN ni de un listado paralelo de personas.
- [ ] Separar diaria desde asistencia, diaria directa, supervisor y residente mensual; no imponer parada a procesos que no la requieren.
- [ ] Avisar solo al evaluador elegible o a quien pueda regularizar; no difundir puntuaciones a todo el scope de mina.
- [ ] Respetar que supervisor se evalua por rol/permiso y residente por capacidad explicita. No crear una subordinacion residente-supervisor inexistente.
- [ ] Revision de diaria tras corregir asistencia al evaluador original, preservando la exclusion de indicadores mientras corresponda.
- [ ] Pruebas: ya evaluado, periodo duplicado, importacion historica incompleta, revision pendiente y acceso revocado. Importacion: resumen de lote.

**Aceptacion:** no hay recordatorio de evaluar algo ya realizado ni acceso nuevo a notas; no se cambian formulas ni promedios.

### Tarea 9. Logistica, EPP, Transporte y Herramientas — P2

**Archivos:** `LogisticaDashboardService`, `EppOperationService`, `EppOperationConfirmationService`, `EppDeliverySignatureService`, `EppCorrectionService`, `TransportePlanningService`, `ParadaHerramientaService` y scheduler.

- [ ] EPP: probar borrador, reserva, firma, confirmacion, correccion, anulacion y reintento de firma. Entregas sin almacen siguen su flujo valido; no inventar una mina.
- [ ] Un evento pertenece a su dominio y Logistica puede mostrarlo; no emitir una segunda copia por verlo en otra pestana.
- [ ] Transporte: cambio real de servicio/turno/asignacion, cancelacion y falta de responsable; solo enlaces y audiencias autorizadas.
- [ ] Herramientas: sustituir audiencia por coincidencia JEFE/PLANE/GERENTE por capacidades y relaciones reales; lista vacia entrega cero.
- [ ] Recordar con fecha limite efectiva. Probar lista ya enviada, RQ cancelado, fecha cambiada y ejecucion repetida del comando.
- [ ] Aprobar previamente umbrales de stock, plazos de firma y alertas de saldos; no incluir reglas financieras o disciplinarias nuevas.

**Aceptacion:** confirmaciones reflejan movimientos reales, avisos sensibles no se amplian por estar en Logistica y no hay recordatorios duplicados de recursos.

### Tarea 10. Experiencia de bandeja y configuracion — P2

**Archivos:** header, vista existente `notificaciones/index.blade.php`, `NotificacionPageController`, Usuarios/Roles y CSS/JS existentes. No crear otro dashboard de notificaciones.

- [ ] Campana como resumen; recuperar la bandeja existente para consulta completa, filtros y paginacion mediante las rutas actuales. Mantener compatible `open_notifications`.
- [ ] Filtros de modulo, tipo, estado, prioridad, fecha/rango y busqueda; mina solo donde aporte contexto autorizado. No perder filtros al leer/archivar ni ocultarlos sin resultados.
- [ ] Un mismo universo autorizado para contador, lista y paginacion. Definir contador global y contador filtrado con etiquetas distintas cuando se necesiten ambos.
- [ ] Alinear Lectura/Abrir/Archivar segun permisos. Distinguir Leida, Abierta y Pendiente resuelto, sin presentar ACTIONED como negocio terminado.
- [ ] No mostrar Abrir si el destino no es valido o autorizado; devolver resultado amigable si cambia entre render y clic.
- [ ] Reutilizar estilos, mensajes, botones y estados de carga; conservar foco y no reinsertar todo el panel por cada poll sin cambios.
- [ ] Pruebas de navegador desktop/movil: filtros vacios, texto largo, permisos revocados, doble clic, cambio de pestana, sesion vencida y errores de red.

**Aceptacion:** el usuario entiende que ocurrio y que puede hacer sin navegar a una pantalla que sera rechazada inevitablemente.

### Tarea 11. Rendimiento, cron, retencion y despliegue — P2

**Archivos:** resolver, bandeja, header, `NotificationsCleanupExpiredCommand`, `routes/console.php`, esquema y documentacion operativa.

- [ ] Medir consultas/latencia con datos sinteticos: 100, 1 000 y 10 000 destinatarios, bandejas extensas y varias pestanas. Evitar usuario x rol x aviso en consultas.
- [ ] Reutilizar indices actuales y EXPLAIN antes de agregar otros. Paginar/fragmentar, proyectar columnas y resolver roles completos en lote.
- [ ] Poll sin peticiones superpuestas, pausa con pestana oculta, espera progresiva ante fallos y detener ante 401/403; al volver recuperar estado. Intervalo final segun medicion, no una promesa fija de tiempo real.
- [ ] No registrar cada poll exitoso en INFO ni enumerar toda la audiencia en logs operativos normales; mantener errores y metricas minimas sin PII.
- [ ] Verificar cron/worker real en cPanel, zona horaria operativa Lima, ejecucion sin solapamiento y ultima ejecucion satisfactoria. Un schedule declarado no prueba que corra en produccion.
- [ ] Probar limites de expires_at y purga +15 dias, distintas prioridades, eventos sin destinatarios y estados READ/ACTIONED/ARCHIVED; no tocar historial del negocio.
- [ ] Diagnosticos/outbox tambien tienen minimizacion y limpieza; no retener mensajes purgados en otra tabla indefinidamente.
- [ ] Desplegar por etapas con backup verificable, migraciones incrementales primero cuando sean necesarias y tipos nuevos desactivados hasta validacion. No reactivar preferencias antiguas ni inundar usuarios con pendientes historicos.
- [ ] Validar con usuarios representativos y rollback de codigo compatible con el esquema; no borrar notificaciones o restaurar la BD a ciegas para revertir una interfaz.

**Aceptacion:** entrega y consulta tienen un costo medido, cron observable y retencion aplicada sin afectar datos operativos.

## 8. Orden recomendado y puntos de control

1. **Seguridad y coherencia:** tarea 1, despues tarea 2. No ampliar emisores antes de cerrar las fugas de audiencia y acceso.
2. **Saber que llega:** tarea 3. Administracion puede verificar roles/usuarios antes de habilitar mas tipos.
3. **No perder ni duplicar:** tarea 4 y validacion de infraestructura real.
4. **RRHH y requerimientos:** tareas 5 y 6, por proceso y con revision independiente.
5. **Operacion diaria:** tarea 7 y despues tarea 8.
6. **Recursos:** tarea 9, separando cada dominio para no crear un bloque de Logistica imposible de verificar.
7. **Interfaz y escala:** tarea 10; tarea 11 cierra rendimiento y despliegue. Las medidas de seguridad/rendimiento imprescindibles no se posponen hasta el final.

Despues de cada tarea informar: hallazgo inicial, archivos cambiados, backend/interfaz, reglas, pruebas agregadas y ejecutadas, resultado, decisiones pendientes, comprobacion manual y necesidad real de migracion/cron. No continuar automaticamente un bloque de negocio con reglas aun no acordadas.

## 9. Decisiones pendientes antes de activar avisos nuevos

| Decision | Propuesta inicial, no regla implementada |
|---|---|
| Responsable por tipo/proceso | Usar relaciones y capacidades existentes; si falta responsable, mostrar configuracion pendiente, no enviar a todos ni a Gerencia por defecto. |
| Dias antes de vencer contratos/cursos/EMO | Acordar ventanas por proceso; no elegir 7/15/30 dias sin validacion del area. |
| Horarios de marcar/cerrar y frecuencia de recordatorio | Usar turno/fecha y plazos del proceso; confirmar horario de escalamiento. |
| Escalamiento | Solo a un responsable superior identificado y autorizado, nunca derivado por texto del cargo. |
| Incidentes nuevos frente a recordatorio | Notificar primera ocurrencia y cambios relevantes; recordatorio resumido con intervalo aprobado, no en cada consulta o poll. |
| Cumplimiento de pendientes | Resolver desde el estado real; permitir archivar la copia sin fingir que cerro el contrato, examen o asistencia. |
| Despacho durable | Preferir la infraestructura MySQL existente si garantiza persistencia atomica y procesamiento; sino aprobar outbox minima y cron compatible con cPanel. |

No estan pendientes GERENTE, roles inactivos, borrado +15 dias ni silenciamiento de criticas: esas decisiones ya fueron confirmadas y no deben volver a suponerse distintas.

## 10. Verificacion final y salida a uso real

Comandos iniciales de regresion (solo BD aislada `proserge_app_test`):

```powershell
$env:APP_ENV='testing'
$env:DB_DATABASE='proserge_app_test'
$env:DB_URL=''
php -d memory_limit=1024M vendor/phpunit/phpunit/phpunit --filter '(OperationalNotificationServiceTest|UsuarioNotificationTest|WebPermissionMiddlewareTest|RolePermissionCheckboxTest|RbacOperationalScopeTest)'
git diff --check
```

Anadir pruebas nuevas de permisos API, destinatarios, bandeja, idempotencia, entrega/reintento, enlaces, caducidad, cron y cada productor; los nombres anteriores no implican que ya cubran esos casos. Ejecutar PHP lint, Blade si se toca interfaz, build si cambian assets y suite completa antes de publicar cambios transversales.

Matriz manual minima: ADMIN activo, GERENTE limitado, RRHH, planner/residente/supervisor con permisos concretos, usuario solo lectura, usuario sin Notificaciones, rol inactivo y usuario con silenciamiento. Probar permisos reales, no solo ocultacion de botones.

**Listo para uso real cuando:** por cada evento activo se puede demostrar quien lo genera, quien lo recibe y quien no, que dato se muestra, a donde lleva, cuando deja de ser pertinente, como se reintenta sin duplicar y cuando se elimina. La aprobacion del area usuaria complementa, no reemplaza, las pruebas tecnicas.
