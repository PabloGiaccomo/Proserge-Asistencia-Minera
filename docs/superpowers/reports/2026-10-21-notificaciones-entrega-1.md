# Notificaciones: ejecucion de la primera entrega

Plan: `docs/superpowers/plans/2026-10-19-notificaciones-por-modulo.md`.
Fecha: 2026-10-21, America/Lima. Estado: COMPLETADA (tarea 1).

## Alcance aprobado

Primera entrega: tarea 1, seguridad de destinatarios y paridad Web/API.
Conservar datos, preferencias, rutas y reglas de los dominios. Las alertas
criticas respetan silenciamiento. No realizar push, FTP ni migraciones de
produccion. No ejecutar en esta entrega todos los bloques del plan.

## Estado y decisiones

- [x] Recuperar plan, codigo actual y cambios locales ajenos.
- [x] Agregar regresiones de entrega, roles, API, revocacion y aislamiento.
- [x] Observar las regresiones fallar por los defectos, no por infraestructura.
- [x] Corregir autorizacion compartida, seleccion y consultas.
- [x] Ejecutar regresiones y revision final.
- [x] Actualizar plan y registrar resultado.

Ruling: continuar en el checkout y rama codex actuales sin crear otro worktree
ni copiar cambios locales ajenos: esta es una continuacion con trabajo sin
confirmar en varios dominios y el usuario no solicito aislamiento. Costo si
fuera incorrecto: las modificaciones conviven en el working tree; revisar y
confirmar solo los archivos de esta entrega, nunca hacer reset o stash global.

La primera ejecucion de pruebas detecto MySQL local apagado (2002). Se inicio
el proceso XAMPP existente en modo oculto; aun no completaba handshake en la
comprobacion posterior. Se detuvieron solo los clientes de prueba propios,
sin reparar, eliminar o migrar bases existentes. La base de pruebas se debe
verificar antes de continuar. Un error de infraestructura no es evidencia RED.

Se preparo una instancia MariaDB temporal aislada en puerto 3307, con esquema
`proserge_app_test` creado exclusivamente desde database/setup y migraciones.
No se importaron datos reales ni se modifico el esquema local del puerto 3306.
El servidor original iniciado por esta tarea no completo el arranque y fue
detenido; no se intento repararlo. La configuracion versionada no se cambia.
Finalizadas las pruebas, la instancia temporal se apago normalmente. Su
directorio y script quedaron excluidos mediante .git/info/exclude local, para
no subir artefactos de prueba accidentalmente; no se versiona ese ajuste. La
limpieza del directorio fue bloqueada por la politica de ejecucion y no se
reintento. El arranque del MySQL local habitual queda pendiente de diagnostico
separado; no se repararon ni borraron sus bases.

## Hallazgos y correcciones

- El resolver aceptaba GERENTE/globales antes del permiso de dominio y usaba
  roles adicionales inactivos en preferencias. Ahora carga roles completos,
  exige Notificaciones Ver + permiso declarado por tipo y excluye roles inactivos.
- Una audiencia explicita vacia podia convertirse en difusion: ahora no entrega.
- `require_permission=false` ya no evita autorizacion. Overrides de contexto
  pueden estrechar, no reemplazar, el permiso declarado por tipo.
- Preferencias globales, por tipo y roles activos siguen filtrando entregas,
  incluso criticas para ADMIN/SUPERADMIN. No se reescribieron preferencias.
- API exige Ver/Actualizar y configuracion general igual que Web. El privilegio
  administrativo activo ADMIN/SUPERADMIN sigue el mecanismo RBAC existente;
  no obliga a recibir avisos silenciados.
- Actualizar sin Ver conserva las escrituras autorizadas existentes, pero la
  respuesta API no incluye el evento (mensaje/payload). Con Ver conserva el
  contrato de respuesta existente.
- Bandeja, contador, campana, consultas directas y marcar todas filtran propietario,
  expiracion, dominio y scope vigente antes de consultar/actualizar en SQL.
  Revocar acceso no elimina el historial ni marca sus avisos como leidos.
- Scope RQ Proserge reutiliza su policy (scope explicito prevalece incluso para
  GERENTE). Los demas dominios operativos usan reglas actuales de scope global
  y administrar. Personal/Habilitacion no exige scope por incluir mina.
- Requisitos adicionales del emisor se normalizan en la policy, se conservan
  bajo `payload._notification_access` del evento nuevo y se revalidan en SQL.
  El emisor no acepta esa clave del payload entrante como autorizacion.
  El recordatorio historico `lista_herramientas_por_vencer` conserva el requisito
  Man Power de su productor, aunque antes no se guardaba en el evento.
- En avisos nuevos se resuelve la mina desde RQ Mina/RQ Proserge cuando falta.
  Para lectura historica con mina NULL se consulta la parada referenciada, sin
  modificar eventos antiguos. Una referencia payload contradictoria no puede
  imponerse a entity_type/entity_id de una parada. NULL no omite scope operativo.
- Herramientas reutiliza `ParadaHerramientaService::accessibleParadasQuery`,
  que aplica las restricciones existentes de mina y supervisor. Compartir mina
  no permite ver la parada de otro supervisor; el supervisor propio y el permiso
  Herramientas Administrar conservan las excepciones que ya tenia el dominio.
  El contexto adicional estrecha permisos; el dominio de destino decide el scope.
- El fallback historico admite aliases/clases de RQ Proserge sin mina ni payload,
  enlazando con su parada y comprobando el alcance actual en SQL.
