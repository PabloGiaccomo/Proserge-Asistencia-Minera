# Correcciones de permisos de notificaciones

Fecha: 2026-10-21. Estado: corregido y verificado localmente; despliegue pendiente.
Origen: auditoria `2026-10-21-notificaciones-permisos-usuarios-auditoria.md`.
Autorizacion del usuario: "Realiza las correcciones".

## Alcance y estado

- [x] Validar y guardar correctamente los booleanos de Roles/Usuarios.
- [x] Conservar silencios al guardar preferencias.
- [x] Alinear ocho tipos de aviso con las capacidades reales de sus destinos.
- [x] Actualizar acciones de la campana y retirar contenido ante 401/403.
- [x] Probar concurrencia entre polling y escritura, y enlace de contrato pendiente.
- [x] Cerrar regresion ampliada y revision final.

No se cambiaron usuarios, roles o permisos reales, ni contratos, trabajadores,
historial o documentos. No hubo push, FTP, migraciones de produccion ni regeneracion
de BPMN/Graphify. Se conservaron los cambios locales previos de otros modulos.
Se continuo en el checkout existente porque contiene el trabajo aprobado anterior.

## Antes de modificar

- Roles trataba presencia de clave como true, incluso notificaciones[id]=0.
- Usuarios aceptaba valores no booleanos y arrays para in_app_enabled.
- Guardar prioridad escribia null en ambos campos de silenciamiento.
- El catalogo exigia Personal Actualizar para destinos con permisos independientes.
- El encabezado fijaba Actualizar al cargar la pagina; no limpiaba ante 401/403.
- La prueba de clic descubrio que ficha aprobada/contrato pendiente acababa en
  revision de ficha por una heuristica de titulo, aunque el destino era Contratos.

## Backend y reglas implementadas

### Configuracion

UsuarioPageController valida estructura y booleano por tipo, consume solo datos
validados y conserva muted_until/mute_until. False, 0 y "0" se guardan false;
valores invalidos se rechazan antes de escribir. Una prioridad nullable usa low,
no una cadena vacia incompatible con el catalogo. Un check omitido para un tipo
incluido sigue desactivandolo.

RolPageController valida cada valor de notificaciones. RoleManagementService
normaliza el valor en vez de conceder por presencia de clave. No se agregaron
permisos globales ni cambios a PermissionMatrix o middleware de autorizacion.

### Catalogo

Migracion incremental: `2026_10_21_000100_align_notification_destination_permissions.php`.
Solo actualiza module, required_permission_module, required_permission_action y
updated_at de tipos existentes. No crea tipos, no cambia IDs, is_active, titulos,
preferencias, roles, destinatarios ni eventos historicos. Probada dos veces para
verificar idempotencia. Down no restaura requisitos inseguros de Personal Actualizar.

| Tipos | Capacidad real del destino |
| --- | --- |
| habilitacion_mina_actualizada / habilitado / estado_critico y habilitacion_examen_programado | habilitacion_minera.ver |
| personal_contrato_firmado, contrato_no_renovado_cerrado | personal.ver_contratos |
| contrato_decision_renovacion | vencimientos.ver |
| personal_ficha_aprobada_falta_contrato | personal.editar_datos_contrato |

Ruling: se corrigio la suposicion de la auditoria inicial para Contratos.
Las rutas actuales exigen Personal Ver contratos / Editar datos contrato,
no basta personal_contratos.ver (routes/web.php:140, 220). Se usa la capacidad
real, sin modificar rutas ni ampliar accesos para hacer coincidir una suposicion.
El agrupamiento del aviso si usa personal_contratos cuando corresponde.
Costo: la separacion futura del RBAC de Contratos no queda resuelta en esta entrega.

El permiso de Notificaciones Ver, usuario/roles activos, preferencias y alcance
existentes siguen siendo condiciones adicionales. La preferencia por tipo no
concede acceso. ADMIN/SUPERADMIN activos conservan el privilegio RBAC existente;
las entregas criticas siguen respetando silencio. Habilitacion no hereda scope
de mina por aparecer una mina en el mensaje.

## Interfaz y contrato de polling

- El poll agrega can_update booleano basado en el usuario autenticado actual.
  Sin Actualizar no incluye URLs funcionales de leer/archivar.
- El encabezado actualiza sus controles desde ese campo; una respuesta sin la
  capacidad no concede escritura. No depende del permiso del render inicial.
- Ante 401/403 en el poll se detiene el intervalo, se retiran contenido, botones,
  panel y backdrop y se restaura el scroll. El backend sigue autorizando cada POST.
- Un POST 403 retira controles de escritura y consulta permisos de nuevo; no
  suspende toda la campana si el usuario aun conserva Ver.
- Singleflight evita consultas superpuestas; una revision invalida respuestas
  pendientes despues de una escritura o cambio de acceso y fuerza refresco inmediato.
