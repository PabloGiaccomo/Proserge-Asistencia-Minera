# Autorizacion de operaciones EPP

Las operaciones por lote usan permisos directos del modulo `epps`, separados
de la visibilidad de las pestanas de Logistica. El modulo EPP aparece en el
editor de permisos; el menu operativo sigue dentro de Logistica.

Capacidades: `ver_operaciones`, `preparar_entrega`, `confirmar_entrega`,
`preparar_devolucion`, `confirmar_devolucion`, `ver_evidencia`,
`descargar_constancia`, `gestionar_inventario`, `ajustar_inventario`,
`corregir`, `anular` y `aprobar_excepcion_firma`.

`ver_logistica_entregas` conserva acceso de lectura legado (`epps.ver`), pero
no concede escrituras ni acceso a evidencia firmada. Los permisos generales
de Logistica no se usan como fallback para estas capacidades. La aprobacion
de testigo autorizado exige `aprobar_excepcion_firma`; no autoriza omitir la
firma real ni su validacion en el flujo de aceptacion.

## Compatibilidad explicita

Al normalizar permisos historicos EPP, solo si la capacidad nueva no existe
en el documento original, se conserva esta equivalencia:

| Grant EPP historico | Capacidad nueva |
|---|---|
| `ver` | `ver_operaciones` |
| `registrar` | `preparar_entrega`, `confirmar_entrega` |
| `devolver` | `preparar_devolucion`, `confirmar_devolucion` |
| `editar`, `actualizar` | `corregir` |
| `eliminar` | `anular` |
| `configurar` | `gestionar_inventario` |
| `administrar` | Todas las capacidades salvo `aprobar_excepcion_firma` |

Un valor nuevo explicitamente `false` prevalece sobre el grant antiguo.
La normalizacion es idempotente, no escribe roles en la base y admite las
listas antiguas `epps.read`, `epps.write`, `epps.admin`/`epps.manage`.
Las matrices completas creadas con capacidades nuevas en `false` requieren
seleccionar las capacidades deseadas en el editor de roles. No se migra un
permiso de Logistica ni la visibilidad de una pestana a un grant de escritura.

## Alcance y gates

`EppScopeService` exige usuario `ACTIVO`. ADMIN, GERENTE y SUPERADMIN usan el
criterio comun de `MinaScopeAccess` y ven todos los almacenes/trabajadores.
Los demas usuarios requieren la mina del almacen en `usuario_mina_scope` y,
para un trabajador, al menos una relacion `personal_mina.activo = true` cuya
mina este dentro de su scope. No se exige habilitacion aprobada ni se cambia
el estado laboral. Los almacenes sin mina son exclusivos de privilegiados.

`EppOperationPolicy` se registra para `EppOperacion` en `AppServiceProvider`.
Los gates `view`, `confirmDelivery`, `confirmReturn`, `viewEvidence`,
`downloadReceipt`, `correct`, `cancel` y `approveSignatureException` reciben
una operacion y comprueban tanto su almacen actual como su trabajador.
`prepareDelivery`/`prepareReturn` reciben almacen y trabajador;
`manageInventory`/`adjustInventory` reciben almacen. `viewAny` comprueba la
capacidad de listado; la consulta del listado debe aplicar ademas
`visibleWarehouseIds` y el alcance de trabajador.

Las futuras rutas deben usar sesion web propia (`web.auth`), permiso directo
`web.permission:epps,<capacidad>` y la policy/servicio de alcance. Los cambios
de estado, reglas de firma e inventario pertenecen a los servicios de dominio;
esta policy comprueba autorizacion. No hay bypass de estado laboral o de
firma por rol privilegiado.
