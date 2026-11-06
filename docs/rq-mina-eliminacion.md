# Eliminacion de un RQ Mina

## Flujo actual

El menu Mas > Eliminar envia un POST a `rq-mina.destroy`. Requiere sesion web,
CSRF, permiso `rq_mina,eliminar` y autorizacion de `RQMinaPolicy`. El servicio
elimina los registros vinculados al RQ seleccionado dentro de una transaccion.
Un fallo revierte los cambios y muestra un mensaje de error.

El alcance actual incluye RQ Proserge, Man Power, asistencia, faltas y tambien
herramientas, evaluaciones y registros operativos relacionados. No equivale a
vaciar globalmente seis modulos: los registros sin vinculo con ese RQ permanecen.
Mi Asistencia comparte el servicio y los registros de Asistencias.

Decision del usuario (2026-10-16): la limpieza corresponde a informacion de
prueba que no representa una operacion real. Se permite retirar su evidencia
relacionada y que los calculos derivados, incluido el uso EPP basado en esas
asignaciones, dejen de contarla. Esto no autoriza eliminar datos de otros RQ.

Los planes, servicios de transporte, sus eventos y los resumenes de ejecucion
se retiran explicitamente por su vinculo al RQ. Las cascadas MySQL siguen
activas como restricciones adicionales. La eliminacion no borra trabajadores,
minas ni catalogos maestros.

## Correccion de orden (2026-10-16)

Las tablas `grupo_trabajo_detalle_actividades` y `grupo_trabajo_actividades`
restringen la eliminacion de una actividad referenciada. El servicio intentaba
borrar actividades antes de retirar esas asignaciones, por lo que MySQL rechazaba
el borrado y la transaccion revertia todo.

Ahora retira primero ambas clases de asignaciones pertenecientes a los grupos
del RQ seleccionado. No desactiva restricciones ni amplia la seleccion a otros
grupos. La prueba `RQMinaApiTest` reproduce ambas restricciones y verifica la
eliminacion de asistencia, faltas, planes y resumen de ejecucion, conservando
personal, mina y otro RQ con su resumen.

## Aislamiento y calculos derivados (2026-10-16)

- Crear, editar y copiar grupos de Man Power valida que el RQ Proserge
  corresponda a la misma parada. El vinculo opcional nulo sigue permitido.
- Antes de borrar, el servicio comprueba la coherencia entre grupos,
  RQ Proserge, detalles de pedido, distribuciones, asistencias, faltas,
  herramientas, planes, resumenes y transporte. Si hay referencias
  contradictorias a otro RQ, aborta la transaccion completa y pide corregir
  esos vinculos. No repara ni reubica datos antiguos en silencio.
- La copia conserva identificadores propios; compartir trabajador o mina no
  hace que se elimine su otra operacion.
- Se bloquean el RQ y las filas seleccionadas mientras se ejecuta el borrado.
  Las restricciones MySQL permanecen activas; una dependencia inesperada
  impide la operacion y revierte los cambios.
- El recálculo sigue el orden trabajador -> evaluaciones. Su agregado usa
  lectura actual para incluir evaluaciones confirmadas despues de un snapshot
  anterior de la transaccion; una prueba con dos conexiones verifica este caso.
- Al retirar evaluaciones asociadas a la asistencia, se actualiza el promedio
  del trabajador con sus evaluaciones restantes. Si no quedan, se retira
  solamente el agregado derivado; Personal permanece.
- El log de exito se emite tras confirmar la transaccion e incluye el actor.
  No equivale a un respaldo completo ni cuenta todas las cascadas.

`RQMinaDeletionSafetyTest` cubre relaciones cruzadas en ambos sentidos,
distribuciones/asistencias ajenas, pasajeros y listas cruzadas, limpieza de
evidencia propia, compatibilidad del grupo legacy vinculado solo por
RQ Proserge, promedio restante/vacio, rollback, otra operacion completa del
mismo trabajador/mina, permisos, scope, sesion y repeticion. `ManPowerApiTest`
cubre crear/editar/copiar sin mezclar padres y conserva las copias validas.

## Limites y operacion

La confirmacion actual es un mensaje de navegador; no ofrece un conteo previo
ni un archivo recuperable de los registros eliminados. El log del servicio
registra actor, identificadores y conteos, no un respaldo restaurable. No usar este
flujo como sustituto de respaldo ni para borrar historial que deba conservarse.

Antes de una limpieza de produccion se necesita respaldo verificable. El alcance
adicional sobre evidencia de pruebas fue autorizado por el usuario. La correccion local de
orden no acredita el esquema del hosting ni ejecuta limpieza en produccion.
Las variantes historicas sin cascadas requieren verificacion especifica.

Los documentos privados de solicitudes de transporte no se borran fisicamente
en este flujo. Eliminar su referencia retira la descarga operativa. Las
evaluaciones directas e importaciones historicas sin vinculo con este RQ no se
seleccionan por coincidencias de trabajador, mina o fecha.