- Abrir sigue siendo GET sin cambios de lectura. El tipo de contrato pendiente
  construye su ruta de datos de contrato, sin confundirse con revisar ficha.
  No se reescribieron las demas heuristicas historicas ni toda la bandeja.

Se reutilizaron el Blade, estilos, formularios y panel existentes. Sin dependencias,
CSS nuevo o componentes/pantallas nuevas. No cambiaron assets compilables: no se
ejecuto npm build; el JavaScript modificado vive en el Blade.

## Archivos

- app/Modules/Seguridad/Controllers/UsuarioPageController.php
- app/Modules/Seguridad/Controllers/RolPageController.php
- app/Modules/Seguridad/Services/RoleManagementService.php
- app/Modules/Notificaciones/Controllers/NotificacionPageController.php
- resources/views/partials/header.blade.php
- database/migrations/2026_10_21_000100_align_notification_destination_permissions.php
- tests/Feature/NotificationUserPreferencesTest.php
- tests/Browser/notification-permissions.cjs
- tests/Browser/notification-permissions-race.cjs
- tests/Browser/role-permissions.cjs (fixture incorpora can_update)
- docs/ARCHITECTURE_MAP.md, auditoria original y este informe.

## Pruebas y evidencia

- Se promovieron las pruebas temporales de auditoria a regresiones versionables.
  RED: 11 fallos en checks, silencio y permisos de destino antes de corregir.
- RED adicional: poll no entregaba can_update; enlace de contrato redirigia a
  revision de ficha; poll pendiente descartaba refresco tras POST exitoso.
- Feature focalizado actual: 37 pruebas / 173 assertions, sin fallos.
- Regresion ampliada final: **381 pruebas / 4668 assertions**, sin fallos,
  en 01:15.165. Incluye Notificaciones, Usuarios/Roles, RBAC, Contratos,
  renovaciones/no renovados/vencimientos, EMO, reparacion y transacciones mineras.
- Browser: 10 escenarios de estado/permisos en 1440 y 390 px, todos pasan.
  Incluyen detener polling tras 401/403 y ausencia de capacidad sin conceder permisos.
- Browser race: POST 200 y 403 intercalados con poll retenido: ambos pasan,
  sin repintar la respuesta obsoleta y con consulta inmediata al terminar.
- Browser role-permissions: checks independientes, escritorio/movil y acciones
  de notificaciones con lectura/escritura pasan.
- Capturas sinteticas en storage/codex_outputs/notification-permissions revisadas:
  panel encuadrado, sin desbordamiento horizontal ni solapamiento de controles.
- PHP lint de los seis PHP nuevos/tocados: correcto. Blade view:cache: correcto.
- Pint --test de Notificaciones, migracion y test nuevo: correcto. Pint detecta
  estilo previo pendiente en los tres archivos de Seguridad; no se reformatearon
  archivos completos por una correccion de pocas lineas.
- git diff --check sin errores. No se ejecuto la suite completa del programa.
- Revision independiente: detecto refresco omitido tras POST exitoso con poll
  pendiente; se reprodujo RED, se corrigio y se probo junto con POST 403. Revision
  final sin defectos confirmados. El fallback del enlace a entity_id sin payload
  personal_id sigue sin caso especifico; el caso real del productor esta cubierto.

Todos los datos de pruebas fueron sinteticos y transaccionales, en proserge_app_test
aislada en puerto 3307. La migracion solo se ejecuto ahi. No se certifican usuarios,
roles, preferencias o cron efectivos del hosting. El MySQL habitual local no fue
reparado ni se migro una copia con datos de trabajadores.

## Despliegue pendiente

El codigo y la migracion aun no se subieron a produccion. Tras un respaldo
verificado y la autorizacion correspondiente, ejecutar en el proyecto correcto:

```bash
php artisan migrate --path=database/migrations/2026_10_21_000100_align_notification_destination_permissions.php --force
php artisan optimize:clear
```

No usar este paso para ejecutar otras migraciones pendientes sin revisarlas.
No reenviar automaticamente eventos historicos ni reactivar tipos/preferencias
para compensar una configuracion faltante. Verificar el catalogo y roles reales
en el hosting antes de considerar desplegada esta correccion.

## Comprobacion manual

1. Desactivar un tipo en Roles/Usuarios y guardar: permanece desactivado y no llega.
2. Con silencio vigente, cambiar solo prioridad: conserva el silencio.
3. Con campana abierta, quitar Actualizar del rol: desaparecen los botones; volver
   a concederlo los recupera en el siguiente poll, sin cerrar sesion.
4. Quitar Ver o vencer sesion: no permanece el contenido del aviso en la campana.
5. Habilitacion/Contratos: el aviso llega solo al usuario con la capacidad del
   destino y preferencias correspondientes; no necesita Personal Actualizar general.
6. Abrir Ficha aprobada con contrato pendiente: abre Datos de contrato, no Revisar ficha.

No quedaron decisiones nuevas de negocio pendientes para estos cuatro arreglos.
