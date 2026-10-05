# RQ Proserge: asignaciones y cobertura

## Scope Mina

- Un Scope Mina asignado limita listado, detalle y acciones a esas minas, incluso
  para roles privilegiados.
- El permiso `rq_proserge.administrar` no concede acceso a otras minas.
- Sin scope, usuarios operativos no ven requerimientos. Se conserva acceso
  global sin scope solo para roles ADMIN, GERENTE y SUPERADMIN.
- Web y API usan la misma politica de acceso.

## Dimensiones de asignacion

Cada trabajador asignado a RQ Proserge tiene dos dimensiones independientes:

- Posicion: `TITULAR` o `SUPLENTE`.
- Tipo: `REGULAR` o `ADICIONAL`.

Combinaciones validas:

- `TITULAR + REGULAR`
- `SUPLENTE + REGULAR`
- `TITULAR + ADICIONAL`
- `SUPLENTE + ADICIONAL`

Una asignacion adicional se muestra y conserva trazabilidad. Operativamente cuenta
como respaldo/backup disponible hasta cubrir la cantidad backup requerida.

## Reglas de cobertura

Por cada detalle de RQ Mina:

- Objetivo titular: `rq_mina_detalle.cantidad`.
- Objetivo respaldo: `rq_mina_detalle.cantidad_backup`.
- Objetivo total: `cantidad_total` o `cantidad + cantidad_backup`.

Cuenta como cobertura titular solo si:

- `posicion_asignacion = TITULAR`
- `tipo_asignacion = REGULAR`
- `estado = ASIGNADO`

Cuenta como respaldo solo si:

- `posicion_asignacion = SUPLENTE`
- `tipo_asignacion = REGULAR`
- `estado = ASIGNADO`

Tambien cuenta como respaldo, con limite del backup requerido, si:

- `tipo_asignacion = ADICIONAL`
- `estado = ASIGNADO`

No cuentan para cobertura:

- retirados;
- reemplazados;
- cancelados.

## Compatibilidad historica

Los registros anteriores pueden tener posicion o tipo `NULL`. No se reclasifican automaticamente.

Mientras existan activos sin clasificar, el sistema los usa solo para conservar cobertura efectiva:

1. Completa primero la brecha titular.
2. Con el saldo completa la brecha de respaldo.
3. No persiste ninguna inferencia en base de datos.
4. Marca `requiere_clasificacion = true`.

La UI muestra `SIN CLASIFICAR` para que RR.HH. pueda corregirlos manualmente.

## cantidad_atendida

`cantidad_atendida` se conserva por compatibilidad con RQ Mina y pantallas existentes.

Ahora representa:

`titular_efectivo + respaldo_efectivo`

con limite maximo `cantidad_total`. Los adicionales activos pueden sumar dentro
de `respaldo_efectivo`; retirados, reemplazados y cancelados no suman.

Una vez que las asignaciones activas llegan a `cantidad_total`, no se permite
agregar otra asignacion nueva. Para cambiar personal en un puesto completo se
debe usar reemplazo o retirar una asignacion y luego asignar.

## Estados

Estado de cobertura de detalle:

- `PENDIENTE`: no hay asignaciones activas.
- `PARCIAL`: hay asignaciones, pero falta titular o respaldo.
- `COMPLETADO`: titular y respaldo estan cubiertos.

Estado de cabecera RQ Proserge:

- `PENDIENTE`: todos los detalles estan pendientes.
- `PARCIAL`: al menos un detalle tiene atencion, pero no todos estan completos.
- `COMPLETADO`: todos los detalles vigentes estan completos.

`CERRADO` y `CANCELADO` no se sobrescriben automaticamente.

## Snapshots

Al asignar se captura:

- usuario asignador;
- fecha y hora de asignacion;
- puesto actual del trabajador;
- estado de habilitacion minera al momento de asignar;
- resumen seguro de disponibilidad.

El snapshot permite que Man Power pueda recibir despues una asignacion trazable sin recalcular todo el contexto original.

## Retiro y reemplazo

- Desasignar abre la confirmacion con el nombre del trabajador y exige motivo.
- El endpoint legacy comparte el servicio de retiro: no permite reescribir
  asignaciones retiradas, reemplazadas o canceladas.
- Los errores SQL se registran internamente, sin exponerlos en la pantalla.
- Para restauraciones con columnas de auditoria faltantes, usar exclusivamente
  `2026_09_07_000100_repair_rq_proserge_assignment_audit_columns.php` despues
  de respaldar y probar una copia de la BD. Agrega solo columnas faltantes
  nullable, sin reclasificar filas ni eliminar indices, datos o historial.
  No sustituye las demas migraciones pendientes de instalacion. Su `down`
  conserva la auditoria. No se ejecuto esta reparacion en produccion.

El retiro es logico:

- cambia estado a `RETIRADO`;
- guarda usuario, fecha y motivo;
- deja de contar para cobertura;
- no elimina la fila.

El reemplazo es transaccional:

- marca la asignacion original como `REEMPLAZADO`;
- crea una nueva asignacion;
- guarda `reemplaza_a_id`;
- recalcula cobertura y estado.

## Limitaciones pendientes

- La UI de reemplazo todavia usa captura simple del trabajador reemplazante; puede mejorarse con el mismo buscador visual de asignacion.
- No se implemento el 15 % de suplentes EECC.
- No se cambio el calculo de backup 20 % de RQ Mina.
- No se vinculo todavia con grupos operativos, SAIT ni Man Power.
