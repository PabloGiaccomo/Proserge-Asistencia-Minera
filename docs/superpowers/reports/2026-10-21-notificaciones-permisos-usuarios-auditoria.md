# Auditoria de permisos de notificaciones y usuarios

Fecha: 2026-10-21. Revision posterior a la primera entrega de Notificaciones.
Estado original: revision completada y defectos reproducidos.
Correcciones aprobadas y aplicadas localmente despues de esta auditoria:
`2026-10-21-notificaciones-permisos-corregidos.md`. Este documento conserva la
evidencia previa; no describe los fallos como presentes despues del arreglo.

## Alcance y proteccion

Se revisaron Roles -> Usuarios -> preferencias -> resolver -> campana -> Web/API,
con el codigo de la revision y cambios locales previos conservados. Graphify se consulto
solo como mapa; no se actualizo. Durante la auditoria no se modifico codigo funcional ni se realizaron
push, FTP, migraciones, cambios de roles, usuarios o preferencias reales.

Pruebas con usuarios sinteticos, DatabaseTransactions y guardias de nombre de
base/puerto: proserge_app_test en instancia aislada 3307. No se auditaron las
asignaciones efectivas de cuentas de produccion: estos resultados no certifican
que cada usuario del hosting tenga la configuracion correcta.

## Hallazgos

### P1. Catalogo de tipos usa permisos de otro modulo

Fuente: database/migrations/2026_06_16_000300_refine_operational_notification_types.php,
lineas 46, 79, 90, 101 y 112; consumo en NotificacionesPolicy::canViewType.

Los avisos habilitacion_examen_programado, habilitacion_mina_estado_critico y
personal_contrato_firmado conservan personal.actualizar como requisito.
Los modulos destino tienen permisos independientes habilitacion_minera y
personal_contratos; no deben inferirse de Personal cuando hay false explicito.

Se reprodujeron seis fallos, dos por tipo:

- Usuario con Ver/Actualizar del dominio + Notificaciones Ver, sin Personal
  Actualizar: excluido de entrega.
- Usuario con Personal Actualizar + Notificaciones Ver pero dominio denegado:
  incluido en entrega. En Habilitacion su URL real devuelve 403, mientras que
  el resolver le entrega el aviso de ese dominio.

Corregir mediante actualizacion incremental del catalogo y pruebas por permiso
real del destino, conservando IDs, is_active y preferencias existentes. No editar
la migracion historica ni conceder Personal completo para compensar el problema.
Este punto ya estaba pendiente en tarea 2 del plan; la primera entrega solo
aseguraba respetar el requisito declarado, no que todo el catalogo fuera correcto.

### P2. El guardado de checks no respeta todos los valores booleanos

RoleManagementService::syncNotificationRolePreferenceRows, lineas 246-257,
decide por presencia de clave. Enviar notificaciones[id]=0 en el formulario
real de Roles persiste is_enabled=true. Un checkbox desmarcado y omitido en el
formulario normal si funciona; el fallo es el valor false/0 explicito aceptado.

UsuarioPageController::updateNotificationPreferences, lineas 350-355 y 397,
no valida preferences.*.in_app_enabled como booleano. Los valores string
"false", string "enabled" y array no vacio se aceptan (302 de guardado) en vez
de rechazarse; el cast bool de PHP los interpreta como true.

Corregir validacion de estructura/booleanos en ambos formularios y normalizacion
en el servicio. Mantener que checks omitidos de tipos incluidos se deshabiliten.
No hay bypass del permiso de configurar: el actor necesita usuarios.configurar
o el permiso correspondiente de Roles para llegar a estas escrituras.

### P2. Guardar preferencias cancela silenciamientos sin accion explicita

UsuarioPageController, lineas 378 y 400: se escriben muted_until=null y
mute_until=null incondicionalmente. Guardar una prioridad con silencios activos
los elimina. El formulario de Usuarios no ofrece un control de desilenciar que
explique ese cambio. La regla confirmada es respetar preferencias y silencio.

Corregir preservando esos campos salvo una accion explicita de desilenciar.

### P2. Una pestana abierta conserva controles con permisos antiguos

resources/views/partials/header.blade.php, lineas 134, 219, 281 y 403:
canUpdateNotifications se fija al render inicial. Poll no actualiza permisos.
Un 401/403 solo genera log; no limpia la campana ni detiene el intervalo.

Tres pruebas de Chrome headless con el Blade real y respuestas de polling
sinteticas del mismo contrato reprodujeron:

- Revocar Actualizar: quedan tres formularios de escritura visibles.
- Revocar Ver (poll 403): permanecen aviso y controles previamente cargados.
- Conceder Actualizar: siguen sin aparecer controles hasta recargar pagina.

El backend si rechaza escrituras tras revocacion; no se demostro una escritura
indebida por esos botones. Es inconsistencia de UI y permanencia de contenido
ya cargado, no nueva entrega desde el servidor despues del 403.

Corregir sincronizando capacidades actuales con el polling y limpiando/deteniendo
la bandeja ante 401/403. Mantener el backend como fuente de autorizacion.

## Comprobaciones satisfactorias

- Usuarios sin usuarios.configurar no pueden guardar preferencias ajenas, incluso
  teniendo Notificaciones Actualizar y Usuarios Editar.
- Habilitar preferencias no concede permisos ausentes del rol.
- Desmarcar un tipo en Usuarios impide su entrega y no altera otro usuario.
- Una revocacion guardada por el formulario real de Roles se aplica a Web en
  la siguiente peticion, sin cerrar sesion.
- Prioridad invalida falla antes de cambiar configuracion general o preferencias.
- La suite previa cubre roles activos/adicionales/inactivos, GERENTE sin bypass,
  silencios criticos, propietario, scope, expiracion y paridad API/Web.

## Evidencia y limites

1. Suite existente: NotificationAuthorizationTest, OperationalNotificationServiceTest,
   UsuarioNotificationTest, WebPermissionMiddlewareTest, RolePermissionCheckboxTest,
   RbacOperationalScopeTest: **122 pruebas / 3107 assertions, todas pasan**.
2. Auditoria adicional temporal: **16 pruebas / 77 assertions, 11 fallos esperados**
   que reproducen los defectos anteriores; cinco controles satisfactorios. No son
   once defectos diferentes: incluyen variantes booleanas y perfiles por tipo.
3. Navegador: **3/3 escenarios de cambios en pestana abierta fallan**. No es
   navegacion contra usuarios/roles reales del hosting: se usa HTML real y API
   simulada, junto con prueba HTTP separada de revocacion efectiva del backend.
4. Pruebas temporales excluidas localmente de Git:
   .codex-tmp/NotificationUserPermissionAuditTest.php y
   .codex-tmp/notification-permissions-audit.cjs. No se agregaron fallos a la
   suite automatica del repositorio ni se cambiaron expectativas para ocultarlos.
5. git diff --check sin errores. No hubo cambios en Blade, PHP funcional,
   CSS/JS compilable ni esquema. No corresponde rebuild o despliegue en esta revision.

## Correccion recomendada

Cambios acotados sobre las piezas actuales: conciliar tipos/permisos de destino,
validar y guardar booleanos, conservar silencios y refrescar controles de la
campana con permisos vigentes. Promover las pruebas temporales a regresiones del
repositorio al aplicar cada arreglo. No redisenar roles ni dar permisos globales.
Verificar luego la configuracion real de cuentas en un entorno autorizado,
sin publicar nombres, documentos o payloads sensibles.