- Se adelanto el punto GET de tarea 2: Abrir no modifica READ/ACTIONED. Se
  conservan los POST existentes para lectura/archivo y sus permisos.

Ruling: agregar `NotificacionesPolicy`, inexistente antes, para compartir
autorizacion de tipo y scope entre resolver y bandeja/API; no crear otra matriz
ni modificar PermissionMatrix/middleware global. No introduce tablas/rutas.

## Archivos de esta entrega

- `app/Modules/Notificaciones/Policies/NotificacionesPolicy.php` (nuevo).
- `app/Modules/Notificaciones/Services/NotificationRecipientResolverService.php`.
- `app/Modules/Notificaciones/Services/NotificationInboxService.php`.
- `app/Modules/Notificaciones/Services/NotificationService.php`.
- `app/Modules/Notificaciones/Controllers/NotificacionController.php`.
- `app/Modules/Notificaciones/Controllers/NotificacionPageController.php`.
- `app/Modules/ParadaHerramientas/Services/ParadaHerramientaService.php`: solo
  import de Builder y consulta publica reutilizando dos filtros privados ya
  existentes; no cambia el flujo ni reglas de Herramientas.
- `tests/Feature/NotificationAuthorizationTest.php` (nuevo).
- `tests/Feature/UsuarioNotificationTest.php`: fixture de silenciamiento recibe
  permiso usuarios.crear, en lugar de depender de un bypass de autorizacion.
- `docs/ARCHITECTURE_MAP.md`, plan aprobado y este informe.

No se modificaron Blade/CSS/JS: el cambio visible se obtiene con las consultas
autorizadas de la campana existente. No hubo build ni redisenos de pantallas.

## Evidencia de pruebas

- RED inicial contra instancia aislada: 19 fallos de comportamiento y 3 errores
  de fixture (faltaba minas.unidad_minera); fixture corregido. Los fallos de
  autorizacion se observaron antes de editar codigo funcional.
- GREEN inicial: 23 pruebas nuevas / 71 assertions.
- RED adicional: override permission_module ignorado; corregido sin ampliar acceso.
- Revision independiente detecto tres casos adicionales: contenido API devuelto
  sin Ver, requisito dinamico no conservado y mina NULL en recordatorios de
  herramientas. Cuatro pruebas RED los reprodujeron; corregidos con regresion.
  Otra prueba RED demostro referencias historicas contradictorias: tambien corregida.
- Segunda revision: tres hallazgos (supervisor, legacy RQ Proserge y Administrar
  Herramientas), reproducidos con cuatro pruebas RED y corregidos reutilizando
  el dominio. Suite focalizada actual: 38 pruebas / 132 assertions, sin fallos.
- Regresion: NotificationAuthorizationTest, OperationalNotificationServiceTest,
  UsuarioNotificationTest, WebPermissionMiddlewareTest, RolePermissionCheckboxTest
  y RbacOperationalScopeTest: 112 pruebas / 3066 assertions, sin fallos.
- Regresion conjunta final: **471 pruebas / 5425 assertions**, sin errores ni
  fallos (01:43.993). Incluye los seis grupos principales anteriores, Bienestar,
  reparacion/identidad/transacciones mineras, EMO individual/masivo/web, flujo
  corregido de habilitacion, Herramientas y tres grupos de Logistica/EPP.
- PHP lint de los nueve PHP tocados y `git diff --check`: sin errores.
  Pint --test: los ocho archivos de Notificaciones/tests pasan. Herramientas
  conserva su formato previo, con solo import y metodo nuevo, validado por lint
  y regresion del dominio; no se formateo el archivo completo.
- Revision independiente final: sin defectos confirmados ni bloqueos restantes
  en el alcance. No sustituye auditoria ni pruebas de todo el programa.
- Sin recorrido manual desktop/movil: no cambio Blade/CSS/JS y el MySQL local
  habitual no arranco; Web/API y sus flujos de autorizacion se probaron por HTTP
  automatizado sobre la instancia aislada. No se ejecuto la suite completa.

## Limites y siguiente entrega

No es la implementacion de todos los modulos del plan. Siguen pendientes la
conciliacion del catalogo/permisos de destino, heuristicas de enlaces de ficha,
diagnosticos por usuario, reintentos durables, nuevos productores y mejora visual.
No se verificaron datos/cron/roles efectivos del hosting. Sin migracion nueva.
Los eventos nuevos guardan el requisito dinamico. Para historicos se respeta el
tipo y se reconstruye el caso conocido de herramientas desde el productor real.
No es posible inventar overrides historicos desconocidos: su conciliacion con
metadatos y destinos sigue siendo parte de tarea 2 antes de ampliar emisores.

Sin decisiones de negocio nuevas pendientes para esta entrega.

## Comprobacion manual

1. Usuario con Ver sin Actualizar: consultar campana y abrir enlace; no puede
   marcar leido, marcar todas ni archivar; Abrir no cambia el estado del aviso.
2. Revocar permiso de dominio o scope RQ Proserge: al refrescar contador/lista,
   el aviso desaparece; URL antigua no permite leerlo ni archivarlo.
3. Usuario con roles adicionales: desactivar uno no elimina permisos de otros
   roles activos ni aporta su preferencia de tipo.
4. Silenciar notificaciones y emitir una critica con permiso: no llega. ADMIN
   conserva su acceso administrativo, no una suscripcion forzada.
5. Habilitacion: una mina como contexto no excluye al responsable por scope.
